<?php

namespace App\Services\Genealogy;

use App\Support\JsonColumn;
use Illuminate\Support\Facades\DB;

class GenealogyTypedRemediationMaterializationService
{
    private const SOURCE_REVIEW_TYPE = 'genealogy_finding';

    private const TARGET_REVIEW_TYPE = 'genealogy_review_packet';

    private const SUPPORTED_OPERATION_TYPES = [
        'family_duplicate_mark',
        'family_child_unlink',
        'source_duplicate_mark',
        'source_duplicate_cleanup',
    ];

    public function __construct(
        private readonly GenealogyReviewPacketValidatorService $validator = new GenealogyReviewPacketValidatorService,
        private readonly GenealogyReviewPacketApplyPreviewService $applyPreview = new GenealogyReviewPacketApplyPreviewService,
        private readonly GenealogyReviewPacketMaterializationService $packetMaterializer = new GenealogyReviewPacketMaterializationService,
    ) {}

    public function materializeFromQueueId(int $reviewQueueId, array $context = []): array
    {
        $row = DB::table('agent_review_queue')->where('id', $reviewQueueId)->first();
        if ($row === null) {
            return [
                'success' => false,
                'error' => 'source_row_not_found',
            ];
        }

        return $this->materializeFromQueueRow($row, $context);
    }

    public function materializeFromToken(string $token, array $context = []): array
    {
        $row = DB::table('agent_review_queue')->where('token', $token)->first();
        if ($row === null) {
            return [
                'success' => false,
                'error' => 'source_row_not_found',
            ];
        }

        return $this->materializeFromQueueRow($row, $context);
    }

    public function materializeFromQueueRow(object|array $row, array $context = []): array
    {
        $row = (object) $row;
        $details = $this->decodeDetails($row->details ?? null);

        if ((string) ($row->review_type ?? '') !== self::SOURCE_REVIEW_TYPE
            || (string) ($row->status ?? '') !== 'pending') {
            return [
                'success' => false,
                'error' => 'source_row_not_pending_genealogy_finding',
                'source_review_queue_id' => isset($row->id) ? (int) $row->id : null,
            ];
        }

        $preview = $this->applyPreview->preview($details);
        $operationTypes = $this->supportedOperationTypes($preview);
        if ($operationTypes === []) {
            return [
                'success' => false,
                'error' => 'unsupported_typed_remediation',
                'source_review_queue_id' => (int) $row->id,
                'typed_remediation_preview' => $preview,
            ];
        }

        $sourceDedupKey = $this->sourceDedupKey($row, $details);
        $existing = $this->findExistingPendingPacket((int) $row->id, $sourceDedupKey);
        if ($existing !== null) {
            return [
                'success' => true,
                'materialized_existing' => true,
                'review_queue_id' => (int) $existing->id,
                'token' => (string) $existing->token,
                'source_review_queue_id' => (int) $row->id,
                'source_dedup_key' => $sourceDedupKey,
                'typed_remediation_preview' => $preview,
            ];
        }

        $packet = $this->buildPacket($row, $details, $preview, $operationTypes, $sourceDedupKey);
        $result = $this->packetMaterializer->materialize($packet, $context);
        $result['source_review_queue_id'] = (int) $row->id;
        $result['source_dedup_key'] = $sourceDedupKey;
        $result['typed_remediation_preview'] = $preview;

        return $result;
    }

    private function buildPacket(object $row, array $details, array $preview, array $operationTypes, string $sourceDedupKey): array
    {
        $packet = $this->packetBaseDetails($details, $operationTypes);
        $sourceReviewQueueId = (int) $row->id;
        $sourceLocators = $this->validator->collectSourceLocators($details);

        $packet['packet_key'] = 'agent_review_queue:genealogy_finding:'.$sourceReviewQueueId;
        $packet['packet_label'] = $this->text($row->title ?? null) ?: 'Genealogy remediation finding #'.$sourceReviewQueueId;
        $packet['summary'] = $this->text($row->summary ?? null)
            ?: 'Typed genealogy remediation preview awaiting operator review.';
        $packet['confidence'] = is_numeric($row->confidence ?? null) ? (float) $row->confidence : ($packet['confidence'] ?? null);
        $packet['identity'] = $this->identityPayload($details);
        $packet['privacy'] = $this->privacyPayload($details);
        $packet['sources'] = $this->sourcePayloads($details);
        $packet['claims'] = $this->claimPayloads($row, $details, $operationTypes, $sourceLocators);
        $packet['typed_remediation_preview'] = $preview;
        $packet['source_review_queue'] = [
            'id' => $sourceReviewQueueId,
            'token' => $this->text($row->token ?? null),
            'review_type' => self::SOURCE_REVIEW_TYPE,
            'finding_type' => $this->text($row->finding_type ?? $details['finding_type'] ?? null),
            'status' => 'pending',
        ];
        $packet['materialization'] = [
            'source' => 'agent_review_queue',
            'source_review_queue_id' => $sourceReviewQueueId,
            'source_token' => $this->text($row->token ?? null),
            'source_dedup_key' => $sourceDedupKey,
            'source_review_type' => self::SOURCE_REVIEW_TYPE,
            'target_review_type' => self::TARGET_REVIEW_TYPE,
            'operation_types' => $operationTypes,
            'apply_enabled' => false,
            'writeback' => false,
        ];

        return $packet;
    }

