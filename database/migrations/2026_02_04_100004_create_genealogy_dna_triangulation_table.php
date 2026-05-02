<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create genealogy_dna_triangulation table for triangulated DNA segments.
     *
     * Stores triangulation groups where 3+ people share overlapping DNA segments,
     * indicating descent from a common ancestor.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_dna_triangulation (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                kit_id INT UNSIGNED NOT NULL COMMENT 'FK to source kit for triangulation',
                match_id_1 INT UNSIGNED NOT NULL COMMENT 'First match in triangulation',
                match_id_2 INT UNSIGNED NOT NULL COMMENT 'Second match in triangulation',
                match_id_3 INT UNSIGNED NULL COMMENT 'Optional third match (can extend groups)',
                chromosome TINYINT UNSIGNED NOT NULL COMMENT 'Chromosome number: 1-22, X=23',
                overlap_start BIGINT UNSIGNED NOT NULL COMMENT 'Overlapping segment start position',
                overlap_end BIGINT UNSIGNED NOT NULL COMMENT 'Overlapping segment end position',
                overlap_cm DECIMAL(8,2) NULL COMMENT 'Overlapping segment length in cM',
                common_ancestor_id INT UNSIGNED NULL COMMENT 'FK to genealogy_persons if identified',
                confidence ENUM('high', 'medium', 'low') DEFAULT 'medium' COMMENT 'Triangulation confidence level',
                verification_status ENUM('unverified', 'verified', 'rejected') DEFAULT 'unverified',
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_kit_id (kit_id),
                INDEX idx_match_1 (match_id_1),
                INDEX idx_match_2 (match_id_2),
                INDEX idx_match_3 (match_id_3),
                INDEX idx_chromosome (chromosome),
                INDEX idx_common_ancestor (common_ancestor_id),
                INDEX idx_confidence (confidence),
                UNIQUE KEY unique_triangulation (kit_id, match_id_1, match_id_2, chromosome, overlap_start),

                CONSTRAINT fk_triangulation_kit FOREIGN KEY (kit_id)
                    REFERENCES genealogy_dna_kits(id) ON DELETE CASCADE,
                CONSTRAINT fk_triangulation_match1 FOREIGN KEY (match_id_1)
                    REFERENCES genealogy_dna_matches(id) ON DELETE CASCADE,
                CONSTRAINT fk_triangulation_match2 FOREIGN KEY (match_id_2)
                    REFERENCES genealogy_dna_matches(id) ON DELETE CASCADE,
                CONSTRAINT fk_triangulation_match3 FOREIGN KEY (match_id_3)
                    REFERENCES genealogy_dna_matches(id) ON DELETE SET NULL,
                CONSTRAINT fk_triangulation_ancestor FOREIGN KEY (common_ancestor_id)
                    REFERENCES genealogy_persons(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS genealogy_dna_triangulation");
    }
};
