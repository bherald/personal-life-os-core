<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOBS = [
        'genealogy_evidence_score_report' => [
            'notes' => 'Observe-only genealogy proposal evidence scoring. Scheduled JSON output is aggregate-only and excludes tree identifiers, proposal rows, proposal ids, person ids, related person ids, agent ids, source locators, and evidence excerpts.',
        ],
        'genealogy_memory_backfill' => [
            'notes' => 'Bounded confirmed Genea learning-memory backfill. Scheduled JSON output is aggregate-only and excludes tree identifiers, run details, memory ids, source/person ids, raw lane payloads, and raw error text.',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        foreach (self::JOBS as $name => $job) {
            DB::table('scheduled_jobs')
                ->where('name', $name)
                ->update([
                    'last_run_output' => null,
                    'notes' => $job['notes'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_evidence_score_report')
            ->update([
                'last_run_output' => null,
                'notes' => 'Reports pending genealogy proposal evidence strength by strong/medium/weak/conflict/missing for review planning. Observe-only; does not approve, reject, apply, or mutate proposals.',
                'updated_at' => now(),
            ]);

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_memory_backfill')
            ->update([
                'last_run_output' => null,
                'notes' => 'Backfills local Genea learning memory across all trees in small confirmed batches. Writes only agent_semantic_memory consensus facts and does not alter canonical genealogy facts.',
                'updated_at' => now(),
            ]);
    }
};
