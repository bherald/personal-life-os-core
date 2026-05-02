<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend genealogy_research_hints with external record matching columns.
 *
 * Adds support for FamilySearch record hints with matching criteria,
 * confidence scoring, and record URLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add columns one at a time (MySQL doesn't support IF NOT EXISTS on ADD COLUMN)
        $columns = [
            "ADD COLUMN record_source VARCHAR(50) NULL AFTER source_info",
            "ADD COLUMN external_record_id VARCHAR(255) NULL AFTER record_source",
            "ADD COLUMN matching_criteria JSON NULL AFTER external_record_id",
            "ADD COLUMN suggested_record_type VARCHAR(50) NULL AFTER matching_criteria",
            "ADD COLUMN record_url VARCHAR(500) NULL AFTER suggested_record_type",
            "ADD COLUMN auto_generated TINYINT NOT NULL DEFAULT 0 AFTER record_url",
        ];

        foreach ($columns as $col) {
            try {
                DB::statement("ALTER TABLE genealogy_research_hints {$col}");
            } catch (\Exception $e) {
                // Column already exists - skip
                if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                    throw $e;
                }
            }
        }

        // Index for dedup on external records
        DB::statement("
            CREATE INDEX idx_research_hints_record_source
            ON genealogy_research_hints (record_source, external_record_id)
        ");

        // Index for auto-generated hint queries
        DB::statement("
            CREATE INDEX idx_research_hints_auto_generated
            ON genealogy_research_hints (auto_generated, created_at)
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX idx_research_hints_auto_generated ON genealogy_research_hints");
        DB::statement("DROP INDEX idx_research_hints_record_source ON genealogy_research_hints");

        DB::statement("
            ALTER TABLE genealogy_research_hints
            DROP COLUMN IF EXISTS record_source,
            DROP COLUMN IF EXISTS external_record_id,
            DROP COLUMN IF EXISTS matching_criteria,
            DROP COLUMN IF EXISTS suggested_record_type,
            DROP COLUMN IF EXISTS record_url,
            DROP COLUMN IF EXISTS auto_generated
        ");
    }
};
