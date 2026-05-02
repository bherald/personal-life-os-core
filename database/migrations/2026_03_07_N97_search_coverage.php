<?php

/**
 * N97 — Negative evidence coverage model (GPS Element 1)
 *
 * GPS Element 1 requires documenting exhaustive search — not just what was found,
 * but what repositories were searched and found nothing.
 *
 * genealogy_search_coverage provides per-repository-type tracking at the person level,
 * summarizing gps_research_logs into a structured coverage map.
 *
 * This is the "GPS compliance dashboard" for negative evidence.
 * Populated by: SearchCoverageService::updateCoverage()
 * Queried by: agent tool get_search_coverage
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_search_coverage (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                person_id      INT UNSIGNED NOT NULL,
                tree_id        INT UNSIGNED NOT NULL,
                repository_type ENUM(
                    'vital_records','census','church','military','immigration',
                    'land','probate','newspaper','cemetery','dna','newspaper_digital',
                    'family_tree_aggregator','state_archives','county_records','other'
                ) NOT NULL,
                repository_name  VARCHAR(300) COMMENT 'Specific repository (e.g. FamilySearch, NARA, LOC ChronAm)',
                search_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total searches attempted',
                positive_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Searches that returned results',
                negative_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Searches that returned nothing',
                date_ranges_covered TEXT COMMENT 'JSON array of date ranges searched',
                geographic_areas_covered TEXT COMMENT 'JSON array of geographic areas searched',
                last_searched_at TIMESTAMP NULL,
                coverage_notes   TEXT COMMENT 'Notes on coverage gaps, exclusions, access issues',
                gps_satisfactory TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT '1 = agent/human determined this repository adequately covered',
                created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_person_id (person_id),
                KEY idx_tree_id (tree_id),
                KEY idx_gps (person_id, gps_satisfactory),
                UNIQUE KEY uq_person_repo (person_id, repository_type, repository_name(200))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Register agent tool
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, risk_level, category, max_calls_per_run, enabled, created_at, updated_at)
            VALUES
                ('get_search_coverage', 'App\\\\Services\\\\Genealogy\\\\SearchCoverageService', 'getCoverageForPerson',
                 'GPS Element 1: Get the negative evidence coverage map for a person — which repository types have been searched, how many times, and whether they returned results. Use at the start of research to avoid repeating searches already done. A person with coverage gaps needs research in uncovered repository types.',
                 '{\"person_id\": {\"type\": \"integer\", \"description\": \"Person ID\", \"required\": true}}',
                 '[\"genealogy:read\"]', 'read', 'genealogy', 10, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE description = VALUES(description), enabled = 1, updated_at = NOW()
        ");

        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, risk_level, category, max_calls_per_run, enabled, created_at, updated_at)
            VALUES
                ('update_search_coverage', 'App\\\\Services\\\\Genealogy\\\\SearchCoverageService', 'updateCoverage',
                 'GPS Element 1: Update the search coverage record after completing a search (positive or negative). Call after every search to maintain the negative evidence map. Required for GPS compliance.',
                 '{\"person_id\": {\"type\": \"integer\", \"description\": \"Person ID\", \"required\": true}, \"repository_type\": {\"type\": \"string\", \"description\": \"Repository type: vital_records, census, church, military, immigration, land, probate, newspaper, cemetery, dna, newspaper_digital, family_tree_aggregator, state_archives, county_records, other\", \"required\": true}, \"repository_name\": {\"type\": \"string\", \"description\": \"Specific repository name (e.g. FamilySearch, NARA)\", \"required\": true}, \"positive\": {\"type\": \"boolean\", \"description\": \"true if search returned results, false if negative\", \"required\": true}, \"notes\": {\"type\": \"string\", \"description\": \"Notes on what was searched and why results were or were not found\"}}',
                 '[\"genealogy:read\", \"genealogy:write\"]', 'write', 'genealogy', 20, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE description = VALUES(description), enabled = 1, updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ('get_search_coverage', 'update_search_coverage')");
        DB::statement("DROP TABLE IF EXISTS genealogy_search_coverage");
    }
};
