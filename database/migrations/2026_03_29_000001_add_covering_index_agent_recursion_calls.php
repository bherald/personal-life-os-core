<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Covering index for MorningDigestCommand RLM stats query:
        // SELECT COUNT(*), SUM(tokens_used), SUM(CASE WHEN move_on_triggered...) WHERE created_at >= ?
        // Without this, the query scans 11.6M rows from disk (avg 9,141s in slow query log).
        // With covering index, all columns are in the index — no table lookups needed.
        DB::statement('CREATE INDEX idx_arc_created_covering ON agent_recursion_calls(created_at, tokens_used, move_on_triggered)');

        // Drop the now-redundant single-column index (prefix is covered by the new index)
        DB::statement('DROP INDEX idx_arc_created ON agent_recursion_calls');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX idx_arc_created_covering ON agent_recursion_calls');
        DB::statement('CREATE INDEX idx_arc_created ON agent_recursion_calls(created_at)');
    }
};
