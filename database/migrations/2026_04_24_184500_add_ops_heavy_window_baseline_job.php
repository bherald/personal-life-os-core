<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
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
                    notes,
                    created_at,
                    updated_at
                )
            VALUES
                (
                    'ops_host_baseline_jobs_heavy_window',
                    'Capture three host baseline samples during the 4 AM heavy scheduled-job window for TODO-011 capacity evidence',
                    'command',
                    'ops:host-baseline jobs --repeat=3 --interval=900',
                    '5 4 * * *',
                    1,
                    1,
                    1,
                    1,
                    45,
                    1,
                    'Maintenance',
                    'OpsCapacity',
                    'observe',
                    'ops',
                    'default',
                    'stall_exempt',
                    'none',
                    'digest',
                    'TODO-011 evidence collector: captures jobs baselines at about 04:05, 04:20, and 04:35 America/New_York. Non-mutating telemetry only; ops:capacity-report remains observe-only until enough heavy-window and deploy samples exist.',
                    NOW(),
                    NOW()
                )
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                command = VALUES(command),
                cron_expression = VALUES(cron_expression),
                enabled = VALUES(enabled),
                run_in_background = VALUES(run_in_background),
                without_overlapping = VALUES(without_overlapping),
                stall_exempt = VALUES(stall_exempt),
                timeout_minutes = VALUES(timeout_minutes),
                max_parallel = VALUES(max_parallel),
                category = VALUES(category),
                source_module = VALUES(source_module),
                runtime_mode = VALUES(runtime_mode),
                workload_family = VALUES(workload_family),
                resource_profile = VALUES(resource_profile),
                stall_policy = VALUES(stall_policy),
                backlog_metric = VALUES(backlog_metric),
                notification_mode = VALUES(notification_mode),
                notes = VALUES(notes),
                updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')->where('name', 'ops_host_baseline_jobs_heavy_window')->delete();
    }
};
