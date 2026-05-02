<?php

namespace App\Services\Genealogy;

class GenealogyIntakePacketActionService
{
    /**
     * Recommend the next operator action for a queue-classified packet entry.
     *
     * @param  array  $entry  Output entry from GenealogyIntakeProposalQueueService (has 'reason', 'status')
     */
    public function recommendFromQueueEntry(array $entry): array
    {
        $reason = (string) ($entry['reason'] ?? 'unknown_decision');

        return self::actionForReason($reason);
    }

    /**
     * Recommend the next operator action for a draft-planner entry.
     *
     * @param  array  $entry  Output entry from GenealogyIntakeProposalDraftService
     *                        Ready: has 'draft_input'. Blocked/Pending: has 'reasons[]'.
     */
    public function recommendFromDraftEntry(array $entry): array
    {
        if (isset($entry['draft_input'])) {
            return self::actionForReason('approved_and_ready');
        }

        $reasons = (array) ($entry['reasons'] ?? []);
        if ($reasons === []) {
            return self::actionForReason('unknown_decision');
        }

        // Pick the highest-urgency reason using defined precedence
        $best = null;
        $bestPriority = PHP_INT_MAX;

        foreach ($reasons as $reason) {
            $order = self::REASON_PRECEDENCE[$reason] ?? 99;
            if ($order < $bestPriority) {
                $bestPriority = $order;
                $best = $reason;
            }
        }

        return self::actionForReason($best ?? $reasons[0]);
    }

    // ── reason → action map ─────────────────────────────────────────

    /**
     * Precedence order: lower = higher urgency.
     * Blocking copy/failure issues first, then decision blocks, then missing prerequisites.
     */
    private const REASON_PRECEDENCE = [
        'copy_conflict' => 1,
        'copy_conflicts' => 1,
        'copy_failed' => 2,
        'decision_rejected' => 3,
        'decision_needs_followup' => 4,
        'decision_deferred' => 5,
        'preview_has_questions' => 6,
        'not_proposal_ready' => 7,
        'missing_copy_execution' => 8,
        'missing_preview_state' => 9,
        'empty_preview_packet' => 10,
        'empty_packet' => 10,
        'missing_review_decision' => 11,
        'unknown_decision' => 99,
    ];

    private const ACTIONS = [
        'approved_and_ready' => [
            'action_code' => 'generate_proposals',
            'label' => 'Generate proposals',
            'description' => 'This packet is ready for proposal generation.',
            'priority' => 'low',
        ],
        'copy_conflict' => [
            'action_code' => 'resolve_copy_conflicts',
            'label' => 'Resolve copy conflicts',
            'description' => 'Reference copy conflicts must be resolved before this packet can proceed.',
            'priority' => 'high',
        ],
        'copy_conflicts' => [
            'action_code' => 'resolve_copy_conflicts',
            'label' => 'Resolve copy conflicts',
            'description' => 'Reference copy conflicts must be resolved before this packet can proceed.',
            'priority' => 'high',
        ],
        'copy_failed' => [
            'action_code' => 'rerun_or_fix_copy',
            'label' => 'Re-run or fix copy',
            'description' => 'One or more reference copies failed. Re-run the copy step or investigate the failure.',
            'priority' => 'high',
        ],
        'decision_deferred' => [
            'action_code' => 'revisit_deferred_packet',
            'label' => 'Revisit deferred packet',
            'description' => 'This packet was deferred. Revisit when ready to make a decision.',
            'priority' => 'medium',
        ],
        'decision_rejected' => [
            'action_code' => 'leave_rejected_packet',
            'label' => 'Leave rejected packet',
            'description' => 'This packet was rejected during review. No further action unless the decision is reversed.',
            'priority' => 'low',
        ],
        'decision_needs_followup' => [
            'action_code' => 'do_followup_review',
            'label' => 'Do follow-up review',
            'description' => 'This packet was flagged for follow-up. Complete the follow-up before proceeding.',
            'priority' => 'high',
        ],
        'missing_copy_execution' => [
            'action_code' => 'run_reference_copy',
            'label' => 'Run reference copy',
            'description' => 'Reference copies have not been run yet. Execute the copy step to continue.',
            'priority' => 'medium',
        ],
        'missing_preview_state' => [
            'action_code' => 'generate_packet_preview',
            'label' => 'Generate packet preview',
            'description' => 'A packet preview has not been generated. Preview the packet to continue.',
            'priority' => 'medium',
        ],
        'empty_preview_packet' => [
            'action_code' => 'verify_packet_contents',
            'label' => 'Verify packet contents',
            'description' => 'This packet appears empty. Verify that it contains the expected documents.',
            'priority' => 'medium',
        ],
        'empty_packet' => [
            'action_code' => 'verify_packet_contents',
            'label' => 'Verify packet contents',
            'description' => 'This packet appears empty. Verify that it contains the expected documents.',
            'priority' => 'medium',
        ],
        'missing_review_decision' => [
            'action_code' => 'record_review_decision',
            'label' => 'Record review decision',
            'description' => 'No human review decision has been recorded. Review and approve or defer this packet.',
            'priority' => 'medium',
        ],
        'preview_has_questions' => [
            'action_code' => 'answer_packet_questions',
            'label' => 'Answer packet questions',
            'description' => 'Resolve the outstanding packet questions before proposal generation can continue.',
            'priority' => 'high',
        ],
        'not_proposal_ready' => [
            'action_code' => 'finish_packet_review',
            'label' => 'Finish packet review',
            'description' => 'The packet review is not yet complete. Finish the review to unlock proposal generation.',
            'priority' => 'medium',
        ],
        'unknown_decision' => [
            'action_code' => 'inspect_packet_state',
            'label' => 'Inspect packet state',
            'description' => 'The packet is in an unexpected state. Inspect it manually to determine the next step.',
            'priority' => 'medium',
        ],
    ];

    private static function actionForReason(string $reason): array
    {
        return self::ACTIONS[$reason] ?? self::ACTIONS['unknown_decision'];
    }
}
