<?php

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService as Resources;
use App\Services\ModelHostControllerSelectionJournal as Journal;
use App\Services\ModelHostControllerWaitService;
use App\Services\UnifiedCli\CliOllamaRuntimePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PROD owns choices. DEV receives them through the operator's manual sync.
        if (! app()->environment('production')) {
            return;
        }
        $definition = json_decode(file_get_contents(base_path('config/local-engine-candidates/ollama-qwen-trials.87.json')),
            true, flags: JSON_THROW_ON_ERROR);
        if (($definition['schema'] ?? null) !== 'plos.ollama_qwen_trials.v1' || count($definition['candidates'] ?? []) !== 2) {
            throw new RuntimeException('Exact Qwen trial definition is unavailable.');
        }
        DB::transaction(function () use ($definition): void {
            $slot = DB::table('distributed_tasks')->where('task_id', Journal::SLOT_ID)->lockForUpdate()->first();
            $state = $slot ? json_decode((string) $slot->result, true, flags: JSON_THROW_ON_ERROR) : [];
            if (! $slot || $slot->task_type !== Journal::TASK_TYPE || $slot->status !== 'held'
                || ($state['schema'] ?? null) !== Journal::SCHEMA || ($state['phase'] ?? null) !== 'idle') {
                throw new RuntimeException('Controller custody must be idle before Qwen trial admission.');
            }
            if (DB::table('distributed_tasks')->where('task_type', ModelHostControllerWaitService::TASK_TYPE)
                ->where('status', 'held')->exists()) {
                throw new RuntimeException('Held controller FIFO tickets must be reconciled before Qwen trial admission.');
            }
            $provider = DB::table('llm_instances')->where('instance_id', $definition['provider_instance_id'])->lockForUpdate()->first();
            $policyRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            if (! $provider || $provider->instance_type !== 'ollama' || ! $provider->is_active
                || $provider->routability !== 'allowed' || ! $policyRow || ! $resourceRow) {
                throw new RuntimeException('Existing active Ollama/controller registration is required.');
            }
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            if (isset($config['controller_qwen_manual_trials_20260929'])) {
                return; // Never replay over later operator choices.
            }
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $defaults = json_decode((string) DB::table('system_configs')->where('section', 'cli')
                ->where('config_key', 'runtime_policy')->value('config_value'), true);
            if (($policy['execution_mode'] ?? null) !== 'serial'
                || ($policy['concurrent_inference_enabled'] ?? null) !== false
                || ($resources['host_reserves']['.87']['free_physical_bytes']['minimum'] ?? 0) < 8589934592
                || ($config['route_policy']['require_explicit_instance'] ?? null) !== true
                || ($config['route_policy']['allow_generic_fallback'] ?? null) !== false
                || ! is_array($config['route_policy']['allowed_model_roles'] ?? null)
                || isset($config['controller_transport_role'])
                || ($config['controller_qwen_trials_20260929']['operator_admission'] ?? null) !== false) {
                throw new RuntimeException('Serial controller, desktop reserve or staged Qwen policy drifted.');
            }
            $supported = json_decode((string) $provider->supported_models, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($supported)) {
                throw new RuntimeException('Ollama supported-model inventory is invalid.');
            }
            foreach ($definition['candidates'] as $candidate) {
                $id = $candidate['target'];
                $model = $candidate['model'];
                $role = $candidate['role'] === 'coding' ? 'coding_trial' : 'embedding_trial';
                $catalog = $config['controller_catalog']['targets'][$id] ?? null;
                $target = $policy['targets'][$id] ?? null;
                $capacity = $resources['profiles'][$candidate['resource_profile']] ?? null;
                $modelRow = DB::table('ollama_models')->where('instance_id', $provider->id)
                    ->where('model_name', $model)->lockForUpdate()->first();
                $profile = DB::table('llm_model_profiles')->where('profile_name', $candidate['profile_name'])
                    ->lockForUpdate()->first();
                if (! is_array($catalog) || ! is_array($target) || ! is_array($capacity) || ! $modelRow || ! $profile
                    || ($catalog['model'] ?? null) !== $model || ($target['model'] ?? null) !== $model
                    || ($catalog['activation_allowed'] ?? null) !== false || ($target['activation_allowed'] ?? null) !== false
                    || ($capacity['qualified'] ?? null) !== false
                    || ($capacity['model_identity'] ?? null) !== 'digest:'.$candidate['digest']
                    || ($resources['bindings']['.87'][$id] ?? null) !== $candidate['resource_profile']
                    || (int) $modelRow->is_available !== 0 || (int) $profile->enabled !== 0
                    || $profile->model_name !== $model || $profile->status !== 'staged'
                    || ($config['models'][$role] ?? null) !== null) {
                    throw new RuntimeException('Staged Qwen target differs from exact trial preimage.');
                }
                $config['models'][$role] = $model;
                $config['route_policy']['allowed_model_roles'][] = $role;
                $catalog['roles'] = [$role];
                $catalog['activation_allowed'] = true;
                $config['controller_catalog']['targets'][$id] = $catalog;
                $target['roles'] = [$role];
                $target['activation_allowed'] = true;
                $target['requires_operator_override'] = true;
                $policy['targets'][$id] = $target;
                if ($candidate['role'] === 'coding') {
                    $runtime = $config['role_runtime']['coding'] ?? null;
                    if (! is_array($runtime) || ($runtime['options']['num_ctx'] ?? null) !== 4096
                        || ($runtime['options']['num_gpu'] ?? null) !== 0
                        || ($runtime['native_tools_enabled'] ?? null) !== false) {
                        throw new RuntimeException('Existing CPU coding runtime changed before Qwen trial.');
                    }
                    $config['role_runtime'][$role] = $runtime;
                    $supported[] = $model;
                    $capabilities = json_decode((string) $modelRow->capabilities, true, flags: JSON_THROW_ON_ERROR);
                    $contract = (new CliOllamaRuntimePolicy)->resolve([
                        'configuration_eligible' => true, 'instance_id' => $provider->instance_id,
                        'engine' => 'ollama', 'model' => $model, 'role' => $role,
                        'native_tools_configured' => false,
                        'model_definition' => ['capabilities' => $capabilities, 'context_length' => $modelRow->context_length],
                        'instance' => ['instance_type' => 'ollama', 'base_url' => $provider->base_url, 'config' => $config],
                    ], ['cli.runtime_policy' => $defaults]);
                    if ($contract['native_tools_enabled'] !== false || $contract['context_window'] !== 4096) {
                        throw new RuntimeException('Coding trial request contract differs from CPU-only scope.');
                    }
                    $capacity['request_contracts'] = ['cli.coding_trial' => Resources::requestContractIdentity($contract)];
                } else {
                    // Isolated 1024-dimension comparison; current 768-dimension RAG route stays unchanged.
                    $options = ['num_ctx' => 2048, 'num_gpu' => 0, 'num_thread' => 4];
                    $contract = ['model' => $model, 'wire_api' => 'ollama_embeddings',
                        'controller_target' => $id, 'controller_host_id' => '.87',
                        'context_window' => 2048, 'dimensions' => 1024, 'maximum_input_bytes' => 8192,
                        'ollama_options' => $options];
                    $config['controller_workloads']['ollama_embeddings'][$model] = ['role' => $role, 'contract' => $contract];
                    $capacity['request_contracts'] = ['plos.embedding_trial' => Resources::requestContractIdentity($contract)];
                }
                $capacity['qualified'] = true;
                $resources['profiles'][$candidate['resource_profile']] = $capacity;
                $metadata = json_decode((string) $profile->metadata, true, flags: JSON_THROW_ON_ERROR);
                $metadata['controller_candidate']['operator_admission'] = true;
                $metadata['controller_candidate']['trial_state'] = 'admitted_pending_measurement';
                $updatedModel = DB::table('ollama_models')->where('id', $modelRow->id)->where('is_available', 0)
                    ->update(['is_available' => 1, 'status' => 'testing', 'updated_at' => now()]);
                $updatedProfile = DB::table('llm_model_profiles')->where('id', $profile->id)->where('enabled', 0)
                    ->where('status', 'staged')
                    ->update(['enabled' => true, 'status' => 'testing', 'requires_override' => true,
                        'metadata' => $this->json($metadata), 'updated_at' => now()]);
                if ($updatedModel !== 1 || $updatedProfile !== 1) {
                    throw new RuntimeException('Qwen trial model/profile changed during admission.');
                }
            }
            $config['controller_qwen_manual_trials_20260929'] = ['targets' => array_column($definition['candidates'], 'target'),
                'manual_only' => true, 'measured_qualification' => false];
            $resources['revision'] .= '-qwen-manual-trials-20260929';
            (new ModelHostControllerPolicyService)->normalize($policy);
            (new Resources)->normalize($resources);
            $updatedProvider = $this->providerPreimageQuery((int) $provider->id, (string) $provider->config)
                ->update(['config' => $this->json($config),
                    'supported_models' => $this->json(array_values(array_unique($supported))), 'updated_at' => now()]);
            if ($updatedProvider !== 1) {
                throw new RuntimeException('Qwen trial provider changed during admission.');
            }
            foreach ([[$policyRow, $policy], [$resourceRow, $resources]] as [$row, $value]) {
                $updated = DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                    ->update(['config_value' => $this->json($value), 'updated_at' => now()]);
                if ($updated !== 1) {
                    throw new RuntimeException('Qwen trial controller policy changed during admission.');
                }
            }
        });
        foreach (['llm_instances', 'llm_instances_all', 'llm_instances_healthy', 'ollama_models', 'llm_model_profiles',
            'syscfg:ai.model_host_controller_policy', 'syscfg:ai.'.Resources::CONFIG_KEY] as $key) {
            Cache::forget($key);
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function providerPreimageQuery(int $id, string $config): Builder
    {
        $query = DB::table('llm_instances')->where('id', $id);

        return DB::connection()->getDriverName() === 'mysql'
            ? $query->whereRaw('SHA2(CAST(config AS CHAR), 256) = ?', [hash('sha256', $config)])
            : $query->where('config', $config);
    }

    public function down(): void
    {
        // Preserve any later operator decision; no automatic model-state rollback.
    }
};
