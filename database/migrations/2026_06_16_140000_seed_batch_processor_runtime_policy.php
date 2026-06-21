<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'batch_size' => 15,
            'max_articles' => 60,
            'ai_timeout_seconds' => 180,
            'node_timeout_seconds' => 300,
            'notification_reserve_seconds' => 60,
            'min_ai_batch_timeout_seconds' => 90,
            'model_role' => 'quality',
            'max_tokens' => 2048,
            'policy_notes' => [
                'batch_size' => 'Fallback articles per AI formatting batch when workflow node config does not provide batch_size.',
                'max_articles' => 'Fallback maximum articles processed by one node execution when workflow node config does not provide max_articles.',
                'ai_timeout_seconds' => 'Fallback per-batch AI timeout when workflow node config does not provide ai_timeout.',
                'node_timeout_seconds' => 'Fallback overall node timeout budget when workflow execution metadata does not provide timeout_seconds.',
                'notification_reserve_seconds' => 'Fallback reserve left for non-AI notification work inside the node timeout budget.',
                'min_ai_batch_timeout_seconds' => 'Minimum useful per-batch AI budget before deterministic formatting is used.',
                'model_role' => 'Fallback LLM role used for batch formatting when workflow node config does not provide model_role.',
                'max_tokens' => 'Fallback AI max_tokens for batch formatting when workflow node config does not provide max_tokens.',
                'ownership' => 'BatchProcessor fallback runtime defaults are table-owned here; explicit workflow node config remains the per-run override.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'news')
            ->where('config_key', 'batch_processor_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);

        $existingNotes = $existing['policy_notes'] ?? null;
        $policy['policy_notes'] = is_array($existingNotes)
            ? array_replace($defaultPolicy['policy_notes'], $existingNotes)
            : $defaultPolicy['policy_notes'];

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'news', 'config_key' => 'batch_processor_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for BatchProcessor fallback batch sizing, article cap, AI timing, and formatting role.',
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
            ->where('section', 'news')
            ->where('config_key', 'batch_processor_runtime_policy')
            ->delete();
    }
};
