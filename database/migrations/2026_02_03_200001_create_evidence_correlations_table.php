<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Evidence Correlations Table
 *
 * Tracks correlations between source citations for the same person/event.
 * Supports GPS methodology for evidence correlation and conflict detection.
 *
 * @see App\Services\Genealogy\EvidenceCorrelationService
 * @see GPS methodology - correlation of evidence
 */
return new class extends Migration
{
    public function up(): void
    {
        // Evidence Correlations - tracks relationships between citations
        DB::statement("
            CREATE TABLE IF NOT EXISTS evidence_correlations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tree_id INT UNSIGNED NOT NULL,
                person_id INT UNSIGNED NOT NULL COMMENT 'Primary person being researched',

                -- Citations being correlated
                citation1_id INT UNSIGNED NOT NULL,
                citation2_id INT UNSIGNED NOT NULL,

                -- Event type being correlated (BIRT, DEAT, MARR, etc.)
                event_type VARCHAR(10) NOT NULL,

                -- Correlation status
                status ENUM('pending', 'corroborates', 'conflicts', 'supplements', 'resolved') NOT NULL DEFAULT 'pending',

                -- Analysis scores (0-100)
                correlation_score TINYINT UNSIGNED NULL COMMENT 'Overall correlation score',
                date_agreement TINYINT UNSIGNED NULL COMMENT 'Date agreement score (0-100)',
                place_agreement TINYINT UNSIGNED NULL COMMENT 'Place agreement score (0-100)',
                source_independence_score TINYINT UNSIGNED NULL COMMENT 'Source independence score (0-100)',

                -- Detailed analysis stored as JSON
                analysis_details JSON NULL COMMENT 'Full analysis breakdown',

                -- Conflict resolution
                resolution_notes TEXT NULL COMMENT 'Explanation of how conflict was resolved',
                preferred_citation_id INT UNSIGNED NULL COMMENT 'Citation preferred in resolution',

                -- General notes
                notes TEXT NULL,

                -- Audit
                assessed_by INT UNSIGNED NULL COMMENT 'User who performed assessment',
                assessed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                -- Indexes
                INDEX idx_tree (tree_id),
                INDEX idx_person (person_id),
                INDEX idx_event_type (event_type),
                INDEX idx_status (status),
                INDEX idx_citation1 (citation1_id),
                INDEX idx_citation2 (citation2_id),
                INDEX idx_correlation_score (correlation_score),
                INDEX idx_person_event (person_id, event_type),
                INDEX idx_tree_status (tree_id, status),

                -- Ensure unique correlation pairs per event type
                UNIQUE INDEX idx_unique_correlation (citation1_id, citation2_id, event_type),

                -- Foreign keys
                CONSTRAINT fk_ec_tree FOREIGN KEY (tree_id)
                    REFERENCES genealogy_trees(id) ON DELETE CASCADE,
                CONSTRAINT fk_ec_person FOREIGN KEY (person_id)
                    REFERENCES genealogy_persons(id) ON DELETE CASCADE,
                CONSTRAINT fk_ec_citation1 FOREIGN KEY (citation1_id)
                    REFERENCES genealogy_citations(id) ON DELETE CASCADE,
                CONSTRAINT fk_ec_citation2 FOREIGN KEY (citation2_id)
                    REFERENCES genealogy_citations(id) ON DELETE CASCADE,
                CONSTRAINT fk_ec_preferred FOREIGN KEY (preferred_citation_id)
                    REFERENCES genealogy_citations(id) ON DELETE SET NULL

            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add index on genealogy_citations for fact_type queries if not exists
        // This improves correlation analysis performance
        $existingIndex = DB::select("
            SHOW INDEX FROM genealogy_citations WHERE Key_name = 'idx_person_fact_type'
        ");

        if (empty($existingIndex)) {
            DB::statement("
                CREATE INDEX idx_person_fact_type ON genealogy_citations(person_id, fact_type)
            ");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS evidence_correlations');

        // Only drop index if it exists
        $existingIndex = DB::select("
            SHOW INDEX FROM genealogy_citations WHERE Key_name = 'idx_person_fact_type'
        ");

        if (!empty($existingIndex)) {
            DB::statement("
                DROP INDEX idx_person_fact_type ON genealogy_citations
            ");
        }
    }
};
