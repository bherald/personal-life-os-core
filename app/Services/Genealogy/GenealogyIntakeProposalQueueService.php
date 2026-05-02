<?php

namespace App\Services\Genealogy;

class GenealogyIntakeProposalQueueService
{
    private const MAX_ITEMS_PER_BUCKET = 25;

    /**
     * Build a proposal queue from a saved intake run's packet data.
     */
    public function buildQueue(array $run): array
    {
        $packets = array_values((array) ($run['packets'] ?? []));

        $readyPackets = [];
        $blockedPackets = [];
        $pendingPackets = [];

        foreach ($packets as $packet) {
            $stage = $this->classifyPacket($packet);
            $entry = $this->buildQueueEntry($packet, $stage);

            match ($stage['status']) {
                'ready' => $readyPackets[] = $entry,
                'blocked' => $blockedPackets[] = $entry,
                default => $pendingPackets[] = $entry,
            };
        }

        usort($readyPackets, fn ($a, $b) => strcasecmp($a['packet_label'], $b['packet_label']));
        usort($blockedPackets, fn ($a, $b) => strcasecmp($a['packet_label'], $b['packet_label']));
        usort($pendingPackets, fn ($a, $b) => strcasecmp($a['packet_label'], $b['packet_label']));

        return [
            'ready_count' => count($readyPackets),
            'blocked_count' => count($blockedPackets),
            'pending_count' => count($pendingPackets),
            'ready_packets' => array_slice($readyPackets, 0, self::MAX_ITEMS_PER_BUCKET),
            'blocked_packets' => array_slice($blockedPackets, 0, self::MAX_ITEMS_PER_BUCKET),
            'pending_packets' => array_slice($pendingPackets, 0, self::MAX_ITEMS_PER_BUCKET),
        ];
    }

    /**
     * Classify a single packet into a proposal stage.
     *
     * @return array{status: string, reason: string, ready_for_proposal: bool}
     */
    public function classifyPacket(array $packet): array
    {
        $copySummary = (array) ($packet['reference_copy_execution']['execution']['summary'] ?? []);
        $previewState = (array) ($packet['preview_state'] ?? []);
        $decision = (string) ($packet['review_decision']['decision'] ?? '');
        $previewStatus = (string) ($previewState['status'] ?? '');

        $hasCopyExecution = $copySummary !== [];
        $hasBlockedConflicts = ($copySummary['blocked_conflicts'] ?? 0) > 0;
        $hasFailed = ($copySummary['failed'] ?? 0) > 0;
        $hasPreviewState = $previewState !== [] && $previewStatus !== '' && $previewStatus !== 'empty_packet';

        // Blocked: copy conflicts take precedence over copy failures
        if ($hasBlockedConflicts) {
            return ['status' => 'blocked', 'reason' => 'copy_conflict', 'ready_for_proposal' => false];
        }
        if ($hasFailed) {
            return ['status' => 'blocked', 'reason' => 'copy_failed', 'ready_for_proposal' => false];
        }

        // Blocked: explicit non-approved decisions
        if ($decision === 'deferred') {
            return ['status' => 'blocked', 'reason' => 'decision_deferred', 'ready_for_proposal' => false];
        }
        if ($decision === 'rejected') {
            return ['status' => 'blocked', 'reason' => 'decision_rejected', 'ready_for_proposal' => false];
        }
        if ($decision === 'needs_followup') {
            return ['status' => 'blocked', 'reason' => 'decision_needs_followup', 'ready_for_proposal' => false];
        }

        // Pending: missing prerequisites
        if (! $hasCopyExecution) {
            return ['status' => 'pending', 'reason' => 'missing_copy_execution', 'ready_for_proposal' => false];
        }
        if (! $hasPreviewState) {
            $reason = $previewStatus === 'empty_packet' ? 'empty_preview_packet' : 'missing_preview_state';

            return ['status' => 'pending', 'reason' => $reason, 'ready_for_proposal' => false];
        }
        if ($decision === '') {
            return ['status' => 'pending', 'reason' => 'missing_review_decision', 'ready_for_proposal' => false];
        }

        // Pending: preview-level gates (after copy/decision checks, before ready)
        if (! empty($previewState['questions'])) {
            return ['status' => 'pending', 'reason' => 'preview_has_questions', 'ready_for_proposal' => false];
        }
        if (array_key_exists('proposal_ready', $previewState) && $previewState['proposal_ready'] !== true) {
            return ['status' => 'pending', 'reason' => 'not_proposal_ready', 'ready_for_proposal' => false];
        }

        // Ready: all gates pass
        if ($decision === 'approved') {
            return ['status' => 'ready', 'reason' => 'approved_and_ready', 'ready_for_proposal' => true];
        }

        // Fallback: unknown decision value
        return ['status' => 'pending', 'reason' => 'unknown_decision', 'ready_for_proposal' => false];
    }

    private function buildQueueEntry(array $packet, array $stage): array
    {
        $entry = [
            'packet_label' => (string) ($packet['packet_label'] ?? 'unknown'),
            'status' => $stage['status'],
            'reason' => $stage['reason'],
        ];

        if (($packet['packet_key'] ?? '') !== '') {
            $entry['packet_key'] = (string) $packet['packet_key'];
        }

        if ($stage['status'] === 'ready') {
            $entry['proposal_ready'] = true;
            $previewState = (array) ($packet['preview_state'] ?? []);
            $entry['question_count'] = count((array) ($previewState['questions'] ?? []));
            $entry['anchor_count'] = count((array) ($previewState['page_anchors'] ?? []));
        }

        return $entry;
    }
}
