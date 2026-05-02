<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET stall_exempt = 1
            WHERE enabled = 1
              AND stall_exempt = 0
              AND job_type = 'agent_task'
        ");
    }

    public function down(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET stall_exempt = 0
            WHERE enabled = 1
              AND stall_exempt = 1
              AND job_type = 'agent_task'
              AND name NOT IN (
                  'genealogy_agent_assess',
                  'genealogy_agent_research_queue'
              )
              AND name NOT LIKE '%\\_agent'
              AND name NOT LIKE '%\\_ops\\_agent'
              AND command NOT LIKE '%:operations%'
              AND command NOT LIKE 'agent:%'
        ");
    }
};
