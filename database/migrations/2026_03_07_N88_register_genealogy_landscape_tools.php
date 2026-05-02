<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N88 — Register 3 new genealogy agent tools:
 *
 * - list_trees: Discover all available genealogy trees (no hardcoded tree_id needed)
 * - get_recent_searches: Load prior searches per person to avoid repetition (GPS cooldown)
 * - get_research_landscape: Aggregate coverage view — surnames, eras, unsearched persons
 *
 * These tools give the agent self-orienting capability without any hardcoded names or IDs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tools = [
            [
                'name' => 'list_trees',
                'service_class' => 'App\\Services\\Genealogy\\GenealogyService',
                'method' => 'listTrees',
                'description' => 'List all available genealogy trees with person/family/source counts. Use at run start to discover which trees exist — do not assume tree IDs.',
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => new stdClass(),
                    'required' => [],
                ]),
                'returns_description' => 'Array of trees with id, name, description, person_count, family_count, source_count',
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'requires_confirmation' => 0,
                'enabled' => 1,
                'source' => 'manual',
                'notes' => 'N88: Self-orienting tool — agent discovers trees without hardcoded IDs',
            ],
            [
                'name' => 'get_recent_searches',
                'service_class' => 'App\\Services\\Genealogy\\ResearchTaskService',
                'method' => 'getRecentSearches',
                'description' => 'Get searches performed in the last N days, grouped by person. Use in assess phase to see what was already searched and avoid repeating the same repositories on the same person.',
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'tree_id' => ['type' => 'integer', 'description' => 'Tree ID'],
                        'person_id' => ['type' => 'integer', 'description' => 'Optional: filter to one person'],
                        'days' => ['type' => 'integer', 'description' => 'Days back to look (default 30)', 'default' => 30],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 100)', 'default' => 100],
                    ],
                    'required' => ['tree_id'],
                ]),
                'returns_description' => 'tree_id, days_back, total_searches, persons_searched, by_person (person_id, person_name, searches[])',
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'requires_confirmation' => 0,
                'enabled' => 1,
                'source' => 'manual',
                'notes' => 'N88: GPS cooldown support — prevents re-searching the same dead ends',
            ],
            [
                'name' => 'get_research_landscape',
                'service_class' => 'App\\Services\\Genealogy\\GenealogyService',
                'method' => 'getResearchLandscape',
                'description' => 'Get aggregate research coverage for a tree: surname distribution, birth-era breakdown, recently researched persons, persons never searched, hint summary, and data gap counts. Use to decide who to prioritize without hardcoded names.',
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'tree_id' => ['type' => 'integer', 'description' => 'Tree ID'],
                    ],
                    'required' => ['tree_id'],
                ]),
                'returns_description' => 'surname_distribution, birth_era_distribution, recently_researched, persons_never_searched, hint_summary, data_gaps',
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'requires_confirmation' => 0,
                'enabled' => 1,
                'source' => 'manual',
                'notes' => 'N88: Self-orienting — agent discovers research gaps from data, not hardcoded targets',
            ],
        ];

        foreach ($tools as $tool) {
            DB::table('agent_tool_registry')->updateOrInsert(
                ['name' => $tool['name']],
                array_merge($tool, ['updated_at' => now()])
            );
        }
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->whereIn('name', ['list_trees', 'get_recent_searches', 'get_research_landscape'])
            ->delete();
    }
};
