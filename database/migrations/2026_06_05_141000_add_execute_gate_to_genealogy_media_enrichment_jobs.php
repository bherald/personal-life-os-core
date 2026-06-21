<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $commands = [
            'genealogy:enrich-media --limit=5' => 'genealogy:enrich-media --execute --limit=5',
            'genealogy:enrich-media --limit=10' => 'genealogy:enrich-media --execute --limit=10',
        ];

        $rows = DB::table('scheduled_jobs')
            ->whereIn('command', array_keys($commands))
            ->get(['id', 'command', 'notes']);

        foreach ($rows as $row) {
            $note = trim((string) ($row->notes ?? ''));
            $note .= ($note === '' ? '' : "\n").'2026-06-05: Added explicit --execute to genealogy media enrichment after command-level write gate was introduced.';

            DB::table('scheduled_jobs')
                ->where('id', $row->id)
                ->update([
                    'command' => $commands[$row->command],
                    'notes' => $note,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $commands = [
            'genealogy:enrich-media --execute --limit=5' => 'genealogy:enrich-media --limit=5',
            'genealogy:enrich-media --execute --limit=10' => 'genealogy:enrich-media --limit=10',
        ];

        $rows = DB::table('scheduled_jobs')
            ->whereIn('command', array_keys($commands))
            ->get(['id', 'command']);

        foreach ($rows as $row) {
            DB::table('scheduled_jobs')
                ->where('id', $row->id)
                ->update([
                    'command' => $commands[$row->command],
                    'updated_at' => now(),
                ]);
        }
    }
};
