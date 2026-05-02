<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET timeout_minutes = 60
            WHERE enabled = 1
              AND job_type = 'agent_task'
              AND (timeout_minutes IS NULL OR timeout_minutes < 60)
        ");
    }

    public function down(): void
    {
        // No rollback — timeout floor is an operational safety backfill.
    }
};
