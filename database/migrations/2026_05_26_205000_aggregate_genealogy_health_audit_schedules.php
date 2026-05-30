<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOBS = [
        'genealogy_health_audit' => [
            'aggregate_command' => 'genealogy:health-audit --all-trees --json --compact --aggregate --limit=20',
            'compact_command' => 'genealogy:health-audit --all-trees --json --compact --limit=20',
            'aggregate_notes' => 'Observe-only control-panel audit. Scheduled JSON output is aggregate-only and excludes tree rows, tree identifiers, issue rows, issue ids, entity ids, review targets, samples, and paths. Performs no downloads, storage writes, genealogy links, review decisions, privacy/export release, or canonical record writes.',
            'compact_notes' => 'Observe-only control-panel audit. Runs for every known family tree and performs no downloads, storage writes, genealogy links, review decisions, privacy/export release, or canonical record writes.',
        ],
        'genealogy_export_readiness_check' => [
            'aggregate_command' => 'genealogy:health-audit --all-trees --sections=export --json --compact --aggregate --limit=25',
            'compact_command' => 'genealogy:health-audit --all-trees --sections=export --json --compact --limit=25',
            'aggregate_notes' => 'Observe-only export preflight. Scheduled JSON output is aggregate-only and excludes tree rows, tree identifiers, issue rows, issue ids, entity ids, review targets, samples, and paths. Checks every tree for export blockers without downloads, link changes, or person/family fact writes.',
            'compact_notes' => 'Observe-only export preflight. Checks every tree for non-self-contained media paths and missing export blockers without downloads, link changes, or person/family fact writes.',
        ],
        'genealogy_unlinked_media_review' => [
            'aggregate_command' => 'genealogy:health-audit --all-trees --sections=media --json --compact --aggregate --limit=50',
            'compact_command' => 'genealogy:health-audit --all-trees --sections=media --json --compact --limit=50',
            'aggregate_notes' => 'Observe-only media review. Scheduled JSON output is aggregate-only and excludes tree rows, tree identifiers, issue rows, issue ids, entity ids, review targets, samples, and paths. Surfaces media health counts without deleting files, linking records, or changing person/family facts.',
            'compact_notes' => 'Observe-only media review. Surfaces unlinked media, missing local files, and external-only media without deleting files, linking records, or changing person/family facts.',
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
                    'command' => $job['aggregate_command'],
                    'last_run_output' => null,
                    'notes' => $job['aggregate_notes'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        foreach (self::JOBS as $name => $job) {
            DB::table('scheduled_jobs')
                ->where('name', $name)
                ->update([
                    'command' => $job['compact_command'],
                    'last_run_output' => null,
                    'notes' => $job['compact_notes'],
                    'updated_at' => now(),
                ]);
        }
    }
};
