<?php

namespace App\Services\AgentMetrics;

class AwoDecisionEnvelopeBuilder
{
    private const APPROVED_STATUSES = ['approved', 'reviewed'];

    private const REJECTED_STATUSES = ['rejected', 'quarantined'];

    public function fromReviewRow(object|array $row): array
    {
        $row = (array) $row;
        $details = $this->decodeJson($row['details'] ?? null);
        $reviewerNotes = $this->decodeJson($row['reviewer_notes'] ?? null);
        $status = strtolower((string) ($row['status'] ?? 'unknown'));

        return [
            'version' => 1,
            'source' => 'agent_review_queue',
            'review_queue_id' => isset($row['id']) ? (int) $row['id'] : null,
            'agent_id' => $this->stringOrNull($row['agent_id'] ?? null),
            'review_type' => $this->stringOrNull($row['review_type'] ?? null),
            'operator_decision' => $this->operatorDecision($status),
            'decision_reason' => $this->decisionReason($status, $reviewerNotes),
            'risk_label' => $this->qualityGateString($details, 'risk_label'),
            'privacy_review_status' => $this->qualityGateString($details, 'privacy_review_status'),
            'public_export_status' => $this->qualityGateString($details, 'public_export_status'),
            'living_person_status' => $this->qualityGateString($details, 'living_person_status'),
            'provider_boundary_status' => $this->qualityGateString($details, 'provider_boundary_status'),
            'review_seconds' => $this->positiveIntOrNull($reviewerNotes['review_seconds'] ?? $details['review_seconds'] ?? null),
            'rework_required' => $this->reworkRequired($status, $reviewerNotes),
            'hard_fail_confirmed' => $this->hardFailConfirmed($status, $details, $reviewerNotes),
            'notes_present' => $this->notesPresent($row['reviewer_notes'] ?? null),
            'recorded_at' => $this->stringOrNull($row['reviewed_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? null),
        ];
    }

    public function qualityGateFromReviewRow(object|array $row): array
    {
        $row = (array) $row;
        $details = $this->decodeJson($row['details'] ?? null);

        return $this->qualityGate($details);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function operatorDecision(string $status): string
    {
        if (in_array($status, self::APPROVED_STATUSES, true)) {
            return $status === 'reviewed' ? 'approved_with_notes' : 'approved';
        }

        if (in_array($status, self::REJECTED_STATUSES, true)) {
            return 'rejected';
        }

        if ($status === 'pending') {
            return 'pending';
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $reviewerNotes
     */
    private function decisionReason(string $status, array $reviewerNotes): ?string
    {
        $explicit = $reviewerNotes['reason']
            ?? $reviewerNotes['reject_reason']
            ?? $reviewerNotes['action']
            ?? null;

        if (is_string($explicit) && preg_match('/^[a-z0-9_.:-]{1,80}$/i', $explicit)) {
            return strtolower($explicit);
        }

        return match ($status) {
            'approved' => 'operator_approved',
            'reviewed' => 'operator_reviewed',
            'rejected' => 'operator_rejected',
            'quarantined' => 'operator_quarantined',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function qualityGateString(array $details, string $key): ?string
    {
        $value = $this->qualityGate($details)[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function qualityGate(array $details): array
    {
        $qualityGate = $details['quality_gate'] ?? [];

        return is_array($qualityGate) ? $qualityGate : [];
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @param  array<string, mixed>  $reviewerNotes
     */
    private function reworkRequired(string $status, array $reviewerNotes): bool
    {
        if (($reviewerNotes['rework_required'] ?? false) === true) {
            return true;
        }

        $reason = $this->decisionReason($status, $reviewerNotes);

        return in_array($reason, ['needs_rework', 'not_useful', 'missing_evidence', 'weak_source'], true);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $reviewerNotes
     */
    private function hardFailConfirmed(string $status, array $details, array $reviewerNotes): bool
    {
        if (($reviewerNotes['hard_fail_confirmed'] ?? false) === true) {
            return true;
        }

        $hardFails = $this->qualityGate($details)['hard_fail_reasons'] ?? [];

        return in_array($status, self::REJECTED_STATUSES, true)
            && is_array($hardFails)
            && $hardFails !== [];
    }

    private function notesPresent(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
