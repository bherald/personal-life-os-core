<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
                    'knowledge_graph_catchup',
                    'Offset stale-first knowledge graph catch-up pass for APL #1A backlog burn-down',
                    'command',
                    'rag:build-knowledge-graph --limit=100 --sleep=750 --min-chars=50 --max-chars=8000 --backlog=all --order=stale-first --budget-minutes=95',
                    '15 2-22/4 * * *',
                    1,
                    1,
                    1,
                    1,
                    110,
                    1,
                    'RAG',
                    'KnowledgeGraph',
                    'APL #1A catch-up lane: bounded stale-first KG pass between primary 4-hour runs. Target about +600 docs/day while leaving headroom before the next main KG slot.',
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
        DB::table('scheduled_jobs')->where('name', 'knowledge_graph_catchup')->delete();
    }
};
