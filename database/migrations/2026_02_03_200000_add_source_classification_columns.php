<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enhancement #32: Source Classification
 *
 * Adds GPS/Evidence Explained classification columns to genealogy_sources table:
 * - source_category: Original, Derivative, or Authored (per GPS methodology)
 * - information_quality: Primary, Secondary, or Undetermined
 * - classification_confidence: AI confidence score for auto-classification
 * - classification_method: How the classification was determined
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add source classification columns to genealogy_sources
        DB::statement("
            ALTER TABLE genealogy_sources
            ADD COLUMN source_category ENUM('original', 'derivative', 'authored') DEFAULT NULL
                COMMENT 'GPS source type: original=first recording, derivative=copy/abstract, authored=compiled narrative',
            ADD COLUMN information_quality ENUM('primary', 'secondary', 'undetermined') DEFAULT NULL
                COMMENT 'GPS information quality: primary=firsthand, secondary=secondhand/later, undetermined=unknown',
            ADD COLUMN classification_confidence DECIMAL(5,4) DEFAULT NULL
                COMMENT 'AI confidence score for auto-classification (0.0000-1.0000)',
            ADD COLUMN classification_method ENUM('auto', 'manual', 'ai_suggested') DEFAULT NULL
                COMMENT 'How classification was determined',
            ADD COLUMN classification_notes TEXT DEFAULT NULL
                COMMENT 'Notes explaining classification reasoning',
            ADD COLUMN classified_at TIMESTAMP NULL DEFAULT NULL
                COMMENT 'When source was classified'
        ");

        // Add index for filtering by classification
        DB::statement("
            CREATE INDEX idx_sources_classification
            ON genealogy_sources (tree_id, source_category, information_quality)
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE genealogy_sources
            DROP INDEX idx_sources_classification
        ");

        DB::statement("
            ALTER TABLE genealogy_sources
            DROP COLUMN source_category,
            DROP COLUMN information_quality,
            DROP COLUMN classification_confidence,
            DROP COLUMN classification_method,
            DROP COLUMN classification_notes,
            DROP COLUMN classified_at
        ");
    }
};
