<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMPACT_COMMAND = 'joplin:rag-reconcile --json --compact --event-hours=24 --triggered-only --limit=50 --max-delete-candidates=50 --max-dependent-rows=5000';

    private const FULL_COMMAND = 'joplin:rag-reconcile --json --event-hours=24 --triggered-only --limit=50 --max-delete-candidates=50 --max-dependent-rows=5000';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'joplin_rag_reconcile_review')
            ->update([
                'command' => self::COMPACT_COMMAND,
                'last_run_output' => null,
                'notes' => 'Bounded dry-run review lane only: --execute is absent, no RAG rows are deleted, scheduled JSON output is aggregate-only, and threshold breaches require an explicit manual command before any writeback.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'joplin_rag_reconcile_review')
            ->update([
                'command' => self::FULL_COMMAND,
                'last_run_output' => null,
                'notes' => 'Bounded dry-run review lane only: --execute is absent, no RAG rows are deleted, no Joplin source ids are emitted, samples use hashed source_ref values, and threshold breaches require an explicit manual command before any writeback.',
                'updated_at' => now(),
            ]);
    }
};
