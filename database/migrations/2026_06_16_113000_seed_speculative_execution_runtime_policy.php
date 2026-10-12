<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'branch_timeout_seconds' => 1200,
            'branch_tries' => 1,
            'coordination_ttl_seconds' => 1800,
            'arbitration_lock_seconds' => 300,
            'request_flag_ttl_seconds' => 3600,
            'auto_disable_ttl_seconds' => 86400,
            'max_speculative_queue' => 2,
            'speculative_variance_threshold' => 1.0,
            'speculative_min_uplift' => 10.0,
            'policy_notes' => [
                'branch_timeout_seconds' => 'Horizon timeout for one speculative branch job.',
                'branch_tries' => 'Retry attempts for one speculative branch job; speculative work remains best-effort by default.',
                'coordination_ttl_seconds' => 'Redis status/result coordination TTL shared by SpeculativeExecutionService and SpeculativeBranchJob.',
                'arbitration_lock_seconds' => 'Cache lock window used when the last branch triggers arbitration.',
                'request_flag_ttl_seconds' => 'TTL for an agent request to run the next task speculatively.',
                'auto_disable_ttl_seconds' => 'Cooldown when speculative execution is auto-disabled for low observed uplift.',
                'max_speculative_queue' => 'Maximum ready jobs allowed on the speculative queue before new speculative launches are skipped.',
                'speculative_variance_threshold' => 'Benchmark accuracy standard-deviation threshold that permits speculative launch.',
                'speculative_min_uplift' => 'Minimum average quality uplift percent required to avoid auto-disable cooldown.',
                'queue_owner' => 'Branch queue placement remains explicit in the job constructor as speculative.',
                'launch_gates' => 'Queue-depth, benchmark-variance, and low-uplift gates are owned by this system_configs policy.',
                'horizon_owner' => 'Speculative supervisor process count and queue timeout remain owned by Horizon configuration.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'agents')
            ->where('config_key', 'speculative_execution_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);
        if (isset($existing['policy_notes']) && is_array($existing['policy_notes'])) {
            $policy['policy_notes'] = array_replace(
                $defaultPolicy['policy_notes'],
                $existing['policy_notes']
            );
        } else {
            $policy['policy_notes'] = $defaultPolicy['policy_notes'];
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'agents', 'config_key' => 'speculative_execution_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for speculative execution branch jobs and Redis coordination.',
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

        DB::table('system_configs')
            ->where('section', 'agents')
            ->where('config_key', 'speculative_execution_runtime_policy')
            ->delete();
    }
};
