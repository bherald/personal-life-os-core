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
            'timeout_seconds' => 1800,
            'tries' => 3,
            'backoff_seconds' => [60, 120, 300],
            'overlap_release_after_seconds' => 300,
            'overlap_expire_after_seconds' => 1800,
            'continuation_threshold_seconds' => 300,
            'checkpoint_ttl_seconds' => 3600,
            'continuation_delay_seconds' => 30,
            'report_cache_ttl_hours' => 6,
            'policy_notes' => [
                'timeout_seconds' => 'Horizon timeout for one OpsMaintenanceJob dispatch.',
                'tries' => 'Retry attempts for one OpsMaintenanceJob dispatch.',
                'backoff_seconds' => 'Retry backoff sequence for transient queue failures.',
                'overlap' => 'WithoutOverlapping release and expiry values for the nightly ops maintenance lock.',
                'continuation_threshold_seconds' => 'Remaining-runtime threshold that triggers checkpoint and continuation.',
                'checkpoint_ttl_seconds' => 'Cache TTL for continuation checkpoint state.',
                'continuation_delay_seconds' => 'Delay before dispatching the continuation job for the same session.',
                'report_cache_ttl_hours' => 'Daily digest cache TTL for the consolidated ops maintenance report.',
                'schedule_owner' => 'scheduled_jobs.ops_maintenance remains authoritative for cadence, enabled state, max_parallel, stall policy, and scheduler timeout_minutes.',
                'queue_owner' => 'Queue placement remains explicit in the job constructor as the documented high-priority exception.',
                'domain_policy_follow_up' => 'Phase-specific cleanup windows, batch sizes, and health thresholds remain for later table-driven slices.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'ops_maintenance_job_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);
        if (isset($existing['policy_notes']) && is_array($existing['policy_notes'])) {
            $policy['policy_notes'] = array_replace(
                $defaultPolicy['policy_notes'],
                $existing['policy_notes']
            );
        } else {
            $policy['policy_notes'] = $defaultPolicy['policy_notes'];
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'ops', 'config_key' => 'ops_maintenance_job_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for OpsMaintenanceJob queue envelope and continuation checkpointing.',
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
            ->where('section', 'ops')
            ->where('config_key', 'ops_maintenance_job_runtime_policy')
            ->delete();
    }
};
