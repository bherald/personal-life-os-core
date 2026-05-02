<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * INF-11a: Pipeline metrics snapshots for velocity tracking.
 * Daily snapshot of completion % per pipeline. Enables delta/day,
 * projected ETA, and regression detection (backlog growing).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE pipeline_metrics_snapshots (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                snapshot_date DATE NOT NULL,
                pipeline VARCHAR(50) NOT NULL,
                pending INT UNSIGNED NOT NULL DEFAULT 0,
                total INT UNSIGNED NOT NULL DEFAULT 0,
                completion_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                delta_from_prev INT DEFAULT NULL COMMENT 'Change in pending from previous snapshot (negative = progress)',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_date_pipeline (snapshot_date, pipeline)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS pipeline_metrics_snapshots");
    }
};
