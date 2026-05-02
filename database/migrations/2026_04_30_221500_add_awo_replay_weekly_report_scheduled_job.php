<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'awo_replay_weekly_report'],
            [
                'description' => 'Weekly observe-only approval-worthy-output replay report captured in scheduled job output',
                'job_type' => 'command',
                'command' => 'awo:replay --window=7d --limit=500 --markdown',
                'cron_expression' => '30 5 * * 1',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 5,
                'max_parallel' => 1,
                'category' => 'Maintenance',
                'source_module' => 'Agents',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'agents',
                'resource_profile' => 'default',
                'stall_policy' => 'strict',
                'backlog_metric' => 'agent_review_queue',
                'notification_mode' => 'digest',
                'notes' => 'Agent output quality: weekly read-only AWO replay report retained in scheduled_job_runs. It does not enable awo.recording_enabled, promote agents, or mutate review state.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')->where('name', 'awo_replay_weekly_report')->delete();
    }
};
