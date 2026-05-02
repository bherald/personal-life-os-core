<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Email module enhancements:
     * - Draft version history
     * - Scheduled sending enhancements
     * - Email analytics
     * - Attachment management
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS email_draft_versions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                draft_id INT UNSIGNED NOT NULL,
                version_number INT UNSIGNED NOT NULL DEFAULT 1,
                content LONGTEXT NOT NULL,
                changed_by ENUM('ai', 'human') NOT NULL DEFAULT 'human',
                change_type ENUM('created', 'edited', 'approved', 'rejected') NOT NULL DEFAULT 'created',
                diff_summary TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_draft_versions_draft (draft_id),
                INDEX idx_draft_versions_number (draft_id, version_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add columns to email_scheduled if they don't exist
        try {
            DB::statement("ALTER TABLE email_scheduled ADD COLUMN timezone VARCHAR(50) NULL DEFAULT 'America/Los_Angeles'");
        } catch (\Exception $e) {
            // Column may already exist
        }
        try {
            DB::statement("ALTER TABLE email_scheduled ADD COLUMN recurring_pattern VARCHAR(100) NULL");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE email_scheduled ADD COLUMN last_sent_at TIMESTAMP NULL");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE email_scheduled ADD COLUMN next_send_at TIMESTAMP NULL");
        } catch (\Exception $e) {
        }

        DB::statement("
            CREATE TABLE IF NOT EXISTS email_analytics (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                metric_type VARCHAR(50) NOT NULL,
                metric_date DATE NOT NULL,
                metric_value INT NOT NULL DEFAULT 0,
                metadata JSON NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_analytics_type_date (metric_type, metric_date),
                UNIQUE KEY uk_analytics_type_date (metric_type, metric_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS email_attachments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                draft_id INT UNSIGNED NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(100) NULL,
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                virus_scan_status ENUM('pending', 'clean', 'infected', 'skipped') NOT NULL DEFAULT 'pending',
                nextcloud_path VARCHAR(500) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_attachments_draft (draft_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS email_attachments");
        DB::statement("DROP TABLE IF EXISTS email_analytics");
        DB::statement("DROP TABLE IF EXISTS email_draft_versions");

        try {
            DB::statement("ALTER TABLE email_scheduled DROP COLUMN timezone");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE email_scheduled DROP COLUMN recurring_pattern");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE email_scheduled DROP COLUMN last_sent_at");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE email_scheduled DROP COLUMN next_send_at");
        } catch (\Exception $e) {
        }
    }
};
