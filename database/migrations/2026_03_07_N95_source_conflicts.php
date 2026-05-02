<?php

/**
 * N95 — Source conflict detection (GPS Element 4: Resolving Conflicting Evidence)
 *
 * Detects and records conflicts between sources claiming different facts for the same person.
 * Currently conflicts are silent — this makes them visible and actionable.
 *
 * Populated by: SourceConflictService::detectConflictsForPerson()
 * Queried by: agent tool detect_source_conflicts
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_source_conflicts (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                person_id      INT UNSIGNED NOT NULL,
                tree_id        INT UNSIGNED NOT NULL,
                field_name     VARCHAR(100) NOT NULL COMMENT 'Which fact conflicts (birth_date, birth_place, etc.)',
                source_a_id    INT UNSIGNED COMMENT 'First conflicting source',
                source_a_value VARCHAR(500) COMMENT 'Value claimed by source A',
                source_a_quality ENUM('original','derivative','authored') DEFAULT NULL,
                source_b_id    INT UNSIGNED COMMENT 'Second conflicting source',
                source_b_value VARCHAR(500) COMMENT 'Value claimed by source B',
                source_b_quality ENUM('original','derivative','authored') DEFAULT NULL,
                conflict_severity ENUM('minor','moderate','major') NOT NULL DEFAULT 'moderate'
                    COMMENT 'minor=spelling variant, moderate=±5yr, major=contradictory facts',
                resolution_status ENUM('unresolved','resolved','ignored') NOT NULL DEFAULT 'unresolved',
                resolution_notes  TEXT COMMENT 'How the conflict was resolved (GPS analysis)',
                resolved_by       VARCHAR(100) COMMENT 'Agent or human who resolved',
                resolved_at       TIMESTAMP NULL,
                detected_by       VARCHAR(100) COMMENT 'Agent that detected this conflict',
                created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_person_id (person_id),
                KEY idx_tree_id (tree_id),
                KEY idx_resolution_status (resolution_status),
                UNIQUE KEY uq_conflict (person_id, field_name, source_a_id, source_b_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Register agent tools
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, risk_level, category, max_calls_per_run, enabled, created_at, updated_at)
            VALUES
                ('detect_source_conflicts', 'App\\\\Services\\\\Genealogy\\\\SourceConflictService', 'detectConflictsForPerson',
                 'GPS Element 4: Detect conflicts between sources claiming different facts for the same person (e.g., two sources disagree on birth year or place). Returns newly detected conflicts. Run during analyze phase to surface contradictions that need GPS resolution.',
                 '{\"person_id\": {\"type\": \"integer\", \"description\": \"Person ID to check for source conflicts\", \"required\": true}}',
                 '[\"genealogy:read\", \"genealogy:write\"]', 'write', 'genealogy', 5, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE description = VALUES(description), enabled = 1, updated_at = NOW()
        ");

        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, risk_level, category, max_calls_per_run, enabled, created_at, updated_at)
            VALUES
                ('get_source_conflicts', 'App\\\\Services\\\\Genealogy\\\\SourceConflictService', 'getConflictsForPerson',
                 'Get all unresolved source conflicts for a person. Use during analyze phase to understand which facts are contested. GPS requires resolving conflicts before accepting a conclusion.',
                 '{\"person_id\": {\"type\": \"integer\", \"description\": \"Person ID\", \"required\": true}, \"status\": {\"type\": \"string\", \"description\": \"Filter by resolution_status: unresolved, resolved, ignored\", \"default\": \"unresolved\"}}',
                 '[\"genealogy:read\"]', 'read', 'genealogy', 5, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE description = VALUES(description), enabled = 1, updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ('detect_source_conflicts', 'get_source_conflicts')");
        DB::statement("DROP TABLE IF EXISTS genealogy_source_conflicts");
    }
};
