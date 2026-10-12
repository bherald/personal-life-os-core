<?php

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService as Resources;
use App\Services\ModelHostControllerSelectionJournal as Journal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('local')) {
            return;
        }
        $definition = json_decode(file_get_contents(base_path('config/local-engine-candidates/ollama-modalities.87.json')),
            true, flags: JSON_THROW_ON_ERROR);
        if (($definition['schema'] ?? null) !== 'plos.ollama_controller_modalities.v1'
            || count($definition['candidates'] ?? []) !== 6) {
            throw new RuntimeException('Expected reviewed Ollama candidate definition is unavailable.');
        }

        DB::transaction(function () use ($definition): void {
            $slot = DB::table('distributed_tasks')->where('task_id', Journal::SLOT_ID)->lockForUpdate()->first();
            $state = $slot ? json_decode((string) $slot->result, true, flags: JSON_THROW_ON_ERROR) : [];
            if (! $slot || $slot->task_type !== Journal::TASK_TYPE || $slot->status !== 'held'
                || ($state['schema'] ?? null) !== Journal::SCHEMA || ($state['phase'] ?? null) !== 'idle') {
                throw new RuntimeException('Controller custody must be idle before DEV catalog staging.');
            }
            $provider = DB::table('llm_instances')->where('instance_id', $definition['provider_instance_id'])->lockForUpdate()->first();
            $policyRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            if (! $provider || ! $policyRow || ! $resourceRow || $provider->instance_type !== 'ollama'
                || ! $provider->is_active || $provider->routability !== 'allowed') {
                throw new RuntimeException('Existing active Ollama provider and controller policies are required.');
            }
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            if (isset($config['controller_modalities_dev_staged_20260929'])) {
                return; // Never replay over a later operator decision.
            }
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $baseId = $definition['base_target'];
            $baseCatalog = $config['controller_catalog']['targets'][$baseId] ?? null;
            $basePolicy = $policy['targets'][$baseId] ?? null;
            $baseCapacity = $resources['profiles'][$resources['bindings'][$definition['host_id']][$baseId] ?? ''] ?? null;
            if (! is_array($baseCatalog) || ! is_array($basePolicy) || ! is_array($baseCapacity)
                || ($baseCatalog['engine'] ?? null) !== 'ollama'
                || ($baseCatalog['model'] ?? null) !== ($config['default_model'] ?? null)
                || ($baseCatalog['provider_instance_id'] ?? null) !== $provider->instance_id
                || (int) parse_url($provider->base_url, PHP_URL_PORT) !== ($baseCatalog['port'] ?? null)
                || ($config['route_policy']['require_explicit_instance'] ?? null) !== true
                || ($config['route_policy']['allow_generic_fallback'] ?? null) !== false) {
                throw new RuntimeException('DEV Ollama base registration or explicit routing policy drifted.');
            }

            foreach ($definition['candidates'] as $candidate) {
                $id = $candidate['target'];
                $profileId = $candidate['resource_profile'];
                $model = DB::table('ollama_models')->where('instance_id', $provider->id)
                    ->where('model_name', $candidate['model'])->lockForUpdate()->first();
                if (! $model || ! $model->is_available
                    || ($baseCapacity['runtime_identity'] ?? null) !== Resources::runtimeIdentity($candidate['lifecycle_target'])
                    || isset($policy['targets'][$id]) || isset($config['controller_catalog']['targets'][$id])
                    || isset($resources['profiles'][$profileId]) || isset($resources['bindings'][$definition['host_id']][$id])
                    || DB::table('llm_model_profiles')->where('profile_name', $candidate['profile_name'])->exists()) {
                    throw new RuntimeException('DEV Ollama candidate is missing, drifted, or already registered.');
                }
                foreach ($candidate['roles'] as $role) {
                    if (($config['models'][$role] ?? null) !== $candidate['model']) {
                        throw new RuntimeException('DEV Ollama role/model selection differs.');
                    }
                }
                $config['controller_catalog']['targets'][$id] = [...$baseCatalog,
                    'target_id' => $id, 'model' => $candidate['model'], 'roles' => $candidate['roles'],
                    'runtime' => [], 'policy_state' => 'staged', 'activation_allowed' => false];
                $policy['targets'][$id] = [...$basePolicy, 'model' => $candidate['model'],
                    'roles' => $candidate['roles'], 'state' => 'staged', 'activation_allowed' => false,
                    'requires_operator_override' => true, 'idle_ttl_seconds' => 0];
                $resources['profiles'][$profileId] = ['qualified' => false, 'host_id' => $definition['host_id'],
                    'target' => $id, 'model_identity' => 'digest:'.$candidate['model_digest'],
                    'runtime_identity' => Resources::runtimeIdentity($candidate['lifecycle_target']),
                    'settings' => ['context_tokens' => $candidate['context_tokens'],
                        'parallel_requests' => 1, 'mtp_enabled' => false],
                    'requirements' => $candidate['requirements']];
                $resources['bindings'][$definition['host_id']][$id] = $profileId;
                $metadata = ['controller_candidate' => ['schema' => $definition['schema'], 'target' => $id,
                    'provider_instance_id' => $provider->instance_id, 'roles' => $candidate['roles'],
                    'operator_admission' => false, 'measured_qualification' => false,
                    'live_qualification' => 'pending', 'native_tools_enabled' => false,
                    'lifecycle_projection' => $candidate['lifecycle_target']]];
                DB::table('llm_model_profiles')->insert($this->columns('llm_model_profiles', [
                    'profile_name' => $candidate['profile_name'], 'model_name' => $candidate['model'],
                    'description' => 'DEV controller metadata only; activation and capacity qualification pending.',
                    'enabled' => false, 'status' => 'testing', 'requires_override' => true,
                    'metadata' => $this->json($metadata), 'created_at' => now(), 'updated_at' => now(),
                ]));
            }
            $config['controller_modalities_dev_staged_20260929'] = ['schema' => $definition['schema'],
                'targets' => array_column($definition['candidates'], 'target'),
                'activation_allowed' => false, 'qualified' => false];
            $resources['revision'] .= '-dev-ollama-metadata-20260929';
            (new ModelHostControllerPolicyService)->normalize($policy);
            (new Resources)->normalize($resources);
            $updatedProvider = $this->providerPreimageQuery((int) $provider->id, (string) $provider->config)
                ->update(['config' => $this->json($config), 'updated_at' => now()]);
            if ($updatedProvider !== 1) {
                throw new RuntimeException('DEV Ollama provider changed during staging.');
            }
            foreach ([[$policyRow, $policy], [$resourceRow, $resources]] as [$row, $value]) {
                $updated = DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                    ->update(['config_value' => $this->json($value), 'updated_at' => now()]);
                if ($updated !== 1) {
                    throw new RuntimeException('DEV controller policy changed during staging.');
                }
            }
        });
        foreach (['llm_instances', 'llm_instances_all', 'llm_instances_healthy', 'llm_model_profiles',
            'syscfg:ai.model_host_controller_policy', 'syscfg:ai.'.Resources::CONFIG_KEY] as $key) {
            Cache::forget($key);
        }
    }

    private function providerPreimageQuery(int $id, string $config): Builder
    {
        $query = DB::table('llm_instances')->where('id', $id);

        return DB::connection()->getDriverName() === 'mysql'
            ? $query->whereRaw('SHA2(CAST(config AS CHAR), 256) = ?', [hash('sha256', $config)])
            : $query->where('config', $config);
    }

    private function columns(string $table, array $values): array
    {
        return array_intersect_key($values, array_flip(Schema::getColumnListing($table)));
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function down(): void
    {
        // Preserve operator state. Exact current rows need a reviewed rollback.
    }
};
