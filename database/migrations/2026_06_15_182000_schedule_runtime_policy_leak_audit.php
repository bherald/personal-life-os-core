<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function auditPolicy(): array
    {
        return [
            'enabled' => true,
            'max_findings' => 200,
            'include_paths' => ['app', 'config', 'database', 'routes'],
            'exclude_paths' => ['database/schema'],
            'extensions' => ['php'],
            'patterns' => [
                [
                    'name' => 'worker_or_concurrency_cap',
                    'regex' => '/\\b(maxWorkers|max_workers|max_parallel|maxParallel|concurrency|concurrent|slots_available|hard_cap|worker_cap)\\b/i',
                    'severity' => 'high',
                    'recommendation' => 'Keep worker and concurrency policy in scheduled_jobs, scheduled_jobs.notes.runtime, system_configs, or telemetry-derived policy.',
                ],
                [
                    'name' => 'queue_or_backlog_threshold',
                    'regex' => '/\\b(queueLow|queue_low|queue-low|backlog|threshold|soft_cap|min_backlog|pending_min)\\b/i',
                    'severity' => 'medium',
                    'recommendation' => 'Keep queue pressure thresholds table-driven and visible in manager/supervisor output.',
                ],
                [
                    'name' => 'batch_or_limit_policy',
                    'regex' => '/\\b(batchSize|batch_size|max_limit|default_limit|limit\\s*=|--limit|max_findings|max_packets_per_run|max_pending_total)\\b/i',
                    'severity' => 'medium',
                    'recommendation' => 'Classify fixed batch limits as safety rails, migration seeds, or runtime policy; runtime policy belongs in tables.',
                ],
                [
                    'name' => 'timeout_retry_or_stale_window',
                    'regex' => '/\\b(timeout_minutes|timeout|retry|cooldown|stale|stale_minutes|min_minutes_between_runs|expires_days)\\b/i',
                    'severity' => 'medium',
                    'recommendation' => 'Expose timeout, retry, stale, and cooldown windows through scheduled_jobs or system_configs when they affect production throughput.',
                ],
                [
                    'name' => 'model_or_capacity_role_policy',
                    'regex' => '/\\b(capacity_model_roles|role_fit|model_roles|quality|standard|fast|gpu_target|host_affinity|max_concurrent)\\b/i',
                    'severity' => 'high',
                    'recommendation' => 'Route model and hardware capacity through llm_instances, model telemetry, and system_configs policy.',
                ],
            ],
            'classifier' => [
                'line_table_driven_markers' => [
                    'system_configs',
                    'scheduled_jobs',
                    'review_type_registry',
                    'llm_instances',
                    'runtime.',
                    'notes.',
                    'config_value',
                    'runtimePolicyInt',
                    'runtimePolicyValue',
                    'SystemConfigService',
                ],
                'file_table_policy_markers' => [
                    'RUNTIME_POLICY_CONFIG_KEY',
                    'function runtimePolicyInt',
                    'function runtimePolicyValue',
                ],
            ],
            'classification_policy' => [
                'runtime_policy_candidate' => 'App/runtime code match without nearby table-policy reference.',
                'table_driven_reference' => 'Line or file context references table-driven policy markers from the classifier config.',
                'config_default' => 'Config file default; verify it is overrideable and not a hidden production cap.',
                'migration_seed' => 'Migration seed; acceptable only when it creates or updates table-owned policy.',
                'test_fixture' => 'Test fixture or assertion.',
            ],
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')->updateOrInsert(
                ['section' => 'ops', 'config_key' => 'runtime_policy_leak_audit'],
                [
                    'config_value' => json_encode($this->auditPolicy(), JSON_UNESCAPED_SLASHES),
                    'data_type' => 'json',
                    'description' => 'Observe-only scanner for hardcoded growth limits and runtime policy leaks.',
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
                ->where('name', 'runtime_policy_leak_audit')
                ->delete();
        }

        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')
                ->where('section', 'ops')
                ->where('config_key', 'runtime_policy_leak_audit')
                ->delete();
        }
    }

    private function upsertScheduledJob(): void
    {
        $now = now();
        $values = [
            'description' => 'Observe-only audit for hardcoded runtime policy that can limit PLOS growth.',
            'job_type' => 'command',
            'command' => 'ops:runtime-policy-leak-audit --json --compact',
            'cron_expression' => '17 3 * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 20,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => 'Ops',
            'runtime_mode' => 'observe',
            'workload_family' => 'ops',
            'resource_profile' => 'db',
            'stall_policy' => 'strict',
            'backlog_metric' => 'runtime_policy_leaks',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'summary' => 'Finds hardcoded growth limits so runtime policy can move to system_configs, scheduled_jobs, registries, or telemetry.',
                'policy_source' => 'system_configs.ops.runtime_policy_leak_audit',
                'safety' => [
                    'read_only' => true,
                    'mutation_allowed' => false,
                ],
            ], JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];

        $existing = DB::table('scheduled_jobs')
            ->where('name', 'runtime_policy_leak_audit')
            ->first(['id']);

        if ($existing !== null) {
            DB::table('scheduled_jobs')
                ->where('id', $existing->id)
                ->update($values);

            return;
        }

        DB::table('scheduled_jobs')->insert(array_merge(
            ['name' => 'runtime_policy_leak_audit', 'created_at' => $now],
            $values
        ));
    }
};
