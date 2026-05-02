<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'ops_host_baseline_jobs')
            ->where('enabled', 0)
            ->delete();
    }

    public function down(): void
    {
        DB::statement("
            INSERT INTO scheduled_jobs
                (
                    name,
                    description,
                    job_type,
                    command,
                    cron_expression,
                    enabled,
                    run_in_background,
                    without_overlapping,
                    stall_exempt,
                    timeout_minutes,
                    max_parallel,
                    category,
                    source_module,
                    runtime_mode,
                    workload_family,
                    resource_profile,
                    stall_policy,
                    backlog_metric,
                    notification_mode,
                    last_run_status,
                    last_run_output,
                    next_run_at,
                    fail_count,
                    notes,
                    created_at,
                    updated_at
                )
            VALUES
                (
                    'ops_host_baseline_jobs',
                    'Legacy jobs-window host baseline collector superseded by ops_host_baseline_jobs_heavy_window',
                    'command',
                    'ops:host-baseline jobs --repeat=3 --interval=60',
                    '30 4 * * *',
                    0,
                    1,
                    1,
                    1,
                    10,
                    1,
                    'Maintenance',
                    'OpsCapacity',
                    'observe',
                    'ops',
                    'default',
                    'stall_exempt',
                    'none',
                    'digest',
                    NULL,
                    'Restored by rollback; superseded by ops_host_baseline_jobs_heavy_window.',
                    NULL,
                    0,
                    'Legacy disabled jobs-window host baseline collector. Superseded by ops_host_baseline_jobs_heavy_window.',
                    NOW(),
                    NOW()
                )
            ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                last_run_status = VALUES(last_run_status),
                last_run_output = VALUES(last_run_output),
                next_run_at = VALUES(next_run_at),
                fail_count = VALUES(fail_count),
                notes = VALUES(notes),
                updated_at = NOW()
        ");
    }
};
