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

        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'joplin_rag_reconcile_review'],
            [
                'description' => 'Dry-run Joplin RAG stale and duplicate reconcile review.',
                'job_type' => 'command',
                'command' => 'joplin:rag-reconcile --json --event-hours=24 --triggered-only --limit=50 --max-delete-candidates=50 --max-dependent-rows=5000',
                'cron_expression' => '23 */4 * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 10,
                'max_parallel' => 1,
                'category' => 'Maintenance',
                'source_module' => 'Joplin',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'rag',
                'resource_profile' => 'db',
                'stall_policy' => 'strict',
                'backlog_metric' => 'joplin_rag_reconcile',
                'notification_mode' => 'digest',
                'notes' => 'Bounded dry-run review lane only: --execute is absent, no RAG rows are deleted, no Joplin source ids are emitted, samples use hashed source_ref values, and threshold breaches require an explicit manual command before any writeback.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'joplin_rag_reconcile_review')
            ->delete();
    }
};
