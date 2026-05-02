<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'scheduler_optimize_weekly_report'],
            [
                'description' => 'Capture weekly observe-only scheduler optimization recommendations',
                'job_type' => 'command',
                'command' => 'scheduler:optimize-report --window=7d --json',
                'cron_expression' => '45 5 * * 2',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 10,
                'max_parallel' => 1,
                'category' => 'Maintenance',
                'source_module' => 'Ops',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'ops',
                'resource_profile' => 'default',
                'stall_policy' => 'strict',
                'backlog_metric' => 'scheduled_jobs',
                'notification_mode' => 'digest',
                'notes' => 'Weekly observe-only TODO-012 evidence. Stores scheduler optimization recommendations in scheduled job history without changing cron expressions, timeouts, queues, or job limits.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')->where('name', 'scheduler_optimize_weekly_report')->delete();
    }
};
