<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMPACT_COMMAND = 'genealogy:backfill-source-media --mode=sources --tree=all --since=30d --limit=25 --order=oldest --confirm-download --confirm-storage-write --nara-metadata-snapshot --json --compact';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_backfill_source_media')
            ->update([
                'command' => self::COMPACT_COMMAND,
                'last_run_output' => null,
                'notes' => 'Backfills source media in bounded batches. Scheduled JSON output is aggregate-only and excludes tree identifiers, per-source rows, source ids, source titles, media ids, URLs, URL hosts, local paths, and raw errors; confirmed download/storage/link flags remain explicit in the command.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_backfill_source_media')
            ->update([
                'command' => self::COMPACT_COMMAND,
                'last_run_output' => null,
                'notes' => 'Backfills source media for recently updated genealogy sources in bounded batches, saving captured evidence assets and linking citations only when the explicit confirm flags are present.',
                'updated_at' => now(),
            ]);
    }
};
