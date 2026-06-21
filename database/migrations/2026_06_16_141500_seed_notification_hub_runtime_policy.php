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
            'channel_rate_limits_per_minute' => [
                'pushover' => 7,
                'email' => 5,
                'slack' => 30,
                'discord' => 30,
                'ntfy' => 60,
                'webhook' => 60,
            ],
            'default_rate_limit_per_minute' => 10,
            'rate_limit_ttl_seconds' => 60,
            'pushover_retry_seconds' => 60,
            'pushover_expire_seconds' => 3600,
            'http_connect_timeout_seconds' => 5,
            'http_request_timeout_seconds' => 30,
            'policy_notes' => [
                'channel_rate_limits_per_minute' => 'Fallback per-channel notification rate limits when node config does not provide rate_limit_per_minute.',
                'default_rate_limit_per_minute' => 'Fallback notification rate limit for any channel without a channel-specific entry.',
                'rate_limit_ttl_seconds' => 'Cache TTL for NotificationHub per-channel rate counters.',
                'pushover_retry_seconds' => 'Fallback retry interval for urgent Pushover emergency priority notifications.',
                'pushover_expire_seconds' => 'Fallback expire window for urgent Pushover emergency priority notifications.',
                'http_connect_timeout_seconds' => 'Connect timeout for Slack, Discord, ntfy, and generic webhook delivery calls.',
                'http_request_timeout_seconds' => 'Request timeout for Slack, Discord, ntfy, and generic webhook delivery calls.',
                'ownership' => 'NotificationHub fallback delivery timing and rate-limit defaults are table-owned here; explicit workflow node config and credential rows remain higher-priority inputs.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'notifications')
            ->where('config_key', 'hub_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);

        foreach (['channel_rate_limits_per_minute', 'policy_notes'] as $nestedKey) {
            $existingNested = $existing[$nestedKey] ?? null;
            $policy[$nestedKey] = is_array($existingNested)
                ? array_replace($defaultPolicy[$nestedKey], $existingNested)
                : $defaultPolicy[$nestedKey];
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'notifications', 'config_key' => 'hub_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for NotificationHub delivery timeouts, rate limits, and Pushover emergency defaults.',
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
            ->where('section', 'notifications')
            ->where('config_key', 'hub_runtime_policy')
            ->delete();
    }
};
