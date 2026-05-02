<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tools = [
            [
                'name' => 'graph_build_document',
                'service_class' => 'App\\Services\\KnowledgeGraphService',
                'method' => 'buildFromDocument',
                'description' => 'Extract entities and relationships from a specific RAG document into the knowledge graph. Use to target high-value documents or fill gaps.',
                'parameters' => json_encode([
                    'documentId' => ['type' => 'int', 'required' => true, 'description' => 'RAG document ID to extract entities from'],
                ]),
                'returns_description' => 'Object with success, document_id, entities_extracted, triples_created',
                'permissions' => json_encode(['rag:write']),
                'risk_level' => 'write',
                'category' => 'rag',
                'max_calls_per_run' => 10,
            ],
            [
                'name' => 'graph_community_stats',
                'service_class' => 'App\\Services\\CommunityDetectionService',
                'method' => 'getStatistics',
                'description' => 'Get community detection statistics: total communities, levels, sizes, modularity, bridge entities, entity coverage, last run info.',
                'parameters' => json_encode([]),
                'returns_description' => 'Object with community stats, entity stats, report count, bridge entities, last run details',
                'permissions' => json_encode([]),
                'risk_level' => 'read',
                'category' => 'rag',
                'max_calls_per_run' => 3,
            ],
            [
                'name' => 'graph_find_duplicates',
                'service_class' => 'App\\Services\\KnowledgeGraphService',
                'method' => 'findDuplicateEntities',
                'description' => 'Find potential duplicate entities in the knowledge graph using fuzzy name matching. Returns candidate pairs for merge review.',
                'parameters' => json_encode([
                    'options' => ['type' => 'array', 'default' => [], 'description' => 'Options: similarity_threshold (0.8), limit (20), entity_type (null)'],
                ]),
                'returns_description' => 'Array of entity pairs with similarity scores',
                'permissions' => json_encode(['rag:read']),
                'risk_level' => 'read',
                'category' => 'rag',
                'max_calls_per_run' => 3,
            ],
            [
                'name' => 'graph_merge_entities',
                'service_class' => 'App\\Services\\KnowledgeGraphService',
                'method' => 'mergeEntities',
                'description' => 'Merge two duplicate entities — transfers all relationships from source to target and deletes source. Irreversible.',
                'parameters' => json_encode([
                    'sourceId' => ['type' => 'int', 'required' => true, 'description' => 'Entity ID to merge FROM (will be deleted)'],
                    'targetId' => ['type' => 'int', 'required' => true, 'description' => 'Entity ID to merge INTO (will be kept)'],
                ]),
                'returns_description' => 'Boolean success status',
                'permissions' => json_encode(['rag:write']),
                'risk_level' => 'write',
                'category' => 'rag',
                'requires_confirmation' => 0,
                'max_calls_per_run' => 10,
            ],
            [
                'name' => 'graph_community_report',
                'service_class' => 'App\\Services\\CommunityDetectionService',
                'method' => 'getCommunity',
                'description' => 'Get a community with its entities, relationships, report summary, and child communities. Use to inspect community structure.',
                'parameters' => json_encode([
                    'communityId' => ['type' => 'int', 'required' => true, 'description' => 'Community DB ID'],
                ]),
                'returns_description' => 'Object with community details, member entities, report, children',
                'permissions' => json_encode([]),
                'risk_level' => 'read',
                'category' => 'rag',
                'max_calls_per_run' => 5,
            ],
            [
                'name' => 'graph_redetect_communities',
                'service_class' => 'App\\Services\\CommunityDetectionService',
                'method' => 'detectCommunities',
                'description' => 'Trigger full community re-detection with Leiden algorithm. Use after significant KG growth (>20% entity increase). Expensive operation.',
                'parameters' => json_encode([
                    'options' => ['type' => 'array', 'default' => [], 'description' => 'Options: resolutions ([1.0,0.5,0.25]), min_community_size (2), force_rebuild (true)'],
                ]),
                'returns_description' => 'Object with run_id, communities_detected, levels, duration_ms',
                'permissions' => json_encode(['rag:write']),
                'risk_level' => 'write',
                'category' => 'rag',
                'requires_confirmation' => 0,
                'max_calls_per_run' => 1,
            ],
            [
                'name' => 'graph_entity_search',
                'service_class' => 'App\\Services\\KnowledgeGraphService',
                'method' => 'searchEntities',
                'description' => 'Search for entities by name (including aliases). Use to look up entities before merging or building.',
                'parameters' => json_encode([
                    'query' => ['type' => 'string', 'required' => true, 'description' => 'Entity name to search for'],
                    'options' => ['type' => 'array', 'default' => [], 'description' => 'Options: types (array), limit (20)'],
                ]),
                'returns_description' => 'Array of matching entities with id, name, type, aliases',
                'permissions' => json_encode([]),
                'risk_level' => 'read',
                'category' => 'rag',
                'max_calls_per_run' => 10,
            ],
            [
                'name' => 'graph_stats',
                'service_class' => 'App\\Services\\KnowledgeGraphService',
                'method' => 'getStatistics',
                'description' => 'Get knowledge graph statistics: total entities, triples, average confidence, top predicates, entity type distribution.',
                'parameters' => json_encode([]),
                'returns_description' => 'Object with total_triples, total_entities, average_confidence, top_predicates, entity_types',
                'permissions' => json_encode([]),
                'risk_level' => 'read',
                'category' => 'rag',
                'max_calls_per_run' => 3,
            ],
        ];

        foreach ($tools as $tool) {
            DB::insert("
                INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description, permissions, risk_level, category, requires_confirmation, max_calls_per_run, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'config')
                ON DUPLICATE KEY UPDATE
                    service_class = VALUES(service_class),
                    method = VALUES(method),
                    description = VALUES(description),
                    parameters = VALUES(parameters),
                    returns_description = VALUES(returns_description),
                    updated_at = NOW()
            ", [
                $tool['name'],
                $tool['service_class'],
                $tool['method'],
                $tool['description'],
                $tool['parameters'],
                $tool['returns_description'],
                $tool['permissions'],
                $tool['risk_level'],
                $tool['category'],
                $tool['requires_confirmation'] ?? 0,
                $tool['max_calls_per_run'],
            ]);
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN (
            'graph_build_document', 'graph_community_stats', 'graph_find_duplicates',
            'graph_merge_entities', 'graph_community_report', 'graph_redetect_communities',
            'graph_entity_search', 'graph_stats'
        )");
    }
};
