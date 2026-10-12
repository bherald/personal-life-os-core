<?php

namespace App\Services\Files;

use App\Services\ContentExtractionProvenanceService;
use App\Services\FaceEmbeddingService;
use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\GlobalFaceWritebackApprovalService;
use App\Services\NextcloudFileApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Same-source evidence and catalog field reviews. Names and machine fields remain separate claims. */
class PhotoReviewEvidenceService
{
    private const COMPARISON_KEY = '_face_comparison_advice';

    private const COMPARISON_SCHEMA = 'plos.face_comparison_advice.v1';

    private const FIELD_REVIEW_KEY = '_photo_field_reviews';

    private const FIELD_REVIEW_SCHEMA = 'plos.photo_field_reviews.v1';

    public function __construct(private readonly FaceLinkBridgeService $sources) {}

    public function evidence(array $selector): array
    {
        $binding = $this->resolve($selector);
        $file = $binding['file'];
        $proof = $this->sources->reviewSourcePath($binding['path'], null, true);
        $hash = ($proof['success'] ?? false) ? $proof['source_sha256'] : null;
        $textTruncated = false;
        $text = function (mixed $value) use (&$textTruncated): ?string {
            if (! is_string($value) || $value === '') {
                return null;
            }
            $limit = max(256, min(20000, (int) config('file_registry.photo_review.max_text_chars', 8000)));
            $textTruncated = $textTruncated || mb_strlen($value) > $limit;

            return mb_substr($value, 0, $limit);
        };
        $provenanceRow = $file ? DB::table('file_content_extraction_provenance')
            ->where('file_registry_id', $file->id)->first(['file_registry_id', 'metadata']) : null;
        $metadataValid = $this->metadataValid($provenanceRow?->metadata);
        $metadata = $this->readMetadata($provenanceRow?->metadata);
        $analysis = is_array($metadata['ai_analysis'] ?? null) ? $metadata['ai_analysis'] : [];
        $analysisHash = $this->hash($analysis['source_sha256'] ?? null);
        $version = $analysis['version'] ?? null;
        $analysisBound = is_string($version) && $version !== '' && $version === ($file?->ai_analysis_version ?? null)
            && ! str_starts_with($version, 'processing') && ! str_starts_with($version, 'fail')
            && ! empty($analysis['recorded_at']) && ! empty($file?->ai_analyzed_at);
        $inputState = $hash && $analysisHash && $analysisBound ? (hash_equals($hash, $analysisHash) ? 'current' : 'stale') : 'unknown';
        $provenance = app(ContentExtractionProvenanceService::class)->decode($provenanceRow?->metadata);
        $ocrText = $file?->ai_detected_text;
        $ocrVerified = $provenance !== null && is_string($ocrText)
            && hash_equals($provenance['output_sha256'], hash('sha256', $ocrText));
        $ocrInputHash = $this->hash($provenance['input_sha256'] ?? null);
        $ocrInputState = $ocrVerified && $hash && $ocrInputHash
            ? (hash_equals($hash, $ocrInputHash) ? 'current' : 'stale')
            : 'unknown';
        $faces = $file ? $this->faces((int) $file->id, $hash) : ['items' => [], 'truncated' => false, 'decisions_truncated' => false];
        $comparisons = $this->comparisonProjection($metadata, $file, $hash);
        if (! $this->bindingMatches($binding, $selector)) {
            throw new RuntimeException('photo_binding_changed');
        }
        // Recheck after reading catalog/identity history; do not publish a mixed-source bundle.
        if ($hash && ! $this->currentHash($binding['path'], $hash)) {
            throw new RuntimeException('source_photo_changed');
        }
        $source = ['status' => $hash ? 'verified_current' : 'held',
            'error_code' => $hash ? null : ($proof['error_code'] ?? 'source_photo_unavailable'),
            'source_sha256' => $hash, 'source_dimensions' => $proof['source_dimensions'] ?? null,
            'coordinate_basis' => 'encoded_pixels',
            'catalog_hash_state' => $hash && $this->hash($file?->content_hash)
                ? (hash_equals($hash, $file->content_hash) ? 'matching' : 'different') : 'unknown',
            'preview_url' => $hash ? '/api/media/photo-evidence/preview?'.http_build_query($selector + ['source_sha256' => $hash]) : null];
        $fieldReview = $this->fieldReviewProjection($file, $metadata, $metadataValid, $provenanceRow?->metadata, $provenance,
            $hash, $inputState, $ocrText, $ocrVerified, $ocrInputState);

        return ['success' => true, 'schema' => 'plos.photo_review_evidence.v1', 'selector' => $selector,
            'asset' => ['file_registry_id' => $file ? (int) $file->id : null, 'asset_uuid' => $file?->asset_uuid,
                'filename' => $text($file?->filename ?? basename($binding['path']))],
            'source' => $source,
            'captions' => [
                'source_caption' => ['text' => $text($file?->exif_caption), 'state' => 'stored_catalog_copy'],
                'catalog_description' => ['text' => $text($file?->description), 'state' => 'catalog_value'],
                'generated' => ['text' => $text($file?->ai_description), 'input_state' => $inputState,
                    'source_sha256' => $analysisHash, 'version' => $text($file?->ai_analysis_version),
                    'recorded_at' => $text($file?->ai_analyzed_at)],
                'reviewed' => $fieldReview['caption']['reviewed'],
            ],
            'ocr' => ['text' => $text($ocrText), 'input_state' => $ocrInputState,
                'source_sha256' => $ocrInputHash, 'output_verified' => $ocrVerified,
                'provider' => $text($provenance['provider'] ?? null), 'reviewed' => $fieldReview['ocr']['reviewed']],
            'field_review' => $fieldReview,
            'date' => ['date_taken' => $file?->date_taken, 'precision' => $file?->date_taken_precision,
                'source' => $file?->date_taken_source, 'confidence' => $file?->date_taken_confidence,
                'reasoning' => $text($file?->date_taken_reasoning), 'scan_date' => $file?->scan_date,
                'is_scan' => (bool) ($file?->is_scan ?? false), 'needs_review' => (bool) ($file?->date_needs_review ?? false)],
            'faces' => $faces['items'], 'face_comparisons' => $comparisons, 'limits' => ['faces_truncated' => $faces['truncated'],
                'decisions_truncated' => $faces['decisions_truncated'], 'text_truncated' => $textTruncated]];
    }

