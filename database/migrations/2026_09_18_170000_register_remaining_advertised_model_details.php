<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_instances') || ! Schema::hasTable('ollama_models')) {
            return;
        }

        DB::transaction(function (): void {
            // Registration data only: never change provider defaults, role bindings or admission.
            $instances = DB::table('llm_instances')
                ->whereIn('instance_id', ['codex_exec', 'ollama_host_only', 'glm52_87'])
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($instances as $instance) {
                $models = json_decode($instance->supported_models, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($models) || ! array_is_list($models)) {
                    throw new RuntimeException('Advertised model inventory must be a list.');
                }
                foreach ($models as $model) {
                    if (! is_string($model) || trim($model) === '' || strlen($model) > 100) {
                        throw new RuntimeException('Advertised model identity is invalid.');
                    }
                    if (DB::table('ollama_models')->where('instance_id', $instance->id)->where('model_name', $model)->exists()) {
                        continue;
                    }
                    $evidence = [
                        'schema' => 'plos.model_catalog_evidence.v1',
                        'recorded_on' => '2026-09-18',
                        'source' => 'llm_instances.supported_models',
                        'instance_id' => $instance->instance_id,
                        'qualification' => 'not_qualified_for_unified_cli',
                        'availability_verified' => false,
                        'effective_capabilities' => 'unknown',
                        'reason' => $instance->is_active ? 'provider_advertisement_only' : 'inactive_instance_inventory_only',
                    ];
                    if ($instance->instance_id === 'codex_exec') {
                        $publishedContext = ['gpt-5.5' => 1050000, 'gpt-5.4' => 1050000, 'gpt-5.4-mini' => 400000];
                        if (isset($publishedContext[$model])) {
                            $evidence['published_api'] = [
                                'source_url' => 'https://developers.openai.com/api/docs/models/'.$model,
                                'context_tokens' => $publishedContext[$model],
                                'maximum_output_tokens' => 128000,
                                'scope' => 'API specification; not a PLOS Codex transport or account qualification',
                            ];
                            $evidence['codex_chatgpt_auth'] = [
                                'source_url' => 'https://learn.chatgpt.com/docs/models',
                                'retirement_date' => $model === 'gpt-5.5' ? '2026-10-14' : '2026-08-31',
                                'api_key_access_affected' => false,
                                'local_auth_mode_verified' => false,
                            ];
                        }
                    }
                    DB::table('ollama_models')->insert([
                        'instance_id' => $instance->id, 'model_name' => $model, 'display_name' => $model,
                        'profile' => 'catalog_only', 'status' => 'unavailable', 'is_available' => false,
                        'capabilities' => '[]', 'use_cases' => '["inventory_review"]',
                        'description' => 'Advertised identity recorded for review. Runtime availability and model-specific PLOS capabilities are unqualified; no route is enabled.',
                        'context_length' => null, 'size_gb' => null, 'vram_required_mb' => null,
                        'vetting_notes' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        'first_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
        Cache::forget('ollama_available_models');
        Cache::forget('ollama_vetted_models');
    }

    public function down(): void
    {
        // Additive inventory is retained, including any later operator qualification.
    }
};
