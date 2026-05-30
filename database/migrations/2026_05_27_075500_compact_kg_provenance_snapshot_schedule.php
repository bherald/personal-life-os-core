<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMPACT_COMMAND = 'graph:snapshot-provenance --json --compact';

    private const FULL_COMMAND = 'graph:snapshot-provenance --json';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'kg_provenance_snapshot')
            ->update([
                'command' => self::COMPACT_COMMAND,
                'last_run_output' => null,
                'notes' => 'Daily KG provenance metrics snapshot after the overnight heavy window. Scheduled JSON output is aggregate-only and excludes raw audit payloads, sample rows, source document ids, entity ids, and graph rows; the job still writes one idempotent kg_provenance row per date to pipeline_metrics_snapshots.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'kg_provenance_snapshot')
            ->update([
                'command' => self::FULL_COMMAND,
                'last_run_output' => null,
                'notes' => 'Daily observe-only KG provenance evidence after the overnight heavy window. Writes one idempotent kg_provenance row per date to pipeline_metrics_snapshots.',
                'updated_at' => now(),
            ]);
    }
};
