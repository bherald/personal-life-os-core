<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add weekly scheduled job for model discovery via ai-ops agent.
 *
 * Runs every Sunday at 7 AM — probes Ollama /api/tags, Claude CLI version,
 * and external API /models endpoints to detect new/deprecated models.
 * Creates review queue items when diffs are found.
 *
 * Job type: agent_task — runs ai-ops skill with a targeted task description.
 * Not in the 15-min ai-ops cycle because external API calls every 15min is wasteful.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne("SELECT id FROM scheduled_jobs WHERE name = 'ai_model_discovery'");
        if (!$exists) {
            DB::insert(
                "INSERT INTO scheduled_jobs
                    (name, command, job_type, cron_expression, enabled, category,
                     timeout_minutes, description, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    'ai_model_discovery',
                    'ai-ops',
                    'agent_task',
                    '0 7 * * 0',   // Every Sunday at 7 AM
                    1,
                    'Maintenance',
                    30,
                    'Weekly LLM model discovery — probes Ollama, Claude CLI, and external APIs for new/deprecated models',
                    json_encode([
                        'task' => 'Run check_model_updates to discover new and deprecated models across all LLM providers (Ollama, Claude CLI, Groq, OpenRouter, Gemini, Mistral, DeepInfra, SambaNova, Cerebras). Report findings and create review items for any model changes detected.',
                        'notify' => false,
                    ]),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'ai_model_discovery'");
    }
};
