<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_LAUNCH_GATES_NOTE = 'Variance/uplift thresholds and queue-depth gates remain owned by config/agents.php until a separate service-policy slice moves them.';

    private const LAUNCH_GATES_NOTE = 'Queue-depth, benchmark-variance, and low-uplift gates are owned by this system_configs policy.';

    /** @return array<string,mixed> */
    private function launchGatePolicy(): array
    {
        return [
            'max_speculative_queue' => $this->positiveInt(env('SPECULATIVE_QUEUE_DEPTH'), 2),
            'speculative_variance_threshold' => $this->nonNegativeFloat(env('SPECULATIVE_VARIANCE'), 1.0),
            'speculative_min_uplift' => $this->nonNegativeFloat(env('SPECULATIVE_MIN_UPLIFT'), 10.0),
            'policy_notes' => [
                'max_speculative_queue' => 'Maximum ready jobs allowed on the speculative queue before new speculative launches are skipped.',
                'speculative_variance_threshold' => 'Benchmark accuracy standard-deviation threshold that permits speculative launch.',
                'speculative_min_uplift' => 'Minimum average quality uplift percent required to avoid auto-disable cooldown.',
                'launch_gates' => self::LAUNCH_GATES_NOTE,
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

        $defaults = $this->launchGatePolicy();
        $policy = array_replace($defaults, $existing);
        $policy['policy_notes'] = array_replace(
            $defaults['policy_notes'],
            isset($existing['policy_notes']) && is_array($existing['policy_notes'])
                ? $existing['policy_notes']
                : []
        );
        if (($policy['policy_notes']['launch_gates'] ?? null) === self::LEGACY_LAUNCH_GATES_NOTE) {
            $policy['policy_notes']['launch_gates'] = self::LAUNCH_GATES_NOTE;
        }

        $payload = [
            'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
            'data_type' => 'json',
            'description' => 'Runtime policy for speculative execution branch jobs, Redis coordination, and launch gates.',
            'updated_at' => now(),
        ];

        if ($row === null) {
            $payload['created_at'] = now();

            DB::table('system_configs')->insert(array_merge([
                'section' => 'agents',
                'config_key' => 'speculative_execution_runtime_policy',
            ], $payload));

            return;
        }

        DB::table('system_configs')
            ->where('section', 'agents')
            ->where('config_key', 'speculative_execution_runtime_policy')
            ->update($payload);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'agents')
            ->where('config_key', 'speculative_execution_runtime_policy')
            ->first(['config_value']);

        if ($row === null) {
            return;
        }

        $policy = json_decode((string) $row->config_value, true);
        if (! is_array($policy)) {
            return;
        }

        unset(
            $policy['max_speculative_queue'],
            $policy['speculative_variance_threshold'],
            $policy['speculative_min_uplift']
        );

        if (isset($policy['policy_notes']) && is_array($policy['policy_notes'])) {
            unset(
                $policy['policy_notes']['max_speculative_queue'],
                $policy['policy_notes']['speculative_variance_threshold'],
                $policy['policy_notes']['speculative_min_uplift']
            );

            $policy['policy_notes']['launch_gates'] = self::LEGACY_LAUNCH_GATES_NOTE;
        }

        DB::table('system_configs')
            ->where('section', 'agents')
            ->where('config_key', 'speculative_execution_runtime_policy')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }

    private function nonNegativeFloat(mixed $value, float $fallback): float
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $floatValue = (float) $value;

        return $floatValue >= 0.0 ? $floatValue : $fallback;
    }
};
