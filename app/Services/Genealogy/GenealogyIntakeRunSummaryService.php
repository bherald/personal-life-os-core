<?php

namespace App\Services\Genealogy;

class GenealogyIntakeRunSummaryService
{
    /**
     * Build a full summary for a single intake run (detail view).
     */
    public function summarizeRun(array $run): array
    {
        $packets = array_values((array) ($run['packets'] ?? []));
        $totals = $this->buildPacketTotals($packets);
        $copyProgress = $this->buildCopyProgress($packets);
        $blockedPackets = $this->buildBlockedPackets($packets);
        $reviewSignals = $this->buildReviewSignals($packets);
        $decisionCounts = $this->buildDecisionCounts($packets);
        $proposalReadiness = $this->buildProposalReadiness($packets);
        $approvalApplyProgress = $this->buildApprovalApplyProgress($packets);

        return [
            'run_health' => $this->deriveRunHealth($run, $totals, $copyProgress),
            'next_action' => $this->deriveNextAction($run, $totals, $copyProgress, $reviewSignals, $decisionCounts, $approvalApplyProgress),
            'packet_totals' => $totals,
            'copy_progress' => $copyProgress,
            'blocked_packets' => $blockedPackets,
            'review_signals' => $reviewSignals,
            'decision_counts' => $decisionCounts,
            'proposal_readiness' => $proposalReadiness,
            'approval_apply_progress' => $approvalApplyProgress,
            'updated_at' => $run['updated_at'] ?? null,
        ];
    }

    /**
     * Build a compact summary for list views.
     *
     * Accepts lightweight list-item data (status + copy_progress, no packets array).
     * Falls back gracefully when full packet data is not available.
     */
    public function summarizeRunListItem(array $run): array
    {
        // If full packets are available, use them
        if (isset($run['packets'])) {
            $packets = array_values((array) $run['packets']);
            $totals = $this->buildPacketTotals($packets);
            $copyProgress = $this->buildCopyProgress($packets);
            $reviewSignals = $this->buildReviewSignals($packets);
            $decisionCounts = $this->buildDecisionCounts($packets);
            $approvalApplyProgress = $this->buildApprovalApplyProgress($packets);

            return [
                'run_health' => $this->deriveRunHealth($run, $totals, $copyProgress),
                'next_action' => $this->deriveNextAction($run, $totals, $copyProgress, $reviewSignals, $decisionCounts, $approvalApplyProgress),
                'packet_totals' => $totals,
                'copy_progress' => $copyProgress,
                'approval_apply_progress' => $approvalApplyProgress,
            ];
        }

        // List-item path: derive from copy_progress + status
        $cp = (array) ($run['copy_progress'] ?? []);
        $copyProgress = [
            'copied' => (int) ($cp['copied'] ?? 0),
            'already_in_place' => (int) ($cp['already_in_place'] ?? 0),
            'blocked_conflicts' => (int) ($cp['blocked_conflicts'] ?? 0),
            'failed' => (int) ($cp['failed'] ?? 0),
            'total_files' => (int) ($cp['copied'] ?? 0)
                + (int) ($cp['already_in_place'] ?? 0)
                + (int) ($cp['blocked_conflicts'] ?? 0)
                + (int) ($cp['failed'] ?? 0),
        ];

        $packetsWithExecution = (int) ($cp['packets_with_execution'] ?? 0);
        $hasBlocked = $copyProgress['blocked_conflicts'] > 0 || $copyProgress['failed'] > 0;

        $health = $this->deriveHealthFromStatus((string) ($run['status'] ?? ''), $packetsWithExecution, $hasBlocked);
        $nextAction = $this->deriveActionFromStatus((string) ($run['status'] ?? ''), $packetsWithExecution, $hasBlocked);

        return [
            'run_health' => $health,
            'next_action' => $nextAction,
            'packet_totals' => [
                'total_packets' => null,
                'packets_with_copy_execution' => $packetsWithExecution,
                'packets_blocked' => null,
                'packets_ready_for_questions' => null,
            ],
            'copy_progress' => $copyProgress,
            'approval_apply_progress' => [
                'applied_packets' => null,
                'partial_packets' => null,
                'failed_packets' => null,
                'empty_packets' => null,
                'pending_packets' => null,
                'last_applied_at' => null,
                'applied_packet_labels' => [],
            ],
        ];
    }

    private function buildPacketTotals(array $packets): array
    {
        $total = count($packets);
        $withExecution = 0;
        $blocked = 0;
        $readyForQuestions = 0;

        foreach ($packets as $packet) {
            $execution = (array) ($packet['reference_copy_execution']['execution'] ?? []);
            $summary = (array) ($execution['summary'] ?? []);

            if ($summary !== []) {
                $withExecution++;
            }

            if (($summary['blocked_conflicts'] ?? 0) > 0 || ($summary['failed'] ?? 0) > 0) {
                $blocked++;
            }

            // A packet is ready for questions if it has copy execution with no blocks,
            // or if there's no copy execution needed (no reference_copy_execution key at all
            // is ambiguous, so we only count packets that have completed copy successfully).
            if ($summary !== [] && ($summary['blocked_conflicts'] ?? 0) === 0 && ($summary['failed'] ?? 0) === 0) {
                $readyForQuestions++;
            }
        }

        return [
            'total_packets' => $total,
            'packets_with_copy_execution' => $withExecution,
            'packets_blocked' => $blocked,
            'packets_ready_for_questions' => $readyForQuestions,
        ];
    }

