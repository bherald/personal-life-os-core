<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration for Enhancement N04-N16 Tables
 *
 * N04: Genealogy Name Variants (enhanced existing table)
 * N05: Historical Maps
 * N06: DNA Triangulation Groups
 * N13: Identity Verification
 * N14: Alias/Variant Detection
 * N16: Removal Proof Archive (enhanced existing table)
 */
return new class extends Migration
{
    public function up(): void
    {
        // =====================================================================
        // N05: Historical Maps Tables
        // =====================================================================

        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_historical_maps (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                map_year INT NULL,
                source VARCHAR(100) NULL,
                source_url VARCHAR(500) NULL,
                tile_url VARCHAR(500) NULL,
                bounds JSON NULL,
                center_latitude DECIMAL(10,6) NULL,
                center_longitude DECIMAL(10,6) NULL,
                attribution TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_year (map_year),
                INDEX idx_location (center_latitude, center_longitude)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_historical_boundaries (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                census_year INT NOT NULL,
                country VARCHAR(10) NOT NULL DEFAULT 'US',
                state_code VARCHAR(10) NULL,
                boundary_name VARCHAR(255) NOT NULL,
                boundary_type ENUM('state', 'county', 'township', 'city') NOT NULL,
                boundary_level INT DEFAULT 1,
                geojson_data LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_year_country (census_year, country),
                INDEX idx_state (state_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_place_history (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                place_id INT UNSIGNED NOT NULL,
                historical_name VARCHAR(255) NOT NULL,
                name_type ENUM('official', 'common', 'variant', 'native') DEFAULT 'official',
                valid_from DATE NULL,
                valid_to DATE NULL,
                source VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_place (place_id),
                INDEX idx_dates (valid_from, valid_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =====================================================================
        // N06: DNA Triangulation Groups Table
        // =====================================================================

        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_dna_triangulation_groups (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                kit_id INT UNSIGNED NOT NULL,
                group_number INT NOT NULL,
                match_count INT NOT NULL,
                match_hash VARCHAR(32) NOT NULL,
                match_ids JSON NOT NULL,
                group_data JSON NULL,
                avg_shared_cm DECIMAL(8,2) NULL,
                chromosome_count INT NULL,
                cohesion_percent DECIMAL(5,2) NULL,
                estimated_relationship VARCHAR(50) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_kit (kit_id),
                UNIQUE INDEX idx_kit_hash (kit_id, match_hash),
                INDEX idx_relationship (estimated_relationship)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =====================================================================
        // N13: Identity Verification Tables
        // =====================================================================

        DB::statement("
            CREATE TABLE IF NOT EXISTS identity_verification_documents (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                document_id VARCHAR(36) NOT NULL UNIQUE,
                subject_id INT UNSIGNED NOT NULL,
                document_type ENUM('drivers_license', 'passport', 'state_id', 'utility_bill', 'bank_statement', 'notarized_affidavit') NOT NULL,
                original_filename VARCHAR(255) NULL,
                mime_type VARCHAR(100) NULL,
                file_hash VARCHAR(64) NULL,
                encrypted_path VARCHAR(500) NULL,
                auto_redacted TINYINT(1) DEFAULT 0,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_subject (subject_id),
                INDEX idx_expires (expires_at),
                INDEX idx_type (document_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS identity_verification_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                document_id INT UNSIGNED NOT NULL,
                broker_id INT UNSIGNED NOT NULL,
                token VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_token (token),
                INDEX idx_document (document_id),
                INDEX idx_broker (broker_id),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS identity_verification_redactions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                document_id INT UNSIGNED NOT NULL,
                redacted_version_id VARCHAR(36) NOT NULL,
                redaction_data JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_document (document_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS identity_verification_audit (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                document_id INT UNSIGNED NOT NULL,
                action VARCHAR(50) NOT NULL,
                metadata JSON NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_document (document_id),
                INDEX idx_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS broker_verification_requirements (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                broker_id INT UNSIGNED NOT NULL UNIQUE,
                requires_id TINYINT(1) DEFAULT 0,
                requires_proof_of_address TINYINT(1) DEFAULT 0,
                requires_notarization TINYINT(1) DEFAULT 0,
                accepted_documents JSON NULL,
                additional_requirements JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_broker (broker_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =====================================================================
        // N14: Subject Name Variants Table
        // =====================================================================

        DB::statement("
            CREATE TABLE IF NOT EXISTS subject_name_variants (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                subject_id INT UNSIGNED NOT NULL,
                variant_type VARCHAR(50) NOT NULL,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                full_name VARCHAR(255) NULL,
                source VARCHAR(50) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_subject (subject_id),
                INDEX idx_type (variant_type),
                INDEX idx_names (first_name, last_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // =====================================================================
        // N16: Removal Proof Archive Enhancements
        // =====================================================================

        DB::statement("
            CREATE TABLE IF NOT EXISTS removal_verification_schedule (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                removal_request_id INT UNSIGNED NOT NULL,
                search_url VARCHAR(500) NOT NULL,
                interval_days INT DEFAULT 30,
                next_check_date DATE NOT NULL,
                last_check_date DATE NULL,
                last_check_result VARCHAR(50) NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_request (removal_request_id),
                INDEX idx_next_check (next_check_date, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        DB::statement("
            CREATE TABLE IF NOT EXISTS removal_proof_audit (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                removal_request_id INT UNSIGNED NOT NULL,
                action VARCHAR(50) NOT NULL,
                metadata JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_request (removal_request_id),
                INDEX idx_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        // N16 tables
        DB::statement("DROP TABLE IF EXISTS removal_proof_audit");
        DB::statement("DROP TABLE IF EXISTS removal_verification_schedule");

        // N14 table
        DB::statement("DROP TABLE IF EXISTS subject_name_variants");

        // N13 tables
        DB::statement("DROP TABLE IF EXISTS broker_verification_requirements");
        DB::statement("DROP TABLE IF EXISTS identity_verification_audit");
        DB::statement("DROP TABLE IF EXISTS identity_verification_redactions");
        DB::statement("DROP TABLE IF EXISTS identity_verification_tokens");
        DB::statement("DROP TABLE IF EXISTS identity_verification_documents");

        // N06 table
        DB::statement("DROP TABLE IF EXISTS genealogy_dna_triangulation_groups");

        // N05 tables
        DB::statement("DROP TABLE IF EXISTS genealogy_place_history");
        DB::statement("DROP TABLE IF EXISTS genealogy_historical_boundaries");
        DB::statement("DROP TABLE IF EXISTS genealogy_historical_maps");
    }
};
