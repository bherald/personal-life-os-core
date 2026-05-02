<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N125 — Review queue quality gates
 *
 * 1. Exclude notes_append from change_proposal fetch_sql (prevents standalone display)
 * 2. Reject existing junk notes_append in genealogy_proposed_changes
 * 3. Reject junk Kostenbader research_facts (vague speculative observations)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Update change_proposal fetch_sql to exclude notes_append
        DB::update(
            "UPDATE review_type_registry
             SET fetch_sql = ?, updated_at = NOW()
             WHERE name = 'change_proposal'",
            [
                "SELECT pc.id, pc.tree_id, pc.person_id, pc.change_type, pc.field_name, pc.current_value, "
                . "pc.proposed_value, pc.evidence_sources, pc.evidence_summary, pc.confidence, pc.created_at, "
                . "CONCAT(p.given_name, ' ', p.surname) as person_name, "
                . "p.birth_date as anchor_birth_date, p.death_date as anchor_death_date "
                . "FROM genealogy_proposed_changes pc "
                . "LEFT JOIN genealogy_persons p ON p.id = pc.person_id "
                . "WHERE pc.status = 'pending' AND pc.change_type != 'notes_append' "
                . "ORDER BY pc.confidence DESC, pc.created_at ASC",
            ]
        );

        // 2. Reject all pending notes_append in genealogy_proposed_changes
        // These are duplicates — notes_append should only be applied via genealogy_finding approval
        $rejected = DB::update(
            "UPDATE genealogy_proposed_changes
             SET status = 'rejected', updated_at = NOW()
             WHERE status = 'pending' AND change_type = 'notes_append'"
        );

        if ($rejected > 0) {
            echo "  Rejected {$rejected} pending notes_append proposed changes\n";
        }

        // 3. Reject junk Kostenbader research_facts (vague speculative observations, not verified facts)
        try {
            $rejectedFacts = DB::connection('pgsql_rag')->update(
                "UPDATE research_facts
                 SET review_status = 'rejected', reviewed_at = NOW()
                 WHERE review_status = 'pending'
                   AND normalized_claim ILIKE ANY(ARRAY[
                       '%may be available but%',
                       '%not readily accessible%',
                       '%not directly available%',
                       '%remains unclear due to insufficient%',
                       '%require further investigation%'
                   ])"
            );
            if ($rejectedFacts > 0) {
                echo "  Rejected {$rejectedFacts} junk research facts\n";
            }
        } catch (\Throwable $e) {
            echo "  Warning: Could not clean research_facts: {$e->getMessage()}\n";
        }
    }

    public function down(): void
    {
        // Restore original fetch_sql without the notes_append filter
        DB::update(
            "UPDATE review_type_registry
             SET fetch_sql = ?, updated_at = NOW()
             WHERE name = 'change_proposal'",
            [
                "SELECT pc.id, pc.tree_id, pc.person_id, pc.change_type, pc.field_name, pc.current_value, "
                . "pc.proposed_value, pc.evidence_sources, pc.evidence_summary, pc.confidence, pc.created_at, "
                . "CONCAT(p.given_name, ' ', p.surname) as person_name, "
                . "p.birth_date as anchor_birth_date, p.death_date as anchor_death_date "
                . "FROM genealogy_proposed_changes pc "
                . "LEFT JOIN genealogy_persons p ON p.id = pc.person_id "
                . "WHERE pc.status = 'pending' "
                . "ORDER BY pc.confidence DESC, pc.created_at ASC",
            ]
        );
    }
};
