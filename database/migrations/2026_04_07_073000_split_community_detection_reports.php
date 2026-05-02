<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'community_detection')
            ->where('command', 'like', '%--reports%')
            ->update([
                'command' => 'graph:detect-communities --force',
                'notes' => DB::raw("CONCAT(COALESCE(notes, ''), ' | 2026-04-07: split reports out after detection completed but combined --reports phase overran scheduler timeout.')"),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')
            ->where('name', 'community_detection')
            ->where('command', 'graph:detect-communities --force')
            ->update([
                'command' => 'graph:detect-communities --force --reports --report-limit=50 --sleep=2000',
                'updated_at' => now(),
            ]);
    }
};
