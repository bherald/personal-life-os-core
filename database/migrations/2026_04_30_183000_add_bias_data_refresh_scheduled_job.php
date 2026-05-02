<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'bias_data_refresh'],
            [
                'description' => 'Refresh free news-bias ratings and supporting bias data for news workflows',
                'job_type' => 'command',
                'command' => 'bias:maintenance --all --source=free',
                'cron_expression' => '20 3 1 * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 1,
                'timeout_minutes' => 60,
                'max_parallel' => 1,
                'category' => 'Maintenance',
                'source_module' => 'Maintenance',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'news',
                'resource_profile' => 'default',
                'stall_policy' => 'stall_exempt',
                'backlog_metric' => 'bias_ratings',
                'notification_mode' => 'digest',
                'notes' => 'NewsBias monthly free/default refresh uses the Idiap MBFC-derived GitHub dataset. AllSides is excluded from scheduled refresh and remains an explicit operator-selected enrichment source via --source=allsides or --source=both.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')->where('name', 'bias_data_refresh')->delete();
    }
};
