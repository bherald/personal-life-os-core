<?php

namespace App\Services\Genealogy;

class GenealogyReviewPacketAdapterService
{
    public const REVIEW_TYPE = 'genealogy_review_packet';

    public const AGENT_ID = 'genealogy-review-packet';

    public function __construct(
        private readonly GenealogyReviewPacketValidatorService $validator = new GenealogyReviewPacketValidatorService,
        private readonly GenealogyReviewPacketApplyPreviewService $applyPreview = new GenealogyReviewPacketApplyPreviewService,
    ) {}

    public function toReviewPayload(array $packet, array $context = []): array
    {
        $claims = $this->normalizeClaims($packet);
        $sourceLocators = $this->validator->collectSourceLocators($packet);
        $dedupKey = $this->buildDedupKey($packet, $sourceLocators, $claims);
        $title = $this->buildTitle($packet, $sourceLocators, $dedupKey);
        $summary = $this->buildSummary($packet, $claims);
        $confidence = $this->normalizeConfidence($packet['confidence'] ?? $context['confidence'] ?? null);

        return [
            'agent_id' => (string) ($context['agent_id'] ?? self::AGENT_ID),
            'review_type' => self::REVIEW_TYPE,
            'title' => $title,
            'summary' => $summary,
            'details' => [
                'schema' => 'genealogy_review_packet.v1',
                'dedup_key' => $dedupKey,
                'packet_key' => $this->nullableString($packet['packet_key'] ?? $packet['id'] ?? null),
                'packet_label' => $this->nullableString($packet['packet_label'] ?? $packet['title'] ?? null),
                'source_locator' => $sourceLocators[0] ?? null,
                'source_locators' => $sourceLocators,
                'sources' => $this->validator->collectSourcePayloads($packet),
                'claims' => $claims,
                'identity' => $this->identityPayload($packet),
                'privacy' => $this->privacyPayload($packet),
                'sprint' => is_array($packet['sprint'] ?? null) ? $packet['sprint'] : [],
                'validation' => $this->validator->validate($packet),
                'apply_preview' => $this->applyPreview->preview($packet),
                'decision_log' => [],
                'packet' => $packet,
            ],
            'confidence' => $confidence,
            'priority' => $this->priority($confidence, $context),
            'expires_at' => $context['expires_at'] ?? null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeClaims(array $packet): array
    {
        $claims = [];

        foreach ($this->validator->collectClaims($packet) as $index => $claim) {
            $claims[] = [
                'index' => $index,
                'claim' => $this->firstText($claim, ['claim', 'claim_text', 'statement', 'extracted_claim', 'extracted_text', 'text', 'value', 'proposed_value', 'proposed_name']),
                'field_name' => $this->nullableString($claim['field_name'] ?? null),
                'change_type' => $this->nullableString($claim['change_type'] ?? null),
                'relationship_type' => $this->nullableString($claim['relationship_type'] ?? null),
                'person_id' => $this->positiveIntOrNull($claim['person_id'] ?? $packet['person_id'] ?? $packet['target_person_id'] ?? null),
                'source_ref' => $this->firstText($claim, ['source_ref', 'source_locator', 'citation', 'url', 'path']),
                'raw' => $claim,
            ];
        }

        return $claims;
    }

    /**
     * @param  string[]  $sourceLocators
     */
    private function buildTitle(array $packet, array $sourceLocators, string $dedupKey): string
    {
        $prefix = 'Genealogy review packet '.substr($dedupKey, 0, 10).': ';
        $label = $this->nullableString($packet['packet_label'] ?? $packet['title'] ?? null);
        if ($label !== null) {
            return mb_substr($prefix.$label, 0, 500);
        }

        $locator = $sourceLocators[0] ?? null;
        if ($locator !== null) {
            return mb_substr($prefix.$locator, 0, 500);
        }

        return mb_substr($prefix.'source-backed claim', 0, 500);
    }

    /**
     * @param  array<int, array<string, mixed>>  $claims
     */
    private function buildSummary(array $packet, array $claims): string
    {
        $summary = $this->nullableString($packet['summary'] ?? null);
        if ($summary !== null) {
            return $summary;
        }

        $claimText = $claims[0]['claim'] ?? null;
        if (is_string($claimText) && $claimText !== '') {
            return count($claims) === 1
                ? $claimText
                : $claimText.' (+'.(count($claims) - 1).' more claims)';
        }

        return 'Source-backed genealogy packet awaiting operator review.';
    }

    private function normalizeConfidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, round((float) $value, 2)));
    }

    private function priority(?float $confidence, array $context): int
    {
        if (isset($context['priority']) && is_numeric($context['priority'])) {
            return max(0, min(2, (int) $context['priority']));
        }

        if ($confidence !== null && $confidence >= 0.9) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  string[]  $sourceLocators
     * @param  array<int, array<string, mixed>>  $claims
     */
    private function buildDedupKey(array $packet, array $sourceLocators, array $claims): string
    {
        $material = [
            'packet_key' => $this->nullableString($packet['packet_key'] ?? $packet['id'] ?? null),
            'source_locators' => $sourceLocators,
            'identity' => $this->identityPayload($packet),
            'claims' => array_map(
                fn (array $claim): array => [
                    'claim' => $claim['claim'] ?? null,
                    'field_name' => $claim['field_name'] ?? null,
                    'change_type' => $claim['change_type'] ?? null,
                    'relationship_type' => $claim['relationship_type'] ?? null,
                    'person_id' => $claim['person_id'] ?? null,
                    'source_ref' => $claim['source_ref'] ?? null,
                ],
                $claims
            ),
        ];
        $encoded = json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $encoded === false ? serialize($material) : $encoded);
    }

    private function identityPayload(array $packet): array
    {
        $identity = $packet['identity'] ?? $packet['target_identity'] ?? $packet['person_identity'] ?? [];

        if (! is_array($identity)) {
            $identity = [];
        }

        foreach (['person_id', 'target_person_id'] as $key) {
            if (isset($packet[$key]) && ! isset($identity[$key])) {
                $identity[$key] = $packet[$key];
            }
        }

        return $identity;
    }

    private function privacyPayload(array $packet): array
    {
        $privacy = $packet['privacy'] ?? $packet['privacy_gate'] ?? $packet['privacy_review'] ?? [];

        return is_array($privacy) ? $privacy : [];
    }

    private function firstText(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
