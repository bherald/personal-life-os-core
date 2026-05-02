<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add context_length to llm_instances for pre-validation of input sizes.
     *
     * Prevents context-overflow errors (especially embedding failures) by allowing
     * AIService to check text length before sending to providers. Values are in tokens.
     */
    public function up(): void
    {
        Schema::table('llm_instances', function (Blueprint $table) {
            $table->unsignedInteger('context_length')->nullable()
                ->after('supported_models')
                ->comment('Max context window in tokens for this provider');
            $table->unsignedInteger('embedding_context_length')->nullable()
                ->after('context_length')
                ->comment('Max embedding input in tokens (if different from context_length)');
        });

        // Populate optimal context lengths for all existing instances
        $this->populateContextLengths();
    }

    private function populateContextLengths(): void
    {
        $lengths = [
            // Ollama local instances - context_length for text models, embedding for nomic-embed-text
            'ollama_primary' => [
                'context_length' => 8192,       // llama3.1:8b default context
                'embedding_context_length' => 8192, // nomic-embed-text max
            ],
            'ollama_secondary' => [
                'context_length' => 8192,
                'embedding_context_length' => 8192,
            ],

            // SambaNova - Llama 3.1/3.3 models
            'sambanova_free' => [
                'context_length' => 128000,     // Llama-3.1-70B supports 128K
                'embedding_context_length' => null, // No embedding capability
            ],

            // Cerebras
            'cerebras_free' => [
                'context_length' => 128000,     // Llama 3.1-8b on Cerebras: 128K
                'embedding_context_length' => null,
            ],

            // Groq
            'groq_free' => [
                'context_length' => 128000,     // llama-3.3-70b-versatile: 128K
                'embedding_context_length' => null,
            ],

            // OpenRouter - varies by model, use lowest common denominator
            'openrouter_free' => [
                'context_length' => 128000,     // Most free models support 128K+
                'embedding_context_length' => null,
            ],

            // DeepInfra
            'deepinfra_free' => [
                'context_length' => 128000,     // Llama-3.3-70B: 128K
                'embedding_context_length' => 8192, // BAAI/bge-base default
            ],

            // Claude CLI - Claude Sonnet 4 / Opus 4
            'claude_cli' => [
                'context_length' => 200000,     // Claude 200K context window
                'embedding_context_length' => null, // No embedding via CLI
            ],

            // Gemini
            'gemini_free' => [
                'context_length' => 1048576,    // Gemini 2.5 Flash: 1M tokens
                'embedding_context_length' => 2048, // text-embedding-004: 2048 tokens
            ],

            // Mistral
            'mistral_free' => [
                'context_length' => 128000,     // mistral-large-latest: 128K
                'embedding_context_length' => 8192, // mistral-embed: 8K
            ],
        ];

        foreach ($lengths as $instanceId => $values) {
            DB::table('llm_instances')
                ->where('instance_id', $instanceId)
                ->update([
                    'context_length' => $values['context_length'],
                    'embedding_context_length' => $values['embedding_context_length'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('llm_instances', function (Blueprint $table) {
            $table->dropColumn(['context_length', 'embedding_context_length']);
        });
    }
};
