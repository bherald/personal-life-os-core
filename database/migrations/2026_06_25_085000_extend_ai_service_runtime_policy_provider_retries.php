<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policyExtension(): array
    {
        return [
            'provider_retry_max_attempts' => 2,
            'provider_retry_initial_backoff_ms' => 500,
            'provider_retry_max_backoff_ms' => 4000,
            'provider_retry_backoff_multiplier' => 2,
            'policy_notes' => [
                'provider_retry_max_attempts' => 'Maximum total attempts per provider before AIService falls back to the next provider.',
                'provider_retry_initial_backoff_ms' => 'Initial provider retry backoff in milliseconds before jitter is applied.',
                'provider_retry_max_backoff_ms' => 'Maximum provider retry backoff in milliseconds after jitter and exponential growth.',
                'provider_retry_backoff_multiplier' => 'Exponential multiplier for provider retry backoff growth.',
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

        $extension = $this->policyExtension();
        $policy = array_replace($extension, $existing);
        $policy['policy_notes'] = array_replace(
            $extension['policy_notes'],
            isset($existing['policy_notes']) && is_array($existing['policy_notes'])
                ? $existing['policy_notes']
                : []
        );

        $payload = [
            'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
            'data_type' => 'json',
            'description' => 'Runtime policy for AIService gateway model discovery, cache behavior, slot timeout alerting, and provider retries.',
            'updated_at' => now(),
        ];

        if ($row === null) {
            $payload['created_at'] = now();

            DB::table('system_configs')->insert(array_merge([
                'section' => 'ai',
                'config_key' => 'service_runtime_policy',
            ], $payload));

            return;
        }

        DB::table('system_configs')
            ->where('section', 'ai')
            ->where('config_key', 'service_runtime_policy')
            ->update($payload);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ai')
            ->where('config_key', 'service_runtime_policy')
            ->first(['config_value']);

        if ($row === null) {
            return;
        }

        $policy = json_decode((string) $row->config_value, true);
        if (! is_array($policy)) {
            return;
        }

        unset(
            $policy['provider_retry_max_attempts'],
            $policy['provider_retry_initial_backoff_ms'],
            $policy['provider_retry_max_backoff_ms'],
            $policy['provider_retry_backoff_multiplier']
        );

        if (isset($policy['policy_notes']) && is_array($policy['policy_notes'])) {
            unset(
                $policy['policy_notes']['provider_retry_max_attempts'],
                $policy['policy_notes']['provider_retry_initial_backoff_ms'],
                $policy['policy_notes']['provider_retry_max_backoff_ms'],
                $policy['policy_notes']['provider_retry_backoff_multiplier']
            );
        }

        DB::table('system_configs')
            ->where('section', 'ai')
            ->where('config_key', 'service_runtime_policy')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
