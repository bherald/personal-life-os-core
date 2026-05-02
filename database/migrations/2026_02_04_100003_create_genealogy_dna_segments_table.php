<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create genealogy_dna_segments table for chromosome segment data.
     *
     * Stores individual DNA segments shared with matches for chromosome browser visualization.
     * Compatible with GEDmatch and DNA Painter segment formats.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_dna_segments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                match_id INT UNSIGNED NOT NULL COMMENT 'FK to genealogy_dna_matches',
                chromosome TINYINT UNSIGNED NOT NULL COMMENT 'Chromosome number: 1-22, X=23',
                start_position BIGINT UNSIGNED NOT NULL COMMENT 'Segment start position in base pairs',
                end_position BIGINT UNSIGNED NOT NULL COMMENT 'Segment end position in base pairs',
                cm_length DECIMAL(8,2) NOT NULL COMMENT 'Segment length in centiMorgans',
                snp_count INT UNSIGNED NULL COMMENT 'Number of SNPs in segment',
                is_full_ibd TINYINT(1) NULL COMMENT 'Full IBD vs half IBD if known',
                side VARCHAR(10) NULL COMMENT 'maternal, paternal, or unknown',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_match_id (match_id),
                INDEX idx_chromosome (chromosome),
                INDEX idx_position (chromosome, start_position, end_position),
                INDEX idx_cm_length (cm_length DESC),

                CONSTRAINT fk_dna_segments_match FOREIGN KEY (match_id)
                    REFERENCES genealogy_dna_matches(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS genealogy_dna_segments");
    }
};
