<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create genealogy_dna_kits table for DNA test kit tracking.
     *
     * Stores DNA kit information from providers like Ancestry, 23andMe, FTDNA, MyHeritage, GEDmatch.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_dna_kits (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                person_id INT UNSIGNED NOT NULL COMMENT 'FK to genealogy_persons',
                kit_provider ENUM('ancestry', '23andme', 'ftdna', 'myheritage', 'gedmatch', 'livingdna', 'other') NOT NULL COMMENT 'DNA testing company',
                kit_id VARCHAR(100) NULL COMMENT 'Provider-specific kit identifier',
                raw_data_file VARCHAR(500) NULL COMMENT 'Path to uploaded raw DNA data file',
                haplogroup_maternal VARCHAR(50) NULL COMMENT 'mtDNA haplogroup',
                haplogroup_paternal VARCHAR(50) NULL COMMENT 'Y-DNA haplogroup (males only)',
                ethnicity_estimate JSON NULL COMMENT 'Ethnicity/ancestry breakdown from provider',
                total_cm_shared DECIMAL(10,2) NULL COMMENT 'Total cM in kit for reference',
                uploaded_at TIMESTAMP NULL COMMENT 'When raw data was uploaded',
                last_match_sync TIMESTAMP NULL COMMENT 'Last time matches were synced from provider',
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_person_id (person_id),
                INDEX idx_kit_provider (kit_provider),
                INDEX idx_kit_id (kit_id),
                UNIQUE KEY unique_person_provider (person_id, kit_provider),

                CONSTRAINT fk_dna_kits_person FOREIGN KEY (person_id)
                    REFERENCES genealogy_persons(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS genealogy_dna_kits");
    }
};
