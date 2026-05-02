<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // =========================================================================
        // EMAIL SCHEDULED SENDING
        // =========================================================================
        DB::statement("
            CREATE TABLE IF NOT EXISTS email_scheduled (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                to_address VARCHAR(255) NOT NULL,
                cc_address VARCHAR(500) NULL,
                bcc_address VARCHAR(500) NULL,
                subject VARCHAR(500) NOT NULL,
                body TEXT NULL,
                html_body TEXT NULL,
                scheduled_at DATETIME NOT NULL,
                timezone VARCHAR(50) DEFAULT 'America/Los_Angeles',
                status ENUM('pending', 'processing', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
                priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
                error_message TEXT NULL,
                sent_at DATETIME NULL,
                metadata JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_scheduled_status (status, scheduled_at),
                INDEX idx_scheduled_time (scheduled_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS email_recurring_schedules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                to_address VARCHAR(255) NOT NULL,
                subject_template VARCHAR(500) NOT NULL,
                body_template TEXT NULL,
                cron_expression VARCHAR(100) NOT NULL,
                timezone VARCHAR(50) DEFAULT 'America/Los_Angeles',
                is_active TINYINT(1) DEFAULT 1,
                next_run_at DATETIME NULL,
                last_run_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =========================================================================
        // EMAIL ATTACHMENTS & VIRUS SCANNING
        // =========================================================================
        DB::statement("
            CREATE TABLE IF NOT EXISTS email_attachments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email_id INT UNSIGNED NULL,
                filename VARCHAR(255) NOT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_size INT UNSIGNED NOT NULL,
                mime_type VARCHAR(100) NULL,
                nextcloud_path VARCHAR(500) NULL,
                synced_at DATETIME NULL,
                cleanup_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_id (email_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS attachment_virus_scans (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                file_path VARCHAR(500) NOT NULL,
                scan_result ENUM('clean', 'infected', 'error') NOT NULL,
                virus_name VARCHAR(255) NULL,
                scanned_at DATETIME NOT NULL,
                INDEX idx_scanned_at (scanned_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =========================================================================
        // EMAIL AI SUGGESTIONS TRACKING
        // =========================================================================
        DB::statement("
            CREATE TABLE IF NOT EXISTS email_suggestions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email_id INT UNSIGNED NULL,
                suggestion_type VARCHAR(50) NOT NULL,
                suggested_content TEXT NULL,
                status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                modification_level ENUM('none', 'minor', 'major') DEFAULT 'none',
                reviewed_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =========================================================================
        // GENEALOGY VERSIONING
        // =========================================================================
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_versions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entity_type VARCHAR(50) NOT NULL,
                entity_id INT UNSIGNED NOT NULL,
                version_number INT UNSIGNED NOT NULL,
                old_data JSON NULL,
                new_data JSON NULL,
                diff_summary JSON NULL,
                change_reason VARCHAR(500) NULL,
                changed_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_entity (entity_type, entity_id),
                INDEX idx_version (entity_type, entity_id, version_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =========================================================================
        // DATA REMOVAL SUPPRESSION LIST
        // =========================================================================
        DB::statement("
            CREATE TABLE IF NOT EXISTS data_removal_suppression_list (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                url VARCHAR(500) NULL,
                domain VARCHAR(255) NULL,
                type ENUM('temporary', 'permanent', 'rate_limit', 'captcha_block', 'manual_only') DEFAULT 'temporary',
                reason VARCHAR(500) NULL,
                expires_at DATETIME NULL,
                metadata JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_url (url(100)),
                INDEX idx_domain (domain),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS data_removal_suppression_archive (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                original_id INT UNSIGNED NOT NULL,
                url VARCHAR(500) NULL,
                domain VARCHAR(255) NULL,
                type VARCHAR(50) NULL,
                reason VARCHAR(500) NULL,
                metadata JSON NULL,
                created_at DATETIME NULL,
                expired_at DATETIME NULL,
                archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =========================================================================
        // DATA REMOVAL STATE REQUESTS (Multi-State Law)
        // =========================================================================
        DB::statement("
            CREATE TABLE IF NOT EXISTS data_removal_state_requests (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                consumer_name VARCHAR(255) NOT NULL,
                consumer_email VARCHAR(255) NOT NULL,
                state_code CHAR(2) NOT NULL,
                law_code VARCHAR(20) NOT NULL,
                request_type VARCHAR(50) NOT NULL,
                request_date DATE NOT NULL,
                deadline_date DATE NOT NULL,
                status ENUM('pending', 'verified', 'in_progress', 'completed', 'denied', 'extended') DEFAULT 'pending',
                broker_id INT UNSIGNED NULL,
                notes TEXT NULL,
                completed_at DATETIME NULL,
                metadata JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_state (state_code),
                INDEX idx_status (status),
                INDEX idx_deadline (deadline_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =========================================================================
        // WORKFLOW TEMPLATES
        // =========================================================================
        DB::statement("
            CREATE TABLE IF NOT EXISTS workflow_templates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                template_data JSON NOT NULL,
                is_public TINYINT(1) DEFAULT 0,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS workflow_variables (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                workflow_id INT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                value TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_workflow (workflow_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =========================================================================
        // WORKFLOW APPROVAL REQUESTS
        // =========================================================================
        DB::statement("
            CREATE TABLE IF NOT EXISTS workflow_approval_requests (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                workflow_run_id INT UNSIGNED NOT NULL,
                node_id INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                data_snapshot JSON NULL,
                approvers JSON NULL,
                expires_at DATETIME NOT NULL,
                status ENUM('pending', 'approved', 'rejected', 'expired') DEFAULT 'pending',
                escalation_level INT UNSIGNED DEFAULT 0,
                decided_by INT UNSIGNED NULL,
                decided_at DATETIME NULL,
                comments TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_run (workflow_run_id),
                INDEX idx_status (status),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS workflow_approval_notifications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_id INT UNSIGNED NOT NULL,
                approver_email VARCHAR(255) NOT NULL,
                is_escalation TINYINT(1) DEFAULT 0,
                sent_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_request (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS workflow_approval_history (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_id INT UNSIGNED NOT NULL,
                action VARCHAR(50) NOT NULL,
                actor_id INT UNSIGNED NULL,
                comments TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_request (request_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =========================================================================
        // FILE BUNDLES
        // =========================================================================
        DB::statement("
            CREATE TABLE IF NOT EXISTS file_bundles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bundle_type VARCHAR(50) NOT NULL,
                base_name VARCHAR(255) NOT NULL,
                primary_file_id INT UNSIGNED NULL,
                total_size BIGINT UNSIGNED DEFAULT 0,
                file_count INT UNSIGNED DEFAULT 0,
                metadata JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_base_name (base_name),
                INDEX idx_type (bundle_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS file_bundle_members (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bundle_id INT UNSIGNED NOT NULL,
                file_id INT UNSIGNED NULL,
                file_path VARCHAR(500) NOT NULL,
                role ENUM('primary', 'sidecar', 'member') DEFAULT 'member',
                updated_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bundle (bundle_id),
                INDEX idx_path (file_path(100))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =========================================================================
        // STEALTH BROWSER FINGERPRINTS
        // =========================================================================
        DB::statement("
            CREATE TABLE IF NOT EXISTS stealth_fingerprints (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                fingerprint_hash VARCHAR(64) NOT NULL UNIQUE,
                fingerprint_data JSON NOT NULL,
                user_agent TEXT NOT NULL,
                success_count INT UNSIGNED DEFAULT 0,
                failure_count INT UNSIGNED DEFAULT 0,
                last_used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_hash (fingerprint_hash),
                INDEX idx_success (success_count DESC)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =========================================================================
        // ALTER FILE_REGISTRY FOR SEMANTIC SEARCH
        // =========================================================================
        // Check if columns exist before adding
        $columns = DB::select("SHOW COLUMNS FROM file_registry LIKE 'semantic_indexed_at'");
        if (empty($columns)) {
            DB::statement("ALTER TABLE file_registry ADD COLUMN semantic_indexed_at DATETIME NULL");
        }
        $columns = DB::select("SHOW COLUMNS FROM file_registry LIKE 'semantic_chunk_count'");
        if (empty($columns)) {
            DB::statement("ALTER TABLE file_registry ADD COLUMN semantic_chunk_count INT UNSIGNED DEFAULT 0");
        }
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS stealth_fingerprints");
        DB::statement("DROP TABLE IF EXISTS file_bundle_members");
        DB::statement("DROP TABLE IF EXISTS file_bundles");
        DB::statement("DROP TABLE IF EXISTS workflow_approval_history");
        DB::statement("DROP TABLE IF EXISTS workflow_approval_notifications");
        DB::statement("DROP TABLE IF EXISTS workflow_approval_requests");
        DB::statement("DROP TABLE IF EXISTS workflow_variables");
        DB::statement("DROP TABLE IF EXISTS workflow_templates");
        DB::statement("DROP TABLE IF EXISTS data_removal_state_requests");
        DB::statement("DROP TABLE IF EXISTS data_removal_suppression_archive");
        DB::statement("DROP TABLE IF EXISTS data_removal_suppression_list");
        DB::statement("DROP TABLE IF EXISTS genealogy_versions");
        DB::statement("DROP TABLE IF EXISTS email_suggestions");
        DB::statement("DROP TABLE IF EXISTS attachment_virus_scans");
        DB::statement("DROP TABLE IF EXISTS email_attachments");
        DB::statement("DROP TABLE IF EXISTS email_recurring_schedules");
        DB::statement("DROP TABLE IF EXISTS email_scheduled");
    }
};
