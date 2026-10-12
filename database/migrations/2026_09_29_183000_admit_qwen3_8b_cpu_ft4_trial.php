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
        if (! app()->environment('production')) {
            return;
        }
        $candidate = json_decode(file_get_contents(base_path('config/local-engine-candidates/ollama-qwen3-8b-cpu-ft4.87.json')),
            true, flags: JSON_THROW_ON_ERROR);
        if (($candidate['schema'] ?? null) !== 'plos.ollama_qwen3_8b_cpu_ft4_candidate.v1'
            || ($candidate['role'] ?? null) !== 'genealogy_research_trial') {
            throw new RuntimeException('Exact Qwen3 CPU FT4 trial definition is unavailable.');
        }
        DB::transaction(function () use ($candidate): void {
            $slot = DB::table('distributed_tasks')->where('task_id', Journal::SLOT_ID)->lockForUpdate()->first();
            $state = $slot ? json_decode((string) $slot->result, true, flags: JSON_THROW_ON_ERROR) : [];
            if (! $slot || $slot->task_type !== Journal::TASK_TYPE || $slot->status !== 'held'
                || ($state['schema'] ?? null) !== Journal::SCHEMA || ($state['phase'] ?? null) !== 'idle'
                || DB::table('distributed_tasks')->where('task_type', ModelHostControllerWaitService::TASK_TYPE)
                    ->where('status', 'held')->exists()) {
                throw new RuntimeException('Controller custody and FIFO must be idle before Qwen3 CPU FT4 trial admission.');
            }
            $provider = DB::table('llm_instances')->where('instance_id', $candidate['provider_instance_id'])->lockForUpdate()->first();
            $policyRow = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            $profile = DB::table('llm_model_profiles')->where('profile_name', $candidate['profile_name'])->lockForUpdate()->first();
            if (! $provider || ! $provider->is_active || $provider->routability !== 'allowed'
                || ! $policyRow || ! $resourceRow || ! $profile) {
                throw new RuntimeException('Staged Qwen3 CPU FT4 rows are required.');
            }
            $config = json_decode((string) $provider->config, true, flags: JSON_THROW_ON_ERROR);
            if (isset($config['controller_qwen3_cpu_ft4_admitted_20260929'])) {
                return; // Never replay over later operator choices.
            }
            $policy = json_decode((string) $policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode((string) $resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $id = $candidate['target'];
            $role = $candidate['role'];
            $catalog = $config['controller_catalog']['targets'][$id] ?? null;
            $target = $policy['targets'][$id] ?? null;
            $capacity = $resources['profiles'][$candidate['resource_profile']] ?? null;
            $metadata = json_decode((string) $profile->metadata, true, flags: JSON_THROW_ON_ERROR);
            $modelRow = DB::table('ollama_models')->where('instance_id', $provider->id)
                ->where('model_name', $candidate['model'])->lockForUpdate()->first();
            if (($config['controller_qwen3_cpu_ft4_staged_20260929']['target'] ?? null) !== $id
                || ! is_array($catalog) || ! is_array($target) || ! is_array($capacity) || ! $modelRow
                || ($catalog['activation_allowed'] ?? null) !== false
                || ($target['activation_allowed'] ?? null) !== false
                || ($capacity['qualified'] ?? null) !== false
                || ($capacity['model_identity'] ?? null) !== 'digest:'.$candidate['digest']
                || ($resources['bindings'][$candidate['host_id']][$id] ?? null) !== $candidate['resource_profile']
                || ($metadata['controller_candidate']['native_tools_enabled'] ?? null) !== false
                || ($metadata['controller_candidate']['genealogy_autonomous_qualified'] ?? null) !== false
                || (int) $profile->enabled !== 0 || $profile->status !== 'staged'
                || (int) $modelRow->is_available !== 0
                || isset($config['models'][$role])
                || in_array($role, $config['route_policy']['allowed_model_roles'] ?? [], true)
                || ($config['route_policy']['allow_generic_fallback'] ?? null) !== false
                || ($policy['execution_mode'] ?? null) !== 'serial'
                || ($resources['host_reserves'][$candidate['host_id']]['free_physical_bytes']['minimum'] ?? 0) < 8589934592) {
                throw new RuntimeException('Qwen3 CPU FT4 trial preimage or desktop reserve changed.');
            }
            $config['models'][$role] = $candidate['model'];
            $config['route_policy']['allowed_model_roles'][] = $role;
            $supported = json_decode((string) $provider->supported_models, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($supported) || ! array_is_list($supported)) {
                throw new RuntimeException('Ollama supported-model inventory is invalid.');
            }
            $supported[] = $candidate['model'];
            $config['role_runtime'][$role] = ['options' => $candidate['runtime_options'],
                'think' => false, 'native_tools_enabled' => true, 'input_modalities' => ['text']];
            $catalog['activation_allowed'] = true;
            $config['controller_catalog']['targets'][$id] = $catalog;
            $target['activation_allowed'] = true;
            $target['requires_operator_override'] = false; // Exact supervised CLI transport remains mandatory.
            $policy['targets'][$id] = $target;
            $defaults = json_decode((string) DB::table('system_configs')->where('section', 'cli')
                ->where('config_key', 'runtime_policy')->value('config_value'), true);
            $capabilities = json_decode((string) $modelRow->capabilities, true, flags: JSON_THROW_ON_ERROR);
            if (array_intersect($capabilities, ['tools', 'tool_calling', 'tool_use']) === []) {
                throw new RuntimeException('Qwen3 CPU alias lacks exact native-tool capability.');
            }
            $contract = (new CliOllamaRuntimePolicy)->resolve([
                'configuration_eligible' => true, 'instance_id' => $provider->instance_id,
                'engine' => 'ollama', 'model' => $candidate['model'], 'role' => $role,
                'native_tools_configured' => true,
                'model_definition' => ['capabilities' => $capabilities, 'context_length' => $modelRow->context_length],
                'instance' => ['instance_type' => 'ollama', 'base_url' => $provider->base_url, 'config' => $config],
            ], ['cli.runtime_policy' => $defaults]);
            if ($contract['context_window'] !== 4096 || $contract['native_tools_enabled'] !== true
                || ($contract['ollama_options']['num_gpu'] ?? null) !== 0 || ($contract['enable_thinking'] ?? null) !== false) {
                throw new RuntimeException('Qwen3 CPU FT4 trial request differs from CPU text-only contract.');
            }
            $capacity['qualified'] = true;
            $capacity['request_contracts'] = ['cli.'.$role => Resources::requestContractIdentity($contract)];
            $resources['profiles'][$candidate['resource_profile']] = $capacity;
            $resources['revision'] .= '-qwen3-8b-cpu-ft4-trial-20260929';
            $metadata['controller_candidate']['operator_admission'] = true;
            $metadata['controller_candidate']['trial_state'] = 'tree4_readonly_trial_pending_measurement';
            $metadata['controller_candidate']['native_tools_enabled'] = true;
            if (DB::table('ollama_models')->where('id', $modelRow->id)->where('is_available', 0)
                ->update(['is_available' => 1, 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Qwen3 CPU alias model changed during admission.');
            }
            if (DB::table('llm_model_profiles')->where('id', $profile->id)->where('enabled', 0)->where('status', 'staged')
                ->update(['enabled' => true, 'status' => 'testing', 'metadata' => $this->json($metadata), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Qwen3 CPU FT4 profile changed during admission.');
            }
            $config['controller_qwen3_cpu_ft4_admitted_20260929'] = ['target' => $id,
                'manual_only' => true, 'measured_qualification' => false,
                'native_tools_enabled' => true, 'genealogy_autonomous_qualified' => false];
            (new ModelHostControllerPolicyService)->normalize($policy);
            (new Resources)->normalize($resources);
            if ($this->providerPreimageQuery((int) $provider->id, (string) $provider->config)
                ->update(['config' => $this->json($config),
                    'supported_models' => $this->json(array_values(array_unique($supported))), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Qwen3 CPU FT4 provider changed during admission.');
            }
            foreach ([[$policyRow, $policy], [$resourceRow, $resources]] as [$row, $value]) {
                if (DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                    ->update(['config_value' => $this->json($value), 'updated_at' => now()]) !== 1) {
                    throw new RuntimeException('Qwen3 CPU FT4 policy changed during admission.');
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
        // Preserve later operator qualification choices.
    }
};
