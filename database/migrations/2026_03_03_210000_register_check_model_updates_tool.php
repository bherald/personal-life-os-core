<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne("SELECT id FROM agent_tool_registry WHERE name = 'check_model_updates'");
        if (!$exists) {
            DB::insert(
                "INSERT INTO agent_tool_registry
                    (name, service_class, method, description, parameters, returns_description,
                     permissions, risk_level, category, requires_confirmation, max_calls_per_run,
                     enabled, source, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    'check_model_updates',
                    'App\\Services\\AIOperationsService',
                    'checkModelUpdates',
                    'Discover available models on all configured LLM providers and compare against the DB. Checks Ollama /api/tags, Claude CLI version, and external API /models endpoints. Returns per-provider diff of new/deprecated models and creates a review queue item when changes are found.',
                    json_encode([]),
                    'Object with providers (per-provider diff), recommendations (list of providers with changes), and summary string',
                    json_encode(['system:read']),
                    'read',
                    'ai_operations',
                    false,
                    1,
                    true,
                    'manual',
                ]
            );
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'check_model_updates'");
    }
};
