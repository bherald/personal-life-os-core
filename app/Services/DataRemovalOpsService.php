<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Data Removal Operations Service
 *
 * Provides agent-callable tool methods for the data-removal-ops agent.
 * Monitors the personal data removal pipeline: broker health, request tracking,
 * effectiveness metrics, relisting detection, and proof archival.
 *
 * All data lives in MySQL (default connection).
 * PRIVACY: Methods must never return PII — only IDs, counts, and aggregates.
 */
class DataRemovalOpsService
{
    // =========================================================================
    // ASSESS TOOLS
    // =========================================================================

    /**
     * Get overall pipeline statistics — subjects, brokers, request counts
     * by status, submission rates, completion trends.
     */
    public function getPipelineStats(): array
    {
        try {
            // Subject counts
            $subjects = DB::selectOne("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active
                FROM data_subjects
            ");

            // Broker counts
            $brokers = DB::selectOne("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                       SUM(CASE WHEN automation_tier = 1 THEN 1 ELSE 0 END) as tier_auto,
                       SUM(CASE WHEN automation_tier = 2 THEN 1 ELSE 0 END) as tier_ai_assisted,
                       SUM(CASE WHEN automation_tier = 3 THEN 1 ELSE 0 END) as tier_manual
                FROM data_brokers
            ");

            // Request status breakdown
            $requestStats = DB::selectOne("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                       SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
                       SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                       SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                       SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                       SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved
                FROM removal_requests
            ");

            // Recent 30-day activity
            $recentActivity = DB::selectOne("
                SELECT COUNT(*) as total_30d,
                       SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_30d,
                       SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_30d
                FROM removal_requests
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");

            // Daily submission rate (last 7 days)
            $dailyRate = DB::select("
                SELECT DATE(created_at) as date, COUNT(*) as submissions
                FROM removal_requests
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ");

            return [
                'subjects' => [
                    'total' => (int) ($subjects->total ?? 0),
                    'active' => (int) ($subjects->active ?? 0),
                ],
                'brokers' => [
                    'total' => (int) ($brokers->total ?? 0),
                    'active' => (int) ($brokers->active ?? 0),
                    'by_tier' => [
                        'auto' => (int) ($brokers->tier_auto ?? 0),
                        'ai_assisted' => (int) ($brokers->tier_ai_assisted ?? 0),
                        'manual' => (int) ($brokers->tier_manual ?? 0),
                    ],
                ],
                'requests' => [
                    'total' => (int) ($requestStats->total ?? 0),
                    'pending' => (int) ($requestStats->pending ?? 0),
                    'submitted' => (int) ($requestStats->submitted ?? 0),
                    'confirmed' => (int) ($requestStats->confirmed ?? 0),
                    'failed' => (int) ($requestStats->failed ?? 0),
                    'rejected' => (int) ($requestStats->rejected ?? 0),
                    'approved' => (int) ($requestStats->approved ?? 0),
                ],
                'recent_30d' => [
                    'total' => (int) ($recentActivity->total_30d ?? 0),
                    'confirmed' => (int) ($recentActivity->confirmed_30d ?? 0),
                    'failed' => (int) ($recentActivity->failed_30d ?? 0),
                ],
                'daily_submissions' => array_map(fn($d) => [
                    'date' => $d->date,
                    'count' => (int) $d->submissions,
                ], $dailyRate),
            ];
        } catch (\Exception $e) {
            Log::error('DataRemovalOpsService::getPipelineStats failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get broker health status — opt-out page availability, form validation,
     * response times, broken/degraded brokers.
     */
    public function getBrokerHealth(): array
    {
        try {
            // Current broker health status
            $healthSummary = DB::selectOne("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN health_status = 'healthy' OR health_status IS NULL THEN 1 ELSE 0 END) as healthy,
                       SUM(CASE WHEN health_status = 'degraded' THEN 1 ELSE 0 END) as degraded,
                       SUM(CASE WHEN health_status = 'broken' THEN 1 ELSE 0 END) as broken,
                       SUM(CASE WHEN health_status = 'changed' THEN 1 ELSE 0 END) as changed
                FROM data_brokers
                WHERE is_active = 1
            ");

            // Recent health checks
            $recentChecks = DB::select("
                SELECT db.id as broker_id, db.name as broker_name, db.domain,
                       db.health_status, db.last_health_check,
                       bhc.response_time_ms, bhc.status as check_status, bhc.check_type
                FROM data_brokers db
                LEFT JOIN broker_health_checks bhc ON bhc.data_broker_id = db.id
                    AND bhc.id = (SELECT MAX(id) FROM broker_health_checks WHERE data_broker_id = db.id)
                WHERE db.is_active = 1
                AND (db.health_status IN ('degraded', 'broken', 'changed')
                     OR db.last_health_check IS NULL
                     OR db.last_health_check < DATE_SUB(NOW(), INTERVAL 7 DAY))
                ORDER BY
                    CASE db.health_status
                        WHEN 'broken' THEN 1
                        WHEN 'changed' THEN 2
                        WHEN 'degraded' THEN 3
                        ELSE 4
                    END,
                    db.last_health_check ASC
                LIMIT 20
            ");

            // Average response times
            $avgResponseTime = DB::selectOne("
                SELECT AVG(response_time_ms) as avg_ms,
                       MAX(response_time_ms) as max_ms,
                       MIN(response_time_ms) as min_ms
                FROM broker_health_checks
                WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");

            // Brokers requiring CAPTCHA
            $captchaCount = DB::selectOne("
                SELECT COUNT(*) as count
                FROM data_brokers
                WHERE is_active = 1 AND requires_captcha = 1
            ");

            return [
                'summary' => [
                    'total_active' => (int) ($healthSummary->total ?? 0),
                    'healthy' => (int) ($healthSummary->healthy ?? 0),
                    'degraded' => (int) ($healthSummary->degraded ?? 0),
                    'broken' => (int) ($healthSummary->broken ?? 0),
                    'changed' => (int) ($healthSummary->changed ?? 0),
                ],
                'problem_brokers' => array_map(fn($b) => [
                    'broker_id' => $b->broker_id,
                    'name' => $b->broker_name,
                    'domain' => $b->domain,
                    'health_status' => $b->health_status,
                    'last_check' => $b->last_health_check,
                    'response_time_ms' => $b->response_time_ms ? (int) $b->response_time_ms : null,
                    'check_status' => $b->check_status,
                ], $recentChecks),
                'response_times' => [
                    'avg_ms' => round((float) ($avgResponseTime->avg_ms ?? 0)),
                    'max_ms' => (int) ($avgResponseTime->max_ms ?? 0),
                    'min_ms' => (int) ($avgResponseTime->min_ms ?? 0),
                ],
                'captcha_required' => (int) ($captchaCount->count ?? 0),
            ];
        } catch (\Exception $e) {
            Log::error('DataRemovalOpsService::getBrokerHealth failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get request pipeline status — where requests are stuck,
     * follow-up overdue, age distribution.
     */
    public function getRequestStatus(): array
    {
        try {
            // Follow-up overdue (submitted but no confirmation within follow-up interval)
            $followupInterval = (int) config('data_removal.followup_interval_days', 7);
            $maxFollowups = (int) config('data_removal.max_followups', 3);

            $overdueFollowups = DB::selectOne("
                SELECT COUNT(*) as count
                FROM removal_requests
                WHERE status = 'submitted'
                AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ", [$followupInterval]);

            // Stale pending (pending for >30 days without action)
            $stalePending = DB::selectOne("
                SELECT COUNT(*) as count
                FROM removal_requests
                WHERE status = 'pending'
                AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");

            // Request age distribution
            $ageDistribution = DB::select("
                SELECT
                    CASE
                        WHEN DATEDIFF(NOW(), created_at) <= 1 THEN 'today'
                        WHEN DATEDIFF(NOW(), created_at) <= 7 THEN 'this_week'
                        WHEN DATEDIFF(NOW(), created_at) <= 30 THEN 'this_month'
                        WHEN DATEDIFF(NOW(), created_at) <= 90 THEN 'this_quarter'
                        ELSE 'older'
                    END as age_bucket,
                    status,
                    COUNT(*) as count
                FROM removal_requests
                GROUP BY age_bucket, status
                ORDER BY count DESC
            ");

            // D2: removal_activity_log table dropped (2026-03-16)
            $recentActivity = [];

            return [
                'followup_overdue' => (int) ($overdueFollowups->count ?? 0),
                'followup_interval_days' => $followupInterval,
                'stale_pending' => (int) ($stalePending->count ?? 0),
                'age_distribution' => array_map(fn($d) => [
                    'bucket' => $d->age_bucket,
                    'status' => $d->status,
                    'count' => (int) $d->count,
                ], $ageDistribution),
                'recent_activity_7d' => array_map(fn($a) => [
                    'action' => $a->activity_type,
                    'count' => (int) $a->count,
                ], $recentActivity),
            ];
        } catch (\Exception $e) {
            Log::error('DataRemovalOpsService::getRequestStatus failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get effectiveness metrics — per-broker and overall success rates,
     * average days to removal, trends.
     */
    public function getEffectivenessMetrics(): array
    {
        // removal_effectiveness table dropped per D2 decision (2026-03-16).
        // Derive basic metrics from removal_requests instead.
        try {
            $completionTimes = DB::select("
                SELECT
                    CASE
                        WHEN DATEDIFF(confirmed_at, created_at) <= 3 THEN '0-3 days'
                        WHEN DATEDIFF(confirmed_at, created_at) <= 7 THEN '4-7 days'
                        WHEN DATEDIFF(confirmed_at, created_at) <= 14 THEN '8-14 days'
                        WHEN DATEDIFF(confirmed_at, created_at) <= 30 THEN '15-30 days'
                        ELSE '30+ days'
                    END as time_bucket,
                    COUNT(*) as count
                FROM removal_requests
                WHERE status = 'confirmed'
                AND confirmed_at IS NOT NULL
                GROUP BY time_bucket
                ORDER BY count DESC
            ");

            $counts = DB::selectOne("
                SELECT
                    SUM(CASE WHEN status = 'submitted' OR status = 'confirmed' OR status = 'failed' THEN 1 ELSE 0 END) as total_submitted,
                    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as total_confirmed,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed
                FROM removal_requests
            ");

            $totalSubmitted = (int) ($counts->total_submitted ?? 0);
            $totalConfirmed = (int) ($counts->total_confirmed ?? 0);

            return [
                'overall' => [
                    'avg_success_rate' => $totalSubmitted > 0 ? round(($totalConfirmed / $totalSubmitted) * 100, 1) : 0,
                    'avg_days_to_removal' => 0,
                    'total_submitted' => $totalSubmitted,
                    'total_confirmed' => $totalConfirmed,
                    'total_failed' => (int) ($counts->total_failed ?? 0),
                    'total_relistings' => 0,
                ],
                'by_broker' => [],
                'completion_times' => array_map(fn($d) => [
                    'bucket' => $d->time_bucket,
                    'count' => (int) $d->count,
                ], $completionTimes),
            ];
        } catch (\Exception $e) {
            Log::error('DataRemovalOpsService::getEffectivenessMetrics failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get relisting detection — data that reappeared after confirmed removal.
     * Privacy-critical metric.
     */
    public function getRelistingDetection(): array
    {
        try {
            // Recent relistings
            $relistings = DB::select("
                SELECT rr.id as request_id, rr.broker_id,
                       db.name as broker_name, db.domain,
                       rr.relisting_count, rr.relisting_detected_at,
                       rr.confirmed_at as original_removal_date,
                       DATEDIFF(rr.relisting_detected_at, rr.confirmed_at) as days_until_relisting
                FROM removal_requests rr
                JOIN data_brokers db ON db.id = rr.broker_id
                WHERE rr.relisting_detected_at IS NOT NULL
                AND rr.relisting_detected_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                ORDER BY rr.relisting_detected_at DESC
                LIMIT 20
            ");

            // Relistings by broker
            $byBroker = DB::select("
                SELECT db.name as broker_name, db.domain,
                       COUNT(*) as relisting_count,
                       AVG(DATEDIFF(rr.relisting_detected_at, rr.confirmed_at)) as avg_days_to_relist
                FROM removal_requests rr
                JOIN data_brokers db ON db.id = rr.broker_id
                WHERE rr.relisting_detected_at IS NOT NULL
                GROUP BY db.id, db.name, db.domain
                ORDER BY relisting_count DESC
                LIMIT 10
            ");

            // Total relisting stats
            $totalRelistings = DB::selectOne("
                SELECT COUNT(*) as total_relistings,
                       COUNT(DISTINCT broker_id) as brokers_relisting,
                       SUM(relisting_count) as total_relist_events
                FROM removal_requests
                WHERE relisting_detected_at IS NOT NULL
            ");

            return [
                'total' => [
                    'requests_with_relistings' => (int) ($totalRelistings->total_relistings ?? 0),
                    'brokers_relisting' => (int) ($totalRelistings->brokers_relisting ?? 0),
                    'total_relist_events' => (int) ($totalRelistings->total_relist_events ?? 0),
                ],
                'recent_90d' => array_map(fn($r) => [
                    'request_id' => $r->request_id,
                    'broker_name' => $r->broker_name,
                    'domain' => $r->domain,
                    'relisting_count' => (int) $r->relisting_count,
                    'detected_at' => $r->relisting_detected_at,
                    'days_until_relisting' => (int) ($r->days_until_relisting ?? 0),
                ], $relistings),
                'by_broker' => array_map(fn($b) => [
                    'name' => $b->broker_name,
                    'domain' => $b->domain,
                    'count' => (int) $b->relisting_count,
                    'avg_days_to_relist' => round((float) ($b->avg_days_to_relist ?? 0), 1),
                ], $byBroker),
            ];
        } catch (\Exception $e) {
            Log::error('DataRemovalOpsService::getRelistingDetection failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get proof-of-removal archive coverage — completeness of proof
     * collection for confirmed removals.
     */
    public function getProofCoverage(): array
    {
        // removal_proof_archive table dropped per D2 decision (2026-03-16).
        // Return basic confirmed count from removal_requests.
        try {
            $confirmed = DB::selectOne("
                SELECT COUNT(*) as count FROM removal_requests WHERE status = 'confirmed'
            ");

            $totalConfirmed = (int) ($confirmed->count ?? 0);

            return [
                'total_confirmed' => $totalConfirmed,
                'with_proof' => 0,
                'without_proof' => $totalConfirmed,
                'coverage_pct' => 0,
                'proof_types' => [],
                'recent_30d' => [
                    'confirmed' => 0,
                    'with_proof' => 0,
                    'capture_rate_pct' => 0,
                ],
                'note' => 'Proof archive table dropped per D2 decision. Proof tracking not available.',
            ];
        } catch (\Exception $e) {
            Log::error('DataRemovalOpsService::getProofCoverage failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get review queue status — pending human reviews for removal requests.
     */
    public function getReviewQueue(): array
    {
        try {
            // Requests awaiting review
            $pendingReview = DB::selectOne("
                SELECT COUNT(*) as count,
                       MIN(created_at) as oldest
                FROM removal_requests
                WHERE status IN ('pending', 'approved')
                AND ai_confidence IS NOT NULL
            ");

            // By AI confidence tier
            $byConfidence = DB::select("
                SELECT
                    CASE
                        WHEN ai_confidence >= 90 THEN 'high (90-100)'
                        WHEN ai_confidence >= 75 THEN 'medium (75-90)'
                        WHEN ai_confidence >= 50 THEN 'low (50-75)'
                        ELSE 'very_low (<50)'
                    END as confidence_tier,
                    COUNT(*) as count
                FROM removal_requests
                WHERE status = 'pending'
                AND ai_confidence IS NOT NULL
                GROUP BY confidence_tier
                ORDER BY count DESC
            ");

            return [
                'pending_reviews' => (int) ($pendingReview->count ?? 0),
                'oldest_pending' => $pendingReview->oldest ?? null,
                'by_confidence' => array_map(fn($d) => [
                    'tier' => $d->confidence_tier,
                    'count' => (int) $d->count,
                ], $byConfidence),
            ];
        } catch (\Exception $e) {
            Log::error('DataRemovalOpsService::getReviewQueue failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // ACT TOOLS
    // =========================================================================

    /**
     * Trigger broker health check for degraded/broken brokers.
     * Read-only operation that checks opt-out page availability.
     */
    public function triggerBrokerHealthCheck(int $brokerId = 0): array
    {
        try {
            $healthService = app(BrokerHealthService::class);

            if ($brokerId > 0) {
                // Check specific broker
                $broker = DB::selectOne("SELECT id, name, domain FROM data_brokers WHERE id = ?", [$brokerId]);
                if (!$broker) {
                    return ['error' => "Broker ID {$brokerId} not found"];
                }

                $result = $healthService->checkBroker($brokerId);
                return [
                    'checked' => 1,
                    'results' => [[
                        'broker_id' => $brokerId,
                        'name' => $broker->name,
                        'status' => $result['status'] ?? 'unknown',
                        'response_time_ms' => $result['response_time_ms'] ?? null,
                    ]],
                ];
            }

            // Check all degraded/broken brokers
            $problematic = DB::select("
                SELECT id, name, domain
                FROM data_brokers
                WHERE is_active = 1
                AND health_status IN ('degraded', 'broken', 'changed')
                LIMIT " . config('data_removal.broker_health_check_batch', 10) . "
            ");

            $results = [];
            foreach ($problematic as $broker) {
                try {
                    $result = $healthService->checkBroker($broker->id);
                    $results[] = [
                        'broker_id' => $broker->id,
                        'name' => $broker->name,
                        'status' => $result['status'] ?? 'unknown',
                        'response_time_ms' => $result['response_time_ms'] ?? null,
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'broker_id' => $broker->id,
                        'name' => $broker->name,
                        'status' => 'check_failed',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return [
                'checked' => count($results),
                'results' => $results,
            ];
        } catch (\Exception $e) {
            Log::error('DataRemovalOpsService::triggerBrokerHealthCheck failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Flag stale requests — overdue follow-ups and long-pending requests
     * that need human attention. Creates review queue entries.
     */
    public function flagStaleRequests(int $overdueDays = 14): array
    {
        try {
            // Find overdue submitted requests
            $staleRequests = DB::select("
                SELECT rr.id, rr.broker_id, rr.status,
                       DATEDIFF(NOW(), rr.updated_at) as days_stale,
                       db.name as broker_name
                FROM removal_requests rr
                JOIN data_brokers db ON db.id = rr.broker_id
                WHERE rr.status = 'submitted'
                AND rr.updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY rr.updated_at ASC
                LIMIT " . config('data_removal.flag_stale_batch', 20) . "
            ", [$overdueDays]);

            $flagged = 0;
            foreach ($staleRequests as $req) {
                try {
                    $token = bin2hex(random_bytes(32));
                    DB::insert("
                        INSERT INTO agent_review_queue
                        (agent_id, review_type, title, summary, details, priority, confidence, status, token, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
                    ", [
                        'data-removal-ops',
                        'data_removal_followup',
                        "Stale removal request: {$req->broker_name} ({$req->days_stale} days)",
                        "Removal request #{$req->id} for {$req->broker_name} has been stale for {$req->days_stale} days (status: {$req->status})",
                        json_encode([
                            'broker_id' => $req->broker_id,
                            'broker_name' => $req->broker_name,
                            'days_stale' => $req->days_stale,
                            'status' => $req->status,
                            'request_id' => $req->id,
                        ]),
                        $req->days_stale > 30 ? 1 : 0,
                        0.6,
                        $token,
                    ]);
                    $flagged++;
                } catch (\Exception $e) {
                    // Skip duplicates
                }
            }

            return [
                'flagged' => $flagged,
                'overdue_threshold_days' => $overdueDays,
                'total_found' => count($staleRequests),
            ];
        } catch (\Exception $e) {
            Log::error('DataRemovalOpsService::flagStaleRequests failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Flag confirmed relistings for urgent human attention.
     * Privacy-critical escalation.
     */
    public function flagRelistings(): array
    {
        try {
            // Find recent unreviewed relistings
            $relistings = DB::select("
                SELECT rr.id, rr.broker_id, rr.relisting_count,
                       rr.relisting_detected_at,
                       db.name as broker_name, db.domain
                FROM removal_requests rr
                JOIN data_brokers db ON db.id = rr.broker_id
                WHERE rr.relisting_detected_at IS NOT NULL
                AND rr.relisting_detected_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND rr.id NOT IN (
                    SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(details, '$.request_id')) AS UNSIGNED)
                    FROM agent_review_queue
                    WHERE review_type = 'data_removal_relisting'
                    AND status = 'pending'
                )
                ORDER BY rr.relisting_detected_at DESC
                LIMIT " . config('data_removal.flag_relistings_batch', 10) . "
            ");

            $flagged = 0;
            foreach ($relistings as $relist) {
                try {
                    $token = bin2hex(random_bytes(32));
                    DB::insert("
                        INSERT INTO agent_review_queue
                        (agent_id, review_type, title, summary, details, priority, confidence, status, token, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
                    ", [
                        'data-removal-ops',
                        'data_removal_relisting',
                        "RELISTING: {$relist->broker_name} ({$relist->domain}) - data reappeared",
                        "Data reappeared on {$relist->broker_name} ({$relist->domain}) after removal. Relisting count: {$relist->relisting_count}.",
                        json_encode([
                            'broker_id' => $relist->broker_id,
                            'broker_name' => $relist->broker_name,
                            'domain' => $relist->domain,
                            'relisting_count' => $relist->relisting_count,
                            'detected_at' => $relist->relisting_detected_at,
                            'request_id' => $relist->id,
                        ]),
                        2, // urgent priority
                        0.8,
                        $token,
                    ]);
                    $flagged++;
                } catch (\Exception $e) {
                    // Skip duplicates
                }
            }

            return [
                'flagged' => $flagged,
                'total_relistings_found' => count($relistings),
                'message' => $flagged > 0
                    ? "Flagged {$flagged} relistings for urgent review"
                    : 'No new relistings to flag',
            ];
        } catch (\Exception $e) {
            Log::error('DataRemovalOpsService::flagRelistings failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }
}