    private function packetBaseDetails(array $details, array $operationTypes): array
    {
        $packet = $details;
        if ($operationTypes !== []
            && ! isset($packet['remediation'])
            && ! isset($packet['remediation_packet'])
            && ! isset($packet['remediations'])
            && ! isset($packet['remediation_packets'])) {
            $packet['remediation'] = $details;

            foreach (['operation_type', 'operation', 'type', 'change_type'] as $key) {
                $value = $this->text($packet[$key] ?? null);
                if ($value !== null && in_array($value, self::SUPPORTED_OPERATION_TYPES, true)) {
                    unset($packet[$key]);
                }
            }
        }

        return $packet;
    }

    private function findExistingPendingPacket(int $sourceReviewQueueId, string $sourceDedupKey): ?object
    {
        return DB::table('agent_review_queue')
            ->select(['id', 'token'])
            ->where('review_type', self::TARGET_REVIEW_TYPE)
            ->where('status', 'pending')
            ->where(function ($query) use ($sourceReviewQueueId, $sourceDedupKey): void {
                JsonColumn::whereScalarEquals($query, 'details', '$.packet.materialization.source_review_queue_id', (string) $sourceReviewQueueId);
                JsonColumn::orWhereScalarEquals($query, 'details', '$.packet.source_review_queue.id', (string) $sourceReviewQueueId);

                if ($sourceDedupKey !== '') {
                    JsonColumn::orWhereScalarEquals($query, 'details', '$.packet.materialization.source_dedup_key', $sourceDedupKey);
                    JsonColumn::orWhereScalarEquals($query, 'details', '$.packet.source_review_queue.dedup_key', $sourceDedupKey);
                }
            })
            ->orderBy('id')
            ->first();
    }

    private function decodeDetails(mixed $details): array
    {
        if (is_array($details)) {
            return $details;
        }

        if (! is_string($details) || trim($details) === '') {
            return [];
        }

        $decoded = json_decode($details, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private function supportedOperationTypes(array $preview): array
    {
        $types = [];
        foreach ((array) ($preview['operations'] ?? []) as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $type = $this->text($operation['operation_type'] ?? null);
            if ($type !== null && in_array($type, self::SUPPORTED_OPERATION_TYPES, true)) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    private function sourceDedupKey(object $row, array $details): string
    {
        foreach (['typed_remediation_dedup_key', 'remediation_dedup_key', 'dedup_key'] as $key) {
            $value = $this->text($details[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return 'agent_review_queue:genealogy_finding:'.(int) $row->id;
    }

    private function identityPayload(array $details): array
    {
        $identity = $details['identity'] ?? $details['target_identity'] ?? $details['person_identity'] ?? [];
        if (! is_array($identity)) {
            $identity = [];
        }

        foreach (['person_id', 'target_person_id'] as $key) {
            if (isset($details[$key]) && ! isset($identity[$key])) {
                $identity[$key] = $details[$key];
            }
        }

        return $identity;
    }

    private function privacyPayload(array $details): array
    {
        $privacy = $details['privacy'] ?? $details['privacy_gate'] ?? $details['privacy_review'] ?? [];
        if (is_array($privacy) && $privacy !== []) {
            return $privacy;
        }

        foreach (['privacy_cleared', 'privacy_clearance', 'cleared'] as $key) {
            if ($this->truthy($details[$key] ?? null)) {
                return [
                    'cleared' => true,
                    'status' => 'cleared',
                ];
            }
        }

        return [];
    }

    private function sourcePayloads(array $details): array
    {
        $sources = $this->validator->collectSourcePayloads($details);

        return $sources !== [] ? $sources : [];
    }

    private function claimPayloads(object $row, array $details, array $operationTypes, array $sourceLocators): array
    {
        $claims = $this->validator->collectClaims($details);
        $hasClaimText = collect($claims)->contains(fn (array $claim): bool => $this->firstText($claim, [
            'claim',
            'claim_text',
            'statement',
            'extracted_claim',
            'extracted_text',
            'text',
            'value',
            'proposed_value',
            'proposed_name',
        ]) !== null);

        if ($hasClaimText) {
            return $claims;
        }

        $claimText = $this->firstText($details, ['claim_text', 'claim', 'statement', 'evidence_summary', 'summary'])
            ?? $this->text($row->summary ?? null)
            ?? $this->text($row->title ?? null)
            ?? 'Typed genealogy remediation preview for operator review.';

        return [[
            'claim_text' => $claimText,
            'person_id' => $this->positiveInt($details['person_id'] ?? $details['target_person_id'] ?? $details['identity']['person_id'] ?? null),
            'source_ref' => $sourceLocators[0] ?? null,
            'evidence_summary' => $claimText,
        ]];
    }

    private function firstText(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->text($payload[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function truthy(mixed $value): bool
    {
        if ($value === true) {
            return true;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
        }

        return false;
    }
}
