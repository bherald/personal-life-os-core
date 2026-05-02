<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'face_link_weekly_report'],
            [
                'description' => 'Weekly observe-only face/genealogy bridge telemetry report captured in scheduled job output',
                'job_type' => 'command',
                'command' => 'ops:face-telemetry-report --markdown --hours=168',
                'cron_expression' => '20 5 * * 1',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 5,
                'max_parallel' => 1,
                'category' => 'Maintenance',
                'source_module' => 'Genealogy',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'genealogy',
                'resource_profile' => 'default',
                'stall_policy' => 'strict',
                'backlog_metric' => 'face_links',
                'notification_mode' => 'digest',
                'notes' => 'TODO face/genealogy loop: weekly read-only 168-hour report after Sunday full recluster and Monday schema sync. Output is retained in scheduled_job_runs; no notification or remediation write is performed.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')->where('name', 'face_link_weekly_report')->delete();
    }
};
