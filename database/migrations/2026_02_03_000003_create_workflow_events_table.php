<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create workflow_events table for checkpointing/resume functionality
 *
 * Event-sourced workflow state tracking following Temporal.io patterns.
 * Enables replay of workflow execution to rebuild state after failures.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE workflow_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                execution_id CHAR(36) NOT NULL COMMENT 'UUID linking events to a single workflow execution',
                sequence INT UNSIGNED NOT NULL COMMENT 'Monotonically increasing event number per execution',
                event_type ENUM('NodeStarted', 'NodeCompleted', 'NodeFailed', 'SignalReceived', 'VariableSet') NOT NULL,
                node_id VARCHAR(255) NULL COMMENT 'Node identifier (workflow_node.id or custom)',
                payload JSON NULL COMMENT 'Event-specific data (input, output, error, etc.)',
                metadata JSON NULL COMMENT 'Contextual info (duration_ms, attempt, user, etc.)',
                recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_execution_sequence (execution_id, sequence),
                INDEX idx_execution_node (execution_id, node_id),
                INDEX idx_event_type (event_type),
                INDEX idx_recorded_at (recorded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_events');
    }
};
