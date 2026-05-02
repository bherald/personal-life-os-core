<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // =====================================================================
        // 1. FACTCHECK-OPS AGENT TOOLS
        // =====================================================================

        $factcheckTools = [
            // ── ASSESS PHASE ──────────────────────────────────────────────
            [
                'name' => 'factcheck_pipeline_stats',
                'service_class' => 'App\\Services\\FactCheckOpsService',
                'method' => 'getPipelineStats',
                'description' => 'Get fact-check pipeline statistics — claims processed (30d), checkworthiness scores, verdict counts (supported/refuted/inconclusive), evidence coverage, confidence averages, and daily throughput. Baseline assessment tool.',
                'parameters' => '[]',
                'returns_description' => 'Array with claims stats, verdict breakdown, evidence metrics, and daily throughput',
                'permissions' => '["factcheck:read"]',
                'risk_level' => 'read',
                'category' => 'factcheck',
            ],
            [
                'name' => 'factcheck_claim_quality',
                'service_class' => 'App\\Services\\FactCheckOpsService',
                'method' => 'getClaimQuality',
                'description' => 'Get claim quality metrics — checkworthiness score distribution by tier, entity extraction success rate, and claims-per-document breakdown. Shows decomposition effectiveness.',
                'parameters' => '[]',
                'returns_description' => 'Array with checkworthiness_distribution, entity_extraction rates, claims_per_document',
                'permissions' => '["factcheck:read"]',
                'risk_level' => 'read',
                'category' => 'factcheck',
            ],
            [
                'name' => 'factcheck_evidence_health',
                'service_class' => 'App\\Services\\FactCheckOpsService',
                'method' => 'getEvidenceHealth',
                'description' => 'Get evidence health — NLI label distribution (supported/contradicted/neutral), source domain diversity, evidence-per-claim buckets, and credibility score statistics.',
                'parameters' => '[]',
                'returns_description' => 'Array with nli_distribution, source_diversity, evidence_per_claim buckets, credibility stats',
                'permissions' => '["factcheck:read"]',
                'risk_level' => 'read',
                'category' => 'factcheck',
            ],
            [
                'name' => 'factcheck_verdict_distribution',
                'service_class' => 'App\\Services\\FactCheckOpsService',
                'method' => 'getVerdictDistribution',
                'description' => 'Get verdict distribution — supported/refuted/inconclusive ratios with confidence averages, human review rates, confidence tier distribution, and weekly trend.',
                'parameters' => '[]',
                'returns_description' => 'Array with by_verdict breakdown, human_review stats, confidence_distribution, weekly_trend',
                'permissions' => '["factcheck:read"]',
                'risk_level' => 'read',
                'category' => 'factcheck',
            ],
            [
                'name' => 'factcheck_source_credibility_overview',
                'service_class' => 'App\\Services\\FactCheckOpsService',
                'method' => 'getSourceCredibilityOverview',
                'description' => 'Get source credibility overview — trust score distribution, tier breakdown (high/medium/low), stale sources count, lowest-trust and most-cited sources.',
                'parameters' => '[]',
                'returns_description' => 'Array with total_sources, avg_trust_score, tier_breakdown, stale_sources, lowest_trust, most_cited',
                'permissions' => '["factcheck:read"]',
                'risk_level' => 'read',
                'category' => 'factcheck',
            ],
            [
                'name' => 'factcheck_contradiction_queue',
                'service_class' => 'App\\Services\\FactCheckOpsService',
                'method' => 'getContradictionQueue',
                'description' => 'Get contradiction queue — pending contradictions awaiting human review, severity distribution, contradiction types (negation/antonym/numeric/temporal/semantic), and review history.',
                'parameters' => '[]',
                'returns_description' => 'Array with pending counts by severity, by_severity_label, by_type, reviewed_history',
                'permissions' => '["factcheck:read"]',
                'risk_level' => 'read',
                'category' => 'factcheck',
            ],
            [
                'name' => 'factcheck_review_backlog',
                'service_class' => 'App\\Services\\FactCheckOpsService',
                'method' => 'getReviewBacklog',
                'description' => 'Get review backlog — unreviewed verdicts and contradictions count, age of oldest pending, avg confidence of pending verdicts, total backlog size.',
                'parameters' => '[]',
                'returns_description' => 'Array with verdicts pending/oldest, contradictions pending/oldest, total_backlog, verdict_age_distribution',
                'permissions' => '["factcheck:read"]',
                'risk_level' => 'read',
                'category' => 'factcheck',
            ],

            // ── ACT PHASE ─────────────────────────────────────────────────
            [
                'name' => 'factcheck_rerun_failed_claims',
                'service_class' => 'App\\Services\\FactCheckOpsService',
                'method' => 'rerunFailedClaims',
                'description' => 'Rerun checkworthy claims that failed mid-pipeline (have no verdict). LIMIT TO 5 PER RUN. Submit for review first if more need rerun.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 5, 'description' => 'Maximum claims to rerun (default 5)'],
                ]),
                'returns_description' => 'Array with rerun_count, results with claim_id/status/verdict, succeeded/failed counts',
                'permissions' => '["factcheck:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'factcheck',
                'max_calls_per_run' => 1,
            ],
            [
                'name' => 'factcheck_flag_low_confidence_verdicts',
                'service_class' => 'App\\Services\\FactCheckOpsService',
                'method' => 'flagLowConfidenceVerdicts',
                'description' => 'Flag low-confidence verdicts for human review. Creates review queue entries for verdicts below the confidence threshold.',
                'parameters' => json_encode([
                    'threshold' => ['type' => 'number', 'required' => false, 'default' => 0.4, 'description' => 'Confidence threshold below which to flag (default 0.4)'],
                ]),
                'returns_description' => 'Array with flagged count, threshold, total_found',
                'permissions' => '["factcheck:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'factcheck',
                'max_calls_per_run' => 1,
            ],
            [
                'name' => 'factcheck_refresh_stale_sources',
                'service_class' => 'App\\Services\\FactCheckOpsService',
                'method' => 'refreshStaleSources',
                'description' => 'Refresh credibility scores for sources not verified recently. Recalculates composite scores using current data.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'default' => 10, 'description' => 'Maximum sources to refresh (default 10)'],
                    'staleDays' => ['type' => 'integer', 'required' => false, 'default' => 30, 'description' => 'Refresh sources not verified in this many days (default 30)'],
                ]),
                'returns_description' => 'Array with refreshed/failed counts and per-source old/new scores',
                'permissions' => '["factcheck:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'factcheck',
                'requires_confirmation' => 1,
            ],
        ];

        foreach ($factcheckTools as $tool) {
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

        // =====================================================================
        // 2. FACTCHECK-OPS SCHEDULED JOB
        // =====================================================================

        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'factcheck_ops_agent'");
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, command, cron_expression, job_type, enabled, category,
                 timeout_minutes, run_in_background, without_overlapping, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'factcheck_ops_agent',
                'Fact-check pipeline health: claim decomposition, evidence retrieval, NLI ranking, verdict quality',
                'factcheck-ops',
                '0 */6 * * *',
                'agent_task',
                1,
                'Agent',
                10,
                1,
                1,
                json_encode(['notify' => true]),
            ]);
        }

        // =====================================================================
        // 3. DATA-REMOVAL-OPS AGENT TOOLS
        // =====================================================================

        $removalTools = [
            // ── ASSESS PHASE ──────────────────────────────────────────────
            [
                'name' => 'removal_pipeline_stats',
                'service_class' => 'App\\Services\\DataRemovalOpsService',
                'method' => 'getPipelineStats',
                'description' => 'Get data removal pipeline statistics — active subjects, broker counts by tier, request status breakdown (pending/submitted/confirmed/failed), 30-day activity, and daily submission rates.',
                'parameters' => '[]',
                'returns_description' => 'Array with subjects, brokers by tier, requests by status, recent_30d, daily_submissions',
                'permissions' => '["privacy:read"]',
                'risk_level' => 'read',
                'category' => 'privacy',
            ],
            [
                'name' => 'removal_broker_health',
                'service_class' => 'App\\Services\\DataRemovalOpsService',
                'method' => 'getBrokerHealth',
                'description' => 'Get broker health status — healthy/degraded/broken/changed counts, problem brokers with details, response time stats, CAPTCHA-required count.',
                'parameters' => '[]',
                'returns_description' => 'Array with summary counts, problem_brokers list, response_times, captcha_required',
                'permissions' => '["privacy:read"]',
                'risk_level' => 'read',
                'category' => 'privacy',
            ],
            [
                'name' => 'removal_request_status',
                'service_class' => 'App\\Services\\DataRemovalOpsService',
                'method' => 'getRequestStatus',
                'description' => 'Get request pipeline status — follow-up overdue count, stale pending requests, age distribution by status, and recent 7-day activity log.',
                'parameters' => '[]',
                'returns_description' => 'Array with followup_overdue, stale_pending, age_distribution, recent_activity_7d',
                'permissions' => '["privacy:read"]',
                'risk_level' => 'read',
                'category' => 'privacy',
            ],
            [
                'name' => 'removal_effectiveness_metrics',
                'service_class' => 'App\\Services\\DataRemovalOpsService',
                'method' => 'getEffectivenessMetrics',
                'description' => 'Get removal effectiveness — overall and per-broker success rates, avg days to removal, relisting counts, completion time distribution.',
                'parameters' => '[]',
                'returns_description' => 'Array with overall stats, by_broker effectiveness, completion_times',
                'permissions' => '["privacy:read"]',
                'risk_level' => 'read',
                'category' => 'privacy',
            ],
            [
                'name' => 'removal_relisting_detection',
                'service_class' => 'App\\Services\\DataRemovalOpsService',
                'method' => 'getRelistingDetection',
                'description' => 'Get relisting detection — data that reappeared after confirmed removal. Shows recent relistings, by-broker patterns, and days-until-relisting averages. Privacy-critical metric.',
                'parameters' => '[]',
                'returns_description' => 'Array with total relisting stats, recent_90d details, by_broker breakdown',
                'permissions' => '["privacy:read"]',
                'risk_level' => 'read',
                'category' => 'privacy',
            ],
            [
                'name' => 'removal_proof_coverage',
                'service_class' => 'App\\Services\\DataRemovalOpsService',
                'method' => 'getProofCoverage',
                'description' => 'Get proof-of-removal archive coverage — confirmed removals vs captured proofs, proof type distribution, recent capture rate.',
                'parameters' => '[]',
                'returns_description' => 'Array with total_confirmed, with_proof, coverage_pct, proof_types, recent_30d capture rate',
                'permissions' => '["privacy:read"]',
                'risk_level' => 'read',
                'category' => 'privacy',
            ],
            [
                'name' => 'removal_review_queue',
                'service_class' => 'App\\Services\\DataRemovalOpsService',
                'method' => 'getReviewQueue',
                'description' => 'Get review queue status — pending human reviews, AI confidence tier distribution, broker discovery queue size.',
                'parameters' => '[]',
                'returns_description' => 'Array with pending_reviews, oldest_pending, by_confidence tiers, broker_discovery_pending',
                'permissions' => '["privacy:read"]',
                'risk_level' => 'read',
                'category' => 'privacy',
            ],

            // ── ACT PHASE ─────────────────────────────────────────────────
            [
                'name' => 'removal_trigger_broker_health_check',
                'service_class' => 'App\\Services\\DataRemovalOpsService',
                'method' => 'triggerBrokerHealthCheck',
                'description' => 'Trigger broker health check — checks opt-out page availability for a specific broker or all degraded/broken brokers. Read-only verification.',
                'parameters' => json_encode([
                    'brokerId' => ['type' => 'integer', 'required' => false, 'default' => 0, 'description' => 'Specific broker ID to check (0 = all degraded/broken)'],
                ]),
                'returns_description' => 'Array with checked count and results with broker_id/name/status/response_time_ms',
                'permissions' => '["privacy:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'privacy',
                'max_calls_per_run' => 2,
            ],
            [
                'name' => 'removal_flag_stale_requests',
                'service_class' => 'App\\Services\\DataRemovalOpsService',
                'method' => 'flagStaleRequests',
                'description' => 'Flag stale removal requests for human review — overdue follow-ups and long-pending requests. Creates review queue entries.',
                'parameters' => json_encode([
                    'overdueDays' => ['type' => 'integer', 'required' => false, 'default' => 14, 'description' => 'Days after which a submitted request is considered stale (default 14)'],
                ]),
                'returns_description' => 'Array with flagged count, overdue_threshold_days, total_found',
                'permissions' => '["privacy:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'privacy',
                'max_calls_per_run' => 1,
            ],
            [
                'name' => 'removal_flag_relistings',
                'service_class' => 'App\\Services\\DataRemovalOpsService',
                'method' => 'flagRelistings',
                'description' => 'Flag confirmed data relistings for urgent human review. Privacy-critical escalation — data reappeared after confirmed removal.',
                'parameters' => '[]',
                'returns_description' => 'Array with flagged count, total_relistings_found, message',
                'permissions' => '["privacy:read", "system:write"]',
                'risk_level' => 'write',
                'category' => 'privacy',
                'max_calls_per_run' => 1,
            ],
        ];

        foreach ($removalTools as $tool) {
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

        // =====================================================================
        // 4. DATA-REMOVAL-OPS SCHEDULED JOB
        // =====================================================================

        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'data_removal_ops_agent'");
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, command, cron_expression, job_type, enabled, category,
                 timeout_minutes, run_in_background, without_overlapping, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'data_removal_ops_agent',
                'Data removal pipeline health: broker status, request tracking, effectiveness, relisting detection, proof coverage',
                'data-removal-ops',
                '0 */4 * * *',
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
        // Remove factcheck-ops tools
        $factcheckToolNames = [
            'factcheck_pipeline_stats', 'factcheck_claim_quality', 'factcheck_evidence_health',
            'factcheck_verdict_distribution', 'factcheck_source_credibility_overview',
            'factcheck_contradiction_queue', 'factcheck_review_backlog',
            'factcheck_rerun_failed_claims', 'factcheck_flag_low_confidence_verdicts',
            'factcheck_refresh_stale_sources',
        ];
        $placeholders = implode(',', array_fill(0, count($factcheckToolNames), '?'));
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ($placeholders)", $factcheckToolNames);

        // Remove factcheck-ops scheduled job
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'factcheck_ops_agent'");

        // Remove data-removal-ops tools
        $removalToolNames = [
            'removal_pipeline_stats', 'removal_broker_health', 'removal_request_status',
            'removal_effectiveness_metrics', 'removal_relisting_detection',
            'removal_proof_coverage', 'removal_review_queue',
            'removal_trigger_broker_health_check', 'removal_flag_stale_requests',
            'removal_flag_relistings',
        ];
        $placeholders = implode(',', array_fill(0, count($removalToolNames), '?'));
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ($placeholders)", $removalToolNames);

        // Remove data-removal-ops scheduled job
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'data_removal_ops_agent'");
    }
};
