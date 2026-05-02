<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::update(
            'UPDATE scheduled_jobs
             SET command = ?,
                 timeout_minutes = GREATEST(COALESCE(timeout_minutes, 0), 180),
                 notes = ?,
                 updated_at = NOW()
             WHERE name = ?',
            [
                'rag:build-knowledge-graph --limit=150 --sleep=750 --min-chars=50 --max-chars=8000',
                'THROTTLED Apr11: KG batch pinned to local fast Ollama path, 150 docs/run with 750ms spacing to reduce contention.',
                'knowledge_graph_build',
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
                'rag:build-knowledge-graph --limit=750',
                'BOOSTED Mar5: sleep reduced from 2000ms to 500ms per doc. N70 KG array type fix active.',
                'knowledge_graph_build',
            ]
        );
    }
};
