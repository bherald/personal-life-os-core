<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Consolidate 5 Pushover notifications into single ops:daily-report at 5:50 AM.
 *
 * Replaces: morning_digest (5:15), nightly_ops (10PM), rss_self_heal --notify (4:45),
 * devops_ai_maintenance (4:30).
 *
 * rss:self-heal and devops:ai-maintenance still RUN (maintenance work is valuable),
 * but their Pushover notifications are no longer needed — ops:daily-report reads their results.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Rename morning_digest → daily_report, update command + cron to 5:50
        DB::statement("
            UPDATE scheduled_jobs
            SET name = 'daily_report',
                command = 'ops:daily-report',
                cron_expression = '50 5 * * *',
                description = 'Consolidated daily ops report — system, pipelines, agents, RSS, DevOps',
                updated_at = NOW()
            WHERE name = 'morning_digest'
        ");

        // 2. Disable nightly_ops (absorbed into daily report)
        DB::statement("
            UPDATE scheduled_jobs
            SET enabled = 0,
                description = CONCAT('[RETIRED: absorbed into daily_report] ', COALESCE(description, '')),
                updated_at = NOW()
            WHERE name = 'nightly_ops'
        ");

        // 3. Remove --notify from rss_self_heal (still runs healing, just no separate Pushover)
        DB::statement("
            UPDATE scheduled_jobs
            SET command = 'rss:self-heal --check-all',
                description = 'RSS feed self-healing (notification via daily_report)',
                updated_at = NOW()
            WHERE name = 'rss_self_heal'
        ");

        // 4. devops_ai_maintenance keeps running but suppress its Pushover
        //    (it only sends notification from sendNotification() method which we'll gate)
        //    For now, just update description to document the change
        DB::statement("
            UPDATE scheduled_jobs
            SET description = 'AI DevOps maintenance (notification via daily_report)',
                updated_at = NOW()
            WHERE name = 'devops_ai_maintenance'
        ");
    }

    public function down(): void
    {
        // Restore morning_digest
        DB::statement("
            UPDATE scheduled_jobs
            SET name = 'morning_digest',
                command = 'ops:morning-digest',
                cron_expression = '15 5 * * *',
                description = 'Overnight analysis, auto-healing, and Pushover report',
                updated_at = NOW()
            WHERE name = 'daily_report'
        ");

        // Re-enable nightly_ops
        DB::statement("
            UPDATE scheduled_jobs
            SET enabled = 1,
                description = 'Nightly ops health summary via Pushover',
                updated_at = NOW()
            WHERE name = 'nightly_ops'
        ");

        // Restore --notify on rss_self_heal
        DB::statement("
            UPDATE scheduled_jobs
            SET command = 'rss:self-heal --check-all --notify',
                updated_at = NOW()
            WHERE name = 'rss_self_heal'
        ");
    }
};
