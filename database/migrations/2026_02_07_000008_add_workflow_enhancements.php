<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Workflow module enhancements:
     * - Templates for reusable workflow patterns
     * - Approval gates for human-in-the-loop workflows
     * - Execution metrics for performance tracking
     * - Node-level output caching
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS workflow_templates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                description TEXT NULL,
                category VARCHAR(50) NULL,
                template_definition JSON NOT NULL,
                template_nodes JSON NOT NULL,
                default_config JSON NULL,
                usage_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_templates_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS workflow_approval_gates (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                workflow_run_id INT UNSIGNED NOT NULL,
                node_execution_id BIGINT UNSIGNED NULL,
                approval_type ENUM('manual', 'condition') NOT NULL DEFAULT 'manual',
                status ENUM('pending', 'approved', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
                requested_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                responded_at TIMESTAMP NULL,
                responded_by VARCHAR(100) NULL,
                timeout_minutes INT UNSIGNED NOT NULL DEFAULT 1440,
                context JSON NULL,
                response_notes TEXT NULL,
                INDEX idx_gates_run (workflow_run_id),
                INDEX idx_gates_status (status),
                INDEX idx_gates_requested (requested_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS workflow_execution_metrics (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                workflow_run_id INT UNSIGNED NOT NULL,
                node_execution_id BIGINT UNSIGNED NULL,
                node_type VARCHAR(50) NULL,
                metric_name VARCHAR(50) NOT NULL,
                metric_value DECIMAL(12,4) NOT NULL,
                unit ENUM('ms', 'bytes', 'count', 'percent') NOT NULL DEFAULT 'ms',
                recorded_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_metrics_run (workflow_run_id),
                INDEX idx_metrics_node_type (node_type),
                INDEX idx_metrics_name (metric_name),
                INDEX idx_metrics_recorded (recorded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS workflow_node_cache (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                node_type VARCHAR(50) NOT NULL,
                cache_key VARCHAR(128) NOT NULL,
                cached_output LONGTEXT NOT NULL,
                hits INT UNSIGNED NOT NULL DEFAULT 0,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_node_cache (node_type, cache_key),
                INDEX idx_node_cache_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS workflow_node_cache");
        DB::statement("DROP TABLE IF EXISTS workflow_execution_metrics");
        DB::statement("DROP TABLE IF EXISTS workflow_approval_gates");
        DB::statement("DROP TABLE IF EXISTS workflow_templates");
    }
};
