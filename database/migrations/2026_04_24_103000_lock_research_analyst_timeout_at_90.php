<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-04-24 root-cause fix for the research_analyst_agent timeout
 * problem: three prior migrations bumped timeout_minutes 60→90 and
 * each one stuck for a few hours then reverted to 60. Today's prod
 * check found the cause:
 *
 *   ScheduledJobService::adaptJobTimeout() recalculates
 *   timeout_minutes after every successful run using
 *   ceil(p95_minutes) + 15-min buffer, floored at the job_type
 *   minimum (60 for agent_task). Successful research_analyst_agent
 *   runs average ~18min → adaptive math = 33 → floor 60. So every
 *   manual bump to 90 gets undone within hours of the next
 *   successful run.
 *
 * The system already supports per-job opt-out via the
 * `timeout_locked` boolean column (line 622 of ScheduledJobService —
 * "Skip if human has locked the timeout"). Three prior migrations
 * just didn't set it. Fixing now.
 *
 * Sets timeout_minutes = 90 AND timeout_locked = 1 atomically. The
 * lock prevents the adaptive loop from recomputing on the next
 * successful run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update(
            "UPDATE scheduled_jobs
             SET timeout_minutes = 90,
                 timeout_locked = 1,
                 updated_at = NOW()
             WHERE name = ?",
            ['research_analyst_agent']
        );
    }

    public function down(): void
    {
        // Unlock so the adaptive system can manage it again. Keep the
        // bumped timeout — operator can revert manually if desired.
        DB::update(
            "UPDATE scheduled_jobs
             SET timeout_locked = 0, updated_at = NOW()
             WHERE name = ?",
            ['research_analyst_agent']
        );
    }
};
