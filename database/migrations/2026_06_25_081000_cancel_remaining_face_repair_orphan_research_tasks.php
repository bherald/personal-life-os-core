<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable('genealogy_research_tasks') || ! Schema::hasTable('agent_review_queue')) {
            return;
        }

        $reason = 'Cancelled because face/photo provenance repair packets are operator-review only; the non-face research-task runner blocks face/photo identity tasks.';
        $cancelledResults = json_encode([
            'cancelled_by' => '2026_06_25_081000_cancel_remaining_face_repair_orphan_research_tasks',
            'operator_review_boundary' => true,
            'blocked_runner_reason' => 'face_or_photo_identity_task_blocked',
        ], JSON_UNESCAPED_SLASHES);

        DB::update(
            "UPDATE genealogy_research_tasks t
              JOIN agent_review_queue q ON q.id = t.queue_item_id
               SET t.status = 'cancelled',
                   t.outcome_state = 'operator_review_required',
                   t.outcome_reason = ?,
                   t.error_message = NULL,
                   t.completed_at = COALESCE(t.completed_at, CURRENT_TIMESTAMP),
                   t.results = CASE
                       WHEN t.results IS NULL OR JSON_VALID(t.results) = 0 THEN ?
                       ELSE JSON_SET(
                           t.results,
                           '$.cancelled_by',
                           '2026_06_25_081000_cancel_remaining_face_repair_orphan_research_tasks',
                           '$.operator_review_boundary',
                           'true',
                           '$.blocked_runner_reason',
                           'face_or_photo_identity_task_blocked'
                       )
                   END,
                   t.updated_at = CURRENT_TIMESTAMP
             WHERE t.tree_id = 4
               AND t.status IN ('queued', 'processing')
               AND q.review_type = 'genealogy_review_packet'
               AND q.finding_type = 'face_person_source_repair_plan'
               AND JSON_UNQUOTE(JSON_EXTRACT(t.parameters, '$.source')) = 'genealogy_review_packet'
               AND JSON_UNQUOTE(JSON_EXTRACT(t.parameters, '$.operation_type')) = 'genealogy_todo_create'
               AND (
                   LOWER(t.research_question) LIKE '%face%'
                   OR LOWER(t.research_question) LIKE '%photo%'
               )",
            [$reason, $cancelledResults]
        );
    }

    public function down(): void
    {
        // Do not reopen operator-review-only face/photo tasks automatically.
    }
};
