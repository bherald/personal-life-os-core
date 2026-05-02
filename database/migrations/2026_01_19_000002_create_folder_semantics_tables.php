<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E13: Hierarchical Context Enhancement
 *
 * Creates tables for semantic folder understanding:
 * - folder_semantics: Learned meanings of folder names
 * - folder_research_queue: Queue for unknown folders needing web research
 *
 * Also adds columns to windows_file_actions for hierarchical context.
 */
return new class extends Migration
{
    /**
     * This migration is for MySQL only (references windows_file_actions table)
     */
    public function getConnection(): ?string
    {
        return 'mysql';
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create folder_semantics table
        DB::statement("
            CREATE TABLE IF NOT EXISTS folder_semantics (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                -- Folder identification (both name and pattern)
                folder_name VARCHAR(255) NOT NULL COMMENT 'Folder name, e.g., GTA5',
                folder_name_lower VARCHAR(255) GENERATED ALWAYS AS (LOWER(folder_name)) STORED,
                path_pattern VARCHAR(500) NULL COMMENT 'Optional path pattern, e.g., */GTA5/*, */Desktop/GUIDE POST LINKS/*',

                -- Semantic meaning
                semantic_meaning VARCHAR(255) NOT NULL COMMENT 'What this folder represents, e.g., Grand Theft Auto 5 video game',
                semantic_category VARCHAR(100) NOT NULL COMMENT 'High-level category: gaming, software, reference, work, etc.',
                suggested_destination VARCHAR(500) NULL COMMENT 'Learned destination path when Human corrects AI',

                -- Learning metadata
                source ENUM('human', 'ai_suggested', 'rag_inferred', 'llm_interpreted', 'web_research') NOT NULL DEFAULT 'human',
                confidence TINYINT UNSIGNED DEFAULT 100 COMMENT '0-100, decreases if Human overrides',
                times_used INT UNSIGNED DEFAULT 0 COMMENT 'How often this semantic was applied',
                times_overridden INT UNSIGNED DEFAULT 0 COMMENT 'How often Human changed AI suggestion using this',

                -- Context from learning event
                learned_from_path TEXT NULL COMMENT 'Original path where this was learned',
                learned_from_action_id BIGINT UNSIGNED NULL COMMENT 'FK to windows_file_actions if learned from correction',

                -- Standard fields
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                -- Indexes
                INDEX idx_folder_name_lower (folder_name_lower),
                INDEX idx_semantic_category (semantic_category),
                INDEX idx_path_pattern (path_pattern(100)),
                INDEX idx_source (source),
                INDEX idx_confidence (confidence),
                UNIQUE KEY uk_folder_pattern (folder_name_lower, path_pattern(100))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create folder_research_queue table
        DB::statement("
            CREATE TABLE IF NOT EXISTS folder_research_queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

                folder_name VARCHAR(255) NOT NULL,
                full_path TEXT NOT NULL COMMENT 'Path where this unknown folder was encountered',
                file_count INT UNSIGNED DEFAULT 0,
                total_size_bytes BIGINT UNSIGNED DEFAULT 0,

                -- Research status
                status ENUM('pending', 'researching', 'completed', 'failed', 'skipped') DEFAULT 'pending',

                -- Results
                research_result JSON NULL COMMENT 'Web research findings',
                suggested_meaning VARCHAR(255) NULL,
                suggested_category VARCHAR(100) NULL,
                confidence TINYINT UNSIGNED NULL COMMENT 'Research confidence 0-100',

                -- Tracking
                queued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                researched_at TIMESTAMP NULL,
                research_source VARCHAR(100) NULL COMMENT 'Which source provided the answer: rag, llm, web',
                error_message TEXT NULL,

                -- Prevent duplicates
                folder_name_lower VARCHAR(255) GENERATED ALWAYS AS (LOWER(folder_name)) STORED,

                INDEX idx_status (status),
                INDEX idx_queued_at (queued_at),
                INDEX idx_folder_name_lower (folder_name_lower),
                UNIQUE KEY uk_folder_pending (folder_name_lower, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add columns to windows_file_actions for hierarchical context
        // Only if the table exists (may not exist in test environment)
        if (! Schema::hasTable('windows_file_actions')) {
            return;
        }
        $columns = DB::select('SHOW COLUMNS FROM windows_file_actions');
        $columnNames = array_map(fn ($c) => $c->Field, $columns);

        if (! in_array('semantic_context', $columnNames)) {
            DB::statement("
                ALTER TABLE windows_file_actions
                ADD COLUMN semantic_context JSON NULL
                    COMMENT 'Hierarchical semantic analysis: [{level, folder, meaning, category, source}]'
                    AFTER bundle_analysis
            ");
        }

        if (! in_array('proposed_scope_path', $columnNames)) {
            DB::statement("
                ALTER TABLE windows_file_actions
                ADD COLUMN proposed_scope_path VARCHAR(500) NULL
                    COMMENT 'AI proposed move boundary, e.g., /Library/Desktop/GUIDE POST LINKS/GTA5'
                    AFTER semantic_context
            ");
        }

        if (! in_array('human_adjusted_scope', $columnNames)) {
            DB::statement("
                ALTER TABLE windows_file_actions
                ADD COLUMN human_adjusted_scope VARCHAR(500) NULL
                    COMMENT 'If Human changed scope, store their choice for learning'
                    AFTER proposed_scope_path
            ");
        }

        // Also add sensitivity columns if not present (from prior enhancement)
        if (! in_array('sensitivity_level', $columnNames)) {
            DB::statement("
                ALTER TABLE windows_file_actions
                ADD COLUMN sensitivity_level ENUM('high', 'medium', 'low') NULL
                    COMMENT 'PII sensitivity level detected in file'
                    AFTER human_adjusted_scope
            ");
        }

        if (! in_array('sensitivity_findings', $columnNames)) {
            DB::statement("
                ALTER TABLE windows_file_actions
                ADD COLUMN sensitivity_findings JSON NULL
                    COMMENT 'Detailed PII findings from SensitiveDataDetectorService'
                    AFTER sensitivity_level
            ");
        }

        if (! in_array('evidence_trail', $columnNames)) {
            DB::statement("
                ALTER TABLE windows_file_actions
                ADD COLUMN evidence_trail JSON NULL
                    COMMENT 'Explainability data showing WHY AI made categorization decision'
                    AFTER sensitivity_findings
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop new tables
        DB::statement('DROP TABLE IF EXISTS folder_research_queue');
        DB::statement('DROP TABLE IF EXISTS folder_semantics');

        // Remove added columns from windows_file_actions
        $columns = DB::select('SHOW COLUMNS FROM windows_file_actions');
        $columnNames = array_map(fn ($c) => $c->Field, $columns);

        $columnsToRemove = [
            'semantic_context',
            'proposed_scope_path',
            'human_adjusted_scope',
            'sensitivity_level',
            'sensitivity_findings',
            'evidence_trail',
        ];

        foreach ($columnsToRemove as $col) {
            if (in_array($col, $columnNames)) {
                DB::statement("ALTER TABLE windows_file_actions DROP COLUMN {$col}");
            }
        }
    }
};
