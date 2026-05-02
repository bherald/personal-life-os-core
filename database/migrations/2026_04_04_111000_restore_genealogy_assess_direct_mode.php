<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'genealogy-researcher',
                notes = ?,
                stall_exempt = 1,
                timeout_minutes = 30,
                updated_at = NOW()
            WHERE name = 'genealogy_agent_assess'
        ", [json_encode(['mode' => 'assess'])]);
    }

    public function down(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'genealogy-assessor',
                notes = 'Assess phase only — discovers who needs research, manages coverage',
                updated_at = NOW()
            WHERE name = 'genealogy_agent_assess'
        ");
    }
};