    private function fieldReviewProjection(?object $file, array $metadata, bool $metadataValid, mixed $provenanceRaw, ?array $provenance,
        ?string $sourceHash, string $captionState, mixed $ocrText, bool $ocrVerified, string $ocrState): array
    {
        $history = $this->fieldReviewHistory($metadata, $file);
        $textLimit = $this->textLimit();
        $definitions = [
            'caption' => ['text' => $file?->ai_description ?? null, 'source' => 'ai_description',
                'can_accept' => $sourceHash !== null && $captionState === 'current'],
            'ocr' => ['text' => $ocrText, 'source' => 'ai_detected_text',
                'can_accept' => $sourceHash !== null && $ocrState === 'current' && $ocrVerified],
        ];
        $result = [];
        foreach ($definitions as $field => $definition) {
            $evidence = $this->fieldEvidence($field, $file, $metadata, $provenanceRaw, $provenance);
            $node = $history[$field] ?? null;
            $acceptText = $this->acceptableText($definition['text'], $textLimit) ? $definition['text'] : null;
            $malformed = ! $metadataValid || $history === null;
            $latestReceipt = is_array($node) && $node['receipts'] !== []
                ? $node['receipts'][count($node['receipts']) - 1] : null;
            $result[$field] = [
                'revision' => $malformed ? null : ($node['revision'] ?? 0),
                'max_text_chars' => $textLimit,
                'evidence_sha256' => $evidence['evidence_sha256'],
                'can_accept' => ! $malformed && ($node['revision'] ?? 0) < $this->maxFieldDecisions()
                    && $definition['can_accept'] && $acceptText !== null,
                'accept_text' => ! $malformed && $definition['can_accept'] ? $acceptText : null,
                'accept_source' => ! $malformed && $definition['can_accept'] && $acceptText !== null ? $definition['source'] : null,
                'held' => $malformed || ($node['revision'] ?? 0) >= $this->maxFieldDecisions(),
                'reviewed' => $malformed ? ['state' => 'unknown', 'evidence_changed' => true]
                    : $this->reviewedProjection($latestReceipt, $sourceHash, $evidence['evidence_sha256']),
            ];
        }

        return $result;
    }

    private function reviewedProjection(?array $receipt, ?string $sourceHash, string $evidenceHash): ?array
    {
        if ($receipt === null) {
            return null;
        }

        return [
            'state' => $sourceHash !== null && hash_equals($receipt['source_sha256'], $sourceHash) ? 'current' : 'stale',
            'decision' => $receipt['decision'],
            'text' => $receipt['text'] ?? null,
            'actor_id' => $receipt['actor_id'],
            'decided_at' => $receipt['created_at'],
            'source_sha256' => $receipt['source_sha256'],
            'evidence_sha256' => $receipt['evidence_sha256'],
            'asset_uuid' => $receipt['asset_uuid'],
            'file_registry_id' => $receipt['file_registry_id'],
            'evidence_changed' => ! hash_equals($receipt['evidence_sha256'], $evidenceHash),
        ];
    }

    private function fieldEvidence(string $field, ?object $file, array $metadata, mixed $provenanceRaw, ?array $provenance): array
    {
        $claims = $field === 'caption'
            ? ['source_caption' => $file?->exif_caption ?? null,
                'catalog_description' => $file?->description ?? null,
                'generated_caption' => $file?->ai_description ?? null,
                'analysis' => $metadata['ai_analysis'] ?? null,
                'analysis_version' => $file?->ai_analysis_version ?? null,
                'analyzed_at' => $file?->ai_analyzed_at ?? null]
            : ['ocr_text' => $file?->ai_detected_text ?? null,
                // Bind the decoded contract, not its JSON spelling: database JSON
                // implementations may reorder object keys on read-back.
                'provenance' => $this->ocrEvidenceProvenance($provenance),
                'malformed_provenance_sha256' => $provenance === null ? $this->malformedProvenanceHash($provenanceRaw) : null];

        return ['claims' => $claims, 'evidence_sha256' => hash('sha256', $this->canonicalJson([
            'schema' => 'plos.photo_field_evidence.v1', 'field' => $field, 'claims' => $claims,
        ]))];
    }

    private function captionInputState(?object $file, array $metadata, string $sourceHash): string
    {
        $analysis = is_array($metadata['ai_analysis'] ?? null) ? $metadata['ai_analysis'] : [];
        $analysisHash = $this->hash($analysis['source_sha256'] ?? null);
        $version = $analysis['version'] ?? null;
        $bound = is_string($version) && $version !== '' && $version === ($file?->ai_analysis_version ?? null)
            && ! str_starts_with($version, 'processing') && ! str_starts_with($version, 'fail')
            && ! empty($analysis['recorded_at']) && ! empty($file?->ai_analyzed_at);

        return $analysisHash && $bound ? (hash_equals($sourceHash, $analysisHash) ? 'current' : 'stale') : 'unknown';
    }

    private function ocrEvidenceProvenance(?array $provenance): ?array
    {
        if ($provenance === null) {
            return null;
        }

        unset($provenance[self::FIELD_REVIEW_KEY]);

        return $provenance;
    }

