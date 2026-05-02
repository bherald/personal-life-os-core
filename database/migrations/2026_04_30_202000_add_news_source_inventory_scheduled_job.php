<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'news_source_inventory'],
            [
                'description' => 'Read-only inventory of table-backed news RSS feeds, health, recent article counts, and bias-rating coverage',
                'job_type' => 'command',
                'command' => 'news:source-inventory --workflow=news_brief --days=7 --strict --json',
                'cron_expression' => '40 5 * * 0',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 10,
                'max_parallel' => 1,
                'category' => 'Maintenance',
                'source_module' => 'News',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'news',
                'resource_profile' => 'default',
                'stall_policy' => 'strict',
                'backlog_metric' => 'rss_feeds',
                'notification_mode' => 'digest',
                'notes' => 'Weekly observe-only check after RSS self-heal and before daily report. Verifies configured news_brief RSS feeds resolve through table-backed bias_ratings/bias_rating_aliases and have recent health/article telemetry.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')->where('name', 'news_source_inventory')->delete();
    }
};
