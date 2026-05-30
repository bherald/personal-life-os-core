<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMPACT_COMMAND = 'genealogy:memory-backfill --tree=all --lanes=all --limit=25 --confirm --json --compact';

    private const FULL_COMMAND = 'genealogy:memory-backfill --tree=all --lanes=all --limit=25 --confirm --json';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_memory_backfill')
            ->update([
                'command' => self::COMPACT_COMMAND,
                'last_run_output' => null,
                'notes' => 'Bounded confirmed Genea learning-memory backfill. Scheduled JSON output is aggregate-only and excludes run details, memory ids, source/person ids, raw lane payloads, and raw error text.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_memory_backfill')
            ->update([
                'command' => self::FULL_COMMAND,
                'last_run_output' => null,
                'notes' => 'Backfills local Genea learning memory across all trees in small confirmed batches. Writes only agent_semantic_memory consensus facts and does not alter canonical genealogy facts.',
                'updated_at' => now(),
            ]);
    }
};
