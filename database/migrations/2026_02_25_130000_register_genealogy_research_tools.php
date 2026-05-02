<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tools = [
            [
                'name' => 'newspaper_search',
                'service_class' => 'App\\Services\\Genealogy\\NewspaperSearchService',
                'method' => 'search',
                'description' => 'Search LOC Chronicling America for historical newspapers (1690-1963). Find obituaries, marriage notices, migration records, local news. Free, no API key. 600K+ newspapers across all 50 states.',
                'parameters' => json_encode([
                    'query' => ['type' => 'string', 'description' => 'Search query (person name, event, location)', 'required' => true],
                    'options' => ['type' => 'array', 'description' => 'Options: state, date_from, date_to, page, per_page', 'default' => []],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 10,
            ],
            [
                'name' => 'newspaper_search_obituaries',
                'service_class' => 'App\\Services\\Genealogy\\NewspaperSearchService',
                'method' => 'searchObituaries',
                'description' => 'Search historical newspapers specifically for obituaries. Searches LOC Chronicling America with obituary-focused query patterns.',
                'parameters' => json_encode([
                    'name' => ['type' => 'string', 'description' => 'Person name to search for', 'required' => true],
                    'options' => ['type' => 'array', 'description' => 'Options: state, date_from, date_to', 'default' => []],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 5,
            ],
            [
                'name' => 'dna_find_triangulations',
                'service_class' => 'App\\Services\\Genealogy\\DnaMatchService',
                'method' => 'findTriangulations',
                'description' => 'Find DNA triangulation groups — overlapping segments shared by multiple matches, suggesting common ancestors.',
                'parameters' => json_encode([
                    'kitId' => ['type' => 'integer', 'description' => 'DNA kit ID', 'required' => true],
                    'minOverlapCm' => ['type' => 'integer', 'description' => 'Minimum overlap in cM (default: 7)', 'default' => 7],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 5,
            ],
            [
                'name' => 'dna_matches_by_person',
                'service_class' => 'App\\Services\\Genealogy\\DnaMatchService',
                'method' => 'getMatchesByPerson',
                'description' => 'Get all DNA matches linked to a specific person, grouped by kit.',
                'parameters' => json_encode([
                    'personId' => ['type' => 'integer', 'description' => 'Person ID', 'required' => true],
                    'options' => ['type' => 'array', 'description' => 'Options: min_cm, relationship_type', 'default' => []],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 5,
            ],
            [
                'name' => 'fan_analyze_cluster',
                'service_class' => 'App\\Services\\Genealogy\\FANClusterService',
                'method' => 'analyzeCluster',
                'description' => 'Analyze a Friends/Associates/Neighbors (FAN) cluster — identify recurring associates, potential family connections, and migration patterns.',
                'parameters' => json_encode([
                    'clusterId' => ['type' => 'integer', 'description' => 'FAN cluster ID', 'required' => true],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 5,
            ],
            [
                'name' => 'fan_suggest_research',
                'service_class' => 'App\\Services\\Genealogy\\FANClusterService',
                'method' => 'suggestResearchTargets',
                'description' => 'Get research suggestions from a FAN cluster — prioritized associates who may reveal family connections.',
                'parameters' => json_encode([
                    'clusterId' => ['type' => 'integer', 'description' => 'FAN cluster ID', 'required' => true],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 5,
            ],
            [
                'name' => 'evidence_build_chain',
                'service_class' => 'App\\Services\\Genealogy\\EvidenceCorrelationService',
                'method' => 'buildEvidenceChain',
                'description' => 'Build a complete evidence chain for a person and event type (birth, death, marriage, parentage). Returns all supporting evidence with strength scores and citations — essential for GPS compliance.',
                'parameters' => json_encode([
                    'personId' => ['type' => 'integer', 'description' => 'Person ID', 'required' => true],
                    'eventType' => ['type' => 'string', 'description' => 'Event type: birth, death, marriage, parentage, burial, migration', 'required' => true],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 10,
            ],
            [
                'name' => 'surname_phonetic_matches',
                'service_class' => 'App\\Services\\Genealogy\\NameVariantService',
                'method' => 'findPhoneticMatches',
                'description' => 'Find phonetic surname matches in a tree using Soundex, Metaphone, and NYSIIS — discovers alternate spellings and transcription variants.',
                'parameters' => json_encode([
                    'treeId' => ['type' => 'integer', 'description' => 'Tree ID', 'required' => true],
                    'surname' => ['type' => 'string', 'description' => 'Surname to match', 'required' => true],
                    'limit' => ['type' => 'integer', 'description' => 'Max results (default: 20)', 'default' => 20],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 10,
            ],
            [
                'name' => 'source_search',
                'service_class' => 'App\\Services\\Genealogy\\SourceCitationService',
                'method' => 'searchSources',
                'description' => 'Search genealogy sources in a tree by keyword. Returns source records with metadata for citation building.',
                'parameters' => json_encode([
                    'treeId' => ['type' => 'integer', 'description' => 'Tree ID', 'required' => true],
                    'query' => ['type' => 'string', 'description' => 'Search query', 'required' => true],
                    'limit' => ['type' => 'integer', 'description' => 'Max results (default: 50)', 'default' => 50],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 5,
            ],
            [
                'name' => 'detect_duplicates',
                'service_class' => 'App\\Services\\Genealogy\\DuplicateDetectionService',
                'method' => 'findDuplicatePersons',
                'description' => 'Find potential duplicate persons in a family tree using name, date, and place matching. Returns pairs with confidence scores.',
                'parameters' => json_encode([
                    'treeId' => ['type' => 'integer', 'description' => 'Tree ID', 'required' => true],
                    'options' => ['type' => 'array', 'description' => 'Options: min_score, limit, status_filter', 'default' => []],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 3,
            ],
            [
                'name' => 'map_ancestor_locations',
                'service_class' => 'App\\Services\\Genealogy\\HistoricalMapsService',
                'method' => 'getAncestorLocations',
                'description' => 'Get all ancestor locations in a tree for mapping — clusters by place with event counts. Useful for identifying migration patterns.',
                'parameters' => json_encode([
                    'treeId' => ['type' => 'integer', 'description' => 'Tree ID', 'required' => true],
                    'options' => ['type' => 'array', 'description' => 'Options: generation_limit, event_types', 'default' => []],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 3,
            ],
            [
                'name' => 'map_migration_path',
                'service_class' => 'App\\Services\\Genealogy\\HistoricalMapsService',
                'method' => 'generateMigrationPath',
                'description' => 'Generate a migration path for a person or their ancestors — chronological from/to location segments showing family movement.',
                'parameters' => json_encode([
                    'personId' => ['type' => 'integer', 'description' => 'Person ID', 'required' => true],
                    'direction' => ['type' => 'string', 'description' => 'Direction: ancestors or descendants', 'default' => 'ancestors'],
                    'generations' => ['type' => 'integer', 'description' => 'Number of generations (default: 5)', 'default' => 5],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 5,
            ],
            [
                'name' => 'ai_research_person',
                'service_class' => 'App\\Services\\Genealogy\\GenealogyAIResearchService',
                'method' => 'researchPerson',
                'description' => 'Get AI-generated research suggestions for a person using Evidence Explained methodology. Identifies gaps, suggests record types and repositories to search.',
                'parameters' => json_encode([
                    'personId' => ['type' => 'integer', 'description' => 'Person ID to research', 'required' => true],
                    'options' => ['type' => 'array', 'description' => 'Options: focus (birth, death, marriage, parentage)', 'default' => []],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 5,
            ],
            [
                'name' => 'ai_research_brick_wall',
                'service_class' => 'App\\Services\\Genealogy\\GenealogyAIResearchService',
                'method' => 'suggestResearchForBrickWall',
                'description' => 'Get specialized strategies for breaking through a genealogy brick wall — analyzes gaps, suggests alternate record types, FAN approach, DNA strategies.',
                'parameters' => json_encode([
                    'personId' => ['type' => 'integer', 'description' => 'Person ID at the brick wall', 'required' => true],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 3,
            ],
            [
                'name' => 'dna_triangulation_groups',
                'service_class' => 'App\\Services\\Genealogy\\TriangulationGroupService',
                'method' => 'buildTriangulationGroups',
                'description' => 'Build DNA triangulation groups for a kit — clusters of matches sharing overlapping segments, suggesting common ancestors.',
                'parameters' => json_encode([
                    'kitId' => ['type' => 'integer', 'description' => 'DNA kit ID', 'required' => true],
                    'options' => ['type' => 'array', 'description' => 'Options: min_cm, min_group_size', 'default' => []],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 3,
            ],
            [
                'name' => 'dna_suggest_ancestors',
                'service_class' => 'App\\Services\\Genealogy\\TriangulationGroupService',
                'method' => 'suggestCommonAncestors',
                'description' => 'Suggest common ancestors for a DNA triangulation group — cross-references shared trees and pedigrees.',
                'parameters' => json_encode([
                    'groupId' => ['type' => 'integer', 'description' => 'Triangulation group ID', 'required' => true],
                ]),
                'permissions' => json_encode(['genealogy:read']),
                'risk_level' => 'read',
                'category' => 'genealogy',
                'max_calls_per_run' => 5,
            ],
        ];

        foreach ($tools as $tool) {
            try {
                DB::insert("
                    INSERT INTO agent_tool_registry
                    (name, service_class, method, description, parameters, permissions,
                     risk_level, category, max_calls_per_run, source)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual')
                    ON DUPLICATE KEY UPDATE
                        service_class = VALUES(service_class),
                        method = VALUES(method),
                        description = VALUES(description),
                        parameters = VALUES(parameters),
                        risk_level = VALUES(risk_level),
                        category = VALUES(category),
                        max_calls_per_run = VALUES(max_calls_per_run),
                        updated_at = NOW()
                ", [
                    $tool['name'],
                    $tool['service_class'],
                    $tool['method'],
                    $tool['description'],
                    $tool['parameters'],
                    $tool['permissions'],
                    $tool['risk_level'],
                    $tool['category'],
                    $tool['max_calls_per_run'],
                ]);
            } catch (\Exception $e) {
                // Skip on error (idempotent)
            }
        }
    }

    public function down(): void
    {
        $names = [
            'newspaper_search', 'newspaper_search_obituaries',
            'dna_find_triangulations', 'dna_matches_by_person',
            'fan_analyze_cluster', 'fan_suggest_research',
            'evidence_build_chain', 'surname_phonetic_matches',
            'source_search', 'detect_duplicates',
            'map_ancestor_locations', 'map_migration_path',
            'ai_research_person', 'ai_research_brick_wall',
            'dna_triangulation_groups', 'dna_suggest_ancestors',
        ];

        foreach ($names as $name) {
            DB::delete("DELETE FROM agent_tool_registry WHERE name = ?", [$name]);
        }
    }
};
