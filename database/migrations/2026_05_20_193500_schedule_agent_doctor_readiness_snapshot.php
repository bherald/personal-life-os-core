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
            ['name' => 'agent_doctor_readiness_snapshot'],
            [
                'description' => 'Capture aggregate Agent Doctor readiness snapshots for trend history.',
                'job_type' => 'command',
                'command' => 'ops:agent-doctor-snapshot --json --since=24',
                'cron_expression' => '17 */6 * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 5,
                'max_parallel' => 1,
                'category' => 'Maintenance',
                'source_module' => 'Ops',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'ops',
                'resource_profile' => 'db',
                'stall_policy' => 'strict',
                'backlog_metric' => 'agent_doctor_readiness',
                'notification_mode' => 'digest',
                'notes' => 'Append-only observe snapshot for Agent Doctor history. Stores aggregate statuses, counts, check ids, and output-quality counts only; excludes per-agent detail, raw traces, prompts, completions, command output, and filesystem paths.',
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
            ->where('name', 'agent_doctor_readiness_snapshot')
            ->delete();
    }
};
