<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Genealogy module enhancements:
     * - Change history / audit trail
     * - Name variants (maiden, alias, nickname, etc.)
     * - Report generation storage
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_change_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entity_type ENUM('person', 'family', 'event', 'source') NOT NULL,
                entity_id INT UNSIGNED NOT NULL,
                field_name VARCHAR(100) NOT NULL,
                old_value TEXT NULL,
                new_value TEXT NULL,
                changed_by VARCHAR(100) NULL DEFAULT 'system',
                change_source ENUM('manual', 'import', 'ai', 'merge') NOT NULL DEFAULT 'manual',
                change_notes TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_change_entity (entity_type, entity_id),
                INDEX idx_change_created (created_at),
                INDEX idx_change_source (change_source)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_name_variants (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                person_id INT UNSIGNED NOT NULL,
                name_type ENUM('birth', 'married', 'maiden', 'alias', 'nickname', 'religious', 'phonetic') NOT NULL,
                given_names VARCHAR(255) NULL,
                surname VARCHAR(255) NULL,
                full_name VARCHAR(500) NULL,
                source_id INT UNSIGNED NULL,
                notes TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_name_variants_person (person_id),
                INDEX idx_name_variants_type (name_type),
                INDEX idx_name_variants_surname (surname)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_reports (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                report_type ENUM('ahnentafel', 'descendant', 'pedigree', 'family_group') NOT NULL,
                person_id INT UNSIGNED NULL,
                tree_id INT UNSIGNED NOT NULL,
                parameters JSON NULL,
                content LONGTEXT NULL,
                format ENUM('html', 'pdf', 'text') NOT NULL DEFAULT 'html',
                generated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_reports_type (report_type),
                INDEX idx_reports_person (person_id),
                INDEX idx_reports_tree (tree_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS genealogy_reports");
        DB::statement("DROP TABLE IF EXISTS genealogy_name_variants");
        DB::statement("DROP TABLE IF EXISTS genealogy_change_history");
    }
};
