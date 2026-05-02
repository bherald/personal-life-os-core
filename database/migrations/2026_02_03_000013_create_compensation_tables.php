<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create compensation/rollback tables for Saga pattern implementation
 *
 * Tables:
 * - compensation_handlers: Registry of node_type -> compensation_handler mappings
 * - compensation_log: Audit log of compensation executions
 * - compensation_log_nodes: Detailed per-node compensation results
 */
return new class extends Migration
{
    public function up(): void
    {
        // Compensation handlers registry
        DB::statement("
            CREATE TABLE compensation_handlers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                node_type VARCHAR(100) NOT NULL COMMENT 'Node type this handler compensates (e.g., EmailNode)',
                handler_class VARCHAR(255) NOT NULL COMMENT 'Fully qualified class name or method name',
                config JSON NULL COMMENT 'Handler-specific configuration',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_node_type_active (node_type, active),
                INDEX idx_active (active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Compensation execution log
        DB::statement("
            CREATE TABLE compensation_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                execution_id CHAR(36) NOT NULL COMMENT 'UUID of the workflow execution being compensated',
                failed_node_id VARCHAR(255) NULL COMMENT 'Node ID that triggered compensation',
                nodes_to_compensate JSON NOT NULL COMMENT 'List of node IDs to compensate',
                compensated_nodes JSON NULL COMMENT 'List of successfully compensated node IDs',
                errors JSON NULL COMMENT 'Errors encountered during compensation',
                status ENUM('in_progress', 'completed', 'partial', 'failed') NOT NULL DEFAULT 'in_progress',
                duration_ms INT UNSIGNED NULL COMMENT 'Total compensation duration in milliseconds',
                started_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_execution_id (execution_id),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Detailed per-node compensation results
        DB::statement("
            CREATE TABLE compensation_log_nodes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                compensation_log_id BIGINT UNSIGNED NOT NULL,
                node_id VARCHAR(255) NOT NULL COMMENT 'Node ID that was compensated',
                node_type VARCHAR(100) NOT NULL COMMENT 'Node type',
                status ENUM('completed', 'skipped', 'failed') NOT NULL,
                result JSON NULL COMMENT 'Compensation result data',
                error TEXT NULL COMMENT 'Error message if failed',
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_compensation_log_id (compensation_log_id),
                INDEX idx_node_id (node_id),
                INDEX idx_status (status),
                CONSTRAINT fk_compensation_log_nodes_log
                    FOREIGN KEY (compensation_log_id)
                    REFERENCES compensation_log(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed default handlers for common node types
        DB::insert("
            INSERT INTO compensation_handlers (node_type, handler_class, config)
            VALUES
                ('EmailNode', 'compensateEmail', NULL),
                ('JoplinWriteNode', 'compensateJoplinWrite', NULL),
                ('RAGIndex', 'compensateRAGIndex', NULL),
                ('PushoverNotify', 'compensatePushover', NULL)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('compensation_log_nodes');
        Schema::dropIfExists('compensation_log');
        Schema::dropIfExists('compensation_handlers');
    }
};
