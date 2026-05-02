<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * N48: Add embedding_model and embedding_dimensions config to external LLM providers.
 * Enables dynamic embedding fallback chain: Ollama → Gemini → DeepInfra.
 * Also activates DeepInfra (was is_active=0) since it has 768-dim embedding support.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Gemini: gemini-embedding-001 with output_dimensionality=768 (matches nomic-embed-text)
        $this->addEmbeddingConfig('gemini_free', 'gemini-embedding-001', 768);

        // DeepInfra: BAAI/bge-base-en-v1.5 natively 768 dim, 512 token context
        $this->addEmbeddingConfig('deepinfra_free', 'BAAI/bge-base-en-v1.5', 768);

        // Activate DeepInfra (has embedding capability + 768 dim)
        DB::update("UPDATE llm_instances SET is_active = 1 WHERE instance_id = 'deepinfra_free' AND is_active = 0");

        // Set DeepInfra embedding context length (512 tokens for bge-base-en-v1.5)
        DB::update("UPDATE llm_instances SET embedding_context_length = 512 WHERE instance_id = 'deepinfra_free' AND (embedding_context_length IS NULL OR embedding_context_length = 0)");
    }

    public function down(): void
    {
        $this->removeEmbeddingConfig('gemini_free');
        $this->removeEmbeddingConfig('deepinfra_free');

        DB::update("UPDATE llm_instances SET is_active = 0 WHERE instance_id = 'deepinfra_free'");
        DB::update("UPDATE llm_instances SET embedding_context_length = NULL WHERE instance_id = 'deepinfra_free'");
    }

    private function addEmbeddingConfig(string $instanceId, string $embeddingModel, int $dimensions): void
    {
        $row = DB::selectOne("SELECT config FROM llm_instances WHERE instance_id = ?", [$instanceId]);
        if (!$row) {
            Log::warning("N48 migration: instance {$instanceId} not found, skipping");
            return;
        }

        $config = json_decode($row->config ?? '{}', true) ?: [];
        $config['embedding_model'] = $embeddingModel;
        $config['embedding_dimensions'] = $dimensions;

        DB::update(
            "UPDATE llm_instances SET config = ? WHERE instance_id = ?",
            [json_encode($config), $instanceId]
        );
    }

    private function removeEmbeddingConfig(string $instanceId): void
    {
        $row = DB::selectOne("SELECT config FROM llm_instances WHERE instance_id = ?", [$instanceId]);
        if (!$row) {
            return;
        }

        $config = json_decode($row->config ?? '{}', true) ?: [];
        unset($config['embedding_model'], $config['embedding_dimensions']);

        DB::update(
            "UPDATE llm_instances SET config = ? WHERE instance_id = ?",
            [json_encode($config), $instanceId]
        );
    }
};
