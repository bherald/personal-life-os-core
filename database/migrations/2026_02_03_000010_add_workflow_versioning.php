<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add workflow versioning support
 *
 * Enables version control for workflows:
 * - Snapshot workflow definitions on update
 * - Track which version was used for each run
 * - Support rollback to previous versions
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add current_version column to workflows table
        if (!Schema::hasColumn('workflows', 'current_version')) {
            DB::statement("ALTER TABLE workflows ADD COLUMN current_version INT UNSIGNED DEFAULT 1 AFTER active");
        }

        // Create workflow_versions table
        if (!Schema::hasTable('workflow_versions')) {
            DB::statement("
                CREATE TABLE workflow_versions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    workflow_id INT UNSIGNED NOT NULL,
                    version INT UNSIGNED NOT NULL,
                    definition JSON NOT NULL COMMENT 'Full workflow definition at this version',
                    nodes_snapshot JSON NOT NULL COMMENT 'Snapshot of workflow_nodes and configs',
                    created_by VARCHAR(255) NULL COMMENT 'User or system that created version',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    notes TEXT NULL COMMENT 'Optional notes about this version',
                    UNIQUE KEY uk_workflow_version (workflow_id, version),
                    INDEX idx_workflow_id (workflow_id),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // Create workflow_version_runs table to track which version each run used
        if (!Schema::hasTable('workflow_version_runs')) {
            DB::statement("
                CREATE TABLE workflow_version_runs (
                    run_id INT UNSIGNED NOT NULL,
                    workflow_version_id INT UNSIGNED NOT NULL,
                    PRIMARY KEY (run_id),
                    INDEX idx_version_id (workflow_version_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_version_runs');
        Schema::dropIfExists('workflow_versions');

        if (Schema::hasColumn('workflows', 'current_version')) {
            DB::statement("ALTER TABLE workflows DROP COLUMN current_version");
        }
    }
};
