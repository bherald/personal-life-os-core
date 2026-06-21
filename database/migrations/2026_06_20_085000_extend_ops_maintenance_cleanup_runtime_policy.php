<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policyPatch(): array
    {
        return [
            'dump_process_timeout_seconds' => 300,
            'backup_keep_count' => 5,
            'failed_scheduled_job_scan_hours' => 24,
            'default_scheduled_job_timeout_minutes' => 60,
            'stuck_job_timeout_buffer_minutes' => 10,
            'old_failed_jobs_retention_days' => 30,
            'old_failed_jobs_delete_limit' => 10000,
            'stale_agent_session_hours' => 2,
            'old_workflow_runs_retention_days' => 90,
            'workflow_runs_delete_limit' => 5000,
            'log_rotation_max_bytes' => 10485760,
            'policy_notes' => [
                'dump_process_timeout_seconds' => 'Timeout for database dump subprocesses used by OpsMaintenanceJob backups.',
                'backup_keep_count' => 'Number of backup files retained per database backup pattern.',
                'failed_scheduled_job_scan_hours' => 'Lookback window for failed/running scheduled job checks.',
                'default_scheduled_job_timeout_minutes' => 'Fallback timeout when a scheduled job row has no timeout_minutes.',
                'stuck_job_timeout_buffer_minutes' => 'Buffer added to scheduled_jobs.timeout_minutes before a running job is treated as stuck.',
                'old_failed_jobs_retention_days' => 'Retention window for failed_jobs cleanup.',
                'old_failed_jobs_delete_limit' => 'Maximum failed_jobs rows removed in one maintenance pass.',
                'stale_agent_session_hours' => 'Inactive active-agent session age before expiry.',
                'old_workflow_runs_retention_days' => 'Retention window for workflow_runs cleanup.',
                'workflow_runs_delete_limit' => 'Maximum workflow_runs rows removed in one maintenance pass.',
                'log_rotation_max_bytes' => 'Maximum log file size before OpsMaintenanceJob rotates a log.',
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

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            $policy = [];
        }

        $patch = $this->policyPatch();
        $notes = array_replace(
            is_array($patch['policy_notes'] ?? null) ? $patch['policy_notes'] : [],
            is_array($policy['policy_notes'] ?? null) ? $policy['policy_notes'] : []
        );
        unset($patch['policy_notes']);

        $policy = array_replace($patch, $policy);
        $policy['policy_notes'] = $notes;

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'ops', 'config_key' => 'ops_maintenance_job_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for OpsMaintenanceJob queue envelope, continuation checkpointing, and bounded cleanup windows.',
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

        $remove = array_keys(array_filter(
            $this->policyPatch(),
            static fn (mixed $value, string $key): bool => $key !== 'policy_notes',
            ARRAY_FILTER_USE_BOTH
        ));
        $noteKeys = array_keys($this->policyPatch()['policy_notes']);

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'ops_maintenance_job_runtime_policy')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        foreach ($remove as $key) {
            unset($policy[$key]);
        }
        if (is_array($policy['policy_notes'] ?? null)) {
            foreach ($noteKeys as $key) {
                unset($policy['policy_notes'][$key]);
            }
        }

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'ops_maintenance_job_runtime_policy')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
