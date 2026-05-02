<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateInstance('ollama_primary', [
            'models' => [
                'fast' => 'gemma2:2b',
                'standard' => 'qwen3:4b',
                'quality' => 'qwen3:4b',
            ],
            'default_model' => 'qwen3:4b',
        ], 1);

        $this->updateInstance('ollama_secondary', [
            'models' => [
                'fast' => 'gemma3:4b',
                'standard' => 'qwen3:8b',
                'quality' => 'qwen3:8b',
            ],
            'default_model' => 'qwen3:8b',
        ], 1);
    }

    public function down(): void
    {
        $this->updateInstance('ollama_primary', [
            'models' => [
                'fast' => 'gemma2:2b',
                'standard' => 'qwen3:4b',
                'quality' => 'deepseek-r1:7b',
            ],
        ], 1, false);

        $this->updateInstance('ollama_secondary', [
            'models' => [
                'fast' => 'qwen3:8b',
                'standard' => 'qwen3:8b',
                'quality' => 'deepseek-r1:14b',
            ],
            'default_model' => 'llama3.1:8b-instruct-q5_K_M',
        ], 2, false);
    }

    private function updateInstance(string $instanceId, array $configPatch, int $maxConcurrent, bool $setNotes = true): void
    {
        $row = DB::selectOne(
            'SELECT id, config, notes FROM llm_instances WHERE instance_id = ? LIMIT 1',
            [$instanceId]
        );

        if (! $row) {
            return;
        }

        $config = json_decode($row->config ?? '{}', true);
        if (! is_array($config)) {
            $config = [];
        }

        $config = array_replace_recursive($config, $configPatch);

        $notes = $row->notes;
        if ($setNotes) {
            $notes = 'STABILIZED Apr12: offline-first Ollama role map shifted to installed leaner models and single concurrency for unattended reliability.';
        }

        DB::update(
            'UPDATE llm_instances
             SET config = ?, max_concurrent = ?, notes = ?, updated_at = NOW()
             WHERE id = ?',
            [json_encode($config, JSON_UNESCAPED_SLASHES), $maxConcurrent, $notes, $row->id]
        );
    }
};
