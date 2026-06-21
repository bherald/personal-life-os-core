<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS person_face_appearance_profiles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                identity_key VARCHAR(320) NOT NULL,
                identity_type ENUM('genealogy_person', 'name') NOT NULL,
                tree_id INT UNSIGNED NULL,
                genealogy_person_id INT UNSIGNED NULL,
                display_name VARCHAR(255) NOT NULL,
                age_band ENUM('baby', 'child', 'teen', 'adult', 'senior', 'unknown') NOT NULL DEFAULT 'unknown',
                age_min TINYINT UNSIGNED NULL,
                age_max TINYINT UNSIGNED NULL,
                representative_file_registry_face_id BIGINT UNSIGNED NULL,
                representative_face_embedding_id BIGINT UNSIGNED NULL COMMENT 'pgsql_rag.face_embeddings.id; no cross-database FK',
                representative_person_cluster_id INT UNSIGNED NULL COMMENT 'pgsql_rag.person_clusters.id; no cross-database FK',
                face_count INT UNSIGNED NOT NULL DEFAULT 0,
                verified_face_count INT UNSIGNED NOT NULL DEFAULT 0,
                confidence DECIMAL(5,2) NULL,
                source VARCHAR(64) NOT NULL DEFAULT 'derived_from_faces',
                metadata JSON NULL,
                notes TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_pfap_identity_band (identity_key, age_band),
                KEY idx_pfap_tree_identity (tree_id, identity_key),
                KEY idx_pfap_person_band (genealogy_person_id, age_band),
                KEY idx_pfap_display_name (display_name),
                KEY idx_pfap_rep_face (representative_file_registry_face_id),
                KEY idx_pfap_rep_embedding (representative_face_embedding_id),
                CONSTRAINT fk_pfap_genealogy_person
                    FOREIGN KEY (genealogy_person_id)
                    REFERENCES genealogy_persons(id)
                    ON DELETE SET NULL,
                CONSTRAINT fk_pfap_representative_face
                    FOREIGN KEY (representative_file_registry_face_id)
                    REFERENCES file_registry_faces(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS person_face_profile_faces (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                profile_id BIGINT UNSIGNED NOT NULL,
                file_registry_face_id BIGINT UNSIGNED NOT NULL,
                file_registry_id BIGINT UNSIGNED NOT NULL,
                face_embedding_id BIGINT UNSIGNED NULL COMMENT 'pgsql_rag.face_embeddings.id; no cross-database FK',
                person_cluster_id INT UNSIGNED NULL COMMENT 'pgsql_rag.person_clusters.id; no cross-database FK',
                age_at_photo TINYINT UNSIGNED NULL,
                photo_date DATE NULL,
                birth_year SMALLINT UNSIGNED NULL,
                age_band ENUM('baby', 'child', 'teen', 'adult', 'senior', 'unknown') NOT NULL DEFAULT 'unknown',
                evidence_source VARCHAR(64) NOT NULL DEFAULT 'unknown',
                confidence DECIMAL(5,2) NULL,
                verified TINYINT(1) NOT NULL DEFAULT 0,
                metadata JSON NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_pfp_face (file_registry_face_id),
                KEY idx_pfp_profile_band (profile_id, age_band),
                KEY idx_pfp_file (file_registry_id),
                KEY idx_pfp_embedding (face_embedding_id),
                KEY idx_pfp_cluster (person_cluster_id),
                KEY idx_pfp_age (age_band, age_at_photo),
                CONSTRAINT fk_pfp_profile
                    FOREIGN KEY (profile_id)
                    REFERENCES person_face_appearance_profiles(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_pfp_face
                    FOREIGN KEY (file_registry_face_id)
                    REFERENCES file_registry_faces(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_pfp_file
                    FOREIGN KEY (file_registry_id)
                    REFERENCES file_registry(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS person_face_profile_faces');
        DB::statement('DROP TABLE IF EXISTS person_face_appearance_profiles');
    }
};
