<?php

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService as Resources;
use App\Services\UnifiedCli\CliProviderRuntimePolicy;
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
        $definition = json_decode(file_get_contents(base_path('config/local-engine-candidates/bitnet-manual.87.json')), true, flags: JSON_THROW_ON_ERROR);
        $registration = $definition['task_registration'] ?? [];
        if (($registration['schema'] ?? null) !== 'plos.bitnet.dedicated_manual_task.v1'
            || $definition['lifecycle_target']['task_user'] !== 'SYSTEM'
            || $definition['lifecycle_target']['task_name'] === $registration['protected_task_name']
            || ! hash_equals($definition['lifecycle_target']['control_sha256'], hash_file('sha256', base_path($definition['control_source'])))) {
            throw new RuntimeException('Dedicated BitNet definition or launcher identity differs.');
        }
        DB::transaction(function () use ($definition, $registration): void {
            $id = $definition['target'];
            $provider = DB::table('llm_instances')->where('instance_id', $id)->lockForUpdate()->first();
            $profile = DB::table('llm_model_profiles')->where('profile_name', $definition['profile_name'])->lockForUpdate()->first();
            $policyRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            if (! $provider || ! $profile || ! $policyRow || ! $resourceRow) {
                throw new RuntimeException('Dedicated BitNet requires the existing manual registration.');
            }
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            if (($config['manual_task_registration']['schema'] ?? null) === $registration['schema']) {
                return; // Do not replay a later operator decision or re-enable a failed candidate.
            }
            $metadata = json_decode($profile->metadata, true, flags: JSON_THROW_ON_ERROR);
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $capacityId = $resources['bindings']['.87'][$id] ?? null;
            $capacity = $resources['profiles'][$capacityId] ?? [];
            $model = DB::table('ollama_models')->where('instance_id', $provider->id)->where('model_name', $definition['model'])->lockForUpdate()->first();
            $blocked = ($config['manual_text_admission']['qualification'] ?? null) === 'manual_text_blocked_host_task_access_denied';
            $pending = ($config['manual_text_admission']['qualification'] ?? null) === $definition['qualification'];
            $roles = $blocked ? ['privacy_deny_prefilter'] : ['privacy_deny_prefilter', 'offline_manual'];
            if ((! $blocked && ! $pending) || $provider->instance_type !== 'local_llm'
                || (int) $provider->is_active !== 1 || $provider->quarantine_status !== 'none'
                || ($config['default_model'] ?? null) !== $definition['model'] || ! $model || ! $model->is_available
                || $profile->model_name !== $definition['model'] || ($blocked && ((bool) $profile->enabled || $provider->routability !== 'blocked'))
                || ($pending && $provider->routability !== 'allowed')
                || ($config['manual_text_admission']['schema'] ?? null) !== $definition['schema']
                || ($metadata['manual_text_admission']['qualification'] ?? null) !== $config['manual_text_admission']['qualification']
                || ($config['route_policy']['allowed_model_roles'] ?? null) !== $roles
                || ($policy['targets'][$id]['roles'] ?? null) !== $roles
                || ($policy['targets'][$id]['activation_allowed'] ?? null) !== true
                || ($config['controller_catalog']['targets'][$id]['model'] ?? null) !== $definition['model']
                || ($config['controller_catalog']['targets'][$id]['target_id'] ?? null) !== $id
                || isset($config['manual_task_registration'])
                || isset($config['model_host_controller_canary']) || isset($config['model_host_controller_automatic'])
                || ($capacity['qualified'] ?? false) !== true || ($capacity['model_identity'] ?? null) !== $definition['model_identity']
                || ($capacity['runtime_identity'] ?? null) !== ($blocked ? $definition['prior_runtime_identity'] : Resources::runtimeIdentity($definition['lifecycle_target']))
                || ($capacity['settings']['context_tokens'] ?? null) !== $definition['context_tokens']
                || ! isset($capacity['request_contracts']['plos.privacy_deny_prefilter.candidate'])
                || count($capacity['request_contracts']) !== ($blocked ? 1 : 2)) {
                throw new RuntimeException('BitNet dedicated-task predecessor drift requires review.');
            }
            $config['manual_task_registration'] = $registration + [
                'state' => 'pending_host_install_and_manual_qualification',
                'prior_runtime_identity' => $capacity['runtime_identity'],
                'prior_request_contracts' => $capacity['request_contracts'],
                'registered_at' => now()->toIso8601String(),
            ];
            $config['models']['offline_manual'] = $definition['model'];
            $config['controller_transport_role'] = 'offline_manual';
            $config['max_tokens'] = $definition['max_output_tokens'];
            $config['context_window'] = $definition['context_tokens'];
            $config['role_runtime']['offline_manual'] = ['context_handling' => 'provider_reject',
                'context_window' => $definition['context_tokens'], 'max_tokens' => $definition['max_output_tokens'],
                'temperature' => 0, 'absolute_timeout_seconds' => 120, 'idle_timeout_seconds' => 60];
            $config['route_policy'] = [...$config['route_policy'], 'allowed_model_roles' => ['offline_manual'],
                'require_model_role' => true, 'require_explicit_instance' => true, 'require_cached_health' => false,
                'allow_generic_fallback' => false, 'tools_enabled' => false, 'redacted_input_roles' => ['privacy_deny_prefilter']];
            $config['manual_text_admission']['qualification'] = $definition['qualification'];
            $config['controller_catalog']['targets'][$id]['roles'] = ['offline_manual'];
            $policy['targets'][$id]['roles'] = ['offline_manual'];
            $contract = (new CliProviderRuntimePolicy)->resolve([
                'configuration_eligible' => true, 'instance_id' => $id, 'engine' => $definition['engine'],
                'model' => $definition['model'], 'role' => 'offline_manual', 'native_tools_configured' => false,
                'model_definition' => ['capabilities' => ['text', 'classification'], 'context_length' => $definition['context_tokens']],
                'instance' => ['instance_type' => 'local_llm', 'base_url' => $provider->base_url, 'config' => $config],
            ], ['cli.runtime_policy' => json_decode((string) DB::table('system_configs')->where('section', 'cli')->where('config_key', 'runtime_policy')->value('config_value'), true)]);
            $resources['profiles'][$capacityId]['runtime_identity'] = Resources::runtimeIdentity($definition['lifecycle_target']);
            // Old privacy evidence is retained above, but is not admission for this new runtime.
            $resources['profiles'][$capacityId]['request_contracts'] = ['cli.offline_manual.bitnet' => Resources::requestContractIdentity($contract)];
            $resources['revision'] .= '-bitnet-dedicated-task-20260928';
            (new ModelHostControllerPolicyService)->normalize($policy);
            (new Resources)->normalize($resources);
            $metadata['manual_text_admission'] = $config['manual_text_admission'];
            $metadata['manual_task_registration'] = $config['manual_task_registration'];
            $metadata['lifecycle_projection'] = $definition['lifecycle_target'];
            DB::table('llm_instances')->where('id', $provider->id)->update(['config' => $this->json($config),
                'routability' => 'allowed', 'updated_at' => now()]);
            DB::table('llm_model_profiles')->where('id', $profile->id)->update([
                'metadata' => $this->json($metadata), 'enabled' => false, 'updated_at' => now(),
            ]); // Root enables the manual choice only after installing and qualifying the new task.
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
        // Keep evidence. Rollback requires matched host/task and table restoration under clear custody.
    }
};
