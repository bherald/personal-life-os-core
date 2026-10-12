<?php

namespace App\Services\Files;

use App\Services\ExifWritebackService;
use App\Services\FaceRegionService;
use App\Services\NextcloudFileApiService;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Exact-asset, operator-approved replacement of the complete MWG face region list. */
class ReviewedFaceMetadataReplacementService
{
    public const ACTION = 'replace_reviewed_face_metadata';

    public const APPEND_ACTION = 'append_reviewed_face_metadata';

    public function __construct(
        private readonly FaceRegionService $regions,
        private readonly ExifWritebackService $writeback,
    ) {}

    /** Read-only evidence used to prepare an exact region-index replacement map. */
    public function plan(string $assetUuid): array
    {
        $asset = $this->asset($assetUuid);
        $evidence = $this->evidence($asset);

        return ['success' => true, 'identity_approval' => false,
            'asset_uuid' => $asset->asset_uuid, 'file_registry_id' => (int) $asset->id,
            'source_sha256' => $evidence['source_sha256'],
            'region_info_sha256' => $evidence['region_info_sha256'],
            'source_dimensions' => $evidence['source_dimensions'],
            'region_info' => $evidence['region_info'],
            'region_count' => count($evidence['region_info']['RegionList'] ?? [])];
    }

    /** Read-only proof for a one-face append to an empty RegionList. */
    public function planAppend(string $assetUuid): array
    {
        $plan = $this->plan($assetUuid);
        $dimensions = $plan['source_dimensions'] ?? null;
        $plan['append_eligible'] = $plan['region_count'] === 0 && is_array($dimensions)
            && is_int($dimensions['width'] ?? null) && $dimensions['width'] > 0
            && is_int($dimensions['height'] ?? null) && $dimensions['height'] > 0;

        return $plan;
    }

