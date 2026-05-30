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

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_evidence_score_report')
            ->update([
                'command' => 'genealogy:evidence-score --all-trees --json --compact --limit=100',
                'last_run_output' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_evidence_score_report')
            ->update([
                'command' => 'genealogy:evidence-score --all-trees --json --limit=100',
                'updated_at' => now(),
            ]);
    }
};
