<?php

use App\Services\Ops\AgentToolPolicyRefreshService;
use App\Services\SystemConfigService;
use App\Services\TypeSafeJevDecisionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $tool = DB::table('agent_tool_registry')->where('name', 'typesafe_jev_decide')->lockForUpdate()->first();
            if ($tool === null || $tool->service_class !== TypeSafeJevDecisionService::class || $tool->method !== 'evaluate' ||
                trim((string) ($tool->mcp_server ?? '')) !== '' || trim((string) ($tool->mcp_tool ?? '')) !== '') {
                throw new RuntimeException('Expected registered Jev service binding is required.');
            }
            $row = DB::table('system_configs')->where('section', 'ops')->where('config_key', 'agent_tool_policy_overrides')->lockForUpdate()->first();
            $policy = $row === null ? ['schema' => 'plos.agent_tool_policy_overrides.v1', 'tools' => []] : json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (($policy['schema'] ?? null) !== 'plos.agent_tool_policy_overrides.v1' || ! is_array($policy['tools'] ?? null)) {
                throw new RuntimeException('Existing tool privacy override policy is invalid.');
            }
            // One-time registration: preserve subsequent operator changes and unrelated tools.
            if (array_key_exists('typesafe_jev_decide', $policy['tools'])) {
                return;
            }
            $policy['tools']['typesafe_jev_decide'] = [
                'service_class' => TypeSafeJevDecisionService::class,
                'method' => 'evaluate',
                'privacy_class' => 'online_registered_task_input',
            ];
            $values = [
                'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'updated_at' => now(),
            ];
            if ($row === null) {
                DB::table('system_configs')->insert($values + [
                    'section' => 'ops',
                    'config_key' => 'agent_tool_policy_overrides',
                    'description' => 'Exact service-bound tool privacy classifications preserved by policy refresh; existing private-data permission is retained.',
                    'created_at' => now(),
                ]);
            } else {
                DB::table('system_configs')->where('id', $row->id)->update($values);
            }
            DB::table('agent_tool_registry')->where('id', $tool->id)->update([
                'privacy_class' => 'online_registered_task_input',
                'schema_generation' => 2,
                'max_result_bytes' => 12000,
                'updated_at' => now(),
            ]);
        });
        app(SystemConfigService::class)->forget(AgentToolPolicyRefreshService::OVERRIDES_KEY);
    }

    public function down(): void
    {
        // Preserve operator-owned registrations; revoke/edit the scoped table row explicitly.
    }
};
