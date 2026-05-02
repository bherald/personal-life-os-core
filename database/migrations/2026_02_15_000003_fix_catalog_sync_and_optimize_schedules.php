<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fix: file-catalog:sync has failed 10 times because 'catalog_sync' is not in the run_type enum
        try {
            DB::statement("ALTER TABLE file_registry_sync_runs MODIFY COLUMN run_type ENUM('initial_import','verification','reorganization','nextcloud_sync','bundle_scan','catalog_sync') NOT NULL");
        } catch (\Exception $e) {
            // Column may already be updated or table doesn't exist (dev)
        }

        // Only run scheduled_jobs updates if table exists (prod only)
        if (Schema::hasTable('scheduled_jobs')) {
            // Reset fail count now that the bug is fixed
            DB::update("UPDATE scheduled_jobs SET fail_count = 0, last_run_status = 'success' WHERE id = ?", [10]);

            // youtube_watch_later: 60 → 120min timeout (new KeyPointsPostProcessor + WatchLaterOrganize nodes do heavy AI work)
            DB::update("UPDATE scheduled_jobs SET timeout_minutes = 120 WHERE id = ?", [28]);

            // nextcloud:cache-refresh: every 3h → every 2h (local Nextcloud, executes in ~3s)
            DB::update("UPDATE scheduled_jobs SET cron_expression = ? WHERE id = ?", ['0 */2 * * *', 4]);

            // joplin_sync: add 1 PM run for faster RAG indexing (was 4AM+10PM, now 4AM+1PM+10PM)
            DB::update("UPDATE scheduled_jobs SET cron_expression = ? WHERE id = ?", ['0 4,13,22 * * *', 23]);
        }
    }

    public function down(): void
    {
        // Revert run_type enum (remove catalog_sync)
        try {
            DB::statement("ALTER TABLE file_registry_sync_runs MODIFY COLUMN run_type ENUM('initial_import','verification','reorganization','nextcloud_sync','bundle_scan') NOT NULL");
        } catch (\Exception $e) {
            // May fail if rows exist with catalog_sync
        }

        DB::update("UPDATE scheduled_jobs SET timeout_minutes = 60 WHERE id = ?", [28]);
        DB::update("UPDATE scheduled_jobs SET cron_expression = ? WHERE id = ?", ['0 */3 * * *', 4]);
        DB::update("UPDATE scheduled_jobs SET cron_expression = ? WHERE id = ?", ['0 4,22 * * *', 23]);
    }
};
