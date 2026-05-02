<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_jobs')->updateOrInsert(
            ['name' => 'kg_provenance_snapshot'],
            [
                'description' => 'Capture daily knowledge-graph provenance audit counts into pipeline metrics snapshots',
                'job_type' => 'command',
                'command' => 'graph:snapshot-provenance --json',
                'cron_expression' => '25 5 * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 10,
                'max_parallel' => 1,
                'category' => 'Maintenance',
                'source_module' => 'RAG',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'rag',
                'resource_profile' => 'default',
                'stall_policy' => 'strict',
                'backlog_metric' => 'kg_provenance',
                'notification_mode' => 'digest',
                'notes' => 'Daily observe-only KG provenance evidence after the overnight heavy window. Writes one idempotent kg_provenance row per date to pipeline_metrics_snapshots.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')->where('name', 'kg_provenance_snapshot')->delete();
    }
};
