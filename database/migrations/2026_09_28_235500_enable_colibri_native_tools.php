<?php

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService as Resources;
use App\Services\UnifiedCli\CliAgentRuntime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }
        if (! (new ModelHostControllerPolicyService)->operatorPacketsApplicable([
            'config/local-engine-candidates/colibri-glm-tools.87.json',
        ])) {
            return;
        }
        $d = json_decode(file_get_contents(base_path('config/local-engine-candidates/colibri-glm-tools.87.json')), true, flags: JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($d): void {
            $provider = DB::table('llm_instances')->where('instance_id', $d['instance_id'])->lockForUpdate()->first();
            $profile = DB::table('llm_model_profiles')->where('profile_name', $d['profile_name'])->lockForUpdate()->first();
            $policyRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            if (! $provider || ! $profile || ! $policyRow || ! $resourceRow) {
                throw new RuntimeException('Registered Colibri predecessor is missing.');
            }
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            if (($config['native_tool_registration']['schema'] ?? null) === $d['schema']) {
                return;
            }
            $metadata = json_decode($profile->metadata, true, flags: JSON_THROW_ON_ERROR);
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $model = DB::table('ollama_models')->where('instance_id', $provider->id)->where('model_name', $d['model'])->lockForUpdate()->first();
            $old = $metadata['lifecycle_projection'] ?? [];
            $oldBinding = $resources['bindings']['.87'][$d['target']] ?? '';
            $capacity = $resources['profiles'][$oldBinding] ?? [];
            $contract = $d['cli_agent_runtime'];
            $managed = $d['managed_runtime'];
            $spec = $d['lifecycle_target'];
            $same = fn (array $a, array $b): bool => Resources::requestContractIdentity($a) === Resources::requestContractIdentity($b);
            if ($d['schema'] !== 'plos.colibri.native_tools.20260928.v1'
                || ! $model || ! (bool) $provider->is_active || $provider->routability !== 'allowed'
                || ! (bool) $model->is_available || ! (bool) $profile->enabled
                || ($config['manual_controller_restore']['qualification'] ?? null) !== 'bounded_manual_text_passed'
                || ($config['manual_controller_restore']['managed_runtime_sha256'] ?? null) !== $d['prior_managed_runtime_sha256']
                || ! $same($old, $d['prior_lifecycle_target'])
                || ($capacity['runtime_identity'] ?? null) !== Resources::runtimeIdentity($old)
                || ($capacity['settings']['mtp_enabled'] ?? null) !== false
                || ($managed['environment']['MTP'] ?? null) !== '0'
                || ($managed['native_tools_enabled'] ?? null) !== true || ($spec['native_tools_enabled'] ?? null) !== true
                || ($contract['native_tools_enabled'] ?? null) !== true || array_key_exists('manual_text_only', $contract)
                || $managed['context_tokens'] !== $contract['context_window'] || $managed['max_output_tokens'] !== $contract['max_output_tokens']
                || $managed['ram_gib'] !== $config['manual_controller_restore']['managed_runtime']['ram_gib']
                || $spec['model_identity'] !== $old['model_identity'] || $spec['gateway_sha256'] !== $old['gateway_sha256']
                || $spec['executable_sha256'] !== $old['executable_sha256']
                || hash('sha256', $d['managed_runtime_document']) !== $d['managed_runtime_sha256']
                || ! $same(json_decode($d['managed_runtime_document'], true, flags: JSON_THROW_ON_ERROR), $managed)
                || ! str_contains($spec['task_arguments'], $d['managed_runtime_sha256'])
                || hash_file('sha256', base_path('scripts/plos-colibri-managed-run.ps1')) !== $spec['control_sha256']) {
                throw new RuntimeException('Colibri native-tool predecessor or exact runtime binding changed.');
            }
            $role = $d['role'];
            $registration = ['schema' => $d['schema'], 'state' => 'awaiting_matched_host_install',
                'operator_authorized_tools' => true, 'fresh_tool_test' => 'pending',
                'prior_route_policy' => $config['route_policy'], 'prior_cli_agent_runtime' => $config['cli_agent_runtime'],
                'prior_lifecycle_projection' => $old, 'prior_resource_binding' => $oldBinding,
                'prior_profile_status' => $profile->status, 'prior_profile_notes' => $profile->notes,
                'managed_runtime' => $managed, 'managed_runtime_sha256' => $d['managed_runtime_sha256']];
            $config['native_tool_registration'] = $registration;
            $config['models'] = [$role => $d['model']];
            $config['controller_transport_role'] = $role;
            $config['cli_agent_runtime'] = $contract;
            $config['tokenizer_bindings_path'] = $d['tokenizer_bindings_path'];
            $config['context_window'] = $contract['context_window'];
            $config['max_tokens'] = $contract['max_output_tokens'];
            $config['route_policy'] = [...$config['route_policy'], 'mode' => 'controller_managed_native_tools',
                'allowed_model_roles' => [$role], 'require_model_role' => true, 'require_explicit_instance' => false,
                'normal_chat_default' => false, 'allow_generic_fallback' => false, 'tools_enabled' => true,
                'max_prompt_chars' => $d['max_prompt_chars'], 'operator_route_block' => false];
            unset($config['role_runtime']['offline_manual']);
            $config['role_runtime'][$role] = ['context_window' => $contract['context_window'], 'max_tokens' => $contract['max_output_tokens'],
                'enable_thinking' => $contract['enable_thinking'], 'temperature' => $contract['temperature'],
                'absolute_timeout_seconds' => $contract['absolute_timeout_seconds'], 'idle_timeout_seconds' => $contract['idle_timeout_seconds'],
                'native_tools_enabled' => true];
            $target = &$config['controller_catalog']['targets'][$d['target']];
            $target['roles'] = [$role];
            $target['policy_state'] = 'production';
            unset($target);
            $policy['targets'][$d['target']]['roles'] = [$role];
            $policy['targets'][$d['target']]['state'] = 'production';
            $policy['targets'][$d['target']]['lifecycle_adapter']['native_tools_enabled'] = true;
            $validated = CliAgentRuntime::validateContract(['instance' => ['config' => $config], 'engine' => $d['engine'],
                'model' => $d['model'], 'role' => $role, 'native_tools_configured' => true]);
            $capacity['runtime_identity'] = Resources::runtimeIdentity($spec);
            $capacity['settings']['context_tokens'] = $contract['context_window'];
            $capacity['request_contracts'] = ['cli.deep_offline.colibri' => Resources::requestContractIdentity($validated)];
            $resources['profiles'][$d['resource_profile']] = $capacity;
            $resources['bindings']['.87'][$d['target']] = $d['resource_profile'];
            $resources['revision'] .= '-colibri-native-tools';
            $resources = (new Resources)->normalize($resources);
            (new ModelHostControllerPolicyService)->normalize($policy);
            $metadata['native_tool_registration'] = $registration;
            $metadata['lifecycle_projection'] = $spec;
            $caps = array_values(array_unique([...json_decode($model->capabilities, true, flags: JSON_THROW_ON_ERROR), 'tools', 'tool_calling']));
            // Block the brief table/host transition; root reopens only after exact installed identity is verified.
            DB::table('llm_instances')->where('id', $provider->id)->update(['config' => $this->json($config), 'is_active' => false, 'routability' => 'blocked', 'updated_at' => now()]);
            DB::table('ollama_models')->where('id', $model->id)->update(['capabilities' => $this->json($caps), 'context_length' => $contract['context_window'], 'is_available' => false, 'updated_at' => now()]);
            DB::table('llm_model_profiles')->where('id', $profile->id)->update(['metadata' => $this->json($metadata), 'enabled' => false,
                'status' => 'active', 'capabilities' => $this->json($caps),
                'notes' => 'Controller-managed GLM with native tools/MCP under existing tool permissions and approvals. Normal deep_offline role; existing defaults preserved. Prior manual test and operator decisions retained in metadata.', 'updated_at' => now()]);
            foreach ([[$policyRow, $policy], [$resourceRow, $resources]] as [$row, $value]) {
                DB::table('system_configs')->where('id', $row->id)->update(['config_value' => $this->json($value), 'updated_at' => now()]);
            }
        });
        foreach (['llm_instances', 'llm_instances_all', 'llm_instances_healthy', 'external_api_providers', 'ollama_models', 'llm_model_profiles'] as $key) {
            Cache::forget($key);
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function down(): void
    {
        // Restore only matched host/table preimages; preserve controller custody and evidence.
    }
};
