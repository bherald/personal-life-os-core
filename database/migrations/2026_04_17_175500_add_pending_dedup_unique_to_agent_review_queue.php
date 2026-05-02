<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Block 6 finding #5 from the 2026-04-17 ChatGPT review chain.
 *
 * AgentLoopService::submitForReview already does a SELECT-then-INSERT dedup
 * against agent_review_queue. That dedup is racy: two scheduled-job workers
 * can both pass the SELECT check before either INSERTs, producing duplicate
 * pending rows for the same agent/type/title. Production today has tens of
 * pending duplicates (ai-ops model-update findings being the worst offender).
 *
 * Close the window with a DB-level UNIQUE constraint scoped to status='pending'
 * via a virtual generated column. Approved/rejected/dismissed history is
 * unaffected — the generated column produces NULL for non-pending rows and
 * NULLs don't conflict in MySQL UNIQUE indexes.
 *
 * up() first cleans up the existing pending-duplicate backlog (keeping the
 * oldest row per dedup key), then adds the column and index atomically.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Clean up existing pending duplicates, keeping the oldest row per
        //    dedup key. Newer pending duplicates become 'dismissed' with a
        //    reviewer_notes trail so the operator can audit.
        $deleted = DB::update("
            UPDATE agent_review_queue a1
            INNER JOIN (
                SELECT MIN(id) AS keep_id, agent_id, review_type, LEFT(COALESCE(title, ''), 80) AS title_prefix
                FROM agent_review_queue
                WHERE status = 'pending'
                GROUP BY agent_id, review_type, LEFT(COALESCE(title, ''), 80)
                HAVING COUNT(*) > 1
            ) keeper
              ON keeper.agent_id = a1.agent_id
             AND keeper.review_type = a1.review_type
             AND LEFT(COALESCE(a1.title, ''), 80) = keeper.title_prefix
             AND a1.id <> keeper.keep_id
            SET a1.status = 'dismissed',
                a1.reviewer_notes = CONCAT(
                    COALESCE(a1.reviewer_notes, ''),
                    IF(a1.reviewer_notes IS NULL OR a1.reviewer_notes = '', '', CHAR(10)),
                    '[auto-dismissed 2026-04-17: pending dup of id=', keeper.keep_id, ' — migration add_pending_dedup_unique]'
                ),
                a1.reviewed_at = IFNULL(a1.reviewed_at, NOW()),
                a1.updated_at = NOW()
            WHERE a1.status = 'pending'
        ");

        Log::info('Migration: dismissed pending review duplicates', [
            'rows_affected' => $deleted,
        ]);

        // 2. Add generated dedup-key column + unique index. Virtual (not stored)
        //    so we don't bloat the table; MySQL evaluates on read + on index write.
        //    Column length: agent_id(100) + | + review_type(50) + | + 80 = ~233 max.
        DB::statement("
            ALTER TABLE agent_review_queue
              ADD COLUMN pending_dedup_key VARCHAR(250) GENERATED ALWAYS AS (
                  IF(status = 'pending',
                     CONCAT(agent_id, '|', review_type, '|', LEFT(IFNULL(title, ''), 80)),
                     NULL)
              ) VIRTUAL,
              ADD UNIQUE INDEX uk_arq_pending_dedup (pending_dedup_key)
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE agent_review_queue DROP INDEX uk_arq_pending_dedup');
        DB::statement('ALTER TABLE agent_review_queue DROP COLUMN pending_dedup_key');
    }
};
