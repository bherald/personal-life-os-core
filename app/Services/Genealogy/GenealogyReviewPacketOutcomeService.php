<?php

namespace App\Services\Genealogy;

class GenealogyReviewPacketOutcomeService
{
    private const TERMINAL_PACKET_STATUSES = [
        'reviewed_preview_only' => true,
        'rejected' => true,
    ];

    private const FOLLOW_UP_PACKET_STATUSES = [
        'clarification_requested' => true,
        'deferred' => true,
    ];

    private const FOLLOW_UP_ACTIONS = [
        'packet_clarification_requested' => true,
        'packet_deferred' => true,
        'clarification_requested' => true,
        'deferred' => true,
    ];

    private const DECISION_ACTIONS = [
        'queued' => true,
        'packet_reviewed_preview_only' => true,
        'packet_rejected' => true,
        'packet_clarification_requested' => true,
        'packet_deferred' => true,
        'reviewed_preview_only' => true,
        'rejected' => true,
        'clarification_requested' => true,
        'deferred' => true,
    ];

    private const DECISION_REASON_CODES = [
        'source_verified' => true,
        'missing_source_locator' => true,
        'locator_mismatch' => true,
        'source_needs_review' => true,
        'citation_incomplete' => true,
        'identity_unclear' => true,
        'weak_evidence' => true,
        'privacy_review_needed' => true,
        'duplicate_packet' => true,
        'other' => true,
    ];

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public function fromDetails(array $details, ?string $rowStatus = null): array
    {
        $packetStatus = $this->safeCode($details['packet_status'] ?? null)
            ?? $this->safeCode($rowStatus)
            ?? 'pending';
        $safeRowStatus = $this->safeCode($rowStatus);
        $decisionLog = $this->decisionLog($details['decision_log'] ?? null);
        $latest = $this->latestDecision($decisionLog);
        $latestAction = $this->safeAction($latest['action'] ?? null);
        $latestReason = $this->safeReasonCode($latest['meta']['reason_code'] ?? $latest['reason_code'] ?? null);
        $previewOnly = $this->isPreviewOnly($details);
        $terminal = isset(self::TERMINAL_PACKET_STATUSES[$packetStatus])
            || in_array($safeRowStatus, ['reviewed', 'rejected'], true);
        $followUp = isset(self::FOLLOW_UP_PACKET_STATUSES[$packetStatus])
            || ($latestAction !== null && isset(self::FOLLOW_UP_ACTIONS[$latestAction]));
        $touched = $decisionLog !== [];
        $outcomeState = $this->outcomeState($packetStatus, $touched, $terminal, $followUp);

        return [
            'schema' => 'genealogy_review_packet_outcome.v1',
            'packet_status' => $packetStatus,
            'packet_status_label' => $this->labelize($packetStatus),
            'row_status' => $safeRowStatus,
            'decision_count' => count($decisionLog),
            'touched' => $touched,
            'terminal' => $terminal,
            'follow_up' => $followUp,
            'preview_only' => $previewOnly,
            'outcome_state' => $outcomeState,
            'outcome_label' => $this->outcomeLabel($packetStatus, $outcomeState, $previewOnly),
            'progress_label' => $this->progressLabel($outcomeState, $previewOnly),
            'latest_action' => $latestAction,
            'latest_action_label' => $latestAction !== null ? $this->labelize($latestAction) : null,
            'latest_reason_code' => $latestReason,
            'latest_reason_label' => $latestReason !== null ? $this->labelize($latestReason) : null,
            'latest_actor' => $this->safeActor($latest['actor'] ?? null),
            'latest_at' => $this->safeTimestamp($latest['created_at'] ?? null),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decisionLog(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($entry): bool => is_array($entry) && $entry !== []));
    }

    /**
     * @param  list<array<string, mixed>>  $decisionLog
     * @return array<string, mixed>
     */
    private function latestDecision(array $decisionLog): array
    {
        for ($idx = count($decisionLog) - 1; $idx >= 0; $idx--) {
            if ($decisionLog[$idx] !== []) {
                return $decisionLog[$idx];
            }
        }

        return [];
    }

