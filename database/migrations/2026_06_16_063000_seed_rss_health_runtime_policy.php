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
            'default_timeout_seconds' => 15,
            'connect_timeout_cap_seconds' => 5,
            'redirect_probe_timeout_cap_seconds' => 10,
            'healthy_failure_threshold' => 0,
            'degraded_failure_threshold' => 2,
            'failed_failure_threshold' => 3,
            'alert_failure_threshold' => 3,
            'high_failure_dead_threshold' => 30,
            'policy_notes' => [
                'default_timeout_seconds' => 'Default RSS fetch timeout when a workflow or CLI call does not provide one.',
                'connect_timeout_cap_seconds' => 'Upper bound for RSS redirect-probe connect timeout.',
                'redirect_probe_timeout_cap_seconds' => 'Upper bound for RSS redirect-probe total timeout.',
                'failure_thresholds' => 'Consecutive-failure thresholds for healthy/degraded/failed status and alerting.',
                'high_failure_dead_threshold' => 'Consecutive failures before a feed with no redirect is marked permanently dead during self-healing.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'rss')
            ->where('config_key', 'health_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'rss', 'config_key' => 'health_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for RSS health monitor timeouts, failure thresholds, and self-healing dead-feed threshold.',
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
            ->where('section', 'rss')
            ->where('config_key', 'health_runtime_policy')
            ->delete();
    }
};
