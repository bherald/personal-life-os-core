<?php

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService as Resources;
use App\Services\ModelHostControllerSelectionJournal as Journal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PROD owns model choices; DEV receives them through the operator's manual sync.
        if (! app()->environment('production')) {
            return;
        }
        $definition = json_decode(file_get_contents(base_path('config/local-engine-candidates/ollama-qwen-trials.87.json')), true, flags: JSON_THROW_ON_ERROR);
        if (($definition['schema'] ?? null) !== 'plos.ollama_qwen_trials.v1') {
            throw new RuntimeException('Invalid Ollama trial definition.');
        }
        foreach (['system_configs', 'llm_instances', 'ollama_models', 'llm_model_profiles', 'distributed_tasks'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Existing model registry is required for Ollama trials.');
            }
        }
        DB::transaction(function () use ($definition): void {
            $slot = DB::table('distributed_tasks')->where('task_id', Journal::SLOT_ID)->lockForUpdate()->first();
            $state = $slot ? json_decode($slot->result, true, flags: JSON_THROW_ON_ERROR) : [];
            if (! $slot || $slot->task_type !== Journal::TASK_TYPE || $slot->status !== 'held'
                || ($state['schema'] ?? null) !== Journal::SCHEMA || ($state['phase'] ?? null) !== 'idle') {
                throw new RuntimeException('Controller custody must be registered and idle before staging Ollama trials.');
            }
            $provider = DB::table('llm_instances')->where('instance_id', $definition['provider_instance_id'])->lockForUpdate()->first();
            $policyRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            if (! $provider || $provider->instance_type !== 'ollama' || ! $policyRow || ! $resourceRow) {
                throw new RuntimeException('Existing Ollama/controller registration is required.');
            }
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            if (isset($config['controller_qwen_trials_20260929'])) {
                return; // A completed prior transaction is never replayed over operator changes.
            }
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $base = $definition['base_target'];
            $catalogBase = $config['controller_catalog']['targets'][$base] ?? null;
            $policyBase = $policy['targets'][$base] ?? null;
            $capacityId = $resources['bindings'][$definition['host_id']][$base] ?? null;
            $capacityBase = is_string($capacityId) ? ($resources['profiles'][$capacityId] ?? null) : null;
            if (! is_array($catalogBase) || ! is_array($policyBase) || ! is_array($capacityBase)
                || ($catalogBase['engine'] ?? null) !== 'ollama'
                || ($catalogBase['provider_instance_id'] ?? null) !== $provider->instance_id
                || ($policy['execution_mode'] ?? null) !== 'serial'
                || ($resources['host_reserves'][$definition['host_id']]['free_physical_bytes']['minimum'] ?? 0) < 8589934592
                || ($config['route_policy']['require_explicit_instance'] ?? null) !== true
                || ($config['route_policy']['allow_generic_fallback'] ?? null) !== false) {
                throw new RuntimeException('Ollama base admission, serial execution or desktop reserve drifted.');
            }
            foreach ($definition['candidates'] as $candidate) {
                $id = $candidate['target'];
                $resourceId = $candidate['resource_profile'];
                if (! preg_match('/\A[a-f0-9]{64}\z/', $candidate['digest'])
                    || isset($config['controller_catalog']['targets'][$id]) || isset($policy['targets'][$id])
                    || isset($resources['profiles'][$resourceId]) || isset($resources['bindings'][$definition['host_id']][$id])
                    || DB::table('ollama_models')->where('instance_id', $provider->id)->where('model_name', $candidate['model'])->exists()
                    || DB::table('llm_model_profiles')->where('profile_name', $candidate['profile_name'])->exists()) {
                    throw new RuntimeException('Ollama trial identity is invalid, drifted or partially registered.');
                }
                $config['controller_catalog']['targets'][$id] = [...$catalogBase,
                    'target_id' => $id, 'model' => $candidate['model'], 'roles' => [$candidate['role']],
                    'runtime' => [], 'policy_state' => 'staged', 'activation_allowed' => false];
                $policy['targets'][$id] = [...$policyBase,
                    'model' => $candidate['model'], 'roles' => [$candidate['role']],
                    'state' => 'staged', 'activation_allowed' => false,
                    'requires_operator_override' => true, 'idle_ttl_seconds' => 0];
                $resources['profiles'][$resourceId] = [
                    'qualified' => false, 'host_id' => $definition['host_id'], 'target' => $id,
                    'model_identity' => 'digest:'.$candidate['digest'],
                    'runtime_identity' => $capacityBase['runtime_identity'],
                    'settings' => ['context_tokens' => $candidate['context_tokens'], 'parallel_requests' => 1, 'mtp_enabled' => false],
                    'requirements' => $candidate['requirements'],
                ];
                $resources['bindings'][$definition['host_id']][$id] = $resourceId;
                $metadata = ['controller_candidate' => [
                    'schema' => $definition['schema'], 'target' => $id,
                    'provider_instance_id' => $provider->instance_id,
                    'model_digest' => $candidate['digest'], 'artifact_bytes' => $candidate['artifact_bytes'],
                    'role' => $candidate['role'], 'context_tokens' => $candidate['context_tokens'],
                    'native_dimensions' => $candidate['native_dimensions'] ?? null,
                    'native_tools_enabled' => false, 'operator_admission' => false,
                    'measured_qualification' => false, 'notes' => $candidate['notes'],
                ]];
                DB::table('ollama_models')->insert($this->columns('ollama_models', [
                    'instance_id' => $provider->id, 'model_name' => $candidate['model'],
                    'display_name' => $candidate['model'], 'profile' => $candidate['profile_name'],
                    'status' => 'testing', 'is_available' => 0,
                    'capabilities' => $this->json($candidate['capabilities']),
                    'use_cases' => $this->json([$candidate['role']]),
                    'context_length' => $candidate['context_tokens'],
                    'size_gb' => $candidate['artifact_bytes'] / 1073741824,
                    'vetting_notes' => $candidate['notes'], 'created_at' => now(), 'updated_at' => now(),
                ]));
                DB::table('llm_model_profiles')->insert($this->columns('llm_model_profiles', [
                    'profile_name' => $candidate['profile_name'], 'model_name' => $candidate['model'],
                    'description' => 'Downloaded .87 Ollama trial; disabled pending managed quality and capacity tests.',
                    'enabled' => false, 'status' => 'staged', 'requires_override' => true,
                    'metadata' => $this->json($metadata), 'created_at' => now(), 'updated_at' => now(),
                ]));
            }
            $config['controller_qwen_trials_20260929'] = [
                'schema' => $definition['schema'], 'targets' => array_column($definition['candidates'], 'target'),
                'operator_admission' => false, 'measured_qualification' => false,
            ];
            $resources['revision'] .= '-qwen-trials-20260929';
            (new ModelHostControllerPolicyService)->normalize($policy);
            (new Resources)->normalize($resources);
            DB::table('llm_instances')->where('id', $provider->id)->update(['config' => $this->json($config), 'updated_at' => now()]);
            foreach ([[$policyRow, $policy], [$resourceRow, $resources]] as [$row, $value]) {
                DB::table('system_configs')->where('id', $row->id)->update(['config_value' => $this->json($value), 'updated_at' => now()]);
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

    private function columns(string $table, array $values): array
    {
        return array_intersect_key($values, array_flip(Schema::getColumnListing($table)));
    }

    public function down(): void
    {
        // Preserve downloaded artifacts and any later operator qualification choices.
    }
};
