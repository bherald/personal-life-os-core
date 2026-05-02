<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add speculative execution columns to agent_benchmarks.
     * Links benchmark entries to speculative runs for S20 (Adaptive Mode Selection) learning.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE agent_benchmarks
                ADD COLUMN is_speculative TINYINT(1) DEFAULT 0 AFTER error_message,
                ADD COLUMN spec_run_id VARCHAR(64) NULL AFTER is_speculative,
                ADD INDEX idx_speculative (is_speculative, spec_run_id)
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE agent_benchmarks
                DROP INDEX idx_speculative,
                DROP COLUMN spec_run_id,
                DROP COLUMN is_speculative
        ");
    }
};
