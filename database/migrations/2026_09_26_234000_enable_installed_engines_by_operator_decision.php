<?php

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService;
use App\Services\UnifiedCli\CliProviderRuntimePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $policyRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_resource_policy')->lockForUpdate()->first();
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $definitions = json_decode(file_get_contents(base_path('config/local-engine-candidates/installed-candidates.87.json')), true, flags: JSON_THROW_ON_ERROR);
            $changed = false;
            foreach ($definitions['candidates'] as $definition) {
                $id = $definition['target'];
                $instance = DB::table('llm_instances')->where('instance_id', $id)->lockForUpdate()->first();
                $profile = DB::table('llm_model_profiles')->where('profile_name', $definition['profile_name'])->lockForUpdate()->first();
                if (! $instance || ! $profile || ! isset($policy['targets'][$id], $resources['bindings']['.87'][$id])) {
                    throw new RuntimeException('Operator engine activation requires complete prior registration.');
                }
                $metadata = json_decode($profile->metadata, true, flags: JSON_THROW_ON_ERROR);
                if (isset($metadata['operator_activation_20260926'])) {
                    continue; // Later operator changes are never replayed away.
                }
                $registration = $metadata['controller_registration'];
                $capacityId = $resources['bindings']['.87'][$id];
                $capacity = $resources['profiles'][$capacityId];
                $config = json_decode($instance->config, true, flags: JSON_THROW_ON_ERROR);
                if ($registration['target'] !== $id || $registration['model'] !== $definition['model']
                    || $capacity['target'] !== $id
                    || ModelHostControllerResourcePolicyService::requestContractIdentity($capacity['requirements'])
                        !== ModelHostControllerResourcePolicyService::requestContractIdentity($registration['capacity_candidate'])
                    || ($config['models'][$definition['role']] ?? null) !== $definition['model']
                    || $config['controller_catalog']['targets'][$id]['model'] !== $definition['model']) {
                    throw new RuntimeException('Operator activation refuses a drifted candidate identity or resource floor.');
                }
                $metadata['operator_activation_20260926'] = [
                    'schema' => 'plos.operator_engine_activation.v1', 'basis' => 'operator_approved_existing_history',
                    'operator_instruction' => 'no qualification run, just get it online, plos is built to be fault tolerant',
                    'fresh_qualification' => false, 'new_inference_claim' => false, 'resource_floors_changed' => false,
                    'scope' => 'Exact existing local model, explicit offline_manual selection, no model tools or fallback.',
                    'prior_profile_model_name' => $profile->model_name, 'served_model_alias' => $definition['model'],
                    'resource_qualified_flag_is_admission_decision' => true, 'recorded_at' => now()->toIso8601String(),
                ];
                $metadata['controller_registration']['enabled'] = true;
                // Task startup and its required boot trigger remain disabled; normal controller activation owns real work.
                $policy['targets'][$id]['activation_allowed'] = true;
                $policy['targets'][$id]['requires_operator_override'] = false;
                $config['controller_catalog']['targets'][$id]['activation_allowed'] = true;
                $config['route_policy']['require_cached_health'] = false;
                $resources['profiles'][$capacityId]['qualified'] = true;
                $defaults = json_decode((string) DB::table('system_configs')->where('section', 'cli')->where('config_key', 'runtime_policy')->value('config_value'), true);
                $contract = (new CliProviderRuntimePolicy)->resolve([
                    'configuration_eligible' => true, 'instance_id' => $id, 'engine' => $definition['engine'],
                    'model' => $definition['model'], 'role' => $definition['role'], 'native_tools_configured' => false,
                    'model_definition' => ['capabilities' => ['text', 'streaming']],
                    'instance' => ['instance_type' => 'local_llm', 'base_url' => $instance->base_url, 'config' => $config],
                ], ['cli.runtime_policy' => $defaults]);
                $resources['profiles'][$capacityId]['request_contracts'] = [
                    'cli.offline_manual.installed' => ModelHostControllerResourcePolicyService::requestContractIdentity($contract),
                ];
                DB::table('llm_instances')->where('id', $instance->id)->update(['is_active' => 1, 'routability' => 'allowed',
                    'config' => $this->json($config), 'notes' => 'Operator enabled from existing history; no fresh qualification run. Normal controller resource/custody/artifact gates remain.', 'updated_at' => now()]);
                DB::table('ollama_models')->where('instance_id', $instance->id)->where('model_name', $definition['model'])->update(['is_available' => 1, 'updated_at' => now()]);
                DB::table('llm_model_profiles')->where('id', $profile->id)->update(['enabled' => 1, 'status' => 'active',
                    'requires_override' => 0, 'model_name' => $definition['model'], 'metadata' => $this->json($metadata), 'updated_at' => now()]);
                $changed = true;
            }
            $native = DB::table('llm_model_profiles')->where('profile_name', 'hybrid_native_qwen3_coder_30b')->lockForUpdate()->first();
            if (! $native || $native->model_name !== 'qwen3-coder:30b') {
                throw new RuntimeException('Native manual profile identity requires review.');
            }
            $nativeMetadata = json_decode($native->metadata, true, flags: JSON_THROW_ON_ERROR);
            if (! isset($nativeMetadata['operator_profile_enablement_20260926'])) {
                $nativeMetadata['operator_profile_enablement_20260926'] = [
                    'basis' => 'operator_approved_existing_manual_route', 'fresh_qualification' => false,
                    'new_inference_claim' => false, 'model' => 'qwen3-coder:30b', 'recorded_at' => now()->toIso8601String(),
                ];
                DB::table('llm_model_profiles')->where('id', $native->id)->update(['enabled' => 1, 'status' => 'active',
                    'requires_override' => 0, 'metadata' => $this->json($nativeMetadata), 'updated_at' => now()]);
                $changed = true;
            }
            if (! $changed) {
                return;
            }
            (new ModelHostControllerPolicyService)->normalize($policy);
            (new ModelHostControllerResourcePolicyService)->normalize($resources);
            DB::table('system_configs')->where('id', $policyRow->id)->update(['config_value' => $this->json($policy), 'updated_at' => now()]);
            DB::table('system_configs')->where('id', $resourceRow->id)->update(['config_value' => $this->json($resources), 'updated_at' => now()]);
        });
        foreach (['llm_instances', 'llm_instances_all', 'llm_instances_healthy', 'ollama_models', 'llm_model_profiles'] as $key) {
            Cache::forget($key);
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function down(): void
    {
        // Do not revoke or replay an explicit operator decision or erase historical evidence.
    }
};
