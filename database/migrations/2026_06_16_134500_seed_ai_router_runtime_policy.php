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
            'text_generation_timeout_seconds' => 120,
            'default_max_tokens' => 4096,
            'embedding_char_limit' => 30000,
            'vision_cli_timeout_seconds' => 180,
            'policy_notes' => [
                'text_generation_timeout_seconds' => 'Fallback text-generation timeout when a request/provider/node does not provide an explicit timeout.',
                'default_max_tokens' => 'Fallback max token budget when a request/provider/node does not provide max_tokens.',
                'embedding_char_limit' => 'Fallback maximum input characters retained before generating local embeddings.',
                'vision_cli_timeout_seconds' => 'Hard deadline for Claude CLI image analysis subprocess output draining.',
                'ownership' => 'AIRouter fallback runtime defaults are table-owned here; llm_instances, request config, provider config, and node config remain higher-priority routing/runtime inputs.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ai')
            ->where('config_key', 'router_runtime_policy')
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
            ['section' => 'ai', 'config_key' => 'router_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for AIRouter fallback timeouts, default token budget, and embedding input limit.',
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
            ->where('section', 'ai')
            ->where('config_key', 'router_runtime_policy')
            ->delete();
    }
};
