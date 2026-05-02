<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Add role-based model configuration to all LLM providers.
 *
 * Adds a `models` object to each provider's config JSON mapping roles to model names:
 *   - standard: general-purpose, agents, content processing
 *   - fast:     quick classification, tagging, short completions
 *   - quality:  research synthesis, complex reasoning (uses best model)
 *   - vision:   image analysis (where supported)
 *
 * Also updates claude_cli priority to be read from DB (was hardcoded to 20 in buildFallbackChain).
 */
return new class extends Migration
{
    private array $providerModels = [
        'claude_cli' => [
            'standard' => 'sonnet',
            'fast'     => 'haiku',
            'quality'  => 'opus',
            'vision'   => 'sonnet',
        ],
        'groq_free' => [
            'standard' => 'llama-3.3-70b-versatile',
            'fast'     => 'llama-3.1-8b-instant',
            'quality'  => 'llama-3.3-70b-versatile',
        ],
        'openrouter_free' => [
            'standard' => 'deepseek/deepseek-r1:free',
            'fast'     => 'meta-llama/llama-4-scout:free',
            'quality'  => 'deepseek/deepseek-r1:free',
            'vision'   => 'google/gemma-3-27b-it:free',
        ],
        'mistral_free' => [
            'standard' => 'mistral-small-latest',
            'fast'     => 'mistral-small-latest',
            'quality'  => 'mistral-large-latest',
            'vision'   => 'pixtral-12b-2409',
        ],
        'gemini_free' => [
            'standard' => 'gemini-2.5-flash',
            'fast'     => 'gemini-2.0-flash-lite',
            'quality'  => 'gemini-2.5-flash',
            'vision'   => 'gemini-2.0-flash',
        ],
        'deepinfra_free' => [
            'standard' => 'meta-llama/Llama-3.3-70B-Instruct',
            'fast'     => 'meta-llama/Meta-Llama-3.1-8B-Instruct',
            'quality'  => 'meta-llama/Llama-3.3-70B-Instruct',
        ],
        'sambanova_free' => [
            'standard' => 'Meta-Llama-3.3-70B-Instruct',
            'fast'     => 'Meta-Llama-3.1-8B-Instruct',
            'quality'  => 'Meta-Llama-3.3-70B-Instruct',
        ],
        'cerebras_free' => [
            'standard' => 'llama-3.3-70b',
            'fast'     => 'llama3.1-8b',
            'quality'  => 'llama-3.3-70b',
        ],
    ];

    public function up(): void
    {
        foreach ($this->providerModels as $instanceId => $models) {
            $row = DB::selectOne("SELECT config FROM llm_instances WHERE instance_id = ?", [$instanceId]);
            if (!$row) {
                Log::info("Role model migration: instance {$instanceId} not found, skipping");
                continue;
            }

            $config = json_decode($row->config ?? '{}', true) ?: [];
            $config['models'] = $models;

            DB::update(
                "UPDATE llm_instances SET config = ?, updated_at = NOW() WHERE instance_id = ?",
                [json_encode($config), $instanceId]
            );

            Log::info("Role model migration: updated {$instanceId} with " . count($models) . " roles");
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->providerModels) as $instanceId) {
            $row = DB::selectOne("SELECT config FROM llm_instances WHERE instance_id = ?", [$instanceId]);
            if (!$row) {
                continue;
            }

            $config = json_decode($row->config ?? '{}', true) ?: [];
            unset($config['models']);

            DB::update(
                "UPDATE llm_instances SET config = ?, updated_at = NOW() WHERE instance_id = ?",
                [json_encode($config), $instanceId]
            );
        }
    }
};
