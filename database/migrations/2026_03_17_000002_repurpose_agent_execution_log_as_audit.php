<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INF-3: Repurpose unused agent_execution_log into structured audit log.
 *
 * The table was created in 2026_02_13 but never populated.
 * Adding columns for proper action auditing: agent, action type, risk level, outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only alter if table exists and is empty (safe guard)
        if (!Schema::hasTable('agent_execution_log')) {
            return;
        }

        $count = DB::selectOne('SELECT COUNT(*) as cnt FROM agent_execution_log');
        if ($count->cnt > 0) {
            // Table has data — don't alter, something else is using it
            return;
        }

        DB::statement("ALTER TABLE agent_execution_log
            ADD COLUMN agent_name VARCHAR(100) NULL AFTER session_id,
            ADD COLUMN action_type VARCHAR(50) NOT NULL DEFAULT 'tool_call' AFTER agent_name,
            ADD COLUMN action_detail VARCHAR(255) NULL AFTER action_type,
            ADD COLUMN risk_level ENUM('read','write','destructive','blocked') NULL AFTER action_detail,
            ADD COLUMN context JSON NULL AFTER risk_level,
            ADD COLUMN outcome ENUM('success','failure','denied','timeout','skipped') NOT NULL DEFAULT 'success' AFTER context
        ");

        // Add indexes for common queries
        DB::statement("ALTER TABLE agent_execution_log
            ADD INDEX idx_ael_agent_name (agent_name),
            ADD INDEX idx_ael_action_type (action_type),
            ADD INDEX idx_ael_created_at (created_at),
            ADD INDEX idx_ael_risk_level (risk_level)
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('agent_execution_log')) {
            return;
        }

        // Drop indexes first
        DB::statement("ALTER TABLE agent_execution_log
            DROP INDEX IF EXISTS idx_ael_agent_name,
            DROP INDEX IF EXISTS idx_ael_action_type,
            DROP INDEX IF EXISTS idx_ael_created_at,
            DROP INDEX IF EXISTS idx_ael_risk_level
        ");

        DB::statement("ALTER TABLE agent_execution_log
            DROP COLUMN IF EXISTS agent_name,
            DROP COLUMN IF EXISTS action_type,
            DROP COLUMN IF EXISTS action_detail,
            DROP COLUMN IF EXISTS risk_level,
            DROP COLUMN IF EXISTS context,
            DROP COLUMN IF EXISTS outcome
        ");
    }
};
