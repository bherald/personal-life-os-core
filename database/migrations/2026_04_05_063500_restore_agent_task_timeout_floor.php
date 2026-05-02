<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::update(
            "UPDATE scheduled_jobs
             SET timeout_minutes = 60, updated_at = NOW()
             WHERE enabled = 1
               AND job_type = 'agent_task'
               AND COALESCE(timeout_minutes, 0) < 60"
        );
    }

    public function down(): void
    {
        // No-op: prior lower values were adaptive artifacts, not durable config.
    }
};
