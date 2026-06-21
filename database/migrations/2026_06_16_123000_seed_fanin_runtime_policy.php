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
            'fan_out_id_key' => 'fan_out_id',
            'timeout_seconds' => 3600,
            'poll_interval_seconds' => 5,
            'aggregation_mode' => 'array',
            'include_failed' => false,
            'require_all_success' => true,
            'policy_notes' => [
                'fan_out_id_key' => 'Default input key used to locate the fan-out id when node config omits it.',
                'timeout_seconds' => 'Default maximum wait for fan-out branches before returning timeout.',
                'poll_interval_seconds' => 'Default sleep interval between branch-status polls.',
                'aggregation_mode' => 'Default result aggregation mode when node config omits it.',
                'include_failed' => 'Default whether failed branch outputs are included in aggregation.',
                'require_all_success' => 'Default whether any failed branch makes fan-in fail.',
                'node_config_owner' => 'Explicit workflow node config still wins for all matching keys.',
                'fanout_policy_owner' => 'FanOut branch caps and HTTP pool defaults remain owned by workflow.fanout_runtime_policy.',
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
            ->where('config_key', 'fanin_runtime_policy')
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
            ['section' => 'workflow', 'config_key' => 'fanin_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for FanInNode wait, poll, and aggregation defaults.',
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
            ->where('config_key', 'fanin_runtime_policy')
            ->delete();
    }
};
