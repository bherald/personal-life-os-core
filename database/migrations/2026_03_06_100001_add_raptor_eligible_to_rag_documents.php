<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N79: Add raptor_eligible column to rag_documents.
 *
 * Values: NULL = unscreened, 1 = eligible, 0 = ineligible.
 *
 * Initial bulk classification (no LLM):
 *   < 1000 chars  → 0 (too short to form a 2-paragraph hierarchy)
 *   >= 4000 chars → 1 (always worth hierarchical summarization)
 *   1000–3999     → NULL (borderline; AI screener handles these)
 *
 * This immediately moves ~204K file_registry/genealogy_person noise
 * docs out of the raptor_build queue, leaving ~600 real candidates.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_rag')->statement(
            "ALTER TABLE rag_documents ADD COLUMN IF NOT EXISTS raptor_eligible SMALLINT DEFAULT NULL"
        );

        // Short docs: ineligible (heuristic, instant, no LLM needed)
        DB::connection('pgsql_rag')->statement("
            UPDATE rag_documents
            SET raptor_eligible = 0
            WHERE parent_id IS NULL
              AND raptor_eligible IS NULL
              AND LENGTH(content) < 1000
        ");

        // Long docs: eligible (definitely worth hierarchical summarization)
        DB::connection('pgsql_rag')->statement("
            UPDATE rag_documents
            SET raptor_eligible = 1
            WHERE parent_id IS NULL
              AND raptor_eligible IS NULL
              AND LENGTH(content) >= 4000
        ");

        // Borderline (1000–3999 chars) left as NULL for AI screener

        // Full RAPTOR reset: clear existing summaries and unmark all indexed docs.
        // The 3 existing summaries pre-date eligibility screening; start clean so
        // only vetted docs build the hierarchy going forward.
        DB::connection('pgsql_rag')->statement("TRUNCATE TABLE raptor_summaries");

        DB::connection('pgsql_rag')->statement("
            UPDATE rag_documents
            SET raptor_indexed_at = NULL,
                raptor_error_count = 0
            WHERE raptor_indexed_at IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement(
            "ALTER TABLE rag_documents DROP COLUMN IF EXISTS raptor_eligible"
        );
    }
};
