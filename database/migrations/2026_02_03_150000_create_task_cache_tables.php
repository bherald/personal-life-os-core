<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Enhancement #18: Task Caching for workflow nodes
     */
    public function up(): void
    {
        // Main cache table
        DB::statement("
            CREATE TABLE IF NOT EXISTS task_cache (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cache_key VARCHAR(255) NOT NULL,
                node_type VARCHAR(100) NOT NULL,
                input_hash VARCHAR(64) NOT NULL,
                output LONGTEXT NOT NULL,
                ttl_seconds INT UNSIGNED NOT NULL DEFAULT 3600,
                expires_at TIMESTAMP NULL,
                workflow_id INT UNSIGNED NULL,
                hit_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_hit_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_cache_key (cache_key),
                INDEX idx_node_type (node_type),
                INDEX idx_expires_at (expires_at),
                INDEX idx_workflow_id (workflow_id),
                INDEX idx_input_hash (input_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Stats table for hit/miss tracking per day
        DB::statement("
            CREATE TABLE IF NOT EXISTS task_cache_stats (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                node_type VARCHAR(100) NOT NULL,
                stat_type ENUM('hit', 'miss') NOT NULL,
                count INT UNSIGNED NOT NULL DEFAULT 0,
                recorded_date DATE NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_node_stat_date (node_type, stat_type, recorded_date),
                INDEX idx_recorded_date (recorded_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_cache_stats');
        Schema::dropIfExists('task_cache');
    }
};
