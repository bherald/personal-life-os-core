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
            ['name' => 'agent_tool_policy_refresh'],
            [
                'description' => 'Refresh table-backed agent tool availability, privacy posture, and result limits.',
                'job_type' => 'command',
                'command' => 'ops:agent-tool-policy-refresh --apply --json --compact',
                'cron_expression' => '23 7,19 * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'stall_exempt' => 0,
                'timeout_minutes' => 10,
                'max_parallel' => 1,
                'category' => 'Maintenance',
                'source_module' => 'Agents',
                'runtime_mode' => 'maintenance',
                'workload_family' => 'agent_tools',
                'resource_profile' => 'db',
                'stall_policy' => 'strict',
                'backlog_metric' => 'agent_tool_policy_gap',
                'notification_mode' => 'digest',
                'notes' => 'Deterministic local refresh only. Resolves registered service/MCP metadata and writes agent_tool_registry availability/privacy/limit fields; performs no tool execution, network probes, external LLM calls, or private-data sharing.',
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

        DB::table('scheduled_jobs')->where('name', 'agent_tool_policy_refresh')->delete();
    }
};
