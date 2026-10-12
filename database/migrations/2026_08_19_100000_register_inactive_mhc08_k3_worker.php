<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'model_host_controller_mhc08_k3_worker';

    private const COMMAND = 'model-host-controller:mhc08-k3-work';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $values = $this->values();
        $existing = DB::table('scheduled_jobs')->where('name', self::JOB_NAME)->first();
        if ($existing !== null) {
            if (! $this->matches($existing, $values)) {
                throw new RuntimeException('MHC-08 K3 worker already exists with different authority.');
            }

            return;
        }

        $now = now();
        DB::table('scheduled_jobs')->insert([
            'name' => self::JOB_NAME,
            ...$values,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $existing = DB::table('scheduled_jobs')->where('name', self::JOB_NAME)->first();
        if ($existing !== null && $this->matches($existing, $this->values())) {
            DB::table('scheduled_jobs')->where('id', $existing->id)->delete();
        }
    }

    /** @return array<string,mixed> */
    private function values(): array
    {
        return [
            'description' => 'Inactive explicit-only MHC-08 Kimi K3 worker; promotion requires completed live gates.',
            'job_type' => 'command',
            'command' => self::COMMAND,
            'cron_expression' => '* * * * *',
            'enabled' => 0,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 420,
            'timeout_locked' => 1,
            'max_parallel' => 1,
            'category' => 'AI',
            'source_module' => 'ModelHostController',
            'runtime_mode' => 'queue_research',
            'workload_family' => 'llm',
            'resource_profile' => 'network',
            'stall_policy' => 'strict',
            'backlog_metric' => 'distributed_tasks_mhc08_k3_pending',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'summary' => 'Claims at most one explicitly submitted K3 ultra-deep or archival task and bypasses generic routing.',
                'policy_source' => 'system_configs.ai.model_host_controller_mhc08_k3_policy',
                'runtime' => [
                    'task_type' => 'mhc08_k3_explicit_v1',
                    'max_tokens' => 128,
                    'max_retries' => 0,
                    'lease_ttl_seconds' => 25200,
                    'execution_mode' => 'serial',
                ],
                'safety' => [
                    'explicit_only' => true,
                    'normal_routing' => false,
                    'tools' => false,
                    'fallback' => false,
                    'disabled_until_promotion' => true,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
    }

    /** @param array<string,mixed> $values */
    private function matches(object $row, array $values): bool
    {
        foreach ($values as $key => $value) {
            $actual = $row->{$key} ?? null;
            if (is_int($value)) {
                if ((int) $actual !== $value) {
                    return false;
                }
            } elseif ((string) $actual !== $value) {
                return false;
            }
        }

        return true;
    }
};
