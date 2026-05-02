<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Face region integration with file registry:
     * - file_registry_faces: links detected faces to files and genealogy persons
     * - Adds face_count to file_registry for quick filtering
     */
    public function up(): void
    {
        // Create linking table for faces in files
        DB::statement("
            CREATE TABLE IF NOT EXISTS file_registry_faces (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                file_registry_id BIGINT UNSIGNED NOT NULL,
                person_name VARCHAR(255) NOT NULL,
                genealogy_person_id INT UNSIGNED NULL COMMENT 'Links to genealogy_persons if matched',
                region_x DECIMAL(10,8) NULL COMMENT 'Normalized X coordinate (0-1)',
                region_y DECIMAL(10,8) NULL COMMENT 'Normalized Y coordinate (0-1)',
                region_w DECIMAL(10,8) NULL COMMENT 'Normalized width (0-1)',
                region_h DECIMAL(10,8) NULL COMMENT 'Normalized height (0-1)',
                confidence DECIMAL(5,2) NULL COMMENT 'Detection confidence 0-100',
                source ENUM('xmp', 'ai_detection', 'manual') NOT NULL DEFAULT 'xmp',
                verified TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_face_file (file_registry_id),
                INDEX idx_face_person_name (person_name),
                INDEX idx_face_genealogy (genealogy_person_id),
                UNIQUE KEY uk_face_file_person (file_registry_id, person_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add face_count to file_registry for quick filtering
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN face_count INT UNSIGNED NULL DEFAULT NULL");
        } catch (\Exception $e) {
            // Column may already exist
        }

        // Add face_scan_at to track when faces were last extracted
        try {
            DB::statement("ALTER TABLE file_registry ADD COLUMN face_scan_at TIMESTAMP NULL");
        } catch (\Exception $e) {
            // Column may already exist
        }
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS file_registry_faces");

        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN face_count");
        } catch (\Exception $e) {
        }
        try {
            DB::statement("ALTER TABLE file_registry DROP COLUMN face_scan_at");
        } catch (\Exception $e) {
        }
    }
};
