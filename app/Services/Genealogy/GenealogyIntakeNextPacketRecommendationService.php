<?php

namespace App\Services\Genealogy;

class GenealogyIntakeNextPacketRecommendationService
{
    /**
     * Given a saved run plus optional workspace/draft-plan payloads,
     * return deterministic recommendations for the next best packets to review.
     *
     * Pure, side-effect free, deterministic.
     */
    public function recommend(array $run, array $workspace = [], array $draftPlan = []): array
    {
        $packets = array_values((array) ($run['packets'] ?? []));

        $queueStatuses = $this->buildQueueStatusMap($workspace);
        $readyPacketKeys = $this->buildReadyPacketSet($draftPlan);
        $questionPackets = $this->buildQuestionSet($packets);

        $blocked = [];
        $applyAttention = [];
        $questions = [];
        $proposalReady = [];
        $unreviewed = [];
        $pending = [];
        $applied = [];

        foreach ($packets as $packet) {
            $key = (string) ($packet['packet_key'] ?? '');
            $label = (string) ($packet['packet_label'] ?? '');
            $identity = compact('key', 'label');

            $queueStatus = $this->resolveQueueStatus($identity, $queueStatuses);

            if ($queueStatus === 'blocked') {
                $blocked[] = $packet;

                continue;
            }

            if ($this->packetNeedsApplyAttention($packet)) {
                $applyAttention[] = $packet;

                continue;
            }

            if ($this->packetHasQuestions($identity, $questionPackets, $packet)) {
                $questions[] = $packet;

                continue;
            }

            if ($this->packetWasApplied($packet)) {
                $applied[] = $packet;

                continue;
            }

            if ($this->packetIsProposalReady($identity, $readyPacketKeys)) {
                $proposalReady[] = $packet;

                continue;
            }

            $decision = (string) ($packet['review_decision']['decision'] ?? '');
            if ($decision === '') {
                $unreviewed[] = $packet;

                continue;
            }

            // Everything else is pending
            $pending[] = $packet;
        }

        $counts = [
            'blocked' => count($blocked),
            'apply_attention' => count($applyAttention),
            'questions' => count($questions),
            'proposal_ready' => count($proposalReady),
            'unreviewed' => count($unreviewed),
            'pending' => count($pending),
            'applied' => count($applied),
        ];

        $shortcuts = [
            'blocked' => $this->buildShortcut($blocked, 'blocked'),
            'apply_attention' => $this->buildShortcut($applyAttention, 'apply_attention'),
            'questions' => $this->buildShortcut($questions, 'questions'),
            'proposal_ready' => $this->buildShortcut($proposalReady, 'proposal_ready'),
            'unreviewed' => $this->buildShortcut($unreviewed, 'unreviewed'),
        ];

        $primary = $this->selectPrimary($blocked, $applyAttention, $questions, $proposalReady, $unreviewed, $pending);

        return [
            'primary' => $primary,
            'shortcuts' => $shortcuts,
            'counts' => $counts,
        ];
    }

    // ── category classification helpers ─────────────────────────────

    /**
     * Build a map of packet identities to their queue status from workspace data.
     * Returns array of [{key, label, status}].
     */
    private function buildQueueStatusMap(array $workspace): array
    {
        $map = [];
        $queue = (array) ($workspace['queue'] ?? []);

        foreach (['ready_packets', 'blocked_packets', 'pending_packets'] as $bucket) {
            foreach ((array) ($queue[$bucket] ?? []) as $entry) {
                $map[] = [
                    'key' => (string) ($entry['packet_key'] ?? ''),
                    'label' => (string) ($entry['packet_label'] ?? ''),
                    'status' => (string) ($entry['status'] ?? 'pending'),
                ];
            }
        }

        return $map;
    }

    /**
     * Build a set of packet identities that are in draftPlan.ready_packets.
     * Returns array of [{key, label}].
     */
    private function buildReadyPacketSet(array $draftPlan): array
    {
        $set = [];
        foreach ((array) ($draftPlan['ready_packets'] ?? []) as $entry) {
            $set[] = [
                'key' => (string) ($entry['packet_key'] ?? ''),
                'label' => (string) ($entry['packet_label'] ?? ''),
            ];
        }

        return $set;
    }

    /**
     * Build a set of packet identities that have non-empty questions.
     * Returns array of [{key, label}].
     */
    private function buildQuestionSet(array $packets): array
    {
        $set = [];
        foreach ($packets as $packet) {
            $previewState = (array) ($packet['preview_state'] ?? []);
            if (! empty($previewState['questions'])) {
                $set[] = [
                    'key' => (string) ($packet['packet_key'] ?? ''),
                    'label' => (string) ($packet['packet_label'] ?? ''),
                ];
            }
        }

        return $set;
    }

