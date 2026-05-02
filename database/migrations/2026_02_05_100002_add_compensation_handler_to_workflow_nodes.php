<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add compensation_handler column to workflow_nodes for Saga pattern rollback
 *
 * Enables per-node compensation handlers for workflow failure recovery.
 * When a workflow fails, CompensationService executes handlers in reverse order.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add compensation_handler column to workflow_nodes
        if (!Schema::hasColumn('workflow_nodes', 'compensation_handler')) {
            DB::statement("
                ALTER TABLE workflow_nodes
                ADD COLUMN compensation_handler VARCHAR(255) NULL
                COMMENT 'Compensation handler class or method for rollback'
                AFTER timeout_seconds
            ");
        }

        // Add compensation_config for handler-specific parameters
        if (!Schema::hasColumn('workflow_nodes', 'compensation_config')) {
            DB::statement("
                ALTER TABLE workflow_nodes
                ADD COLUMN compensation_config JSON NULL
                COMMENT 'Configuration for the compensation handler'
                AFTER compensation_handler
            ");
        }

        // Add compensation-related event types to workflow_events
        // Note: ENUM modification in MySQL requires ALTER TYPE
        DB::statement("
            ALTER TABLE workflow_events
            MODIFY COLUMN event_type ENUM(
                'NodeStarted',
                'NodeCompleted',
                'NodeFailed',
                'SignalReceived',
                'VariableSet',
                'CompensationStarted',
                'CompensationCompleted',
                'CompensationFailed'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        // Revert ENUM to original values
        DB::statement("
            ALTER TABLE workflow_events
            MODIFY COLUMN event_type ENUM(
                'NodeStarted',
                'NodeCompleted',
                'NodeFailed',
                'SignalReceived',
                'VariableSet'
            ) NOT NULL
        ");

        if (Schema::hasColumn('workflow_nodes', 'compensation_config')) {
            DB::statement("ALTER TABLE workflow_nodes DROP COLUMN compensation_config");
        }

        if (Schema::hasColumn('workflow_nodes', 'compensation_handler')) {
            DB::statement("ALTER TABLE workflow_nodes DROP COLUMN compensation_handler");
        }
    }
};
