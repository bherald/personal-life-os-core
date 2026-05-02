<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ========================================
// CENTRALIZED JOB SCHEDULER
// ========================================
// All scheduled jobs are now managed in the database via the scheduled_jobs table.
// System cron should invoke `php artisan scheduler:run` directly every minute.
// This command checks the database and runs any jobs that are due.
//
// Jobs can be viewed and managed via:
// - UI: /scheduled-jobs
// - CLI: php artisan scheduler:list
// - API: /api/scheduled-jobs
//
// To add a new job:
// 1. Go to /scheduled-jobs in the UI and click "New Job"
// 2. Or use the API: POST /api/scheduled-jobs
// 3. Or insert directly into the scheduled_jobs table
//
// Cron expressions use standard 5-field format:
//   minute hour day month weekday
//   Examples:
//     0 4 * * *     = 4:00 AM daily
//     */5 * * * *   = Every 5 minutes
//     0 */3 * * *   = Every 3 hours
//     0 8 * * 0     = Sundays at 8 AM
//     0 2 1 * *     = 1st of month at 2 AM
//
// This replaces:
// - All Laravel Schedule::command() calls
// - All Laravel `php artisan schedule:run` usage for runtime scheduling
// - Dynamic workflow scheduling
// - Commented-out scheduled tasks (now disabled in DB)

// ========================================
// LEGACY REFERENCE (REMOVED)
// ========================================
// The following jobs have been migrated to the scheduled_jobs table:
//
// CORE:
// - ops_maintenance (4:00 AM daily) - OpsMaintenanceJob
// - devops_ai_maintenance (4:30 AM daily)
//
// JOPLIN/NEXTCLOUD:
// - joplin_queue_worker (every 4 hours)
// - nextcloud_cache_refresh (every 3 hours)
//
// RESEARCH:
// - research_run (9 PM daily)
//
// E06 DATA REMOVAL:
// - data_removal_scan (Sunday 3 AM)
// - data_removal_digest (8 AM daily)
// - data_removal_discover (1st of month 2 AM)
//
// RSS:
// - rss_self_heal (4:45 AM daily)
//
// E13 FILE REGISTRY:
// - file_registry_scan (Sun/Mon/Wed/Fri 2 AM)
// - file_registry_bundles (Sun/Mon/Wed/Fri 3 AM)
// - file_registry_verify (5 AM daily)
// - file_execute_actions (5:30 AM daily)
//
// GENEALOGY:
// - genealogy_sync_contacts (Sunday 6 AM)
// - genealogy_auto_research (Sunday 7 AM)
// - genealogy_face_scan (Sunday 8 AM)
//
// EA2 EMAIL:
// - email_suggestions_cleanup (Sunday 3 AM)
// - shipments_scan_hourly (DISABLED)
// - shipments_cleanup (DISABLED)
// - email_suggestions_scan (DISABLED)
// - email_bill_digest (DISABLED)
//
// WORKFLOWS:
// - All workflow schedules from workflows table
//
// View/manage all at: /scheduled-jobs
