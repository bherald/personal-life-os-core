<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'ollama_secondary';

    private const MODEL = 'qwen3.5:9b';

    private const PROFILE = 'vision_qwen35';

    public function up(): void
    {
        if (! Schema::hasTable('llm_instances') || ! Schema::hasTable('ollama_models')) {
            return;
        }

        $instance = DB::table('llm_instances')
            ->where('instance_id', self::INSTANCE_ID)
            ->where('instance_type', 'ollama')
            ->first(['id']);
        if (! $instance) {
            return;
        }

        $now = now();
        $capabilities = [
            'text_input',
            'text_output',
            'file_input',
            'image_input',
            'image_to_text',
            'ocr_text_extraction',
            'vision',
            'visual_reasoning',
            'tool_use',
            'tool_calling',
            'long_context',
            'local_candidate',
        ];
        $useCases = [
            'offline_chat_candidate',
            'vision_candidate',
            'document_image_analysis_candidate',
            'native_tool_call_candidate',
        ];
        $notes = 'Candidate on ollama_secondary (.87). 2026-07-19 native text, native /api/chat image input, native tool-call, and Codex Responses text smokes passed. Native JSON-schema output returned non-JSON and failed. Exact PLOS structured-output and Codex tool-routing admission remains required before normal routing.';

        $modelValues = [
            'display_name' => 'Qwen 3.5 9B Multimodal',
            'profile' => self::PROFILE,
            'status' => 'testing',
            'is_available' => 1,
            'capabilities' => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
            'use_cases' => json_encode($useCases, JSON_UNESCAPED_SLASHES),
            'description' => $notes,
            'size_gb' => 6.6,
            'context_length' => 32768,
            'vram_required_mb' => 6400,
            'quality_rating' => 82,
            'vetting_notes' => $notes,
            'vetted_by' => 'codex-candidate-smoke',
            'last_seen_at' => $now,
            'updated_at' => $now,
        ];
        $modelKey = [
            'instance_id' => $instance->id,
            'model_name' => self::MODEL,
        ];
        if (! DB::table('ollama_models')->where($modelKey)->exists()) {
            $modelValues['first_seen_at'] = $now;
            $modelValues['created_at'] = $now;
        }
        DB::table('ollama_models')->updateOrInsert($modelKey, $modelValues);

        if (Schema::hasTable('llm_model_profiles')) {
            $profileValues = [
                'model_name' => self::MODEL,
                'description' => 'Local Qwen 3.5 multimodal candidate on .87',
                'use_cases' => json_encode($useCases, JSON_UNESCAPED_SLASHES),
                'enabled' => 1,
                'notes' => $notes,
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('llm_model_profiles', 'status')) {
                $profileValues['status'] = 'testing';
            }
            if (Schema::hasColumn('llm_model_profiles', 'capabilities')) {
                $profileValues['capabilities'] = json_encode($capabilities, JSON_UNESCAPED_SLASHES);
            }
            if (Schema::hasColumn('llm_model_profiles', 'requires_override')) {
                $profileValues['requires_override'] = 1;
            }
            if (Schema::hasColumn('llm_model_profiles', 'metadata')) {
                $profileValues['metadata'] = json_encode([
                    'instance_id' => self::INSTANCE_ID,
                    'route' => 'local_only_candidate',
                    'runtime' => [
                        'num_ctx' => 32768,
                        'think' => false,
                    ],
                    'admission' => [
                        'text_smoke' => 'passed',
                        'native_image_smoke' => 'passed',
                        'native_tool_call_smoke' => 'passed',
                        'native_json_schema_smoke' => 'failed_non_json',
                        'structured_output_eval' => 'blocked_native_schema_failure',
                        'tool_routing_eval' => 'pending',
                    ],
                ], JSON_UNESCAPED_SLASHES);
            }

            $profileKey = ['profile_name' => self::PROFILE];
            if (! DB::table('llm_model_profiles')->where($profileKey)->exists()) {
                $profileValues['created_at'] = $now;
            }
            DB::table('llm_model_profiles')->updateOrInsert($profileKey, $profileValues);
        }

        $this->clearCaches();
    }

    public function down(): void
    {
        if (Schema::hasTable('llm_model_profiles')) {
            DB::table('llm_model_profiles')
                ->where('profile_name', self::PROFILE)
                ->where('model_name', self::MODEL)
                ->delete();
        }

        if (Schema::hasTable('llm_instances') && Schema::hasTable('ollama_models')) {
            $instanceId = DB::table('llm_instances')
                ->where('instance_id', self::INSTANCE_ID)
                ->value('id');
            if ($instanceId) {
                DB::table('ollama_models')
                    ->where('instance_id', $instanceId)
                    ->where('model_name', self::MODEL)
                    ->delete();
            }
        }

        $this->clearCaches();
    }

    private function clearCaches(): void
    {
        foreach ([
            'llm_instances_all',
            'llm_instances_healthy',
            'llm_model_profiles',
            'ollama_available_models',
            'ollama_vetted_models',
        ] as $key) {
            Cache::forget($key);
        }
    }
};
