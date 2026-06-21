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
            ['name' => 'llm_capability_watch'],
            [
                'description' => 'Read-only LLM/runtime capability posture and watch-family summary.',
                'job_type' => 'command',
                'command' => 'llm:capability-watch --json --compact',
                'cron_expression' => '17 6,18 * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 5,
                'max_parallel' => 1,
                'category' => 'Maintenance',
                'source_module' => 'AI',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'llm',
                'resource_profile' => 'db',
                'stall_policy' => 'strict',
                'backlog_metric' => 'llm_capability_watch',
                'notification_mode' => 'digest',
                'notes' => 'Aggregate-only read-only capability posture for Ollama, BitNet/local sidecars, external bench providers, eval packs, and runtime watch families. Performs no network probes, external LLM calls, routing changes, or private-data sharing.',
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

        DB::table('scheduled_jobs')->where('name', 'llm_capability_watch')->delete();
    }
};
