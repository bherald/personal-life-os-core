<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wire `ollama:drift-check` into the scheduler so model drift between
 * llm_instances.supported_models and each host's live /api/tags surfaces
 * daily instead of waiting for an operator to run the command manually.
 *
 * Finding F3 (commit 4da623c8a) made the drift check trustworthy for
 * empty-but-healthy hosts — before that a scheduled drift check would
 * have produced noisy false positives. With F3 shipped, scheduling is
 * safe.
 *
 * Schedule: daily at 05:30 America/New_York, well before the 05:50 daily
 * report fires (MorningDigestCommand reads LLM state) so any drift is
 * already visible when operator reads the morning digest. 10-minute
 * timeout is generous — the command makes at most N HTTP requests to
 * live Ollama hosts with a 15s per-call timeout.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO scheduled_jobs
                (
                    name,
                    description,
                    job_type,
                    command,
                    cron_expression,
                    enabled,
                    run_in_background,
                    without_overlapping,
                    stall_exempt,
                    timeout_minutes,
                    max_parallel,
                    category,
                    source_module,
                    notes,
                    created_at,
                    updated_at
                )
            VALUES
                (
                    'ollama_drift_check',
                    'Daily drift check between llm_instances.supported_models and each live Ollama host /api/tags',
                    'command',
                    'ollama:drift-check --no-fail',
                    '30 5 * * *',
                    1,
                    0,
                    1,
                    1,
                    10,
                    1,
                    'Ollama',
                    'OllamaModelRegistry',
                    'Row 3 follow-on. --no-fail keeps the scheduled job non-blocking; drift is surfaced in the daily-report ROUTING section plus the command output. Command routed via OllamaDriftCheckCommand -> OllamaModelRegistryService::driftCheck().',
                    NOW(),
                    NOW()
                )
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                command = VALUES(command),
                cron_expression = VALUES(cron_expression),
                enabled = VALUES(enabled),
                run_in_background = VALUES(run_in_background),
                without_overlapping = VALUES(without_overlapping),
                stall_exempt = VALUES(stall_exempt),
                timeout_minutes = VALUES(timeout_minutes),
                max_parallel = VALUES(max_parallel),
                category = VALUES(category),
                source_module = VALUES(source_module),
                notes = VALUES(notes),
                updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')->where('name', 'ollama_drift_check')->delete();
    }
};