    /**
     * Preview defaults to read-only. Applying requires a current trusted CLI caller;
     * the worker binds its approval to this complete registered argument object.
     */
    public function replace(
        string $assetUuid,
        string $expectedSourceHash,
        string $expectedRegionInfoHash,
        array $replacements,
        string $idempotencyKey,
        bool $dryRun = true,
    ): array {
        $this->validateRequest($assetUuid, $expectedSourceHash, $expectedRegionInfoHash, $replacements, $idempotencyKey);
        $asset = $this->asset($assetUuid);

        if ($dryRun) {
            $evidence = $this->evidence($asset);
            $this->assertProof($evidence, $expectedSourceHash, $expectedRegionInfoHash);
            $this->assertCompleteIndexMap($evidence, $replacements);
            $result = $this->writeback->replaceReviewedFaceMetadata(
                $asset->current_path, $expectedSourceHash, $expectedRegionInfoHash, $replacements, true
            );

            return $this->present($asset, $result, null, true);
        }

        $caller = $this->trustedCaller();
        $request = ['assetUuid' => $assetUuid, 'registryPath' => $asset->registry_path,
            'expectedSourceHash' => $expectedSourceHash,
            'expectedRegionInfoHash' => $expectedRegionInfoHash, 'replacements' => $replacements,
            'idempotencyKey' => $idempotencyKey, 'dryRun' => false,
            'registryPathSha256' => hash('sha256', $asset->registry_path),
            'resolvedPathSha256' => hash('sha256', $asset->current_path)];
        $digest = $this->digest($request);
        $claim = $this->claim($assetUuid, $idempotencyKey, $digest, $request, $caller, self::ACTION,
            'plos.reviewed_face_metadata_replacement.v1');
        if (isset($claim['replay'])) {
            return $claim['replay'];
        }

        $dispatchAttempted = false;
        try {
            return DB::transaction(function () use ($assetUuid, $expectedSourceHash, $expectedRegionInfoHash,
                $replacements, $idempotencyKey, $digest, $caller, $asset, $request, &$dispatchAttempted): array {
                $lockedAsset = $this->asset($assetUuid, true);
                if ((int) $lockedAsset->id !== (int) $asset->id || $lockedAsset->registry_path !== $asset->registry_path
                    || $lockedAsset->registry_path !== $request['registryPath']
                    || $lockedAsset->current_path !== $asset->current_path
                    || ! hash_equals($request['registryPathSha256'], hash('sha256', $lockedAsset->registry_path))
                    || ! hash_equals($request['resolvedPathSha256'], hash('sha256', $lockedAsset->current_path))) {
                    throw new RuntimeException('reviewed_face_metadata_asset_changed');
                }
                $currentEvidence = $this->evidence($lockedAsset);
                $this->assertProof($currentEvidence, $expectedSourceHash, $expectedRegionInfoHash);
                $this->assertCompleteIndexMap($currentEvidence, $replacements);
                $dispatchAttempted = true;
                $result = $this->writeback->replaceReviewedFaceMetadata(
                    $lockedAsset->current_path,
                    $expectedSourceHash,
                    $expectedRegionInfoHash,
                    $replacements,
                    false,
                    $caller,
                );

                if ($this->physicalOutcomeUnknown($result)) {
                    $this->finish($idempotencyKey, $digest, 'unknown', $result, $lockedAsset, self::ACTION,
                        'plos.reviewed_face_metadata_replacement.v1');

                    return $this->present($lockedAsset, $result, $idempotencyKey, false, true);
                }

                if (($result['success'] ?? false) !== true || ($result['metadata_file_written'] ?? null) !== false
                    && ($result['metadata_file_written'] ?? null) !== true) {
                    $this->finish($idempotencyKey, $digest, 'failed', $result, $lockedAsset, self::ACTION,
                        'plos.reviewed_face_metadata_replacement.v1');

                    return $this->present($lockedAsset, $result, $idempotencyKey, false);
                }

                $writtenHash = $result['written_sha256'] ?? null;
                $proposedHash = $result['proposed_region_info_sha256'] ?? null;
                if (! is_string($writtenHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $writtenHash)
                    || ! is_string($proposedHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $proposedHash)) {
                    throw new RuntimeException('reviewed_face_metadata_write_receipt_incomplete');
                }
                $after = $this->evidence($lockedAsset, $writtenHash);
                if (! hash_equals($proposedHash, $after['region_info_sha256'])) {
                    throw new RuntimeException('reviewed_face_metadata_postwrite_mismatch');
                }

                $presented = $this->present($lockedAsset, $result, $idempotencyKey, false);
                $this->finish($idempotencyKey, $digest, 'completed', $result, $lockedAsset, self::ACTION,
                    'plos.reviewed_face_metadata_replacement.v1', $presented);

                return $presented;
            });
        } catch (Throwable $error) {
            $this->finishAfterError($idempotencyKey, $digest, $asset, $dispatchAttempted, $error, self::ACTION);
            throw new RuntimeException($dispatchAttempted
                ? 'reviewed_face_metadata_outcome_unknown' : $this->safeErrorCode($error), previous: $error);
        }
    }

