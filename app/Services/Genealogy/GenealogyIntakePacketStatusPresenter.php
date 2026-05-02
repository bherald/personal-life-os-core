<?php

namespace App\Services\Genealogy;

class GenealogyIntakePacketStatusPresenter
{
    /**
     * Present a queue-classified packet stage as operator-friendly text.
     *
     * @param  array  $packet  Raw packet from the saved snapshot
     * @param  array  $stage   Output from GenealogyIntakeProposalQueueService::classifyPacket()
     *                         Shape: {status, reason, ready_for_proposal}
     */
    public function presentQueueStage(array $packet, array $stage): array
    {
        $reason = (string) ($stage['reason'] ?? '');
        $status = (string) ($stage['status'] ?? 'pending');

        return [
            'headline' => self::headlineForReason($reason),
            'summary' => self::summaryForReason($reason),
            'details' => self::detailsForReasons([$reason]),
            'severity' => self::severityFromStatus($status),
        ];
    }

    /**
     * Present a draft-planner entry as operator-friendly text.
     *
     * @param  array  $packet  Raw packet from the saved snapshot
     * @param  array  $entry   Output entry from GenealogyIntakeProposalDraftService::plan()
     *                         Ready: {packet_key, packet_label, draft_input}
     *                         Blocked/Pending: {packet_key, packet_label, reasons[]}
     */
    public function presentDraftStage(array $packet, array $entry): array
    {
        $reasons = (array) ($entry['reasons'] ?? []);
        $hasDraftInput = isset($entry['draft_input']);

        if ($hasDraftInput || $reasons === []) {
            return [
                'headline' => self::headlineForReason('approved_and_ready'),
                'summary' => self::summaryForReason('approved_and_ready'),
                'details' => self::detailsForReasons(['approved_and_ready']),
                'severity' => 'ready',
            ];
        }

        $hasBlocking = false;
        foreach ($reasons as $r) {
            if (in_array($r, self::BLOCKING_REASONS, true)) {
                $hasBlocking = true;
                break;
            }
        }

        $primaryReason = $reasons[0] ?? 'unknown_decision';

        return [
            'headline' => self::headlineForReason($primaryReason),
            'summary' => self::draftSummaryFromReasons($reasons),
            'details' => self::detailsForReasons($reasons),
            'severity' => $hasBlocking ? 'blocked' : 'pending',
        ];
    }

    // ── reason maps ─────────────────────────────────────────────────

    private const BLOCKING_REASONS = [
        'copy_conflict',
        'copy_conflicts',
        'copy_failed',
        'decision_deferred',
        'decision_rejected',
        'decision_needs_followup',
    ];

    private const HEADLINES = [
        'approved_and_ready' => 'Ready for proposal generation',
        'copy_conflict' => 'Reference copy conflict',
        'copy_conflicts' => 'Reference copy conflict',
        'copy_failed' => 'Reference copy failure',
        'decision_deferred' => 'Packet deferred',
        'decision_rejected' => 'Packet rejected',
        'decision_needs_followup' => 'Follow-up required',
        'missing_copy_execution' => 'Reference copies not started',
        'missing_preview_state' => 'Packet preview not available',
        'empty_preview_packet' => 'Packet has no content',
        'empty_packet' => 'Packet has no content',
        'missing_review_decision' => 'Awaiting human review',
        'preview_has_questions' => 'Unresolved questions',
        'not_proposal_ready' => 'Packet review incomplete',
        'unknown_decision' => 'Unknown review status',
    ];

    private const SUMMARIES = [
        'approved_and_ready' => 'Copy complete, no unresolved questions, and human review approved.',
        'copy_conflict' => 'Reference copy encountered conflicts that must be resolved before proceeding.',
        'copy_conflicts' => 'Reference copy encountered conflicts that must be resolved before proceeding.',
        'copy_failed' => 'One or more reference copies failed and need attention.',
        'decision_deferred' => 'This packet was deferred and should not move forward yet.',
        'decision_rejected' => 'This packet was rejected during human review.',
        'decision_needs_followup' => 'This packet needs follow-up before it can proceed.',
        'missing_copy_execution' => 'Reference copies have not been run for this packet yet.',
        'missing_preview_state' => 'Packet preview has not been generated yet.',
        'empty_preview_packet' => 'This packet contains no documents to preview.',
        'empty_packet' => 'This packet contains no documents to preview.',
        'missing_review_decision' => 'A human review decision has not been recorded for this packet.',
        'preview_has_questions' => 'Human answers are still needed before proposals can be generated.',
        'not_proposal_ready' => 'Packet review is not complete enough for proposal generation.',
        'unknown_decision' => 'The review decision for this packet is not recognized.',
    ];

    private const DETAILS = [
        'approved_and_ready' => 'Packet is approved for proposal generation.',
        'copy_conflict' => 'Reference copy conflict must be resolved.',
        'copy_conflicts' => 'Reference copy conflict must be resolved.',
        'copy_failed' => 'One or more reference copy operations failed.',
        'decision_deferred' => 'Human reviewer deferred this packet.',
        'decision_rejected' => 'Human reviewer rejected this packet.',
        'decision_needs_followup' => 'Human reviewer flagged this packet for follow-up.',
        'missing_copy_execution' => 'Reference copy step has not been run.',
        'missing_preview_state' => 'Packet preview has not been generated.',
        'empty_preview_packet' => 'Packet has no documents and cannot be previewed.',
        'empty_packet' => 'Packet has no documents and cannot be previewed.',
        'missing_review_decision' => 'No human review decision has been recorded.',
        'preview_has_questions' => 'Unresolved questions must be answered first.',
        'not_proposal_ready' => 'Packet review is not yet sufficient for proposals.',
        'unknown_decision' => 'Review decision is not recognized by the system.',
    ];

    // ── helpers ──────────────────────────────────────────────────────

    private static function headlineForReason(string $reason): string
    {
        return self::HEADLINES[$reason] ?? self::HEADLINES['unknown_decision'];
    }

    private static function summaryForReason(string $reason): string
    {
        return self::SUMMARIES[$reason] ?? self::SUMMARIES['unknown_decision'];
    }

    private static function detailForReason(string $reason): string
    {
        return self::DETAILS[$reason] ?? self::DETAILS['unknown_decision'];
    }

    private static function detailsForReasons(array $reasons): array
    {
        $seen = [];
        $details = [];

        foreach ($reasons as $reason) {
            $text = self::detailForReason($reason);
            if (! isset($seen[$text])) {
                $seen[$text] = true;
                $details[] = $text;
            }
        }

        return $details;
    }

    private static function severityFromStatus(string $status): string
    {
        return match ($status) {
            'ready' => 'ready',
            'blocked' => 'blocked',
            default => 'pending',
        };
    }

    private static function draftSummaryFromReasons(array $reasons): string
    {
        $count = count($reasons);
        if ($count === 0) {
            return self::summaryForReason('unknown_decision');
        }
        if ($count === 1) {
            return self::summaryForReason($reasons[0]);
        }

        return sprintf(
            '%s Additionally, %d other issue%s must be resolved.',
            self::summaryForReason($reasons[0]),
            $count - 1,
            $count - 1 > 1 ? 's' : ''
        );
    }
}
