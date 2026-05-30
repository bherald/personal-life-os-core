<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMPACT_COMMAND = 'genealogy:backfill-source-media --mode=sources --tree=all --since=30d --limit=25 --order=oldest --confirm-download --confirm-storage-write --nara-metadata-snapshot --json --compact';

    private const FULL_COMMAND = 'genealogy:backfill-source-media --mode=sources --tree=all --since=30d --limit=25 --order=oldest --confirm-download --confirm-storage-write --nara-metadata-snapshot --json';

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
                'notes' => 'Captures URL-only genealogy_sources into tree-local FT storage in small frequent batches. Scheduled JSON output is aggregate-only; failed rows are marked source_media_backfill_blocked and skipped until retry_blocked is requested.',
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
                'command' => self::FULL_COMMAND,
                'last_run_output' => null,
                'notes' => 'Captures URL-only genealogy_sources into tree-local FT storage in small frequent batches. Failed rows are marked source_media_backfill_blocked and skipped until retry_blocked is requested.',
                'updated_at' => now(),
            ]);
    }
};
