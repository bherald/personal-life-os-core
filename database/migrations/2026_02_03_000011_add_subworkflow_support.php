<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add sub-workflow support to workflow_runs table.
 *
 * Enables hierarchical workflow execution where:
 * - parent_run_id: links to parent workflow run (NULL for top-level runs)
 * - parent_node_execution_id: links to the SubWorkflowNode execution that spawned this child
 * - depth: tracks nesting level (0 = top-level, 1 = first child, etc.)
 *
 * Also adds max_depth configuration in system_configs for recursion protection.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add columns for sub-workflow tracking (IF NOT EXISTS for idempotency)
        // Note: parent_run_id must match workflow_runs.id type (INT UNSIGNED)
        $columns = DB::select("SHOW COLUMNS FROM workflow_runs LIKE 'parent_run_id'");
        if (empty($columns)) {
            DB::statement('ALTER TABLE workflow_runs ADD COLUMN parent_run_id INT UNSIGNED NULL');
        } else {
            // Fix type mismatch if column exists with wrong type
            $col = $columns[0];
            if (stripos($col->Type, 'bigint') !== false) {
                DB::statement('ALTER TABLE workflow_runs MODIFY COLUMN parent_run_id INT UNSIGNED NULL');
            }
        }

        $columns = DB::select("SHOW COLUMNS FROM workflow_runs LIKE 'parent_node_execution_id'");
        if (empty($columns)) {
            DB::statement('ALTER TABLE workflow_runs ADD COLUMN parent_node_execution_id INT UNSIGNED NULL');
        } else {
            // Fix type mismatch if column exists with wrong type
            $col = $columns[0];
            if (stripos($col->Type, 'bigint') !== false) {
                DB::statement('ALTER TABLE workflow_runs MODIFY COLUMN parent_node_execution_id INT UNSIGNED NULL');
            }
        }

        $columns = DB::select("SHOW COLUMNS FROM workflow_runs LIKE 'depth'");
        if (empty($columns)) {
            DB::statement('ALTER TABLE workflow_runs ADD COLUMN depth TINYINT UNSIGNED DEFAULT 0');
        }

        // Add foreign key for parent_run_id (self-referential) - check if exists first
        $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_NAME = 'workflow_runs' AND CONSTRAINT_NAME = 'fk_parent_run'");
        if (empty($fks)) {
            DB::statement('ALTER TABLE workflow_runs ADD CONSTRAINT fk_parent_run FOREIGN KEY (parent_run_id) REFERENCES workflow_runs(id) ON DELETE SET NULL');
        }

        // Index for efficient child run queries
        $indexes = DB::select("SHOW INDEX FROM workflow_runs WHERE Key_name = 'idx_parent_run'");
        if (empty($indexes)) {
            DB::statement('CREATE INDEX idx_parent_run ON workflow_runs(parent_run_id)');
        }

        // Index for finding runs by depth (useful for cleanup/monitoring)
        $indexes = DB::select("SHOW INDEX FROM workflow_runs WHERE Key_name = 'idx_depth'");
        if (empty($indexes)) {
            DB::statement('CREATE INDEX idx_depth ON workflow_runs(depth)');
        }

        // Add max_depth setting to system_configs (default 5)
        $exists = DB::selectOne("SELECT id FROM system_configs WHERE config_key = ?", ['workflow_max_depth']);
        if (!$exists) {
            DB::insert(
                "INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
                ['workflow', 'workflow_max_depth', '5', 'integer', 'Maximum nesting depth for sub-workflows to prevent infinite recursion']
            );
        }
    }

    public function down(): void
    {
        // Remove system config
        DB::delete("DELETE FROM system_configs WHERE config_key = ?", ['workflow_max_depth']);

        // Remove indexes
        DB::statement('DROP INDEX idx_depth ON workflow_runs');
        DB::statement('DROP INDEX idx_parent_run ON workflow_runs');

        // Remove foreign key
        DB::statement('ALTER TABLE workflow_runs DROP FOREIGN KEY fk_parent_run');

        // Remove columns
        DB::statement('ALTER TABLE workflow_runs DROP COLUMN depth');
        DB::statement('ALTER TABLE workflow_runs DROP COLUMN parent_node_execution_id');
        DB::statement('ALTER TABLE workflow_runs DROP COLUMN parent_run_id');
    }
};
