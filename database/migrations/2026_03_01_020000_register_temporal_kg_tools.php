<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GR-1: Register bi-temporal knowledge graph agent tools.
 *
 * Adds 4 tools for temporal KG operations to agent_tool_registry (MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        // graph_invalidate_triple — soft-delete a KG edge
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, enabled, risk_level, category, source, notes, created_at, updated_at)
            VALUES
                ('graph_invalidate_triple', 'App\\\\Services\\\\KnowledgeGraphService', 'invalidateTriple',
                 'Soft-delete (invalidate) a knowledge graph edge. Sets t_expired timestamp, records in edge history. Use when a fact is no longer true or has been superseded.',
                 '{\"id\": {\"type\": \"integer\", \"required\": true, \"description\": \"Triple ID to invalidate\"}, \"reason\": {\"type\": \"string\", \"required\": false, \"description\": \"Reason for invalidation\"}, \"superseded_by\": {\"type\": \"integer\", \"required\": false, \"description\": \"ID of replacement triple\"}}',
                 '[\"rag:write\"]', 1, 'write', 'rag', 'manual',
                 'Soft-delete only — edge remains in DB with t_expired set. Reversible via graph_restore_triple (not yet registered). Records audit trail in knowledge_graph_edge_history.',
                 NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                service_class = VALUES(service_class),
                method = VALUES(method),
                description = VALUES(description),
                parameters = VALUES(parameters),
                risk_level = VALUES(risk_level),
                notes = VALUES(notes),
                updated_at = NOW()
        ");

        // graph_query_temporal — point-in-time relationship query
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, enabled, risk_level, category, source, notes, created_at, updated_at)
            VALUES
                ('graph_query_temporal', 'App\\\\Services\\\\KnowledgeGraphService', 'findRelationshipsAsOf',
                 'Query knowledge graph relationships as they existed at a specific point in time. Returns edges that were active (not yet expired) at the given date.',
                 '{\"entity\": {\"type\": \"string\", \"required\": true, \"description\": \"Entity name to search\"}, \"as_of_date\": {\"type\": \"string\", \"required\": true, \"description\": \"ISO date for point-in-time query (e.g. 2025-06-15)\"}, \"direction\": {\"type\": \"string\", \"required\": false, \"description\": \"outgoing, incoming, or both (default)\"}, \"min_confidence\": {\"type\": \"number\", \"required\": false, \"description\": \"Minimum confidence threshold\"}}',
                 '[\"rag:read\"]', 1, 'read', 'rag', 'manual',
                 'Read-only temporal query. Uses transaction time (created_at/t_expired) to reconstruct graph state at a past moment.',
                 NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                service_class = VALUES(service_class),
                method = VALUES(method),
                description = VALUES(description),
                parameters = VALUES(parameters),
                risk_level = VALUES(risk_level),
                notes = VALUES(notes),
                updated_at = NOW()
        ");

        // graph_edge_history — view change history for a triple
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, enabled, risk_level, category, source, notes, created_at, updated_at)
            VALUES
                ('graph_edge_history', 'App\\\\Services\\\\KnowledgeGraphService', 'getEdgeHistory',
                 'View the full change history (audit trail) for a knowledge graph triple. Shows creation, invalidation, supersession, and restoration events.',
                 '{\"triple_id\": {\"type\": \"integer\", \"required\": true, \"description\": \"Triple ID to get history for\"}}',
                 '[\"rag:read\"]', 1, 'read', 'rag', 'manual',
                 'Read-only audit trail from knowledge_graph_edge_history table.',
                 NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                service_class = VALUES(service_class),
                method = VALUES(method),
                description = VALUES(description),
                parameters = VALUES(parameters),
                risk_level = VALUES(risk_level),
                notes = VALUES(notes),
                updated_at = NOW()
        ");

        // graph_temporal_stats — temporal coverage statistics
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, enabled, risk_level, category, source, notes, created_at, updated_at)
            VALUES
                ('graph_temporal_stats', 'App\\\\Services\\\\KnowledgeGraphService', 'getTemporalStats',
                 'Get temporal coverage statistics for the knowledge graph: active/expired counts, temporal type distribution, stale candidates (valid_until in past but not expired), coverage percentage.',
                 '{}',
                 '[\"rag:read\"]', 1, 'read', 'rag', 'manual',
                 'Read-only temporal health assessment. Stale candidates are edges where valid_until < NOW() but t_expired IS NULL — facts that may have expired in the real world but KG hasnt caught up.',
                 NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                service_class = VALUES(service_class),
                method = VALUES(method),
                description = VALUES(description),
                parameters = VALUES(parameters),
                risk_level = VALUES(risk_level),
                notes = VALUES(notes),
                updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM agent_tool_registry WHERE name IN ('graph_invalidate_triple', 'graph_query_temporal', 'graph_edge_history', 'graph_temporal_stats')");
    }
};
