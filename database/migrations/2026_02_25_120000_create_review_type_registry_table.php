<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create review_type_registry table for pluggable review types.
 *
 * This enables dynamic registration of review types without code changes.
 * Pattern matches agent_tool_registry for consistency.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Create registry table
        DB::statement("
            CREATE TABLE IF NOT EXISTS review_type_registry (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL UNIQUE COMMENT 'Internal identifier: agent, research, genealogy, faces, privacy',
                label VARCHAR(100) NOT NULL COMMENT 'Display label: Agent Findings, Research Facts',
                icon VARCHAR(50) DEFAULT NULL COMMENT 'Icon name: robot, magnifying-glass, users',
                category VARCHAR(50) NOT NULL COMMENT 'Grouping category for tabs',

                -- Source configuration
                source_table VARCHAR(100) NOT NULL COMMENT 'Primary table: agent_review_queue, research_facts',
                source_connection ENUM('mysql', 'pgsql_rag') NOT NULL DEFAULT 'mysql',

                -- SQL templates
                count_sql TEXT NOT NULL COMMENT 'COUNT query for stats',
                fetch_sql TEXT NOT NULL COMMENT 'SELECT query with placeholders',
                approve_sql TEXT DEFAULT NULL COMMENT 'UPDATE query for approval',
                reject_sql TEXT DEFAULT NULL COMMENT 'UPDATE query for rejection',

                -- Field mapping (JSON)
                field_mapping JSON NOT NULL COMMENT 'Maps source columns to unified fields',

                -- Vue component configuration
                vue_renderer VARCHAR(100) DEFAULT NULL COMMENT 'Vue component for custom rendering',
                vue_detail_component VARCHAR(100) DEFAULT NULL COMMENT 'Detail panel component',

                -- Actions configuration
                actions JSON DEFAULT NULL COMMENT 'Custom actions beyond approve/reject',

                -- Behavior flags
                requires_image TINYINT(1) NOT NULL DEFAULT 0,
                image_field VARCHAR(100) DEFAULT NULL,
                batch_enabled TINYINT(1) NOT NULL DEFAULT 1,

                -- Handler configuration for complex operations
                service_class VARCHAR(255) DEFAULT NULL,
                approve_method VARCHAR(100) DEFAULT NULL,
                reject_method VARCHAR(100) DEFAULT NULL,

                -- Display settings
                display_order INT NOT NULL DEFAULT 100,
                color VARCHAR(50) DEFAULT NULL COMMENT 'Theme color token',
                enabled TINYINT(1) NOT NULL DEFAULT 1,

                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_enabled (enabled),
                INDEX idx_category (category),
                INDEX idx_display_order (display_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed with existing review types
        $this->seedReviewTypes();
    }

    public function down(): void
    {
        Schema::dropIfExists('review_type_registry');
    }

    private function seedReviewTypes(): void
    {
        // Agent Findings
        DB::insert('
            INSERT INTO review_type_registry
            (name, label, icon, category, source_table, source_connection, count_sql, fetch_sql, approve_sql, reject_sql, field_mapping, vue_renderer, requires_image, batch_enabled, color, display_order, enabled)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ', [
            'agent',
            'Agent Findings',
            'cpu-chip',
            'agent',
            'agent_review_queue',
            'mysql',
            "SELECT COUNT(*) as total FROM agent_review_queue WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())",
            "SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, created_at ASC LIMIT 100",
            "UPDATE agent_review_queue SET status = 'approved', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?",
            "UPDATE agent_review_queue SET status = 'rejected', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?",
            json_encode([
                'unified_id_template' => 'agent:{{token}}',
                'id' => 'id',
                'token' => 'token',
                'title' => 'title',
                'summary' => 'summary',
                'confidence' => 'confidence',
                'priority' => 'priority',
                'created_at' => 'created_at',
                'expires_at' => 'expires_at',
                'details_json' => 'details',
                'review_type' => 'review_type',
                'agent_id' => 'agent_id',
            ]),
            'AgentFindingRenderer',
            0,
            1,
            'ops-sky',
            10,
            1,
        ]);

        // Research Facts (PostgreSQL)
        DB::insert('
            INSERT INTO review_type_registry
            (name, label, icon, category, source_table, source_connection, count_sql, fetch_sql, approve_sql, reject_sql, field_mapping, vue_renderer, requires_image, batch_enabled, color, display_order, enabled)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ', [
            'research',
            'Research Facts',
            'magnifying-glass',
            'research',
            'research_facts',
            'pgsql_rag',
            "SELECT COUNT(*) as total FROM research_facts WHERE review_status = 'pending'",
            "SELECT f.id, f.fact_statement, f.fact_type, f.confidence_score, f.source_urls, f.context_snippet, f.verification_summary, f.external_sources_confirmed, f.external_sources_denied, f.created_at, m.title as mission_title, m.domain_category FROM research_facts f LEFT JOIN research_missions m ON m.id = f.mission_id WHERE f.review_status = 'pending' ORDER BY f.confidence_score DESC, f.created_at ASC LIMIT 100",
            "UPDATE research_facts SET review_status = 'approved', reviewed_at = NOW() WHERE id = ?",
            "UPDATE research_facts SET review_status = 'rejected', reviewed_at = NOW() WHERE id = ?",
            json_encode([
                'unified_id_template' => 'research:{{id}}',
                'id' => 'id',
                'title_expr' => 'LEFT(fact_statement, 80)',
                'summary' => 'fact_statement',
                'confidence' => 'confidence_score',
                'created_at' => 'created_at',
                'fact_type' => 'fact_type',
                'source_urls_json' => 'source_urls',
                'domain_category' => 'domain_category',
                'mission_title' => 'mission_title',
                'verification_summary' => 'verification_summary',
            ]),
            'ResearchFactRenderer',
            0,
            1,
            'ops-butterscotch',
            20,
            1,
        ]);

        // Genealogy Proposals
        DB::insert('
            INSERT INTO review_type_registry
            (name, label, icon, category, source_table, source_connection, count_sql, fetch_sql, approve_sql, reject_sql, field_mapping, vue_renderer, service_class, approve_method, reject_method, requires_image, batch_enabled, color, display_order, enabled)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ', [
            'proposal',
            'Genealogy Proposals',
            'users',
            'genealogy',
            'genealogy_proposed_relationships',
            'mysql',
            "SELECT COUNT(*) as total FROM genealogy_proposed_relationships WHERE status = 'pending'",
            "SELECT pr.id, pr.tree_id, pr.person_id, pr.relationship_type, pr.proposed_name, pr.proposed_given_name, pr.proposed_surname, pr.proposed_sex, pr.proposed_birth_date, pr.proposed_birth_place, pr.evidence_sources, pr.evidence_summary, pr.confidence, pr.created_at, CONCAT(p.given_name, ' ', p.surname) as person_name FROM genealogy_proposed_relationships pr LEFT JOIN genealogy_persons p ON p.id = pr.person_id WHERE pr.status = 'pending' ORDER BY pr.confidence DESC, pr.created_at ASC LIMIT 100",
            null,
            "UPDATE genealogy_proposed_relationships SET status = 'rejected', reviewed_at = NOW(), updated_at = NOW() WHERE id = ?",
            json_encode([
                'unified_id_template' => 'proposal:{{id}}',
                'id' => 'id',
                'tree_id' => 'tree_id',
                'person_id' => 'person_id',
                'person_name' => 'person_name',
                'title_expr' => "CONCAT(relationship_type, ': ', proposed_name)",
                'summary' => 'evidence_summary',
                'confidence' => 'confidence',
                'created_at' => 'created_at',
                'relationship_type' => 'relationship_type',
                'proposed_name' => 'proposed_name',
                'proposed_birth_date' => 'proposed_birth_date',
                'proposed_birth_place' => 'proposed_birth_place',
                'evidence_sources_json' => 'evidence_sources',
            ]),
            'ProposalRenderer',
            'App\\Services\\Genealogy\\FamilyService',
            'applyProposedRelationship',
            null,
            0,
            0,
            'ops-lilac',
            30,
            1,
        ]);

        // Face Matches (with images)
        DB::insert('
            INSERT INTO review_type_registry
            (name, label, icon, category, source_table, source_connection, count_sql, fetch_sql, approve_sql, reject_sql, field_mapping, vue_renderer, vue_detail_component, actions, requires_image, image_field, batch_enabled, color, display_order, enabled)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ', [
            'face',
            'Face Matches',
            'user-circle',
            'faces',
            'genealogy_face_match_queue',
            'mysql',
            "SELECT COUNT(*) as total FROM genealogy_face_match_queue WHERE status = 'pending'",
            "SELECT f.id, f.tree_id, f.media_id, f.face_name, f.suggested_person_id, f.match_type, f.confidence_score, f.face_region, f.match_details, f.created_at, CONCAT(p.given_name, ' ', p.surname) as suggested_person_name, COALESCE(m.nextcloud_path, m.original_path) as media_path FROM genealogy_face_match_queue f LEFT JOIN genealogy_persons p ON p.id = f.suggested_person_id LEFT JOIN genealogy_media m ON m.id = f.media_id WHERE f.status = 'pending' ORDER BY f.confidence_score DESC, f.created_at ASC LIMIT 100",
            "UPDATE genealogy_face_match_queue SET status = 'approved', reviewed_at = NOW(), updated_at = NOW() WHERE id = ?",
            "UPDATE genealogy_face_match_queue SET status = 'rejected', reviewed_at = NOW(), updated_at = NOW() WHERE id = ?",
            json_encode([
                'unified_id_template' => 'face:{{id}}',
                'id' => 'id',
                'tree_id' => 'tree_id',
                'media_id' => 'media_id',
                'title_expr' => "CONCAT('Face: ', COALESCE(face_name, 'Unknown'))",
                'summary_expr' => "CONCAT('Suggested: ', COALESCE(suggested_person_name, 'New face'))",
                'confidence_expr' => 'confidence_score / 100',
                'created_at' => 'created_at',
                'face_name' => 'face_name',
                'suggested_person_id' => 'suggested_person_id',
                'suggested_person_name' => 'suggested_person_name',
                'match_type' => 'match_type',
                'media_path' => 'media_path',
                'image_url' => '/api/media/face-match-crop/{{id}}',
                'face_region_json' => 'face_region',
            ]),
            'FaceMatchRenderer',
            'FaceMatchDetail',
            json_encode([
                ['name' => 'link', 'label' => 'Link to Person', 'icon' => 'link', 'handler' => 'openLinkModal'],
                ['name' => 'ignore', 'label' => 'Ignore', 'icon' => 'eye-slash', 'handler' => 'ignoreFace'],
            ]),
            1,
            'media_path',
            1,
            'ops-green',
            40,
            1,
        ]);

        // Privacy/Data Removal
        DB::insert('
            INSERT INTO review_type_registry
            (name, label, icon, category, source_table, source_connection, count_sql, fetch_sql, approve_sql, reject_sql, field_mapping, vue_renderer, requires_image, batch_enabled, color, display_order, enabled)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ', [
            'privacy',
            'Privacy Requests',
            'shield-check',
            'privacy',
            'removal_requests',
            'mysql',
            "SELECT COUNT(*) as total FROM removal_requests WHERE requires_review = 1 AND status = 'pending'",
            "SELECT r.id, r.profile_url, r.status, r.ai_confidence, r.ai_notes, r.review_notes, r.created_at, b.name as broker_name, b.url as broker_url, s.name as subject_name FROM removal_requests r LEFT JOIN data_removal_brokers b ON b.id = r.broker_id LEFT JOIN data_removal_subjects s ON s.id = r.subject_id WHERE r.requires_review = 1 AND r.status = 'pending' ORDER BY r.ai_confidence DESC, r.created_at ASC LIMIT 100",
            "UPDATE removal_requests SET status = 'approved', review_notes = CONCAT(COALESCE(review_notes, ''), ' [Approved via Research Hub]'), updated_at = NOW() WHERE id = ?",
            "UPDATE removal_requests SET status = 'rejected', review_notes = CONCAT(COALESCE(review_notes, ''), ' [Rejected via Research Hub]'), updated_at = NOW() WHERE id = ?",
            json_encode([
                'unified_id_template' => 'privacy:{{id}}',
                'id' => 'id',
                'title_expr' => "CONCAT('Remove from: ', COALESCE(broker_name, 'Unknown'))",
                'summary' => 'ai_notes',
                'confidence' => 'ai_confidence',
                'created_at' => 'created_at',
                'profile_url' => 'profile_url',
                'broker_name' => 'broker_name',
                'broker_url' => 'broker_url',
                'subject_name' => 'subject_name',
            ]),
            'PrivacyRequestRenderer',
            0,
            1,
            'ops-peach',
            50,
            1,
        ]);
    }
};
