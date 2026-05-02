<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix face_recluster_full: add --optimize after --recluster-singletons so that
 * empty old clusters are cleaned up and new cluster centroids are computed in
 * the same run instead of waiting for the next face_recluster cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'faces:cluster --recluster-singletons --optimize',
                description = 'Re-cluster singletons and small clusters with confirmed anchors, then optimize centroids and cleanup',
                updated_at = NOW()
            WHERE name = 'face_recluster_full'
        ");
    }

    public function down(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'faces:cluster --recluster-singletons',
                description = 'Re-cluster singletons and small clusters with confirmed anchors via HDBSCAN',
                updated_at = NOW()
            WHERE name = 'face_recluster_full'
        ");
    }
};
