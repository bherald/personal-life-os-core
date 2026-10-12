<?php

use App\Services\AIService;
use App\Services\ModelHostControllerResourcePolicyService as Resources;
use App\Services\OpenAICompatibleProgressClient;
use App\Services\UnifiedCli\CliProviderRuntimePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PROFILE = 'online87.qwen38_87.2ea56a265678.4096.serial';

    public function up(): void
    {
        DB::transaction(function (): void {
            $id = 'qwen38_87';
            $row = DB::table('system_configs')->where('section', 'ai')->where('config_key', Resources::CONFIG_KEY)->lockForUpdate()->first();
            $provider = DB::table('llm_instances')->where('instance_id', $id)->lockForUpdate()->first();
            $modelProfile = DB::table('llm_model_profiles')->where('profile_name', 'deep_offline_qwen38')->lockForUpdate()->first();
            if ($row === null || $provider === null || $modelProfile === null || $provider->instance_type !== 'local_llm') {
                throw new RuntimeException('Qwen operator admission requires the existing local registration.');
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $config = json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR);
            $metadata = json_decode($modelProfile->metadata, true, flags: JSON_THROW_ON_ERROR);
            $registration = $metadata['controller_registration'] ?? [];
            if (($registration['target'] ?? null) !== $id || ($registration['launch_sha256'] ?? null) !== 'e1118af601b8d6a356432af2dbcd4f9cd7c9b6804068ad1eeda20821f94c75d5') {
                throw new RuntimeException('Qwen operator admission requires the reviewed managed launch.');
            }
            $profile = $this->profile();
            $binding = $policy['bindings']['.87'][$id] ?? null;
            if ($binding !== null && $binding !== self::PROFILE) {
                throw new RuntimeException('Qwen already has a different resource binding.');
            }
            if (isset($policy['profiles'][self::PROFILE]) && Resources::requestContractIdentity($policy['profiles'][self::PROFILE]) !== Resources::requestContractIdentity($profile)) {
                throw new RuntimeException('Qwen resource profile was edited; preserve it for review.');
            }
            foreach (['context_handling' => 'provider_reject', 'context_window' => 4096] as $key => $value) {
                if (array_key_exists($key, $config) && $config[$key] !== $value) {
                    throw new RuntimeException('Qwen context policy differs from retained runtime capacity.');
                }
                $config[$key] = $value;
            }
            $model = $config['models']['deep_offline'] ?? null;
            $definition = DB::table('ollama_models')->where('instance_id', $provider->id)->where('model_name', $model)->first();
            if ($model !== 'qwen3.8-27b-q4_k_m' || $definition === null || (int) $definition->context_length !== 4096) {
                throw new RuntimeException('Qwen retained model identity/context differs.');
            }
            $runtime = app(AIService::class)->prepareManagedRuntimeConfig([], $config, 'deep_offline', 'openai_compatible');
            $sse = ['schema' => 'plos.managed_inference.request.v1', 'controller_host_id' => '.87', 'controller_target' => $id,
                'context_window' => $runtime['num_ctx'], 'wire_api' => 'openai_chat_completions',
                'endpoint_sha256' => hash('sha256', rtrim($provider->base_url, '/').'/chat/completions'),
                'request_settings' => OpenAICompatibleProgressClient::contentFreeSettings(OpenAICompatibleProgressClient::streamPayload(
                    AIService::openAICompatibleTextPayload($runtime, $config, $model), $config['stream_policy'] ?? []))];
            $defaults = json_decode(DB::table('system_configs')->where('section', 'cli')->where('config_key', 'runtime_policy')->value('config_value'), true, flags: JSON_THROW_ON_ERROR);
            $cli = (new CliProviderRuntimePolicy)->resolve(['configuration_eligible' => true, 'instance_id' => $id,
                'engine' => 'llama.cpp', 'model' => $model, 'role' => 'deep_offline', 'native_tools_configured' => false,
                'model_definition' => ['context_length' => (int) $definition->context_length, 'capabilities' => json_decode($definition->capabilities, true, flags: JSON_THROW_ON_ERROR)],
                'instance' => ['instance_type' => $provider->instance_type, 'base_url' => $provider->base_url, 'config' => $config]], ['cli.runtime_policy' => $defaults]);
            $next = $policy;
            $next['profiles'][self::PROFILE] = $profile;
            $next['bindings']['.87'][$id] = self::PROFILE;
            $resources = new Resources(fn () => $next);
            $resources->assertRequestContract('.87', $id, $sse);
            $resources->assertRequestContract('.87', $id, $cli);
            $admission = $this->admission();
            if (isset($metadata['controller_operator_admission']) && $metadata['controller_operator_admission'] !== $admission) {
                throw new RuntimeException('Qwen operator admission record changed; preserve for review.');
            }
            $metadata['controller_operator_admission'] = $admission;
            foreach ([['system_configs', $row, 'config_value', $next], ['llm_instances', $provider, 'config', $config],
                ['llm_model_profiles', $modelProfile, 'metadata', $metadata]] as [$table, $record, $column, $value]) {
                if (Resources::requestContractIdentity(json_decode($record->$column, true, flags: JSON_THROW_ON_ERROR)) === Resources::requestContractIdentity($value)) {
                    continue;
                }
                DB::table($table)->where('id', $record->id)->update([$column => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
            }
        });
        foreach (['llm_instances', 'llm_model_profiles', 'external_api_providers'] as $key) {
            Cache::forget($key);
        }
    }

    private function profile(): array
    {
        // Existing schema's admission flag; operator waiver, not a fresh measured qualification.
        return json_decode(<<<'JSON'
{
    "qualified": true,
    "host_id": ".87",
    "target": "qwen38_87",
    "model_identity": "sha256:31629f53165ab6a7dad8c9847dcfd1fdf55829dac1e6e748f4a68581b0033d34",
    "runtime_identity": "sha256:9710751aaea592d91d07681ab2b01d91d73386d827de4871cc4ab9479dfa1e90",
    "settings": {
        "context_tokens": 4096,
        "parallel_requests": 1,
        "mtp_enabled": false
    },
    "requirements": {
        "cold": {
            "free_physical_bytes": {
                "minimum": 34359738368
            },
            "commit_headroom_bytes": {
                "minimum": 25769803776
            },
            "drive_c_free_bytes": {
                "minimum": 42949672960
            },
            "drive_d_free_bytes": {
                "minimum": 42949672960
            },
            "pagefile_current_bytes": {
                "maximum": 12884901888
            }
        },
        "loaded": {
            "free_physical_bytes": {
                "minimum": 8589934592
            },
            "commit_headroom_bytes": {
                "minimum": 8589934592
            },
            "drive_c_free_bytes": {
                "minimum": 42949672960
            },
            "drive_d_free_bytes": {
                "minimum": 42949672960
            },
            "pagefile_current_bytes": {
                "maximum": 12884901888
            },
            "pagefile_growth_bytes": {
                "maximum": 3221225472
            }
        }
    },
    "request_contracts": {
        "plos.deep_offline.streaming": "19a7c0c6f472e328f5fd9d607c2f6d7c7f15701b9fda195f7ee71278b315f07a",
        "cli.deep_offline.qwen38": "414c23c44751d786e7ffcfc6455cbe906af736b9da6064394925b8dfac6485d9"
    }
}
JSON, true, flags: JSON_THROW_ON_ERROR);
    }

    private function admission(): array
    {
        return json_decode(<<<'JSON'
{
    "schema": "plos.controller.operator_admission.v1",
    "date_utc": "2026-09-26",
    "decision": "operator_requested_online_without_fresh_qualification",
    "fresh_qualification": false,
    "basis": [
        "existing vetted Qwen model registration and operator approval retained",
        "unchanged model and eight runtime identities",
        "unchanged serial CPU context4096 capacity floors"
    ],
    "scope": "controller-owned explicit deep_offline text; exact SSE and CLI contracts; no tools or generic fallback",
    "resource_profile": "online87.qwen38_87.2ea56a265678.4096.serial",
    "no_model_request_performed": true
}
JSON, true, flags: JSON_THROW_ON_ERROR);
    }

    public function down(): void
    {
        // Keep explicit operator admission and evidence; host/table changes must remain paired.
    }
};
