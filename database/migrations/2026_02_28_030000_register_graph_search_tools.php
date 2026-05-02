<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tools = [
            [
                'name' => 'graph_local_search',
                'service_class' => 'App\\Services\\GraphSearchService',
                'method' => 'localSearch',
                'description' => 'Search knowledge graph using entity-centric BFS traversal. Best for specific entity queries.',
                'parameters' => json_encode([
                    'query' => ['type' => 'string', 'required' => true, 'description' => 'Search query text'],
                    'limit' => ['type' => 'int', 'default' => 10, 'description' => 'Max results'],
                ]),
                'returns_description' => 'Array of documents with graph scores and matched entities',
                'permissions' => json_encode([]),
                'risk_level' => 'read',
                'category' => 'rag',
                'max_calls_per_run' => 5,
                'source' => 'config',
            ],
            [
                'name' => 'graph_global_search',
                'service_class' => 'App\\Services\\GraphSearchService',
                'method' => 'globalSearch',
                'description' => 'Search community reports for broad/thematic queries using cosine similarity on report embeddings.',
                'parameters' => json_encode([
                    'query' => ['type' => 'string', 'required' => true, 'description' => 'Search query text'],
                    'limit' => ['type' => 'int', 'default' => 5, 'description' => 'Max results'],
                ]),
                'returns_description' => 'Array of community reports with similarity scores',
                'permissions' => json_encode([]),
                'risk_level' => 'read',
                'category' => 'rag',
                'max_calls_per_run' => 5,
                'source' => 'config',
            ],
            [
                'name' => 'graph_drift_search',
                'service_class' => 'App\\Services\\GraphSearchService',
                'method' => 'driftSearch',
                'description' => 'Hybrid global-to-local search: finds community context, extracts entities, then BFS from those entities.',
                'parameters' => json_encode([
                    'query' => ['type' => 'string', 'required' => true, 'description' => 'Search query text'],
                    'limit' => ['type' => 'int', 'default' => 10, 'description' => 'Max results'],
                ]),
                'returns_description' => 'Array of documents from RRF fusion of global and local graph search',
                'permissions' => json_encode([]),
                'risk_level' => 'read',
                'category' => 'rag',
                'max_calls_per_run' => 3,
                'source' => 'config',
            ],
        ];

        foreach ($tools as $tool) {
            DB::insert("
                INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description, permissions, risk_level, category, max_calls_per_run, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    service_class = VALUES(service_class),
                    method = VALUES(method),
                    description = VALUES(description),
                    parameters = VALUES(parameters),
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
                $tool['max_calls_per_run'],
                $tool['source'],
            ]);
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ('graph_local_search', 'graph_global_search', 'graph_drift_search')");
    }
};
