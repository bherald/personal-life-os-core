<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N99 — Graph Deduplication + N100 — Repository Routing
 *
 * Registers agent tools for:
 * - GraphDeduplicationService (BYU Wilson 2001 method)
 * - RepositoryRoutingService (era × geography matrix)
 */
return new class extends Migration
{
    public function up(): void
    {
        $tools = [
            [
                'name'          => 'find_graph_duplicates',
                'description'   => 'N99 — Find potential duplicate persons using graph-anchor deduplication (BYU Wilson 2001). Common-name persons that share rare-surname relatives are candidate duplicates. Returns scored pairs for human review.',
                'service_class' => 'App\\Services\\Genealogy\\GraphDeduplicationService',
                'method'   => 'findGraphDuplicates',
                'parameters'    => json_encode([
                    'tree_id' => ['type' => 'integer', 'required' => true,  'description' => 'Family tree ID'],
                    'limit'   => ['type' => 'integer', 'required' => false, 'description' => 'Max candidates to return (default 50)'],
                ]),
                'permissions'   => json_encode([]),
                'enabled'       => 1,
            ],
            [
                'name'          => 'get_repositories_for_person',
                'description'   => 'N100 — Return a prioritized list of genealogy repositories tailored to the era and geographic region of a specific person. Guides search strategy by suggesting the highest-yield sources before searching.',
                'service_class' => 'App\\Services\\Genealogy\\RepositoryRoutingService',
                'method'   => 'getRepositoriesForPerson',
                'parameters'    => json_encode([
                    'person_id' => ['type' => 'integer', 'required' => true, 'description' => 'Person ID to route repositories for'],
                ]),
                'permissions'   => json_encode([]),
                'enabled'       => 1,
            ],
        ];

        foreach ($tools as $tool) {
            DB::statement("
                INSERT INTO agent_tool_registry
                    (name, description, service_class, method, parameters, permissions, enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    description   = VALUES(description),
                    service_class = VALUES(service_class),
                    method   = VALUES(method),
                    parameters    = VALUES(parameters),
                    permissions   = VALUES(permissions),
                    enabled       = VALUES(enabled)
            ", [
                $tool['name'],
                $tool['description'],
                $tool['service_class'],
                $tool['method'],
                $tool['parameters'],
                $tool['permissions'],
                $tool['enabled'],
            ]);
        }
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->whereIn('name', ['find_graph_duplicates', 'get_repositories_for_person'])
            ->delete();
    }
};
