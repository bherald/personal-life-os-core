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
            'config/local-engine-candidates/colibri-glm-manual.87.json',
        ])) {
            return;
        }
        $definition = json_decode(file_get_contents(base_path('config/local-engine-candidates/colibri-glm-manual.87.json')), true, flags: JSON_THROW_ON_ERROR);
        if (($definition['registration_ready'] ?? null) !== true || ($definition['measured_qualification'] ?? null) !== false
            || ($definition['live_qualification'] ?? null) !== 'pending') {
            throw new RuntimeException('Colibri restoration requires reviewed exact runtime pins; no candidate defaults may be deployed.');
        }
        DB::transaction(function () use ($definition): void {
            $provider = DB::table('llm_instances')->where('instance_id', $definition['instance_id'])->lockForUpdate()->first();
            $alias = DB::table('llm_instances')->where('instance_id', $definition['retired_alias'])->lockForUpdate()->first();
            $profile = DB::table('llm_model_profiles')->where('profile_name', $definition['profile_name'])->lockForUpdate()->first();
            $policyRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            $automaticRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_automatic_selection_policy')->lockForUpdate()->first();
            if (! $provider || ! $alias || ! $profile || ! $policyRow || ! $resourceRow || ! $automaticRow) {
                throw new RuntimeException('Colibri restoration requires the retired canonical rows and controller policies.');
            }
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            if (($config['manual_controller_restore']['schema'] ?? null) === $definition['schema']) {
                return; // Later qualification and operator decisions are never replayed.
            }
            $aliasConfig = json_decode($alias->config, true, flags: JSON_THROW_ON_ERROR);
            $metadata = json_decode($profile->metadata, true, flags: JSON_THROW_ON_ERROR);
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $automatic = json_decode($automaticRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $id = $definition['target'];
            $model = $definition['model'];
            $role = $definition['role'];
            $spec = $definition['lifecycle_target'];
            $managed = $definition['managed_runtime'];
            $contract = $definition['cli_agent_runtime'];
            $modelRow = DB::table('ollama_models')->where('instance_id', $provider->id)->where('model_name', $model)->lockForUpdate()->first();
            if ($definition['instance_id'] !== 'colibri_87' || $id !== 'glm52_87' || $role !== 'offline_manual'
                || (bool) $provider->is_active || $provider->routability !== 'blocked'
                || (bool) $alias->is_active || $alias->routability !== 'blocked' || (bool) $profile->enabled
                || ! $modelRow || (bool) $modelRow->is_available || $profile->model_name !== $model
                || ($config['route_policy']['operator_route_block_phase'] ?? null) !== 'glm52_retirement_20260926'
                || ($policy['targets'][$id]['activation_allowed'] ?? null) !== false
                || ! in_array($policy['targets'][$id]['state'] ?? null, ['storage_absent', 'research_retained'], true)
                || isset($automatic['targets'][$id]) || in_array($id, $automatic['role_targets'] ?? [], true)
                || isset($resources['bindings']['.87'][$id]) || isset($resources['profiles'][$definition['resource_profile']])
                || isset($config['controller_catalog']) || ($spec['adapter_type'] ?? null) !== 'colibri_gateway'
                || ($spec['native_tools_enabled'] ?? null) !== false
                || ($managed['native_tools_enabled'] ?? null) !== false
                || ($managed['model_path'] ?? null) !== ($spec['model_path'] ?? null)
                || ($managed['model_id'] ?? null) !== $model
                || ($managed['context_tokens'] ?? null) !== ($contract['context_window'] ?? null)
                || ($managed['max_output_tokens'] ?? null) !== ($contract['max_output_tokens'] ?? null)
                || ($managed['runtime_receipt_sha256'] ?? null) !== ($spec['runtime_receipt_sha256'] ?? null)
                || ! is_string($definition['managed_runtime_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $definition['managed_runtime_sha256']) !== 1
                || ! str_contains($spec['task_arguments'], $definition['managed_runtime_sha256'])
                || ($spec['model_api_id'] ?? null) !== $model || ($contract['model'] ?? null) !== $model
                || ($contract['controller_target'] ?? null) !== $id
                || ($contract['gateway_sha256'] ?? null) !== ($spec['gateway_sha256'] ?? null)
                || ($contract['engine_sha256'] ?? null) !== ($spec['executable_sha256'] ?? null)) {
                throw new RuntimeException('Retired Colibri predecessor or exact new runtime binding differs.');
            }
            $archive = ['schema' => $definition['schema'], 'qualification' => 'pending', 'measured_qualification' => false,
                'prior_route_policy' => $config['route_policy'], 'prior_cli_agent_runtime' => $config['cli_agent_runtime'] ?? null,
                'prior_runtime_release' => $config['runtime_release'] ?? null, 'prior_model_release' => $config['model_release'] ?? null,
                'prior_alias_controller_catalog' => $aliasConfig['controller_catalog'] ?? null,
                'engine_ram_bytes' => $definition['engine_ram_bytes'], 'managed_runtime' => $managed,
                'managed_runtime_sha256' => $definition['managed_runtime_sha256']];
            $config['manual_controller_restore'] = $archive;
            $config['models'] = [$role => $model];
            $config['max_tokens'] = $contract['max_output_tokens'];
            $config['context_window'] = $contract['context_window'];
            $config['temperature'] = $contract['temperature'];
            $config['enable_thinking'] = $contract['enable_thinking'];
            $config['runtime_release'] = $definition['runtime_release'];
            $config['model_release'] = [...($config['model_release'] ?? []), 'primary_root' => $spec['model_path'],
                'unified_root' => $spec['model_path'], 'secondary_root' => null];
            $config['controller_transport_role'] = $role;
            $config['route_policy'] = [...$config['route_policy'], 'mode' => 'operator_selected_manual_text',
                'allowed_model_roles' => [$role], 'require_model_role' => true, 'require_explicit_instance' => true,
                'normal_chat_default' => false, 'allow_generic_fallback' => false, 'tools_enabled' => false,
                'require_cached_health' => false, 'max_prompt_chars' => $definition['max_prompt_chars'], 'operator_route_block' => false];
            unset($config['route_policy']['operator_route_block_phase']);
            $config['cli_agent_runtime'] = $contract;
            $config['role_runtime'][$role] = ['context_window' => $contract['context_window'], 'max_tokens' => $contract['max_output_tokens'],
                'enable_thinking' => $contract['enable_thinking'], 'temperature' => $contract['temperature'],
                'absolute_timeout_seconds' => $contract['absolute_timeout_seconds'], 'idle_timeout_seconds' => $contract['idle_timeout_seconds'],
                'native_tools_enabled' => false];
            $target = ['target_id' => $id, 'provider_instance_id' => $provider->instance_id, 'controller_host_id' => '.87',
                'model' => $model, 'engine' => $definition['engine'], 'roles' => [$role], 'port' => $spec['port'],
                'policy_state' => 'staged', 'activation_allowed' => true, 'runtime' => []];
            $config['controller_catalog'] = ['schema' => 'plos.model_host_controller.catalog_binding.v1', 'targets' => [$id => $target]];
            // A blocked duplicate still makes origin resolution ambiguous. Retain its evidence, not a second live binding.
            $aliasConfig['retired_controller_catalog'] = $aliasConfig['controller_catalog'] ?? null;
            unset($aliasConfig['controller_catalog']);
            $policy['targets'][$id] = [...$policy['targets'][$id], 'provider_instance_id' => $provider->instance_id,
                'model' => $model, 'engine' => $definition['engine'], 'state' => 'staged', 'activation_allowed' => true,
                'requires_operator_override' => false, 'roles' => [$role], 'switch_budget_exempt' => false,
                'startup_timeout_seconds' => $spec['startup_timeout_seconds'],
                'lifecycle_adapter' => array_intersect_key($spec, array_flip(['adapter_type', 'control_roots', 'required_process_arguments', 'resource_mode', 'native_tools_enabled']))];
            $validated = CliAgentRuntime::validateContract(['instance' => ['config' => $config], 'engine' => $definition['engine'],
                'model' => $model, 'role' => $role, 'native_tools_configured' => false]);
            $resources['profiles'][$definition['resource_profile']] = ['qualified' => true, 'host_id' => '.87', 'target' => $id,
                'model_identity' => $spec['model_identity'], 'runtime_identity' => Resources::runtimeIdentity($spec),
                'settings' => ['context_tokens' => $contract['context_window'], 'parallel_requests' => 1, 'mtp_enabled' => false],
                'requirements' => $definition['requirements'],
                'request_contracts' => ['cli.offline_manual.colibri' => Resources::requestContractIdentity($validated)]];
            $resources['bindings']['.87'][$id] = $definition['resource_profile'];
            $resources['revision'] .= '-colibri-manual-20260928';
            $resources = (new Resources)->normalize($resources);
            (new ModelHostControllerPolicyService)->normalize($policy);
            $metadata['manual_controller_restore'] = $archive;
            $metadata['lifecycle_projection'] = $spec;
            DB::table('llm_instances')->where('id', $provider->id)->update(['config' => $this->json($config), 'updated_at' => now()]);
            DB::table('llm_instances')->where('id', $alias->id)->update(['config' => $this->json($aliasConfig), 'updated_at' => now()]);
            DB::table('ollama_models')->where('id', $modelRow->id)->update(['context_length' => $contract['context_window'],
                'capabilities' => $this->json(['text_input', 'text_output', 'streaming', 'local_private']), 'updated_at' => now()]);
            DB::table('llm_model_profiles')->where('id', $profile->id)->update(['metadata' => $this->json($metadata), 'updated_at' => now()]);
            // Provider/model/profile stay blocked until root installs and qualifies this exact runtime.
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
        // Exact host, table and custody reconciliation is required; never restore retired routes implicitly.
    }
};
