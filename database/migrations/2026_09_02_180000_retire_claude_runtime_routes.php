<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('llm_instances')) {
            DB::table('llm_instances')
                ->where(function ($query): void {
                    $query->where('instance_id', 'claude_cli')
                        ->orWhereIn('instance_type', ['claude_cli', 'anthropic_api']);
                })
                ->update([
                    'is_active' => 0,
                    'is_healthy' => 0,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')
                ->where('section', 'ai')
                ->whereIn('config_key', ['agent_proxy_policy', 'claude_slot_scaling_policy'])
                ->delete();

            $this->removePolicyKeys('ai', 'router_runtime_policy', ['vision_cli_timeout_seconds']);
            $this->removePolicyKeys('ai', 'service_runtime_policy', [
                'slot_timeout_counter_ttl_seconds',
                'slot_timeout_alert_threshold',
                'slot_timeout_alert_cooldown_seconds',
            ]);
            $this->removePolicyKeys('research', 'topic_runner_runtime_policy', ['claude']);
            $this->removeAuditMarkers();
        }

        foreach ([
            'llm_instances_all',
            'llm_instances_healthy',
            'external_api_providers',
            'ai_claude_slot_scaling_policy',
            'syscfg:ai.agent_proxy_policy',
            'syscfg:ai.router_runtime_policy',
            'syscfg:ai.service_runtime_policy',
            'syscfg:research.topic_runner_runtime_policy',
            'syscfg:ops.runtime_policy_leak_audit',
        ] as $key) {
            Cache::forget($key);
        }
    }

    public function down(): void
    {
        // Security retirement is intentionally not reversed automatically.
        // Restoring an external provider requires a new reviewed migration.
    }

    /** @param list<string> $keys */
    private function removePolicyKeys(string $section, string $configKey, array $keys): void
    {
        $row = DB::table('system_configs')
            ->where('section', $section)
            ->where('config_key', $configKey)
            ->first(['id', 'config_value']);
        if ($row === null) {
            return;
        }

        $policy = json_decode((string) $row->config_value, true);
        if (! is_array($policy)) {
            return;
        }

        foreach ($keys as $key) {
            unset($policy[$key]);
            if (isset($policy['policy_notes']) && is_array($policy['policy_notes'])) {
                unset($policy['policy_notes'][$key]);
            }
        }

        DB::table('system_configs')->where('id', $row->id)->update([
            'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    private function removeAuditMarkers(): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'runtime_policy_leak_audit')
            ->first(['id', 'config_value']);
        if ($row === null) {
            return;
        }

        $policy = json_decode((string) $row->config_value, true);
        if (! is_array($policy) || ! isset($policy['classifier']) || ! is_array($policy['classifier'])) {
            return;
        }

        $markers = [
            'file_table_policy_markers' => 'class AgentProxyPolicyService',
            'line_table_driven_markers' => '$this->agentProxyPolicy',
        ];
        foreach ($markers as $key => $retiredMarker) {
            if (! isset($policy['classifier'][$key]) || ! is_array($policy['classifier'][$key])) {
                continue;
            }
            $policy['classifier'][$key] = array_values(array_filter(
                $policy['classifier'][$key],
                static fn (mixed $marker): bool => $marker !== $retiredMarker
            ));
        }

        DB::table('system_configs')->where('id', $row->id)->update([
            'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }
};
