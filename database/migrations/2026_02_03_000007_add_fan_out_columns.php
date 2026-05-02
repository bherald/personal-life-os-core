<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add fan-out/fan-in parallelism columns to node_executions table.
 *
 * Enables parallel branch execution where:
 * - branch_index: identifies which branch of a fan-out this execution belongs to
 * - parent_fan_out_id: links back to the FanOut node that spawned this branch
 * - state: tracks execution state (pending, running, success, failed, skipped)
 * - input/output: JSON columns for branch-specific data
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add columns for fan-out/fan-in tracking
        DB::statement('ALTER TABLE node_executions ADD COLUMN branch_index INT UNSIGNED DEFAULT 0');
        DB::statement('ALTER TABLE node_executions ADD COLUMN parent_fan_out_id VARCHAR(255) NULL');
        DB::statement('ALTER TABLE node_executions ADD COLUMN state ENUM("pending", "running", "success", "failed", "skipped") DEFAULT "running"');
        DB::statement('ALTER TABLE node_executions ADD COLUMN input JSON NULL');
        DB::statement('ALTER TABLE node_executions ADD COLUMN output JSON NULL');

        // Index for efficient fan-in queries (find all branches for a given fan-out)
        DB::statement('CREATE INDEX idx_fan_out ON node_executions(run_id, parent_fan_out_id)');

        // Index for branch ordering
        DB::statement('CREATE INDEX idx_branch ON node_executions(parent_fan_out_id, branch_index)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX idx_branch ON node_executions');
        DB::statement('DROP INDEX idx_fan_out ON node_executions');
        DB::statement('ALTER TABLE node_executions DROP COLUMN output');
        DB::statement('ALTER TABLE node_executions DROP COLUMN input');
        DB::statement('ALTER TABLE node_executions DROP COLUMN state');
        DB::statement('ALTER TABLE node_executions DROP COLUMN parent_fan_out_id');
        DB::statement('ALTER TABLE node_executions DROP COLUMN branch_index');
    }
};
