<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ADD_MODELS = [
        'ollama_jetson' => [
            'phi4-mini:3.8b',
            'llama3.2:3b',
            'deepcoder:1.5b',
            'nuextract:3.8b',
            'bge-m3:567m',
        ],
        'ollama_secondary' => [
            'gpt-oss:20b',
            'mistral-small3.2:24b',
            'magistral:24b',
        ],
    ];

    private const INSTANCE_NOTES = [
        'ollama_jetson' => '2026-07-02 RTC: Jetson bakeoff added phi4-mini:3.8b, llama3.2:3b, deepcoder:1.5b, nuextract:3.8b, and bge-m3:567m as installed candidate inventory. Offline dev routing stays on qwen3-coder:30b because the smaller models did not emit Codex command_execution events.',
        'ollama_secondary' => '2026-07-02 RTC: .87 bakeoff added gpt-oss:20b, mistral-small3.2:24b, and magistral:24b as installed bench inventory. Daily role mappings stay unchanged because exact-output smokes failed.',
    ];

    private const MODEL_ROWS = [
        'ollama_jetson' => [
            'phi4-mini:3.8b' => [
                'display_name' => 'Phi-4 Mini 3.8B',
                'profile' => 'fast_text_candidate',
                'capabilities' => ['text_input', 'text_output', 'tool_use', 'local_candidate', 'jetson_candidate'],
                'use_cases' => ['manual_offline_text_candidate', 'jetson_fast_response_candidate', 'tool_call_retest_candidate'],
                'notes' => '2026-07-02 RTC: Jetson exact-output smoke passed, but Codex offline smoke narrated shell steps without real command_execution events. Candidate-only; do not route offline dev.',
                'quality_rating' => 58,
                'size_gb' => 2.32,
                'context_length' => 131072,
                'vram_required_mb' => 2800,
                'avg_response_time_ms' => 8380,
            ],
            'llama3.2:3b' => [
                'display_name' => 'Llama 3.2 3B',
                'profile' => 'tiny_text_candidate',
                'capabilities' => ['text_input', 'text_output', 'tool_use', 'local_candidate', 'jetson_candidate'],
                'use_cases' => ['manual_offline_text_candidate', 'jetson_low_memory_fallback_candidate'],
                'notes' => '2026-07-02 RTC: Jetson exact-output smoke passed, but Codex command_execution smoke timed out without a tool event. Candidate-only.',
                'quality_rating' => 54,
                'size_gb' => 1.88,
                'context_length' => 131072,
                'vram_required_mb' => 2300,
                'avg_response_time_ms' => 11360,
            ],
            'deepcoder:1.5b' => [
                'display_name' => 'DeepCoder 1.5B',
                'profile' => 'tiny_coding_candidate',
                'capabilities' => ['text_input', 'text_output', 'code_generation', 'local_candidate', 'jetson_candidate'],
                'use_cases' => ['manual_coding_comparison_only'],
                'notes' => '2026-07-02 RTC: Jetson exact-output smoke failed and Codex command_execution smoke timed out without a tool event. Installed for comparison only; do not route.',
                'quality_rating' => 28,
                'size_gb' => 1.04,
                'context_length' => 131072,
                'vram_required_mb' => 1400,
                'avg_response_time_ms' => null,
            ],
            'nuextract:3.8b' => [
                'display_name' => 'NuExtract 3.8B',
                'profile' => 'extraction_candidate',
                'capabilities' => ['text_input', 'text_output', 'structured_output_candidate', 'local_candidate', 'jetson_candidate'],
                'use_cases' => ['manual_extraction_comparison_only', 'genealogy_extraction_retest_candidate'],
                'notes' => '2026-07-02 RTC: Jetson extraction smoke repeated JSON fragments and emitted template markers. Keep out of production Genea extraction until strict JSON behavior is proven.',
                'quality_rating' => 36,
                'size_gb' => 2.03,
                'context_length' => 4096,
                'vram_required_mb' => 2500,
                'avg_response_time_ms' => null,
            ],
            'bge-m3:567m' => [
                'display_name' => 'BGE-M3 567M',
                'profile' => 'embedding_candidate',
                'capabilities' => ['embedding', 'text_embedding', 'similarity', 'local_candidate', 'jetson_candidate'],
                'use_cases' => ['offline_rag_embedding_candidate', 'genealogy_rag_comparison_candidate', 'multilingual_embedding_candidate'],
                'notes' => '2026-07-02 RTC: Jetson embedding API smoke passed with 1024-dimension embeddings. Candidate for offline RAG comparison against embeddinggemma; not default until retrieval quality is compared.',
                'quality_rating' => 72,
                'size_gb' => 1.08,
                'context_length' => 8192,
                'vram_required_mb' => 1200,
                'avg_response_time_ms' => null,
            ],
        ],
        'ollama_secondary' => [
            'gpt-oss:20b' => [
                'display_name' => 'GPT-OSS 20B',
                'profile' => 'reasoning_bench',
                'capabilities' => ['text_input', 'text_output', 'reasoning_candidate', 'local_candidate', 'secondary_candidate'],
                'use_cases' => ['manual_reasoning_bench_only', 'tool_call_retest_candidate'],
                'notes' => '2026-07-02 RTC: .87 exact-output smoke returned thinking with empty final content under a tiny output cap. Bench-only; do not route daily/offline roles.',
                'quality_rating' => 34,
                'size_gb' => 12.85,
                'context_length' => null,
                'vram_required_mb' => 14000,
                'avg_response_time_ms' => 29460,
            ],
            'mistral-small3.2:24b' => [
                'display_name' => 'Mistral Small 3.2 24B',
                'profile' => 'heavy_text_bench',
                'capabilities' => ['text_input', 'text_output', 'vision_candidate', 'tool_use_candidate', 'local_candidate', 'secondary_candidate'],
                'use_cases' => ['manual_heavy_text_bench_only', 'vision_tool_retest_candidate'],
                'notes' => '2026-07-02 RTC: .87 exact-output smoke returned a tool-unavailable refusal instead of the requested marker. Bench-only; do not route daily/offline roles.',
                'quality_rating' => 30,
                'size_gb' => 14.14,
                'context_length' => null,
                'vram_required_mb' => 15500,
                'avg_response_time_ms' => 30970,
            ],
            'magistral:24b' => [
                'display_name' => 'Magistral 24B',
                'profile' => 'reasoning_bench',
                'capabilities' => ['text_input', 'text_output', 'reasoning_candidate', 'local_candidate', 'secondary_candidate'],
                'use_cases' => ['manual_reasoning_bench_only', 'tool_call_retest_candidate'],
                'notes' => '2026-07-02 RTC: .87 exact-output smoke returned thinking with empty final content under a tiny output cap. Bench-only; do not route daily/offline roles.',
                'quality_rating' => 30,
                'size_gb' => 13.35,
                'context_length' => null,
                'vram_required_mb' => 14500,
                'avg_response_time_ms' => 17130,
            ],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $now = now();

        foreach (self::ADD_MODELS as $instanceId => $models) {
            $this->addSupportedModels($instanceId, $models, self::INSTANCE_NOTES[$instanceId] ?? null);
        }

        $this->upsertOllamaRows($now);
        $this->clearCaches();
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        foreach (self::ADD_MODELS as $instanceId => $models) {
            $row = DB::table('llm_instances')
                ->where('instance_id', $instanceId)
                ->first(['supported_models']);

            if (! $row) {
                continue;
            }

            $remove = array_flip($models);
            $supported = array_values(array_filter(
                $this->decodeList($row->supported_models ?? '[]'),
                static fn (string $model): bool => ! isset($remove[$model])
            ));

            DB::table('llm_instances')
                ->where('instance_id', $instanceId)
                ->update([
                    'supported_models' => json_encode($supported, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }

        $this->clearCaches();
    }

    /**
     * @param  array<int,string>  $models
     */
    private function addSupportedModels(string $instanceId, array $models, ?string $note): void
    {
        $columns = ['supported_models'];
        if (Schema::hasColumn('llm_instances', 'notes')) {
            $columns[] = 'notes';
        }

        $row = DB::table('llm_instances')
            ->where('instance_id', $instanceId)
            ->first($columns);

        if (! $row) {
            return;
        }

        $supported = $this->decodeList($row->supported_models ?? '[]');
        foreach ($models as $model) {
            if (! in_array($model, $supported, true)) {
                $supported[] = $model;
            }
        }

        $payload = [
            'supported_models' => json_encode($supported, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ];

        if ($note !== null && Schema::hasColumn('llm_instances', 'notes')) {
            $payload['notes'] = $this->appendNoteOnce((string) ($row->notes ?? ''), $note);
        }

        DB::table('llm_instances')
            ->where('instance_id', $instanceId)
            ->update($payload);
    }

    private function upsertOllamaRows(Carbon $now): void
    {
        if (! Schema::hasTable('ollama_models')) {
            return;
        }

        foreach (self::MODEL_ROWS as $instanceKey => $models) {
            $dbInstanceId = DB::table('llm_instances')
                ->where('instance_id', $instanceKey)
                ->value('id');

            if (! $dbInstanceId) {
                continue;
            }

            foreach ($models as $modelName => $payload) {
                $key = [
                    'instance_id' => $dbInstanceId,
                    'model_name' => $modelName,
                ];
                $row = [
                    'display_name' => $payload['display_name'],
                    'profile' => $payload['profile'],
                    'status' => 'testing',
                    'is_available' => 1,
                    'capabilities' => json_encode($payload['capabilities'], JSON_UNESCAPED_SLASHES),
                    'use_cases' => json_encode($payload['use_cases'], JSON_UNESCAPED_SLASHES),
                    'description' => $payload['notes'],
                    'size_gb' => $payload['size_gb'],
                    'context_length' => $payload['context_length'],
                    'vram_required_mb' => $payload['vram_required_mb'],
                    'avg_response_time_ms' => $payload['avg_response_time_ms'],
                    'quality_rating' => $payload['quality_rating'],
                    'vetting_notes' => $payload['notes'],
                    'vetted_by' => 'codex-rtc',
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ];

                if (! DB::table('ollama_models')->where($key)->exists()) {
                    $row['first_seen_at'] = $now;
                    $row['created_at'] = $now;
                }

                DB::table('ollama_models')->updateOrInsert($key, $row);
            }
        }
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

    private function appendNoteOnce(string $notes, string $note): string
    {
        if (str_contains($notes, $note)) {
            return $notes;
        }

        return trim($notes) === '' ? $note : rtrim($notes)."\n".$note;
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
