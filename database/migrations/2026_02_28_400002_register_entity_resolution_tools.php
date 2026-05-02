<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description,
                 permissions, risk_level, category, requires_confirmation, enabled, source, created_at, updated_at)
            VALUES
                ('entity_resolve_candidates', 'App\\\\Services\\\\EntityResolutionService', 'findCandidates',
                 'Find candidate duplicate entity pairs using embedding similarity (ANN). Returns pairs with similarity scores.',
                 '{\"limit\": {\"type\": \"integer\", \"default\": 50, \"description\": \"Max entities to scan\"},
                   \"entity_type\": {\"type\": \"string\", \"description\": \"Filter by entity type\"},
                   \"min_similarity\": {\"type\": \"number\", \"default\": 0.75, \"description\": \"Minimum similarity threshold\"}}',
                 'Array of candidate pairs with entity IDs, names, types, and similarity scores',
                 '[\"rag:read\"]', 'read', 'knowledge_graph', 0, 1, 'manual', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                parameters = VALUES(parameters),
                updated_at = NOW()
        ");

        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description,
                 permissions, risk_level, category, requires_confirmation, enabled, source, created_at, updated_at)
            VALUES
                ('entity_resolve_stats', 'App\\\\Services\\\\EntityResolutionService', 'getStatistics',
                 'Get entity resolution statistics: embedding coverage, recent runs, 7-day merge totals, pending reviews.',
                 '{}',
                 'Statistics object with coverage, run history, and merge totals',
                 '[\"rag:read\"]', 'read', 'knowledge_graph', 0, 1, 'manual', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM agent_tool_registry WHERE name IN ('entity_resolve_candidates', 'entity_resolve_stats')");
    }
};
