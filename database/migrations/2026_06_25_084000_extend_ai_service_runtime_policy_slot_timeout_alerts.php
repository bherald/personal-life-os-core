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
            'slot_timeout_counter_ttl_seconds' => 600,
            'slot_timeout_alert_threshold' => 3,
            'slot_timeout_alert_cooldown_seconds' => 5400,
            'policy_notes' => [
                'slot_timeout_counter_ttl_seconds' => 'Cache TTL for consecutive Claude CLI slot timeout tracking.',
                'slot_timeout_alert_threshold' => 'Consecutive Claude CLI slot timeouts required before an alert log is emitted.',
                'slot_timeout_alert_cooldown_seconds' => 'Cooldown before another Claude CLI slot timeout alert log can be emitted.',
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
            'description' => 'Runtime policy for AIService gateway model discovery, cache behavior, and slot timeout alerting.',
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
            $policy['slot_timeout_counter_ttl_seconds'],
            $policy['slot_timeout_alert_threshold'],
            $policy['slot_timeout_alert_cooldown_seconds']
        );

        if (isset($policy['policy_notes']) && is_array($policy['policy_notes'])) {
            unset(
                $policy['policy_notes']['slot_timeout_counter_ttl_seconds'],
                $policy['policy_notes']['slot_timeout_alert_threshold'],
                $policy['policy_notes']['slot_timeout_alert_cooldown_seconds']
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
