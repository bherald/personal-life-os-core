<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function classifier(): array
    {
        return [
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
        ];
    }

    /** @return array<string,mixed> */
    private function fallbackPolicy(): array
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
                    'regex' => '/\\b(capacity_model_roles|role_fit|model_roles|gpu_target|host_affinity|max_concurrent)\\b|\\b(modelRole|model_role|model-role)\\b.*\\b(quality|standard|fast)\\b|\\b(quality|standard|fast)\\b.*\\b(modelRole|model_role|model-role)\\b/i',
                    'severity' => 'high',
                    'recommendation' => 'Route model and hardware capacity through llm_instances, model telemetry, and system_configs policy.',
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
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->first(['config_value']);

        $policy = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;

        if (! is_array($policy)) {
            $policy = $this->fallbackPolicy();
        }

        $policy['classifier'] = $this->classifier();
        $policy['classification_policy'] = is_array($policy['classification_policy'] ?? null)
            ? $policy['classification_policy']
            : [];
        $policy['classification_policy']['table_driven_reference'] =
            'Line or file context references table-driven policy markers from the classifier config.';

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'ops', 'config_key' => 'runtime_policy_leak_audit'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Observe-only scanner for hardcoded growth limits and runtime policy leaks.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->first(['config_value']);

        $policy = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;

        if (! is_array($policy)) {
            return;
        }

        unset($policy['classifier']);
        if (is_array($policy['classification_policy'] ?? null)) {
            $policy['classification_policy']['table_driven_reference'] =
                'Line references system_configs, scheduled_jobs, registries, runtime notes, or config values.';
        }

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
