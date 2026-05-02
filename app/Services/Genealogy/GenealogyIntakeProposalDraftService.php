<?php

namespace App\Services\Genealogy;

class GenealogyIntakeProposalDraftService
{
    /**
     * Build a deterministic proposal draft plan from a saved intake run snapshot.
     * Pure read-only: no DB writes, no LLM calls, no side effects.
     */
    public function plan(array $run): array
    {
        $runKey = (string) ($run['run_key'] ?? '');
        $packets = array_values((array) ($run['packets'] ?? []));

        $readyPackets = [];
        $blockedPackets = [];
        $pendingPackets = [];

        foreach ($packets as $packet) {
            $reasons = $this->classifyReasons($packet);

            if ($reasons === []) {
                $readyPackets[] = $this->buildReadyEntry($packet);
            } elseif ($this->hasBlockingReason($reasons)) {
                $blockedPackets[] = $this->buildEntryWithReasons($packet, $reasons);
            } else {
                $pendingPackets[] = $this->buildEntryWithReasons($packet, $reasons);
            }
        }

        $total = count($packets);

        return [
            'run_key' => $runKey,
            'summary' => [
                'total_packets' => $total,
                'ready_packets' => count($readyPackets),
                'blocked_packets' => count($blockedPackets),
                'pending_packets' => count($pendingPackets),
                'can_generate_any' => count($readyPackets) > 0,
                'can_generate_all' => $total > 0 && count($readyPackets) === $total,
            ],
            'ready_packets' => $readyPackets,
            'blocked_packets' => $blockedPackets,
            'pending_packets' => $pendingPackets,
        ];
    }

    private const BLOCKING_REASONS = [
        'copy_conflicts',
        'copy_failed',
        'decision_deferred',
        'decision_rejected',
        'decision_needs_followup',
    ];

    /**
     * Classify all reasons a packet is NOT ready. Returns empty array when fully ready.
     */
    private function classifyReasons(array $packet): array
    {
        $copySummary = (array) ($packet['reference_copy_execution']['execution']['summary'] ?? []);
        $previewState = (array) ($packet['preview_state'] ?? []);
        $decision = (string) ($packet['review_decision']['decision'] ?? '');
        $previewStatus = (string) ($previewState['status'] ?? '');

        $reasons = [];

        // Copy execution checks
        if ($copySummary === []) {
            $reasons[] = 'missing_copy_execution';
        } else {
            if (($copySummary['blocked_conflicts'] ?? 0) > 0) {
                $reasons[] = 'copy_conflicts';
            }
            if (($copySummary['failed'] ?? 0) > 0) {
                $reasons[] = 'copy_failed';
            }
        }

        // Preview state checks
        if ($previewState === [] || $previewStatus === '') {
            $reasons[] = 'missing_preview_state';
        } elseif ($previewStatus === 'empty_packet') {
            $reasons[] = 'empty_packet';
        } else {
            // Preview exists and is non-empty — check detail gates
            if (! empty($previewState['questions'])) {
                $reasons[] = 'preview_has_questions';
            }
            if (array_key_exists('proposal_ready', $previewState) && $previewState['proposal_ready'] === false) {
                $reasons[] = 'not_proposal_ready';
            }
        }

        // Review decision checks
        if ($decision === '') {
            $reasons[] = 'missing_review_decision';
        } else {
            match ($decision) {
                'deferred' => $reasons[] = 'decision_deferred',
                'rejected' => $reasons[] = 'decision_rejected',
                'needs_followup' => $reasons[] = 'decision_needs_followup',
                'approved' => null,
                default => $reasons[] = 'missing_review_decision',
            };
        }

        return $reasons;
    }

    private function hasBlockingReason(array $reasons): bool
    {
        foreach ($reasons as $reason) {
            if (in_array($reason, self::BLOCKING_REASONS, true)) {
                return true;
            }
        }

        return false;
    }

    private function buildReadyEntry(array $packet): array
    {
        return [
            'packet_key' => (string) ($packet['packet_key'] ?? ''),
            'packet_label' => (string) ($packet['packet_label'] ?? 'unknown'),
            'draft_input' => $this->buildDraftInput($packet),
        ];
    }

    private function buildEntryWithReasons(array $packet, array $reasons): array
    {
        return [
            'packet_key' => (string) ($packet['packet_key'] ?? ''),
            'packet_label' => (string) ($packet['packet_label'] ?? 'unknown'),
            'reasons' => $reasons,
        ];
    }

    private function buildDraftInput(array $packet): array
    {
        $previewState = (array) ($packet['preview_state'] ?? []);
        $copySummary = (array) ($packet['reference_copy_execution']['execution']['summary'] ?? []);
        $reviewDecision = (array) ($packet['review_decision'] ?? []);

        $input = [
            'packet_key' => (string) ($packet['packet_key'] ?? ''),
            'packet_label' => (string) ($packet['packet_label'] ?? 'unknown'),
            'packet_summary' => (string) ($previewState['packet_summary'] ?? ''),
            'questions' => array_values((array) ($previewState['questions'] ?? [])),
            'page_anchors' => array_values((array) ($previewState['page_anchors'] ?? [])),
            'structured_facts' => array_values((array) ($previewState['structured_facts'] ?? [])),
            // Block-3 fix: carry structured source citations through the draft so the
            // persistence planner can emit source_add proposals instead of hard-skipping.
            // Each entry is a loose shape — planner normalizes + validates url/source_id.
            'sources' => array_values((array) ($previewState['sources'] ?? [])),
            'copy_summary' => [
                'copied' => (int) ($copySummary['copied'] ?? 0),
                'already_in_place' => (int) ($copySummary['already_in_place'] ?? 0),
                'blocked_conflicts' => (int) ($copySummary['blocked_conflicts'] ?? 0),
                'failed' => (int) ($copySummary['failed'] ?? 0),
            ],
            'review_decision' => [
                'decision' => (string) ($reviewDecision['decision'] ?? ''),
                'notes' => $reviewDecision['notes'] ?? null,
                'reviewed_by' => $reviewDecision['reviewed_by'] ?? null,
            ],
        ];

        return $input;
    }
}
