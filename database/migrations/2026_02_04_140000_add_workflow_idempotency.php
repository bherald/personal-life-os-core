<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add idempotency key support to workflow_runs table
 *
 * Prevents duplicate workflow executions by checking for existing runs with the same idempotency key.
 * Keys are auto-generated from workflow_id + input hash, or can be client-provided.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add idempotency_key column to workflow_runs
        DB::statement("
            ALTER TABLE workflow_runs
            ADD COLUMN idempotency_key VARCHAR(64) NULL COMMENT 'SHA256 hash of workflow_id + normalized input for dedup'
            AFTER depth
        ");

        // Add unique index for idempotency checking
        DB::statement("
            ALTER TABLE workflow_runs
            ADD UNIQUE INDEX idx_idempotency_key (idempotency_key)
        ");

        // Add index for lookup by workflow + key combination
        DB::statement("
            ALTER TABLE workflow_runs
            ADD INDEX idx_workflow_idempotency (workflow_id, idempotency_key)
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE workflow_runs DROP INDEX idx_workflow_idempotency");
        DB::statement("ALTER TABLE workflow_runs DROP INDEX idx_idempotency_key");
        DB::statement("ALTER TABLE workflow_runs DROP COLUMN idempotency_key");
    }
};
