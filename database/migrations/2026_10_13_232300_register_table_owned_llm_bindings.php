<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs') || ! Schema::hasTable('llm_instances')) {
            return;
        }
        if (DB::table('system_configs')->where('section', 'routing')->where('config_key', 'llm_bindings')->exists()) {
            return; // An existing operator-owned binding is never replaced.
        }
        $providers = DB::table('llm_instances')->orderBy('priority')->orderBy('id')->get()->keyBy('instance_id');
        $config = static fn ($row): array => $row ? (json_decode((string) ($row->config ?? '{}'), true) ?: []) : [];
        $primary = $providers->get('ollama_primary');
        $secondary = $providers->get('ollama_secondary');
        $secondaryConfig = $config($secondary);
        $bindings = [];
        $aliases = ['any' => null];
        if ($primary) {
            $aliases['primary'] = [$primary->instance_id];
        }
        if ($secondary) {
            $aliases['secondary'] = [$secondary->instance_id];
        }
        $managedModel = $secondaryConfig['coexistence']['model'] ?? $secondaryConfig['models']['standard'] ?? null;
        $controllerRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->first();
        $controller = $controllerRow ? json_decode((string) $controllerRow->config_value, true) : [];
        if (is_array($controller) && is_string($controller['host_id'] ?? null)) {
            $bindings['controller_transport'] = [
                'base_url' => config('model_host_controller.url'),
                'expected_host_id' => $controller['host_id'],
                'allowed_hosts' => config('model_host_controller.allowed_hosts', []),
                'port' => config('model_host_controller.allowed_port'),
            ];
        }
        $managedTarget = null;
        foreach ($controller['targets'] ?? [] as $id => $target) {
            if (($target['engine'] ?? null) === 'ollama' && ($target['model'] ?? null) === $managedModel) {
                if ($managedTarget !== null) {
                    throw new RuntimeException('Managed knowledge-graph target registration is ambiguous.');
                }
                $managedTarget = $id;
            }
        }
        $bindings['knowledge_graph'] = ['aliases' => $aliases, 'default_alias' => $primary ? 'primary' : 'any'];
        if ($secondary && is_string($managedModel) && $managedModel !== '' && $managedTarget !== null) {
            $bindings['knowledge_graph']['managed'] = ['instance_id' => $secondary->instance_id, 'model' => $managedModel, 'model_role' => 'standard', 'target_id' => $managedTarget, 'engine' => 'ollama'];
        }
        if ($secondary && is_string($managedModel) && $managedModel !== '') {
            $bindings['ollama_coexistence'] = ['instance_id' => $secondary->instance_id, 'model' => $managedModel, 'base_url' => $secondary->base_url, 'qwen_instance_id' => $providers->get('qwen38_87')?->instance_id, 'admission_group' => $secondaryConfig['slot_policy']['admission_group'] ?? null];
        }
        $failureDomain = (string) ($config($primary)['failure_domain'] ?? '');
        $productionRemote = str_starts_with($failureDomain, 'host:') ? substr($failureDomain, 5) : null;
        foreach (['mhc08_k3' => 'kimi_k3_87', 'bitnet_canary' => 'bitnet_87', 'qwen_bench' => 'qwen38_87'] as $name => $legacyId) {
            $row = $providers->get($legacyId);
            if (! $row) {
                continue;
            }
            $rowConfig = $config($row);
            $url = parse_url((string) $row->base_url);
            $bindings[$name] = ['instance_id' => $row->instance_id, 'base_url' => $row->base_url, 'host' => $url['host'] ?? null, 'port' => $url['port'] ?? $row->port ?? null, 'model' => $rowConfig['default_model'] ?? $rowConfig['models']['deep_offline'] ?? null, 'host_affinity' => $row->host_affinity ?? null, 'production_remote' => $productionRemote];
            $bindings[$name]['target_ref'] = 'provider:'.$row->instance_id.'/'.($bindings[$name]['model'] ?? '');
        }
        $canaryRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_canary_policy')->first();
        $canary = $canaryRow ? json_decode((string) $canaryRow->config_value, true) : [];
        if (is_array($canary['targets'] ?? null) && $canary['targets'] !== []) {
            $bindings['controller_canary'] = ['targets' => array_map(static fn ($target): array => array_intersect_key($target, array_flip(['provider_instance_id', 'model', 'engine', 'role', 'adapter'])), $canary['targets'])];
        }
        $scorecard = config('ollama_eval.k3_05');
        if (is_array($scorecard) && $scorecard !== []) {
            $scorecard['comparator_model'] = str_starts_with((string) ($scorecard['comparator_target'] ?? ''), 'ollama:') ? substr($scorecard['comparator_target'], 7) : null;
            $bindings['kimi_scorecard'] = $scorecard;
        }
        $jetson = $providers->get('ollama_jetson');
        if ($jetson) {
            $bindings['offline_sidecar'] = ['instance_id' => $jetson->instance_id, 'profiles' => ['plos-offline-jetson' => $jetson->instance_id]];
        }
        DB::table('system_configs')->insert(['section' => 'routing', 'config_key' => 'llm_bindings', 'config_value' => json_encode($bindings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'data_type' => 'json', 'description' => 'Explicit operator-owned LLM route aliases and controller/canary target bindings; missing bindings refuse routing.', 'created_at' => now(), 'updated_at' => now()]);
        if ($primary && $secondary) {
            $row = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'service_runtime_policy')->first();
            $policy = $row ? json_decode((string) $row->config_value, true) : null;
            if (is_array($policy) && ! array_key_exists('preferred_ollama_instance_ids_by_role', $policy)) {
                $policy['preferred_ollama_instance_ids_by_role'] = array_fill_keys(['standard', 'quality', 'coding', 'uncensored'], [$secondary->instance_id, $primary->instance_id]);
                DB::table('system_configs')->where('id', $row->id)->update(['config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
        }
        Cache::forget('syscfg:routing.llm_bindings');
        Cache::forget('syscfg:ai.service_runtime_policy');
    }

    public function down(): void
    {
        // Preserve operator-owned bindings and decisions when code is rolled back.
    }
};
