<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET enabled = 0,
                notes = CONCAT(
                    COALESCE(notes, ''),
                    CASE
                        WHEN COALESCE(notes, '') = '' THEN ''
                        ELSE '\n'
                    END,
                    '[disabled 2026-04-11] legacy research_run superseded by research_run_missions'
                ),
                updated_at = NOW()
            WHERE name = 'research_run'
        ");

        DB::update("
            UPDATE scheduled_jobs
            SET stall_exempt = 1,
                timeout_minutes = GREATEST(COALESCE(timeout_minutes, 0), 45),
                notes = CONCAT(
                    COALESCE(notes, ''),
                    CASE
                        WHEN COALESCE(notes, '') = '' THEN ''
                        ELSE '\n'
                    END,
                    '[stabilized 2026-04-11] stall-exempt because checkpointed ops job is I/O-heavy and continuation-safe'
                ),
                updated_at = NOW()
            WHERE name = 'ops_maintenance'
        ");
    }

    public function down(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET enabled = 1,
                updated_at = NOW()
            WHERE name = 'research_run'
        ");

        DB::update("
            UPDATE scheduled_jobs
            SET stall_exempt = 0,
                updated_at = NOW()
            WHERE name = 'ops_maintenance'
        ");
    }
};
