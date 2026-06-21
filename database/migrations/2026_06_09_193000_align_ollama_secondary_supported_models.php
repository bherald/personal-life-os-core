<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INSTANCE_ID = 'ollama_secondary';

    private const ADD_MODELS = [
        'huihui_ai/qwen2.5-vl-abliterated:7b',
        'huihui_ai/qwen3-abliterated:8b',
    ];

    private const REMOVE_MODELS = [
        'maxwellb/gemma4-12b-it-oym',
        'maxwellb/gemma4-12b-it-oym:latest',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $row = DB::table('llm_instances')
            ->where('instance_id', self::INSTANCE_ID)
            ->first();

        if (! $row) {
            return;
        }

        $models = $this->decodeList($row->supported_models ?? '[]');
        $models = array_values(array_filter(
            $models,
            fn (string $model): bool => ! in_array($model, self::REMOVE_MODELS, true)
        ));

        foreach (self::ADD_MODELS as $model) {
            if (! in_array($model, $models, true)) {
                $models[] = $model;
            }
        }

        DB::table('llm_instances')
            ->where('instance_id', self::INSTANCE_ID)
            ->update([
                'supported_models' => json_encode($models, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

        $this->refreshOllamaModelRows(now());
        $this->clearCaches();
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $row = DB::table('llm_instances')
            ->where('instance_id', self::INSTANCE_ID)
            ->first();

        if (! $row) {
            return;
        }

        $models = $this->decodeList($row->supported_models ?? '[]');
        $models = array_values(array_filter(
            $models,
            fn (string $model): bool => ! in_array($model, self::ADD_MODELS, true)
        ));

        foreach (self::REMOVE_MODELS as $model) {
            if (! in_array($model, $models, true)) {
                $models[] = $model;
            }
        }

        DB::table('llm_instances')
            ->where('instance_id', self::INSTANCE_ID)
            ->update([
                'supported_models' => json_encode($models, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

        $this->clearCaches();
    }

    private function refreshOllamaModelRows(Carbon $now): void
    {
        if (! Schema::hasTable('ollama_models')) {
            return;
        }

        $instanceId = DB::table('llm_instances')
            ->where('instance_id', self::INSTANCE_ID)
            ->value('id');

        if (! $instanceId) {
            return;
        }

        $testingRows = [
            'huihui_ai/qwen2.5-vl-abliterated:7b' => [
                'display_name' => 'Huihui Qwen2.5-VL 7B Abliterated',
                'profile' => 'uncensored_multimodal',
                'capabilities' => ['text_input', 'text_output', 'image_input', 'image_to_text', 'ocr_text_extraction', 'vision', 'uncensored', 'operator_uncensored', 'local_candidate'],
                'use_cases' => ['private_uncensored_image_review_candidate', 'ocr_assist_candidate', 'visual_qa_candidate'],
                'vetting_notes' => '2026-06-09 RTS: pulled to .87 and benign OCR smoke passed (read PLOS 42). Cold load was about 51s, slower than Qwen3-VL abliterated; keep override-only candidate.',
                'quality_rating' => 82,
            ],
            'huihui_ai/qwen3-abliterated:8b' => [
                'display_name' => 'Huihui Qwen3 8B Abliterated',
                'profile' => 'uncensored_text_qwen',
                'capabilities' => ['text_input', 'text_output', 'private_uncensored_text', 'uncensored', 'operator_uncensored', 'local_candidate'],
                'use_cases' => ['private_uncensored_text_candidate', 'operator_text_review_candidate'],
                'vetting_notes' => '2026-06-09 RTS: pulled to .87 and text smoke passed. Emits thinking before final content; avoid tiny output caps and keep override-only candidate.',
                'quality_rating' => 80,
            ],
        ];

        foreach ($testingRows as $modelName => $payload) {
            $key = [
                'instance_id' => $instanceId,
                'model_name' => $modelName,
            ];
            $modelPayload = [
                'display_name' => $payload['display_name'],
                'profile' => $payload['profile'],
                'status' => 'testing',
                'is_available' => 1,
                'capabilities' => json_encode($payload['capabilities'], JSON_UNESCAPED_SLASHES),
                'use_cases' => json_encode($payload['use_cases'], JSON_UNESCAPED_SLASHES),
                'description' => $payload['vetting_notes'],
                'quality_rating' => $payload['quality_rating'],
                'vetting_notes' => $payload['vetting_notes'],
                'vetted_by' => 'codex-rts',
                'last_seen_at' => $now,
                'updated_at' => $now,
            ];

            if (! DB::table('ollama_models')->where($key)->exists()) {
                $modelPayload['first_seen_at'] = $now;
                $modelPayload['created_at'] = $now;
            }

            DB::table('ollama_models')->updateOrInsert($key, $modelPayload);
        }

        DB::table('ollama_models')
            ->where('instance_id', $instanceId)
            ->whereIn('model_name', self::REMOVE_MODELS)
            ->update([
                'status' => 'unavailable',
                'is_available' => 0,
                'vetting_notes' => '2026-06-09 RTS: .87 text-only and image-input smokes both failed with unable-to-load blob error; model was removed from .87 Ollama. Keep unavailable and hidden.',
                'updated_at' => $now,
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function decodeList(mixed $json): array
    {
        if (is_array($json)) {
            return array_values(array_unique(array_map('strval', $json)));
        }

        if (! is_string($json) || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded)
            ? array_values(array_unique(array_map('strval', $decoded)))
            : [];
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
