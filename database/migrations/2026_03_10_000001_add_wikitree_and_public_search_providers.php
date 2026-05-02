<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N113 — WikiTree Providers
 *
 * 1. Registers WikiTree in genealogy_research_providers
 * 2. Registers 3 WikiTree agent tools: wikitree_search, wikitree_get_ancestors, wikitree_get_person
 * 3. Updates genealogy_research_providers provider_class column for WikiTree
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Register WikiTree in genealogy_research_providers
        DB::statement("
            INSERT INTO genealogy_research_providers
                (provider_id, provider_name, provider_type, base_url, auth_type,
                 capabilities, is_active, is_authenticated, priority, provider_class,
                 signup_url, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                provider_name     = VALUES(provider_name),
                base_url          = VALUES(base_url),
                auth_type         = VALUES(auth_type),
                capabilities      = VALUES(capabilities),
                is_active         = VALUES(is_active),
                is_authenticated  = VALUES(is_authenticated),
                priority          = VALUES(priority),
                provider_class    = VALUES(provider_class),
                signup_url        = VALUES(signup_url),
                notes             = VALUES(notes)
        ", [
            'wikitree',
            'WikiTree',
            'api',
            'https://api.wikitree.com/api.php',
            'none',
            json_encode([
                'search_persons'  => true,
                'search_records'  => false,
                'get_record'      => false,
                'get_person'      => true,
                'get_family'      => true,
                'get_collections' => false,
                'hints'           => true,
                'attach_records'  => false,
                'dna_matches'     => false,
                'get_ancestors'   => true,
            ]),
            1, // is_active
            1, // is_authenticated (no auth needed)
            2, // priority
            'App\\Services\\Genealogy\\Providers\\WikiTreeProvider',
            'https://www.wikitree.com/wiki/Help:API_Documentation',
            'Free open genealogy — 30M+ profiles. No API key required. Strong US colonial-era coverage.',
        ]);

        // 2. Register wikitree_search agent tool
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, description, service_class, method, parameters, returns_description,
                 permissions, risk_level, category, enabled, max_calls_per_run, max_tokens_per_call)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                description        = VALUES(description),
                service_class      = VALUES(service_class),
                method             = VALUES(method),
                parameters         = VALUES(parameters),
                returns_description= VALUES(returns_description),
                permissions        = VALUES(permissions),
                enabled            = VALUES(enabled)
        ", [
            'wikitree_search',
            'Search WikiTree profiles by name, dates, and places. WikiTree is a free collaborative genealogy platform with 30+ million profiles and strong US colonial-era coverage. No API key required. Returns matched profiles with birth/death dates, places, and profile URLs.',
            'App\\Services\\Genealogy\\Providers\\GenealogyProviderManager',
            'searchWikiTree',
            json_encode([
                'given_name'  => ['type' => 'string',  'required' => false, 'description' => 'First/given name to search'],
                'surname'     => ['type' => 'string',  'required' => true,  'description' => 'Surname/last name at birth'],
                'birth_year'  => ['type' => 'integer', 'required' => false, 'description' => 'Approximate birth year (±15 year tolerance)'],
                'birth_place' => ['type' => 'string',  'required' => false, 'description' => 'Birth location (state, country, etc.)'],
                'death_year'  => ['type' => 'integer', 'required' => false, 'description' => 'Approximate death year'],
                'limit'       => ['type' => 'integer', 'required' => false, 'description' => 'Max results (default 20, max 100)'],
            ]),
            'Array with success, source, total_count, results[]. Each result: id (WikiTree ID e.g. Smith-1), given_name, surname, full_name, birth_date, birth_place, death_date, death_place, url.',
            json_encode(['genealogy:read']),
            'read',
            'genealogy',
            1,
            8,    // max_calls_per_run
            4000, // max_tokens_per_call
        ]);

        // 3. Register wikitree_get_ancestors agent tool
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, description, service_class, method, parameters, returns_description,
                 permissions, risk_level, category, enabled, max_calls_per_run, max_tokens_per_call)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                description        = VALUES(description),
                service_class      = VALUES(service_class),
                method             = VALUES(method),
                parameters         = VALUES(parameters),
                returns_description= VALUES(returns_description),
                permissions        = VALUES(permissions),
                enabled            = VALUES(enabled)
        ", [
            'wikitree_get_ancestors',
            'Retrieve ancestor tree from WikiTree for a known WikiTree profile ID. Returns up to 5 generations of ancestors with birth/death dates and places. Use after finding a profile via wikitree_search. Ahnentafel numbers are included for generation-level identification.',
            'App\\Services\\Genealogy\\Providers\\GenealogyProviderManager',
            'getWikiTreeAncestors',
            json_encode([
                'wikitree_id' => ['type' => 'string',  'required' => true,  'description' => 'WikiTree profile ID (e.g. Smith-1 or numeric ID from wikitree_search result)'],
                'depth'       => ['type' => 'integer', 'required' => false, 'description' => 'Generations to traverse: 1=parents, 2=grandparents, 3=great-grandparents (default 3, max 5)'],
            ]),
            'Array with success, person_id, depth, total_count, ancestors[]. Each ancestor: id, full_name, birth_date, birth_place, death_date, death_place, ahnentafel (position number), url.',
            json_encode(['genealogy:read']),
            'read',
            'genealogy',
            1,
            4,    // max_calls_per_run (ancestor queries are heavier)
            6000, // max_tokens_per_call
        ]);

        // 4. Register wikitree_get_person agent tool
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, description, service_class, method, parameters, returns_description,
                 permissions, risk_level, category, enabled, max_calls_per_run, max_tokens_per_call)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                description        = VALUES(description),
                service_class      = VALUES(service_class),
                method             = VALUES(method),
                parameters         = VALUES(parameters),
                returns_description= VALUES(returns_description),
                permissions        = VALUES(permissions),
                enabled            = VALUES(enabled)
        ", [
            'wikitree_get_person',
            'Get a WikiTree profile and immediate family (parents, spouses, children, siblings) by WikiTree ID. Use after wikitree_search to get full profile details and family structure. Bio snippet included if available.',
            'App\\Services\\Genealogy\\Providers\\GenealogyProviderManager',
            'getWikiTreePerson',
            json_encode([
                'wikitree_id' => ['type' => 'string', 'required' => true, 'description' => 'WikiTree profile ID (e.g. Smith-1 from wikitree_search result)'],
            ]),
            'Object with success, person (full profile), family (parents, spouses, children, siblings arrays).',
            json_encode(['genealogy:read']),
            'read',
            'genealogy',
            1,
            6,    // max_calls_per_run
            4000, // max_tokens_per_call
        ]);

    }

    public function down(): void
    {
        DB::table('agent_tool_registry')->whereIn('name', [
            'wikitree_search',
            'wikitree_get_ancestors',
            'wikitree_get_person',
        ])->delete();

        DB::table('genealogy_research_providers')->where('provider_id', 'wikitree')->delete();
    }
};
