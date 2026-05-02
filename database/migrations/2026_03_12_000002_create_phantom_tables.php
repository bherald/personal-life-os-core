<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N123: Create tables previously referenced in code but never provisioned.
 * Enables: email analytics, file semantic search, tag management,
 * fact-check pipeline tracking, workflow dry-run connections.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. email_queue — Email analytics pipeline (MySQL)
        // Used by EmailAnalyticsService for send tracking, delivery metrics, hourly patterns
        DB::statement("
            CREATE TABLE IF NOT EXISTS email_queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                to_address VARCHAR(255) NOT NULL,
                cc VARCHAR(500) NULL,
                bcc VARCHAR(500) NULL,
                subject VARCHAR(500) NOT NULL,
                body MEDIUMTEXT NULL,
                source VARCHAR(100) NULL DEFAULT 'system',
                status ENUM('pending', 'queued', 'sending', 'sent', 'failed', 'rejected', 'bounced') NOT NULL DEFAULT 'pending',
                priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
                error_message TEXT NULL,
                metadata JSON NULL,
                scheduled_for TIMESTAMP NULL,
                sent_at TIMESTAMP NULL,
                failed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_eq_status (status),
                INDEX idx_eq_created (created_at),
                INDEX idx_eq_sent (sent_at),
                INDEX idx_eq_to (to_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2. file_registry_tags — AI/manual tag management (MySQL)
        // Used by MediaBrowserController and ExifWritebackService for tag CRUD and EXIF sync
        DB::statement("
            CREATE TABLE IF NOT EXISTS file_registry_tags (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                file_registry_id BIGINT UNSIGNED NOT NULL,
                tag VARCHAR(255) NOT NULL,
                source ENUM('ai', 'manual', 'exif', 'import') NOT NULL DEFAULT 'ai',
                confidence DECIMAL(3,2) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_frt_file_tag_source (file_registry_id, tag, source),
                INDEX idx_frt_source (source),
                INDEX idx_frt_tag (tag)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 3. workflow_connections — Node connection graph for dry-run (MySQL)
        // Used by WorkflowDryRunService to trace execution paths
        DB::statement("
            CREATE TABLE IF NOT EXISTS workflow_connections (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                workflow_id BIGINT UNSIGNED NOT NULL,
                source_node_id BIGINT UNSIGNED NOT NULL,
                target_node_id BIGINT UNSIGNED NOT NULL,
                source_port VARCHAR(50) NULL DEFAULT 'output',
                target_port VARCHAR(50) NULL DEFAULT 'input',
                condition_expression TEXT NULL,
                sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_wc_workflow (workflow_id),
                INDEX idx_wc_source (source_node_id),
                INDEX idx_wc_target (target_node_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 4. fact_check_pipeline_runs — Pipeline run tracking (PostgreSQL)
        // Used by FactCheckPipelineService for history, stats, run details
        DB::connection('pgsql_rag')->statement("
            CREATE TABLE IF NOT EXISTS fact_check_pipeline_runs (
                id SERIAL PRIMARY KEY,
                pipeline_id VARCHAR(64) NOT NULL,
                source_url TEXT NULL,
                source_title VARCHAR(500) NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'running',
                claim_count INT DEFAULT 0,
                supported_count INT DEFAULT 0,
                refuted_count INT DEFAULT 0,
                inconclusive_count INT DEFAULT 0,
                overall_factuality_score DECIMAL(5,4) NULL,
                duration_ms INT NULL,
                error_message TEXT NULL,
                metadata JSONB NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL
            )
        ");
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_fcpr_pipeline ON fact_check_pipeline_runs (pipeline_id)
        ");
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_fcpr_status ON fact_check_pipeline_runs (status)
        ");
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_fcpr_created ON fact_check_pipeline_runs (created_at)
        ");

        // 5. file_semantic_embeddings — File-level semantic search (PostgreSQL)
        // Used by SemanticSearchService for vector similarity search on file chunks
        DB::connection('pgsql_rag')->statement("
            CREATE TABLE IF NOT EXISTS file_semantic_embeddings (
                id SERIAL PRIMARY KEY,
                file_id BIGINT NOT NULL,
                chunk_index INT NOT NULL DEFAULT 0,
                chunk_text TEXT NOT NULL,
                embedding vector(768),
                metadata JSONB NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (file_id, chunk_index)
            )
        ");
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_fse_file ON file_semantic_embeddings (file_id)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS email_queue");
        DB::statement("DROP TABLE IF EXISTS file_registry_tags");
        DB::statement("DROP TABLE IF EXISTS workflow_connections");
        DB::connection('pgsql_rag')->statement("DROP TABLE IF EXISTS fact_check_pipeline_runs");
        DB::connection('pgsql_rag')->statement("DROP TABLE IF EXISTS file_semantic_embeddings");
    }
};
