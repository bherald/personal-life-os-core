<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::insert(
            "INSERT IGNORE INTO recursion_config (service_name, enabled, max_depth, max_tokens, max_time_seconds, max_cost_usd, strategies, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            ['auto_decompose', true, 1, 30000, 120, 0.25, '["partition_map"]', 'AIService-level auto-decompose: transparently splits large prompts into smaller sub-calls']
        );
    }

    public function down(): void
    {
        DB::delete("DELETE FROM recursion_config WHERE service_name = 'auto_decompose'");
    }
};
