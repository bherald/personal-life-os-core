<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Small TODO-001 throughput bump after a clean 24h/7d stability window.
 *
 * The original catch-up lane used --budget-minutes=95 while the live scheduler
 * timeout is 90 minutes. Keep the run bounded under that timeout and only raise
 * the document cap from 100 to 125.
 */
return new class extends Migration
{
    private const NEW_COMMAND = 'rag:build-knowledge-graph --limit=125 --sleep=750 --min-chars=50 --max-chars=8000 --backlog=all --order=stale-first --budget-minutes=85 --instance=secondary --model-role=standard';

    private const OLD_COMMAND = 'rag:build-knowledge-graph --limit=100 --sleep=750 --min-chars=50 --max-chars=8000 --backlog=all --order=stale-first --budget-minutes=95 --instance=secondary --model-role=standard';

    public function up(): void
    {
        DB::update(
            'UPDATE scheduled_jobs
             SET command = ?,
                 notes = ?,
                 updated_at = NOW()
             WHERE name = ?',
            [
                self::NEW_COMMAND,
                'TODO-001 KG catch-up lane: 2026-04-24 stability pass showed zero 24h/7d failures and about 1,500 KG docs/day. Raised catch-up cap 100 to 125 and kept budget below 90-minute scheduler timeout.',
                'knowledge_graph_catchup',
            ]
        );
    }

    public function down(): void
    {
        DB::update(
            'UPDATE scheduled_jobs
             SET command = ?,
                 notes = ?,
                 updated_at = NOW()
             WHERE name = ?',
            [
                self::OLD_COMMAND,
                'APL #1A catch-up lane: bounded stale-first KG pass between primary 4-hour runs. Target about +600 docs/day while leaving headroom before the next main KG slot.',
                'knowledge_graph_catchup',
            ]
        );
    }
};