    private function buildCopyProgress(array $packets): array
    {
        $progress = [
            'copied' => 0,
            'already_in_place' => 0,
            'blocked_conflicts' => 0,
            'failed' => 0,
            'total_files' => 0,
        ];

        foreach ($packets as $packet) {
            $summary = (array) ($packet['reference_copy_execution']['execution']['summary'] ?? []);
            if ($summary === []) {
                continue;
            }

            $copied = (int) ($summary['copied'] ?? 0);
            $alreadyInPlace = (int) ($summary['already_in_place'] ?? 0);
            $blockedConflicts = (int) ($summary['blocked_conflicts'] ?? 0);
            $failed = (int) ($summary['failed'] ?? 0);

            $progress['copied'] += $copied;
            $progress['already_in_place'] += $alreadyInPlace;
            $progress['blocked_conflicts'] += $blockedConflicts;
            $progress['failed'] += $failed;
            $progress['total_files'] += $copied + $alreadyInPlace + $blockedConflicts + $failed;
        }

        return $progress;
    }

    private function buildBlockedPackets(array $packets): array
    {
        $blocked = [];

        foreach ($packets as $packet) {
            $summary = (array) ($packet['reference_copy_execution']['execution']['summary'] ?? []);
            if (($summary['blocked_conflicts'] ?? 0) > 0 || ($summary['failed'] ?? 0) > 0) {
                $blocked[] = (string) ($packet['packet_label'] ?? 'unknown');
            }

            if (count($blocked) >= 5) {
                break;
            }
        }

        return $blocked;
    }

    private function buildReviewSignals(array $packets): array
    {
        $withQuestions = 0;
        $proposalReady = 0;

        foreach ($packets as $packet) {
            $previewState = (array) ($packet['preview_state'] ?? []);

            // Check preview_state first, then fall back to top-level packet fields
            $hasQuestions = ! empty($previewState['questions']) || ! empty($packet['questions']);
            $isProposalReady = ! empty($previewState['proposal_ready']) || ! empty($packet['proposal_ready']);

            if ($hasQuestions) {
                $withQuestions++;
            }

            if ($isProposalReady) {
                $proposalReady++;
            }
        }

        return [
            'packets_with_questions' => $withQuestions,
            'packets_proposal_ready' => $proposalReady,
        ];
    }

    private function buildDecisionCounts(array $packets): array
    {
        $counts = [
            'approved' => 0,
            'deferred' => 0,
            'rejected' => 0,
            'needs_followup' => 0,
            'pending' => 0,
        ];

        foreach ($packets as $packet) {
            $decision = (string) ($packet['review_decision']['decision'] ?? '');
            if (isset($counts[$decision])) {
                $counts[$decision]++;
            } else {
                $counts['pending']++;
            }
        }

        return $counts;
    }

    private function buildProposalReadiness(array $packets): array
    {
        $total = count($packets);
        $ready = 0;
        $blocked = 0;
        $pendingReview = 0;
        $approved = 0;
        $readyLabels = [];

        foreach ($packets as $packet) {
            $copySummary = (array) ($packet['reference_copy_execution']['execution']['summary'] ?? []);
            $previewState = (array) ($packet['preview_state'] ?? []);
            $decision = (string) ($packet['review_decision']['decision'] ?? '');
            $previewStatus = (string) ($previewState['status'] ?? '');
            $label = (string) ($packet['packet_label'] ?? 'unknown');

            $hasCopyExecution = $copySummary !== [];
            $hasCopyBlocks = ($copySummary['blocked_conflicts'] ?? 0) > 0 || ($copySummary['failed'] ?? 0) > 0;
            $hasPreviewState = $previewState !== [] && $previewStatus !== '' && $previewStatus !== 'empty_packet';
            $isApproved = $decision === 'approved';

            if ($hasCopyBlocks) {
                $blocked++;
            } elseif (! $hasCopyExecution || ! $hasPreviewState || $decision === '') {
                $pendingReview++;
            } elseif ($decision === 'deferred' || $decision === 'rejected' || $decision === 'needs_followup') {
                $blocked++;
            } elseif ($isApproved) {
                $approved++;

                if ($hasCopyExecution && ! $hasCopyBlocks && $hasPreviewState) {
                    $ready++;
                    if (count($readyLabels) < 5) {
                        $readyLabels[] = $label;
                    }
                }
            }
        }

        return [
            'total_packets' => $total,
            'ready_packets' => $ready,
            'blocked_packets' => $blocked,
            'pending_review_packets' => $pendingReview,
            'approved_packets' => $approved,
            'can_generate_proposals' => $total > 0 && $ready > 0 && $ready === $total,
            'ready_packet_labels' => $readyLabels,
        ];
    }

