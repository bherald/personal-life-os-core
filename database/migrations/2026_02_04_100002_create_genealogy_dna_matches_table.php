<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create genealogy_dna_matches table for DNA match tracking.
     *
     * Stores DNA matches imported from various providers with relationship predictions.
     * Based on DNA Painter cM ranges and GEDmatch data structures.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_dna_matches (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                kit_id INT UNSIGNED NOT NULL COMMENT 'FK to genealogy_dna_kits',
                match_name VARCHAR(255) NOT NULL COMMENT 'Name of the DNA match',
                match_kit_id VARCHAR(100) NULL COMMENT 'Match kit ID if known (for cross-referencing)',
                match_provider_id VARCHAR(100) NULL COMMENT 'Provider-specific match identifier',
                shared_cm DECIMAL(8,2) NOT NULL COMMENT 'Total shared centiMorgans',
                shared_segments INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of shared DNA segments',
                longest_segment_cm DECIMAL(8,2) NULL COMMENT 'Longest shared segment in cM',
                predicted_relationship VARCHAR(100) NULL COMMENT 'AI/algorithm predicted relationship',
                confidence_score DECIMAL(5,2) NULL COMMENT 'Prediction confidence 0-100',
                confirmed_relationship VARCHAR(100) NULL COMMENT 'User-confirmed actual relationship',
                common_ancestor_id INT UNSIGNED NULL COMMENT 'FK to genealogy_persons if identified',
                maternal_side TINYINT(1) NULL COMMENT '1=maternal, 0=paternal, NULL=unknown',
                match_tree_url VARCHAR(500) NULL COMMENT 'URL to match family tree if available',
                match_tree_size INT UNSIGNED NULL COMMENT 'Number of people in match tree',
                shared_ancestor_hints JSON NULL COMMENT 'Potential shared ancestors from provider',
                notes TEXT NULL,
                match_date DATE NULL COMMENT 'When match was first identified',
                last_updated TIMESTAMP NULL COMMENT 'Last sync from provider',
                is_starred TINYINT(1) DEFAULT 0 COMMENT 'User marked as important',
                is_hidden TINYINT(1) DEFAULT 0 COMMENT 'User chose to hide match',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                INDEX idx_kit_id (kit_id),
                INDEX idx_shared_cm (shared_cm DESC),
                INDEX idx_predicted_relationship (predicted_relationship),
                INDEX idx_confirmed_relationship (confirmed_relationship),
                INDEX idx_common_ancestor (common_ancestor_id),
                INDEX idx_match_provider_id (match_provider_id),
                INDEX idx_starred (is_starred),
                UNIQUE KEY unique_kit_match (kit_id, match_provider_id),

                CONSTRAINT fk_dna_matches_kit FOREIGN KEY (kit_id)
                    REFERENCES genealogy_dna_kits(id) ON DELETE CASCADE,
                CONSTRAINT fk_dna_matches_ancestor FOREIGN KEY (common_ancestor_id)
                    REFERENCES genealogy_persons(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS genealogy_dna_matches");
    }
};