    /** Append exactly one approved current face; no path, name, or ROI is caller supplied. */
    public function append(string $assetUuid, string $expectedSourceHash, string $expectedRegionInfoHash,
        int $approvedFaceId, string $approvalReceiptId, string $idempotencyKey, bool $dryRun = true): array
    {
        $this->validateAppendRequest($assetUuid, $expectedSourceHash, $expectedRegionInfoHash,
            $approvedFaceId, $approvalReceiptId, $idempotencyKey);
        $asset = $this->asset($assetUuid);

        if ($dryRun) {
            $evidence = $this->evidence($asset);
            $this->assertProof($evidence, $expectedSourceHash, $expectedRegionInfoHash);
            $this->assertEmptyRegionList($evidence);
            $result = $this->writeback->appendReviewedFaceMetadata($asset->current_path, $expectedSourceHash,
                $expectedRegionInfoHash, $approvedFaceId, $approvalReceiptId, true);

            return $this->present($asset, $result, null, true);
        }

        $caller = $this->trustedCaller();
        $request = ['assetUuid' => $assetUuid, 'registryPath' => $asset->registry_path,
            'expectedSourceHash' => $expectedSourceHash, 'expectedRegionInfoHash' => $expectedRegionInfoHash,
            'approvedFaceId' => $approvedFaceId, 'approvalReceiptId' => $approvalReceiptId,
            'idempotencyKey' => $idempotencyKey, 'dryRun' => false,
            'registryPathSha256' => hash('sha256', $asset->registry_path),
            'resolvedPathSha256' => hash('sha256', $asset->current_path)];
        $digest = $this->digest($request);
        $claim = $this->claim($assetUuid, $idempotencyKey, $digest, $request, $caller, self::APPEND_ACTION,
            'plos.reviewed_face_metadata_append.v1');
        if (isset($claim['replay'])) {
            return $claim['replay'];
        }

        $dispatchAttempted = false;
        try {
            return DB::transaction(function () use ($assetUuid, $expectedSourceHash, $expectedRegionInfoHash,
                $approvedFaceId, $approvalReceiptId, $idempotencyKey, $digest, $caller, $asset, $request, &$dispatchAttempted): array {
                $lockedAsset = $this->asset($assetUuid, true);
                if ((int) $lockedAsset->id !== (int) $asset->id || $lockedAsset->registry_path !== $asset->registry_path
                    || $lockedAsset->registry_path !== $request['registryPath']
                    || $lockedAsset->current_path !== $asset->current_path
                    || ! hash_equals($request['registryPathSha256'], hash('sha256', $lockedAsset->registry_path))
                    || ! hash_equals($request['resolvedPathSha256'], hash('sha256', $lockedAsset->current_path))) {
                    throw new RuntimeException('reviewed_face_metadata_asset_changed');
                }
                $currentEvidence = $this->evidence($lockedAsset);
                $this->assertProof($currentEvidence, $expectedSourceHash, $expectedRegionInfoHash);
                $this->assertEmptyRegionList($currentEvidence);
                $dispatchAttempted = true;
                $result = $this->writeback->appendReviewedFaceMetadata($lockedAsset->current_path,
                    $expectedSourceHash, $expectedRegionInfoHash, $approvedFaceId, $approvalReceiptId, false, $caller);

                if ($this->physicalOutcomeUnknown($result)) {
                    $this->finish($idempotencyKey, $digest, 'unknown', $result, $lockedAsset, self::APPEND_ACTION,
                        'plos.reviewed_face_metadata_append.v1');

                    return $this->present($lockedAsset, $result, $idempotencyKey, false, true);
                }
                if (($result['success'] ?? false) !== true || ! is_bool($result['metadata_file_written'] ?? null)) {
                    $this->finish($idempotencyKey, $digest, 'failed', $result, $lockedAsset, self::APPEND_ACTION,
                        'plos.reviewed_face_metadata_append.v1');

                    return $this->present($lockedAsset, $result, $idempotencyKey, false);
                }
                $writtenHash = $result['written_sha256'] ?? null;
                $proposedHash = $result['proposed_region_info_sha256'] ?? null;
                if (! is_string($writtenHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $writtenHash)
                    || ! is_string($proposedHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $proposedHash)) {
                    throw new RuntimeException('reviewed_face_metadata_write_receipt_incomplete');
                }
                $after = $this->evidence($lockedAsset, $writtenHash);
                if (! hash_equals($proposedHash, $after['region_info_sha256'])
                    || count($after['region_info']['RegionList'] ?? []) !== 1) {
                    throw new RuntimeException('reviewed_face_metadata_postwrite_mismatch');
                }
                $presented = $this->present($lockedAsset, $result, $idempotencyKey, false);
                $this->finish($idempotencyKey, $digest, 'completed', $result, $lockedAsset, self::APPEND_ACTION,
                    'plos.reviewed_face_metadata_append.v1', $presented);

                return $presented;
            });
        } catch (Throwable $error) {
            $this->finishAfterError($idempotencyKey, $digest, $asset, $dispatchAttempted, $error, self::APPEND_ACTION);
            throw new RuntimeException($dispatchAttempted
                ? 'reviewed_face_metadata_outcome_unknown' : $this->safeErrorCode($error), previous: $error);
        }
    }

