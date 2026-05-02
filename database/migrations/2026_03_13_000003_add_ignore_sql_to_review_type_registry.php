<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N128: Add ignore_sql column to review_type_registry.
 *
 * The ignoreItem() method was hardcoding "SET status = 'ignored'" which fails
 * for tables that use different column names (e.g. research_facts uses
 * review_status, not status). This mirrors the approve_sql/reject_sql pattern.
 *
 * Also auto-approves ops review items (action/finding types) since they are
 * informational and approve/reject has no service handler side effects.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add ignore_sql column
        DB::statement("
            ALTER TABLE review_type_registry
            ADD COLUMN ignore_sql TEXT DEFAULT NULL COMMENT 'UPDATE query for ignore/dismiss'
            AFTER reject_sql
        ");

        // Populate ignore_sql for each review type
        // agent_review_queue uses `status` column, keyed by `token`
        DB::update("
            UPDATE review_type_registry
            SET ignore_sql = ?
            WHERE name = 'agent'
        ", ["UPDATE agent_review_queue SET status = 'ignored', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?"]);

        // research_facts uses `review_status` column, keyed by `id`
        DB::update("
            UPDATE review_type_registry
            SET ignore_sql = ?
            WHERE name = 'research'
        ", ["UPDATE research_facts SET review_status = 'ignored', reviewed_at = NOW(), updated_at = NOW() WHERE id = ?"]);

        // genealogy_proposed_relationships uses `status`, keyed by `id`
        DB::update("
            UPDATE review_type_registry
            SET ignore_sql = ?
            WHERE name = 'proposal'
        ", ["UPDATE genealogy_proposed_relationships SET status = 'ignored', reviewed_at = NOW(), updated_at = NOW() WHERE id = ?"]);

        // genealogy_face_match_queue uses `status`, keyed by `id`
        DB::update("
            UPDATE review_type_registry
            SET ignore_sql = ?
            WHERE name = 'face'
        ", ["UPDATE genealogy_face_match_queue SET status = 'ignored', reviewed_at = NOW(), updated_at = NOW() WHERE id = ?"]);

        // removal_requests uses `status`, keyed by `id`
        DB::update("
            UPDATE review_type_registry
            SET ignore_sql = ?
            WHERE name = 'privacy'
        ", ["UPDATE removal_requests SET status = 'ignored', updated_at = NOW() WHERE id = ?"]);

        // genealogy_finding (agent_review_queue, keyed by token)
        DB::update("
            UPDATE review_type_registry
            SET ignore_sql = ?
            WHERE name = 'genealogy_finding'
        ", ["UPDATE agent_review_queue SET status = 'ignored', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?"]);

        // change_proposal (genealogy_proposed_changes, keyed by id)
        DB::update("
            UPDATE review_type_registry
            SET ignore_sql = ?
            WHERE name = 'change_proposal'
        ", ["UPDATE genealogy_proposed_changes SET status = 'ignored', reviewed_at = NOW(), updated_at = NOW() WHERE id = ?"]);

        // tool_proposal (agent_review_queue, keyed by token)
        DB::update("
            UPDATE review_type_registry
            SET ignore_sql = ?
            WHERE name = 'tool_proposal'
        ", ["UPDATE agent_review_queue SET status = 'ignored', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?"]);

        // skill_optimization (agent_review_queue, keyed by token)
        DB::update("
            UPDATE review_type_registry
            SET ignore_sql = ?
            WHERE name = 'skill_optimization'
        ", ["UPDATE agent_review_queue SET status = 'ignored', reviewed_at = NOW(), updated_at = NOW() WHERE token = ?"]);

        // Exclude ops informational items (action/finding) from the 'agent' review type.
        // These have no service handler — approve/reject just toggles status with no side effects.
        // tool_proposal and skill_optimization have their own dedicated review types.
        DB::update("
            UPDATE review_type_registry
            SET count_sql = ?,
                fetch_sql = ?
            WHERE name = 'agent'
        ", [
            "SELECT COUNT(*) as total FROM agent_review_queue WHERE status = 'pending' AND review_type NOT IN ('genealogy_finding', 'action', 'finding', 'tool_proposal', 'skill_optimization') AND (expires_at IS NULL OR expires_at > NOW())",
            "SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE status = 'pending' AND review_type NOT IN ('genealogy_finding', 'action', 'finding', 'tool_proposal', 'skill_optimization') AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, created_at ASC LIMIT 100",
        ]);

        // Bulk-dismiss existing pending ops items so they don't linger
        DB::update("
            UPDATE agent_review_queue
            SET status = 'ignored', reviewed_at = NOW(), reviewer_notes = 'N128: ops informational — auto-dismissed', updated_at = NOW()
            WHERE status = 'pending' AND review_type IN ('action', 'finding')
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE review_type_registry DROP COLUMN ignore_sql");
    }
};
