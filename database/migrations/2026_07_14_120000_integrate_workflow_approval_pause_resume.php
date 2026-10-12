<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE workflow_runs MODIFY COLUMN status ENUM('running', 'awaiting_approval', 'completed', 'failed') NOT NULL");
        DB::statement("ALTER TABLE node_executions MODIFY COLUMN state ENUM('pending', 'running', 'awaiting_approval', 'success', 'failed', 'skipped') NOT NULL DEFAULT 'running'");

        DB::statement('ALTER TABLE workflow_approval_gates ADD COLUMN execution_id CHAR(36) NULL AFTER node_execution_id');
        DB::statement('ALTER TABLE workflow_approval_gates ADD COLUMN node_id VARCHAR(255) NULL AFTER execution_id');
        DB::statement('ALTER TABLE workflow_approval_gates ADD COLUMN decision_payload JSON NULL AFTER context');
        DB::statement('ALTER TABLE workflow_approval_gates ADD UNIQUE INDEX uk_gate_execution_node (execution_id, node_id)');
    }

    public function down(): void
    {
        DB::update("UPDATE workflow_runs SET status = 'failed', error_message = COALESCE(error_message, 'Approval checkpoint rolled back.'), completed_at = COALESCE(completed_at, NOW()) WHERE status = 'awaiting_approval'");
        DB::update("UPDATE node_executions SET state = 'failed', error_message = COALESCE(error_message, 'Approval checkpoint rolled back.') WHERE state = 'awaiting_approval'");

        DB::statement('ALTER TABLE workflow_approval_gates DROP INDEX uk_gate_execution_node');
        DB::statement('ALTER TABLE workflow_approval_gates DROP COLUMN decision_payload');
        DB::statement('ALTER TABLE workflow_approval_gates DROP COLUMN node_id');
        DB::statement('ALTER TABLE workflow_approval_gates DROP COLUMN execution_id');

        DB::statement("ALTER TABLE node_executions MODIFY COLUMN state ENUM('pending', 'running', 'success', 'failed', 'skipped') NOT NULL DEFAULT 'running'");
        DB::statement("ALTER TABLE workflow_runs MODIFY COLUMN status ENUM('running', 'completed', 'failed') NOT NULL");
    }
};
