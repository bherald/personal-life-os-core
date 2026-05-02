<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tools = [
            // ── ASSESS PHASE ──────────────────────────────────────────────
            [
                'name' => 'research_engine_status',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getEngineStatus',
                'description' => 'Get health status of all search engines in the multi-engine fallback chain (NewsAPI, GNews, Wikipedia, SearXNG, Curl, Puppeteer). Shows active/disabled state, trust scores, success/failure counts, and overall chain health.',
                'parameters' => '[]',
                'returns_description' => 'Array with per-engine status and summary (active count, disabled count, chain health: intact/degraded/broken)',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],
            [
                'name' => 'research_circuit_breaker_status',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getCircuitBreakerStatus',
                'description' => 'Get circuit breaker state for all research engines. Shows which engines have open circuits (failing and backed off) vs closed (healthy).',
                'parameters' => '[]',
                'returns_description' => 'Array with per-engine circuit breaker state (open/closed), failure counts, last failure time, next retry time',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],
            [
                'name' => 'research_topic_stats',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getTopicStats',
                'description' => 'Get research topic scheduling statistics. Shows active/inactive counts, frequency distribution (daily/weekly/monthly), and overdue topics that have not run within their expected timeframe.',
                'parameters' => '[]',
                'returns_description' => 'Array with topic counts, frequency breakdown, and list of overdue topics with hours overdue',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],
            [
                'name' => 'research_dedup_stats',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getDedupStats',
                'description' => 'Get deduplication effectiveness across all 4 layers: content hash (Layer 1), semantic similarity (Layer 2), rejection registry (Layer 3), and fact-level comparison (Layer 4). High dedup rates indicate engines returning stale content.',
                'parameters' => '[]',
                'returns_description' => 'Array with per-layer dedup stats (duplicates caught, unique passed) and overall dedup rate percentage',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],
            [
                'name' => 'research_result_quality',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getResultQuality',
                'description' => 'Get research result quality metrics including pending/approved/skipped counts, approval rates (all-time and last 7 days), and quality trend direction.',
                'parameters' => '[]',
                'returns_description' => 'Array with all-time and 7-day quality metrics, approval rates, and trend indicator',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],
            [
                'name' => 'research_source_credibility',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getSourceCredibility',
                'description' => 'Get source credibility overview showing trust score distribution, sources with low trust (<=3), and sources with high failure rates (>10). Identifies sources that may need replacement or disabling.',
                'parameters' => '[]',
                'returns_description' => 'Array with total/active source counts, average trust score, low-trust sources, high-failure sources',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],
            [
                'name' => 'research_cache_stats',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getCacheStats',
                'description' => 'Get research cache effectiveness stats including total entries, active/expired counts, cache hit rates, and expiry rate. Low hit rates suggest caching is not effective.',
                'parameters' => '[]',
                'returns_description' => 'Array with cache entry counts, hit stats, average hits per entry, and expiry rate percentage',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],
            [
                'name' => 'research_archive_stats',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getArchiveStats',
                'description' => 'Get Archive.org preservation statistics for research sources. Shows how many source URLs have been archived for long-term preservation.',
                'parameters' => '[]',
                'returns_description' => 'Array with archive counts, success/failure rates, and coverage percentage',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],

            // ── ACT PHASE ─────────────────────────────────────────────────
            [
                'name' => 'research_reset_circuit_breaker',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'resetCircuitBreaker',
                'description' => 'Reset an open circuit breaker for a specific search engine. Use when the underlying issue is likely resolved. Safe operation: only resets Redis state; next failure will re-open the circuit.',
                'parameters' => json_encode([
                    'engine_name' => ['type' => 'string', 'required' => true, 'description' => 'Engine name to reset (newsapi, gnews, wikipedia, searxng, curl_scraper, puppeteer)'],
                ]),
                'returns_description' => 'Array with before/after circuit breaker state and success indicator',
                'permissions' => '["research:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'research',
            ],
            [
                'name' => 'research_disable_engine',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'disableEngine',
                'description' => 'Disable a search engine in the research pipeline. SUBMIT FOR REVIEW FIRST. Use when an engine has persistent failures and is degrading the fallback chain.',
                'parameters' => json_encode([
                    'engine_name' => ['type' => 'string', 'required' => true, 'description' => 'Engine name to disable'],
                ]),
                'returns_description' => 'Array with engine name and disable confirmation',
                'permissions' => '["research:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'research',
                'requires_confirmation' => 1,
            ],
            [
                'name' => 'research_enable_engine',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'enableEngine',
                'description' => 'Re-enable a previously disabled search engine and reset its failure count. Use after confirming the engine is operational again.',
                'parameters' => json_encode([
                    'engine_name' => ['type' => 'string', 'required' => true, 'description' => 'Engine name to re-enable'],
                ]),
                'returns_description' => 'Array with engine name and enable confirmation',
                'permissions' => '["research:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'research',
            ],
            [
                'name' => 'research_stale_topics',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getStaleTopics',
                'description' => 'Get research topics that have not run successfully within their expected timeframe (daily: >36h, weekly: >9d, monthly: >35d). Identifies scheduling gaps.',
                'parameters' => '[]',
                'returns_description' => 'Array of stale topics with ID, description, frequency, last run time, and category',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],
            [
                'name' => 'research_failed_results',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getFailedResults',
                'description' => 'Get recent failed/skipped research results from the last 7 days. Patterns in failures reveal systemic engine or topic issues.',
                'parameters' => '[]',
                'returns_description' => 'Array of recent failed results with topic ID, description, status, and timestamp',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],
            [
                'name' => 'research_run_topic',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'runTopic',
                'description' => 'Trigger a research run for a specific topic. Use to test engine recovery or catch up overdue topics. LIMIT TO 2 PER RUN to avoid overwhelming the pipeline.',
                'parameters' => json_encode([
                    'topic_id' => ['type' => 'integer', 'required' => true, 'description' => 'Research topic ID to run'],
                ]),
                'returns_description' => 'Array with topic ID, description, exit code, command output, and success indicator',
                'permissions' => '["research:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'research',
                'max_calls_per_run' => 2,
            ],
            [
                'name' => 'research_archive_sources',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'archiveSources',
                'description' => 'Archive research source URLs for a topic via Archive.org for long-term preservation. Preserves sources before they go stale or offline.',
                'parameters' => json_encode([
                    'topic_id' => ['type' => 'integer', 'required' => true, 'description' => 'Research topic ID whose sources to archive'],
                ]),
                'returns_description' => 'Array with archived URL count, success/failure details',
                'permissions' => '["research:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'research',
            ],
            [
                'name' => 'research_discover_sources',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'discoverSources',
                'description' => 'Discover new authoritative sources for a research topic. Useful when existing sources are degrading or need diversification.',
                'parameters' => json_encode([
                    'topic_description' => ['type' => 'string', 'required' => true, 'description' => 'Topic description to discover sources for'],
                ]),
                'returns_description' => 'Array of discovered sources with name, URL, and relevance assessment',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],
        ];

        foreach ($tools as $tool) {
            try {
                $columns = 'name, service_class, method, description, parameters, returns_description, permissions, risk_level, category, enabled, source';
                $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, 1, \'config\'';
                $values = [
                    $tool['name'],
                    $tool['service_class'],
                    $tool['method'],
                    $tool['description'],
                    $tool['parameters'],
                    $tool['returns_description'],
                    $tool['permissions'],
                    $tool['risk_level'],
                    $tool['category'],
                ];

                if (isset($tool['requires_confirmation'])) {
                    $columns .= ', requires_confirmation';
                    $placeholders .= ', ?';
                    $values[] = $tool['requires_confirmation'];
                }

                if (isset($tool['max_calls_per_run'])) {
                    $columns .= ', max_calls_per_run';
                    $placeholders .= ', ?';
                    $values[] = $tool['max_calls_per_run'];
                }

                DB::insert("
                    INSERT INTO agent_tool_registry ({$columns})
                    VALUES ({$placeholders})
                ", $values);
            } catch (\Exception $e) {
                // Skip duplicates (idempotent)
            }
        }

        // Add scheduled job for research-ops agent
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'research_ops_agent'");
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, command, cron_expression, job_type, enabled, category,
                 timeout_minutes, run_in_background, without_overlapping, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'research_ops_agent',
                'Research pipeline health monitoring: engine fallback chain, circuit breakers, topic scheduling, source credibility, 4-layer dedup',
                'research-ops',
                '*/30 * * * *',
                'agent_task',
                1,
                'Agent',
                10,
                1,
                1,
                json_encode(['notify' => true]),
            ]);
        }
    }

    public function down(): void
    {
        $toolNames = [
            'research_engine_status', 'research_circuit_breaker_status', 'research_topic_stats',
            'research_dedup_stats', 'research_result_quality', 'research_source_credibility',
            'research_cache_stats', 'research_archive_stats', 'research_reset_circuit_breaker',
            'research_disable_engine', 'research_enable_engine', 'research_stale_topics',
            'research_failed_results', 'research_run_topic', 'research_archive_sources',
            'research_discover_sources',
        ];

        $placeholders = implode(',', array_fill(0, count($toolNames), '?'));
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ($placeholders)", $toolNames);
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'research_ops_agent'");
    }
};
