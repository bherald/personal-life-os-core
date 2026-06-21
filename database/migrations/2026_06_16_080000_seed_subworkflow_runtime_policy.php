<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policy(int $maxDepth): array
    {
        return [
            'max_depth' => $maxDepth,
            'timeout_seconds' => 3600,
            'wait_for_completion_default' => true,
            'circular_reference_check_limit' => 20,
            'policy_notes' => [
                'max_depth' => 'Default SubWorkflowNode nesting limit when workflow node config does not provide a stricter runtime rule.',
                'timeout_seconds' => 'Default exposed SubWorkflowNode timeout value for node config defaults and logging.',
                'wait_for_completion_default' => 'Default wait_for_completion value used when workflow node config does not provide one.',
                'circular_reference_check_limit' => 'Maximum parent-run records checked while detecting circular workflow references.',
                'legacy_compatibility' => 'Seeded from workflow.workflow_max_depth when present; the legacy row is kept for compatibility.',
                'overrides' => 'Workflow node config remains the per-run override for timeout and wait_for_completion.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $existingRow = DB::table('system_configs')
            ->where('section', 'workflow')
            ->where('config_key', 'subworkflow_runtime_policy')
            ->first(['config_value']);

        $existing = $existingRow !== null
            ? json_decode((string) $existingRow->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $legacyRow = DB::table('system_configs')
            ->where('section', 'workflow')
            ->where('config_key', 'workflow_max_depth')
            ->first(['config_value']);

        $legacyDepth = is_numeric($legacyRow?->config_value)
            ? max(1, (int) $legacyRow->config_value)
            : 5;

        $policy = array_replace_recursive($this->policy($legacyDepth), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'workflow', 'config_key' => 'subworkflow_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for SubWorkflowNode nesting, circular-reference guards, and exposed defaults.',
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
            ->where('config_key', 'subworkflow_runtime_policy')
            ->delete();
    }
};
