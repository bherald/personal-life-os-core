<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AG-4: Cross-Agent Shared Procedural Memory
 *
 * Adds is_shared flag to agent_procedures.
 * Canonical procedures auto-promoted to shared during consolidation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE agent_procedures
            ADD COLUMN is_shared TINYINT(1) NOT NULL DEFAULT 0
                AFTER is_canonical,
            ADD INDEX idx_shared_active (is_shared, is_retired)
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE agent_procedures DROP INDEX idx_shared_active");
        DB::statement("ALTER TABLE agent_procedures DROP COLUMN is_shared");
    }
};
