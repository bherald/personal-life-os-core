<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')->updateOrInsert(
                ['section' => 'ops', 'config_key' => 'llm_portfolio_review_policy'],
                [
                    'config_value' => json_encode([
                        'enabled' => true,
                        'minimum_days_between_reviews' => 14,
                        'required_capability_lanes' => [
                            'fast_text',
                            'standard_text',
                            'quality_reasoning',
                            'coding',
                            'tool_calling',
                            'structured_output',
                            'uncensored_operator',
                            'embeddings',
                            'reranking',
                            'vision_image_input',
                            'image_generation_output',
                            'ocr_document_input',
                            'speech_to_text_input',
                            'text_to_speech_output',
                            'video_input',
                            'video_output',
                            'other_media_input',
                            'other_media_output',
                        ],
                        'inventory' => [
                            'connect_timeout_seconds' => 5,
                            'timeout_seconds' => 20,
                            'include_inactive' => true,
                            'probe_live' => true,
                        ],
                        'codex' => [
                            'provider_instance_id' => 'codex_exec',
                            'model_role' => 'quality',
                            'reasoning_effort' => 'high',
                            'timeout_seconds' => 900,
                            'official_sources_required' => true,
                            'candidate_source_classes' => [
                                'official_hugging_face_organization_or_model_card',
                                'official_repository_or_release_notes',
                                'vendor_model_catalog',
                                'technical_report_or_research_paper',
                                'credible_independent_evaluation',
                                'sustained_community_feedback_as_lead_only',
                                'local_plos_telemetry',
                            ],
                            'require_exact_revision' => true,
                            'require_license' => true,
                            'require_evidence_grade' => true,
                            'read_only' => true,
                        ],
                        'review' => [
                            'review_type' => 'ai_model_update',
                            'agent_id' => 'codex-portfolio',
                            'deduplicate_pending' => true,
                            'close_when_no_action' => true,
                        ],
                        'execution' => [
                            'approval_required' => true,
                            'executor' => 'Codex RTC using exact action validation and rollback gates',
                            'automatic_install' => false,
                            'automatic_promote' => false,
                            'automatic_retire' => false,
                            'automatic_route_change' => false,
                        ],
                    ], JSON_UNESCAPED_SLASHES),
                    'data_type' => 'json',
                    'description' => 'Biweekly deterministic inventory plus read-only Codex LLM portfolio recommendation and operator-review policy.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if (Schema::hasTable('scheduled_jobs')) {
            $this->scheduleReview();
        }

        if (Schema::hasTable('llm_instances')) {
            $this->allowTrackedPortfolioSchema();
        }

        Cache::forget('syscfg:ops.llm_portfolio_review_policy');
        Cache::forget('llm_instances_all');
        Cache::forget('external_api_providers');
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')->where('name', 'llm_portfolio_review')->delete();
        }

        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')
                ->where('section', 'ops')
                ->whereIn('config_key', ['llm_portfolio_review_policy', 'llm_portfolio_review_state'])
                ->delete();
        }

        if (Schema::hasTable('llm_instances')) {
            $row = DB::table('llm_instances')->where('instance_id', 'codex_exec')->first();
            if ($row !== null) {
                $config = $this->decodeMap($row->config ?? null);
                $structured = $this->decodeMap($config['structured_output'] ?? []);
                $roots = array_values(array_filter(
                    array_map('strval', is_array($structured['schema_file_roots'] ?? null) ? $structured['schema_file_roots'] : []),
                    static fn (string $root): bool => $root !== resource_path('schemas')
                ));
                $structured['schema_file_roots'] = $roots;
                $config['structured_output'] = $structured;
                DB::table('llm_instances')->where('id', $row->id)->update([
                    'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function scheduleReview(): void
    {
        $now = now();
        $values = [
            'description' => 'Biweekly LLM portfolio inventory and Codex recommendation packet for operator review.',
            'job_type' => 'command',
            'command' => 'ops:llm-portfolio-review --json --compact',
            'cron_expression' => '30 6 * * 0',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 30,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => 'Ops',
            'runtime_mode' => 'maintenance',
            'workload_family' => 'ops',
            'resource_profile' => 'network',
            'stall_policy' => 'strict',
            'backlog_metric' => 'llm_portfolio_review_due',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'summary' => 'Runs Sundays at 06:30 and the table-owned fourteen-day due gate executes every second Sunday.',
                'policy_source' => 'system_configs.ops.llm_portfolio_review_policy',
                'operator_surface' => ['5am PLOS report', 'Daily Operations workboard', 'Review Hub', 'Operator Evidence'],
                'execution' => [
                    'inventory_first' => true,
                    'codex_read_only' => true,
                    'writes_deduplicated_review' => true,
                    'automatic_install_or_route_change' => false,
                    'operator_approval_required' => true,
                ],
            ], JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];

        $existing = DB::table('scheduled_jobs')->where('name', 'llm_portfolio_review')->first(['id']);
        if ($existing !== null) {
            DB::table('scheduled_jobs')->where('id', $existing->id)->update($values);

            return;
        }

        DB::table('scheduled_jobs')->insert(array_merge([
            'name' => 'llm_portfolio_review',
            'created_at' => $now,
        ], $values));
    }

    private function allowTrackedPortfolioSchema(): void
    {
        $row = DB::table('llm_instances')->where('instance_id', 'codex_exec')->first();
        if ($row === null) {
            return;
        }

        $config = $this->decodeMap($row->config ?? null);
        $structured = $this->decodeMap($config['structured_output'] ?? []);
        $roots = array_map('strval', is_array($structured['schema_file_roots'] ?? null) ? $structured['schema_file_roots'] : []);
        $roots[] = resource_path('schemas');
        $structured['schema_file_roots'] = array_values(array_unique($roots));
        $config['structured_output'] = $structured;

        DB::table('llm_instances')->where('id', $row->id)->update([
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
