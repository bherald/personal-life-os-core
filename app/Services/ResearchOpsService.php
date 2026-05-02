<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;

/**
 * Research Operations Service
 *
 * Provides agent-callable tool methods for the research-ops agent.
 * Wraps WebResearchService, ResearchEnhancementsService, and direct
 * PostgreSQL queries for research pipeline health monitoring.
 */
class ResearchOpsService
{
    private string $db = 'pgsql_rag';

    // =========================================================================
    // ASSESS TOOLS
    // =========================================================================

    /**
     * Get health status of all search engines in the fallback chain.
     * Wraps WebResearchService::getEngineStatus() with additional metrics.
     */
    public function getEngineStatus(): array
    {
        try {
            $engines = app(WebResearchService::class)->getEngineStatus();

            $active = count(array_filter($engines, fn($e) => $e['active']));
            $disabled = count(array_filter($engines, fn($e) => !$e['active']));
            $degraded = count(array_filter($engines, fn($e) => $e['health'] === 'degraded'));

            $chainIntact = $active > 0;
            $chainHealth = $active >= 4 ? 'intact' : ($active >= 2 ? 'degraded' : ($active >= 1 ? 'critical' : 'broken'));

            return [
                'engines' => $engines,
                'summary' => [
                    'total' => count($engines),
                    'active' => $active,
                    'disabled' => $disabled,
                    'degraded' => $degraded,
                    'chain_health' => $chainHealth,
                    'chain_intact' => $chainIntact,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ResearchOpsService: Failed to get engine status', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage(), 'engines' => [], 'summary' => ['chain_health' => 'unknown']];
        }
    }

    /**
     * Get circuit breaker status for all research engines.
     */
    public function getCircuitBreakerStatus(): array
    {
        $enhancements = app(ResearchEnhancementsService::class);
        $engineNames = ['newsapi', 'wikipedia', 'searxng', 'curl_scraper', 'puppeteer'];

        $breakers = [];
        $openCount = 0;

        foreach ($engineNames as $name) {
            $status = $enhancements->getCircuitBreakerStatus($name);
            $breakers[] = $status;
            if ($status['state'] === 'open') {
                $openCount++;
            }
        }

        return [
            'circuit_breakers' => $breakers,
            'summary' => [
                'total' => count($breakers),
                'open' => $openCount,
                'closed' => count($breakers) - $openCount,
                'all_healthy' => $openCount === 0,
            ],
        ];
    }

    /**
     * Get research topic scheduling statistics.
     */
    public function getTopicStats(): array
    {
        try {
            $topics = DB::connection($this->db)->select("
                SELECT id, description, frequency, is_active, last_ran_at, rag_category
                FROM research_topics
                ORDER BY is_active DESC, last_ran_at ASC NULLS FIRST
            ");

            $active = 0;
            $inactive = 0;
            $overdue = [];
            $frequencies = ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'other' => 0];
            $now = now();

            foreach ($topics as $t) {
                if ($t->is_active) {
                    $active++;
                } else {
                    $inactive++;
                }

                $freq = strtolower($t->frequency ?? 'other');
                if (isset($frequencies[$freq])) {
                    $frequencies[$freq]++;
                } else {
                    $frequencies['other']++;
                }

                if ($t->is_active && $t->last_ran_at) {
                    $lastRan = \Carbon\Carbon::parse($t->last_ran_at);
                    $overdueThreshold = match ($freq) {
                        'daily' => $lastRan->addHours(36),
                        'weekly' => $lastRan->addDays(9),
                        'monthly' => $lastRan->addDays(35),
                        default => $lastRan->addDays(14),
                    };
                    if ($now->greaterThan($overdueThreshold)) {
                        $overdue[] = [
                            'id' => $t->id,
                            'description' => $t->description,
                            'frequency' => $t->frequency,
                            'last_ran_at' => $t->last_ran_at,
                            'hours_overdue' => $now->diffInHours($overdueThreshold),
                        ];
                    }
                } elseif ($t->is_active && !$t->last_ran_at) {
                    $overdue[] = [
                        'id' => $t->id,
                        'description' => $t->description,
                        'frequency' => $t->frequency,
                        'last_ran_at' => null,
                        'hours_overdue' => null,
                    ];
                }
            }

            return [
                'topics' => [
                    'total' => count($topics),
                    'active' => $active,
                    'inactive' => $inactive,
                ],
                'frequencies' => $frequencies,
                'overdue' => $overdue,
                'overdue_count' => count($overdue),
            ];
        } catch (\Exception $e) {
            Log::error('ResearchOpsService: Failed to get topic stats', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get deduplication effectiveness stats across all 4 layers.
     */
    public function getDedupStats(): array
    {
        try {
            // Layer 1: Content hash duplicates detected
            $contentHashDupes = DB::connection($this->db)->select("
                SELECT COUNT(*) as total,
                       COUNT(CASE WHEN dedup_status = 'duplicate' THEN 1 END) as duplicates,
                       COUNT(CASE WHEN dedup_status = 'unique' THEN 1 END) as unique_results
                FROM research_results
                WHERE created_at > NOW() - INTERVAL '30 days'
            ");

            // Layer 3: Rejection registry stats
            $rejections = DB::connection($this->db)->select("
                SELECT COUNT(*) as total_rejections,
                       COUNT(DISTINCT research_topic_id) as topics_with_rejections,
                       COUNT(*) as total_rejection_hits
                FROM research_rejections
            ");

            // Layer 4: Fact-level dedup
            $factDupes = DB::connection($this->db)->select("
                SELECT COUNT(*) as total_rejected_facts
                FROM research_rejected_facts
            ");

            $total = $contentHashDupes[0]->total ?? 0;
            $dupes = $contentHashDupes[0]->duplicates ?? 0;
            $dupRate = $total > 0 ? round(($dupes / $total) * 100, 1) : 0;

            return [
                'period' => 'last_30_days',
                'layer_1_content_hash' => [
                    'total_results' => $total,
                    'duplicates_caught' => $dupes,
                    'unique_passed' => $contentHashDupes[0]->unique_results ?? 0,
                    'dedup_rate_pct' => $dupRate,
                ],
                'layer_3_rejection_registry' => [
                    'total_rejections' => $rejections[0]->total_rejections ?? 0,
                    'topics_with_rejections' => $rejections[0]->topics_with_rejections ?? 0,
                    'total_rejection_hits' => $rejections[0]->total_rejection_hits ?? 0,
                ],
                'layer_4_fact_level' => [
                    'total_rejected_facts' => $factDupes[0]->total_rejected_facts ?? 0,
                ],
                'overall_dedup_rate_pct' => $dupRate,
            ];
        } catch (\Exception $e) {
            Log::error('ResearchOpsService: Failed to get dedup stats', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get research result quality metrics (pending/approved/skipped, confidence).
     */
    public function getResultQuality(): array
    {
        try {
            $stats = DB::connection($this->db)->select("
                SELECT
                    COUNT(*) as total,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) as skipped
                FROM research_results
            ");

            $recent = DB::connection($this->db)->select("
                SELECT
                    COUNT(*) as total,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) as skipped
                FROM research_results
                WHERE created_at > NOW() - INTERVAL '7 days'
            ");

            $total = $stats[0]->total ?? 0;
            $approved = $stats[0]->approved ?? 0;
            $approvalRate = $total > 0 ? round(($approved / $total) * 100, 1) : 0;

            $recentTotal = $recent[0]->total ?? 0;
            $recentApproved = $recent[0]->approved ?? 0;
            $recentRate = $recentTotal > 0 ? round(($recentApproved / $recentTotal) * 100, 1) : 0;

            return [
                'all_time' => [
                    'total' => $total,
                    'pending' => $stats[0]->pending ?? 0,
                    'approved' => $approved,
                    'skipped' => $stats[0]->skipped ?? 0,
                    'approval_rate_pct' => $approvalRate,
                ],
                'last_7_days' => [
                    'total' => $recentTotal,
                    'approved' => $recentApproved,
                    'skipped' => $recent[0]->skipped ?? 0,
                    'approval_rate_pct' => $recentRate,
                ],
                'trend' => $recentRate >= $approvalRate ? 'stable_or_improving' : 'declining',
            ];
        } catch (\Exception $e) {
            Log::error('ResearchOpsService: Failed to get result quality', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get source credibility overview (trust scores, failure patterns).
     */
    public function getSourceCredibility(): array
    {
        try {
            $sources = DB::connection($this->db)->select("
                SELECT name, base_url, is_active, trust_score, success_count, failure_count,
                       last_success_at, last_failure_at, is_search_engine
                FROM research_sources
                ORDER BY trust_score ASC
            ");

            $lowTrust = [];
            $highFailure = [];
            $totalTrust = 0;
            $activeCount = 0;

            foreach ($sources as $s) {
                if ($s->is_active) {
                    $activeCount++;
                    $totalTrust += $s->trust_score;
                }
                if ($s->trust_score <= 3 && $s->is_active) {
                    $lowTrust[] = [
                        'name' => $s->name,
                        'trust_score' => $s->trust_score,
                        'failure_count' => $s->failure_count,
                    ];
                }
                if ($s->failure_count > 10 && $s->is_active) {
                    $highFailure[] = [
                        'name' => $s->name,
                        'failure_count' => $s->failure_count,
                        'success_count' => $s->success_count,
                        'last_failure' => $s->last_failure_at,
                    ];
                }
            }

            return [
                'total_sources' => count($sources),
                'active_sources' => $activeCount,
                'avg_trust_score' => $activeCount > 0 ? round($totalTrust / $activeCount, 1) : 0,
                'low_trust_sources' => $lowTrust,
                'high_failure_sources' => $highFailure,
                'concerns' => count($lowTrust) + count($highFailure),
            ];
        } catch (\Exception $e) {
            Log::error('ResearchOpsService: Failed to get source credibility', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get research cache effectiveness stats.
     */
    public function getCacheStats(): array
    {
        try {
            $stats = DB::connection($this->db)->select("
                SELECT
                    COUNT(*) as total_entries,
                    COUNT(CASE WHEN expires_at < NOW() THEN 1 END) as expired,
                    COUNT(CASE WHEN expires_at >= NOW() THEN 1 END) as active,
                    COALESCE(SUM(access_count), 0) as total_hits,
                    COALESCE(AVG(access_count), 0) as avg_hits_per_entry
                FROM research_cache
            ");

            $totalEntries = $stats[0]->total_entries ?? 0;
            $expired = $stats[0]->expired ?? 0;
            $active = $stats[0]->active ?? 0;
            $totalHits = $stats[0]->total_hits ?? 0;

            return [
                'total_entries' => $totalEntries,
                'active_entries' => $active,
                'expired_entries' => $expired,
                'total_cache_hits' => (int) $totalHits,
                'avg_hits_per_entry' => round($stats[0]->avg_hits_per_entry ?? 0, 1),
                'expiry_rate_pct' => $totalEntries > 0 ? round(($expired / $totalEntries) * 100, 1) : 0,
            ];
        } catch (\Exception $e) {
            Log::error('ResearchOpsService: Failed to get cache stats', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get Archive.org preservation stats.
     */
    public function getArchiveStats(): array
    {
        try {
            return app(ResearchEnhancementsService::class)->getArchiveStats();
        } catch (\Exception $e) {
            Log::error('ResearchOpsService: Failed to get archive stats', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // ACT TOOLS
    // =========================================================================

    /**
     * Reset a circuit breaker for a specific engine.
     */
    public function resetCircuitBreaker(string $engine_name): array
    {
        try {
            $enhancements = app(ResearchEnhancementsService::class);
            $before = $enhancements->getCircuitBreakerStatus($engine_name);
            $enhancements->resetCircuitBreaker($engine_name);
            $after = $enhancements->getCircuitBreakerStatus($engine_name);

            Log::info('ResearchOpsService: Circuit breaker reset', [
                'engine' => $engine_name,
                'was_open' => $before['state'] === 'open',
            ]);

            return [
                'engine' => $engine_name,
                'before' => $before,
                'after' => $after,
                'success' => true,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'success' => false];
        }
    }

    /**
     * Disable a search engine in the research_sources table.
     */
    public function disableEngine(string $engine_name): array
    {
        try {
            $affected = DB::connection($this->db)->update("
                UPDATE research_sources SET is_active = false WHERE name = ?
            ", [$engine_name]);

            Log::info('ResearchOpsService: Engine disabled', ['engine' => $engine_name, 'affected' => $affected]);

            return [
                'engine' => $engine_name,
                'disabled' => $affected > 0,
                'message' => $affected > 0 ? "Engine '{$engine_name}' disabled" : "Engine '{$engine_name}' not found",
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Re-enable a previously disabled search engine.
     */
    public function enableEngine(string $engine_name): array
    {
        try {
            $affected = DB::connection($this->db)->update("
                UPDATE research_sources SET is_active = true, failure_count = 0 WHERE name = ?
            ", [$engine_name]);

            Log::info('ResearchOpsService: Engine enabled', ['engine' => $engine_name, 'affected' => $affected]);

            return [
                'engine' => $engine_name,
                'enabled' => $affected > 0,
                'message' => $affected > 0 ? "Engine '{$engine_name}' re-enabled with failure count reset" : "Engine '{$engine_name}' not found",
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get topics that are stale (overdue for their scheduled frequency).
     */
    public function getStaleTopics(): array
    {
        try {
            $topics = DB::connection($this->db)->select("
                SELECT id, description, frequency, last_ran_at, rag_category
                FROM research_topics
                WHERE is_active = true
                  AND (
                    last_ran_at IS NULL
                    OR (frequency = 'daily' AND last_ran_at < NOW() - INTERVAL '36 hours')
                    OR (frequency = 'weekly' AND last_ran_at < NOW() - INTERVAL '9 days')
                    OR (frequency = 'monthly' AND last_ran_at < NOW() - INTERVAL '35 days')
                  )
                ORDER BY last_ran_at ASC NULLS FIRST
            ");

            return [
                'stale_topics' => array_map(fn($t) => [
                    'id' => $t->id,
                    'description' => $t->description,
                    'frequency' => $t->frequency,
                    'last_ran_at' => $t->last_ran_at,
                    'category' => $t->rag_category,
                ], $topics),
                'count' => count($topics),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get recent failed research attempts with error details.
     */
    public function getFailedResults(): array
    {
        try {
            // Check for topics that failed recently by looking at results with error indicators
            $failed = DB::connection($this->db)->select("
                SELECT rr.id, rr.research_topic_id, rt.description as topic_description,
                       rr.status, rr.created_at
                FROM research_results rr
                JOIN research_topics rt ON rt.id = rr.research_topic_id
                WHERE rr.status = 'skipped'
                  AND rr.created_at > NOW() - INTERVAL '7 days'
                ORDER BY rr.created_at DESC
                LIMIT 20
            ");

            return [
                'recent_failures' => array_map(fn($f) => [
                    'result_id' => $f->id,
                    'topic_id' => $f->research_topic_id,
                    'topic' => $f->topic_description,
                    'status' => $f->status,
                    'created_at' => $f->created_at,
                ], $failed),
                'count' => count($failed),
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Archive research source URLs for a topic via Archive.org.
     */
    public function archiveSources(int $topic_id): array
    {
        try {
            return app(ResearchEnhancementsService::class)->archiveResearchSources($topic_id);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Compatibility wrapper for the existing research_run_topic registry tool.
     */
    public function runTopic(int $topic_id): array
    {
        try {
            $topic = DB::connection($this->db)->selectOne(
                "SELECT id, description FROM research_topics WHERE id = ? LIMIT 1",
                [$topic_id]
            );

            if (!$topic) {
                return [
                    'success' => false,
                    'topic_id' => $topic_id,
                    'message' => "Topic {$topic_id} not found",
                ];
            }

            $exitCode = Artisan::call('research:run', [
                '--topic' => $topic_id,
                '--max' => 1,
                '--force' => true,
            ]);

            return [
                'success' => $exitCode === 0,
                'topic_id' => $topic_id,
                'description' => $topic->description,
                'exit_code' => $exitCode,
                'output' => Artisan::output(),
            ];
        } catch (\Throwable $e) {
            Log::error('ResearchOpsService: runTopic failed', [
                'topic_id' => $topic_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'topic_id' => $topic_id,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Discover new authoritative sources for a topic.
     */
    public function discoverSources(string $topic_description): array
    {
        try {
            return app(WebResearchService::class)->discoverSourcesForTopic($topic_description);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // ANALYST TOOLS (Content-level analysis for research-analyst agent)
    // =========================================================================

    /**
     * Get detailed topic coverage analysis — per-topic result counts, quality scores,
     * recency, and coverage gaps.
     */
    public function getTopicCoverage(): array
    {
        try {
            $topics = DB::connection($this->db)->select("
                SELECT
                    rt.id,
                    rt.description,
                    rt.frequency,
                    rt.is_active,
                    rt.last_ran_at,
                    rt.rag_category,
                    COUNT(rr.id) as total_results,
                    COUNT(CASE WHEN rr.status = 'approved' THEN 1 END) as approved,
                    COUNT(CASE WHEN rr.status = 'pending' THEN 1 END) as pending,
                    COUNT(CASE WHEN rr.status = 'skipped' THEN 1 END) as skipped,
                    ROUND(AVG(rr.quality_score)::numeric, 2) as avg_quality,
                    ROUND(AVG(rr.ai_quality_score)::numeric, 2) as avg_ai_quality,
                    MAX(rr.created_at) as latest_result_at
                FROM research_topics rt
                LEFT JOIN research_results rr ON rt.id = rr.research_topic_id
                WHERE rt.is_active = true
                GROUP BY rt.id, rt.description, rt.frequency, rt.is_active, rt.last_ran_at, rt.rag_category
                ORDER BY total_results ASC, avg_quality ASC NULLS FIRST
            ");

            $gaps = [];
            $lowQuality = [];
            $stale = [];
            $now = now();

            foreach ($topics as $t) {
                if ($t->total_results == 0) {
                    $gaps[] = ['id' => $t->id, 'description' => $t->description, 'reason' => 'zero_results'];
                } elseif ($t->approved == 0) {
                    $gaps[] = ['id' => $t->id, 'description' => $t->description, 'reason' => 'zero_approved', 'total' => $t->total_results];
                }

                if ($t->avg_quality !== null && (float) $t->avg_quality < 0.5) {
                    $lowQuality[] = ['id' => $t->id, 'description' => $t->description, 'avg_quality' => $t->avg_quality, 'results' => $t->total_results];
                }

                if ($t->latest_result_at) {
                    $daysSince = $now->diffInDays(\Carbon\Carbon::parse($t->latest_result_at));
                    if ($daysSince > 30) {
                        $stale[] = ['id' => $t->id, 'description' => $t->description, 'days_since_result' => $daysSince];
                    }
                }
            }

            return [
                'topics' => array_map(fn($t) => [
                    'id' => $t->id,
                    'description' => $t->description,
                    'frequency' => $t->frequency,
                    'category' => $t->rag_category,
                    'total_results' => (int) $t->total_results,
                    'approved' => (int) $t->approved,
                    'pending' => (int) $t->pending,
                    'skipped' => (int) $t->skipped,
                    'avg_quality' => $t->avg_quality,
                    'avg_ai_quality' => $t->avg_ai_quality,
                    'latest_result_at' => $t->latest_result_at,
                ], $topics),
                'coverage_gaps' => $gaps,
                'low_quality_topics' => $lowQuality,
                'stale_topics' => $stale,
                'summary' => [
                    'total_active_topics' => count($topics),
                    'topics_with_gaps' => count($gaps),
                    'topics_low_quality' => count($lowQuality),
                    'topics_stale' => count($stale),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ResearchOpsService: Failed to get topic coverage', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get pending research results awaiting review, with AI quality assessments.
     */
    public function getPendingResults(int $limit = 20): array
    {
        try {
            $results = DB::connection($this->db)->select("
                SELECT
                    rr.id,
                    rr.research_topic_id,
                    rt.description as topic_description,
                    rt.rag_category,
                    rr.status,
                    rr.quality_score,
                    rr.ai_quality_score,
                    rr.ai_has_findings,
                    rr.ai_recommendation,
                    rr.dedup_status,
                    rr.created_at,
                    LENGTH(rr.ai_output) as output_length
                FROM research_results rr
                JOIN research_topics rt ON rt.id = rr.research_topic_id
                WHERE rr.status = 'pending'
                ORDER BY rr.ai_quality_score DESC NULLS LAST, rr.created_at ASC
                LIMIT ?
            ", [$limit]);

            $withFindings = 0;
            $highQuality = 0;

            foreach ($results as $r) {
                if ($r->ai_has_findings) $withFindings++;
                if ($r->ai_quality_score !== null && (float) $r->ai_quality_score >= 0.7) $highQuality++;
            }

            return [
                'pending_results' => array_map(fn($r) => [
                    'id' => $r->id,
                    'topic_id' => $r->research_topic_id,
                    'topic' => $r->topic_description,
                    'category' => $r->rag_category,
                    'quality_score' => $r->quality_score,
                    'ai_quality_score' => $r->ai_quality_score,
                    'ai_has_findings' => (bool) $r->ai_has_findings,
                    'ai_recommendation' => $r->ai_recommendation,
                    'dedup_status' => $r->dedup_status,
                    'output_length' => (int) $r->output_length,
                    'created_at' => $r->created_at,
                ], $results),
                'summary' => [
                    'total_pending' => count($results),
                    'with_findings' => $withFindings,
                    'high_quality' => $highQuality,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ResearchOpsService: Failed to get pending results', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get a specific research result's full AI output for content review.
     */
    public function getResultDetail(int $result_id): array
    {
        try {
            $result = DB::connection($this->db)->select("
                SELECT
                    rr.id,
                    rr.research_topic_id,
                    rt.description as topic_description,
                    rr.ai_output,
                    rr.status,
                    rr.quality_score,
                    rr.ai_quality_score,
                    rr.ai_has_findings,
                    rr.ai_recommendation,
                    rr.extracted_facts,
                    rr.dedup_status,
                    rr.dedup_matched_id,
                    rr.content_hash,
                    rr.rag_indexed_at,
                    rr.created_at,
                    rr.reviewed_at
                FROM research_results rr
                JOIN research_topics rt ON rt.id = rr.research_topic_id
                WHERE rr.id = ?
            ", [$result_id]);

            if (empty($result)) {
                return ['error' => "Result {$result_id} not found"];
            }

            $r = $result[0];
            $facts = $r->extracted_facts ? json_decode($r->extracted_facts, true) : [];

            return [
                'id' => $r->id,
                'topic_id' => $r->research_topic_id,
                'topic' => $r->topic_description,
                'status' => $r->status,
                'ai_output' => $r->ai_output,
                'quality_score' => $r->quality_score,
                'ai_quality_score' => $r->ai_quality_score,
                'ai_has_findings' => (bool) $r->ai_has_findings,
                'ai_recommendation' => $r->ai_recommendation,
                'extracted_facts' => $facts,
                'fact_count' => count($facts),
                'dedup_status' => $r->dedup_status,
                'dedup_matched_id' => $r->dedup_matched_id,
                'rag_indexed' => $r->rag_indexed_at !== null,
                'created_at' => $r->created_at,
                'reviewed_at' => $r->reviewed_at,
            ];
        } catch (\Exception $e) {
            Log::error('ResearchOpsService: Failed to get result detail', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Approve a pending research result and optionally trigger RAG indexing.
     */
    public function approveResult(int $result_id): array
    {
        try {
            $result = DB::connection($this->db)->select("
                SELECT id, status, research_topic_id FROM research_results WHERE id = ?
            ", [$result_id]);

            if (empty($result)) {
                return ['error' => "Result {$result_id} not found"];
            }

            if ($result[0]->status !== 'pending') {
                return ['error' => "Result {$result_id} is already '{$result[0]->status}'", 'result_id' => $result_id];
            }

            DB::connection($this->db)->update("
                UPDATE research_results SET status = 'approved', reviewed_at = NOW() WHERE id = ?
            ", [$result_id]);

            Log::info('ResearchOpsService: Result approved by analyst', ['result_id' => $result_id]);

            return [
                'result_id' => $result_id,
                'status' => 'approved',
                'message' => "Result {$result_id} approved successfully",
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Skip (reject) a pending research result with a reason.
     */
    public function skipResult(int $result_id, string $reason = ''): array
    {
        try {
            $result = DB::connection($this->db)->select("
                SELECT id, status FROM research_results WHERE id = ?
            ", [$result_id]);

            if (empty($result)) {
                return ['error' => "Result {$result_id} not found"];
            }

            if ($result[0]->status !== 'pending') {
                return ['error' => "Result {$result_id} is already '{$result[0]->status}'", 'result_id' => $result_id];
            }

            DB::connection($this->db)->update("
                UPDATE research_results SET status = 'skipped', reviewed_at = NOW() WHERE id = ?
            ", [$result_id]);

            Log::info('ResearchOpsService: Result skipped by analyst', ['result_id' => $result_id, 'reason' => $reason]);

            return [
                'result_id' => $result_id,
                'status' => 'skipped',
                'reason' => $reason,
                'message' => "Result {$result_id} skipped",
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get cross-topic research trends — category distribution, quality trends,
     * output volume over time.
     */
    public function getResearchTrends(): array
    {
        try {
            // Category distribution
            $categories = DB::connection($this->db)->select("
                SELECT
                    rt.rag_category as category,
                    COUNT(DISTINCT rt.id) as topic_count,
                    COUNT(rr.id) as result_count,
                    COUNT(CASE WHEN rr.status = 'approved' THEN 1 END) as approved_count,
                    ROUND(AVG(rr.quality_score)::numeric, 2) as avg_quality
                FROM research_topics rt
                LEFT JOIN research_results rr ON rt.id = rr.research_topic_id
                WHERE rt.is_active = true
                GROUP BY rt.rag_category
                ORDER BY result_count DESC
            ");

            // Weekly volume trend (last 8 weeks)
            $weeklyVolume = DB::connection($this->db)->select("
                SELECT
                    DATE_TRUNC('week', created_at) as week,
                    COUNT(*) as total,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) as skipped,
                    ROUND(AVG(quality_score)::numeric, 2) as avg_quality
                FROM research_results
                WHERE created_at > NOW() - INTERVAL '56 days'
                GROUP BY DATE_TRUNC('week', created_at)
                ORDER BY week DESC
                LIMIT 8
            ");

            // Fact extraction effectiveness
            $factStats = DB::connection($this->db)->select("
                SELECT
                    COUNT(*) as total_results,
                    COUNT(CASE WHEN ai_has_findings = true THEN 1 END) as with_findings,
                    COUNT(CASE WHEN extracted_facts IS NOT NULL AND extracted_facts != '[]' THEN 1 END) as with_facts
                FROM research_results
                WHERE created_at > NOW() - INTERVAL '30 days'
            ");

            $totalRecent = $factStats[0]->total_results ?? 0;
            $withFindings = $factStats[0]->with_findings ?? 0;
            $withFacts = $factStats[0]->with_facts ?? 0;

            return [
                'categories' => array_map(fn($c) => [
                    'category' => $c->category ?? 'uncategorized',
                    'topic_count' => (int) $c->topic_count,
                    'result_count' => (int) $c->result_count,
                    'approved_count' => (int) $c->approved_count,
                    'avg_quality' => $c->avg_quality,
                ], $categories),
                'weekly_volume' => array_map(fn($w) => [
                    'week' => $w->week,
                    'total' => (int) $w->total,
                    'approved' => (int) $w->approved,
                    'skipped' => (int) $w->skipped,
                    'avg_quality' => $w->avg_quality,
                ], $weeklyVolume),
                'fact_extraction' => [
                    'total_recent' => (int) $totalRecent,
                    'with_findings' => (int) $withFindings,
                    'with_facts' => (int) $withFacts,
                    'findings_rate_pct' => $totalRecent > 0 ? round(($withFindings / $totalRecent) * 100, 1) : 0,
                    'fact_extraction_rate_pct' => $totalRecent > 0 ? round(($withFacts / $totalRecent) * 100, 1) : 0,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ResearchOpsService: Failed to get research trends', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Search the RAG knowledge base for research content by query.
     * Useful for checking what's already been indexed and finding related content.
     */
    public function searchResearchKnowledge(string $query, int $limit = 10): array
    {
        try {
            $ragService = app(RAGService::class);
            $results = $ragService->search($query, $limit, 'research');

            return [
                'query' => $query,
                'results' => array_map(fn($r) => [
                    'id' => $r['document']->id ?? null,
                    'title' => $r['document']->title ?? null,
                    'score' => $r['similarity'] ?? null,
                    'content_preview' => substr($r['document']->content ?? '', 0, 300),
                    'metadata' => json_decode($r['document']->metadata ?? '{}', true),
                ], $results),
                'count' => count($results),
            ];
        } catch (\Exception $e) {
            Log::error('ResearchOpsService: Failed to search research knowledge', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage(), 'query' => $query, 'results' => []];
        }
    }
}
