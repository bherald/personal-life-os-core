<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enhancement tables for:
     * - Agent execution logging
     * - Agent handoff tracking
     * - Workflow node metrics
     * - File quarantine columns
     */
    public function up(): void
    {
        // Agent execution log
        DB::statement("
            CREATE TABLE IF NOT EXISTS agent_execution_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                session_id VARCHAR(50) NOT NULL,
                role VARCHAR(50) NOT NULL,
                input_summary TEXT NULL,
                output_summary TEXT NULL,
                duration_ms DECIMAL(10,2) NULL,
                success TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_agent_session (session_id),
                INDEX idx_agent_role (role),
                INDEX idx_agent_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Agent handoff log
        DB::statement("
            CREATE TABLE IF NOT EXISTS agent_handoff_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                handoff_id VARCHAR(50) NOT NULL,
                from_role VARCHAR(50) NOT NULL,
                to_role VARCHAR(50) NOT NULL,
                data_summary TEXT NULL,
                success TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_handoff_id (handoff_id),
                INDEX idx_handoff_roles (from_role, to_role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Workflow node metrics (if not exists)
        DB::statement("
            CREATE TABLE IF NOT EXISTS workflow_node_metrics (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                workflow_run_id INT UNSIGNED NOT NULL,
                workflow_node_id INT UNSIGNED NULL,
                node_type VARCHAR(100) NULL,
                duration_ms DECIMAL(10,2) NULL,
                memory_bytes BIGINT UNSIGNED NULL,
                success TINYINT(1) NOT NULL DEFAULT 1,
                error TEXT NULL,
                executed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_wnm_run (workflow_run_id),
                INDEX idx_wnm_node (workflow_node_id),
                INDEX idx_wnm_type (node_type),
                INDEX idx_wnm_executed (executed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add workflow run columns if not exist
        try {
            DB::statement("ALTER TABLE workflow_runs ADD COLUMN total_duration_ms DECIMAL(10,2) NULL");
        } catch (\Exception $e) {}
        try {
            DB::statement("ALTER TABLE workflow_runs ADD COLUMN nodes_executed INT UNSIGNED NULL");
        } catch (\Exception $e) {}

        // Add file_registry quarantine columns if not exist
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN quarantine_status VARCHAR(20) NULL");
        } catch (\Exception $e) {}
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN quarantine_reason VARCHAR(50) NULL");
        } catch (\Exception $e) {}
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN quarantine_details TEXT NULL");
        } catch (\Exception $e) {}
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN quarantined_at TIMESTAMP NULL");
        } catch (\Exception $e) {}
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN quarantine_reviewed_at TIMESTAMP NULL");
        } catch (\Exception $e) {}
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN quarantine_review_notes TEXT NULL");
        } catch (\Exception $e) {}
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS agent_handoff_log");
        DB::statement("DROP TABLE IF EXISTS agent_execution_log");
        DB::statement("DROP TABLE IF EXISTS workflow_node_metrics");

        try {
            DB::statement("ALTER TABLE workflow_runs DROP COLUMN total_duration_ms");
        } catch (\Exception $e) {}
        try {
            DB::statement("ALTER TABLE workflow_runs DROP COLUMN nodes_executed");
        } catch (\Exception $e) {}

        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN quarantine_status");
        } catch (\Exception $e) {}
        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN quarantine_reason");
        } catch (\Exception $e) {}
        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN quarantine_details");
        } catch (\Exception $e) {}
        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN quarantined_at");
        } catch (\Exception $e) {}
        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN quarantine_reviewed_at");
        } catch (\Exception $e) {}
        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN quarantine_review_notes");
        } catch (\Exception $e) {}
    }
};