    /**
     * Resolve queue status for a packet identity against the workspace queue map.
     */
    private function resolveQueueStatus(array $identity, array $queueStatuses): ?string
    {
        foreach ($queueStatuses as $entry) {
            if ($this->identityMatches($identity, $entry)) {
                return $entry['status'];
            }
        }

        return null;
    }

    private function packetHasQuestions(array $identity, array $questionSet, array $packet): bool
    {
        // Direct check on the packet itself
        $previewState = (array) ($packet['preview_state'] ?? []);
        if (! empty($previewState['questions'])) {
            return true;
        }

        // Fallback to the pre-built set (for identity-based matching)
        foreach ($questionSet as $entry) {
            if ($this->identityMatches($identity, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function packetIsProposalReady(array $identity, array $readyPacketKeys): bool
    {
        foreach ($readyPacketKeys as $entry) {
            if ($this->identityMatches($identity, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function packetNeedsApplyAttention(array $packet): bool
    {
        $status = trim((string) (($packet['approval_apply_state']['status'] ?? '')));

        return in_array($status, ['partial', 'failed'], true);
    }

    private function packetWasApplied(array $packet): bool
    {
        return trim((string) (($packet['approval_apply_state']['status'] ?? ''))) === 'success';
    }

    /**
     * Match two identities: prefer key match, fall back to case-insensitive label.
     */
    private function identityMatches(array $a, array $b): bool
    {
        $aKey = $a['key'] ?? '';
        $bKey = $b['key'] ?? '';

        if ($aKey !== '' && $bKey !== '' && $aKey === $bKey) {
            return true;
        }

        $aLabel = $a['label'] ?? '';
        $bLabel = $b['label'] ?? '';

        if ($aLabel !== '' && $bLabel !== '' && strcasecmp($aLabel, $bLabel) === 0) {
            return true;
        }

        return false;
    }

    // ── primary selection ───────────────────────────────────────────

    private function selectPrimary(array $blocked, array $applyAttention, array $questions, array $proposalReady, array $unreviewed, array $pending): ?array
    {
        if ($blocked !== []) {
            return $this->buildPrimary($blocked[0], 'blocked', 'Resolve blocked packet before continuing', 'high');
        }
        if ($applyAttention !== []) {
            return $this->buildPrimary($applyAttention[0], 'apply_attention', 'Review apply failures or partial results before continuing', 'high');
        }
        if ($questions !== []) {
            return $this->buildPrimary($questions[0], 'questions', 'Answer packet questions before proposal generation', 'high');
        }
        if ($proposalReady !== []) {
            return $this->buildPrimary($proposalReady[0], 'proposal_ready', 'Packet is ready for proposal review', 'medium');
        }
        if ($unreviewed !== []) {
            return $this->buildPrimary($unreviewed[0], 'unreviewed', 'Packet has not been reviewed yet', 'medium');
        }
        if ($pending !== []) {
            return $this->buildPrimary($pending[0], 'pending', 'Packet is still pending prerequisites', 'low');
        }

        return null;
    }

    // ── output builders ─────────────────────────────────────────────

    private function buildPrimary(array $packet, string $category, string $reason, string $priority): array
    {
        return [
            'packet_label' => (string) ($packet['packet_label'] ?? 'unknown'),
            'packet_key' => (string) ($packet['packet_key'] ?? ''),
            'category' => $category,
            'reason' => $reason,
            'priority' => $priority,
        ];
    }

    private function buildShortcut(array $packets, string $category): ?array
    {
        if ($packets === []) {
            return null;
        }

        $first = $packets[0];
        $reasons = [
            'blocked' => 'Resolve blocked packet before continuing',
            'apply_attention' => 'Review apply failures or partial results before continuing',
            'questions' => 'Answer packet questions before proposal generation',
            'proposal_ready' => 'Packet is ready for proposal review',
            'unreviewed' => 'Packet has not been reviewed yet',
        ];
        $priorities = [
            'blocked' => 'high',
            'apply_attention' => 'high',
            'questions' => 'high',
            'proposal_ready' => 'medium',
            'unreviewed' => 'medium',
        ];

        return [
            'packet_label' => (string) ($first['packet_label'] ?? 'unknown'),
            'packet_key' => (string) ($first['packet_key'] ?? ''),
            'category' => $category,
            'reason' => $reasons[$category] ?? '',
            'priority' => $priorities[$category] ?? 'low',
        ];
    }
}
