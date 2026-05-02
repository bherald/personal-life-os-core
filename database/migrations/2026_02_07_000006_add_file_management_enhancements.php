<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * File management enhancements:
     * - Quarantine system
     * - File bundles (RAW+JPG, video+subtitle grouping)
     * - Collections (albums, smart collections)
     * - Version tracking
     * - Semantic search columns
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS file_quarantine (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                file_registry_id INT UNSIGNED NULL,
                asset_uuid CHAR(36) NULL,
                reason ENUM('suspicious', 'malformed', 'policy_violation', 'manual') NOT NULL DEFAULT 'manual',
                detected_by ENUM('scan', 'ai', 'manual') NOT NULL DEFAULT 'manual',
                details JSON NULL,
                status ENUM('quarantined', 'reviewed', 'released', 'deleted') NOT NULL DEFAULT 'quarantined',
                reviewed_by VARCHAR(100) NULL,
                reviewed_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_quarantine_file (file_registry_id),
                INDEX idx_quarantine_status (status),
                INDEX idx_quarantine_reason (reason)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS file_bundles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                primary_file_id INT UNSIGNED NULL,
                name VARCHAR(255) NOT NULL,
                bundle_type ENUM('raw_jpg', 'video_subtitle', 'document_set', 'photo_series') NOT NULL,
                auto_detected TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bundles_primary (primary_file_id),
                INDEX idx_bundles_type (bundle_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS file_bundle_members (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bundle_id INT UNSIGNED NOT NULL,
                file_registry_id INT UNSIGNED NOT NULL,
                role ENUM('primary', 'sidecar', 'related') NOT NULL DEFAULT 'related',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bundle_members_bundle (bundle_id),
                INDEX idx_bundle_members_file (file_registry_id),
                UNIQUE KEY uk_bundle_file (bundle_id, file_registry_id),
                CONSTRAINT fk_bundle_members_bundle FOREIGN KEY (bundle_id) REFERENCES file_bundles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS file_collections (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                collection_type ENUM('album', 'project', 'tag_group') NOT NULL DEFAULT 'album',
                cover_image_uuid CHAR(36) NULL,
                is_smart TINYINT(1) NOT NULL DEFAULT 0,
                smart_criteria JSON NULL,
                item_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_collections_type (collection_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS file_collection_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                collection_id INT UNSIGNED NOT NULL,
                file_registry_id INT UNSIGNED NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                added_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_collection_file (collection_id, file_registry_id),
                INDEX idx_collection_items_collection (collection_id),
                CONSTRAINT fk_collection_items_collection FOREIGN KEY (collection_id) REFERENCES file_collections(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS file_versions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                file_registry_id INT UNSIGNED NOT NULL,
                version_number INT UNSIGNED NOT NULL DEFAULT 1,
                nextcloud_path VARCHAR(500) NOT NULL,
                file_size BIGINT UNSIGNED NULL,
                content_hash VARCHAR(64) NULL,
                change_description TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_versions_file (file_registry_id),
                INDEX idx_versions_number (file_registry_id, version_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add semantic search columns to file_registry
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN ai_description TEXT NULL");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN search_keywords TEXT NULL");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN quarantine_status VARCHAR(20) NULL DEFAULT NULL");
        } catch (\Exception $e) {
        }
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS file_collection_items");
        DB::statement("DROP TABLE IF EXISTS file_collections");
        DB::statement("DROP TABLE IF EXISTS file_bundle_members");
        DB::statement("DROP TABLE IF EXISTS file_bundles");
        DB::statement("DROP TABLE IF EXISTS file_versions");
        DB::statement("DROP TABLE IF EXISTS file_quarantine");

        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN ai_description");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN search_keywords");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN quarantine_status");
        } catch (\Exception $e) {
        }
    }
};
