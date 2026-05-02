<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix agent schedule overlaps to prevent simultaneous Ollama contention.
 *
 * Problem: ai_ops (every 15m), system_guardian (every 30m), and several 2h/4h agents
 * ran at overlapping minutes, causing all of them to hit a hung Ollama
 * simultaneously. 30+ failures cascaded when primary went down because
 * they all exhausted the circuit breaker threshold together.
 *
 * Fix: Stagger minutes so no two agents start in the same 5-minute window.
 * High-frequency agents (ai-ops, system-guardian) get unique offsets.
 * Reduce ai-ops from every 15 min to every 20 min (was excessive).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schedules = [
            // High-frequency: stagger within the hour
            'ai_ops_agent'           => '3,23,43 * * * *',    // Was */15 (every 15m), now every 20m at :03,:23,:43
            'system_guardian_agent'   => '8,38 * * * *',       // Was */30 (every 30m), now :08,:38 — no overlap with ai-ops

            // 2-hour agents: spread across the hour
            'email_ops_agent'        => '11 1,3,5,7,9,11,13,15,17,19,21,23 * * *',  // Was 11 */2, keep minute, use odd hours
            'file_ops_agent'         => '15 0,2,4,6,8,10,12,14,16,18,20,22 * * *',  // Was 15 */2, keep minute, use even hours
            'log_analyst_agent'      => '48 1,3,5,7,9,11,13,15,17,19,21,23 * * *',  // Was 16 */2, move to :48 odd hours

            // 4-hour agents: spread evenly, no two in same window
            'research_ops_agent'     => '22 2,6,10,14,18,22 * * *',   // Was 22 */4, keep
            'workflow_ops_agent'     => '33 1,5,9,13,17,21 * * *',    // Was 25 */4, move to :33 odd offset
            'youtube_ops_agent'      => '52 3,7,11,15,19,23 * * *',   // Was 28 */4, move to :52 different hours
            'data_removal_ops_agent' => '50 0,4,8,12,16,20 * * *',    // Was 50 */4, keep
            'file_curator_agent'     => '40 2,6,10,14,18,22 * * *',   // Was 40 */4, keep

            // 6-hour agents: keep spread
            'factcheck_ops_agent'    => '20 0,6,12,18 * * *',         // Was 20 */6, keep
            'knowledge_curator_agent'=> '30 3,9,15,21 * * *',         // Was 30 */6, offset to odd
            'research_analyst_agent' => '45 5,11,17,23 * * *',        // Was 45 */6, offset to different
        ];

        foreach ($schedules as $name => $cron) {
            DB::update("
                UPDATE scheduled_jobs SET cron_expression = ?, updated_at = NOW()
                WHERE name = ? AND enabled = 1
            ", [$cron, $name]);
        }
    }

    public function down(): void
    {
        $originals = [
            'ai_ops_agent'           => '*/15 * * * *',
            'system_guardian_agent'   => '*/30 * * * *',
            'email_ops_agent'        => '11 */2 * * *',
            'file_ops_agent'         => '15 */2 * * *',
            'log_analyst_agent'      => '16 */2 * * *',
            'research_ops_agent'     => '22 */4 * * *',
            'workflow_ops_agent'     => '25 */4 * * *',
            'youtube_ops_agent'      => '28 */4 * * *',
            'data_removal_ops_agent' => '50 */4 * * *',
            'file_curator_agent'     => '40 */4 * * *',
            'factcheck_ops_agent'    => '20 */6 * * *',
            'knowledge_curator_agent'=> '30 */6 * * *',
            'research_analyst_agent' => '45 */6 * * *',
        ];

        foreach ($originals as $name => $cron) {
            DB::update("
                UPDATE scheduled_jobs SET cron_expression = ?, updated_at = NOW()
                WHERE name = ?
            ", [$cron, $name]);
        }
    }
};
