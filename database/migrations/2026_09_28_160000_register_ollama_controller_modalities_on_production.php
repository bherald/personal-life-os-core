<?php

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService as Resources;
use App\Services\UnifiedCli\CliOllamaRuntimePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PROD is authoritative; the operator explicitly owns DEV configuration sync.
        if (! app()->environment('production')) {
            return;
        }
        $definition = json_decode(file_get_contents(base_path('config/local-engine-candidates/ollama-modalities.87.json')), true, flags: JSON_THROW_ON_ERROR);
        if (($definition['schema'] ?? null) !== 'plos.ollama_controller_modalities.v1') {
            throw new RuntimeException('Invalid Ollama modality registration.');
        }
        DB::transaction(function () use ($definition): void {
            $provider = DB::table('llm_instances')->where('instance_id', $definition['provider_instance_id'])->lockForUpdate()->first();
            $policyRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            if (! $provider || ! $policyRow || ! $resourceRow || $provider->instance_type !== 'ollama') {
                throw new RuntimeException('Existing Ollama provider/controller registrations are required.');
            }
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            if (isset($config['controller_modalities_20260928'])) {
                return; // Do not replay over later operator choices.
            }
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $defaults = json_decode((string) DB::table('system_configs')->where('section', 'cli')->where('config_key', 'runtime_policy')->value('config_value'), true);
            $baseId = $definition['base_target'];
            $baseCatalog = $config['controller_catalog']['targets'][$baseId] ?? null;
            $basePolicy = $policy['targets'][$baseId] ?? null;
            $baseCapacity = $resources['profiles'][$resources['bindings'][$definition['host_id']][$baseId] ?? ''] ?? null;
            if (! is_array($baseCatalog) || ! is_array($basePolicy) || ! is_array($baseCapacity)
                || ($baseCatalog['engine'] ?? null) !== 'ollama'
                || ($baseCatalog['model'] ?? null) !== ($config['default_model'] ?? null)
                || ($baseCatalog['provider_instance_id'] ?? null) !== $provider->instance_id
                || (int) parse_url($provider->base_url, PHP_URL_PORT) !== $baseCatalog['port']
                || ($config['route_policy']['require_explicit_instance'] ?? null) !== true
                || ($config['route_policy']['allow_generic_fallback'] ?? null) !== false) {
                throw new RuntimeException('Ollama base registration or explicit routing policy drifted.');
            }
            foreach ($definition['candidates'] as $candidate) {
                $id = $candidate['target'];
                $capacityId = $candidate['resource_profile'];
                $model = DB::table('ollama_models')->where('instance_id', $provider->id)
                    ->where('model_name', $candidate['model'])->lockForUpdate()->first();
                $runtimeIdentity = Resources::runtimeIdentity($candidate['lifecycle_target']);
                if (! $model || ! $model->is_available
                    || ($baseCapacity['runtime_identity'] ?? null) !== $runtimeIdentity
                    || isset($policy['targets'][$id]) || isset($config['controller_catalog']['targets'][$id])
                    || isset($resources['profiles'][$capacityId]) || isset($resources['bindings'][$definition['host_id']][$id])
                    || DB::table('llm_model_profiles')->where('profile_name', $candidate['profile_name'])->exists()) {
                    throw new RuntimeException('Ollama candidate is missing, drifted or partially registered.');
                }
                foreach ($candidate['roles'] as $role) {
                    if (($config['models'][$role] ?? null) !== $candidate['model']) {
                        throw new RuntimeException('Ollama role/model selection changed before registration.');
                    }
                    $config['role_runtime'][$role] = $candidate['role_runtime'];
                    if (! in_array($role, $config['route_policy']['allowed_model_roles'], true)) {
                        $config['route_policy']['allowed_model_roles'][] = $role;
                    }
                }
                $config['controller_catalog']['targets'][$id] = [...$baseCatalog,
                    'target_id' => $id, 'model' => $candidate['model'], 'roles' => $candidate['roles'],
                    'runtime' => [], 'policy_state' => 'staged', 'activation_allowed' => true];
                $policy['targets'][$id] = [...$basePolicy, 'model' => $candidate['model'],
                    'roles' => $candidate['roles'], 'state' => 'staged', 'activation_allowed' => true,
                    'requires_operator_override' => false, 'idle_ttl_seconds' => 0];
                $contracts = [];
                $capabilities = json_decode($model->capabilities ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                if ($candidate['workload'] === 'embedding') {
                    $contract = ['model' => $candidate['model'], 'wire_api' => 'ollama_embeddings',
                        'controller_target' => $id, 'controller_host_id' => $definition['host_id'],
                        'context_window' => $candidate['context_tokens'], 'dimensions' => 768, 'maximum_input_bytes' => 32768,
                        'ollama_options' => $candidate['role_runtime']['options']];
                    $config['controller_workloads']['ollama_embeddings'][$candidate['model']] = ['role' => 'embedding', 'contract' => $contract];
                    $contracts['plos.embedding.exact'] = Resources::requestContractIdentity($contract);
                } else {
                    foreach ($candidate['roles'] as $role) {
                        $contract = (new CliOllamaRuntimePolicy)->resolve([
                            'configuration_eligible' => true, 'instance_id' => $provider->instance_id,
                            'engine' => 'ollama', 'model' => $candidate['model'], 'role' => $role,
                            'native_tools_configured' => false,
                            'model_definition' => ['capabilities' => $capabilities, 'context_length' => $model->context_length],
                            'instance' => ['instance_type' => 'ollama', 'base_url' => $provider->base_url, 'config' => $config],
                        ], ['cli.runtime_policy' => $defaults]);
                        if ($contract['native_tools_enabled'] !== false || $contract['context_window'] !== $candidate['context_tokens']) {
                            throw new RuntimeException('Ollama candidate contract exceeds its declared scope.');
                        }
                        $digest = Resources::requestContractIdentity($contract);
                        if (! in_array($digest, $contracts, true)) {
                            $contracts['cli.'.$role.'.candidate'] = $digest;
                        }
                    }
                }
                $resources['profiles'][$capacityId] = ['qualified' => true, 'host_id' => $definition['host_id'],
                    'target' => $id, 'model_identity' => 'digest:'.$candidate['model_digest'], 'runtime_identity' => $runtimeIdentity,
                    'settings' => ['context_tokens' => $candidate['context_tokens'], 'parallel_requests' => 1, 'mtp_enabled' => false],
                    'requirements' => $candidate['requirements'], 'request_contracts' => $contracts];
                $resources['bindings'][$definition['host_id']][$id] = $capacityId;
                $metadata = ['controller_candidate' => ['schema' => $definition['schema'], 'target' => $id,
                    'provider_instance_id' => $provider->instance_id, 'roles' => $candidate['roles'],
                    'operator_admission' => true, 'measured_qualification' => false, 'live_qualification' => 'pending',
                    'native_tools_enabled' => false, 'runtime_notes' => $candidate['engine_notes'],
                    'lifecycle_projection' => $candidate['lifecycle_target']]];
                DB::table('llm_model_profiles')->insert($this->columns('llm_model_profiles', [
                    'profile_name' => $candidate['profile_name'], 'model_name' => $candidate['model'],
                    'description' => 'Explicit controller candidate: '.implode(', ', $candidate['roles']).'. Tools disabled; live checks pending.',
                    'enabled' => true, 'status' => 'active', 'requires_override' => false,
                    'metadata' => $this->json($metadata), 'created_at' => now(), 'updated_at' => now(),
                ]));
            }
            $config['controller_modalities_20260928'] = ['schema' => $definition['schema'],
                'operator_admission' => true, 'measured_qualification' => false, 'live_qualification' => 'pending',
                'targets' => array_column($definition['candidates'], 'target'), 'recorded_at' => now()->toIso8601String()];
            $resources['revision'] .= '-ollama-modalities-20260928';
            (new ModelHostControllerPolicyService)->normalize($policy);
            (new Resources)->normalize($resources);
            DB::table('llm_instances')->where('id', $provider->id)->update(['config' => $this->json($config), 'updated_at' => now()]);
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

    private function columns(string $table, array $values): array
    {
        return array_intersect_key($values, array_flip(Schema::getColumnListing($table)));
    }

    public function down(): void
    {
        // Preserve operator choices. Rollback requires a reviewed coherent host/table restore.
    }
};
