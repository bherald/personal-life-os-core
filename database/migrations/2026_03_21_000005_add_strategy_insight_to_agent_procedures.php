<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AG-15: Add strategy_insight column for why-level abstraction
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE agent_procedures ADD COLUMN strategy_insight TEXT NULL AFTER action_sequence");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE agent_procedures DROP COLUMN IF EXISTS strategy_insight");
    }
};
