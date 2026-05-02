<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add midday stability digest — same command as morning report
 * but runs at 4 PM with 10-hour lookback. Gives a 10-hour
 * feedback loop for validating deploys without waiting until next morning.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::insert(
            "INSERT INTO scheduled_jobs
                (name, description, job_type, command, cron_expression, enabled, run_in_background,
                 without_overlapping, timeout_minutes, category, source_module, created_at, updated_at)
             VALUES (?, ?, 'command', ?, ?, 1, 1, 1, 30, 'ops', 'infrastructure', NOW(), NOW())",
            [
                'midday_digest',
                'Midday stability check — 10h lookback, same format as morning report. Confirms deploy stability without waiting until next morning.',
                'ops:daily-report --hours=10',
                '0 16 * * *', // 4:00 PM daily
            ]
        );
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'midday_digest'");
    }
};
