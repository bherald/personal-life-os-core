<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4E: Update face_recluster to include --optimize,
 * and add weekly face_recluster_full job for singleton re-clustering.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Update existing face_recluster job to include --optimize
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'faces:cluster --backfill --optimize',
                description = 'Assign unclustered faces to clusters, then optimize (merge similar, anchor match, cleanup)',
                updated_at = NOW()
            WHERE name = 'face_recluster'
        ");

        // Add weekly re-clustering job for singletons (Sundays at 3 AM)
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'face_recluster_full'");
        if (!$exists) {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, command, cron_expression, category, enabled,
                 timeout_minutes, run_in_background, without_overlapping, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'face_recluster_full',
                'Re-cluster singletons and small clusters with confirmed anchors via HDBSCAN',
                'faces:cluster --recluster-singletons',
                '0 3 * * 0',
                'E13-FileRegistry',
                0, // Disabled by default — enable after verifying optimize works
                30,
                1,
                1,
                'Weekly HDBSCAN re-clustering with semi-supervised anchors. More expensive than --optimize. Enable after Phase 4E verification.',
            ]);
        }
    }

    public function down(): void
    {
        // Revert face_recluster to original command
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'faces:cluster --backfill',
                description = 'Assign unclustered faces to clusters and merge close singletons',
                updated_at = NOW()
            WHERE name = 'face_recluster'
        ");

        // Remove weekly job
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'face_recluster_full'");
    }
};
