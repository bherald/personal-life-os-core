<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enhancement #30: Evidence Classification for GPS Methodology
 *
 * Adds columns for GPS evidence classification:
 * - evidence_type: direct, indirect, negative
 * - source_quality: original, derivative, authored
 * - information_type: primary, secondary, indeterminate
 * - evidence_conclusion_id: links evidence to specific conclusions
 *
 * Based on Elizabeth Shown Mills' "Evidence Explained" methodology.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add evidence classification columns to genealogy_citations
        // Using raw SQL per project conventions
        if (!$this->columnExists('genealogy_citations', 'evidence_type')) {
            DB::statement("
                ALTER TABLE genealogy_citations
                ADD COLUMN evidence_type ENUM('direct', 'indirect', 'negative') NULL
                    COMMENT 'GPS evidence type: direct=explicitly states fact, indirect=requires inference, negative=absence proves something'
                AFTER quality
            ");
        }

        if (!$this->columnExists('genealogy_citations', 'information_type')) {
            DB::statement("
                ALTER TABLE genealogy_citations
                ADD COLUMN information_type ENUM('primary', 'secondary', 'indeterminate') NULL
                    COMMENT 'Information category: primary=from participant/eyewitness, secondary=from derivative account'
                AFTER evidence_type
            ");
        }

        if (!$this->columnExists('genealogy_citations', 'evidence_analysis')) {
            DB::statement("
                ALTER TABLE genealogy_citations
                ADD COLUMN evidence_analysis TEXT NULL
                    COMMENT 'GPS analysis notes explaining how evidence supports or contradicts conclusions'
                AFTER information_type
            ");
        }

        if (!$this->columnExists('genealogy_citations', 'conclusion_id')) {
            DB::statement("
                ALTER TABLE genealogy_citations
                ADD COLUMN conclusion_id INT UNSIGNED NULL
                    COMMENT 'Links this evidence to a specific research conclusion'
                AFTER evidence_analysis
            ");
        }

        // Add source quality columns to genealogy_sources
        if (!$this->columnExists('genealogy_sources', 'source_quality')) {
            DB::statement("
                ALTER TABLE genealogy_sources
                ADD COLUMN source_quality ENUM('original', 'derivative', 'authored') NULL
                    COMMENT 'GPS source category: original=created at time, derivative=copy/transcription, authored=compiled narrative'
                AFTER notes
            ");
        }

        if (!$this->columnExists('genealogy_sources', 'quality_notes')) {
            DB::statement("
                ALTER TABLE genealogy_sources
                ADD COLUMN quality_notes TEXT NULL
                    COMMENT 'Notes on source quality assessment'
                AFTER source_quality
            ");
        }

        // Create evidence conclusions table for tracking fact conclusions from evidence
        if (!$this->tableExists('genealogy_evidence_conclusions')) {
            DB::statement("
                CREATE TABLE genealogy_evidence_conclusions (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    tree_id INT UNSIGNED NOT NULL,
                    person_id INT UNSIGNED NULL,
                    family_id INT UNSIGNED NULL,
                    fact_type VARCHAR(50) NOT NULL COMMENT 'GEDCOM fact type (BIRT, DEAT, MARR, etc.)',
                    conclusion_text TEXT NOT NULL COMMENT 'The concluded fact statement',
                    confidence_level ENUM('proven', 'probable', 'possible', 'speculative') NOT NULL DEFAULT 'possible',
                    reasoning TEXT NULL COMMENT 'Explanation of how evidence supports this conclusion',
                    conflicting_evidence TEXT NULL COMMENT 'Notes on any conflicting evidence considered',
                    gps_compliant TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether this conclusion meets GPS standards',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_tree_id (tree_id),
                    INDEX idx_person_id (person_id),
                    INDEX idx_family_id (family_id),
                    INDEX idx_fact_type (fact_type),
                    FOREIGN KEY (tree_id) REFERENCES genealogy_trees(id) ON DELETE CASCADE,
                    FOREIGN KEY (person_id) REFERENCES genealogy_persons(id) ON DELETE CASCADE,
                    FOREIGN KEY (family_id) REFERENCES genealogy_families(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                COMMENT='GPS evidence conclusions - tracks fact conclusions derived from analyzed evidence'
            ");
        }

        // Add foreign key for conclusion_id after table exists
        // Skip if FK already exists
        $fkExists = DB::selectOne("
            SELECT COUNT(*) as cnt FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND TABLE_NAME = 'genealogy_citations'
            AND CONSTRAINT_NAME = 'fk_citations_conclusion'
        ");

        if (!$fkExists->cnt) {
            DB::statement("
                ALTER TABLE genealogy_citations
                ADD INDEX idx_conclusion_id (conclusion_id),
                ADD CONSTRAINT fk_citations_conclusion
                    FOREIGN KEY (conclusion_id) REFERENCES genealogy_evidence_conclusions(id)
                    ON DELETE SET NULL
            ");
        }
    }

    public function down(): void
    {
        // Remove foreign key first
        $fkExists = DB::selectOne("
            SELECT COUNT(*) as cnt FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND TABLE_NAME = 'genealogy_citations'
            AND CONSTRAINT_NAME = 'fk_citations_conclusion'
        ");

        if ($fkExists->cnt) {
            DB::statement("ALTER TABLE genealogy_citations DROP FOREIGN KEY fk_citations_conclusion");
            DB::statement("ALTER TABLE genealogy_citations DROP INDEX idx_conclusion_id");
        }

        // Drop evidence conclusions table
        DB::statement("DROP TABLE IF EXISTS genealogy_evidence_conclusions");

        // Remove columns from genealogy_citations
        if ($this->columnExists('genealogy_citations', 'conclusion_id')) {
            DB::statement("ALTER TABLE genealogy_citations DROP COLUMN conclusion_id");
        }
        if ($this->columnExists('genealogy_citations', 'evidence_analysis')) {
            DB::statement("ALTER TABLE genealogy_citations DROP COLUMN evidence_analysis");
        }
        if ($this->columnExists('genealogy_citations', 'information_type')) {
            DB::statement("ALTER TABLE genealogy_citations DROP COLUMN information_type");
        }
        if ($this->columnExists('genealogy_citations', 'evidence_type')) {
            DB::statement("ALTER TABLE genealogy_citations DROP COLUMN evidence_type");
        }

        // Remove columns from genealogy_sources
        if ($this->columnExists('genealogy_sources', 'quality_notes')) {
            DB::statement("ALTER TABLE genealogy_sources DROP COLUMN quality_notes");
        }
        if ($this->columnExists('genealogy_sources', 'source_quality')) {
            DB::statement("ALTER TABLE genealogy_sources DROP COLUMN source_quality");
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $result = DB::selectOne("
            SELECT COUNT(*) as cnt FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
        ", [$table, $column]);

        return $result->cnt > 0;
    }

    private function tableExists(string $table): bool
    {
        $result = DB::selectOne("
            SELECT COUNT(*) as cnt FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
        ", [$table]);

        return $result->cnt > 0;
    }
};