    private function claim(string $assetUuid, string $receiptId, string $digest, array $request, array $caller,
        string $action, string $schema): array
    {
        return DB::transaction(function () use ($assetUuid, $receiptId, $digest, $request, $caller, $action, $schema): array {
            $asset = $this->asset($assetUuid, true);
            $old = DB::table('review_desk_receipts')->where('id', $receiptId)->lockForUpdate()->first();
            if ($old !== null) {
                if ($old->action !== $action || $old->reference !== $assetUuid || $old->request_digest !== $digest) {
                    throw new RuntimeException('reviewed_face_metadata_receipt_conflict');
                }
                if ($old->state !== 'completed') {
                    throw new RuntimeException('reviewed_face_metadata_outcome_held');
                }
                $payload = json_decode((string) $old->payload, true, flags: JSON_THROW_ON_ERROR);
                if (($payload['schema'] ?? null) !== $schema || ($payload['success'] ?? null) !== true
                    || ($payload['effect_status'] ?? null) !== 'completed'
                    || ! is_array($payload['request'] ?? null) || $this->digest($payload['request']) !== $digest
                    || ! is_array($payload['result'] ?? null)) {
                    throw new RuntimeException('reviewed_face_metadata_receipt_invalid');
                }
                $prior = $payload['result'];
                $expectedWrittenHash = $prior['written_sha256'] ?? null;
                $expectedRegionHash = $prior['proposed_region_info_sha256'] ?? null;
                if (! is_string($expectedWrittenHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $expectedWrittenHash)
                    || ! is_string($expectedRegionHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $expectedRegionHash)) {
                    throw new RuntimeException('reviewed_face_metadata_receipt_invalid');
                }
                $this->assertProof($this->evidence($asset, $expectedWrittenHash), $expectedWrittenHash, $expectedRegionHash);

                return ['replay' => array_replace($prior, ['replayed' => true])];
            }

            if (($request['registryPath'] ?? null) !== $asset->registry_path
                || ! hash_equals((string) ($request['registryPathSha256'] ?? ''), hash('sha256', $asset->registry_path))
                || ! hash_equals((string) ($request['resolvedPathSha256'] ?? ''), hash('sha256', $asset->current_path))) {
                throw new RuntimeException('reviewed_face_metadata_asset_changed');
            }
            $evidence = $this->evidence($asset);
            $this->assertProof($evidence, $request['expectedSourceHash'], $request['expectedRegionInfoHash']);
            if ($action === self::APPEND_ACTION) {
                $this->assertEmptyRegionList($evidence);
            } else {
                $this->assertCompleteIndexMap($evidence, $request['replacements']);
            }
            if (DB::table('review_desk_receipts')->whereIn('action', [self::ACTION, self::APPEND_ACTION])->where('reference', $assetUuid)
                ->whereIn('state', ['processing', 'unknown'])->exists()) {
                throw new RuntimeException('reviewed_face_metadata_asset_outcome_held');
            }

            DB::table('review_desk_receipts')->insert([
                'id' => $receiptId, 'reference' => $assetUuid, 'idempotency_key' => $receiptId,
                'request_digest' => $digest, 'action' => $action, 'state' => 'processing',
                'payload' => json_encode(['schema' => $schema,
                    'success' => false, 'identity_approval' => false, 'effect_status' => 'not_started',
                    'request' => $request, 'caller' => $caller], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [];
        });
    }

    private function finish(string $receiptId, string $digest, string $state, array $result, object $asset,
        string $action, string $schema, ?array $presented = null): void
    {
        if (! in_array($state, ['completed', 'failed', 'unknown'], true)) {
            throw new RuntimeException('reviewed_face_metadata_receipt_state_invalid');
        }
        $row = DB::table('review_desk_receipts')->where('id', $receiptId)->lockForUpdate()->first();
        if ($row === null || $row->action !== $action || $row->request_digest !== $digest
            || $row->reference !== $asset->asset_uuid || $row->state !== 'processing') {
            throw new RuntimeException('reviewed_face_metadata_receipt_changed');
        }
        $safeResult = $presented ?? $this->present($asset, $result, $receiptId, false, $state === 'unknown');
        $payload = ['schema' => $schema,
            'success' => $state === 'completed', 'identity_approval' => false,
            'effect_status' => match ($state) {
                'completed' => 'completed', 'failed' => 'known_not_written', default => 'physical_outcome_unknown',
            }, 'request' => json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR)['request'] ?? [],
            'trusted_caller' => json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR)['caller'] ?? null,
            'asset_uuid' => $asset->asset_uuid, 'file_registry_id' => (int) $asset->id,
            'registry_path_sha256' => hash('sha256', $asset->registry_path),
            'resolved_path_sha256' => hash('sha256', $asset->current_path),
            'result' => $safeResult, 'writer_proof' => $this->writerProof($result)];
        DB::table('review_desk_receipts')->where('id', $receiptId)->update([
            'state' => $state, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
    }

    private function finishAfterError(string $receiptId, string $digest, object $asset, bool $dispatchAttempted, Throwable $error,
        string $action): void
    {
        try {
            DB::transaction(function () use ($receiptId, $digest, $dispatchAttempted, $error, $action): void {
                $row = DB::table('review_desk_receipts')->where('id', $receiptId)->lockForUpdate()->first();
                if ($row === null || $row->state !== 'processing' || $row->request_digest !== $digest
                    || $row->action !== $action) {
                    return;
                }
                $payload = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);
                $payload['effect_status'] = $dispatchAttempted ? 'physical_outcome_unknown' : 'known_not_written';
                $payload['error_code'] = $this->safeErrorCode($error);
                DB::table('review_desk_receipts')->where('id', $receiptId)->update([
                    'state' => $dispatchAttempted ? 'unknown' : 'failed',
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            });
        } catch (Throwable) {
            // The committed processing receipt remains a fail-closed custody hold.
        }
    }

    private function asset(string $assetUuid, bool $lock = false): object
    {
        if (! Str::isUuid($assetUuid) || strtolower($assetUuid) !== $assetUuid) {
            throw new RuntimeException('reviewed_face_metadata_asset_selector_invalid');
        }
        $query = DB::table('file_registry')->where('asset_uuid', $assetUuid)->where('status', 'active');
        if ($lock) {
            $query->lockForUpdate();
        }
        $asset = $query->first(['id', 'asset_uuid', 'current_path', 'status', 'mime_type']);
        if ($asset === null || ! is_string($asset->current_path) || $asset->current_path === ''
            || ! is_string($asset->mime_type) || ! str_starts_with(strtolower($asset->mime_type), 'image/')) {
            throw new RuntimeException('reviewed_face_metadata_asset_unavailable');
        }
        $registryPath = $asset->current_path;
        $mappedPath = app(NextcloudFileApiService::class)->localPath($registryPath);
        $root = realpath((string) config('services.nextcloud.data_path'));
        if (! is_string($mappedPath) || $mappedPath === '' || is_link($mappedPath)) {
            throw new RuntimeException('reviewed_face_metadata_source_unavailable');
        }
        $path = realpath($mappedPath);
        if ($root === false || $path === false || ! is_file($path) || ! is_readable($path)
            || ! str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('reviewed_face_metadata_source_unavailable');
        }
        $asset->registry_path = $registryPath;
        $asset->current_path = $path;

        return $asset;
    }

    private function evidence(object $asset, ?string $expectedSourceHash = null): array
    {
        $evidence = $this->regions->readFaceMetadataEvidence($asset->current_path, $expectedSourceHash);
        if (($evidence['source_path'] ?? null) !== $asset->current_path
            || ! is_string($evidence['source_sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $evidence['source_sha256'])
            || ! is_string($evidence['region_info_sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $evidence['region_info_sha256'])
            || ! is_array($evidence['region_info'] ?? null)
            || ! is_array($evidence['region_info']['RegionList'] ?? []) || ! array_is_list($evidence['region_info']['RegionList'] ?? [])) {
            throw new RuntimeException('reviewed_face_metadata_evidence_invalid');
        }

        return $evidence;
    }

    private function assertProof(array $evidence, string $sourceHash, string $regionInfoHash): void
    {
        if (! hash_equals($sourceHash, $evidence['source_sha256'])
            || ! hash_equals($regionInfoHash, $evidence['region_info_sha256'])) {
            throw new RuntimeException('reviewed_face_metadata_proof_changed');
        }
    }

    private function assertEmptyRegionList(array $evidence): void
    {
        if (($evidence['region_info']['RegionList'] ?? []) !== []) {
            throw new RuntimeException('reviewed_face_metadata_append_requires_empty_region_list');
        }
    }

    private function validateAppendRequest(string $assetUuid, string $sourceHash, string $regionInfoHash,
        int $faceId, string $approvalReceiptId, string $idempotencyKey): void
    {
        if (! Str::isUuid($assetUuid) || strtolower($assetUuid) !== $assetUuid
            || ! preg_match('/\A[a-f0-9]{64}\z/', $sourceHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $regionInfoHash)
            || $faceId < 1 || ! Str::isUuid($approvalReceiptId) || strtolower($approvalReceiptId) !== $approvalReceiptId
            || ! Str::isUuid($idempotencyKey) || strtolower($idempotencyKey) !== $idempotencyKey
            || strlen(self::APPEND_ACTION) > 30) {
            throw new RuntimeException('reviewed_face_metadata_append_request_invalid');
        }
    }

    private function validateRequest(string $assetUuid, string $sourceHash, string $regionInfoHash, array $replacements, string $idempotencyKey): void
    {
        if (! Str::isUuid($assetUuid) || strtolower($assetUuid) !== $assetUuid
            || ! preg_match('/\A[a-f0-9]{64}\z/', $sourceHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $regionInfoHash)
            || ! Str::isUuid($idempotencyKey) || strtolower($idempotencyKey) !== $idempotencyKey
            || ! array_is_list($replacements) || $replacements === []
            || count($replacements) > (int) config('file_registry.photo_review.max_faces')) {
            throw new RuntimeException('reviewed_face_metadata_request_invalid');
        }
        $indices = [];
        $faces = [];
        foreach ($replacements as $replacement) {
            if (! is_array($replacement) || count($replacement) !== 3
                || array_diff(array_keys($replacement), ['region_index', 'approved_face_id', 'approval_receipt_id']) !== []
                || ! is_int($replacement['region_index'] ?? null) || $replacement['region_index'] < 0
                || ! is_int($replacement['approved_face_id'] ?? null) || $replacement['approved_face_id'] < 1
                || ! is_string($replacement['approval_receipt_id'] ?? null) || ! Str::isUuid($replacement['approval_receipt_id'])
                || strtolower($replacement['approval_receipt_id']) !== $replacement['approval_receipt_id']) {
                throw new RuntimeException('reviewed_face_metadata_replacement_map_invalid');
            }
            if (isset($indices[$replacement['region_index']]) || isset($faces[$replacement['approved_face_id']])) {
                throw new RuntimeException('reviewed_face_metadata_replacement_map_ambiguous');
            }
            $indices[$replacement['region_index']] = true;
            $faces[$replacement['approved_face_id']] = true;
        }
    }

    private function trustedCaller(): array
    {
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        $keys = ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256'];
        if (! is_array($caller) || count($caller) !== count($keys) || array_diff(array_keys($caller), $keys) !== []) {
            throw new RuntimeException('reviewed_face_metadata_trusted_caller_required');
        }
        foreach ($keys as $key) {
            if (! is_string($caller[$key]) || $caller[$key] === '' || strlen($caller[$key]) > 255
                || preg_match('/[\x00-\x20\x7f]/', $caller[$key])
                || (str_ends_with($key, 'sha256') && ! preg_match('/\A[a-f0-9]{64}\z/', $caller[$key]))) {
                throw new RuntimeException('reviewed_face_metadata_trusted_caller_required');
            }
        }

        return $caller;
    }

    private function physicalOutcomeUnknown(array $result): bool
    {
        $written = $result['metadata_file_written'] ?? null;

        return ! is_bool($written)
            || ($result['physical_write_outcome_unknown'] ?? false) === true
            || ($result['repair_required'] ?? false) === true
            || (($result['success'] ?? false) !== true
                && (($result['source_replaced'] ?? false) === true || $written !== false));
    }

    private function present(object $asset, array $result, ?string $receiptId, bool $dryRun, bool $unknown = false): array
    {
        $out = ['success' => ($result['success'] ?? false) === true && ! $unknown,
            'status' => $unknown ? 'unknown' : (($result['success'] ?? false) === true ? 'ready' : 'held'),
            'identity_approval' => false, 'asset_uuid' => $asset->asset_uuid,
            'file_registry_id' => (int) $asset->id, 'dry_run' => $dryRun,
            'metadata_file_written' => ($result['metadata_file_written'] ?? false) === true,
            'source_sha256' => $result['source_sha256'] ?? null,
            'region_info_sha256' => $result['region_info_sha256'] ?? null,
            'proposed_region_info_sha256' => $result['proposed_region_info_sha256'] ?? null,
            'regions_reviewed' => $result['regions_reviewed'] ?? null];
        if (isset($result['proposed_region_info']) && is_array($result['proposed_region_info'])) {
            $out['proposed_region_info'] = $result['proposed_region_info'];
        }
        if ($receiptId !== null) {
            $out['receipt_id'] = $receiptId;
        }
        if (($result['success'] ?? false) !== true) {
            $out['error_code'] = $this->safeWriterFailureCode($result['failure_code'] ?? null)
                ?? 'reviewed_face_metadata_write_held';
            $failureStage = $this->safeWriterFailureStage($result['failure_stage'] ?? null);
            if ($failureStage !== null) {
                $out['failure_stage'] = $failureStage;
            }
        }
        if (isset($result['written_sha256']) && is_string($result['written_sha256'])) {
            $out['written_sha256'] = $result['written_sha256'];
        }
        if (isset($result['backup_sha256']) && is_string($result['backup_sha256'])) {
            $out['backup_sha256'] = $result['backup_sha256'];
        }

        return $out;
    }

    private function writerProof(array $result): array
    {
        $proof = [];
        foreach (['source_sha256', 'region_info_sha256', 'proposed_region_info_sha256', 'written_sha256',
            'backup_sha256', 'regions_reviewed', 'faces_advanced', 'vectors_advanced', 'metadata_file_written', 'unchanged'] as $key) {
            if (array_key_exists($key, $result) && (is_scalar($result[$key]) || $result[$key] === null)) {
                $proof[$key] = $result[$key];
            }
        }
        $failureCode = $this->safeWriterFailureCode($result['failure_code'] ?? null);
        if ($failureCode !== null) {
            $proof['failure_code'] = $failureCode;
        }
        $failureStage = $this->safeWriterFailureStage($result['failure_stage'] ?? null);
        if ($failureStage !== null) {
            $proof['failure_stage'] = $failureStage;
        }

        return $proof;
    }

    private function safeWriterFailureCode(mixed $code): ?string
    {
        return is_string($code) && in_array($code, [
            'reviewed_face_metadata_roundtrip_mismatch', 'decision_preimage_changed',
            'metadata_transition_partial_after_copyback', 'metadata_transition_failed_before_copyback',
            'capture_date_tags_changed', 'date_only_capture_metadata_protected',
        ], true) ? $code : null;
    }

    private function safeWriterFailureStage(mixed $stage): ?string
    {
        return is_string($stage) && in_array($stage, [
            'source_preflight', 'metadata_preparation', 'temporary_face_metadata_roundtrip',
            'prepared_file_verification', 'source_copyback', 'source_provenance_commit',
            'temporary_date_tag_preservation',
        ], true) ? $stage : null;
    }

    private function safeErrorCode(Throwable $error): string
    {
        $message = $error->getMessage();
        if (preg_match('/\Areviewed_face_metadata_[a-z0-9_]+\z/', $message)) {
            return $message;
        }

        return 'reviewed_face_metadata_held';
    }

    private function digest(array $request): string
    {
        return hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function assertCompleteIndexMap(array $evidence, array $replacements): void
    {
        $regionList = $evidence['region_info']['RegionList'] ?? [];
        if (count($regionList) !== count($replacements)) {
            throw new RuntimeException('reviewed_face_metadata_replacement_map_incomplete');
        }
        $indices = array_column($replacements, 'region_index');
        sort($indices, SORT_NUMERIC);
        if ($indices !== range(0, count($regionList) - 1)) {
            throw new RuntimeException('reviewed_face_metadata_replacement_map_incomplete');
        }
    }
}
