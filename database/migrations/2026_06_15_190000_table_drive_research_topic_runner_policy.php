<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function topicRunnerPolicy(): array
    {
        return [
            'enabled' => true,
            'topic_selection' => [
                'default_max_topics' => 10,
            ],
            'runtime' => [
                'low_remaining_stop_seconds' => 120,
                'completion_mark_buffer_seconds' => 60,
                'completion_mark_min_elapsed_seconds' => 60,
                'default_timeout_safety_buffer_seconds' => 60,
                'default_minimum_timeout_seconds' => 60,
            ],
            'orchestrator' => [
                'requested_timeout_seconds' => 1800,
                'timeout_safety_buffer_seconds' => 180,
                'minimum_timeout_seconds' => 300,
                'minimum_mission_minutes' => 5,
                'default_max_sources' => 20,
                'max_verification_facts' => 12,
            ],
            'claude' => [
                'depth_timeout_seconds' => [
                    'NORMAL' => 180,
                    'DEEP' => 300,
                    'EXHAUSTIVE' => 600,
                ],
            ],
            'hybrid' => [
                'mission_requested_timeout_seconds' => 900,
                'mission_timeout_safety_buffer_seconds' => 180,
                'mission_minimum_timeout_seconds' => 180,
                'minimum_mission_minutes' => 3,
                'orchestrator_max_sources_cap' => 15,
                'synthesis_requested_timeout_seconds' => 120,
                'synthesis_timeout_safety_buffer_seconds' => 60,
                'synthesis_minimum_timeout_seconds' => 60,
                'synthesis_max_tokens' => 2000,
            ],
            'genealogy_deep_research' => [
                'time_limit_seconds' => 180,
                'max_results_multiplier' => 3,
                'per_query_limit' => 15,
                'description_preview_chars' => 300,
            ],
            'rag' => [
                'search_limit' => 5,
                'context_content_limit_chars' => 1500,
            ],
            'tools' => [
                'default_max_sources' => 10,
                'default_date_filter_days' => 30,
            ],
            'ai_triage' => [
                'truncate_chars' => 2000,
                'max_tokens' => 200,
                'timeout_seconds' => 15,
                'retry_limit' => 20,
            ],
            'synthesis' => [
                'max_tokens' => 4000,
                'timeout_seconds' => 120,
            ],
            'graphlit' => [
                'web_context_limit' => 10,
                'kb_context_limit' => 5,
                'podcast_context_limit' => 5,
                'synthesis_max_tokens' => 2500,
            ],
            'legacy' => [
                'default_max_sources' => 5,
                'rag_search_limit' => 5,
                'max_tokens' => 2000,
            ],
            'safety' => [
                'policy_source' => 'system_configs.research.topic_runner_runtime_policy',
                'code_defaults_allowed' => false,
                'notes' => 'ResearchTopicRunner runtime limits, source caps, token caps, and timeout budgets are table-owned.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'research', 'config_key' => 'topic_runner_runtime_policy'],
            [
                'config_value' => json_encode($this->topicRunnerPolicy(), JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Table-owned runtime policy for ResearchTopicRunner timeouts, limits, and token budgets.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'research')
            ->where('config_key', 'topic_runner_runtime_policy')
            ->delete();
    }
};
