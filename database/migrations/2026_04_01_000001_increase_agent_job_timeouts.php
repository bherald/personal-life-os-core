<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Increase timeout_minutes for agentic agents that are hitting SIGALRM.
 *
 * Agentic agents (ai-ops, file-ops, email-ops, etc.) spend most time in
 * LLM HTTP calls. Their timeout_minutes was set too low (30-35min),
 * causing wall-clock pcntl_alarm to kill them before they complete.
 * Adaptive timeout extension only works in hybrid/queue modes, not agentic.
 *
 * Also ensures data_removal_ops_agent has stall_exempt=1 (S1 sprint fix
 * used name patterns that may have missed it).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Increase timeout for agentic agents that consistently SIGALRM
        $agentTimeouts = [
            'ai_ops_agent' => 60,
            'file_ops_agent' => 60,
            'email_ops_agent' => 60,
            'data_removal_ops_agent' => 60,
            'factcheck_ops_agent' => 60,
            'file_curator_agent' => 60,
            'youtube_ops_agent' => 60,
            'workflow_ops_agent' => 60,
            'log_analyst_agent' => 60,
            'system_guardian_agent' => 45,
        ];

        foreach ($agentTimeouts as $name => $timeout) {
            DB::update(
                "UPDATE scheduled_jobs SET timeout_minutes = ? WHERE name = ? AND (timeout_minutes IS NULL OR timeout_minutes < ?)",
                [$timeout, $name, $timeout]
            );
        }

        // Ensure data_removal_ops_agent has stall_exempt (belt + suspenders)
        DB::update(
            "UPDATE scheduled_jobs SET stall_exempt = 1 WHERE name = 'data_removal_ops_agent' AND stall_exempt = 0"
        );
    }

    public function down(): void
    {
        // No rollback — timeout values are tuned, not structural
    }
};
