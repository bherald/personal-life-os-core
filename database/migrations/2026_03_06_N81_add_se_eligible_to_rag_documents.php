<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add se_eligible column: NULL=unscreened, 1=eligible, 0=ineligible
        DB::connection('pgsql_rag')->statement(
            "ALTER TABLE rag_documents ADD COLUMN IF NOT EXISTS se_eligible SMALLINT DEFAULT NULL"
        );

        // Bulk classify by length (heuristics — no LLM needed for SE screening)
        // < 500 chars → 0 (too short for sentence-window retrieval)
        // >= 2000 chars → 1 (always worth embedding)
        // 500–1999 → NULL (borderline, heuristic screener handles via --screen pass)
        DB::connection('pgsql_rag')->statement("
            UPDATE rag_documents
            SET se_eligible = CASE
                WHEN length(content) < 500  THEN 0
                WHEN length(content) >= 2000 THEN 1
                ELSE NULL
            END
            WHERE se_eligible IS NULL
        ");

        // Backfill already-indexed docs to eligible (proven indexable)
        // Guard: sentence_indexed_at may not exist on fresh installs
        try {
            DB::connection('pgsql_rag')->statement("
                UPDATE rag_documents
                SET se_eligible = 1
                WHERE sentence_indexed_at IS NOT NULL
                  AND se_eligible IS NULL
            ");
        } catch (\Throwable) {
            // Column doesn't exist — skip backfill (no indexed docs to mark)
        }

        // Add se_screen scheduled job (every 6h, drains borderline unscreened docs)
        DB::statement("
            INSERT INTO scheduled_jobs
                (name, command, cron_expression, enabled, category, timeout_minutes, description, stall_exempt, created_at, updated_at)
            VALUES
                ('se_screen', 'rag:build-sentences --screen --limit=10000', '0 1,7,13,19 * * *', 1, 'RAG', 30,
                 'Screen unscreened RAG documents for sentence-indexing eligibility (N81)', 0, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                command = VALUES(command),
                cron_expression = VALUES(cron_expression),
                enabled = VALUES(enabled),
                updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement(
            "ALTER TABLE rag_documents DROP COLUMN IF EXISTS se_eligible"
        );

        DB::statement("DELETE FROM scheduled_jobs WHERE name = 'se_screen'");
    }
};