    private function outcomeState(string $packetStatus, bool $touched, bool $terminal, bool $followUp): string
    {
        if ($terminal) {
            return 'terminal';
        }

        if ($followUp) {
            return 'follow_up';
        }

        if ($touched) {
            return 'touched';
        }

        return $packetStatus === 'pending' ? 'pending' : 'unknown';
    }

    private function outcomeLabel(string $packetStatus, string $outcomeState, bool $previewOnly): string
    {
        return match ($outcomeState) {
            'terminal' => $packetStatus === 'reviewed_preview_only' && $previewOnly
                ? 'Reviewed preview-only'
                : $this->labelize($packetStatus),
            'follow_up' => $this->labelize($packetStatus),
            'touched' => 'Touched',
            'pending' => 'Awaiting decision',
            default => $this->labelize($packetStatus),
        };
    }

    private function progressLabel(string $outcomeState, bool $previewOnly): string
    {
        return match ($outcomeState) {
            'terminal' => $previewOnly ? 'Outcome recorded, preview-only' : 'Outcome recorded',
            'follow_up' => $previewOnly ? 'Follow-up recorded, preview-only' : 'Follow-up recorded',
            'touched' => $previewOnly ? 'Decision log touched, preview-only' : 'Decision log touched',
            'pending' => $previewOnly ? 'Awaiting first decision, preview-only' : 'Awaiting first decision',
            default => 'Outcome state unknown',
        };
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function isPreviewOnly(array $details): bool
    {
        $preview = $details['apply_preview'] ?? null;
        if (! is_array($preview)) {
            return false;
        }

        if (($preview['mutates_accepted_facts'] ?? null) !== false) {
            return false;
        }

        if ($this->hasAcceptedFactMutations($preview['accepted_fact_mutations'] ?? [])) {
            return false;
        }

        $operations = $preview['operations'] ?? [];
        if (! is_array($operations)) {
            return true;
        }

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            if ($this->previewFlagEnabled($operation['mutates_accepted_facts'] ?? null)
                || $this->previewFlagEnabled($operation['apply_enabled'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function hasAcceptedFactMutations(mixed $acceptedFactMutations): bool
    {
        if (is_array($acceptedFactMutations)) {
            return $acceptedFactMutations !== [];
        }

        return $acceptedFactMutations !== null
            && $acceptedFactMutations !== false
            && $acceptedFactMutations !== '';
    }

    private function previewFlagEnabled(mixed $value): bool
    {
        if ($value === null || $value === false || $value === 0 || $value === '') {
            return false;
        }

        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
        }

        return true;
    }

    private function normalizedText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function safeCode(mixed $value): ?string
    {
        $text = $this->normalizedText($value);
        if ($text === null) {
            return null;
        }

        $code = strtolower($text);
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $code)) {
            return null;
        }

        return $code;
    }

    private function safeAction(mixed $value): ?string
    {
        $code = $this->safeCode($value);
        if ($code === null) {
            return null;
        }

        return isset(self::DECISION_ACTIONS[$code]) ? $code : 'unknown';
    }

    private function safeReasonCode(mixed $value): ?string
    {
        $code = $this->safeCode($value);
        if ($code === null) {
            return null;
        }

        return isset(self::DECISION_REASON_CODES[$code]) ? $code : 'other';
    }

    private function safeActor(mixed $value): ?string
    {
        $text = $this->normalizedText($value);
        if ($text === null) {
            return null;
        }

        if (strlen($text) > 64 || str_contains($text, '://') || str_contains($text, '/')) {
            return 'unknown';
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', $text) ? $text : 'unknown';
    }

    private function safeTimestamp(mixed $value): ?string
    {
        $text = $this->normalizedText($value);
        if ($text === null) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/', $text)
            ? $text
            : null;
    }

    private function labelize(string $value): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $value));
    }
}
