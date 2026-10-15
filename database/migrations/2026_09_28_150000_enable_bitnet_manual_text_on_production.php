<?php

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService as Resources;
use App\Services\UnifiedCli\CliProviderRuntimePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! (new ModelHostControllerPolicyService)->operatorPacketsApplicable([
            'config/local-engine-candidates/bitnet-manual.87.json',
        ])) {
            return;
        }
        // PROD owns these decisions. DEV configuration is copied only by the operator.
        if (! app()->environment('production')) {
            return;
        }
        $definition = json_decode(file_get_contents(base_path('config/local-engine-candidates/bitnet-manual.87.json')), true, flags: JSON_THROW_ON_ERROR);
        if (($definition['schema'] ?? null) !== 'plos.bitnet.manual_text.v1'
            || ! hash_equals($definition['lifecycle_target']['control_sha256'], hash_file('sha256', base_path($definition['control_source'])))) {
            throw new RuntimeException('BitNet manual definition or launcher identity differs.');
        }
        DB::transaction(function () use ($definition): void {
            $id = $definition['target'];
            $provider = DB::table('llm_instances')->where('instance_id', $id)->lockForUpdate()->first();
            $policyRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            $resourceRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            if (! $provider || ! $policyRow || ! $resourceRow) {
                throw new RuntimeException('BitNet manual activation requires existing qualified registrations.');
            }
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            if (isset($config['manual_text_admission'])) {
                return; // Preserve subsequent operator edits; do not replay this decision.
            }
            $policy = json_decode($policyRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $resources = json_decode($resourceRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            $capacityId = $resources['bindings']['.87'][$id] ?? null;
            $capacity = $resources['profiles'][$capacityId] ?? [];
            $model = DB::table('ollama_models')->where('instance_id', $provider->id)->where('model_name', $definition['model'])->lockForUpdate()->first();
            if ($provider->instance_type !== 'local_llm' || $config['default_model'] !== $definition['model']
                || ($config['route_policy']['allowed_model_roles'] ?? null) !== ['privacy_deny_prefilter']
                || (isset($config['model_host_controller_canary']) || isset($config['model_host_controller_automatic']))
                || ! $model || ! $model->is_available || ($capacity['qualified'] ?? false) !== true
                || ($capacity['model_identity'] ?? null) !== $definition['model_identity']
                || ($capacity['runtime_identity'] ?? null) !== $definition['prior_runtime_identity']
                || ($capacity['settings']['context_tokens'] ?? null) !== $definition['context_tokens']
                || count($capacity['request_contracts'] ?? []) !== 1
                || ! isset($capacity['request_contracts']['plos.privacy_deny_prefilter.candidate'])
                || DB::table('llm_model_profiles')->where('profile_name', $definition['profile_name'])->exists()) {
                throw new RuntimeException('BitNet qualification or registration drift requires review.');
            }
            $config['models']['offline_manual'] = $definition['model'];
            $config['controller_transport_role'] = 'offline_manual';
            $config['max_tokens'] = $definition['max_output_tokens'];
            $config['context_window'] = $definition['context_tokens'];
            $config['route_policy'] = [...$config['route_policy'],
                'allowed_model_roles' => ['privacy_deny_prefilter', 'offline_manual'],
                'require_explicit_instance' => true, 'require_cached_health' => false,
                'allow_generic_fallback' => false, 'tools_enabled' => false,
                'redacted_input_roles' => ['privacy_deny_prefilter']];
            $config['role_runtime']['offline_manual'] = ['context_handling' => 'provider_reject',
                'context_window' => $definition['context_tokens'], 'max_tokens' => $definition['max_output_tokens'],
                'temperature' => 0, 'absolute_timeout_seconds' => 120, 'idle_timeout_seconds' => 60];
            // Privacy keeps its fixed four-token contract and request settings.
            $config['manual_text_admission'] = ['schema' => $definition['schema'],
                'basis' => 'explicit_operator_enablement', 'grade' => 'manual_candidate',
                'qualification' => $definition['qualification'], 'native_tools' => false,
                'automatic_selection' => false, 'registered_at' => now()->toIso8601String()];
            $config['controller_catalog']['targets'][$id]['roles'] = ['offline_manual', 'privacy_deny_prefilter'];
            $config['controller_catalog']['targets'][$id]['activation_allowed'] = true;
            $config['notes'] = 'Explicit manual text candidate; qualified privacy decision retained. No agent tools or generic fallback.';
            $policy['targets'][$id]['roles'] = ['privacy_deny_prefilter', 'offline_manual'];
            $policy['targets'][$id]['activation_allowed'] = true;
            $policy['targets'][$id]['requires_operator_override'] = false;
            $contract = (new CliProviderRuntimePolicy)->resolve([
                'configuration_eligible' => true, 'instance_id' => $id, 'engine' => $definition['engine'],
                'model' => $definition['model'], 'role' => 'offline_manual', 'native_tools_configured' => false,
                'model_definition' => ['capabilities' => ['text', 'classification'], 'context_length' => $definition['context_tokens']],
                'instance' => ['instance_type' => 'local_llm', 'base_url' => $provider->base_url, 'config' => $config],
            ], ['cli.runtime_policy' => json_decode((string) DB::table('system_configs')->where('section', 'cli')->where('config_key', 'runtime_policy')->value('config_value'), true)]);
            $resources['profiles'][$capacityId]['runtime_identity'] = Resources::runtimeIdentity($definition['lifecycle_target']);
            $resources['profiles'][$capacityId]['request_contracts']['cli.offline_manual.bitnet'] = Resources::requestContractIdentity($contract);
            $resources['revision'] = 'bitnet-manual-text-20260928';
            (new ModelHostControllerPolicyService)->normalize($policy);
            (new Resources)->normalize($resources);
            DB::table('llm_instances')->where('id', $provider->id)->update([
                'is_active' => 1, 'routability' => 'allowed', 'quarantine_status' => 'none',
                'quarantined_at' => null, 'quarantine_reason' => null, 'quarantine_source' => null,
                'config' => $this->json($config), 'updated_at' => now(),
            ]); // Do not invent a successful health observation or change historical metrics.
            $description = 'BitNet CPU manual text candidate: brief summaries, simple extraction and classification. No tools, automatic routing, coding or factual qualification.';
            DB::table('llm_model_profiles')->insert($this->columns('llm_model_profiles', [
                'profile_name' => $definition['profile_name'], 'model_name' => $definition['model'],
                'description' => $description, 'enabled' => true, 'status' => 'active', 'requires_override' => false,
                'metadata' => $this->json(['manual_text_admission' => $config['manual_text_admission'],
                    'model_role' => 'offline_manual', 'provider_instance_id' => $id, 'native_tools_enabled' => false,
                    'supported_tasks' => $definition['supported_tasks'], 'not_qualified' => $definition['not_qualified'],
                    'lifecycle_projection' => $definition['lifecycle_target']]), 'created_at' => now(), 'updated_at' => now(),
            ]));
            DB::table('ollama_models')->where('id', $model->id)->update([
                'display_name' => 'BitNet 2B — manual text candidate / privacy gate',
                'description' => $description.' Existing privacy-only vetting evidence remains in vetting_notes.', 'updated_at' => now(),
            ]);
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
        // Exact operator rollback must also restore the pinned host launcher. Never silently widen or replay policy.
    }
};
