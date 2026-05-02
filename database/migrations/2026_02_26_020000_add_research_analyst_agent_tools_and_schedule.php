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
                'name' => 'research_topic_coverage',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getTopicCoverage',
                'description' => 'Get detailed topic coverage analysis — per-topic result counts, quality scores, recency, and coverage gaps (zero results or zero approved). Identifies low-quality topics and stale topics with no recent results.',
                'parameters' => '[]',
                'returns_description' => 'Array with per-topic stats, coverage_gaps, low_quality_topics, stale_topics, and summary counts',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],
            [
                'name' => 'research_pending_results',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getPendingResults',
                'description' => 'Get pending research results awaiting review, sorted by AI quality score (highest first). Shows AI assessment including quality score, has_findings flag, recommendation, and dedup status.',
                'parameters' => json_encode([
                    'limit' => ['type' => 'integer', 'required' => false, 'description' => 'Max results to return (default 20)'],
                ]),
                'returns_description' => 'Array with pending_results list and summary (total_pending, with_findings, high_quality counts)',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],
            [
                'name' => 'research_trends',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getResearchTrends',
                'description' => 'Get cross-topic research trends — category distribution with topic/result counts, weekly volume over last 8 weeks with approval rates, and fact extraction effectiveness (findings rate, fact extraction rate).',
                'parameters' => '[]',
                'returns_description' => 'Array with categories breakdown, weekly_volume trend, and fact_extraction stats',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],

            // ── ANALYZE PHASE ─────────────────────────────────────────────
            [
                'name' => 'research_result_detail',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'getResultDetail',
                'description' => 'Get a specific research result\'s full AI output for content quality review. Includes AI output text, extracted facts, quality scores, dedup info, and review status. Use sparingly — only for ambiguous results (quality 0.3-0.7).',
                'parameters' => json_encode([
                    'result_id' => ['type' => 'integer', 'required' => true, 'description' => 'Research result ID to inspect'],
                ]),
                'returns_description' => 'Array with full result detail including ai_output, extracted_facts, quality metrics, and review status',
                'permissions' => '["research:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],
            [
                'name' => 'research_knowledge_search',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'searchResearchKnowledge',
                'description' => 'Search the RAG knowledge base for existing research content. Useful for checking what\'s already been indexed to detect novelty vs redundancy in pending results.',
                'parameters' => json_encode([
                    'query' => ['type' => 'string', 'required' => true, 'description' => 'Search query for RAG knowledge base'],
                    'limit' => ['type' => 'integer', 'required' => false, 'description' => 'Max results to return (default 10)'],
                ]),
                'returns_description' => 'Array with search results including title, relevance score, and content preview',
                'permissions' => '["rag:read"]',
                'risk_level' => 'read',
                'category' => 'research',
            ],

            // ── ACT PHASE ─────────────────────────────────────────────────
            [
                'name' => 'research_approve_result',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'approveResult',
                'description' => 'Approve a pending research result. Use when ai_quality_score >= 0.7 and ai_has_findings is true. Approved results become available for RAG indexing by knowledge-curator.',
                'parameters' => json_encode([
                    'result_id' => ['type' => 'integer', 'required' => true, 'description' => 'Research result ID to approve'],
                ]),
                'returns_description' => 'Array with result_id, status confirmation, and success message',
                'permissions' => '["research:write"]',
                'risk_level' => 'write',
                'category' => 'research',
                'max_calls_per_run' => 15,
            ],
            [
                'name' => 'research_skip_result',
                'service_class' => 'App\\Services\\ResearchOpsService',
                'method' => 'skipResult',
                'description' => 'Skip (reject) a pending research result with a reason. Use when ai_quality_score < 0.3, no findings, or dedup_status is duplicate.',
                'parameters' => json_encode([
                    'result_id' => ['type' => 'integer', 'required' => true, 'description' => 'Research result ID to skip'],
                    'reason' => ['type' => 'string', 'required' => false, 'description' => 'Reason for skipping (e.g., "low quality", "duplicate content", "no findings")'],
                ]),
                'returns_description' => 'Array with result_id, status, reason, and confirmation message',
                'permissions' => '["research:write"]',
                'risk_level' => 'write',
                'category' => 'research',
                'max_calls_per_run' => 15,
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

        // Add scheduled job for research-analyst agent
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'research_analyst_agent'");
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, command, cron_expression, job_type, enabled, category,
                 timeout_minutes, run_in_background, without_overlapping, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'research_analyst_agent',
                'Research content analyst: reviews pending results, approves/skips by quality score, identifies coverage gaps, tracks research trends',
                'research:analyst',
                '0 */6 * * *',
                'agent_task',
                1,
                'Agent',
                15,
                1,
                1,
                json_encode(['notify' => true]),
            ]);
        }

        // Also add scheduled job for research-ops agent (was in previous migration with wrong command)
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'research_ops_agent'");
        if ($exists) {
            // Fix the command name to match the artisan command
            DB::update("UPDATE scheduled_jobs SET command = 'research:operations' WHERE name = 'research_ops_agent'");
        }
    }

    public function down(): void
    {
        $toolNames = [
            'research_topic_coverage', 'research_pending_results', 'research_trends',
            'research_result_detail', 'research_knowledge_search',
            'research_approve_result', 'research_skip_result',
        ];

        $placeholders = implode(',', array_fill(0, count($toolNames), '?'));
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ($placeholders)", $toolNames);
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'research_analyst_agent'");
    }
};