    private function buildApprovalApplyProgress(array $packets): array
    {
        $progress = [
            'applied_packets' => 0,
            'partial_packets' => 0,
            'failed_packets' => 0,
            'empty_packets' => 0,
            'pending_packets' => 0,
            'last_applied_at' => null,
            'applied_packet_labels' => [],
        ];

        foreach ($packets as $packet) {
            $state = (array) ($packet['approval_apply_state'] ?? []);
            $status = (string) ($state['status'] ?? '');
            $updatedAt = ($state['updated_at'] ?? null) !== null ? (string) $state['updated_at'] : null;
            $label = (string) ($packet['packet_label'] ?? 'unknown');

            if ($state === [] || $status === '') {
                $progress['pending_packets']++;

                continue;
            }

            if ($updatedAt !== null && ($progress['last_applied_at'] === null || $updatedAt > $progress['last_applied_at'])) {
                $progress['last_applied_at'] = $updatedAt;
            }

            switch ($status) {
                case 'success':
                    $progress['applied_packets']++;
                    if (count($progress['applied_packet_labels']) < 5) {
                        $progress['applied_packet_labels'][] = $label;
                    }
                    break;
                case 'partial':
                    $progress['partial_packets']++;
                    break;
                case 'failed':
                    $progress['failed_packets']++;
                    break;
                case 'empty':
                    $progress['empty_packets']++;
                    break;
                default:
                    $progress['pending_packets']++;
                    break;
            }
        }

        return $progress;
    }

    private function deriveRunHealth(array $run, array $totals, array $copyProgress): string
    {
        if ($totals['total_packets'] === 0) {
            return 'untouched';
        }

        if ($totals['packets_blocked'] > 0) {
            return 'blocked';
        }

        if ($totals['packets_with_copy_execution'] === 0) {
            return 'untouched';
        }

        if ($totals['packets_with_copy_execution'] < $totals['total_packets']) {
            return 'partial';
        }

        return 'ready';
    }

    private function deriveNextAction(array $run, array $totals, array $copyProgress, array $reviewSignals, array $decisionCounts = [], array $approvalApplyProgress = []): string
    {
        if ($totals['total_packets'] === 0) {
            return 'Add packets to run';
        }

        if ($totals['packets_blocked'] > 0) {
            return 'Resolve copy conflicts';
        }

        if ($totals['packets_with_copy_execution'] === 0) {
            return 'Run reference copy step';
        }

        if ($totals['packets_with_copy_execution'] < $totals['total_packets']) {
            return 'Run reference copy step';
        }

        // Review decision signals (after copy is complete)
        $deferred = ($decisionCounts['deferred'] ?? 0);
        $needsFollowup = ($decisionCounts['needs_followup'] ?? 0);
        $approved = ($decisionCounts['approved'] ?? 0);
        $pending = ($decisionCounts['pending'] ?? 0);

        if ($deferred > 0 || $needsFollowup > 0) {
            return 'Resolve deferred packets';
        }

        if (($approvalApplyProgress['failed_packets'] ?? 0) > 0) {
            return 'Review apply failures';
        }

        if (
            $approved > 0
            && $pending === 0
            && $deferred === 0
            && $needsFollowup === 0
            && ($approvalApplyProgress['applied_packets'] ?? 0) === $totals['total_packets']
            && $totals['total_packets'] > 0
        ) {
            return 'Review applied packets';
        }

        if ($reviewSignals['packets_with_questions'] > 0) {
            return 'Review packet questions';
        }

        if ($reviewSignals['packets_proposal_ready'] > 0) {
            return 'Review proposals';
        }

        if ($approved > 0 && $pending === 0 && $deferred === 0 && $needsFollowup === 0) {
            return 'Ready for proposal generation';
        }

        return 'Reference copies ready';
    }

    private function deriveHealthFromStatus(string $status, int $packetsWithExecution, bool $hasBlocked): string
    {
        if ($status === 'copy_attention_needed' || $hasBlocked) {
            return 'blocked';
        }

        if ($status === 'reference_copies_ready') {
            return 'ready';
        }

        if ($packetsWithExecution > 0) {
            return 'partial';
        }

        return 'untouched';
    }

    private function deriveActionFromStatus(string $status, int $packetsWithExecution, bool $hasBlocked): string
    {
        if ($hasBlocked || $status === 'copy_attention_needed') {
            return 'Resolve copy conflicts';
        }

        if ($status === 'reference_copies_ready') {
            return 'Reference copies ready';
        }

        if ($packetsWithExecution === 0) {
            return 'Run reference copy step';
        }

        return 'Run reference copy step';
    }
}
