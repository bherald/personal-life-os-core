<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INF-10e: Add finding_type to agent_review_queue.
 * Links review items to remediation_actions for self-healing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agent_review_queue', 'finding_type')) {
            return;
        }

        DB::statement("
            ALTER TABLE agent_review_queue
            ADD COLUMN finding_type VARCHAR(100) NULL AFTER review_type,
            ADD INDEX idx_review_queue_finding_type (finding_type)
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('agent_review_queue', 'finding_type')) {
            DB::statement("ALTER TABLE agent_review_queue DROP INDEX idx_review_queue_finding_type, DROP COLUMN finding_type");
        }
    }
};
