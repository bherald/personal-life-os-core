<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create proposed changes table
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_proposed_changes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tree_id INT UNSIGNED NOT NULL,
                person_id INT UNSIGNED NOT NULL,
                change_type ENUM('fact_update','event_add','source_add','media_link') NOT NULL,
                field_name VARCHAR(100) NULL,
                current_value TEXT NULL,
                proposed_value TEXT NOT NULL,
                evidence_sources TEXT NULL COMMENT 'JSON array',
                evidence_summary TEXT NOT NULL,
                confidence DECIMAL(3,2) DEFAULT 0.50,
                agent_id VARCHAR(100) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                applied_at TIMESTAMP NULL,
                reviewer_notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_tree_status (tree_id, status),
                INDEX idx_person (person_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Register the propose_change tool
        try {
            DB::insert("INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description,
                 permissions, risk_level, category, enabled, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())", [
                'propose_change',
                'App\\Services\\Genealogy\\PersonService',
                'proposeChange',
                'Propose a fact update, event addition, source link, or media link for an existing person. Changes are queued for human review before being applied to the tree.',
                json_encode([
                    'person_id' => ['type' => 'integer', 'required' => true, 'description' => 'Existing person ID in tree'],
                    'change_type' => ['type' => 'string', 'required' => true, 'description' => 'fact_update, event_add, source_add, or media_link'],
                    'field_name' => ['type' => 'string', 'required' => false, 'description' => 'Field to update (for fact_update: birth_date, death_date, occupation, etc.)'],
                    'proposed_value' => ['type' => 'string', 'required' => true, 'description' => 'New value (string for facts, JSON for events/sources)'],
                    'evidence_sources' => ['type' => 'array', 'required' => false, 'description' => 'Array of source citation strings'],
                    'evidence_summary' => ['type' => 'string', 'required' => true, 'description' => 'How the change was determined'],
                    'confidence' => ['type' => 'float', 'required' => false, 'description' => 'Confidence 0.0-1.0 (default 0.5)'],
                ]),
                'Returns proposal ID and status',
                json_encode(['genealogy:write']),
                'write',
                'genealogy',
            ]);
        } catch (\Throwable $e) {
            // Already registered
        }

        // Register change_proposal review type
        try {
            DB::insert("INSERT INTO review_type_registry
                (name, label, icon, category, source_table, source_connection, count_sql, fetch_sql,
                 approve_sql, reject_sql, field_mapping, ui_schema, service_class, approve_method,
                 batch_enabled, enabled, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())", [
                'change_proposal',
                'Genealogy Changes',
                'pencil',
                'genealogy',
                'genealogy_proposed_changes',
                'mysql',
                "SELECT COUNT(*) as total FROM genealogy_proposed_changes WHERE status = 'pending'",
                "SELECT pc.id, pc.tree_id, pc.person_id, pc.change_type, pc.field_name, pc.current_value, pc.proposed_value, pc.evidence_sources, pc.evidence_summary, pc.confidence, pc.created_at, CONCAT(p.given_name, ' ', p.surname) as person_name FROM genealogy_proposed_changes pc LEFT JOIN genealogy_persons p ON p.id = pc.person_id WHERE pc.status = 'pending' ORDER BY pc.confidence DESC, pc.created_at ASC LIMIT 100",
                null,
                "UPDATE genealogy_proposed_changes SET status = 'rejected', reviewer_notes = ?, updated_at = NOW() WHERE id = ?",
                json_encode([
                    'id' => 'id',
                    'title_expr' => "CONCAT(change_type, ': ', COALESCE(field_name, 'N/A'))",
                    'unified_id_template' => 'change:{{id}}',
                    'summary' => 'evidence_summary',
                    'confidence' => 'confidence',
                    'created_at' => 'created_at',
                    'tree_id' => 'tree_id',
                    'person_id' => 'person_id',
                    'person_name' => 'person_name',
                    'change_type' => 'change_type',
                    'field_name' => 'field_name',
                    'current_value' => 'current_value',
                    'proposed_value' => 'proposed_value',
                    'evidence_sources_json' => 'evidence_sources',
                ]),
                json_encode([
                    'header' => [
                        ['type' => 'badge', 'source' => 'change_type', 'class' => 'badge-type'],
                        ['type' => 'text', 'source' => 'person_name', 'label' => 'Person'],
                        ['type' => 'confidence', 'source' => 'confidence'],
                    ],
                    'body' => [
                        ['type' => 'diff', 'label' => 'Proposed Change', 'current' => 'current_value', 'proposed' => 'proposed_value', 'field_label' => 'field_name'],
                        ['type' => 'text', 'source' => 'evidence_summary', 'label' => 'Evidence'],
                    ],
                    'footer' => [
                        ['type' => 'json', 'source' => 'evidence_sources', 'label' => 'Sources', 'collapsible' => true, 'compact' => true],
                        ['type' => 'timestamp', 'source' => 'created_at'],
                    ],
                ]),
                'App\\Services\\Genealogy\\PersonService',
                'applyProposedChange',
                1,
            ]);
        } catch (\Throwable $e) {
            // Already registered
        }

        // Disable Claude CLI in LLM pool
        try {
            DB::update("UPDATE llm_instances SET is_active = 0, updated_at = NOW() WHERE instance_id = 'claude_cli'");
        } catch (\Throwable $e) {
            // Table may not exist or have different schema on dev
        }
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS genealogy_proposed_changes");
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'propose_change'");
        DB::delete("DELETE FROM review_type_registry WHERE name = 'change_proposal'");
        DB::update("UPDATE llm_instances SET is_active = 1, updated_at = NOW() WHERE instance_id = 'claude_cli'");
    }
};
