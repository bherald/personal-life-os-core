<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix unsafe flag combinations from audit migration:
 *
 * 1. faces:cluster --backfill --optimize --dedup: --dedup is an early-return
 *    branch that skips backfill+optimize entirely. Must be a separate job.
 * 2. faces:cluster --recluster-singletons --optimize --purge-bloat: same issue,
 *    --purge-bloat early-returns before recluster+optimize.
 * 3. genealogy:media-validate --purge: deletes orphaned rows without --dry-run.
 *    Too aggressive for automated weekly run. Remove flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Revert face_recluster to safe command (remove --dedup)
        DB::update(
            "UPDATE scheduled_jobs SET command = ? WHERE name = ?",
            ['faces:cluster --backfill --optimize', 'face_recluster']
        );

        // Revert face_recluster_full to safe command (remove --purge-bloat)
        DB::update(
            "UPDATE scheduled_jobs SET command = ? WHERE name = ?",
            ['faces:cluster --recluster-singletons --optimize', 'face_recluster_full']
        );

        // Remove --purge from media-validate (destructive without oversight)
        DB::update(
            "UPDATE scheduled_jobs SET command = ? WHERE name = ?",
            ['genealogy:media-validate --tree-id=4 --batch=500', 'genealogy_media_validate']
        );

        // Add --dedup as its own monthly maintenance job (safe standalone)
        DB::insert(
            "INSERT IGNORE INTO scheduled_jobs
                (name, description, job_type, command, cron_expression, enabled, timeout_minutes, category, created_at, updated_at)
             VALUES (?, ?, 'command', ?, ?, 1, ?, ?, NOW(), NOW())",
            [
                'face_dedup_embeddings',
                'Monthly dedup of identical face embeddings across different files',
                'faces:cluster --dedup',
                '0 2 1 * *',
                30,
                'Files',
            ]
        );

        // Add --purge-bloat as its own monthly maintenance job (safe standalone)
        DB::insert(
            "INSERT IGNORE INTO scheduled_jobs
                (name, description, job_type, command, cron_expression, enabled, timeout_minutes, category, created_at, updated_at)
             VALUES (?, ?, 'command', ?, ?, 1, ?, ?, NOW(), NOW())",
            [
                'face_purge_bloat',
                'Monthly purge of bloated face clusters (evict low-similarity faces)',
                'faces:cluster --purge-bloat',
                '0 2 8 * *',
                30,
                'Files',
            ]
        );
    }

    public function down(): void
    {
        // Restore combined flags (unsafe but original from prior migration)
        DB::update(
            "UPDATE scheduled_jobs SET command = ? WHERE name = ?",
            ['faces:cluster --backfill --optimize --dedup', 'face_recluster']
        );
        DB::update(
            "UPDATE scheduled_jobs SET command = ? WHERE name = ?",
            ['faces:cluster --recluster-singletons --optimize --purge-bloat', 'face_recluster_full']
        );
        DB::update(
            "UPDATE scheduled_jobs SET command = ? WHERE name = ?",
            ['genealogy:media-validate --tree-id=4 --batch=500 --purge', 'genealogy_media_validate']
        );

        DB::delete("DELETE FROM scheduled_jobs WHERE name IN ('face_dedup_embeddings', 'face_purge_bloat')");
    }
};
