<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4E: Add centroid tracking and retry limits to person_clusters.
 *
 * Enables centroid-first matching (98% fewer distance evaluations),
 * cluster optimization passes, and retry limits for stubborn clusters.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // Centroid embedding — mean of all face embeddings in cluster
        DB::connection($this->connection)->statement("
            ALTER TABLE person_clusters
            ADD COLUMN IF NOT EXISTS centroid vector(128)
        ");

        // Max cosine distance from centroid to any member (cluster tightness)
        DB::connection($this->connection)->statement("
            ALTER TABLE person_clusters
            ADD COLUMN IF NOT EXISTS centroid_radius REAL
        ");

        // When centroid was last recalculated
        DB::connection($this->connection)->statement("
            ALTER TABLE person_clusters
            ADD COLUMN IF NOT EXISTS last_optimized_at TIMESTAMP
        ");

        // Retry limits — how many times optimize tried to merge this cluster
        DB::connection($this->connection)->statement("
            ALTER TABLE person_clusters
            ADD COLUMN IF NOT EXISTS merge_retry INTEGER DEFAULT 0
        ");

        // Reason for last skip during optimization
        DB::connection($this->connection)->statement("
            ALTER TABLE person_clusters
            ADD COLUMN IF NOT EXISTS merge_notes TEXT
        ");

        // HNSW index on centroids for fast cluster-vs-cluster matching
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_person_clusters_centroid
            ON person_clusters
            USING hnsw (centroid vector_cosine_ops)
            WITH (m = 16, ef_construction = 64)
        ");

        // Index for finding stale centroids
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_person_clusters_last_optimized
            ON person_clusters(last_optimized_at)
            WHERE last_optimized_at IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP INDEX IF EXISTS idx_person_clusters_last_optimized");
        DB::connection($this->connection)->statement("DROP INDEX IF EXISTS idx_person_clusters_centroid");
        DB::connection($this->connection)->statement("ALTER TABLE person_clusters DROP COLUMN IF EXISTS merge_notes");
        DB::connection($this->connection)->statement("ALTER TABLE person_clusters DROP COLUMN IF EXISTS merge_retry");
        DB::connection($this->connection)->statement("ALTER TABLE person_clusters DROP COLUMN IF EXISTS last_optimized_at");
        DB::connection($this->connection)->statement("ALTER TABLE person_clusters DROP COLUMN IF EXISTS centroid_radius");
        DB::connection($this->connection)->statement("ALTER TABLE person_clusters DROP COLUMN IF EXISTS centroid");
    }
};
