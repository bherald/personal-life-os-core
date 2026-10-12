<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'mcp_tool_contract_audit';

    private const COMMAND = 'ops:mcp-tool-contract-audit --json --compact --fail-on-violations';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $now = now();
        $values = [
            'description' => 'Observe-only live PLOS MCP tools/list security and reviewed-baseline drift audit.',
            'job_type' => 'command',
            'command' => self::COMMAND,
            'cron_expression' => '47 6 * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 2,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => 'Agents',
            'runtime_mode' => 'observe',
            'workload_family' => 'agent_safety',
            'resource_profile' => 'default',
            'stall_policy' => 'strict',
            'backlog_metric' => 'mcp_tool_contract_drift',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'summary' => 'Exercises only the trusted PLOS stdio tools/list endpoint and fails on metadata, schema, annotation, name, or reviewed content-digest drift.',
                'policy_source' => 'mcp-servers.plos.tool-contract-baseline.json',
                'runtime' => [
                    'protocol_version' => '2025-06-18',
                    'integrity_schema' => 'plos.mcp_tool_contract_audit.v1',
                    'compact' => true,
                    'fail_on_violations' => true,
                    'fail_on_drift' => true,
                    'arbitrary_mcp_config_execution' => false,
                ],
                'safety' => [
                    'read_only' => true,
                    'network_used' => false,
                    'metadata_uploaded' => false,
                    'raw_descriptions_or_schemas_emitted' => false,
                    'baseline_refresh_requires_review' => true,
                ],
            ], JSON_UNESCAPED_SLASHES),
            'last_run_output' => null,
            'updated_at' => $now,
        ];

        $existing = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->first(['id']);

        if ($existing !== null) {
            DB::table('scheduled_jobs')->where('id', $existing->id)->update($values);

            return;
        }

        DB::table('scheduled_jobs')->insert(array_merge(
            ['name' => self::JOB_NAME, 'created_at' => $now],
            $values
        ));
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')->where('name', self::JOB_NAME)->delete();
    }
};
