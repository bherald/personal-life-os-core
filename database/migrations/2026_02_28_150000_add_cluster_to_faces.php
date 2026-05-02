<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add cluster_id to file_registry_faces (MySQL).
 * Cluster assignment lives in MySQL to avoid cross-DB joins.
 * References person_clusters.id in pgvector but no FK constraint (cross-DB).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add cluster_id column
        try {
            DB::statement("ALTER TABLE file_registry_faces ADD COLUMN cluster_id INT UNSIGNED NULL");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        // Index for cluster lookups
        try {
            DB::statement("CREATE INDEX idx_frf_cluster_id ON file_registry_faces (cluster_id)");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate key name')) {
                throw $e;
            }
        }

        // Composite index for cluster + hidden (used by cluster tab filters)
        try {
            DB::statement("CREATE INDEX idx_frf_cluster_hidden ON file_registry_faces (cluster_id, hidden)");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate key name')) {
                throw $e;
            }
        }

        // Scheduled re-clustering job (every 6 hours)
        try {
            DB::insert("
                INSERT INTO scheduled_jobs
                (name, description, command, cron_expression, job_type, enabled, category,
                 timeout_minutes, run_in_background, without_overlapping, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                'face_recluster',
                'Assign unclustered faces to clusters and merge close singletons',
                'faces:cluster --backfill',
                '0 */6 * * *',
                'command',
                0, // disabled by default — enable after initial clustering
                'E13-FileRegistry',
                30,
                1,
                1,
                'Phase 4D: Runs backfill to catch faces missed by inline clustering',
            ]);
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        try {
            DB::statement("DROP INDEX idx_frf_cluster_hidden ON file_registry_faces");
        } catch (\Exception $e) {}

        try {
            DB::statement("DROP INDEX idx_frf_cluster_id ON file_registry_faces");
        } catch (\Exception $e) {}

        try {
            DB::statement("ALTER TABLE file_registry_faces DROP COLUMN cluster_id");
        } catch (\Exception $e) {}
    }
};
