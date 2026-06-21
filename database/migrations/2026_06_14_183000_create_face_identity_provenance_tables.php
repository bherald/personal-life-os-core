<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS photoprism_face_export_rows (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                export_batch VARCHAR(100) NOT NULL,
                source_path TEXT NOT NULL,
                normalized_path VARCHAR(700) NOT NULL,
                normalized_path_hash CHAR(64) NOT NULL,
                face_name VARCHAR(255) NOT NULL,
                normalized_face_name VARCHAR(255) NOT NULL,
                region_x DECIMAL(10,8) NULL,
                region_y DECIMAL(10,8) NULL,
                region_w DECIMAL(10,8) NULL,
                region_h DECIMAL(10,8) NULL,
                cluster_ref VARCHAR(100) NULL,
                row_hash CHAR(64) NOT NULL,
                imported_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY pp_face_export_batch_row_unique (export_batch, row_hash),
                KEY pp_face_export_batch_path_name_idx (export_batch, normalized_path_hash, normalized_face_name),
                KEY pp_face_export_batch_name_idx (export_batch, normalized_face_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS face_identity_provenance (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                file_registry_face_id BIGINT UNSIGNED NOT NULL,
                file_registry_id BIGINT UNSIGNED NOT NULL,
                genealogy_person_id INT UNSIGNED NULL,
                tree_id INT UNSIGNED NOT NULL,
                export_batch VARCHAR(100) NOT NULL,
                status VARCHAR(60) NOT NULL,
                evidence_source VARCHAR(60) NOT NULL,
                export_path_hash CHAR(64) NULL,
                export_path_key VARCHAR(700) NULL,
                face_name VARCHAR(255) NULL,
                normalized_face_name VARCHAR(255) NULL,
                canonical_name VARCHAR(500) NULL,
                matched_export_name VARCHAR(255) NULL,
                matched_variant_name VARCHAR(255) NULL,
                reasons JSON NULL,
                checked_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY face_identity_provenance_face_unique (file_registry_face_id),
                KEY face_identity_provenance_status_idx (status),
                KEY face_identity_provenance_person_status_idx (genealogy_person_id, status),
                KEY face_identity_provenance_batch_idx (export_batch),
                KEY face_identity_provenance_file_idx (file_registry_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->upsertConfig(
            'faces',
            'person_page_trust_mode',
            'provenance_trusted',
            'string',
            'Controls /media/faces/person default identity display. Values: provenance_trusted (show only export/copy/variant-backed rows), legacy_all (old behavior), review_all (show all rows but mark provenance).'
        );
    }

    public function down(): void
    {
        DB::delete(
            'DELETE FROM system_configs WHERE section = ? AND config_key = ? AND config_value = ?',
            ['faces', 'person_page_trust_mode', 'provenance_trusted']
        );
        DB::statement('DROP TABLE IF EXISTS face_identity_provenance');
        DB::statement('DROP TABLE IF EXISTS photoprism_face_export_rows');
    }

    private function upsertConfig(string $section, string $key, string $value, string $type, string $description): void
    {
        $existing = DB::selectOne(
            'SELECT id FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
            [$section, $key]
        );

        if ($existing === null) {
            DB::insert(
                'INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                [$section, $key, $value, $type, $description]
            );

            return;
        }

        DB::update(
            'UPDATE system_configs
             SET config_value = ?, data_type = ?, description = ?, updated_at = NOW()
             WHERE section = ? AND config_key = ?',
            [$value, $type, $description, $section, $key]
        );
    }
};
