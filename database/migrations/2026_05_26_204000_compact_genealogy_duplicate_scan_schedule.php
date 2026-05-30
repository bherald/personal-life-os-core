<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMPACT_COMMAND = 'genealogy:duplicate-scan --all-trees --min-score=0.75 --limit=250 --json --compact';

    private const FULL_COMMAND = 'genealogy:duplicate-scan --all-trees --min-score=0.75 --limit=250 --json';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_duplicate_candidate_scan')
            ->update([
                'command' => self::COMPACT_COMMAND,
                'last_run_output' => null,
                'notes' => 'Review-first scan only. Scheduled JSON output is aggregate-only and excludes per-tree rows, tree ids, person ids, person names, and candidate rows; the job may create or refresh pending genealogy_duplicate_pairs rows but does not merge people or mutate canonical person/family facts.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_duplicate_candidate_scan')
            ->update([
                'command' => self::FULL_COMMAND,
                'last_run_output' => null,
                'notes' => 'Review-first scan only. Creates or refreshes pending genealogy_duplicate_pairs rows with score/reasons, but does not merge people or mutate canonical person/family facts.',
                'updated_at' => now(),
            ]);
    }
};
