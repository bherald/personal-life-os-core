<?php

/**
 * N93 — FAN co-occurrence auto-accumulation
 *
 * The FAN (Friends, Associates, Neighbors) principle is the #1 brick-wall
 * breakthrough technique. Co-occurring names extracted from agent search results
 * (witness lists, census neighbors, church members) are stored here.
 *
 * Populated by: FANCooccurrenceService::extractFromSearchResult()
 * Queried by: agent tool fan_analyze_cooccurrences
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS fan_cooccurrences (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                person_id      INT UNSIGNED NOT NULL COMMENT 'Primary person being researched',
                tree_id        INT UNSIGNED NOT NULL,
                cooccurring_name VARCHAR(300) NOT NULL COMMENT 'Name that appeared alongside person_id',
                cooccurring_surname VARCHAR(150) GENERATED ALWAYS AS (
                    TRIM(SUBSTRING_INDEX(cooccurring_name, ' ', -1))
                ) STORED,
                source_type    ENUM('witness','census_neighbor','church','military','land','probate','newspaper','other')
                                NOT NULL DEFAULT 'other',
                source_ref     VARCHAR(1000) COMMENT 'URL or citation where co-occurrence was found',
                source_date    VARCHAR(50) COMMENT 'Date of the source document',
                source_location VARCHAR(300) COMMENT 'Place of the source document',
                occurrence_count SMALLINT UNSIGNED NOT NULL DEFAULT 1
                                COMMENT 'Incremented when same name appears in same source_type again',
                confidence     DECIMAL(4,3) NOT NULL DEFAULT 0.700
                                COMMENT '1.0 = named witness/relative, 0.5 = nearby neighbor',
                agent_id       VARCHAR(50) COMMENT 'Agent that extracted this co-occurrence',
                notes          TEXT,
                created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_person_id (person_id),
                KEY idx_tree_id (tree_id),
                KEY idx_surname (cooccurring_surname),
                UNIQUE KEY uq_person_name_type (person_id, cooccurring_name(200), source_type)
                    COMMENT 'Prevents duplicates; ON DUPLICATE KEY UPDATE increments occurrence_count'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Register agent tool
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, risk_level, category, max_calls_per_run, enabled, created_at, updated_at)
            VALUES
                ('fan_extract_cooccurrences', 'App\\\\Services\\\\Genealogy\\\\FANCooccurrenceService', 'extractFromSearchResult',
                 'Extract co-occurring names (witnesses, neighbors, church members) from a search result and store in fan_cooccurrences table. The FAN principle is the #1 brick-wall breakthrough: co-occurring rare surnames often share a common ancestor. Call after any search that returns a list of people.',
                 '{\"person_id\": {\"type\": \"integer\", \"description\": \"Primary person being researched\", \"required\": true}, \"search_result_text\": {\"type\": \"string\", \"description\": \"Raw text or JSON from search result containing names\", \"required\": true}, \"source_type\": {\"type\": \"string\", \"description\": \"One of: witness, census_neighbor, church, military, land, probate, newspaper, other\", \"default\": \"other\"}, \"source_ref\": {\"type\": \"string\", \"description\": \"URL or citation for the source\"}, \"source_date\": {\"type\": \"string\", \"description\": \"Date of source document\"}, \"source_location\": {\"type\": \"string\", \"description\": \"Location of source document\"}}',
                 '[\"genealogy:read\", \"genealogy:write\"]', 'write', 'genealogy', 10, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                parameters = VALUES(parameters),
                enabled = 1,
                updated_at = NOW()
        ");

        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, risk_level, category, max_calls_per_run, enabled, created_at, updated_at)
            VALUES
                ('fan_get_cooccurrences', 'App\\\\Services\\\\Genealogy\\\\FANCooccurrenceService', 'getCooccurrences',
                 'Get all co-occurring names stored for a person, ranked by occurrence_count and confidence. Use to identify FAN members for further research — especially rare surnames that co-occur multiple times.',
                 '{\"person_id\": {\"type\": \"integer\", \"description\": \"Person ID to get co-occurrences for\", \"required\": true}, \"source_type\": {\"type\": \"string\", \"description\": \"Filter by source_type (optional)\"}, \"min_confidence\": {\"type\": \"number\", \"description\": \"Minimum confidence threshold\", \"default\": 0.5}}',
                 '[\"genealogy:read\"]', 'read', 'genealogy', 10, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                parameters = VALUES(parameters),
                enabled = 1,
                updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ('fan_extract_cooccurrences', 'fan_get_cooccurrences')");
        DB::statement("DROP TABLE IF EXISTS fan_cooccurrences");
    }
};