    private function malformedProvenanceHash(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $metadata = $this->strictMetadata($raw);
            unset($metadata[self::FIELD_REVIEW_KEY]);
            if ($metadata === []) {
                return null;
            }

            return hash('sha256', $this->canonicalJson($metadata));
        } catch (RuntimeException) {
            return hash('sha256', $raw);
        }
    }

    private function validatedIntent(string $assetUuid, array $intent, int $actorId): array
    {
        $required = ['field', 'decision', 'source_sha256', 'evidence_sha256', 'expected_revision', 'idempotency_key'];
        $optional = ['corrected_text', 'notes'];
        if (! Str::isUuid($assetUuid) || $actorId < 1
            || array_diff($required, array_keys($intent)) !== []
            || array_diff(array_keys($intent), [...$required, ...$optional]) !== []) {
            throw new InvalidArgumentException('photo_field_review_intent_invalid');
        }
        if (! in_array($intent['field'], ['caption', 'ocr'], true)
            || ! in_array($intent['decision'], ['accept', 'correct', 'reject'], true)
            || ! is_string($intent['source_sha256']) || ! preg_match('/^[a-f0-9]{64}$/D', $intent['source_sha256'])
            || ! is_string($intent['evidence_sha256']) || ! preg_match('/^[a-f0-9]{64}$/D', $intent['evidence_sha256'])
            || ! is_int($intent['expected_revision']) || $intent['expected_revision'] < 0
            || ! is_string($intent['idempotency_key']) || ! Str::isUuid($intent['idempotency_key'])) {
            throw new InvalidArgumentException('photo_field_review_intent_invalid');
        }
        if ($intent['decision'] === 'correct') {
            if (! is_string($intent['corrected_text'] ?? null) || trim($intent['corrected_text']) === ''
                || mb_strlen($intent['corrected_text']) > $this->textLimit()) {
                throw new InvalidArgumentException('photo_field_review_correction_invalid');
            }
        } elseif (array_key_exists('corrected_text', $intent)) {
            throw new InvalidArgumentException('photo_field_review_intent_invalid');
        }
        if (array_key_exists('notes', $intent) && (! is_string($intent['notes']) || mb_strlen($intent['notes']) > 2000)) {
            throw new InvalidArgumentException('photo_field_review_notes_invalid');
        }

        return array_intersect_key($intent, array_flip([...$required, ...$optional]));
    }

    private function strictMetadata(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_object($raw)) {
            $raw = json_decode(json_encode($raw, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } elseif (is_string($raw)) {
            $decoded = json_decode($raw);
            $raw = $decoded instanceof \stdClass
                ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR)
                : null;
        }
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new RuntimeException('photo_field_review_metadata_held');
        }

        return $raw;
    }

    private function readMetadata(mixed $raw): array
    {
        try {
            return $this->strictMetadata($raw);
        } catch (RuntimeException) {
            return [];
        }
    }

    private function metadataValid(mixed $raw): bool
    {
        try {
            $this->strictMetadata($raw);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function fieldReviewHistory(array $metadata, ?object $file = null): ?array
    {
        if (! array_key_exists(self::FIELD_REVIEW_KEY, $metadata)) {
            return ['schema' => self::FIELD_REVIEW_SCHEMA,
                'caption' => ['revision' => 0, 'receipts' => []], 'ocr' => ['revision' => 0, 'receipts' => []]];
        }
        $history = $metadata[self::FIELD_REVIEW_KEY];
        if (! is_array($history) || ($history['schema'] ?? null) !== self::FIELD_REVIEW_SCHEMA) {
            return null;
        }
        $maxReceipts = 500;
        $seen = [];
        foreach (['caption', 'ocr'] as $field) {
            $node = $history[$field] ?? null;
            if (! is_array($node) || ! is_int($node['revision'] ?? null) || $node['revision'] < 0
                || ! is_array($node['receipts'] ?? null) || ! array_is_list($node['receipts'])
                || count($node['receipts']) !== $node['revision'] || count($node['receipts']) > $maxReceipts) {
                return null;
            }
            foreach (array_values($node['receipts']) as $index => $receipt) {
                if (! is_array($receipt) || ($receipt['field'] ?? null) !== $field
                    || ($receipt['revision'] ?? null) !== $index + 1
                    || ! is_string($receipt['idempotency_key'] ?? null) || ! Str::isUuid($receipt['idempotency_key'])
                    || ! is_int($receipt['actor_id'] ?? null) || $receipt['actor_id'] < 1
                    || ! in_array($receipt['decision'] ?? null, ['accept', 'correct', 'reject'], true)
                    || ($receipt['state'] ?? null) !== 'completed'
                    || ! is_int($receipt['expected_revision'] ?? null) || $receipt['expected_revision'] !== $index
                    || ! is_string($receipt['source_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $receipt['source_sha256'])
                    || ! is_string($receipt['evidence_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $receipt['evidence_sha256'])
                    || ! is_string($receipt['asset_uuid'] ?? null) || ! Str::isUuid($receipt['asset_uuid'])
                    || ! is_int($receipt['file_registry_id'] ?? null) || $receipt['file_registry_id'] < 1
                    || ($file !== null && ($receipt['asset_uuid'] !== ($file->asset_uuid ?? null)
                        || $receipt['file_registry_id'] !== (int) $file->id))
                    || ! is_string($receipt['created_at'] ?? null) || $receipt['created_at'] === ''
                    || ! is_string($receipt['prior_text'] ?? null) && ($receipt['prior_text'] ?? null) !== null
                    || ! is_string($receipt['text'] ?? null) && ($receipt['text'] ?? null) !== null
                    || ! is_string($receipt['accepted_source'] ?? null) && ($receipt['accepted_source'] ?? null) !== null
                    || ($receipt['decision'] === 'accept'
                        && (! $this->acceptableText($receipt['text'] ?? null)
                            || ($receipt['accepted_source'] ?? null) !== ($field === 'caption' ? 'ai_description' : 'ai_detected_text')))
                    || ($receipt['decision'] === 'correct'
                        && (! $this->acceptableText($receipt['text'] ?? null)
                            || ($receipt['accepted_source'] ?? null) !== null))
                    || ($receipt['decision'] === 'reject'
                        && (($receipt['text'] ?? null) !== null || ($receipt['accepted_source'] ?? null) !== null))
                    || (array_key_exists('notes', $receipt)
                        && (! is_string($receipt['notes']) || mb_strlen($receipt['notes']) > 2000))) {
                    return null;
                }
                if (isset($seen[$receipt['idempotency_key']])) {
                    return null;
                }
                $seen[$receipt['idempotency_key']] = true;
            }
        }

        return $history;
    }

    public function isFieldReviewOnlyMetadata(mixed $raw, ?object $file = null): bool
    {
        try {
            $metadata = $this->strictMetadata($raw);
        } catch (RuntimeException) {
            return false;
        }

        return $metadata !== [] && array_diff(array_keys($metadata), [self::FIELD_REVIEW_KEY, self::COMPARISON_KEY]) === []
            && $this->fieldReviewHistory($metadata, $file) !== null && $this->comparisonHistory($metadata) !== null;
    }

    /**
     * Return the latest source-bound human outcomes for RAG projection.
     * This intentionally shares the same strict parser and receipt validator
     * used by review decisions; malformed history is a hold, not an empty review.
     *
     * @return array{present:bool,valid:bool,fields:array<string,array<string,mixed>>}
     */
    public function projectFieldReviewsForIndex(mixed $raw, object $file, ?string $currentSourceHash = null): array
    {
        try {
            $metadata = $this->strictMetadata($raw);
        } catch (Throwable) {
            $present = is_string($raw) && str_contains($raw, '"'.self::FIELD_REVIEW_KEY.'"');

            return ['present' => $present, 'valid' => false, 'fields' => []];
        }

        $present = array_key_exists(self::FIELD_REVIEW_KEY, $metadata);
        $history = $this->fieldReviewHistory($metadata, $file);
        if ($history === null) {
            return ['present' => true, 'valid' => false, 'fields' => []];
        }
        if (! $present) {
            return ['present' => false, 'valid' => true, 'fields' => []];
        }
        if ($currentSourceHash !== null && $this->hash($currentSourceHash) === null) {
            return ['present' => true, 'valid' => false, 'fields' => []];
        }

        $fields = [];
        foreach (['caption', 'ocr'] as $field) {
            $receipts = $history[$field]['receipts'];
            if ($receipts === []) {
                continue;
            }
            $receipt = $receipts[array_key_last($receipts)];
            $state = $currentSourceHash === null ? 'unverified' :
                (hash_equals($currentSourceHash, $receipt['source_sha256']) ? 'current' : 'stale');
            $fields[$field] = [
                'state' => $state,
                'decision' => $receipt['decision'],
                'text' => $receipt['decision'] === 'reject' ? null : $receipt['text'],
                'source_sha256' => $receipt['source_sha256'],
                'evidence_sha256' => $receipt['evidence_sha256'],
                'revision' => $receipt['revision'],
                'actor_id' => $receipt['actor_id'],
                'created_at' => $receipt['created_at'],
                'asset_uuid' => $receipt['asset_uuid'],
                'file_registry_id' => $receipt['file_registry_id'],
            ];
        }

        return ['present' => true, 'valid' => true, 'fields' => $fields];
    }

    private function intentMatchesReceipt(array $receipt, array $intent, int $actorId): bool
    {
        if (($receipt['state'] ?? null) !== 'completed'
            || ($receipt['actor_id'] ?? null) !== $actorId
            || ($receipt['field'] ?? null) !== ($intent['field'] ?? null)
            || ($receipt['decision'] ?? null) !== ($intent['decision'] ?? null)
            || ($receipt['source_sha256'] ?? null) !== ($intent['source_sha256'] ?? null)
            || ($receipt['evidence_sha256'] ?? null) !== ($intent['evidence_sha256'] ?? null)
            || ($receipt['expected_revision'] ?? null) !== ($intent['expected_revision'] ?? null)
            || ($receipt['idempotency_key'] ?? null) !== ($intent['idempotency_key'] ?? null)
            || (($intent['decision'] ?? null) === 'correct' && ($receipt['text'] ?? null) !== ($intent['corrected_text'] ?? null))
            || (($intent['decision'] ?? null) !== 'correct' && array_key_exists('corrected_text', $intent))) {
            return false;
        }

        return array_key_exists('notes', $receipt) === array_key_exists('notes', $intent)
            && (! array_key_exists('notes', $intent) || $receipt['notes'] === $intent['notes']);
    }

    private function findReceipt(array $history, string $idempotencyKey): ?array
    {
        foreach (['caption', 'ocr'] as $field) {
            foreach ($history[$field]['receipts'] as $receipt) {
                if (hash_equals($receipt['idempotency_key'], $idempotencyKey)) {
                    return $receipt;
                }
            }
        }

        return null;
    }

    private function acceptableText(mixed $value, ?int $limit = null): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= ($limit ?? $this->textLimit());
    }

    private function textLimit(): int
    {
        return max(256, min(20000, (int) config('file_registry.photo_review.max_text_chars', 8000)));
    }

    private function maxFieldDecisions(): int
    {
        return max(1, min(500, (int) config('file_registry.photo_review.max_field_decisions', 100)));
    }

    private function canonicalJson(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }
            foreach ($item as $key => $nested) {
                $item[$key] = $sort($nested);
            }

            return $item;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $intent */
    public function decideField(string $assetUuid, array $intent, int $actorId): array
    {
        $intent = $this->validatedIntent($assetUuid, $intent, $actorId);

        return DB::transaction(function () use ($assetUuid, $intent, $actorId): array {
            $file = DB::table('file_registry')->where('asset_uuid', $assetUuid)->where('status', 'active')->lockForUpdate()->first();
            if (! $file || ! is_string($file->current_path ?? null) || $file->current_path === '') {
                throw new RuntimeException('photo_asset_unavailable');
            }

            $provenanceRow = DB::table('file_content_extraction_provenance')
                ->where('file_registry_id', $file->id)->lockForUpdate()->first(['file_registry_id', 'metadata']);
            $metadata = $this->strictMetadata($provenanceRow?->metadata);
            $history = $this->fieldReviewHistory($metadata, $file);
            if ($history === null) {
                throw new RuntimeException('photo_field_review_history_held');
            }
            $field = $intent['field'];
            $node = $history[$field];
            $priorReceipt = $this->findReceipt($history, $intent['idempotency_key']);
            if ($priorReceipt !== null) {
                if (! $this->intentMatchesReceipt($priorReceipt, $intent, $actorId)) {
                    throw new RuntimeException('photo_field_review_idempotency_conflict');
                }

                return ['success' => true, 'replayed' => true, 'receipt' => $priorReceipt];
            }

            if ($node['revision'] !== $intent['expected_revision']) {
                throw new RuntimeException('photo_field_review_revision_conflict');
            }
            if ($node['revision'] >= $this->maxFieldDecisions()) {
                throw new RuntimeException('photo_field_review_limit_reached');
            }
            $sourceHash = $intent['source_sha256'];
            if (! $this->currentHash($file->current_path, $sourceHash)) {
                throw new RuntimeException('photo_field_review_source_changed');
            }

            $provenance = app(ContentExtractionProvenanceService::class)->decode($provenanceRow?->metadata);
            $currentEvidence = $this->fieldEvidence($field, $file, $metadata, $provenanceRow?->metadata, $provenance);
            if (! hash_equals($currentEvidence['evidence_sha256'], $intent['evidence_sha256'])) {
                throw new RuntimeException('photo_field_review_evidence_changed');
            }

            $captionState = $this->captionInputState($file, $metadata, $sourceHash);
            $ocrText = $file->ai_detected_text ?? null;
            $ocrVerified = $provenance !== null && is_string($ocrText)
                && hash_equals($provenance['output_sha256'], hash('sha256', $ocrText));
            $ocrInputHash = $this->hash($provenance['input_sha256'] ?? null);
            $ocrState = $ocrVerified && $ocrInputHash
                ? (hash_equals($sourceHash, $ocrInputHash) ? 'current' : 'stale') : 'unknown';
            if ($intent['decision'] === 'accept') {
                $canAccept = $field === 'caption'
                    ? $captionState === 'current' && $this->acceptableText($file->ai_description ?? null)
                    : $ocrState === 'current' && $this->acceptableText($ocrText) && $ocrVerified;
                if (! $canAccept) {
                    throw new RuntimeException('photo_field_review_acceptance_unavailable');
                }
            }

            $acceptedText = match (true) {
                $intent['decision'] === 'correct' => $intent['corrected_text'],
                $intent['decision'] === 'accept' => $field === 'caption' ? (string) $file->ai_description : (string) $ocrText,
                default => null,
            };
            $receipt = [
                'state' => 'completed',
                'idempotency_key' => $intent['idempotency_key'],
                'field' => $field,
                'decision' => $intent['decision'],
                'actor_id' => $actorId,
                'asset_uuid' => $assetUuid,
                'file_registry_id' => (int) $file->id,
                'created_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'source_sha256' => $sourceHash,
                'evidence_sha256' => $intent['evidence_sha256'],
                'expected_revision' => $intent['expected_revision'],
                'revision' => $node['revision'] + 1,
                'prior_text' => $field === 'caption' ? ($file->description ?? null) : $ocrText,
                'accepted_source' => $intent['decision'] === 'accept'
                    ? ($field === 'caption' ? 'ai_description' : 'ai_detected_text') : null,
                'text' => $acceptedText,
            ];
            if (array_key_exists('notes', $intent)) {
                $receipt['notes'] = $intent['notes'];
            }
            $history[$field]['revision'] = $receipt['revision'];
            $history[$field]['receipts'][] = $receipt;
            $metadata[self::FIELD_REVIEW_KEY] = $history;
            $provenanceJson = json_encode($metadata, JSON_THROW_ON_ERROR);
            if ($provenanceRow) {
                $updated = DB::table('file_content_extraction_provenance')
                    ->where('file_registry_id', $file->id)
                    ->update(['metadata' => $provenanceJson, 'updated_at' => now()]);
            } else {
                $updated = DB::table('file_content_extraction_provenance')->insert([
                    'file_registry_id' => $file->id,
                    'metadata' => $provenanceJson,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('file_registry')->where('id', $file->id)->where('asset_uuid', $assetUuid)
                ->where('status', 'active')->update(['rag_indexed_at' => null]);
            if (! $updated || ! $this->currentHash($file->current_path, $sourceHash)) {
                throw new RuntimeException('photo_field_review_source_changed');
            }

            return ['success' => true, 'replayed' => false, 'receipt' => $receipt];
        });
    }

    /** Retain technical advice only. No identity, operator decision or photo metadata is changed. */
    public function recordFaceComparison(int $fileId, int $sourceEmbeddingId, array $advice): array
    {
        try {
            $item = $this->comparisonItem($advice, $sourceEmbeddingId);
            if ($item['source_evidence'][0]['file_registry_id'] !== $fileId || ! $this->comparisonIsCurrent($item)) {
                throw new RuntimeException('comparison_source_changed');
            }

            return DB::transaction(function () use ($fileId, $item): array {
                $file = DB::table('file_registry')->where('id', $fileId)->where('status', 'active')->lockForUpdate()->first();
                $row = DB::table('file_content_extraction_provenance')->where('file_registry_id', $fileId)->lockForUpdate()->first(['metadata']);
                $metadata = $this->strictMetadata($row?->metadata);
                $history = $this->comparisonHistory($metadata);
                if (! $file || $history === null || ! $this->comparisonIsCurrent($item)) {
                    throw new RuntimeException('comparison_history_or_source_held');
                }
                foreach ($history['items'] as $existing) {
                    if ($existing['advice_id'] === $item['advice_id']) {
                        return ['success' => true, 'state' => 'recorded', 'advice_id' => $item['advice_id'], 'replayed' => true];
                    }
                }
                if (count($history['items']) >= $this->comparisonLimit()) {
                    throw new RuntimeException('comparison_history_limit');
                }
                $history['items'][] = $item;
                $metadata[self::COMPARISON_KEY] = $history;
                $bytes = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                if (strlen($bytes) > (int) config('file_registry.face_comparison.max_metadata_bytes')) {
                    throw new RuntimeException('comparison_metadata_budget');
                }
                if ($row) {
                    $written = DB::table('file_content_extraction_provenance')->where('file_registry_id', $fileId)->update(['metadata' => $bytes, 'updated_at' => now()]);
                } else {
                    $written = DB::table('file_content_extraction_provenance')->insert(['file_registry_id' => $fileId, 'metadata' => $bytes, 'created_at' => now(), 'updated_at' => now()]);
                }
                $stored = DB::table('file_content_extraction_provenance')->where('file_registry_id', $fileId)->value('metadata');
                $verified = $this->comparisonHistory($this->strictMetadata($stored));
                if (! $written || $verified === null || ! in_array($item['advice_id'], array_column($verified['items'], 'advice_id'), true)
                    || ! $this->comparisonIsCurrent($item)) {
                    throw new RuntimeException('comparison_record_readback_or_source_held');
                }

                return ['success' => true, 'state' => 'recorded', 'advice_id' => $item['advice_id'], 'replayed' => false];
            });
        } catch (Throwable) {
            return ['success' => false, 'state' => 'held', 'error_code' => 'advisory_recording_held'];
        }
    }

    private function comparisonLimit(): int
    {
        return max(0, min(100, (int) config('file_registry.face_comparison.max_records')));
    }

    private function comparisonItem(array $advice, int $sourceEmbeddingId): array
    {
        $success = $advice['success'] ?? null;
        $same = $advice['same_person'] ?? null;
        $confidence = $advice['confidence'] ?? null;
        $decision = $advice['decision'] ?? null;
        if (! is_bool($success) || (! is_bool($same) && $same !== null)
            || ($confidence !== null && ((! is_float($confidence) && ! is_int($confidence)) || ! is_finite((float) $confidence) || $confidence < 0 || $confidence > 1))
            || $decision !== ($same === null ? 'abstain' : ($same ? 'same_person' : 'different_person'))
            || (! $success && ($same !== null || $confidence !== null))
            || ($success && $confidence === null) || ($advice['operator_review_required'] ?? null) !== true
            || $sourceEmbeddingId <= 0 || ! is_int($advice['candidate_embedding_id'] ?? null) || $advice['candidate_embedding_id'] <= 0
            || ! is_array($advice['source_evidence'] ?? null) || ! array_is_list($advice['source_evidence']) || count($advice['source_evidence']) !== 2) {
            throw new RuntimeException('comparison_contract_invalid');
        }
        $evidence = [];
        foreach ($advice['source_evidence'] as $pin) {
            if (! is_array($pin) || ! is_int($pin['file_registry_id'] ?? null) || $pin['file_registry_id'] <= 0
                || ! $this->hash($pin['source_sha256'] ?? null) || ! $this->hash($pin['embedding_sha256'] ?? null)
                || ! is_string($pin['embedding_model'] ?? null) || trim($pin['embedding_model']) === '' || strlen($pin['embedding_model']) > 100
                || ($pin['face_region_convention'] ?? null) !== 'normalized_top_left' || ($pin['coordinate_basis'] ?? null) !== 'encoded_pixels') {
                throw new RuntimeException('comparison_source_pin_invalid');
            }
            $region = $pin['region'] ?? null;
            if (! is_array($region) || count($region) !== 4 || array_diff(array_keys($region), ['x', 'y', 'w', 'h']) !== []) {
                throw new RuntimeException('comparison_region_invalid');
            }
            foreach (['x', 'y', 'w', 'h'] as $axis) {
                if ((! is_int($region[$axis]) && ! is_float($region[$axis])) || ! is_finite((float) $region[$axis])) {
                    throw new RuntimeException('comparison_region_invalid');
                }
                $region[$axis] = (float) $region[$axis];
            }
            if ($region['x'] < 0 || $region['y'] < 0 || $region['w'] <= 0 || $region['h'] <= 0
                || $region['x'] + $region['w'] > 1 || $region['y'] + $region['h'] > 1) {
                throw new RuntimeException('comparison_region_invalid');
            }
            $evidence[] = ['file_registry_id' => $pin['file_registry_id'], 'source_sha256' => $pin['source_sha256'],
                'embedding_sha256' => $pin['embedding_sha256'], 'embedding_model' => $pin['embedding_model'], 'region' => $region,
                'face_region_convention' => 'normalized_top_left', 'coordinate_basis' => 'encoded_pixels'];
        }
        $provider = $advice['provider'] ?? '';
        $model = $advice['model'] ?? '';
        $error = $advice['error_code'] ?? null;
        if (! is_string($provider) || strlen($provider) > 100 || ! is_string($model) || strlen($model) > 100
            || ($success && ($provider === '' || $model === ''))
            || ($error !== null && (! is_string($error) || ! preg_match('/^[a-z_]{1,80}$/D', $error)))) {
            throw new RuntimeException('comparison_model_or_error_invalid');
        }
        $crops = $advice['crop_sha256'] ?? [];
        if (! is_array($crops) || ! array_is_list($crops) || ($success && count($crops) !== 2)
            || (count($crops) !== 0 && count($crops) !== 2) || array_filter($crops, fn ($hash) => ! $this->hash($hash))) {
            throw new RuntimeException('comparison_crop_pins_invalid');
        }
        $item = ['success' => $success, 'decision' => $decision, 'same_person' => $same, 'confidence' => $confidence === null ? null : (float) $confidence,
            'operator_review_required' => true, 'provider' => $provider, 'model' => $model, 'error_code' => $error,
            'source_embedding_id' => $sourceEmbeddingId, 'candidate_embedding_id' => $advice['candidate_embedding_id'],
            'source_evidence' => $evidence, 'crop_sha256' => $crops];
        $item['advice_id'] = $this->comparisonDigest($item);
        $item['recorded_at'] = now()->utc()->toIso8601String();

        return $item;
    }

    private function comparisonDigest(array $item): string
    {
        unset($item['advice_id'], $item['recorded_at']);
        foreach ($item['source_evidence'] as &$pin) {
            ksort($pin['region']);
            ksort($pin);
        }
        unset($pin);
        ksort($item);

        return hash('sha256', json_encode($item, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function comparisonHistory(array $metadata): ?array
    {
        if (! array_key_exists(self::COMPARISON_KEY, $metadata)) {
            return ['schema' => self::COMPARISON_SCHEMA, 'items' => []];
        }
        $history = $metadata[self::COMPARISON_KEY];
        if (! is_array($history) || array_diff(array_keys($history), ['schema', 'items']) !== [] || ($history['schema'] ?? null) !== self::COMPARISON_SCHEMA
            || ! is_array($history['items'] ?? null) || ! array_is_list($history['items']) || count($history['items']) > $this->comparisonLimit()) {
            return null;
        }
        $seen = [];
        foreach ($history['items'] as $item) {
            try {
                if (! is_array($item) || array_diff(array_keys($item), ['success', 'decision', 'same_person', 'confidence', 'operator_review_required', 'provider', 'model', 'error_code', 'source_embedding_id', 'candidate_embedding_id', 'source_evidence', 'crop_sha256', 'advice_id', 'recorded_at']) !== [] || ! is_string($item['recorded_at'] ?? null) || strtotime($item['recorded_at']) === false
                    || ! is_int($item['source_embedding_id'] ?? null)) {
                    return null;
                }
                $valid = $this->comparisonItem($item, $item['source_embedding_id']);
                if (! $this->hash($item['advice_id'] ?? null) || ! hash_equals($valid['advice_id'], $item['advice_id']) || isset($seen[$item['advice_id']])) {
                    return null;
                }
                $seen[$item['advice_id']] = true;
                foreach ($item['source_evidence'] as $pin) {
                    if (array_diff(array_keys($pin), array_keys($valid['source_evidence'][0])) !== []) {
                        return null;
                    }
                }
            } catch (Throwable) {
                return null;
            }
        }

        return $history;
    }

    private function comparisonIsCurrent(array $item): bool
    {
        try {
            $embeddings = app(FaceEmbeddingService::class);
            foreach ([$item['source_embedding_id'], $item['candidate_embedding_id']] as $index => $id) {
                $pin = $item['source_evidence'][$index];
                $current = $embeddings->comparisonEmbeddingEvidence($id);
                if ($current === null || $current != $pin) {
                    return false;
                }
                $file = DB::table('file_registry')->where('id', $pin['file_registry_id'])->where('status', 'active')->first(['current_path']);
                if (! $file || ! $this->currentHash($file->current_path, $pin['source_sha256'])) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function comparisonProjection(array $metadata, ?object $file, ?string $hash): array
    {
        $history = $this->comparisonHistory($metadata);
        if ($history === null) {
            return ['schema' => self::COMPARISON_SCHEMA, 'items' => [], 'truncated' => false, 'state' => 'held'];
        }
        $items = [];
        foreach ($history['items'] as $item) {
            $current = $file && $hash && $item['source_evidence'][0]['file_registry_id'] === (int) $file->id
                && hash_equals($hash, $item['source_evidence'][0]['source_sha256']) && $this->comparisonIsCurrent($item);
            $view = $item;
            $view['state'] = $current ? 'current' : 'stale';
            if (! $current) {
                $view['decision'] = 'abstain';
                $view['same_person'] = null;
                $view['confidence'] = null;
            }
            foreach ($view['source_evidence'] as &$pin) {
                $pin['preview_url'] = $current ? '/api/media/photo-evidence/preview?'.http_build_query([
                    'file_registry_id' => $pin['file_registry_id'], 'source_sha256' => $pin['source_sha256']]) : null;
            }
            unset($pin);
            $items[] = $view;
        }

        return ['schema' => self::COMPARISON_SCHEMA, 'items' => $items, 'truncated' => false];
    }

    public function preview(array $selector, string $hash): array
    {
        if ($this->hash($hash) === null) {
            throw new InvalidArgumentException('A current source SHA is required.');
        }
        $binding = $this->resolve($selector);
        $proof = $this->sources->reviewSourcePath($binding['path'], $hash, true);
        if (! ($proof['success'] ?? false)) {
            throw new RuntimeException($proof['error_code'] ?? 'source_photo_unavailable');
        }
        $image = imagecreatefromstring($proof['bytes']);
        if (! $image) {
            throw new RuntimeException('source_crop_unavailable');
        }
        try {
            $max = max(100, min(2000, (int) config('metadata_writeback.preview_max_dimension', 1600)));
            $ratio = min(1, $max / max(imagesx($image), imagesy($image)));
            $scaled = imagescale($image, max(1, (int) floor(imagesx($image) * $ratio)), max(1, (int) floor(imagesy($image) * $ratio)));
            if (! $scaled) {
                throw new RuntimeException('source_crop_unavailable');
            }
            try {
                ob_start();
                try {
                    imagejpeg($scaled, null, 90);
                    $bytes = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            } finally {
                imagedestroy($scaled);
            }
        } finally {
            imagedestroy($image);
        }
        if (! is_string($bytes) || $bytes === '' || ! $this->bindingMatches($binding, $selector) || ! $this->currentHash($binding['path'], $hash)) {
            throw new RuntimeException('source_photo_changed');
        }

        return ['bytes' => $bytes, 'source_sha256' => $hash];
    }

    private function resolve(array $selector): array
    {
        $keys = array_values(array_intersect(array_keys($selector), ['asset_uuid', 'file_registry_id', 'face_match_id', 'face_id', 'media_id']));
        if (count($keys) !== 1 || array_diff(array_keys($selector), [...$keys, 'tree_id']) !== []
            || (isset($selector['tree_id']) && $keys[0] !== 'media_id')) {
            throw new InvalidArgumentException('Choose one exact photo selector.');
        }
        $key = $keys[0];
        if (! is_string($selector[$key]) && ! is_int($selector[$key])) {
            throw new InvalidArgumentException('Invalid photo selector.');
        }
        if ($key === 'asset_uuid' ? ! Str::isUuid((string) $selector[$key]) : ! ctype_digit((string) $selector[$key]) || (int) $selector[$key] <= 0) {
            throw new InvalidArgumentException('Invalid photo selector.');
        }
        $file = null;
        $media = null;
        if (in_array($key, ['asset_uuid', 'file_registry_id'], true)) {
            $file = DB::table('file_registry')->where($key === 'asset_uuid' ? 'asset_uuid' : 'id', $selector[$key])->where('status', 'active')->first();
        } elseif ($key === 'face_id') {
            $face = DB::table('file_registry_faces')->where('id', $selector[$key])->first(['file_registry_id']);
            $file = $face ? DB::table('file_registry')->where('id', $face->file_registry_id)->where('status', 'active')->first() : null;
        } elseif ($key === 'face_match_id') {
            $queue = DB::table('genealogy_face_match_queue')->where('id', $selector[$key])->first();
            if ($queue && $queue->media_id) {
                $media = DB::table('genealogy_media')->where('id', $queue->media_id)->where('tree_id', $queue->tree_id)->first();
                if (! $media) {
                    throw new RuntimeException('media_tree_mismatch');
                }
            }
            $face = $queue && $queue->file_registry_face_id ? DB::table('file_registry_faces')->where('id', $queue->file_registry_face_id)->first(['file_registry_id']) : null;
            $file = $face ? DB::table('file_registry')->where('id', $face->file_registry_id)->where('status', 'active')->first() : null;
        } else {
            if ((! is_string($selector['tree_id'] ?? null) && ! is_int($selector['tree_id'] ?? null))
                || ! ctype_digit((string) $selector['tree_id']) || (int) $selector['tree_id'] <= 0) {
                throw new InvalidArgumentException('An exact family tree is required.');
            }
            $media = DB::table('genealogy_media')->where('id', $selector[$key])->where('tree_id', $selector['tree_id'])->first();
        }
        $mediaPath = $media ? ($media->nextcloud_path ?: $media->original_path) : null;
        if ($file && $media && ltrim((string) $file->current_path, '/') !== ltrim((string) $mediaPath, '/')) {
            throw new RuntimeException('face_media_source_mismatch');
        }
        if (! $file && is_string($mediaPath) && $mediaPath !== '') {
            $matches = DB::table('file_registry')->where('status', 'active')->whereIn('current_path', [ltrim($mediaPath, '/'), '/'.ltrim($mediaPath, '/')])->limit(2)->get();
            if ($matches->count() > 1) {
                throw new RuntimeException('ambiguous_photo_binding');
            }
            $file = $matches->first();
        }
        $path = $file?->current_path ?? $mediaPath;
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('photo_not_found');
        }

        return ['file' => $file, 'media' => $media, 'path' => $path];
    }

    private function bindingMatches(array $binding, array $selector): bool
    {
        try {
            return $binding == $this->resolve($selector);
        } catch (RuntimeException|InvalidArgumentException) {
            return false;
        }
    }

    private function currentHash(string $path, string $hash): bool
    {
        $deadline = hrtime(true) / 1e9 + max(.001, min(30, (float) config('file_registry.photo_review.hash_timeout_seconds', 10)));
        $proof = app(NextcloudFileApiService::class)->computeFileHash($path, $deadline, true);

        return ($proof['success'] ?? false) && hash_equals($hash, (string) ($proof['hash'] ?? ''));
    }

    private function faces(int $fileId, ?string $hash): array
    {
        $limit = max(1, min(100, (int) config('file_registry.photo_review.max_faces', 32)));
        $decisionLimit = max(1, min(50, (int) config('file_registry.photo_review.max_decisions_per_face', 10)));
        $rows = DB::table('file_registry_faces')->where('file_registry_id', $fileId)->where('source_current', 1)
            ->whereNull('superseded_at')->whereRaw('COALESCE(hidden, 0) = 0')->orderBy('id')->limit($limit + 1)->get();
        $items = [];
        $decisionsTruncated = false;
        foreach ($rows->take($limit) as $face) {
            $faceHash = $this->hash($face->source_sha256);
            $state = $faceHash && $hash ? (hash_equals($faceHash, $hash) ? 'current' : 'stale') : 'unknown';
            $region = [];
            foreach (['x', 'y', 'w', 'h'] as $axis) {
                $region[$axis] = is_numeric($face->{'region_'.$axis}) ? (float) $face->{'region_'.$axis} : null;
            }
            $valid = $face->face_region_convention === 'normalized_top_left' && ! in_array(null, $region, true)
                && $region['x'] >= 0 && $region['y'] >= 0 && $region['w'] > 0 && $region['h'] > 0
                && $region['x'] + $region['w'] <= 1 && $region['y'] + $region['h'] <= 1;
            $approved = $state === 'current' && $valid && $face->global_identity_uuid
                ? app(GlobalFaceWritebackApprovalService::class)->approvedFace((int) $face->id) : null;
            $identity = $face->global_identity_uuid ? DB::table('global_face_identities')->where('identity_uuid', $face->global_identity_uuid)->first(['canonical_name']) : null;
            $decisions = DB::table('genealogy_face_match_queue')->where('file_registry_face_id', $face->id)->orderByDesc('id')->limit($decisionLimit + 1)->get();
            $decisionsTruncated = $decisionsTruncated || $decisions->count() > $decisionLimit;
            $decisionItems = [];
            foreach ($decisions->take($decisionLimit) as $decision) {
                $person = $decision->suggested_person_id ? DB::table('genealogy_persons')->where('id', $decision->suggested_person_id)
                    ->where('tree_id', $decision->tree_id)->first(['given_name', 'surname']) : null;
                $decisionItems[] = ['id' => (int) $decision->id, 'tree_id' => (int) $decision->tree_id, 'status' => $decision->status,
                    'person_id' => $person ? (int) $decision->suggested_person_id : null,
                    'name' => $person ? trim($person->given_name.' '.$person->surname) : null, 'reviewed_at' => $decision->reviewed_at ?? null];
            }
            $mappings = $face->global_identity_uuid ? DB::table('global_face_identity_genealogy_links as link')
                ->join('genealogy_persons as person', function ($join): void {
                    $join->on('person.id', '=', 'link.genealogy_person_id')->on('person.tree_id', '=', 'link.tree_id');
                })->where('link.identity_uuid', $face->global_identity_uuid)->orderBy('link.tree_id')->limit(50)
                ->get(['link.tree_id', 'person.id', 'person.given_name', 'person.surname'])->map(fn ($row) => [
                    'tree_id' => (int) $row->tree_id, 'person_id' => (int) $row->id, 'name' => trim($row->given_name.' '.$row->surname),
                ])->all() : [];
            // A rejection of a different candidate is history, not a rejection of
            // this approved individual. Compare exact person/tree pairs only.
            $conflict = false;
            foreach ($mappings as $mapping) {
                $conflict = $conflict || DB::table('genealogy_face_match_queue')->where('file_registry_face_id', $face->id)
                    ->where('tree_id', $mapping['tree_id'])->where('suggested_person_id', $mapping['person_id'])->where('status', 'rejected')->exists();
            }
            $items[] = ['id' => (int) $face->id, 'source_label' => $face->person_name,
                'canonical_name' => $identity?->canonical_name, 'identity_state' => $conflict ? 'conflict' : ($approved ? 'operator_confirmed' : ($face->global_identity_uuid ? 'held' : 'unconfirmed')),
                'source_state' => $state, 'region' => $valid ? $region : null,
                'crop_url' => $state === 'current' && $valid ? '/api/media/faces/registry-crop/'.$face->id.'?source_sha256='.$hash
                    .'&region_fingerprint='.hash('sha256', $hash.json_encode($region, JSON_THROW_ON_ERROR)) : null,
                'tree_mappings' => $mappings, 'decisions' => $decisionItems];
        }

        return ['items' => $items, 'truncated' => $rows->count() > $limit, 'decisions_truncated' => $decisionsTruncated];
    }

    private function hash(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) ? $value : null;
    }
}
