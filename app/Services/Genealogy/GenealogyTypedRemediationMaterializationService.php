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
        'genealogy_todo_create',
    ];

    private const SUPPORTED_INPUT_OPERATION_TYPES = [
        'family_duplicate_mark',
        'family_child_unlink',
        'source_duplicate_mark',
        'source_duplicate_cleanup',
        'genealogy_todo_create',
        'data_quality_review',
        'date_quality_review',
        'genealogy_data_quality',
        'genealogy_source_cleanup',
        'genealogy_source_quality',
        'source_quality_review',
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
        $inspection = $this->inspectQueueRow($row);
        if (! ($inspection['success'] ?? false)) {
            return $inspection;
        }

        if (($inspection['materialized_existing'] ?? false) === true) {
            return $inspection;
        }

        $packet = is_array($inspection['packet_candidate'] ?? null) ? $inspection['packet_candidate'] : [];
        $result = $this->packetMaterializer->materialize($packet, $context);
        $result['source_review_queue_id'] = $inspection['source_review_queue_id'] ?? null;
        $result['source_dedup_key'] = $inspection['source_dedup_key'] ?? '';
        $result['typed_remediation_preview'] = $inspection['typed_remediation_preview'] ?? [];
        $result['operation_types'] = $inspection['operation_types'] ?? [];
        $result['packet_summary'] = $inspection['packet_summary'] ?? null;

        return $result;
    }

    public function inspectQueueRow(object|array $row): array
    {
        $row = (object) $row;
        $details = $this->decodeDetails($row->details ?? null);
        $details = $this->withRowFindingTypeContext($row, $details);

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
                'operation_types' => [],
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
                'operation_types' => $operationTypes,
            ];
        }

        $packet = $this->buildPacket($row, $details, $preview, $operationTypes, $sourceDedupKey);
        $validation = $this->validator->validate($packet);
        $packetSummary = $this->packetSummary($packet, $validation, $preview, $sourceDedupKey);
        if (! ($validation['valid'] ?? false)) {
            return [
                'success' => false,
                'error' => 'packet_validation_failed',
                'validation' => $validation,
                'packet_summary' => $packetSummary,
                'source_review_queue_id' => (int) $row->id,
                'source_dedup_key' => $sourceDedupKey,
                'typed_remediation_preview' => $preview,
                'operation_types' => $operationTypes,
            ];
        }

        return [
            'success' => true,
            'materialized_existing' => false,
            'source_review_queue_id' => (int) $row->id,
            'source_dedup_key' => $sourceDedupKey,
            'typed_remediation_preview' => $preview,
            'operation_types' => $operationTypes,
            'validation' => $validation,
            'packet_summary' => $packetSummary,
            'packet_candidate' => $packet,
        ];
    }

    private function packetSummary(array $packet, array $validation, array $preview, string $sourceDedupKey): array
    {
        $targetContextTypes = $this->targetContextTypes($packet, $preview);

        return [
            'target_review_type' => self::TARGET_REVIEW_TYPE,
            'source_locator_count' => count($this->validator->collectSourceLocators($packet)),
            'claim_count' => count(array_values(array_filter((array) ($packet['claims'] ?? []), 'is_array'))),
            'identity_present' => is_array($packet['identity'] ?? null) && $packet['identity'] !== [],
            'target_context_present' => $targetContextTypes !== [],
            'target_context_types' => $targetContextTypes,
            'privacy_present' => is_array($packet['privacy'] ?? null) && $packet['privacy'] !== [],
            'validation_valid' => (bool) ($validation['valid'] ?? false),
            'validation_error_count' => is_array($validation['errors'] ?? null) ? count($validation['errors']) : 0,
            'validation_warning_count' => is_array($validation['warnings'] ?? null) ? count($validation['warnings']) : 0,
            'preview_only' => (string) ($preview['status'] ?? '') === 'preview_only'
                && ! (bool) ($preview['mutates_accepted_facts'] ?? false),
            'mutates_accepted_facts' => (bool) ($preview['mutates_accepted_facts'] ?? false),
            'dedup_key_present' => $sourceDedupKey !== '',
        ];
    }

    /**
     * @return list<string>
     */
    private function targetContextTypes(array $packet, array $preview): array
    {
        $types = [];
        foreach ([
            'tree' => ['tree_id', 'target_tree_id'],
            'person' => ['person_id', 'target_person_id'],
            'family' => ['family_id', 'target_family_id', 'suspect_family_id'],
            'source' => ['source_id', 'target_source_id'],
        ] as $type => $keys) {
            if ($this->hasTargetContext($packet, $preview, $keys)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @param  list<string>  $keys
     */
    private function hasTargetContext(array $packet, array $preview, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->positiveInt($packet[$key] ?? null) !== null) {
                return true;
            }
        }

        foreach ((array) ($preview['operations'] ?? []) as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            foreach ($keys as $key) {
                if ($this->positiveInt($operation[$key] ?? null) !== null) {
                    return true;
                }
            }

            $currentState = is_array($operation['current_state'] ?? null) ? $operation['current_state'] : [];
            $targetContext = is_array($currentState['target_context'] ?? null) ? $currentState['target_context'] : [];
            foreach ($keys as $key) {
                if ($this->positiveInt($targetContext[$key] ?? null) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    private function buildPacket(object $row, array $details, array $preview, array $operationTypes, string $sourceDedupKey): array
    {
        $packet = $this->packetBaseDetails($details, $operationTypes);
        $sourceReviewQueueId = (int) $row->id;
        $rawSourcePayloads = $this->validator->collectSourcePayloads($details);
        $sourcePayloads = $this->usableSourcePayloads($rawSourcePayloads);
        if ($sourcePayloads === []
            && $this->canUseInternalAuditSource($operationTypes, $rawSourcePayloads)
            && $this->isPreviewOnlyNoWrite($preview)) {
            $sourcePayloads = [$this->internalAuditSourcePayload($sourceReviewQueueId)];
        } elseif ($sourcePayloads === [] && $rawSourcePayloads !== []) {
            $sourcePayloads = $rawSourcePayloads;
        }
        $sourceLocators = $this->sourceLocators($sourcePayloads);

        $packet['packet_key'] = 'agent_review_queue:genealogy_finding:'.$sourceReviewQueueId;
        $packet['packet_label'] = $this->text($row->title ?? null) ?: 'Genealogy remediation finding #'.$sourceReviewQueueId;
        $packet['summary'] = $this->text($row->summary ?? null)
            ?: 'Typed genealogy remediation preview awaiting operator review.';
        $packet['confidence'] = is_numeric($row->confidence ?? null) ? (float) $row->confidence : ($packet['confidence'] ?? null);
        $packet['identity'] = $this->identityPayload($details);
        $packet['privacy'] = $this->privacyPayload($details);
        if ($packet['privacy'] === [] && $this->isPreviewOnlyNoWrite($preview)) {
            $packet['privacy'] = [
                'cleared' => true,
                'status' => 'cleared',
                'scope' => 'internal_typed_remediation_preview',
                'basis' => 'preview_only_no_canonical_write',
            ];
        }
        $this->removeRawSourcePayloads($packet);
        $packet['sources'] = $sourcePayloads;
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

    private function isPreviewOnlyNoWrite(array $preview): bool
    {
        return ($preview['status'] ?? null) === 'preview_only'
            && ($preview['mutates_accepted_facts'] ?? null) === false
            && (array) ($preview['accepted_fact_mutations'] ?? []) === [];
    }

    private function internalAuditSourcePayload(int $sourceReviewQueueId): array
    {
        return [
            'locator' => 'agent_review_queue:genealogy_finding:'.$sourceReviewQueueId,
            'type' => 'internal_audit_finding',
            'label' => 'PLOS genealogy audit finding',
        ];
    }

    private function removeRawSourcePayloads(array &$packet): void
    {
        foreach ([
            'source',
            'source_locator',
            'primary_source',
            'sources',
            'source_links',
            'source_locators',
            'evidence_sources',
            'citations',
            'media',
        ] as $key) {
            unset($packet[$key]);
        }
    }

    /**
     * @param  list<string>  $operationTypes
     * @param  array<int, array<string, mixed>>  $rawSourcePayloads
     */
    private function canUseInternalAuditSource(array $operationTypes, array $rawSourcePayloads): bool
    {
        if (in_array('genealogy_todo_create', $operationTypes, true)) {
            return true;
        }

        return in_array('source_duplicate_cleanup', $operationTypes, true)
            && $rawSourcePayloads !== []
            && $this->usableSourcePayloads($rawSourcePayloads) === [];
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

            foreach (['operation_type', 'operation', 'type', 'change_type', 'finding_type'] as $key) {
                $value = $this->text($packet[$key] ?? null);
                if ($value !== null && in_array($value, self::SUPPORTED_INPUT_OPERATION_TYPES, true)) {
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

    private function withRowFindingTypeContext(object $row, array $details): array
    {
        $findingType = $this->text($row->finding_type ?? null);
        if ($findingType !== null && ! isset($details['finding_type'])) {
            $details['finding_type'] = $findingType;
        }

        return $details;
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

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function usableSourcePayloads(array $sources): array
    {
        if ($sources === []) {
            return [];
        }

        return array_values(array_filter(
            $sources,
            fn (array $source): bool => ! $this->sourceHasManualOnlyLocator($source)
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $sourcePayloads
     * @return list<string>
     */
    private function sourceLocators(array $sourcePayloads): array
    {
        return $this->validator->collectSourceLocators(['sources' => $sourcePayloads]);
    }

    private function sourceHasManualOnlyLocator(array $source): bool
    {
        foreach ([
            'locator',
            'source_locator',
            'url',
            'uri',
            'path',
            'source_path',
            'reference_copy_path',
            'catalog_url',
            'catalog_ref',
            'call_number',
            'citation',
            'media_id',
            'source_id',
        ] as $key) {
            $value = $source[$key] ?? null;
            if (is_scalar($value) && $this->isManualOnlyDomainLocator((string) $value)) {
                return true;
            }
        }

        return false;
    }

    private function isManualOnlyDomainLocator(string $locator): bool
    {
        $locator = trim($locator);
        if ($locator === '') {
            return false;
        }

        $host = parse_url($locator, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(?:[\/?#]|$)/i', $locator) !== 1) {
                return false;
            }

            $host = parse_url('https://'.$locator, PHP_URL_HOST);
        }

        if (! is_string($host) || trim($host) === '') {
            return false;
        }

        $host = strtolower(trim($host));
        foreach ((array) config('scraping.manual_only_domains', []) as $domain) {
            if (! is_scalar($domain)) {
                continue;
            }

            $domain = strtolower(trim((string) $domain));
            if ($domain === '') {
                continue;
            }

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
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
