<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return array<string,mixed>
     */
    private function policy(): array
    {
        return [
            'enabled' => true,
            'policy_source' => 'system_configs.ops.llm_health_reconcile_policy',
            'target_table' => 'llm_instances',
            'probe_scope' => 'active_instances',
            'reconcile_method' => 'LLMPoolManagerService::healthCheckAllInstances',
            'safety' => [
                'write_llm_instance_health' => true,
                'health_metadata_only' => true,
                'routing_changes_allowed' => false,
                'role_changes_allowed' => false,
                'routability_changes_allowed' => false,
                'max_concurrent_changes_allowed' => false,
                'external_llm_invoked' => false,
                'private_data_shared' => false,
            ],
            'output' => [
                'compact_scheduled_output' => true,
                'raw_provider_output_included' => false,
                'provider_urls_included' => false,
                'api_keys_included' => false,
            ],
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')->updateOrInsert(
                ['section' => 'ops', 'config_key' => 'llm_health_reconcile_policy'],
                [
                    'config_value' => json_encode($this->policy(), JSON_UNESCAPED_SLASHES),
                    'data_type' => 'json',
                    'description' => 'Table-owned policy for LLM provider health reconciliation from runtime probes.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        if (Schema::hasTable('scheduled_jobs')) {
            $this->upsertScheduledJob();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')
                ->where('name', 'llm_health_reconcile')
                ->delete();
        }

        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')
                ->where('section', 'ops')
                ->where('config_key', 'llm_health_reconcile_policy')
                ->delete();
        }
    }

    private function upsertScheduledJob(): void
    {
        $now = now();
        $values = [
            'description' => 'Reconcile LLM provider health rows from configured runtime probes.',
            'job_type' => 'command',
            'command' => 'ops:llm-health-reconcile --json --compact',
            'cron_expression' => '*/10 * * * *',
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
            'resource_profile' => 'network',
            'stall_policy' => 'strict',
            'backlog_metric' => 'llm_health_stale',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'summary' => 'Repairs stale llm_instances health/circuit metadata when configured runtime probes succeed.',
                'policy_source' => 'system_configs.ops.llm_health_reconcile_policy',
                'safety' => [
                    'health_metadata_write' => true,
                    'routing_changes_allowed' => false,
                    'role_changes_allowed' => false,
                    'max_concurrent_changes_allowed' => false,
                    'external_llm_invoked' => false,
                    'private_data_shared' => false,
                ],
            ], JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];

        $existing = DB::table('scheduled_jobs')
            ->where('name', 'llm_health_reconcile')
            ->first(['id']);

        if ($existing !== null) {
            DB::table('scheduled_jobs')
                ->where('id', $existing->id)
                ->update($values);

            return;
        }

        DB::table('scheduled_jobs')->insert(array_merge(
            ['name' => 'llm_health_reconcile', 'created_at' => $now],
            $values
        ));
    }
};
