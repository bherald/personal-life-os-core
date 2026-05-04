<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;

class GenealogyReviewPacketDecisionService
{
    private const DECISION_REASON_CODES = [
        'missing_source_locator',
        'source_needs_review',
        'identity_unclear',
        'weak_evidence',
        'privacy_review_needed',
        'duplicate_packet',
        'other',
    ];

    public function __construct(
        private readonly GenealogyReviewPacketDecisionLogService $decisionLog = new GenealogyReviewPacketDecisionLogService,
    ) {}

    public function markReviewed(string $token, ?string $notes = null): array
    {
        return $this->transition(
            $token,
            'reviewed',
            'reviewed_preview_only',
            'packet_reviewed_preview_only',
            $notes,
            [
                'accepted_fact_mutations' => false,
                'proposal_materialization' => 'not_implemented',
            ],
            'Packet marked reviewed; proposal materialization remains preview-only.',
            requirePreviewOnly: true
        );
    }

    public function approve(string $token, ?string $notes = null): array
    {
        return $this->markReviewed($token, $notes);
    }

    public function reject(string $token, ?string $notes = null, ?string $reasonCode = null): array
    {
        return $this->transition(
            $token,
            'rejected',
            'rejected',
            'packet_rejected',
            $notes,
            $this->withReasonCode([
                'accepted_fact_mutations' => false,
                'proposal_materialization' => 'not_implemented',
            ], $reasonCode),
            'Packet rejected.'
        );
    }

    public function clarify(string $token, ?string $notes = null, ?string $reasonCode = null): array
    {
        return $this->transition(
            $token,
            'pending',
            'clarification_requested',
            'packet_clarification_requested',
            $notes,
            $this->withReasonCode([
                'accepted_fact_mutations' => false,
                'proposal_materialization' => 'not_implemented',
            ], $reasonCode),
            'Packet clarification requested; proposal materialization remains preview-only.',
            false
        );
    }

    public function defer(string $token, ?string $notes = null, ?string $reasonCode = null): array
    {
        return $this->transition(
            $token,
            'pending',
            'deferred',
            'packet_deferred',
            $notes,
            $this->withReasonCode([
                'accepted_fact_mutations' => false,
                'proposal_materialization' => 'not_implemented',
            ], $reasonCode),
            'Packet deferred; proposal materialization remains preview-only.',
            false
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function transition(
        string $token,
        string $status,
        string $packetStatus,
        string $action,
        ?string $notes,
        array $meta,
        string $message,
        bool $markReviewedAt = true,
        bool $requirePreviewOnly = false
    ): array {
        $row = DB::table('agent_review_queue')
            ->where('token', $token)
            ->where('review_type', GenealogyReviewPacketAdapterService::REVIEW_TYPE)
            ->where('status', 'pending')
            ->first();

        if ($row === null) {
            return [
                'success' => false,
                'error' => 'Pending genealogy review packet not found.',
            ];
        }

        $details = json_decode((string) ($row->details ?? '{}'), true);
        if (! is_array($details)) {
            $details = [];
        }

        if ($requirePreviewOnly) {
            $previewGuardError = $this->previewOnlyGuardError($details);
            if ($previewGuardError !== null) {
                return [
                    'success' => false,
                    'error' => $previewGuardError,
                ];
            }

            $validationGuardError = $this->validationGuardError($details);
            if ($validationGuardError !== null) {
                return [
                    'success' => false,
                    'error' => $validationGuardError,
                ];
            }
        }

        $details = $this->decisionLog->append($details, $action, 'operator', $notes, $meta);
        $details['packet_status'] = $packetStatus;
        $reviewerNotes = [
            'action' => $action,
            'notes' => $notes,
            'meta' => $meta,
        ];
        if (isset($meta['reason_code'])) {
            $reviewerNotes['reason_code'] = $meta['reason_code'];
        }

        $updates = [
            'status' => $status,
            'details' => json_encode($details, JSON_UNESCAPED_SLASHES),
            'reviewer_notes' => json_encode($reviewerNotes, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ];

        if ($markReviewedAt) {
            $updates['reviewed_at'] = now();
        }

        DB::table('agent_review_queue')
            ->where('id', $row->id)
            ->update($updates);

        $result = [
            'success' => true,
            'message' => $message,
            'status' => $status,
            'packet_status' => $packetStatus,
            'action' => $action,
        ];

        if (isset($meta['reason_code'])) {
            $result['reason_code'] = $meta['reason_code'];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function withReasonCode(array $meta, ?string $reasonCode): array
    {
        $normalized = $this->normalizeReasonCode($reasonCode);
        if ($normalized !== null) {
            $meta['reason_code'] = $normalized;
        }

        return $meta;
    }

    private function normalizeReasonCode(?string $reasonCode): ?string
    {
        $normalized = strtolower(trim((string) $reasonCode));
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/[^a-z0-9_-]+/', '_', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/_+/', '_', $normalized) ?? $normalized, '_-');
        if ($normalized === '') {
            return null;
        }

        return in_array($normalized, self::DECISION_REASON_CODES, true) ? $normalized : 'other';
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function previewOnlyGuardError(array $details): ?string
    {
        $preview = $details['apply_preview'] ?? null;
        if (! is_array($preview)) {
            return 'Review packet apply preview is missing; approve remains blocked.';
        }

        if (($preview['mutates_accepted_facts'] ?? null) !== false) {
            return 'Review packet apply preview is not preview-only; approve remains blocked.';
        }

        $acceptedFactMutations = $preview['accepted_fact_mutations'] ?? [];
        if ($this->hasAcceptedFactMutations($acceptedFactMutations)) {
            return 'Review packet apply preview lists accepted fact mutations; approve remains blocked.';
        }

        $operations = $preview['operations'] ?? [];
        if (is_array($operations)) {
            foreach ($operations as $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                if ($this->previewFlagEnabled($operation['mutates_accepted_facts'] ?? null)) {
                    return 'Review packet apply preview operation mutates accepted facts; approve remains blocked.';
                }

                if ($this->previewFlagEnabled($operation['apply_enabled'] ?? null)) {
                    return 'Review packet apply preview operation is apply-enabled; approve remains blocked.';
                }
            }
        }

        return null;
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

    /**
     * @param  array<string, mixed>  $details
     */
    private function validationGuardError(array $details): ?string
    {
        $validation = $details['validation'] ?? null;
        if (! is_array($validation)) {
            return null;
        }

        $errors = $validation['errors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            return 'Review packet validation has errors; approve remains blocked.';
        }

        if (($validation['valid'] ?? null) === false) {
            return 'Review packet validation is not valid; approve remains blocked.';
        }

        return null;
    }
}
