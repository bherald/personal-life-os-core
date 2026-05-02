<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add GEDCOM 7.0 specific fields to genealogy tables
 *
 * GEDCOM 7.0 introduces several new concepts:
 * - UID: Unique identifier for records (UUID format)
 * - EXID: External identifiers with TYPE (stored in separate table)
 * - SNOTE: Shared notes (reusable note records)
 * - LANG: Language tags for multilingual content
 * - Schema extensions tracking
 *
 * @see https://gedcom.io/specifications/FamilySearchGEDCOMv7.html
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add UID column to persons if not exists
        if (!Schema::hasColumn('genealogy_persons', 'uid')) {
            DB::statement("ALTER TABLE genealogy_persons ADD COLUMN uid VARCHAR(100) NULL AFTER gedcom_id");
            DB::statement("CREATE INDEX idx_persons_uid ON genealogy_persons(uid)");
        }

        // Add language column to persons
        if (!Schema::hasColumn('genealogy_persons', 'primary_language')) {
            DB::statement("ALTER TABLE genealogy_persons ADD COLUMN primary_language VARCHAR(10) NULL AFTER notes");
        }

        // Add UID to families
        if (!Schema::hasColumn('genealogy_families', 'uid')) {
            DB::statement("ALTER TABLE genealogy_families ADD COLUMN uid VARCHAR(100) NULL AFTER gedcom_id");
            DB::statement("CREATE INDEX idx_families_uid ON genealogy_families(uid)");
        }

        // Add UID to sources
        if (!Schema::hasColumn('genealogy_sources', 'uid')) {
            DB::statement("ALTER TABLE genealogy_sources ADD COLUMN uid VARCHAR(100) NULL AFTER gedcom_id");
            DB::statement("CREATE INDEX idx_sources_uid ON genealogy_sources(uid)");
        }

        // Add UID to media
        if (!Schema::hasColumn('genealogy_media', 'uid')) {
            DB::statement("ALTER TABLE genealogy_media ADD COLUMN uid VARCHAR(100) NULL AFTER gedcom_id");
            DB::statement("CREATE INDEX idx_media_uid ON genealogy_media(uid)");
        }

        // Add UID to repositories
        if (!Schema::hasColumn('genealogy_repositories', 'uid')) {
            DB::statement("ALTER TABLE genealogy_repositories ADD COLUMN uid VARCHAR(100) NULL AFTER gedcom_id");
        }

        // Create shared notes table (GEDCOM 7.0 SNOTE records)
        if (!Schema::hasTable('genealogy_shared_notes')) {
            DB::statement("
                CREATE TABLE genealogy_shared_notes (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    tree_id INT UNSIGNED NOT NULL,
                    gedcom_id VARCHAR(20) NULL,
                    uid VARCHAR(100) NULL,
                    note_text MEDIUMTEXT NOT NULL,
                    mime_type VARCHAR(100) NULL,
                    language VARCHAR(10) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_shared_notes_tree (tree_id),
                    INDEX idx_shared_notes_uid (uid),
                    CONSTRAINT fk_shared_notes_tree FOREIGN KEY (tree_id) REFERENCES genealogy_trees(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // Create shared note translations table (GEDCOM 7.0 TRAN)
        if (!Schema::hasTable('genealogy_shared_note_translations')) {
            DB::statement("
                CREATE TABLE genealogy_shared_note_translations (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    shared_note_id INT UNSIGNED NOT NULL,
                    language VARCHAR(10) NOT NULL,
                    mime_type VARCHAR(100) NULL,
                    translated_text MEDIUMTEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_snote_trans_note (shared_note_id),
                    CONSTRAINT fk_snote_trans_note FOREIGN KEY (shared_note_id) REFERENCES genealogy_shared_notes(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // Create shared note references table (links SNOTE to records)
        if (!Schema::hasTable('genealogy_shared_note_refs')) {
            DB::statement("
                CREATE TABLE genealogy_shared_note_refs (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    shared_note_id INT UNSIGNED NOT NULL,
                    record_type ENUM('person', 'family', 'source', 'media', 'repository') NOT NULL,
                    record_id INT UNSIGNED NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_snote_ref_note (shared_note_id),
                    INDEX idx_snote_ref_record (record_type, record_id),
                    CONSTRAINT fk_snote_ref_note FOREIGN KEY (shared_note_id) REFERENCES genealogy_shared_notes(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // Create external identifiers table (GEDCOM 7.0 EXID with TYPE)
        if (!Schema::hasTable('genealogy_external_ids')) {
            DB::statement("
                CREATE TABLE genealogy_external_ids (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    record_type ENUM('person', 'family', 'source', 'media', 'repository', 'place') NOT NULL,
                    record_id INT UNSIGNED NOT NULL,
                    external_id VARCHAR(500) NOT NULL,
                    id_type VARCHAR(500) NULL COMMENT 'URI identifying the authority',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_exid_record (record_type, record_id),
                    INDEX idx_exid_external (external_id(100)),
                    INDEX idx_exid_type (id_type(100))
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // Create media file variants table (GEDCOM 7.0 allows multiple FILE per OBJE)
        if (!Schema::hasTable('genealogy_media_files')) {
            DB::statement("
                CREATE TABLE genealogy_media_files (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    media_id INT UNSIGNED NOT NULL,
                    file_path VARCHAR(1000) NOT NULL,
                    mime_type VARCHAR(100) NULL,
                    media_type VARCHAR(50) NULL COMMENT 'GEDCOM TYPE value',
                    title VARCHAR(500) NULL,
                    is_primary TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_media_files_media (media_id),
                    CONSTRAINT fk_media_files_media FOREIGN KEY (media_id) REFERENCES genealogy_media(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // Create media crop table (GEDCOM 7.0 CROP structure)
        if (!Schema::hasTable('genealogy_media_crops')) {
            DB::statement("
                CREATE TABLE genealogy_media_crops (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    media_id INT UNSIGNED NOT NULL,
                    crop_top INT NULL,
                    crop_left INT NULL,
                    crop_width INT NULL,
                    crop_height INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_media_crops_media (media_id),
                    CONSTRAINT fk_media_crops_media FOREIGN KEY (media_id) REFERENCES genealogy_media(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // Create name translations table (GEDCOM 7.0 TRAN for NAME)
        if (!Schema::hasTable('genealogy_name_translations')) {
            DB::statement("
                CREATE TABLE genealogy_name_translations (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    person_id INT UNSIGNED NOT NULL,
                    language VARCHAR(10) NOT NULL,
                    translated_name VARCHAR(500) NOT NULL,
                    given_name VARCHAR(255) NULL,
                    surname VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_name_trans_person (person_id),
                    CONSTRAINT fk_name_trans_person FOREIGN KEY (person_id) REFERENCES genealogy_persons(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // Create schema extensions table (tracks custom GEDCOM 7.0 extensions used)
        if (!Schema::hasTable('genealogy_schema_extensions')) {
            DB::statement("
                CREATE TABLE genealogy_schema_extensions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    tree_id INT UNSIGNED NOT NULL,
                    extension_tag VARCHAR(50) NOT NULL,
                    extension_uri VARCHAR(500) NOT NULL,
                    description VARCHAR(500) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_schema_ext_tree (tree_id),
                    UNIQUE KEY uk_schema_ext_tree_tag (tree_id, extension_tag),
                    CONSTRAINT fk_schema_ext_tree FOREIGN KEY (tree_id) REFERENCES genealogy_trees(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        // Add GEDCOM version tracking to trees
        if (!Schema::hasColumn('genealogy_trees', 'gedcom_version')) {
            DB::statement("ALTER TABLE genealogy_trees ADD COLUMN gedcom_version VARCHAR(10) NULL DEFAULT '5.5.1' AFTER source_file");
        }

        // Add default language to trees
        if (!Schema::hasColumn('genealogy_trees', 'default_language')) {
            DB::statement("ALTER TABLE genealogy_trees ADD COLUMN default_language VARCHAR(10) NULL DEFAULT 'en' AFTER gedcom_version");
        }
    }

    public function down(): void
    {
        // Drop new tables
        DB::statement("DROP TABLE IF EXISTS genealogy_schema_extensions");
        DB::statement("DROP TABLE IF EXISTS genealogy_name_translations");
        DB::statement("DROP TABLE IF EXISTS genealogy_media_crops");
        DB::statement("DROP TABLE IF EXISTS genealogy_media_files");
        DB::statement("DROP TABLE IF EXISTS genealogy_external_ids");
        DB::statement("DROP TABLE IF EXISTS genealogy_shared_note_refs");
        DB::statement("DROP TABLE IF EXISTS genealogy_shared_note_translations");
        DB::statement("DROP TABLE IF EXISTS genealogy_shared_notes");

        // Remove new columns (only if safe)
        if (Schema::hasColumn('genealogy_persons', 'uid')) {
            DB::statement("ALTER TABLE genealogy_persons DROP COLUMN uid");
        }
        if (Schema::hasColumn('genealogy_persons', 'primary_language')) {
            DB::statement("ALTER TABLE genealogy_persons DROP COLUMN primary_language");
        }
        if (Schema::hasColumn('genealogy_families', 'uid')) {
            DB::statement("ALTER TABLE genealogy_families DROP COLUMN uid");
        }
        if (Schema::hasColumn('genealogy_sources', 'uid')) {
            DB::statement("ALTER TABLE genealogy_sources DROP COLUMN uid");
        }
        if (Schema::hasColumn('genealogy_media', 'uid')) {
            DB::statement("ALTER TABLE genealogy_media DROP COLUMN uid");
        }
        if (Schema::hasColumn('genealogy_repositories', 'uid')) {
            DB::statement("ALTER TABLE genealogy_repositories DROP COLUMN uid");
        }
        if (Schema::hasColumn('genealogy_trees', 'gedcom_version')) {
            DB::statement("ALTER TABLE genealogy_trees DROP COLUMN gedcom_version");
        }
        if (Schema::hasColumn('genealogy_trees', 'default_language')) {
            DB::statement("ALTER TABLE genealogy_trees DROP COLUMN default_language");
        }
    }
};
