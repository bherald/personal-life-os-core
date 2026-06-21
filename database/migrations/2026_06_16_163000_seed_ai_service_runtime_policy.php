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
            'short_prompt_fast_threshold_chars' => 100,
            'available_models_connect_timeout_seconds' => 5,
            'available_models_request_timeout_seconds' => 10,
            'available_models_cache_ttl_seconds' => 300,
            'model_profiles_cache_ttl_seconds' => 60,
            'policy_notes' => [
                'short_prompt_fast_threshold_chars' => 'Prompt length below this threshold may route to the fast model role unless explanation is requested.',
                'available_models_connect_timeout_seconds' => 'Connect timeout for Ollama /api/tags model-discovery calls.',
                'available_models_request_timeout_seconds' => 'Request timeout for Ollama /api/tags model-discovery calls.',
                'available_models_cache_ttl_seconds' => 'Cache TTL for discovered Ollama model lists.',
                'model_profiles_cache_ttl_seconds' => 'Cache TTL for llm_model_profiles role maps.',
                'ownership' => 'AIService gateway runtime defaults are table-owned here; code constants are fallback-only.',
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
            ->where('config_key', 'service_runtime_policy')
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
            ['section' => 'ai', 'config_key' => 'service_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for AIService gateway model discovery and model-role cache behavior.',
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
            ->where('config_key', 'service_runtime_policy')
            ->delete();
    }
};
