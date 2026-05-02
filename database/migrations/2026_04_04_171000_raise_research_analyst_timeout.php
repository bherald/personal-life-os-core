<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET timeout_minutes = GREATEST(COALESCE(timeout_minutes, 0), 90),
                stall_exempt = 1,
                updated_at = NOW()
            WHERE name = 'research_analyst_agent'
        ");
    }

    public function down(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET timeout_minutes = 60,
                updated_at = NOW()
            WHERE name = 'research_analyst_agent'
        ");
    }
};
