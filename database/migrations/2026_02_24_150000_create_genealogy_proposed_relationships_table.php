<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create proposed relationships table
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_proposed_relationships (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tree_id INT UNSIGNED NOT NULL,
                person_id INT UNSIGNED NOT NULL COMMENT 'Existing person in tree',
                relationship_type VARCHAR(20) NOT NULL COMMENT 'parent, child, sibling, spouse',
                proposed_name VARCHAR(255) NOT NULL COMMENT 'Full name of proposed relative',
                proposed_given_name VARCHAR(100) NULL,
                proposed_surname VARCHAR(100) NULL,
                proposed_sex CHAR(1) NULL COMMENT 'M, F, or NULL if unknown',
                proposed_birth_date VARCHAR(50) NULL COMMENT 'GEDCOM date format',
                proposed_birth_place VARCHAR(255) NULL,
                proposed_death_date VARCHAR(50) NULL,
                proposed_death_place VARCHAR(255) NULL,
                evidence_sources TEXT NULL COMMENT 'JSON array of source citations',
                evidence_summary TEXT NULL COMMENT 'How the relationship was determined',
                confidence DECIMAL(3,2) NOT NULL DEFAULT 0.50,
                agent_id VARCHAR(100) NULL COMMENT 'Agent that proposed this',
                review_id INT UNSIGNED NULL COMMENT 'FK to agent_review_queue',
                status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, approved, rejected, applied',
                applied_person_id INT UNSIGNED NULL COMMENT 'Person ID created when applied',
                applied_family_id INT UNSIGNED NULL COMMENT 'Family ID created/used when applied',
                applied_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_tree_status (tree_id, status),
                INDEX idx_person (person_id),
                INDEX idx_review (review_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Register the propose_relationship tool
        try {
            DB::insert("INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description,
                 permissions, risk_level, category, enabled, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())", [
                'propose_relationship',
                'App\\Services\\Genealogy\\FamilyService',
                'proposeRelationship',
                'Propose a new family relationship (parent, child, sibling, spouse) for human review. The proposed person will be created in the tree only after human approval.',
                json_encode([
                    'person_id' => ['type' => 'integer', 'required' => true, 'description' => 'Existing person ID in tree'],
                    'relationship_type' => ['type' => 'string', 'required' => true, 'description' => 'parent, child, sibling, or spouse'],
                    'proposed_name' => ['type' => 'string', 'required' => true, 'description' => 'Full name of proposed relative'],
                    'proposed_sex' => ['type' => 'string', 'required' => false, 'description' => 'M or F (optional)'],
                    'proposed_birth_date' => ['type' => 'string', 'required' => false, 'description' => 'Birth date in GEDCOM format'],
                    'proposed_birth_place' => ['type' => 'string', 'required' => false, 'description' => 'Birth place'],
                    'proposed_death_date' => ['type' => 'string', 'required' => false, 'description' => 'Death date in GEDCOM format'],
                    'proposed_death_place' => ['type' => 'string', 'required' => false, 'description' => 'Death place'],
                    'evidence_sources' => ['type' => 'array', 'required' => false, 'description' => 'Array of source citation strings'],
                    'evidence_summary' => ['type' => 'string', 'required' => true, 'description' => 'How the relationship was determined'],
                    'confidence' => ['type' => 'float', 'required' => false, 'description' => 'Confidence 0.0-1.0 (default 0.5)'],
                ]),
                'Returns proposal ID and review status',
                json_encode(['genealogy:write']),
                'write',
                'genealogy',
            ]);
        } catch (\Throwable $e) {
            // Already registered
        }
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS genealogy_proposed_relationships");
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'propose_relationship'");
    }
};
