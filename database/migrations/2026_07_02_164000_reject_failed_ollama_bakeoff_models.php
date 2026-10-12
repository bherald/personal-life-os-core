<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REJECTED_MODELS = [
        'ollama_jetson' => [
            'phi4-mini:3.8b' => '2026-07-02 RTC: rejected and removed from Jetson. Exact-output smoke passed, but PLOS eval-run passed only 1/9 cases and Codex command_execution was not reliable.',
            'llama3.2:3b' => '2026-07-02 RTC: rejected and removed from Jetson. Exact-output smoke passed, but PLOS eval-run passed 0/9 cases with prose wrappers/schema drift and prompt-injection failures.',
            'deepcoder:1.5b' => '2026-07-02 RTC: rejected and removed from Jetson. Failed exact-output discipline and Codex command_execution gate.',
            'nuextract:3.8b' => '2026-07-02 RTC: rejected and removed from Jetson. Failed exact-output discipline and structured extraction smoke repeated JSON fragments/template markers.',
            'bge-m3:567m' => '2026-07-02 RTC: rejected and removed from Jetson for current PLOS routing. Embedding smoke ranked the fixture correctly but returns 1024 dimensions; current PLOS RAG/pgvector path expects 768-dimensional embeddinggemma vectors.',
        ],
        'ollama_secondary' => [
            'gpt-oss:20b' => '2026-07-02 RTC: rejected and removed from .87. Exact-output passed only with think=false, but PLOS eval-run passed 0/9 cases with empty strict-JSON outputs, schema drift, prompt-injection leakage, and high latency.',
            'mistral-small3.2:24b' => '2026-07-02 RTC: rejected and removed from .87. Exact-output smoke returned a tool-unavailable refusal instead of the requested marker.',
            'magistral:24b' => '2026-07-02 RTC: rejected and removed from .87. Exact-output smoke returned explanatory/thinking text instead of the requested marker.',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        foreach (self::REJECTED_MODELS as $instanceId => $models) {
            $this->removeSupportedModels($instanceId, array_keys($models));
            $this->markModelsUnavailable($instanceId, $models);
        }

        $this->clearCaches();
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        foreach (self::REJECTED_MODELS as $instanceId => $models) {
            $this->restoreSupportedModels($instanceId, array_keys($models));
        }

        $this->clearCaches();
    }

    /**
     * @param  array<int,string>  $models
     */
    private function removeSupportedModels(string $instanceId, array $models): void
    {
        $row = DB::table('llm_instances')
            ->where('instance_id', $instanceId)
            ->first(['supported_models']);

        if (! $row) {
            return;
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

    /**
     * @param  array<string,string>  $models
     */
    private function markModelsUnavailable(string $instanceId, array $models): void
    {
        if (! Schema::hasTable('ollama_models')) {
            return;
        }

        $dbInstanceId = DB::table('llm_instances')
            ->where('instance_id', $instanceId)
            ->value('id');

        if (! $dbInstanceId) {
            return;
        }

        foreach ($models as $modelName => $reason) {
            DB::table('ollama_models')
                ->where('instance_id', $dbInstanceId)
                ->where('model_name', $modelName)
                ->update([
                    'status' => 'unavailable',
                    'is_available' => 0,
                    'description' => $reason,
                    'vetting_notes' => $reason,
                    'vetted_by' => 'codex-rtc',
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  array<int,string>  $models
     */
    private function restoreSupportedModels(string $instanceId, array $models): void
    {
        $row = DB::table('llm_instances')
            ->where('instance_id', $instanceId)
            ->first(['supported_models']);

        if (! $row) {
            return;
        }

        $supported = $this->decodeList($row->supported_models ?? '[]');
        foreach ($models as $model) {
            if (! in_array($model, $supported, true)) {
                $supported[] = $model;
            }
        }

        DB::table('llm_instances')
            ->where('instance_id', $instanceId)
            ->update([
                'supported_models' => json_encode($supported, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
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
