<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'daily_report')
            ->update([
                'description' => 'Action-first 24-hour PLOS production, AI usage, backup, operator-attention, and canonical workboard report',
                'command' => 'ops:daily-report --hours=24',
                'cron_expression' => '0 5 * * *',
                'enabled' => 1,
                'next_run_at' => null,
                'updated_at' => now(),
            ]);

        DB::table('scheduled_jobs')
            ->where('name', 'midday_digest')
            ->update([
                'description' => '[RETIRED: consolidated into 5:00 AM daily_report] Midday PLOS digest',
                'enabled' => 0,
                'next_run_at' => null,
                'updated_at' => now(),
            ]);

        DB::table('scheduled_jobs')
            ->where('name', 'ollama_drift_check')
            ->where('cron_expression', '30 5 * * *')
            ->update([
                'cron_expression' => '30 4 * * *',
                'next_run_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'daily_report')
            ->update([
                'description' => 'Consolidated daily ops report - system, pipelines, agents, RSS, DevOps',
                'command' => 'ops:daily-report',
                'cron_expression' => '50 5 * * *',
                'enabled' => 1,
                'next_run_at' => null,
                'updated_at' => now(),
            ]);

        DB::table('scheduled_jobs')
            ->where('name', 'midday_digest')
            ->update([
                'description' => 'Midday stability check - 10h lookback, same format as morning report',
                'command' => 'ops:daily-report --hours=10',
                'cron_expression' => '29 16 * * *',
                'enabled' => 1,
                'next_run_at' => null,
                'updated_at' => now(),
            ]);

        DB::table('scheduled_jobs')
            ->where('name', 'ollama_drift_check')
            ->where('cron_expression', '30 4 * * *')
            ->update([
                'cron_expression' => '30 5 * * *',
                'next_run_at' => null,
                'updated_at' => now(),
            ]);
    }
};
