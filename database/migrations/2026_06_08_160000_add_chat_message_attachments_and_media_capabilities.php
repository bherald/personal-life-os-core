<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'model_mode')) {
            DB::statement("ALTER TABLE conversations MODIFY model_mode VARCHAR(50) DEFAULT 'standard'");
        }
        if (Schema::hasTable('conversations') && ! Schema::hasColumn('conversations', 'model_override')) {
            Schema::table('conversations', function (Blueprint $table): void {
                $table->boolean('model_override')->default(false)->after('model_mode')->index();
            });
        }

        if (! Schema::hasTable('chat_message_attachments')) {
            Schema::create('chat_message_attachments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conversation_id')->index();
                $table->unsignedBigInteger('message_id')->nullable()->index();
                $table->string('role', 20)->default('user')->index();
                $table->string('direction', 20)->default('input')->index();
                $table->string('disk', 50)->default('local');
                $table->string('storage_path', 500);
                $table->string('original_name', 255)->nullable();
                $table->string('mime_type', 150)->nullable();
                $table->string('media_type', 50)->default('file')->index();
                $table->string('capability', 50)->nullable()->index();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->string('sha256', 64)->nullable()->index();
                $table->string('processing_status', 30)->default('stored')->index();
                $table->mediumText('extracted_text')->nullable();
                $table->mediumText('analysis_text')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['conversation_id', 'message_id'], 'chat_attach_conversation_message_idx');
                $table->index(['direction', 'media_type'], 'chat_attach_direction_media_idx');
            });
        }

        $now = now();
        $this->refreshCapabilityVocabulary($now);
        $this->seedCandidateProfiles($now);
        $this->clearCaches();
    }

    public function down(): void
    {
        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'model_override')) {
            Schema::table('conversations', function (Blueprint $table): void {
                $table->dropColumn('model_override');
            });
        }

        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'model_mode')) {
            DB::statement("ALTER TABLE conversations MODIFY model_mode VARCHAR(20) DEFAULT 'standard'");
        }

        if (Schema::hasTable('chat_message_attachments')) {
            Schema::dropIfExists('chat_message_attachments');
        }

        if (Schema::hasTable('llm_model_profiles')) {
            DB::delete(
                "DELETE FROM llm_model_profiles
                 WHERE profile_name IN (
                    'uncensored_vision',
                    'uncensored_multimodal',
                    'uncensored_coding',
                    'coding_agent',
                    'coding_agent_light',
                    'vision_detailed',
                    'vision_heavy',
                    'media_output_image',
                    'audio_transcription'
                 )"
            );
        }

        if (Schema::hasTable('ollama_models')) {
            DB::delete(
                "DELETE FROM ollama_models
                 WHERE model_name IN (
                    'huihui_ai/qwen3-vl-abliterated:8b-instruct',
                    'maxwellb/gemma4-12b-it-oym',
                    'dolphincoder:15b',
                    'qwen3-coder:30b',
                    'devstral:24b',
                    'qwen3-vl:8b',
                    'qwen3-vl:30b',
                    'x/z-image-turbo'
                 )"
            );
        }

        $this->clearCaches();
    }

    private function refreshCapabilityVocabulary(Carbon $now): void
    {
        if (! Schema::hasTable('ollama_models')) {
            return;
        }

        $rows = DB::table('ollama_models')->select('id', 'model_name', 'profile', 'capabilities', 'use_cases', 'vetting_notes')->get();

        foreach ($rows as $row) {
            $capabilities = $this->decodeList($row->capabilities);
            $useCases = $this->decodeList($row->use_cases);

            $this->addCapabilities($capabilities, ['text_input', 'text_output']);

            if ($this->containsAny($capabilities, ['streaming'])) {
                $this->addCapabilities($capabilities, ['text_streaming']);
            }

            if ($this->containsAny($capabilities, ['vision', 'image_analysis', 'ocr']) || in_array((string) $row->profile, ['vision', 'genealogy_extraction'], true)) {
                $this->addCapabilities($capabilities, ['image_input', 'image_to_text', 'ocr_text_extraction']);
            }

            if ($this->containsAny($capabilities, ['tools'])) {
                $this->addCapabilities($capabilities, ['tool_calling']);
            }

            if ($this->containsAny($capabilities, ['embedding'])) {
                $this->addCapabilities($capabilities, ['embedding_output']);
            }

            if ($this->containsAny($capabilities, ['uncensored']) || (string) $row->profile === 'uncensored') {
                $this->addCapabilities($capabilities, ['operator_uncensored', 'private_uncensored_text']);
            }

            if ((string) $row->profile === 'coding' || str_contains((string) $row->model_name, 'coder')) {
                $this->addCapabilities($capabilities, ['code_input', 'code_output']);
            }

            DB::table('ollama_models')
                ->where('id', $row->id)
                ->update([
                    'capabilities' => json_encode(array_values($capabilities), JSON_UNESCAPED_SLASHES),
                    'use_cases' => json_encode(array_values($useCases), JSON_UNESCAPED_SLASHES),
                    'updated_at' => $now,
                ]);
        }
    }

    private function seedCandidateProfiles(Carbon $now): void
    {
        if (Schema::hasTable('llm_model_profiles')) {
            $this->upsertProfile(
                'uncensored_vision',
                'huihui_ai/qwen3-vl-abliterated:8b-instruct',
                'Candidate local uncensored vision model',
                ['operator_uncensored', 'image_input', 'image_to_text', 'ocr_text_extraction', 'local_candidate'],
                '2026-06-08 RTS: candidate only. Requires local pull, license review, hardware fit, and PLOS eval before enabling. Community abliterated models may lose reasoning quality; do not make default without bench proof.',
                false,
                $now
            );

            $this->upsertProfile(
                'uncensored_multimodal',
                'maxwellb/gemma4-12b-it-oym',
                'Candidate uncensored multimodal operator model',
                ['operator_uncensored', 'image_input', 'audio_input', 'tool_calling', 'thinking_or_reasoning_modes', 'local_candidate'],
                '2026-06-08 RTS: candidate only from Ollama community listing. Verify exact upstream license, local runtime support, and refusal/quality behavior before enabling.',
                false,
                $now
            );

            $this->upsertProfile(
                'uncensored_coding',
                'dolphincoder:15b',
                'Candidate uncensored coding model',
                ['operator_uncensored', 'code_input', 'code_output', 'private_uncensored_text', 'local_candidate'],
                '2026-06-08 RTS: older uncensored StarCoder2-derived coding option. Candidate only; benchmark against current qwen/devstral coding models before enabling.',
                false,
                $now
            );

            $this->upsertProfile(
                'coding_agent',
                'qwen3-coder:30b',
                'Candidate local agentic coding model',
                ['code_input', 'code_output', 'tool_calling', 'agentic_coding', 'long_context', 'local_candidate'],
                '2026-06-08 RTS: .90+ candidate for agentic coding and repo-scale tasks. Requires local pull, memory check, and PLOS coding eval before enabling.',
                false,
                $now
            );

            $this->upsertProfile(
                'coding_agent_light',
                'devstral:24b',
                'Candidate lightweight local coding agent',
                ['code_input', 'code_output', 'agentic_coding', 'long_context', 'local_candidate'],
                '2026-06-08 RTS: .87 candidate for single-GPU/local coding agent work. Verify current Ollama build and compare to qwen3-coder before enabling.',
                false,
                $now
            );

            $this->upsertProfile(
                'vision_detailed',
                'qwen3-vl:8b',
                'Candidate detailed local vision and OCR model',
                ['image_input', 'image_to_text', 'ocr_text_extraction', 'visual_reasoning', 'visual_coding', 'local_candidate'],
                '2026-06-08 RTS: .89 candidate for detailed vision, OCR, layout, and visual coding. Requires Ollama 0.12.7+ and local eval before enabling.',
                false,
                $now
            );

            $this->upsertProfile(
                'vision_heavy',
                'qwen3-vl:30b',
                'Candidate high-quality local vision model',
                ['image_input', 'image_to_text', 'ocr_text_extraction', 'visual_reasoning', 'visual_coding', 'long_context', 'local_candidate'],
                '2026-06-08 RTS: .91 heavy candidate for detailed vision and visual coding. Disabled pending hardware fit and eval.',
                false,
                $now
            );

            $this->upsertProfile(
                'media_output_image',
                'x/z-image-turbo',
                'Candidate local image output generator',
                ['image_output', 'text_to_image', 'local_candidate', 'experimental'],
                '2026-06-08 RTS: candidate only. Ollama image generation is experimental and platform-gated; do not enable until local runtime support is proven.',
                false,
                $now
            );

            $this->upsertProfile(
                'audio_transcription',
                'whisper-local',
                'Local audio/video transcription path',
                ['audio_input', 'video_input', 'audio_to_text', 'video_to_text', 'local_tool'],
                '2026-06-08 RTS: represents local Whisper extraction/tool path, not a chat LLM. Use as attachment preprocessing capability.',
                false,
                $now
            );
        }

        if (Schema::hasTable('ollama_models')) {
            $this->upsertOllamaModel(
                'huihui_ai/qwen3-vl-abliterated:8b-instruct',
                'Huihui Qwen3-VL 8B Abliterated',
                'uncensored_vision',
                ['text_input', 'text_output', 'image_input', 'image_to_text', 'ocr_text_extraction', 'vision', 'uncensored', 'operator_uncensored', 'local_candidate'],
                ['private_uncensored_image_review_candidate', 'ocr_assist_candidate', 'visual_qa_candidate'],
                'Candidate uncensored image-input model. Not installed/enabled until local pull, license review, hardware fit, and eval pass.',
                84,
                $now
            );

            $this->upsertOllamaModel(
                'maxwellb/gemma4-12b-it-oym',
                'Gemma 4 12B IT OYM Abliterated',
                'uncensored_multimodal',
                ['text_input', 'text_output', 'image_input', 'audio_input', 'vision', 'tools', 'tool_calling', 'thinking_or_reasoning_modes', 'uncensored', 'operator_uncensored', 'local_candidate'],
                ['private_uncensored_multimodal_candidate', 'operator_image_review_candidate', 'operator_audio_context_candidate'],
                'Community uncensored multimodal candidate from Ollama listing. Keep unavailable until upstream license, runtime support, and local eval are confirmed.',
                87,
                $now
            );

            $this->upsertOllamaModel(
                'dolphincoder:15b',
                'DolphinCoder 15B',
                'uncensored_coding',
                ['text_input', 'text_output', 'code_input', 'code_output', 'uncensored', 'operator_uncensored', 'private_uncensored_text', 'local_candidate'],
                ['private_uncensored_coding_candidate', 'code_review_candidate'],
                'Candidate uncensored coding model. Older family; use only if current eval beats default coding profile for the specific operator task.',
                82,
                $now
            );

            $this->upsertOllamaModel(
                'qwen3-coder:30b',
                'Qwen3-Coder 30B',
                'coding_agent',
                ['text_input', 'text_output', 'code_input', 'code_output', 'tool_calling', 'agentic_coding', 'long_context', 'local_candidate'],
                ['agentic_coding_candidate', 'repo_scale_code_review_candidate', 'tool_use_candidate'],
                'Candidate .90+ coding model: long-context agentic coding profile. Requires local memory fit and PLOS coding eval before enabling.',
                92,
                $now
            );

            $this->upsertOllamaModel(
                'devstral:24b',
                'Devstral 24B',
                'coding_agent_light',
                ['text_input', 'text_output', 'code_input', 'code_output', 'agentic_coding', 'long_context', 'local_candidate'],
                ['agentic_coding_candidate', 'single_gpu_coding_candidate'],
                'Candidate .87 coding model with Apache 2.0 posture. Requires local eval and comparison to qwen3-coder.',
                87,
                $now
            );

            $this->upsertOllamaModel(
                'qwen3-vl:8b',
                'Qwen3-VL 8B',
                'vision_detailed',
                ['text_input', 'text_output', 'image_input', 'image_to_text', 'ocr_text_extraction', 'vision', 'tools', 'tool_calling', 'visual_reasoning', 'visual_coding', 'long_context', 'local_candidate'],
                ['detailed_vision_candidate', 'ocr_candidate', 'visual_coding_candidate', 'document_layout_candidate'],
                'Candidate .89 vision model. Requires Ollama 0.12.7+ and local PLOS eval before enabling.',
                89,
                $now
            );

            $this->upsertOllamaModel(
                'qwen3-vl:30b',
                'Qwen3-VL 30B',
                'vision_heavy',
                ['text_input', 'text_output', 'image_input', 'image_to_text', 'ocr_text_extraction', 'vision', 'tools', 'tool_calling', 'visual_reasoning', 'visual_coding', 'long_context', 'local_candidate'],
                ['heavy_vision_candidate', 'visual_coding_candidate', 'long_document_vision_candidate'],
                'Candidate .91 heavy vision model. Disabled pending hardware fit and eval.',
                91,
                $now
            );

            $this->upsertOllamaModel(
                'x/z-image-turbo',
                'Z-Image Turbo',
                'media_output_image',
                ['text_input', 'image_output', 'text_to_image', 'generated_file_output', 'local_candidate', 'experimental'],
                ['image_generation_candidate', 'operator_generated_media_candidate'],
                'Candidate local image output generator. Ollama support is experimental/platform-gated; keep unavailable until runtime proof.',
                70,
                $now
            );
        }
    }

    /**
     * @param  array<int,string>  $useCases
     */
    private function upsertProfile(string $profile, string $model, string $description, array $useCases, string $notes, bool $enabled, Carbon $now): void
    {
        $payload = [
            'model_name' => $model,
            'description' => $description,
            'use_cases' => json_encode($useCases, JSON_UNESCAPED_SLASHES),
            'enabled' => $enabled ? 1 : 0,
            'notes' => $notes,
            'updated_at' => $now,
        ];

        if (! DB::table('llm_model_profiles')->where('profile_name', $profile)->exists()) {
            $payload['created_at'] = $now;
        }

        DB::table('llm_model_profiles')->updateOrInsert(
            ['profile_name' => $profile],
            $payload
        );
    }

    /**
     * @param  array<int,string>  $capabilities
     * @param  array<int,string>  $useCases
     */
    private function upsertOllamaModel(string $model, string $display, string $profile, array $capabilities, array $useCases, string $notes, int $quality, Carbon $now): void
    {
        $instance = DB::table('llm_instances')
            ->where('instance_type', 'ollama')
            ->where('is_active', 1)
            ->orderBy('priority')
            ->orderBy('id')
            ->first();

        $key = ['model_name' => $model];
        if ($instance?->id) {
            $key['instance_id'] = $instance->id;
        }

        $payload = [
            'display_name' => $display,
            'profile' => $profile,
            'status' => 'testing',
            'is_available' => 0,
            'capabilities' => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
            'use_cases' => json_encode($useCases, JSON_UNESCAPED_SLASHES),
            'description' => $notes,
            'quality_rating' => $quality,
            'vetting_notes' => $notes,
            'vetted_by' => 'codex-rts',
            'updated_at' => $now,
            'last_seen_at' => $now,
        ];

        if (! DB::table('ollama_models')->where($key)->exists()) {
            $payload['created_at'] = $now;
            $payload['first_seen_at'] = $now;
        }

        DB::table('ollama_models')->updateOrInsert(
            $key,
            $payload
        );
    }

    /**
     * @return array<int,string>
     */
    private function decodeList(mixed $json): array
    {
        if (is_array($json)) {
            return array_values(array_unique(array_map('strval', $json)));
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded)
            ? array_values(array_unique(array_map('strval', $decoded)))
            : [];
    }

    /**
     * @param  array<int,string>  $capabilities
     * @param  array<int,string>  $items
     */
    private function addCapabilities(array &$capabilities, array $items): void
    {
        foreach ($items as $item) {
            if (! in_array($item, $capabilities, true)) {
                $capabilities[] = $item;
            }
        }
    }

    /**
     * @param  array<int,string>  $haystack
     * @param  array<int,string>  $needles
     */
    private function containsAny(array $haystack, array $needles): bool
    {
        return count(array_intersect($haystack, $needles)) > 0;
    }

    private function clearCaches(): void
    {
        foreach ([
            'llm_instances_all',
            'llm_instances_healthy',
            'external_api_providers',
            'llm_model_profiles',
        ] as $key) {
            Cache::forget($key);
        }
    }
};
