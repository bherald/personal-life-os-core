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
            'degraded_success_rate_threshold' => 80.0,
            'failing_success_rate_threshold' => 50.0,
            'critical_success_rate_threshold' => 25.0,
            'consecutive_failure_recommendation_threshold' => 5,
            'policy_notes' => [
                'degraded_success_rate_threshold' => 'Minimum success-rate percent considered healthy.',
                'failing_success_rate_threshold' => 'Minimum success-rate percent considered degraded rather than failing.',
                'critical_success_rate_threshold' => 'Minimum success-rate percent considered failing rather than critical.',
                'consecutive_failure_recommendation_threshold' => 'Consecutive failed runs required before a critical recommendation is emitted.',
                'ownership' => 'WorkflowDiagnosticsService health thresholds are table-owned here; query periods and SQL result limits remain explicit service inputs.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'workflow')
            ->where('config_key', 'diagnostics_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);
        $policy['policy_notes'] = array_replace(
            $defaultPolicy['policy_notes'],
            isset($existing['policy_notes']) && is_array($existing['policy_notes'])
                ? $existing['policy_notes']
                : []
        );

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'workflow', 'config_key' => 'diagnostics_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for WorkflowDiagnosticsService health thresholds and critical recommendation gating.',
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
            ->where('section', 'workflow')
            ->where('config_key', 'diagnostics_runtime_policy')
            ->delete();
    }
};
