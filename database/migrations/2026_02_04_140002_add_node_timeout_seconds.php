<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add node-level timeout configuration to workflow_nodes table
 *
 * Allows configurable timeout per node (not just workflow-level).
 * Defaults to NULL which means use workflow default or system default (300 seconds).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add timeout_seconds column to workflow_nodes
        DB::statement("
            ALTER TABLE workflow_nodes
            ADD COLUMN timeout_seconds INT UNSIGNED NULL COMMENT 'Node execution timeout in seconds (NULL = use default)'
            AFTER node_order
        ");

        // Add timeout tracking columns to node_executions for monitoring
        DB::statement("
            ALTER TABLE node_executions
            ADD COLUMN timeout_seconds INT UNSIGNED NULL COMMENT 'Timeout applied for this execution'
            AFTER state
        ");

        DB::statement("
            ALTER TABLE node_executions
            ADD COLUMN timed_out TINYINT(1) DEFAULT 0 COMMENT 'Whether execution was terminated due to timeout'
            AFTER timeout_seconds
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE node_executions DROP COLUMN timed_out");
        DB::statement("ALTER TABLE node_executions DROP COLUMN timeout_seconds");
        DB::statement("ALTER TABLE workflow_nodes DROP COLUMN timeout_seconds");
    }
};
