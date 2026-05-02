<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE genealogy_research_queue
            ADD COLUMN question_type VARCHAR(50) NULL AFTER priority_reason,
            ADD COLUMN research_question TEXT NULL AFTER question_type,
            ADD COLUMN selection_reason TEXT NULL AFTER research_question,
            ADD COLUMN last_task_id INT UNSIGNED NULL AFTER review_items_count,
            ADD COLUMN last_outcome_state VARCHAR(30) NULL AFTER last_task_id,
            ADD COLUMN last_outcome_reason TEXT NULL AFTER last_outcome_state
        ");

        DB::statement("
            ALTER TABLE genealogy_research_tasks
            ADD COLUMN queue_item_id BIGINT UNSIGNED NULL AFTER person_id,
            ADD COLUMN research_question TEXT NULL AFTER task_type,
            ADD COLUMN selection_reason TEXT NULL AFTER research_question,
            ADD COLUMN scope_reason TEXT NULL AFTER selection_reason,
            ADD COLUMN related_people_used JSON NULL AFTER scope_reason,
            ADD COLUMN sources_checked JSON NULL AFTER related_people_used,
            ADD COLUMN evidence_summary TEXT NULL AFTER sources_checked,
            ADD COLUMN conflicts_found TEXT NULL AFTER evidence_summary,
            ADD COLUMN outcome_state VARCHAR(30) NULL AFTER conflicts_found,
            ADD COLUMN outcome_reason TEXT NULL AFTER outcome_state
        ");

        DB::update("
            DELETE FROM scheduled_jobs
            WHERE name IN ('genealogy_research_direct_ancestors', 'genealogy_records_research')
        ");

        DB::update("
            UPDATE scheduled_jobs
            SET enabled = 1,
                notes = CASE
                    WHEN notes IS NULL OR notes = '' THEN
                        '[anchor-question queue contract enabled 2026-04-11]'
                    WHEN notes LIKE '%anchor-question queue contract enabled 2026-04-11%' THEN notes
                    ELSE CONCAT(notes, ' [anchor-question queue contract enabled 2026-04-11]')
                END,
                updated_at = NOW()
            WHERE name IN ('genealogy_agent_assess', 'genealogy_agent_research_queue')
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE genealogy_research_queue
            DROP COLUMN question_type,
            DROP COLUMN research_question,
            DROP COLUMN selection_reason,
            DROP COLUMN last_task_id,
            DROP COLUMN last_outcome_state,
            DROP COLUMN last_outcome_reason
        ");

        DB::statement("
            ALTER TABLE genealogy_research_tasks
            DROP COLUMN queue_item_id,
            DROP COLUMN research_question,
            DROP COLUMN selection_reason,
            DROP COLUMN scope_reason,
            DROP COLUMN related_people_used,
            DROP COLUMN sources_checked,
            DROP COLUMN evidence_summary,
            DROP COLUMN conflicts_found,
            DROP COLUMN outcome_state,
            DROP COLUMN outcome_reason
        ");
    }
};
