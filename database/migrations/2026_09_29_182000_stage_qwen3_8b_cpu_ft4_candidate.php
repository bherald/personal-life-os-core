<?php

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService as Resources;
use App\Services\ModelHostControllerSelectionJournal as Journal;
use App\Services\ModelHostControllerWaitService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }
        if (! (new ModelHostControllerPolicyService)->operatorPacketsApplicable([
            'config/local-engine-candidates/ollama-qwen3-8b-cpu-ft4.87.json',
        ])) {
            return;
        }
        $candidate = json_decode(file_get_contents(base_path('config/local-engine-candidates/ollama-qwen3-8b-cpu-ft4.87.json')),
            true, flags: JSON_THROW_ON_ERROR);
        if (($candidate['schema'] ?? null) !== 'plos.ollama_qwen3_8b_cpu_ft4_candidate.v1'
            || ! preg_match('/\A[a-f0-9]{64}\z/', $candidate['digest'] ?? '')) {
            throw new RuntimeException('Exact FT4 model candidate definition is unavailable.');
        }
        DB::transaction(function () use ($candidate): void {
            $slot = DB::table('distributed_tasks')->where('task_id', Journal::SLOT_ID)->lockForUpdate()->first();
            $state = $slot ? json_decode((string) $slot->result, true, flags: JSON_THROW_ON_ERROR) : [];
            if (! $slot || $slot->task_type !== Journal::TASK_TYPE || $slot->status !== 'held'
                || ($state['schema'] ?? null) !== Journal::SCHEMA || ($state['phase'] ?? null) !== 'idle'
                || DB::table('distributed_tasks')->where('task_type', ModelHostControllerWaitService::TASK_TYPE)
                    ->where('status', 'held')->exists()) {
                throw new RuntimeException('Controller custody and FIFO must be idle before Qwen3 CPU FT4 candidate staging.');
            }
            $provider = DB::table('llm_instances')->where('instance_id', $candidate['provider_instance_id'])->lockForUpdate()->first();
            $policyRow = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            if (! $provider || $provider->instance_type !== 'ollama' || ! $policyRow || ! $resourceRow) {
                throw new RuntimeException('Existing Ollama/controller tables are required for Qwen3 CPU FT4 candidate staging.');
            }
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            if (isset($config['controller_qwen3_cpu_ft4_staged_20260929'])) {
                return; // Never replay over a later operator decision.
            }
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $base = $candidate['base_target'];
            $catalogBase = $config['controller_catalog']['targets'][$base] ?? null;
            $policyBase = $policy['targets'][$base] ?? null;
            $capacityBase = $resources['profiles'][$resources['bindings'][$candidate['host_id']][$base] ?? ''] ?? null;
            $baseModelRow = DB::table('ollama_models')->where('instance_id', $provider->id)
                ->where('model_name', 'qwen3:8b')->lockForUpdate()->first();
            if (! is_array($catalogBase) || ! is_array($policyBase) || ! is_array($capacityBase)
                || ! $baseModelRow || ! $baseModelRow->is_available
                || ($capacityBase['model_identity'] ?? null) !== 'digest:'.$candidate['digest']
                || ($catalogBase['engine'] ?? null) !== 'ollama'
                || ($catalogBase['provider_instance_id'] ?? null) !== $provider->instance_id
                || ($policy['execution_mode'] ?? null) !== 'serial'
                || ($policy['concurrent_inference_enabled'] ?? null) !== false
                || ($resources['host_reserves'][$candidate['host_id']]['free_physical_bytes']['minimum'] ?? 0) < 8589934592
                || ($config['route_policy']['require_explicit_instance'] ?? null) !== true
                || ($config['route_policy']['allow_generic_fallback'] ?? null) !== false
                || isset($config['controller_catalog']['targets'][$candidate['target']])
                || isset($policy['targets'][$candidate['target']])
                || isset($resources['profiles'][$candidate['resource_profile']])
                || isset($resources['bindings'][$candidate['host_id']][$candidate['target']])
                || DB::table('llm_model_profiles')->where('profile_name', $candidate['profile_name'])->exists()
                || DB::table('ollama_models')->where('instance_id', $provider->id)
                    ->where('model_name', $candidate['model'])->exists()
                || isset($config['models'][$candidate['role']])) {
                throw new RuntimeException('Qwen3 CPU FT4 candidate base, model, reserve or route policy drifted.');
            }
            $id = $candidate['target'];
            $config['controller_catalog']['targets'][$id] = [...$catalogBase,
                'target_id' => $id, 'model' => $candidate['model'], 'roles' => [$candidate['role']],
                'runtime' => [], 'policy_state' => 'staged', 'activation_allowed' => false];
            $policy['targets'][$id] = [...$policyBase,
                'model' => $candidate['model'], 'roles' => [$candidate['role']],
                'state' => 'staged', 'activation_allowed' => false,
                'requires_operator_override' => true, 'idle_ttl_seconds' => 0];
            $resources['profiles'][$candidate['resource_profile']] = [
                'qualified' => false, 'host_id' => $candidate['host_id'], 'target' => $id,
                'model_identity' => 'digest:'.$candidate['digest'],
                'runtime_identity' => $capacityBase['runtime_identity'],
                'settings' => ['context_tokens' => $candidate['context_tokens'], 'parallel_requests' => 1, 'mtp_enabled' => false],
                'requirements' => $candidate['requirements'],
            ];
            $resources['bindings'][$candidate['host_id']][$id] = $candidate['resource_profile'];
            $metadata = ['controller_candidate' => [
                'schema' => $candidate['schema'], 'target' => $id, 'role' => $candidate['role'],
                'model_digest' => $candidate['digest'], 'host_declared_capabilities' => $candidate['host_declared_capabilities'],
                'runtime_options' => $candidate['runtime_options'], 'trial_scope' => 'tree_id=4; supervised read-only tool and quality trial only',
                'prior_contract_issue' => $candidate['prior_contract_issue'],
                'operator_admission' => false, 'measured_qualification' => false,
                'native_tools_enabled' => false, 'genealogy_autonomous_qualified' => false,
            ]];
            DB::table('ollama_models')->insert($this->columns('ollama_models', [
                'instance_id' => $provider->id, 'model_name' => $candidate['model'],
                'display_name' => 'Qwen3 8B CPU FT4 alias', 'profile' => $candidate['profile_name'],
                'status' => 'testing', 'is_available' => 0,
                'capabilities' => $baseModelRow->capabilities, 'use_cases' => $this->json([$candidate['role']]),
                'context_length' => $candidate['context_tokens'],
                'size_gb' => $candidate['artifact_bytes_estimate'] / 1073741824,
                'vetting_notes' => $candidate['prior_contract_issue'],
                'created_at' => now(), 'updated_at' => now(),
            ]));
            DB::table('llm_model_profiles')->insert($this->columns('llm_model_profiles', [
                'profile_name' => $candidate['profile_name'], 'model_name' => $candidate['model'],
                'description' => 'Installed .87 Qwen3 8B CPU alias; disabled pending supervised tree4 trial.',
                'enabled' => false, 'status' => 'staged', 'requires_override' => true,
                'metadata' => $this->json($metadata), 'created_at' => now(), 'updated_at' => now(),
            ]));
            $config['controller_qwen3_cpu_ft4_staged_20260929'] = ['schema' => $candidate['schema'],
                'target' => $id, 'activation_allowed' => false, 'qualified' => false];
            // A disabled, unqualified candidate does not change the live host policy revision.
            (new ModelHostControllerPolicyService)->normalize($policy);
            (new Resources)->normalize($resources);
            if ($this->providerPreimageQuery((int) $provider->id, (string) $provider->config)
                ->update(['config' => $this->json($config), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Qwen3 CPU FT4 candidate provider changed during staging.');
            }
            foreach ([[$policyRow, $policy], [$resourceRow, $resources]] as [$row, $value]) {
                if (DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                    ->update(['config_value' => $this->json($value), 'updated_at' => now()]) !== 1) {
                    throw new RuntimeException('FT4 controller policy changed during staging.');
                }
            }
        });
        foreach (['llm_instances', 'llm_instances_all', 'llm_instances_healthy', 'ollama_models', 'llm_model_profiles',
            'syscfg:ai.model_host_controller_policy', 'syscfg:ai.'.Resources::CONFIG_KEY] as $key) {
            Cache::forget($key);
        }
    }

    private function columns(string $table, array $values): array
    {
        return array_intersect_key($values, array_flip(Schema::getColumnListing($table)));
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function providerPreimageQuery(int $id, string $config): Builder
    {
        $query = DB::table('llm_instances')->where('id', $id);

        // MySQL JSON equality compares a document with a SQL string; match exact stored bytes.
        return DB::connection()->getDriverName() === 'mysql'
            ? $query->whereRaw('SHA2(CAST(config AS CHAR), 256) = ?', [hash('sha256', $config)])
            : $query->where('config', $config);
    }

    public function down(): void
    {
        // Preserve later operator qualification choices.
    }
};
