<?php

namespace App\Services\Files;

use App\Services\ExifWritebackService;
use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\GlobalFaceWritebackApprovalService;
use App\Services\NextcloudFileApiService;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ScanDateEstimateReviewDecisionService
{
    private const EXACT_CAPTURE_ACTION = 'write_exact_capture_date';

    /** @var list<string> */
    private const REJECTION_REASONS = [
        'wrong_date',
        'insufficient_visual_evidence',
        'not_a_scan',
        'duplicate',
        'other',
    ];

    /** Read current catalog preimage; an ISO date expresses no known capture time. */
    public function planExactCatalogDate(string $assetUuid, string $captureDate): array
    {
        try {
            $asset = $this->catalogDateAsset($assetUuid, $captureDate);
            $this->assertNoExactCaptureDateHold($assetUuid);
            if (DB::table('review_desk_receipts')->where('reference', $assetUuid)->where('action', 'set_exact_catalog_date')->whereIn('state', ['processing', 'unknown'])->exists()) {
                throw new RuntimeException('An earlier catalog date decision requires reconciliation.');
            }
            $path = $this->exactCaptureDateLocalPath($asset);
            $hash = hash_file('sha256', $path);
            if (! is_string($hash) || $asset->content_hash !== $hash) {
                throw new RuntimeException('Current physical source differs from catalog hash.');
            }

            return ['success' => true, 'dry_run' => true, 'asset_uuid' => $assetUuid,
                'source_sha256' => $hash, 'catalog_preimage_sha256' => $this->exactDateDigest($this->catalogDatePreimage($asset)),
                'catalog_before' => $this->catalogDatePreimage($asset), 'capture_date' => $captureDate,
                'capture_time_known' => false, 'metadata_file_written' => false, 'identity_approval' => false];
        } catch (Throwable $error) {
            return ['success' => false, 'dry_run' => true, 'status' => 'held', 'error' => $error->getMessage()];
        }
    }

    /** Catalog-only operator decision; physical write remains a distinct approved operation. */
    public function setExactCatalogDate(string $assetUuid, string $captureDate, string $expectedSourceHash, string $expectedCatalogPreimageHash, string $idempotencyKey, bool $dryRun = true): array
    {
        try {
            if (! Str::isUuid($idempotencyKey) || strtolower($idempotencyKey) !== $idempotencyKey
                || ! preg_match('/\A[a-f0-9]{64}\z/', $expectedSourceHash)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $expectedCatalogPreimageHash)) {
                throw new RuntimeException('Exact source, catalog preimage and receipt selectors are required.');
            }
            $request = ['asset_uuid' => $assetUuid, 'capture_date' => $captureDate, 'capture_time_known' => false,
                'source_sha256' => $expectedSourceHash, 'catalog_preimage_sha256' => $expectedCatalogPreimageHash,
                'idempotency_key' => $idempotencyKey];
            $digest = $this->exactDateDigest($request);
            if ($dryRun) {
                $prior = DB::table('review_desk_receipts')->where('reference', $assetUuid)->where('idempotency_key', $idempotencyKey)->first();
                if ($prior && ($prior->action !== 'set_exact_catalog_date' || $prior->request_digest !== $digest || $prior->state !== 'completed')) {
                    throw new RuntimeException('Catalog date receipt conflicts or requires reconciliation.');
                }
                $plan = $this->planExactCatalogDate($assetUuid, $captureDate);
                if (! ($plan['success'] ?? false) || ($plan['source_sha256'] ?? null) !== $expectedSourceHash
                    || ($plan['catalog_preimage_sha256'] ?? null) !== $expectedCatalogPreimageHash) {
                    throw new RuntimeException('Current source or catalog differs from approved preimage.');
                }

                return $plan + ['status' => 'preview', 'request_digest' => $digest];
            }
            $caller = $this->exactDateTrustedCaller();

            return DB::transaction(function () use ($assetUuid, $captureDate, $expectedSourceHash, $expectedCatalogPreimageHash, $idempotencyKey, $request, $digest, $caller): array {
                $asset = $this->catalogDateAsset($assetUuid, $captureDate, true);
                $this->assertNoExactCaptureDateHold($assetUuid);
                $path = $this->exactCaptureDateLocalPath($asset);
                if (hash_file('sha256', $path) !== $expectedSourceHash || $asset->content_hash !== $expectedSourceHash) {
                    throw new RuntimeException('Current source differs from approved preimage.');
                }
                $existing = DB::table('review_desk_receipts')->where('reference', $assetUuid)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    $payload = json_decode($existing->payload, true, flags: JSON_THROW_ON_ERROR);
                    if ($existing->action !== 'set_exact_catalog_date' || $existing->request_digest !== $digest
                        || $existing->state !== 'completed' || ($payload['catalog_after'] ?? null) !== $this->catalogDatePreimage($asset)) {
                        throw new RuntimeException('Catalog date receipt conflicts or current catalog changed.');
                    }

                    return $payload['result'] + ['replayed' => true];
                }
                if (DB::table('review_desk_receipts')->where('reference', $assetUuid)->where('action', 'set_exact_catalog_date')->whereIn('state', ['processing', 'unknown'])->exists()) {
                    throw new RuntimeException('An earlier catalog date decision requires reconciliation.');
                }
                $before = $this->catalogDatePreimage($asset);
                if ($this->exactDateDigest($before) !== $expectedCatalogPreimageHash) {
                    throw new RuntimeException('Catalog date preimage changed after approval.');
                }
                $reason = 'Operator confirmed original date '.$captureDate.'; capture time unknown. Catalog 00:00:00 is a storage placeholder, not evidence of midnight. Prior catalog retained in approval receipt.';
                $decisionId = DB::table('file_date_review_decisions')->insertGetId([
                    'file_registry_id' => $asset->id, 'decision' => 'approved', 'proposed_date' => $captureDate.' 00:00:00',
                    'proposed_precision' => 'exact', 'ai_confidence' => $asset->date_taken_confidence,
                    'ai_source' => $asset->date_taken_source, 'ai_reasoning' => $asset->date_taken_reasoning,
                    'previous_exif_written' => $asset->exif_written, 'reason_code' => null, 'reviewer_notes' => $reason,
                    'metadata_file_written' => false, 'decision_scope' => 'catalog_date_only',
                    'authorization_receipt_id' => $idempotencyKey, 'authorized_source_sha256' => $expectedSourceHash,
                    'authorized_asset_uuid' => $assetUuid, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('file_registry')->where('id', $asset->id)->where('asset_uuid', $assetUuid)->where('status', 'active')->update([
                    'date_taken' => $captureDate.' 00:00:00', 'date_taken_precision' => 'exact', 'date_taken_source' => 'user_manual',
                    'date_taken_confidence' => 1.0, 'date_taken_reasoning' => $reason, 'date_needs_review' => 0,
                    'exif_written' => 0, 'updated_at' => now(),
                ]);
                $after = $this->catalogDatePreimage($this->catalogDateAsset($assetUuid, $captureDate, true));
                if (hash_file('sha256', $path) !== $expectedSourceHash) {
                    throw new RuntimeException('Source changed during catalog date decision.');
                }
                $result = ['success' => true, 'status' => 'completed', 'dry_run' => false, 'receipt_id' => $idempotencyKey,
                    'decision_id' => $decisionId, 'capture_date' => $captureDate, 'capture_time_known' => false,
                    'metadata_file_written' => false, 'identity_approval' => false, 'source_sha256' => $expectedSourceHash];
                DB::table('review_desk_receipts')->insert(['id' => $idempotencyKey, 'reference' => $assetUuid,
                    'idempotency_key' => $idempotencyKey, 'request_digest' => $digest, 'action' => 'set_exact_catalog_date', 'state' => 'completed',
                    'payload' => json_encode(['schema' => 'plos.exact_catalog_date.v1', 'request' => $request, 'trusted_caller' => $caller,
                        'catalog_before' => $before, 'catalog_after' => $after, 'result' => $result], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now()]);

                return $result;
            });
        } catch (Throwable $error) {
            return ['success' => false, 'status' => 'held', 'dry_run' => $dryRun, 'error' => $error->getMessage(),
                'metadata_file_written' => false, 'identity_approval' => false];
        }
    }

    private function catalogDateAsset(string $assetUuid, string $captureDate, bool $lock = false): object
    {
        if (! Str::isUuid($assetUuid) || strtolower($assetUuid) !== $assetUuid || $this->dateOnly($captureDate) !== $captureDate || strlen($captureDate) !== 10) {
            throw new RuntimeException('One exact asset and ISO original date are required.');
        }
        $query = DB::table('file_registry')->where('asset_uuid', $assetUuid)->where('status', 'active');
        if ($lock) {
            $query->lockForUpdate();
        }
        $asset = $query->first();
        if (! $asset || ! in_array($asset->mime_type, ['image/jpeg', 'image/png'], true)) {
            throw new RuntimeException('Current photo asset is unavailable.');
        }

        return $asset;
    }

    private function catalogDatePreimage(object $asset): array
    {
        $fields = ['id', 'asset_uuid', 'current_path', 'status', 'content_hash', 'date_taken', 'date_taken_precision',
            'date_taken_source', 'date_taken_confidence', 'date_taken_reasoning', 'date_needs_review', 'is_scan', 'scan_date', 'exif_written'];

        return array_intersect_key((array) $asset, array_flip($fields));
    }

    /** Recover only tags proven by one earlier completed exact-date transition. */
    private function verifiedDateRestoreTarget(object $asset, string $receiptId, string $path, array $currentEvidence): array
    {
        if (! Str::isUuid($receiptId) || strtolower($receiptId) !== $receiptId
            || ! hash_equals((string) ($asset->content_hash ?? ''), (string) ($currentEvidence['source_sha256'] ?? ''))) {
            throw new RuntimeException('Current source or historical receipt binding is invalid.');
        }
        $receipt = DB::table('review_desk_receipts')->where('id', $receiptId)->first();
        $payload = $receipt ? json_decode((string) $receipt->payload, true, flags: JSON_THROW_ON_ERROR) : null;
        $request = $payload['request'] ?? null;
        $result = $payload['result'] ?? null;
        $writer = $payload['writer_result'] ?? null;
        $decisionId = (int) ($payload['decision_id'] ?? 0);
        $decision = $decisionId > 0 ? DB::table('file_date_review_decisions')->where('id', $decisionId)->first() : null;
        if (! $receipt || $receipt->action !== self::EXACT_CAPTURE_ACTION || $receipt->state !== 'completed'
            || $receipt->reference !== $asset->asset_uuid || $receipt->idempotency_key !== $receiptId
            || ! is_array($request) || ! is_array($result) || ! is_array($writer)
            || ($payload['schema'] ?? null) !== 'plos.exact_capture_date_write.v1'
            || ($payload['effect_status'] ?? null) !== 'completed'
            || ($request['asset_uuid'] ?? null) !== $asset->asset_uuid
            || (int) ($request['file_registry_id'] ?? 0) !== (int) $asset->id
            || ($request['idempotency_key'] ?? null) !== $receiptId
            || ($request['capture_date'] ?? null) !== $this->dateOnly($asset->date_taken)
            || ($request['precision'] ?? null) !== 'exact'
            || ! is_string($request['source_sha256'] ?? null) || ! is_string($request['date_tags_sha256'] ?? null)
            || ! is_string($writer['metadata_transition_receipt'] ?? null)
            || ($writer['success'] ?? null) !== true || ($writer['source_replaced'] ?? false) === true
            || ($result['metadata_transition_receipt'] ?? null) !== $writer['metadata_transition_receipt']
            || ($result['metadata_file_written'] ?? null) !== true
            || ! is_string($result['written_sha256'] ?? null) || ! is_string($result['date_tags_sha256'] ?? null)
            || ! $decision || $decision->decision !== 'approved' || $decision->decision_scope !== self::EXACT_CAPTURE_ACTION
            || (int) $decision->file_registry_id !== (int) $asset->id || (int) $decision->metadata_file_written !== 1
            || $decision->authorization_receipt_id !== $receiptId || $decision->authorized_asset_uuid !== $asset->asset_uuid
            || $decision->authorized_source_sha256 !== $request['source_sha256']
            || $this->dateOnly($decision->proposed_date) !== $request['capture_date']
            || $receipt->request_digest !== $this->exactDateDigest($request)) {
            throw new RuntimeException('Historical date receipt is not an exact completed physical approval.');
        }

        $transition = app(ExifWritebackService::class)->readVerifiedDateTagTransition(
            $writer['metadata_transition_receipt'], (int) $asset->id, (string) $asset->asset_uuid,
            (string) $asset->current_path, $request['source_sha256'], $result['written_sha256'],
            $request['date_tags_sha256'], $result['date_tags_sha256'], $path,
            (string) $currentEvidence['source_sha256']
        );
        if (! ($transition['success'] ?? false) || ! is_array($transition['date_tags'] ?? null)
            || ! is_array($currentEvidence['date_tags'] ?? null)) {
            throw new RuntimeException('Historical date transition or current source chain is unavailable.');
        }
        $sourceChain = $this->assertExactDateSourceAncestry(
            $asset, $result['written_sha256'], $currentEvidence['source_sha256']
        );
        $historicalTags = $transition['date_tags'];
        $currentTags = $currentEvidence['date_tags'];
        foreach ($historicalTags as $tag => $value) {
            if (! is_string($tag) || ! array_key_exists($tag, $currentTags)
                || ($value !== null && (! is_string($value) || trim($value) === ''))) {
                throw new RuntimeException('Historical date-tag map is malformed or unsupported.');
            }
        }
        // Newer tag names absent from the historical packet were never proved
        // absent; preserve their current exact values rather than clearing them.
        $targetTags = $currentTags;
        foreach ($historicalTags as $tag => $value) {
            $targetTags[$tag] = $value;
        }
        ksort($targetTags);
        if (($targetTags['XMP-dc:Source'] ?? null) !== 'plos:operator-confirmed-date-only'
            || ($targetTags['XMP-photoshop:DateCreated'] ?? null) !== str_replace('-', ':', $request['capture_date'])
            || ($targetTags['IPTC:DateCreated'] ?? null) !== str_replace('-', ':', $request['capture_date'])
            || ($targetTags['ExifIFD:DateTimeOriginal'] ?? null) !== null) {
            throw new RuntimeException('Historical tags do not prove the current date-only catalog correction.');
        }

        return ['receipt_id' => $receiptId, 'transition_path' => $writer['metadata_transition_receipt'],
            'historical_source_sha256' => $result['written_sha256'], 'historical_tags_sha256' => $result['date_tags_sha256'],
            'source_chain_receipt_ids' => $sourceChain['receipt_ids'], 'source_chain_sha256' => $sourceChain['sha256'],
            'restored_source_sha256' => $request['source_sha256'],
            'target_tags' => $targetTags, 'target_tags_sha256' => $this->exactDateDigest($targetTags)];
    }

    /** Require a bounded, receipt-backed chain from the historical bytes to current bytes. */
    private function assertExactDateSourceAncestry(
        object $asset,
        string $historicalAfterHash,
        string $currentHash,
    ): array {
        if (hash_equals($historicalAfterHash, $currentHash)) {
            return ['receipt_ids' => [], 'sha256' => $this->exactDateDigest([])];
        }
        $maxHops = max(1, min(256, (int) config('metadata_writeback.max_face_transition_hops', 64)));
        $faces = DB::table('file_registry_faces')->where('file_registry_id', $asset->id)
            ->where('source_current', 1)->whereNull('superseded_at')->where('source_sha256', $currentHash)
            ->whereNotNull('global_identity_uuid')->whereNotNull('global_identity_approval_receipt_id')
            ->orderBy('id')->limit($maxHops + 1)->get();
        if ($faces->count() > $maxHops) {
            throw new RuntimeException('Current source ancestry has too many approved face records.');
        }
        $verifiedChains = [];
        foreach ($faces as $face) {
            $approvedFace = app(GlobalFaceWritebackApprovalService::class)->approvedFace((int) $face->id);
            if ($approvedFace === null || $approvedFace->source_sha256 !== $currentHash
                || $approvedFace->current_path !== $asset->current_path) {
                continue;
            }
            $approvalId = (string) $face->global_identity_approval_receipt_id;
            $approval = DB::table('review_desk_receipts')->where('id', $approvalId)->first();
            $approvalPayload = $approval ? json_decode((string) $approval->payload, true, flags: JSON_THROW_ON_ERROR) : null;
            $approvalSourceHash = $approvalPayload['global_identity_decision']['source_sha256'] ?? null;
            if (! $approval || $approval->state !== 'completed'
                || ! is_string($approvalSourceHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $approvalSourceHash)) {
                continue;
            }
            $transitions = DB::table('review_desk_receipts')->where('reference', $approval->reference)
                ->where('action', GlobalFaceWritebackApprovalService::TRANSITION_ACTION)->where('state', 'completed')
                ->orderBy('created_at')->orderBy('id')->limit($maxHops + 1)->get();
            if ($transitions->count() > $maxHops) {
                continue;
            }
            $edges = [];
            foreach ($transitions as $row) {
                $proof = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);
                if (($proof['schema'] ?? null) !== 'plos.global_face_metadata_transition.v1'
                    || ($proof['kind'] ?? null) !== 'verified_metadata_only'
                    || ($proof['actor'] ?? null) !== 'system/verified-metadata'
                    || ($proof['success'] ?? null) !== true || ($proof['decision_recorded'] ?? null) !== false
                    || ($proof['identity_decision_changed'] ?? null) !== false
                    || ($proof['face_id'] ?? null) !== (int) $face->id
                    || ($proof['file_registry_id'] ?? null) !== (int) $asset->id
                    || ($proof['identity_uuid'] ?? null) !== $face->global_identity_uuid
                    || ($proof['approval_receipt_id'] ?? null) !== $approvalId
                    || ($proof['source_path'] ?? null) !== $asset->current_path
                    || ! preg_match('/\A[a-f0-9]{64}\z/', (string) ($proof['before_sha256'] ?? ''))
                    || ! preg_match('/\A[a-f0-9]{64}\z/', (string) ($proof['after_sha256'] ?? ''))
                    || $proof['before_sha256'] === $proof['after_sha256']
                    || ! hash_equals($row->request_digest, app(GlobalFaceWritebackApprovalService::class)->digest($proof))) {
                    throw new RuntimeException('A metadata source transition has malformed decision history.');
                }
                $edges[] = ['receipt_id' => $row->id, 'before' => $proof['before_sha256'],
                    'after' => $proof['after_sha256'], 'payload' => $proof];
            }
            $cursor = $approvalSourceHash;
            $visited = [$cursor => true];
            $chainIds = [];
            $packetChain = [];
            $historicalHashIsAncestor = hash_equals($cursor, $historicalAfterHash);
            for ($step = 0; $step < $maxHops && ! hash_equals($cursor, $currentHash); $step++) {
                $next = array_values(array_filter($edges, static fn (array $edge): bool => $edge['before'] === $cursor));
                if (count($next) !== 1) {
                    break;
                }
                $edge = $next[0];
                $chainIds[] = $edge['receipt_id'];
                $packetPath = $edge['payload']['packet_path'] ?? null;
                $packetHash = $edge['payload']['packet_sha256'] ?? null;
                if (! is_string($packetPath) || ! preg_match('/\A[a-f0-9]{64}\z/', (string) $packetHash)) {
                    throw new RuntimeException('Metadata transition packet binding is malformed.');
                }
                $packetChain[] = [$edge['before'], $edge['after'], $packetPath, $packetHash];
                $cursor = $edge['after'];
                $historicalHashIsAncestor = $historicalHashIsAncestor || hash_equals($cursor, $historicalAfterHash);
                if (isset($visited[$cursor])) {
                    break;
                }
                $visited[$cursor] = true;
            }
            if (hash_equals($cursor, $currentHash) && $historicalHashIsAncestor) {
                $verifiedChains[] = ['receipt_ids' => $chainIds, 'packet_chain' => $packetChain,
                    'sha256' => $this->exactDateDigest($packetChain)];
            }
        }
        $unique = [];
        foreach ($verifiedChains as $chain) {
            $unique[$chain['sha256']] = $chain;
        }
        if (count($unique) !== 1) {
            throw new RuntimeException('Exact date source ancestry is missing or ambiguous.');
        }

        return array_values($unique)[0];
    }

    /** Read-only plan for one manually curated exact date-only catalog value. */
    public function planExactCaptureDateCorrection(string $assetUuid, ?string $restoreFromReceiptId = null): array
    {
        try {
            $asset = $this->exactCaptureDateAsset($assetUuid, allowWritten: $restoreFromReceiptId !== null);
            $path = $this->exactCaptureDateLocalPath($asset);
            $evidence = app(ExifWritebackService::class)->readExactCaptureDateEvidence($path);
            if (! ($evidence['success'] ?? false) || ! hash_equals((string) ($asset->content_hash ?? ''), (string) ($evidence['source_sha256'] ?? ''))) {
                throw new RuntimeException('Current source or date-tag evidence is unavailable.');
            }
            $restoreTarget = $restoreFromReceiptId === null ? null
                : $this->verifiedDateRestoreTarget($asset, $restoreFromReceiptId, $path, $evidence);
            $this->assertNoExactCaptureDateHold($assetUuid, $restoreTarget);

            $result = ['success' => true, 'status' => 'ready', 'dry_run' => true,
                'asset_uuid' => $asset->asset_uuid, 'file_registry_id' => (int) $asset->id,
                'capture_date' => $this->dateOnly($asset->date_taken), 'precision' => 'exact', 'is_scan' => (int) $asset->is_scan,
                'source_sha256' => $evidence['source_sha256'], 'date_tags_sha256' => $evidence['date_tags_sha256'],
                'date_tags' => $evidence['date_tags'], 'scan_date' => $asset->scan_date,
                'registry_file_size' => (int) ($asset->file_size ?? 0), 'physical_file_size' => filesize($path),
                'identity_approval' => false];
            if ($restoreTarget !== null) {
                $result['restoration'] = $restoreTarget;
            }

            return $result;
        } catch (Throwable $error) {
            return ['success' => false, 'status' => 'held', 'dry_run' => true,
                'error' => $this->safeExactDateError($error)];
        }
    }

    /** Dry-run by default; applying requires the registered worker's exact approval context. */
    public function writeExactCaptureDateCorrection(
        string $assetUuid,
        string $expectedSourceHash,
        string $expectedDateTagsHash,
        string $idempotencyKey,
        bool $dryRun = true,
        ?string $restoreFromReceiptId = null,
    ): array {
        if (! Str::isUuid($assetUuid) || strtolower($assetUuid) !== $assetUuid
            || ! preg_match('/\A[a-f0-9]{64}\z/', $expectedSourceHash)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $expectedDateTagsHash)
            || ! Str::isUuid($idempotencyKey) || strtolower($idempotencyKey) !== $idempotencyKey) {
            return ['success' => false, 'status' => 'held', 'dry_run' => $dryRun, 'error' => 'Exact asset, source, tag preimage, and receipt selectors are required.'];
        }

        if ($restoreFromReceiptId !== null && (! Str::isUuid($restoreFromReceiptId) || strtolower($restoreFromReceiptId) !== $restoreFromReceiptId)) {
            return ['success' => false, 'status' => 'held', 'dry_run' => $dryRun, 'error' => 'Verified restoration receipt selector is invalid.'];
        }
        $plan = $this->planExactCaptureDateCorrection($assetUuid, $restoreFromReceiptId);
        if (! ($plan['success'] ?? false)
            || ! hash_equals($expectedSourceHash, (string) ($plan['source_sha256'] ?? ''))
            || ! hash_equals($expectedDateTagsHash, (string) ($plan['date_tags_sha256'] ?? ''))) {
            return ['success' => false, 'status' => 'held', 'dry_run' => $dryRun,
                'error' => 'Current asset, source bytes, or raw date tags differ from the plan.'];
        }
        if ($dryRun) {
            $preview = ['success' => true, 'status' => 'preview', 'dry_run' => true,
                'asset_uuid' => $assetUuid, 'file_registry_id' => $plan['file_registry_id'],
                'capture_date' => $plan['capture_date'], 'precision' => 'exact', 'is_scan' => $plan['is_scan'], 'scan_date' => $plan['scan_date'],
                'source_sha256' => $expectedSourceHash, 'date_tags_sha256' => $expectedDateTagsHash,
                'preserved_digitization_tags' => array_intersect_key($plan['date_tags'], array_flip([
                    'ExifIFD:CreateDate', 'XMP-xmp:CreateDate', 'IPTC:DigitalCreationDate', 'IPTC:DigitalCreationTime',
                ])), 'clear_capture_time' => true, 'metadata_file_written' => false,
                'identity_approval' => false];
            if ($restoreFromReceiptId !== null) {
                $preview['restoration'] = $plan['restoration'];
            }

            return $preview;
        }

        try {
            $caller = $this->exactDateTrustedCaller();
        } catch (Throwable) {
            return ['success' => false, 'status' => 'held', 'dry_run' => false,
                'error' => 'Trusted registered CLI approval is required.', 'metadata_file_written' => false,
                'identity_approval' => false];
        }
        $asset = $this->exactCaptureDateAsset($assetUuid, allowWritten: $restoreFromReceiptId !== null);
        $date = (string) $plan['capture_date'];
        $request = ['asset_uuid' => $assetUuid, 'file_registry_id' => (int) $asset->id,
            'registry_path_sha256' => hash('sha256', (string) $asset->current_path),
            'source_sha256' => $expectedSourceHash, 'date_tags_sha256' => $expectedDateTagsHash,
            'capture_date' => $date, 'precision' => 'exact', 'is_scan' => (int) $asset->is_scan, 'scan_date' => $asset->scan_date,
            'idempotency_key' => $idempotencyKey];
        if ($restoreFromReceiptId !== null) {
            $request['restore_from_receipt_id'] = $restoreFromReceiptId;
            $request['restore_target_tags_sha256'] = $plan['restoration']['target_tags_sha256'];
            $request['restore_source_chain_sha256'] = $plan['restoration']['source_chain_sha256'];
            $request['restore_original_source_sha256'] = $plan['restoration']['restored_source_sha256'];
        }
        $digest = $this->exactDateDigest($request);
        $claim = $this->claimExactCaptureDateWrite($asset, $request, $digest, $caller);
        if (isset($claim['replay'])) {
            return $claim['replay'];
        }
        if (isset($claim['error'])) {
            return ['success' => false, 'status' => 'held', 'dry_run' => false, 'error' => $claim['error']];
        }

        $dispatchAttempted = false;
        $writerResult = null;
        try {
            $lockedAsset = $this->exactCaptureDateAsset($assetUuid, true, $restoreFromReceiptId !== null);
            if ((int) $lockedAsset->id !== (int) $asset->id || $lockedAsset->current_path !== $asset->current_path
                || $this->dateOnly($lockedAsset->date_taken) !== $date) {
                throw new RuntimeException('Current catalog asset or date changed after operator approval.');
            }
            $path = $this->exactCaptureDateLocalPath($lockedAsset);
            $evidence = app(ExifWritebackService::class)->readExactCaptureDateEvidence($path, $expectedSourceHash);
            if (! ($evidence['success'] ?? false) || ! hash_equals($expectedDateTagsHash, (string) ($evidence['date_tags_sha256'] ?? ''))) {
                throw new RuntimeException('Current source or date-tag preimage changed after operator approval.');
            }
            $freshRestore = $restoreFromReceiptId === null ? null
                : $this->verifiedDateRestoreTarget($lockedAsset, $restoreFromReceiptId, $path, $evidence);
            if (($freshRestore['target_tags_sha256'] ?? null) !== ($request['restore_target_tags_sha256'] ?? null)
                || ($freshRestore['restored_source_sha256'] ?? null) !== ($request['restore_original_source_sha256'] ?? null)) {
                throw new RuntimeException('Verified historical date target changed after approval.');
            }
            if (($freshRestore['source_chain_sha256'] ?? null) !== ($request['restore_source_chain_sha256'] ?? null)) {
                throw new RuntimeException('Verified historical source chain changed after approval.');
            }
            $beforeCopyBack = function ($connection) use ($lockedAsset, $request, $digest, $caller, $claim, $path, $evidence): void {
                $this->assertExactCaptureDateCopyAuthority($connection, $lockedAsset, $request, $digest, $caller, $claim['receipt_id'], $claim['decision_id']);
                if (($request['restore_from_receipt_id'] ?? null) !== null) {
                    $restoreProof = $this->verifiedDateRestoreTarget($lockedAsset, $request['restore_from_receipt_id'], $path, $evidence);
                    if (($restoreProof['target_tags_sha256'] ?? null) !== $request['restore_target_tags_sha256']
                        || ($restoreProof['source_chain_sha256'] ?? null) !== $request['restore_source_chain_sha256']
                        || ($restoreProof['restored_source_sha256'] ?? null) !== $request['restore_original_source_sha256']) {
                        throw new RuntimeException('Verified historical source chain changed before copy-back.');
                    }
                }
            };
            $dispatchAttempted = true;
            $write = app(ExifWritebackService::class)->writeExactCaptureDateMetadata(
                $path, $date, $expectedSourceHash, $expectedDateTagsHash, $beforeCopyBack,
                $freshRestore['target_tags'] ?? null
            );
            $writerResult = $write;
            $unknown = $this->exactCaptureDateOutcomeUnknown($write);
            if (($write['success'] ?? false) !== true || $unknown) {
                $state = $unknown ? 'unknown' : 'failed';
                $this->finishExactCaptureDateWrite($idempotencyKey, $digest, $state, $write, $asset, $claim['decision_id'], $caller, restoreFromReceiptId: $restoreFromReceiptId);

                return $this->presentExactCaptureDateWrite($asset, $date, $write, $idempotencyKey, $state);
            }

            $writtenHash = $write['written_sha256'] ?? null;
            if (! is_string($writtenHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $writtenHash)) {
                throw new RuntimeException('Verified metadata write receipt is incomplete.');
            }
            $after = app(ExifWritebackService::class)->readExactCaptureDateEvidence($path, $writtenHash);
            if (! ($after['success'] ?? false)
                || ! hash_equals((string) ($write['date_metadata']['after_tags_sha256'] ?? ''), (string) ($after['date_tags_sha256'] ?? ''))) {
                throw new RuntimeException('Current written date metadata does not match the verified temporary output.');
            }

            $result = ['success' => true, 'status' => 'completed', 'dry_run' => false,
                'asset_uuid' => $asset->asset_uuid, 'file_registry_id' => (int) $asset->id,
                'capture_date' => $date, 'precision' => 'exact', 'source_sha256' => $expectedSourceHash,
                'written_sha256' => $writtenHash, 'date_tags_sha256' => $after['date_tags_sha256'],
                'metadata_file_written' => empty($write['unchanged']),
                'metadata_transition_receipt' => $write['metadata_transition_receipt'] ?? null,
                'backup_sha256' => $write['backup_sha256'] ?? $expectedSourceHash,
                'faces_advanced' => (int) ($write['faces_advanced'] ?? 0),
                'vectors_advanced' => (int) ($write['vectors_advanced'] ?? 0),
                'identity_approval' => false];
            if ($restoreFromReceiptId !== null) {
                $result['restored_from_receipt_id'] = $restoreFromReceiptId;
            }
            $this->finishExactCaptureDateWrite($idempotencyKey, $digest, 'completed', $write + $result, $asset, $claim['decision_id'], $caller, $result, $restoreFromReceiptId);

            return $result + ['idempotency_key' => $idempotencyKey];
        } catch (Throwable $error) {
            $state = $dispatchAttempted ? 'unknown' : 'failed';
            $this->finishExactCaptureDateError($idempotencyKey, $digest, $state, $error, $claim['decision_id'], $writerResult);
            $metadataFileWritten = is_array($writerResult)
                && (($writerResult['source_replaced'] ?? false) === true
                    || (($writerResult['success'] ?? false) === true && empty($writerResult['unchanged'])));

            return ['success' => false, 'status' => $state, 'dry_run' => false,
                'asset_uuid' => $assetUuid, 'idempotency_key' => $idempotencyKey,
                'metadata_file_written' => $metadataFileWritten,
                'physical_write_outcome_unknown' => $state === 'unknown',
                'failure_code' => $writerResult['failure_code'] ?? null,
                'error' => $this->safeExactDateError($error),
                'identity_approval' => false];
        }
    }

    /** @return array<string,mixed> */
    public function approve(int $id, ?string $notes = null, array $meta = []): array
    {
        $scope = $meta['decision_scope'] ?? null;
        if (! in_array($scope, ['catalog_date_only', 'write_scan_estimated_year'], true)) {
            return $this->error(
                'Choose catalog-only acceptance or the separately authorized scanned-photo year write.',
                'decision_scope_required'
            );
        }

        $result = DB::transaction(function () use ($id, $notes, $meta, $scope): array {
            $row = $this->pendingEstimate($id, true);
            if ($row === null) {
                return $this->error('Pending scan-date suggestion was not found or its file metadata was already written.', 'not_pending');
            }

            $binding = null;
            if ($scope === 'write_scan_estimated_year') {
                $binding = $this->authorizePhysicalWrite($id, $row, $meta);
                if (isset($binding['error'])) {
                    return $binding['error'];
                }
                if (($row->date_taken_precision ?? 'year') !== 'year') {
                    return $this->error('Physical metadata approval is available only for a single estimated year; ranges remain catalog-only.', 'year_precision_required');
                }
            }

            $updated = DB::table('file_registry')
                ->where('id', $id)
                ->where('status', 'active')
                ->where('is_scan', 1)
                ->where('date_needs_review', 1)
                ->whereIn('date_taken_source', ['ai_visual_high', 'ai_visual_medium', 'ai_visual_low'])
                ->whereRaw('COALESCE(exif_written, 0) <> 1')
                ->update([
                    'date_taken_source' => 'user_manual',
                    'date_taken_precision' => $row->date_taken_precision ?? 'year',
                    'date_taken_confidence' => 1.0,
                    'date_needs_review' => 0,
                    'exif_written' => 0,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                return $this->error('Scan-date suggestion changed before approval completed.', 'concurrent_change');
            }

            $decisionId = $this->recordDecision($row, 'approved', null, $notes, [
                'decision_scope' => $scope,
                'authorization_receipt_id' => $binding['receipt_id'] ?? null,
                'authorized_source_sha256' => $binding['source_sha256'] ?? null,
                'authorized_asset_uuid' => $binding['asset_uuid'] ?? null,
            ]);
            Log::info('Scan-date estimate accepted', [
                'file_registry_id' => $id,
                'decision_scope' => $scope,
                'precision' => $row->date_taken_precision ?? 'year',
                'metadata_file_written' => false,
            ]);

            $isRange = ($row->date_taken_precision ?? 'year') === 'year_range';
            $writePhysical = $scope === 'write_scan_estimated_year';

            return [
                'success' => true,
                'message' => $isRange
                    ? 'Approximate year range accepted for the catalog. No single endpoint will be written as the photo date.'
                    : ($writePhysical
                        ? 'Approximate year accepted for the catalog and separately approved for scanned-photo metadata writeback.'
                        : 'Approximate year accepted for the catalog only. The image file was not changed.'),
                'review_state' => $isRange ? 'catalog_year_range_approved' : ($writePhysical ? 'catalog_year_approved_for_write' : 'catalog_year_approved'),
                'metadata_file_written' => false,
                'decision_id' => $decisionId,
                'write_binding' => $binding,
            ];
        });

        if (($result['success'] ?? false) && $scope === 'write_scan_estimated_year') {
            try {
                $result = $this->writeApprovedMetadata($id, $result);
            } catch (Throwable) {
                $result['metadata_file_written'] = false;
                $result['metadata_writeback_error'] = 'Physical write outcome requires reconciliation.';
                $result['physical_write_outcome_unknown'] = true;
                $result['message'] .= ' Physical write outcome requires reconciliation.';
            }
        }
        unset($result['write_binding']);

        return $result;
    }

    /** @return array<string,mixed> */
    public function reject(int $id, ?string $notes = null, array $meta = []): array
    {
        if (($meta['decision_scope'] ?? null) !== 'reject_scan_date_suggestion') {
            return $this->error('Open the scan-date detail before rejecting this suggestion.', 'decision_scope_required');
        }

        $reasonCode = strtolower(trim((string) ($meta['reason_code'] ?? '')));
        if (! in_array($reasonCode, self::REJECTION_REASONS, true)) {
            return $this->error('Choose a valid rejection reason before rejecting this suggestion.', 'reason_required');
        }

        return DB::transaction(function () use ($id, $notes, $reasonCode): array {
            $row = $this->pendingEstimate($id, true);
            if ($row === null) {
                return $this->error('Pending scan-date suggestion was not found or its file metadata was already written.', 'not_pending');
            }

            $updated = DB::table('file_registry')
                ->where('id', $id)
                ->where('status', 'active')
                ->where('is_scan', 1)
                ->where('date_needs_review', 1)
                ->whereIn('date_taken_source', ['ai_visual_high', 'ai_visual_medium', 'ai_visual_low'])
                ->whereRaw('COALESCE(exif_written, 0) <> 1')
                ->update([
                    'date_taken' => null,
                    'date_taken_precision' => null,
                    'date_taken_source' => 'scan_exif',
                    'date_taken_confidence' => null,
                    'date_taken_reasoning' => null,
                    'date_needs_review' => 0,
                    'exif_written' => 0,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                return $this->error('Scan-date suggestion changed before rejection completed.', 'concurrent_change');
            }

            $decisionId = $this->recordDecision($row, 'rejected', $reasonCode, $notes, [
                'decision_scope' => 'reject_scan_date_suggestion',
            ]);
            Log::info('Scan-date catalog suggestion rejected without file metadata write', [
                'file_registry_id' => $id,
                'reason_code' => $reasonCode,
                'metadata_file_written' => false,
            ]);

            return [
                'success' => true,
                'message' => 'Date suggestion rejected and removed from the catalog; the image file was not changed.',
                'review_state' => 'suggestion_rejected',
                'metadata_file_written' => false,
                'decision_id' => $decisionId,
            ];
        });
    }

    private function pendingEstimate(int $id, bool $lock): ?object
    {
        $query = DB::table('file_registry')
            ->where('id', $id)
            ->where('status', 'active')
            ->where('is_scan', 1)
            ->where('date_needs_review', 1)
            ->whereNotNull('date_taken')
            ->whereIn('date_taken_source', ['ai_visual_high', 'ai_visual_medium', 'ai_visual_low'])
            ->whereRaw('COALESCE(exif_written, 0) <> 1');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first([
            'id',
            'asset_uuid',
            'date_taken',
            'date_taken_precision',
            'date_taken_confidence',
            'date_taken_source',
            'date_taken_reasoning',
            'current_path',
            'scan_date',
            'exif_written',
        ]);
    }

    private function recordDecision(object $row, string $decision, ?string $reasonCode, ?string $notes, array $authorization = []): int
    {
        return (int) DB::table('file_date_review_decisions')->insertGetId([
            'file_registry_id' => $row->id,
            'decision' => $decision,
            'proposed_date' => $row->date_taken,
            'proposed_precision' => $row->date_taken_precision ?? 'year',
            'ai_confidence' => $row->date_taken_confidence,
            'ai_source' => $row->date_taken_source,
            'ai_reasoning' => $row->date_taken_reasoning,
            'previous_exif_written' => $row->exif_written,
            'reason_code' => $reasonCode,
            'reviewer_notes' => $this->safeNotes($notes),
            'metadata_file_written' => false,
            'decision_scope' => $authorization['decision_scope'] ?? null,
            'authorization_receipt_id' => $authorization['authorization_receipt_id'] ?? null,
            'authorized_source_sha256' => $authorization['authorized_source_sha256'] ?? null,
            'authorized_asset_uuid' => $authorization['authorized_asset_uuid'] ?? null,
            'metadata_writeback_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function safeNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        return $notes === '' ? null : mb_substr($notes, 0, 2000);
    }

    /** @param array<string,mixed> $result */
    private function writeApprovedMetadata(int $id, array $result, bool $recordWriteErrors = true): array
    {
        $row = DB::table('file_registry')->where('id', $id)->first([
            'date_taken',
            'date_taken_precision',
            'current_path',
            'scan_date',
        ]);
        $binding = $result['write_binding'] ?? [];
        $decisionId = (int) ($result['decision_id'] ?? 0);
        if ($row === null || ! $row->date_taken || ! $row->current_path || $decisionId < 1) {
            if ($recordWriteErrors) {
                $this->recordWriteError($decisionId, 'Current approved file metadata or path is unavailable.');
            }
            $result['message'] .= ' Metadata writeback failed because the current approved file metadata or path is unavailable.';
            $result['metadata_writeback_error'] = 'approved_file_metadata_unavailable';

            return $result;
        }

        if (($row->date_taken_precision ?? 'year') === 'year_range') {
            $result['message'] = 'Approximate year range accepted for the catalog. Physical metadata writeback was skipped because the range has no single photo date.';

            return $result;
        }

        if (($row->current_path ?? null) !== ($binding['source_path'] ?? null)
            || ($row->date_taken ?? null) !== ($binding['proposed_date'] ?? null)
            || ($row->scan_date ?? null) !== ($binding['scan_date'] ?? null)
            || ($row->date_taken_precision ?? 'year') !== ($binding['proposed_precision'] ?? null)) {
            if ($recordWriteErrors) {
                $this->recordWriteError($decisionId, 'Approved file path or date proposal changed before metadata write.');
            }
            $result['message'] .= ' Metadata writeback failed because the approved path or date proposal changed.';
            $result['metadata_writeback_error'] = 'approved_proposal_changed';

            return $result;
        }

        $localPath = app(NextcloudFileApiService::class)->localPath((string) $binding['source_path']);
        $root = realpath((string) config('services.nextcloud.data_path'));
        $path = $localPath ? realpath($localPath) : false;
        if (! $root || ! $path || ! is_file($path) || ! is_readable($path)
            || ! str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            $error = 'Approved source is unavailable as a readable local file inside the configured data root.';
            if ($recordWriteErrors) {
                $this->recordWriteError($decisionId, $error);
            }
            $result['message'] .= ' Metadata writeback failed because the approved source is unavailable locally.';
            $result['metadata_writeback_error'] = $error;

            return $result;
        }

        $write = app(ExifWritebackService::class)->writeScanEstimatedDate(
            $path,
            (string) $row->date_taken,
            $row->scan_date ? (string) $row->scan_date : null,
            'operator_review',
            1.0,
            (string) ($row->date_taken_precision ?? 'year'),
            true,
            (string) $binding['source_sha256']
        );
        if (! ($write['success'] ?? false)) {
            $error = (string) ($write['error'] ?? 'write failed');
            if ($recordWriteErrors) {
                $this->recordWriteError($decisionId, $error);
            }
            $result['message'] .= ' Metadata writeback failed: '.$error.'.';
            $result['metadata_writeback_error'] = $error;

            return $result;
        }

        $updates = ['exif_written' => 1, 'updated_at' => now()];
        if (Schema::hasColumn('file_registry', 'exif_date_written_at')) {
            $updates['exif_date_written_at'] = now();
        }
        $registryUpdated = DB::table('file_registry')->where('id', $id)
            ->where('status', 'active')->where('is_scan', 1)
            ->where('asset_uuid', $binding['asset_uuid'])
            ->where('current_path', $binding['source_path'])
            ->where('date_taken', $binding['proposed_date'])
            ->where('date_taken_precision', $binding['proposed_precision'])
            ->where('date_taken_source', 'user_manual')
            ->where('date_needs_review', 0)
            ->where('scan_date', $binding['scan_date'])
            ->update($updates);
        $writeError = $registryUpdated === 1
            ? null
            : 'Physical metadata write completed, but the registry changed before its write receipt could be updated.';
        DB::table('file_date_review_decisions')->where('id', $decisionId)->update([
            'metadata_file_written' => true, 'metadata_writeback_error' => $writeError, 'updated_at' => now(),
        ]);

        $result['message'] = $registryUpdated === 1
            ? 'Approximate year accepted and written to scanned-photo XMP metadata with year precision; the scanner date was preserved.'
            : $writeError;
        $result['metadata_file_written'] = true;
        $result['write_binding_conflict'] = $registryUpdated !== 1;
        if ($writeError !== null) {
            $result['metadata_writeback_error'] = $writeError;
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function retryApprovedWriteback(int $id, ?string $notes = null, array $meta = []): array
    {
        if (($meta['decision_scope'] ?? null) !== 'retry_original_authorized_scan_estimated_year') {
            return $this->error('Choose the exact original scan-date physical authorization retry scope.', 'decision_scope_required');
        }

        $binding = DB::transaction(function () use ($id, $meta): array {
            $retryReceiptId = $meta['receipt_id'] ?? null;
            $actor = $meta['actor'] ?? null;
            $dateDecisionId = filter_var($meta['date_decision_id'] ?? null, FILTER_VALIDATE_INT);
            $authorizationReceiptId = $meta['authorization_receipt_id'] ?? null;
            $retryReceipt = is_string($retryReceiptId)
                ? DB::table('review_desk_receipts')->where('id', $retryReceiptId)->lockForUpdate()->first()
                : null;
            $reference = $retryReceipt
                ? DB::table('review_desk_references')->where('reference', $retryReceipt->reference)->lockForUpdate()->first()
                : null;
            $retryPayload = $retryReceipt ? json_decode((string) $retryReceipt->payload, true) : null;
            $retryBinding = is_array($retryPayload) ? ($retryPayload['scan_date_write_retry'] ?? null) : null;
            $authorization = is_string($authorizationReceiptId)
                ? DB::table('review_desk_receipts')->where('id', $authorizationReceiptId)->lockForUpdate()->first()
                : null;
            $authorizationPayload = $authorization ? json_decode((string) $authorization->payload, true) : null;
            $writeBinding = is_array($authorizationPayload) ? ($authorizationPayload['scan_date_estimate_write'] ?? null) : null;
            $decision = is_int($dateDecisionId) && $dateDecisionId > 0
                ? DB::table('file_date_review_decisions')->where('id', $dateDecisionId)->lockForUpdate()->first()
                : null;
            $row = DB::table('file_registry')->where('id', $id)->lockForUpdate()->first();
            $policy = DB::table('review_desk_policy')->where('review_type', 'scan_date_estimate')
                ->where('action', 'retry_scan_year_write')->first();
            $policyConditions = $policy ? json_decode((string) ($policy->conditions ?? '{}'), true) : null;

            if (! $retryReceipt || $retryReceipt->state !== 'processing' || $retryReceipt->action !== 'retry_scan_year_write'
                || ($retryPayload['id'] ?? null) !== $retryReceipt->id
                || ($retryPayload['reference'] ?? null) !== $retryReceipt->reference
                || ! $reference || $reference->source !== 'scan_date_estimate' || $reference->review_type !== 'scan_date_estimate'
                || (string) $reference->source_id !== (string) $id || $reference->status !== 'history'
                || ! is_array($retryPayload) || ! is_array($retryBinding)
                || ($retryPayload['action'] ?? null) !== 'retry_scan_year_write'
                || ($retryPayload['decision_scope'] ?? null) !== 'retry_original_authorized_scan_estimated_year'
                || ! is_string($retryPayload['actor'] ?? null) || trim($retryPayload['actor']) === ''
                || ! is_string($actor) || ! hash_equals($retryPayload['actor'], $actor)
                || (int) ($retryBinding['date_decision_id'] ?? 0) !== $dateDecisionId
                || ($retryBinding['authorization_receipt_id'] ?? null) !== $authorizationReceiptId
                || ($retryBinding['asset_uuid'] ?? null) !== ($meta['asset_uuid'] ?? null)
                || ! is_string($retryBinding['source_sha256'] ?? null)
                || ! is_string($meta['source_sha256'] ?? null)
                || ! hash_equals($retryBinding['source_sha256'], $meta['source_sha256'])
                || ! $policy || ! $policy->enabled || ! $policy->sensitive || $policy->bulk_enabled
                || ($policyConditions['operator_only'] ?? false) !== true
                || ($policyConditions['original_authorization_required'] ?? false) !== true
                || $policy->service_class !== self::class || $policy->method !== 'retryApprovedWriteback') {
                return ['error' => $this->error('Exact individual retry authorization is unavailable.', 'retry_authorization_unavailable', 409)];
            }

            $sourceHash = $writeBinding['source_sha256'] ?? null;
            if (! is_string($sourceHash) || preg_match('/\A[a-f0-9]{64}\z/', $sourceHash) !== 1) {
                return ['error' => $this->error('Original source authorization is malformed.', 'retry_authorization_unavailable', 409)];
            }
            $attempts = DB::table('review_desk_receipts')->where('reference', $reference->reference)
                ->where('action', 'retry_scan_year_write')->where('id', '!=', $retryReceipt->id)
                ->get(['id', 'reference', 'state', 'payload']);
            foreach ($attempts as $attempt) {
                $attemptPayload = json_decode((string) $attempt->payload, true);
                $attemptBinding = is_array($attemptPayload) ? ($attemptPayload['scan_date_write_retry'] ?? null) : null;
                $knownFailed = $attempt->state === 'failed' && is_array($attemptPayload)
                    && ($attemptPayload['id'] ?? null) === $attempt->id
                    && ($attemptPayload['reference'] ?? null) === $attempt->reference
                    && ($attemptPayload['action'] ?? null) === 'retry_scan_year_write'
                    && ($attemptPayload['decision_scope'] ?? null) === 'retry_original_authorized_scan_estimated_year'
                    && ($attemptPayload['reference'] ?? null) === $reference->reference
                    && is_array($attemptBinding) && (int) ($attemptBinding['date_decision_id'] ?? 0) === $dateDecisionId
                    && ($attemptBinding['authorization_receipt_id'] ?? null) === $authorization->id
                    && ($attemptBinding['source_sha256'] ?? null) === $sourceHash
                    && ($attemptBinding['asset_uuid'] ?? null) === $writeBinding['asset_uuid']
                    && is_string($attemptPayload['error'] ?? null) && is_int($attemptPayload['http_status'] ?? null);
                $knownCompletedHold = $attempt->state === 'completed' && is_array($attemptPayload)
                    && ($attemptPayload['id'] ?? null) === $attempt->id
                    && ($attemptPayload['reference'] ?? null) === $attempt->reference
                    && ($attemptPayload['action'] ?? null) === 'retry_scan_year_write'
                    && ($attemptPayload['decision_scope'] ?? null) === 'retry_original_authorized_scan_estimated_year'
                    && ($attemptPayload['reference'] ?? null) === $reference->reference
                    && is_array($attemptBinding) && (int) ($attemptBinding['date_decision_id'] ?? 0) === $dateDecisionId
                    && ($attemptBinding['authorization_receipt_id'] ?? null) === $authorization->id
                    && ($attemptBinding['source_sha256'] ?? null) === $sourceHash
                    && ($attemptBinding['asset_uuid'] ?? null) === $writeBinding['asset_uuid']
                    && ($attemptPayload['success'] ?? false) === true
                    && ($attemptPayload['metadata_file_written'] ?? null) === false
                    && ($attemptPayload['effect_status'] ?? null) === 'retry_write_held'
                    && is_string($attemptPayload['metadata_writeback_error'] ?? null)
                    && trim($attemptPayload['metadata_writeback_error']) !== '';
                if (! is_array($attemptPayload) || in_array($attempt->state, ['processing', 'unknown'], true)
                    || ($attemptPayload['metadata_file_written'] ?? false) === true
                    || ! $knownFailed && ! $knownCompletedHold) {
                    return ['error' => $this->error('A prior retry is unresolved or already wrote physical metadata; reconcile it before another attempt.', 'retry_reconciliation_required', 409)];
                }
            }

            if (! $authorization || $authorization->reference !== $reference->reference || $authorization->state !== 'completed'
                || $authorization->action !== 'approve' || ! is_array($authorizationPayload) || ! is_array($writeBinding)
                || ($authorizationPayload['id'] ?? null) !== $authorization->id
                || ($authorizationPayload['reference'] ?? null) !== $reference->reference
                || ($authorizationPayload['action'] ?? null) !== 'approve'
                || ! is_string($authorizationPayload['actor'] ?? null) || trim($authorizationPayload['actor']) === ''
                || ($authorizationPayload['success'] ?? false) !== true || ($authorizationPayload['decision_recorded'] ?? false) !== true
                || ($authorizationPayload['decision_scope'] ?? null) !== 'write_scan_estimated_year'
                || ($authorizationPayload['effect_status'] ?? null) !== 'catalog_accepted_write_held'
                || ($authorizationPayload['metadata_file_written'] ?? null) !== false
                || ! is_string($authorizationPayload['metadata_writeback_error'] ?? null)
                || trim($authorizationPayload['metadata_writeback_error']) === ''
                || (int) ($authorizationPayload['date_decision_id'] ?? 0) !== $dateDecisionId
                || ! is_string($writeBinding['asset_uuid'] ?? null) || ! is_string($writeBinding['source_path'] ?? null)
                || (int) ($writeBinding['file_registry_id'] ?? 0) !== $id
                || $writeBinding['asset_uuid'] !== ($meta['asset_uuid'] ?? null)
                || $sourceHash !== $retryBinding['source_sha256']
                || ($writeBinding['proposed_precision'] ?? null) !== 'year'
                || ! $decision || $decision->decision !== 'approved'
                || ($decision->decision_scope ?? null) !== 'write_scan_estimated_year'
                || (int) $decision->file_registry_id !== $id
                || (string) ($decision->authorization_receipt_id ?? '') !== (string) $authorization->id
                || (string) ($decision->authorized_source_sha256 ?? '') !== $sourceHash
                || (string) ($decision->authorized_asset_uuid ?? '') !== $writeBinding['asset_uuid']
                || (int) ($decision->metadata_file_written ?? 0) === 1
                || trim((string) ($decision->metadata_writeback_error ?? '')) === ''
                || ! $row || $row->status !== 'active' || (int) $row->is_scan !== 1 || (int) $row->date_needs_review !== 0
                || $row->date_taken_source !== 'user_manual' || (int) ($row->exif_written ?? 0) === 1
                || $row->asset_uuid !== $writeBinding['asset_uuid'] || $row->current_path !== $writeBinding['source_path']
                || ($row->date_taken_precision ?? 'year') !== 'year'
                || ! $this->sameDate($row->date_taken, $writeBinding['proposed_date'] ?? null)
                || ! $this->sameDate($decision->proposed_date ?? null, $writeBinding['proposed_date'] ?? null)
                || ($decision->proposed_precision ?? 'year') !== 'year'
                || ! $this->sameNullableDate($row->scan_date, $writeBinding['scan_date'] ?? null)
                || DB::table('file_date_review_decisions')->where('file_registry_id', $id)->where('id', '>', $dateDecisionId)->exists()) {
                return ['error' => $this->error('Original approval, date proposal, or current source changed; fresh physical consent is required.', 'original_authorization_no_longer_applies', 409)];
            }

            return $writeBinding + ['decision_id' => $dateDecisionId, 'receipt_id' => $authorization->id];
        });

        if (isset($binding['error'])) {
            return $binding['error'];
        }

        $proof = app(FaceLinkBridgeService::class)->reviewSourcePath(
            (string) $binding['source_path'], (string) $meta['source_sha256'], true
        );
        if (! ($proof['success'] ?? false) || ! hash_equals((string) $binding['source_sha256'], (string) ($proof['source_sha256'] ?? ''))) {
            return $this->error('Current source bytes differ from the original physical authorization; fresh physical consent is required.', 'source_hash_changed', 409);
        }

        $result = ['success' => true, 'decision_id' => (int) $binding['decision_id'], 'write_binding' => $binding,
            'message' => 'Retrying the exact original source and year authorization.', 'metadata_file_written' => false];
        try {
            $result = $this->writeApprovedMetadata($id, $result, false);
        } catch (Throwable) {
            $result['metadata_file_written'] = false;
            $result['metadata_writeback_error'] = 'Physical write outcome requires reconciliation.';
            $result['physical_write_outcome_unknown'] = true;
            $result['message'] .= ' Physical write outcome requires reconciliation.';
        }
        $result['authorization_receipt_id'] = $binding['receipt_id'];
        $result['effect_status'] = ! empty($result['physical_write_outcome_unknown'])
            ? 'physical_write_outcome_unknown'
            : (! empty($result['metadata_file_written'])
            ? (! empty($result['metadata_writeback_error']) ? 'retry_written_registry_conflict' : 'retry_completed')
            : 'retry_write_held');

        return $result;
    }

    /** @return array<string,mixed> */
    private function authorizePhysicalWrite(int $id, object $row, array $meta): array
    {
        $receiptId = $meta['receipt_id'] ?? null;
        $actor = $meta['actor'] ?? null;
        $receipt = is_string($receiptId)
            ? DB::table('review_desk_receipts')->where('id', $receiptId)->lockForUpdate()->first()
            : null;
        $reference = $receipt
            ? DB::table('review_desk_references')->where('reference', $receipt->reference)->lockForUpdate()->first()
            : null;
        $payload = $receipt ? json_decode((string) $receipt->payload, true) : null;
        $binding = is_array($payload) ? ($payload['scan_date_estimate_write'] ?? null) : null;
        $policy = DB::table('review_desk_policy')->where('review_type', 'scan_date_estimate')->where('action', 'approve')->first();
        $conditions = $policy ? json_decode((string) ($policy->conditions ?? '{}'), true) : null;
        $bindingMeta = $meta['scan_date_estimate_write'] ?? null;

        if (! $receipt || $receipt->state !== 'processing' || $receipt->action !== 'approve'
            || ! $reference || $reference->source !== 'scan_date_estimate' || $reference->review_type !== 'scan_date_estimate'
            || (string) $reference->source_id !== (string) $id
            || ! is_array($payload) || ($payload['reference'] ?? null) !== $reference->reference
            || ($payload['decision_scope'] ?? null) !== 'write_scan_estimated_year'
            || ! is_string($payload['actor'] ?? null) || trim($payload['actor']) === ''
            || ! is_string($actor) || ! hash_equals($payload['actor'], $actor)
            || ! is_array($binding) || (is_array($bindingMeta) && $bindingMeta !== $binding)
            || (int) ($binding['file_registry_id'] ?? 0) !== $id
            || ($binding['asset_uuid'] ?? null) !== ($meta['asset_uuid'] ?? null)
            || ! is_string($binding['source_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $binding['source_sha256']) !== 1
            || ! is_string($meta['source_sha256'] ?? null)
            || ! hash_equals($binding['source_sha256'], $meta['source_sha256'])
            || ! is_string($binding['asset_uuid'] ?? null)
            || ! is_string($binding['source_path'] ?? null)
            || $binding['source_path'] !== $row->current_path
            || $binding['asset_uuid'] !== ($row->asset_uuid ?? null)
            || ! $this->sameDate($binding['proposed_date'] ?? null, $row->date_taken)
            || ($binding['proposed_precision'] ?? 'year') !== ($row->date_taken_precision ?? 'year')
            || ! $this->sameNullableDate($binding['scan_date'] ?? null, $row->scan_date ?? null)
            || ! $policy || ! $policy->enabled || ! $policy->sensitive || $policy->bulk_enabled
            || ($conditions['operator_only'] ?? false) !== true || ($conditions['current_source_required'] ?? false) !== true
            || $policy->service_class !== self::class || $policy->method !== 'approve') {
            return ['error' => $this->error('A matching individual Review Desk approval for this exact source and year is required before writing file metadata.', 'physical_write_authorization_required')];
        }

        return $binding + ['receipt_id' => $receipt->id];
    }

    private function sameDate(mixed $left, mixed $right): bool
    {
        if (! is_string($left) || ! is_string($right)) {
            return false;
        }
        $leftTime = strtotime($left);
        $rightTime = strtotime($right);

        return $leftTime !== false && $rightTime !== false
            && date('Y-m-d H:i:s', $leftTime) === date('Y-m-d H:i:s', $rightTime);
    }

    private function sameNullableDate(mixed $left, mixed $right): bool
    {
        if ($left === null || $left === '') {
            return $right === null || $right === '';
        }

        return $this->sameDate($left, $right);
    }

    private function recordWriteError(int $decisionId, string $error): void
    {
        if ($decisionId > 0) {
            DB::table('file_date_review_decisions')->where('id', $decisionId)->update([
                'metadata_writeback_error' => mb_substr($error, 0, 1000), 'updated_at' => now(),
            ]);
        }
    }

    private function exactCaptureDateAsset(string $assetUuid, bool $lock = false, bool $allowWritten = false): object
    {
        if (! Str::isUuid($assetUuid) || strtolower($assetUuid) !== $assetUuid) {
            throw new RuntimeException('Exact capture-date asset selector is invalid.');
        }
        $query = DB::table('file_registry')->where('asset_uuid', $assetUuid)->where('status', 'active');
        if ($lock) {
            $query->lockForUpdate();
        }
        $asset = $query->first(['id', 'asset_uuid', 'current_path', 'mime_type', 'status', 'is_scan', 'date_taken',
            'date_taken_precision', 'date_taken_source', 'date_taken_confidence', 'date_taken_reasoning',
            'date_needs_review', 'scan_date', 'exif_written', 'file_size', 'content_hash']);
        if ($asset === null || (int) $asset->date_needs_review !== 0
            || $asset->date_taken_source !== 'user_manual' || $asset->date_taken_precision !== 'exact'
            || ! is_string($asset->date_taken) || $this->dateOnly($asset->date_taken) === null
            || ! is_string($asset->current_path) || $asset->current_path === ''
            || ! is_string($asset->mime_type) || ! in_array(strtolower($asset->mime_type), ['image/jpeg', 'image/png'], true)
            || ((int) ($asset->exif_written ?? 0) === 1 && ! $allowWritten)) {
            throw new RuntimeException('Exact manually curated photo date is not currently eligible.');
        }

        return $asset;
    }

    private function exactCaptureDateLocalPath(object $asset): string
    {
        $mapped = app(NextcloudFileApiService::class)->localPath((string) $asset->current_path);
        $root = realpath((string) config('services.nextcloud.data_path'));
        if (! is_string($mapped) || $mapped === '' || is_link($mapped) || $root === false) {
            throw new RuntimeException('Current local source is unavailable.');
        }
        $path = realpath($mapped);
        if ($path === false || ! is_file($path) || ! is_readable($path)
            || ! str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Current source is outside the configured Nextcloud root.');
        }

        return $path;
    }

    private function dateOnly(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/\A(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}:\d{2}))?\z/', $value, $matches)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1]);
        if (! $date || $date->format('Y-m-d') !== $matches[1]
            || (isset($matches[2]) && $matches[2] !== '00:00:00')) {
            return null;
        }

        return $matches[1];
    }

    private function assertNoExactCaptureDateHold(string $assetUuid, ?array $selectedRestore = null): void
    {
        $holds = DB::table('review_desk_receipts')->where('action', self::EXACT_CAPTURE_ACTION)
            ->where('reference', $assetUuid)->whereIn('state', ['processing', 'unknown'])->get();
        foreach ($holds as $receipt) {
            if ($receipt->state === 'processing') {
                throw new RuntimeException('A prior exact capture-date write requires reconciliation.');
            }
            $writeback = app(ExifWritebackService::class);
            if ($writeback->isOriginalRestored($receipt)
                || app(ExactCaptureDateReconciliationService::class)->isOriginalCommitted($receipt)) {
                continue;
            }
            if ($selectedRestore !== null && is_string($selectedRestore['restored_source_sha256'] ?? null)
                && $writeback->isOriginalRestored($receipt, $selectedRestore['restored_source_sha256'])) {
                continue;
            }
            throw new RuntimeException('A prior exact capture-date write requires reconciliation.');
        }
    }

    private function exactDateTrustedCaller(): array
    {
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        $keys = ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256'];
        if (! is_array($caller) || count($caller) !== count($keys) || array_diff(array_keys($caller), $keys) !== []) {
            throw new RuntimeException('Trusted registered CLI approval is required.');
        }
        foreach ($keys as $key) {
            if (! is_string($caller[$key]) || $caller[$key] === '' || strlen($caller[$key]) > 255
                || preg_match('/[\x00-\x20\x7f]/', $caller[$key])
                || (str_ends_with($key, 'sha256') && ! preg_match('/\A[a-f0-9]{64}\z/', $caller[$key]))) {
                throw new RuntimeException('Trusted registered CLI approval is required.');
            }
        }

        return $caller;
    }

    private function exactDateDigest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function claimExactCaptureDateWrite(object $asset, array $request, string $digest, array $caller): array
    {
        return DB::transaction(function () use ($asset, $request, $digest, $caller): array {
            $locked = $this->exactCaptureDateAsset((string) $asset->asset_uuid, true, ($request['restore_from_receipt_id'] ?? null) !== null);
            if ((int) $locked->id !== (int) $asset->id || $locked->current_path !== $asset->current_path
                || ! hash_equals((string) $request['registry_path_sha256'], hash('sha256', (string) $locked->current_path))) {
                return ['error' => 'Current asset path changed after approval.'];
            }
            $key = (string) $request['idempotency_key'];
            $prior = DB::table('review_desk_receipts')->where('reference', $asset->asset_uuid)
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($prior !== null) {
                if ($prior->action !== self::EXACT_CAPTURE_ACTION || $prior->request_digest !== $digest) {
                    return ['error' => 'Receipt key is already bound to a different request.'];
                }
                if ($prior->state !== 'completed') {
                    return ['error' => 'Prior physical outcome is unresolved; reconcile before retrying.'];
                }
                $payload = json_decode((string) $prior->payload, true, flags: JSON_THROW_ON_ERROR);
                $result = $payload['result'] ?? null;
                $path = $this->exactCaptureDateLocalPath($locked);
                $proof = app(ExifWritebackService::class)->readExactCaptureDateEvidence($path, $result['written_sha256'] ?? null);
                if (! is_array($result) || ! ($proof['success'] ?? false)
                    || ! hash_equals((string) ($result['date_tags_sha256'] ?? ''), (string) ($proof['date_tags_sha256'] ?? ''))) {
                    return ['error' => 'Completed receipt no longer matches current source bytes and tags.'];
                }

                return ['replay' => $result + ['replayed' => true]];
            }
            $selectedRestore = null;
            $selectedRestoreId = $request['restore_from_receipt_id'] ?? null;
            if ($selectedRestoreId !== null) {
                $selectedReceipt = DB::table('review_desk_receipts')->where('id', $selectedRestoreId)
                    ->where('action', self::EXACT_CAPTURE_ACTION)->where('reference', $asset->asset_uuid)
                    ->where('state', 'completed')->lockForUpdate()->first();
                if ($selectedReceipt === null) {
                    return ['error' => 'Selected historical date receipt is unavailable.'];
                }
                $path = $this->exactCaptureDateLocalPath($locked);
                $currentEvidence = app(ExifWritebackService::class)->readExactCaptureDateEvidence($path, $request['source_sha256']);
                $selectedRestore = ($currentEvidence['success'] ?? false)
                    ? $this->verifiedDateRestoreTarget($locked, $selectedReceipt->id, $path, $currentEvidence) : null;
                if (($selectedRestore['target_tags_sha256'] ?? null) !== ($request['restore_target_tags_sha256'] ?? null)
                    || ($selectedRestore['source_chain_sha256'] ?? null) !== ($request['restore_source_chain_sha256'] ?? null)
                    || ($selectedRestore['restored_source_sha256'] ?? null) !== ($request['restore_original_source_sha256'] ?? null)) {
                    return ['error' => 'Selected historical date receipt or source proof changed.'];
                }
            }
            $earlier = DB::table('review_desk_receipts')->where('action', self::EXACT_CAPTURE_ACTION)
                ->where('reference', $asset->asset_uuid)->whereIn('state', ['processing', 'unknown', 'completed'])
                ->lockForUpdate()->get();
            foreach ($earlier as $receipt) {
                if ($receipt->state === 'completed' && $receipt->id === $selectedRestoreId) {
                    continue;
                }
                if ($receipt->state === 'unknown' && (app(ExifWritebackService::class)->isOriginalRestored($receipt)
                    || app(ExactCaptureDateReconciliationService::class)->isOriginalCommitted($receipt))) {
                    continue;
                }
                if ($receipt->state === 'unknown' && $selectedRestore !== null
                    && app(ExifWritebackService::class)->isOriginalRestored($receipt, $selectedRestore['restored_source_sha256'])) {
                    continue;
                }

                return ['error' => 'An earlier exact capture-date operation is held or already complete.'];
            }
            $path = $this->exactCaptureDateLocalPath($locked);
            $proof = app(ExifWritebackService::class)->readExactCaptureDateEvidence($path, $request['source_sha256']);
            if (! ($proof['success'] ?? false) || ! hash_equals($request['date_tags_sha256'], (string) ($proof['date_tags_sha256'] ?? ''))
                || $this->dateOnly($locked->date_taken) !== $request['capture_date']) {
                return ['error' => 'Current source or catalog date changed after approval.'];
            }
            $receiptId = $key;
            $decisionId = (int) DB::table('file_date_review_decisions')->insertGetId([
                'file_registry_id' => $locked->id, 'decision' => 'approved',
                'proposed_date' => $locked->date_taken, 'proposed_precision' => 'exact',
                'ai_confidence' => $locked->date_taken_confidence, 'ai_source' => 'user_manual',
                'ai_reasoning' => $locked->date_taken_reasoning, 'previous_exif_written' => $locked->exif_written,
                'reason_code' => null, 'reviewer_notes' => 'Operator-authorized exact date-only metadata correction.',
                'metadata_file_written' => false, 'decision_scope' => self::EXACT_CAPTURE_ACTION,
                'authorization_receipt_id' => $receiptId, 'authorized_source_sha256' => $request['source_sha256'],
                'authorized_asset_uuid' => $locked->asset_uuid, 'metadata_writeback_error' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('review_desk_receipts')->insert([
                'id' => $receiptId, 'reference' => $locked->asset_uuid, 'idempotency_key' => $key,
                'request_digest' => $digest, 'action' => self::EXACT_CAPTURE_ACTION, 'state' => 'processing',
                'payload' => json_encode(['schema' => 'plos.exact_capture_date_write.v1', 'effect_status' => 'not_started',
                    'request' => $request, 'trusted_caller' => $caller, 'decision_id' => $decisionId], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['receipt_id' => $receiptId, 'decision_id' => $decisionId];
        });
    }

    private function assertExactCaptureDateCopyAuthority($connection, object $asset, array $request, string $digest, array $caller, string $receiptId, int $decisionId): void
    {
        $lockClause = $connection->getDriverName() === 'sqlite' ? '' : ' FOR UPDATE';
        $row = $connection->select('SELECT * FROM file_registry WHERE id = ?'.$lockClause, [$asset->id])[0] ?? null;
        $receipt = $connection->select('SELECT * FROM review_desk_receipts WHERE id = ?'.$lockClause, [$receiptId])[0] ?? null;
        $decision = $connection->select('SELECT * FROM file_date_review_decisions WHERE id = ?'.$lockClause, [$decisionId])[0] ?? null;
        $payload = $receipt ? json_decode((string) $receipt->payload, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! $row || $row->asset_uuid !== $request['asset_uuid'] || $row->current_path !== $asset->current_path
            || $row->status !== 'active' || (int) $row->is_scan !== (int) $request['is_scan'] || (int) $row->date_needs_review !== 0
            || $row->date_taken_source !== 'user_manual' || $row->date_taken_precision !== 'exact'
            || ((int) ($row->exif_written ?? 0) === 1 && ($request['restore_from_receipt_id'] ?? null) === null)
            || $this->dateOnly($row->date_taken) !== $request['capture_date']
            || ! $this->sameNullableDate($row->scan_date, $request['scan_date'])
            || ! $receipt || $receipt->action !== self::EXACT_CAPTURE_ACTION || $receipt->state !== 'processing'
            || $receipt->reference !== $request['asset_uuid'] || $receipt->request_digest !== $digest
            || ($payload['request'] ?? null) !== $request || ($payload['trusted_caller'] ?? null) !== $caller
            || ! $decision || $decision->decision !== 'approved' || $decision->decision_scope !== self::EXACT_CAPTURE_ACTION
            || (int) $decision->file_registry_id !== (int) $asset->id
            || (string) $decision->authorization_receipt_id !== $receiptId
            || (string) $decision->authorized_source_sha256 !== $request['source_sha256']
            || (string) $decision->authorized_asset_uuid !== $request['asset_uuid']
            || (int) $decision->metadata_file_written === 1
            || $connection->table('file_date_review_decisions')->where('file_registry_id', $asset->id)->where('id', '>', $decisionId)->exists()) {
            throw new RuntimeException('Exact capture-date approval or current source changed before copy-back.');
        }
    }

    private function exactCaptureDateOutcomeUnknown(array $write): bool
    {
        return ($write['physical_write_outcome_unknown'] ?? false) === true
            || ($write['repair_required'] ?? false) === true
            || ($write['source_replaced'] ?? false) === true;
    }

    private function finishExactCaptureDateWrite(
        string $receiptId,
        string $digest,
        string $state,
        array $write,
        object $asset,
        int $decisionId,
        array $caller,
        ?array $result = null,
        ?string $restoreFromReceiptId = null,
    ): void {
        DB::transaction(function () use ($receiptId, $digest, $state, $write, $asset, $decisionId, $result, $restoreFromReceiptId): void {
            $receipt = DB::table('review_desk_receipts')->where('id', $receiptId)->lockForUpdate()->first();
            if (! $receipt || $receipt->action !== self::EXACT_CAPTURE_ACTION || $receipt->state !== 'processing' || $receipt->request_digest !== $digest) {
                throw new RuntimeException('Exact capture-date receipt changed before completion.');
            }
            $payload = json_decode((string) $receipt->payload, true, flags: JSON_THROW_ON_ERROR);
            $payload['effect_status'] = match ($state) {
                'completed' => 'completed', 'failed' => 'known_not_written', default => 'physical_outcome_unknown'
            };
            $payload['writer_result'] = array_intersect_key($write, array_flip(['success', 'failure_code', 'unchanged', 'written_sha256', 'backup_sha256', 'metadata_transition_receipt', 'faces_advanced', 'vectors_advanced', 'source_replaced', 'repair_required']));
            $payload['result'] = $result;
            DB::table('review_desk_receipts')->where('id', $receiptId)->update([
                'state' => $state, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            $auditUpdate = DB::table('file_date_review_decisions')->where('id', $decisionId)->where('decision', 'approved')
                ->where('decision_scope', self::EXACT_CAPTURE_ACTION)->where('metadata_file_written', false)->update([
                    'metadata_file_written' => $state === 'completed' ? empty($write['unchanged']) : (bool) ($write['source_replaced'] ?? false),
                    'metadata_writeback_error' => $state === 'completed' ? null : ($state === 'unknown' ? 'Physical write outcome requires reconciliation.' : 'Verified writer held before source replacement.'),
                    'updated_at' => now(),
                ]);
            if ($auditUpdate !== 1) {
                throw new RuntimeException('Exact capture-date audit changed before receipt completion.');
            }
            if ($state === 'completed') {
                $registryQuery = DB::table('file_registry')->where('id', $asset->id)->where('asset_uuid', $asset->asset_uuid)
                    ->where('current_path', $asset->current_path)->where('status', 'active')->where('is_scan', $asset->is_scan)
                    ->where('date_needs_review', 0)->where('date_taken_source', 'user_manual')->where('date_taken_precision', 'exact')
                    ->where('date_taken', $asset->date_taken)->where('scan_date', $asset->scan_date);
                if ($restoreFromReceiptId === null) {
                    $registryUpdate = $registryQuery->whereRaw('COALESCE(exif_written, 0) <> 1')
                        ->update(['exif_written' => 1, 'updated_at' => now()]);
                    if ($registryUpdate !== 1) {
                        throw new RuntimeException('Exact capture-date catalog changed before receipt completion.');
                    }
                } else {
                    $writtenHash = $write['written_sha256'] ?? $result['written_sha256'] ?? null;
                    if (! is_string($writtenHash) || ! preg_match('/\A[a-f0-9]{64}\z/', $writtenHash)
                        || ! $registryQuery->where('exif_written', 1)->where('content_hash', $writtenHash)
                            ->lockForUpdate()->first(['id'])) {
                        throw new RuntimeException('Restored date bytes or exact catalog state changed before receipt completion.');
                    }
                }
            }
        });
    }

    private function finishExactCaptureDateError(string $receiptId, string $digest, string $state, Throwable $error, int $decisionId, ?array $write = null): void
    {
        try {
            DB::transaction(function () use ($receiptId, $digest, $state, $decisionId, $write): void {
                $receipt = DB::table('review_desk_receipts')->where('id', $receiptId)->lockForUpdate()->first();
                if (! $receipt || $receipt->state !== 'processing' || $receipt->request_digest !== $digest) {
                    return;
                }
                $payload = json_decode((string) $receipt->payload, true, flags: JSON_THROW_ON_ERROR);
                $payload['effect_status'] = $state === 'unknown' ? 'physical_outcome_unknown' : 'known_not_written';
                $payload['error_code'] = 'exact_capture_date_write_held';
                if ($write !== null) {
                    $payload['writer_result'] = array_intersect_key($write, array_flip(['success', 'failure_code', 'unchanged', 'written_sha256', 'backup_sha256', 'metadata_transition_receipt', 'faces_advanced', 'vectors_advanced', 'source_replaced', 'repair_required']));
                }
                DB::table('review_desk_receipts')->where('id', $receiptId)->update([
                    'state' => $state, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
                DB::table('file_date_review_decisions')->where('id', $decisionId)->update([
                    'metadata_file_written' => (bool) ($write['source_replaced'] ?? (($write['success'] ?? false) === true && empty($write['unchanged']))),
                    'metadata_writeback_error' => 'Exact capture-date writer requires reconciliation.', 'updated_at' => now(),
                ]);
            });
        } catch (Throwable) {
            // A committed processing receipt remains a fail-closed reconciliation hold.
        }
    }

    private function presentExactCaptureDateWrite(object $asset, string $date, array $write, string $receiptId, string $state): array
    {
        return ['success' => $state === 'completed', 'status' => $state, 'dry_run' => false,
            'asset_uuid' => $asset->asset_uuid, 'file_registry_id' => (int) $asset->id,
            'capture_date' => $date, 'precision' => 'exact', 'source_sha256' => $write['backup_sha256'] ?? null,
            'written_sha256' => $write['written_sha256'] ?? null, 'metadata_file_written' => (bool) ($write['source_replaced'] ?? ($state === 'completed' && empty($write['unchanged']) ? true : false)),
            'physical_write_outcome_unknown' => $state === 'unknown',
            'failure_code' => $write['failure_code'] ?? null,
            'receipt_id' => $receiptId, 'identity_approval' => false];
    }

    private function safeExactDateError(Throwable $error): string
    {
        return 'Exact capture-date evidence or current catalog state is held for review.';
    }

    /** @return array{success:false,error:string,error_code:string} */
    private function error(string $message, string $code, int $httpStatus = 422): array
    {
        $result = ['success' => false, 'error' => $message, 'error_code' => $code];
        if ($httpStatus !== 422) {
            $result['http_status'] = $httpStatus;
        }

        return $result;
    }
}
