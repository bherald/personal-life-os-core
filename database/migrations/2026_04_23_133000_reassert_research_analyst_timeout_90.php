<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-assert research_analyst_agent.timeout_minutes = 90.
 *
 * Item 6 of the 2026-04-23 batch sprint: the prod-ops investigation
 * confirmed research_analyst_agent runs are averaging 1078s of an
 * allocated 3600s and SIGALRM'd once at the 60-min cap on Wed
 * 2026-04-22 23:45 UTC. Two earlier migrations
 * (2026_04_11_143500_reassert_research_analyst_runtime_floor and
 * 2026_04_23_091000_bump_research_analyst_agent_timeout) both set
 * timeout_minutes to 90 and are both recorded as ran in the
 * migrations table — but prod shows 60. Something downgraded the row
 * after the migrations applied (manual edit, seed, or rollback).
 *
 * This migration unconditionally writes 90 (no `WHERE timeout < 90`
 * gate) so a subsequent downgrade can be detected: if the next
 * post-migration check finds 60 again, we know the issue is downstream
 * of the migration runner.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update(
            "UPDATE scheduled_jobs
             SET timeout_minutes = 90,
                 stall_exempt = 1,
                 updated_at = NOW()
             WHERE name = ?",
            ['research_analyst_agent']
        );
    }

    public function down(): void
    {
        // No-op: this reasserts the intended operational floor.
    }
};
