<?php

namespace App\Services\Review;

use App\Services\FaceRegionService;
use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\NextcloudFileApiService;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Stage one exact legacy face as a region-only ReviewDesk item. */
class LegacyFaceRegionReviewStagingService
{
    public const ACTION = 'stage_face_region_review';

    public const SELECTED_REGION_ACTION = 'stage_photo_face_region';

    public function __construct(
        private readonly FaceLinkBridgeService $faceSources,
        private readonly FaceRegionService $regions,
        private readonly NextcloudFileApiService $files,
        private readonly ReviewDeskService $desk,
    ) {}

    /** Read the exact selected legacy row and current source/metadata bindings. */
    public function plan(int $faceId, int $treeId, int $mediaId, int $personId, string $assetUuid): array
    {
        $this->validateSelectors($faceId, $treeId, $mediaId, $personId, $assetUuid);
        $evidence = $this->currentEvidence($faceId, $treeId, $mediaId, $personId, $assetUuid);
        if (($hold = $this->priorDecisionPlanHold($evidence['review_history'], $faceId)) !== null) {
            return $hold;
        }

        return ['success' => true, 'read_only' => true, 'identity_decision_changed' => false,
            'asset_uuid' => $assetUuid, 'file_registry_id' => $evidence['file_registry_id'],
            'current_path' => $evidence['current_path'], 'media_id' => $mediaId, 'tree_id' => $treeId,
            'legacy_face_id' => $faceId, 'legacy_person_id' => $personId,
            'source_sha256' => $evidence['source_sha256'],
            'face_snapshot_sha256' => $evidence['face_snapshot_sha256'],
            'region_info_sha256' => $evidence['region_info_sha256'],
            'review_history_sha256' => $evidence['review_history_sha256'],
            'path_binding_sha256' => $evidence['path_binding_sha256'],
            'source_dimensions' => $evidence['source_dimensions'],
            'region_count' => count($evidence['region_info']['RegionList'] ?? []),
            'proposed_queue' => ['status' => 'pending', 'match_type' => 'no_match',
                'file_registry_face_id' => $faceId, 'suggested_person_id' => null,
                'media_id' => $mediaId, 'tree_id' => $treeId],
            'stage_allowed' => true];
    }

    /** Plan one explicitly selected ROI for an unbound legacy AI observation. */
    public function planOperatorSelectedRegion(
        int $faceId,
        int $treeId,
        int $mediaId,
        int $personId,
        string $assetUuid,
        array $proposedRegion,
    ): array {
        $this->validateSelectors($faceId, $treeId, $mediaId, $personId, $assetUuid);
        $region = $this->selectedRegion($proposedRegion);
        $evidence = $this->operatorSelectedRegionEvidence($faceId, $treeId, $mediaId, $personId, $assetUuid, $region);
        if (($hold = $this->priorDecisionPlanHold($evidence['review_history'], $faceId)) !== null) {
            return $hold;
        }
        $this->assertSelectedRegionHasNoCurrentSourceDuplicate($evidence, $region);

        return ['success' => true, 'read_only' => true, 'identity_decision_changed' => false,
            'asset_uuid' => $assetUuid, 'file_registry_id' => $evidence['file_registry_id'],
            'current_path' => $evidence['current_path'], 'tree_id' => $treeId, 'media_id' => $mediaId,
            'person_context_id' => $personId, 'legacy_face_id' => $faceId,
            'source_sha256' => $evidence['source_sha256'], 'source_dimensions' => $evidence['source_dimensions'],
            'face_snapshot_sha256' => $evidence['face_snapshot_sha256'],
            'region_info_sha256' => $evidence['region_info_sha256'],
            'review_history_sha256' => $evidence['review_history_sha256'],
            'path_binding_sha256' => $evidence['path_binding_sha256'],
            'person_media_snapshot_sha256' => $evidence['person_media_snapshot_sha256'],
            'region_count' => count($evidence['region_info']['RegionList'] ?? []),
            'proposed_region' => $region, 'proposed_region_sha256' => $this->digest($region),
            'proposed_queue' => ['status' => 'pending', 'match_type' => 'no_match', 'face_name' => '',
                'file_registry_face_id' => $faceId, 'suggested_person_id' => null,
                'media_id' => $mediaId, 'tree_id' => $treeId],
            'stage_allowed' => true];
    }

    /** Stage a pending region-only review without changing the legacy face or its identity/vector history. */
    public function stageOperatorSelectedRegion(
        int $faceId,
        int $treeId,
        int $mediaId,
        int $personId,
        string $assetUuid,
        array $proposedRegion,
        string $sourceSha256,
        array $sourceDimensions,
        string $faceSnapshotSha256,
        string $regionInfoSha256,
        string $reviewHistorySha256,
        string $pathBindingSha256,
        string $personMediaSnapshotSha256,
        string $idempotencyKey,
    ): array {
        $this->validateSelectors($faceId, $treeId, $mediaId, $personId, $assetUuid);
        $region = $this->selectedRegion($proposedRegion);
        $sourceDimensions = $this->selectedDimensions($sourceDimensions);
        foreach ([$sourceSha256, $faceSnapshotSha256, $regionInfoSha256, $reviewHistorySha256,
            $pathBindingSha256, $personMediaSnapshotSha256] as $hash) {
            if (! is_string($hash) || ! preg_match('/\A[a-f0-9]{64}\z/D', $hash)) {
                throw new RuntimeException('selected_face_region_binding_invalid');
            }
        }
        if (! Str::isUuid($idempotencyKey)) {
            throw new RuntimeException('selected_face_region_request_invalid');
        }
        $caller = $this->trustedCaller();
        $request = ['face_id' => $faceId, 'tree_id' => $treeId, 'media_id' => $mediaId,
            'person_id' => $personId, 'asset_uuid' => strtolower($assetUuid), 'proposed_region' => $region,
            'source_sha256' => $sourceSha256, 'source_dimensions' => $sourceDimensions,
            'face_snapshot_sha256' => $faceSnapshotSha256,
            'region_info_sha256' => $regionInfoSha256, 'review_history_sha256' => $reviewHistorySha256,
            'path_binding_sha256' => $pathBindingSha256, 'person_media_snapshot_sha256' => $personMediaSnapshotSha256,
            'idempotency_key' => $idempotencyKey];
        $digest = $this->digest($request);

        try {
            return DB::transaction(function () use ($request, $digest, $caller, $idempotencyKey): array {
                $prior = DB::table('review_desk_receipts')->where('id', $idempotencyKey)->lockForUpdate()->first();
                if ($prior !== null) {
                    return $this->selectedRegionReplay($prior, $request, $digest);
                }

                $evidence = $this->operatorSelectedRegionEvidence(
                    $request['face_id'], $request['tree_id'], $request['media_id'], $request['person_id'],
                    $request['asset_uuid'], $request['proposed_region'], [
                        $request['source_sha256'], $request['face_snapshot_sha256'], $request['region_info_sha256'],
                        $request['review_history_sha256'], $request['path_binding_sha256'],
                        $request['person_media_snapshot_sha256'],
                    ], true
                );
                $this->assertNoPriorDecision($evidence['review_history'], $request);
                if ($evidence['source_dimensions'] !== $request['source_dimensions']) {
                    throw new RuntimeException('selected_face_region_binding_changed');
                }
                $this->assertSelectedRegionHasNoCurrentSourceDuplicate($evidence, $request['proposed_region']);

                $receiptId = $idempotencyKey;
                $now = now();
                $payload = ['schema' => 'plos.operator_selected_photo_region_stage.v1', 'request' => $request,
                    'caller' => $caller, 'caller_binding_sha256' => $this->callerDigest($caller),
                    'success' => false, 'decision_recorded' => false, 'identity_decision_changed' => false,
                    'effect_status' => 'not_started'];
                DB::table('review_desk_receipts')->insert([
                    'id' => $receiptId, 'reference' => $request['asset_uuid'], 'idempotency_key' => $idempotencyKey,
                    'request_digest' => $digest, 'action' => self::SELECTED_REGION_ACTION, 'state' => 'processing',
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                $queueId = DB::table('genealogy_face_match_queue')->insertGetId([
                    'tree_id' => $request['tree_id'], 'media_id' => $request['media_id'],
                    'file_registry_face_id' => $request['face_id'], 'face_name' => '', 'suggested_person_id' => null,
                    'match_type' => 'no_match', 'confidence_score' => 0,
                    'face_region' => json_encode($request['proposed_region'], JSON_THROW_ON_ERROR),
                    'match_details' => json_encode([
                        'schema' => 'plos.operator_selected_photo_region_stage.v1',
                        'source_sha256' => $evidence['source_sha256'], 'source_path' => $evidence['current_path'],
                        'source_dimensions' => $evidence['source_dimensions'], 'coordinate_basis' => 'encoded_pixels',
                        'face_region_convention' => 'normalized_top_left', 'region_info_sha256' => $evidence['region_info_sha256'],
                        'operator_selected_region_context' => ['tree_id' => $request['tree_id'],
                            'media_id' => $request['media_id'], 'person_context_id' => $request['person_id'],
                            'legacy_face_id' => $request['face_id'], 'legacy_face_snapshot_sha256' => $evidence['face_snapshot_sha256'],
                            'person_media_snapshot_sha256' => $evidence['person_media_snapshot_sha256'],
                            'proposed_region_sha256' => $this->digest($request['proposed_region'])],
                        'legacy_face_reference' => ['file_registry_face_id' => $request['face_id'],
                            'face_snapshot_sha256' => $evidence['face_snapshot_sha256'],
                            'snapshot' => array_intersect_key((array) $evidence['face'], array_flip([
                                'id', 'file_registry_id', 'face_index', 'person_name', 'genealogy_person_id',
                                'region_x', 'region_y', 'region_w', 'region_h', 'source', 'verified', 'hidden',
                                'favorite', 'cluster_id', 'source_sha256', 'face_region_convention', 'source_current',
                                'superseded_at', 'source_generation_key', 'global_identity_uuid',
                                'global_identity_approval_receipt_id', 'created_at', 'updated_at',
                            ]))],
                        'review_history_sha256' => $evidence['review_history_sha256'],
                        'path_binding_sha256' => $evidence['path_binding_sha256'],
                        'identity_decision_changed' => false,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $item = $this->desk->materializeReference('face:'.$queueId);
                if (! is_array($item) || ($item['review_type'] ?? null) !== 'face'
                    || ($item['status'] ?? null) !== 'pending' || ! in_array('refresh_region', $item['actions'] ?? [], true)
                    || in_array('approve', $item['actions'] ?? [], true) || in_array('reject', $item['actions'] ?? [], true)) {
                    throw new RuntimeException('selected_face_region_reference_unavailable');
                }
                $this->assertSourceStillCurrent($evidence);
                $payload = array_replace($payload, ['success' => true, 'effect_status' => 'staged',
                    'queue_id' => $queueId, 'review_reference' => $item['reference'],
                    'desk_alias' => 'face:'.$queueId, 'staged_at' => now()->utc()->toIso8601String()]);
                DB::table('review_desk_receipts')->where('id', $receiptId)->update([
                    'reference' => $item['reference'], 'state' => 'completed',
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                ]);

                return ['success' => true, 'staged' => true, 'replayed' => false,
                    'identity_decision_changed' => false, 'decision_recorded' => false, 'queue_id' => $queueId,
                    'review_reference' => $item['reference'], 'desk_alias' => 'face:'.$queueId,
                    'source_sha256' => $evidence['source_sha256'], 'proposed_region' => $request['proposed_region'],
                    'legacy_face_snapshot_sha256' => $evidence['face_snapshot_sha256'], 'receipt_id' => $receiptId];
            });
        } catch (Throwable $error) {
            $prior = DB::table('review_desk_receipts')->where('id', $idempotencyKey)->first();
            if ($prior !== null) {
                return $this->selectedRegionReplay($prior, $request, $digest);
            }
            throw $error;
        }
    }

    /** Stage only after the CLI worker confirms these exact source and snapshot hashes. */
    public function stage(
        int $faceId,
        int $treeId,
        int $mediaId,
        int $personId,
        string $assetUuid,
        string $sourceSha256,
        string $faceSnapshotSha256,
        string $regionInfoSha256,
        string $reviewHistorySha256,
        string $pathBindingSha256,
        string $idempotencyKey,
    ): array {
        $this->validateSelectors($faceId, $treeId, $mediaId, $personId, $assetUuid);
        foreach ([$sourceSha256, $faceSnapshotSha256, $regionInfoSha256, $reviewHistorySha256, $pathBindingSha256] as $hash) {
            if (! is_string($hash) || ! preg_match('/\A[a-f0-9]{64}\z/D', $hash)) {
                throw new RuntimeException('legacy_face_review_binding_invalid');
            }
        }
        if (! Str::isUuid($idempotencyKey)) {
            throw new RuntimeException('legacy_face_review_request_invalid');
        }
        $caller = $this->trustedCaller();
        $request = ['face_id' => $faceId, 'tree_id' => $treeId, 'media_id' => $mediaId, 'person_id' => $personId,
            'asset_uuid' => $assetUuid, 'source_sha256' => $sourceSha256, 'face_snapshot_sha256' => $faceSnapshotSha256,
            'region_info_sha256' => $regionInfoSha256, 'review_history_sha256' => $reviewHistorySha256,
            'path_binding_sha256' => $pathBindingSha256, 'idempotency_key' => $idempotencyKey];
        $digest = $this->digest($request);

        try {
            return DB::transaction(function () use ($request, $digest, $caller, $idempotencyKey, $assetUuid): array {
                $prior = DB::table('review_desk_receipts')->where('id', $idempotencyKey)->lockForUpdate()->first();
                if ($prior !== null) {
                    return $this->replay($prior, $request, $digest);
                }

                $evidence = $this->currentEvidence(
                    $request['face_id'], $request['tree_id'], $request['media_id'], $request['person_id'], $assetUuid,
                    [$request['source_sha256'], $request['face_snapshot_sha256'], $request['region_info_sha256'],
                        $request['review_history_sha256'], $request['path_binding_sha256']], true
                );
                $this->assertNoPriorDecision($evidence['review_history'], $request);
                $receiptId = $idempotencyKey;
                $now = now();
                $payload = ['schema' => 'plos.legacy_face_region_review_stage.v1', 'request' => $request,
                    'caller' => $caller, 'caller_binding_sha256' => $this->callerDigest($caller),
                    'success' => false, 'decision_recorded' => false, 'identity_decision_changed' => false,
                    'effect_status' => 'not_started'];
                DB::table('review_desk_receipts')->insert([
                    'id' => $receiptId, 'reference' => $assetUuid, 'idempotency_key' => $idempotencyKey,
                    'request_digest' => $digest, 'action' => self::ACTION, 'state' => 'processing',
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                $queueId = DB::table('genealogy_face_match_queue')->insertGetId([
                    'tree_id' => $request['tree_id'], 'media_id' => $request['media_id'],
                    'file_registry_face_id' => $request['face_id'], 'face_name' => '', 'suggested_person_id' => null,
                    'match_type' => 'no_match', 'confidence_score' => 0, 'face_region' => null,
                    'match_details' => json_encode([
                        'schema' => 'plos.legacy_face_region_review_stage.v1',
                        'source_sha256' => $evidence['source_sha256'], 'source_path' => $evidence['current_path'],
                        'source_dimensions' => $evidence['source_dimensions'], 'coordinate_basis' => 'encoded_pixels',
                        'face_region_convention' => 'normalized_top_left',
                        'legacy_face_reference' => ['file_registry_face_id' => $request['face_id'],
                            'person_id' => $request['person_id'], 'face_snapshot_sha256' => $evidence['face_snapshot_sha256']],
                        'region_info_sha256' => $evidence['region_info_sha256'],
                        'review_history_sha256' => $evidence['review_history_sha256'],
                        'path_binding_sha256' => $evidence['path_binding_sha256'],
                        'identity_decision_changed' => false,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $item = $this->desk->materializeReference('face:'.$queueId);
                if (! is_array($item) || ($item['review_type'] ?? null) !== 'face'
                    || ($item['status'] ?? null) !== 'pending' || ! in_array('refresh_region', $item['actions'] ?? [], true)
                    || in_array('approve', $item['actions'] ?? [], true) || in_array('reject', $item['actions'] ?? [], true)) {
                    throw new RuntimeException('legacy_face_review_reference_unavailable');
                }
                $this->assertSourceStillCurrent($evidence);
                $payload = array_replace($payload, ['success' => true, 'effect_status' => 'staged',
                    'queue_id' => $queueId, 'review_reference' => $item['reference'],
                    'desk_alias' => 'face:'.$queueId, 'staged_at' => now()->utc()->toIso8601String()]);
                DB::table('review_desk_receipts')->where('id', $receiptId)->update([
                    'reference' => $item['reference'], 'state' => 'completed',
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                ]);

                return ['success' => true, 'staged' => true, 'replayed' => false,
                    'identity_decision_changed' => false, 'decision_recorded' => false,
                    'queue_id' => $queueId, 'review_reference' => $item['reference'],
                    'desk_alias' => 'face:'.$queueId, 'source_sha256' => $evidence['source_sha256'],
                    'face_snapshot_sha256' => $evidence['face_snapshot_sha256'],
                    'region_info_sha256' => $evidence['region_info_sha256'], 'receipt_id' => $receiptId];
            });
        } catch (Throwable $error) {
            $prior = DB::table('review_desk_receipts')->where('id', $idempotencyKey)->first();
            if ($prior !== null) {
                return $this->replay($prior, $request, $digest);
            }
            throw $error;
        }
    }

    private function currentEvidence(int $faceId, int $treeId, int $mediaId, int $personId, string $assetUuid, ?array $expected = null, bool $lock = false): array
    {
        $assetQuery = DB::table('file_registry')->where('asset_uuid', $assetUuid);
        $asset = ($lock ? $assetQuery->lockForUpdate() : $assetQuery)->first();
        if (! $asset || $asset->status !== 'active') {
            throw new RuntimeException('legacy_face_review_asset_unavailable');
        }
        $faceQuery = DB::table('file_registry_faces')->where('id', $faceId)->where('file_registry_id', $asset->id);
        $face = ($lock ? $faceQuery->lockForUpdate() : $faceQuery)->first();
        $mediaQuery = DB::table('genealogy_media')->where('id', $mediaId)->where('tree_id', $treeId);
        $media = ($lock ? $mediaQuery->lockForUpdate() : $mediaQuery)->first();
        $personQuery = DB::table('genealogy_persons')->where('id', $personId)->where('tree_id', $treeId);
        $person = ($lock ? $personQuery->lockForUpdate() : $personQuery)->first(['id', 'tree_id']);
        if (! $face || ! $media || ! $person || (int) $face->source_current !== 1 || $face->superseded_at !== null
            || (int) ($face->hidden ?? 0) === 1 || (int) ($face->verified ?? 0) !== 1 || $face->source !== 'manual'
            || (int) ($face->genealogy_person_id ?? 0) !== $personId
            || ! in_array($face->source_generation_key ?? null, [null, '', 'legacy'], true)
            || ($face->source_sha256 !== null && ! hash_equals($face->source_sha256, $expected[0] ?? $face->source_sha256))) {
            throw new RuntimeException('legacy_face_review_selection_changed');
        }
        $registryPath = (string) ($asset->current_path ?? '');
        $mediaPath = (string) ($media->nextcloud_path ?: $media->original_path ?: '');
        if ($registryPath === '' || $mediaPath === '' || $this->canonicalPath($registryPath) !== $this->canonicalPath($mediaPath)) {
            throw new RuntimeException('legacy_face_review_path_mismatch');
        }
        $faceSnapshotSha = $this->digest((array) $face);
        $pathBindingSha = $this->digest(['asset_uuid' => $assetUuid, 'file_registry_id' => (int) $asset->id,
            'registry_path' => $asset->current_path, 'media_nextcloud_path' => $media->nextcloud_path,
            'media_original_path' => $media->original_path]);
        $history = $this->history($faceId, $treeId, $mediaId, $lock);
        $historySha = $this->digest($history);
        $source = $this->faceSources->reviewSourcePath($registryPath, $expected[0] ?? null, true);
        if (($source['success'] ?? false) !== true || ! is_string($source['source_sha256'] ?? null)) {
            throw new RuntimeException('legacy_face_review_source_unavailable');
        }
        $localPath = $this->files->localPath($registryPath);
        if (! is_string($localPath) || $localPath === '') {
            throw new RuntimeException('legacy_face_review_local_source_required');
        }
        $metadata = $this->regions->readFaceMetadataEvidence($localPath, $source['source_sha256']);
        $regionList = $metadata['region_info']['RegionList'] ?? null;
        $faceIndex = (int) ($face->face_index ?? -1);
        if (! is_array($regionList) || ! array_is_list($regionList) || $faceIndex < 0 || ! array_key_exists($faceIndex, $regionList)) {
            throw new RuntimeException('legacy_face_review_region_index_unavailable');
        }
        $this->assertNoAmbiguousHistory($history, $faceId);
        $this->assertPriorRejections($face, $treeId, $mediaId, $personId, $source['source_sha256']);
        $evidence = ['file_registry_id' => (int) $asset->id, 'asset_uuid' => $assetUuid,
            'current_path' => $registryPath, 'media_path' => $mediaPath, 'face' => $face,
            'face_snapshot_sha256' => $faceSnapshotSha, 'path_binding_sha256' => $pathBindingSha,
            'review_history_sha256' => $historySha, 'region_info_sha256' => $metadata['region_info_sha256'],
            'region_info' => $metadata['region_info'], 'source_sha256' => $source['source_sha256'],
            'source_dimensions' => $source['source_dimensions'], 'review_history' => $history];
        if ($expected !== null) {
            foreach ([$evidence['source_sha256'], $evidence['face_snapshot_sha256'], $evidence['region_info_sha256'],
                $evidence['review_history_sha256'], $evidence['path_binding_sha256']] as $index => $actual) {
                if (! is_string($actual) || ! hash_equals($expected[$index], $actual)) {
                    throw new RuntimeException('legacy_face_review_binding_changed');
                }
            }
        }

        return $evidence;
    }

    private function operatorSelectedRegionEvidence(
        int $faceId,
        int $treeId,
        int $mediaId,
        int $personId,
        string $assetUuid,
        array $proposedRegion,
        ?array $expected = null,
        bool $lock = false,
    ): array {
        $assetQuery = DB::table('file_registry')->where('asset_uuid', strtolower($assetUuid));
        $asset = ($lock ? $assetQuery->lockForUpdate() : $assetQuery)->first();
        if (! $asset || $asset->status !== 'active') {
            throw new RuntimeException('selected_face_region_asset_unavailable');
        }
        $faceQuery = DB::table('file_registry_faces')->where('id', $faceId)->where('file_registry_id', $asset->id);
        $face = ($lock ? $faceQuery->lockForUpdate() : $faceQuery)->first();
        $mediaQuery = DB::table('genealogy_media')->where('id', $mediaId)->where('tree_id', $treeId);
        $media = ($lock ? $mediaQuery->lockForUpdate() : $mediaQuery)->first();
        $personQuery = DB::table('genealogy_persons')->where('id', $personId)->where('tree_id', $treeId);
        $person = ($lock ? $personQuery->lockForUpdate() : $personQuery)->first(['id', 'tree_id']);
        $personMediaQuery = DB::table('genealogy_person_media')->where('person_id', $personId)->where('media_id', $mediaId);
        $personMediaRows = ($lock ? $personMediaQuery->lockForUpdate() : $personMediaQuery)->limit(2)->get();
        if (! $face || ! $media || ! $person || $personMediaRows->count() !== 1
            || (int) ($face->source_current ?? 0) !== 1 || $face->superseded_at !== null
            || (int) ($face->hidden ?? 0) !== 0 || (int) ($face->verified ?? 0) !== 0
            || (int) ($face->favorite ?? 0) !== 0 || ($face->cluster_id ?? null) !== null
            || $face->source !== 'ai_detection' || ! in_array($face->source_generation_key ?? null, [null, '', 'legacy'], true)
            || trim((string) ($face->person_name ?? '')) !== '' || $face->genealogy_person_id !== null
            || $face->global_identity_uuid !== null || $face->global_identity_approval_receipt_id !== null
            || ! is_null($face->source_sha256) || ! is_null($face->face_region_convention)) {
            throw new RuntimeException('selected_face_region_legacy_row_not_eligible');
        }
        if (DB::table('face_identity_provenance')->where('file_registry_face_id', $faceId)->exists()
            || $this->hasGlobalIdentityReview($faceId)) {
            throw new RuntimeException('selected_face_region_identity_history_protected');
        }
        $registryPath = (string) ($asset->current_path ?? '');
        $mediaPath = (string) ($media->nextcloud_path ?: $media->original_path ?: '');
        if ($registryPath === '' || $mediaPath === '' || $this->canonicalPath($registryPath) !== $this->canonicalPath($mediaPath)) {
            throw new RuntimeException('selected_face_region_path_mismatch');
        }

        $source = $this->faceSources->reviewSourcePath($registryPath, $expected[0] ?? null, true);
        if (($source['success'] ?? false) !== true || ! is_string($source['source_sha256'] ?? null)
            || ! is_array($source['source_dimensions'] ?? null) || count($source['source_dimensions']) !== 2) {
            throw new RuntimeException('selected_face_region_source_unavailable');
        }
        $localPath = $this->files->localPath($registryPath);
        if (! is_string($localPath) || $localPath === '') {
            throw new RuntimeException('selected_face_region_local_source_required');
        }
        $metadata = $this->regions->readFaceMetadataEvidence($localPath, $source['source_sha256']);
        $regionInfo = $metadata['region_info'] ?? null;
        if ($regionInfo === []) {
            $regionInfo = ['RegionList' => []];
        }
        if (! is_string($metadata['region_info_sha256'] ?? null) || ! is_array($regionInfo)
            || ! is_array($regionInfo['RegionList'] ?? null)
            || ! array_is_list($regionInfo['RegionList'])
            || ! is_array($metadata['source_dimensions'] ?? null)
            || (int) ($metadata['source_dimensions']['width'] ?? 0) !== (int) $source['source_dimensions'][0]
            || (int) ($metadata['source_dimensions']['height'] ?? 0) !== (int) $source['source_dimensions'][1]) {
            throw new RuntimeException('selected_face_region_metadata_ambiguous');
        }
        $region = $this->selectedRegion($proposedRegion);
        if (floor($region['w'] * $source['source_dimensions'][0]) < 1 || floor($region['h'] * $source['source_dimensions'][1]) < 1) {
            throw new RuntimeException('selected_face_region_geometry_invalid');
        }
        $faceSnapshotSha = $this->digest((array) $face);
        $pathBindingSha = $this->digest(['asset_uuid' => strtolower($assetUuid), 'file_registry_id' => (int) $asset->id,
            'registry_status' => $asset->status, 'registry_path' => $asset->current_path,
            'registry_revision' => $asset->updated_at ?? null, 'registry_content_hash' => $asset->content_hash ?? null,
            'registry_file_size' => $asset->file_size ?? null, 'registry_nextcloud_fileid' => $asset->nextcloud_fileid ?? null,
            'media_id' => (int) $media->id, 'media_tree_id' => (int) $media->tree_id,
            'media_nextcloud_path' => $media->nextcloud_path, 'media_original_path' => $media->original_path]);
        $history = $this->history($faceId, $treeId, $mediaId, $lock);
        $historySha = $this->digest($history);
        $personMedia = $personMediaRows->first();
        $personMediaSha = $this->digest((array) $personMedia);
        $this->assertNoAmbiguousHistory($history, $faceId);
        $this->assertSelectedRegionPriorDecisions($face, $treeId, $mediaId, $personId, $source['source_sha256'], $region);
        $evidence = ['file_registry_id' => (int) $asset->id, 'asset_uuid' => strtolower($assetUuid),
            'current_path' => $registryPath, 'media_path' => $mediaPath, 'face' => $face,
            'face_snapshot_sha256' => $faceSnapshotSha, 'path_binding_sha256' => $pathBindingSha,
            'review_history_sha256' => $historySha, 'region_info_sha256' => $metadata['region_info_sha256'],
            'region_info' => $regionInfo, 'source_sha256' => $source['source_sha256'],
            'source_dimensions' => array_values($source['source_dimensions']), 'review_history' => $history,
            'person_media_snapshot_sha256' => $personMediaSha, 'person_media' => $personMedia];
        if ($expected !== null) {
            foreach ([$evidence['source_sha256'], $evidence['face_snapshot_sha256'], $evidence['region_info_sha256'],
                $evidence['review_history_sha256'], $evidence['path_binding_sha256'], $evidence['person_media_snapshot_sha256']] as $index => $actual) {
                if (! is_string($actual) || ! hash_equals($expected[$index], $actual)) {
                    throw new RuntimeException('selected_face_region_binding_changed');
                }
            }
        }

        return $evidence;
    }

    private function selectedDimensions(mixed $dimensions): array
    {
        if (! is_array($dimensions) || ! array_is_list($dimensions) || count($dimensions) !== 2
            || ! is_int($dimensions[0] ?? null) || ! is_int($dimensions[1] ?? null)
            || min($dimensions) < 1) {
            throw new RuntimeException('selected_face_region_dimensions_invalid');
        }

        return $dimensions;
    }

    private function selectedRegion(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('selected_face_region_geometry_invalid');
        }
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['h', 'w', 'x', 'y']) {
            throw new RuntimeException('selected_face_region_geometry_invalid');
        }
        $region = [];
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if ((! is_float($value[$axis]) && ! is_int($value[$axis])) || ! is_finite((float) $value[$axis])) {
                throw new RuntimeException('selected_face_region_geometry_invalid');
            }
            $region[$axis] = round((float) $value[$axis], 8);
        }
        if ($region['x'] < 0 || $region['y'] < 0 || $region['w'] <= 0 || $region['h'] <= 0
            || $region['x'] + $region['w'] > 1 || $region['y'] + $region['h'] > 1) {
            throw new RuntimeException('selected_face_region_geometry_invalid');
        }

        return $region;
    }

    private function assertSelectedRegionPriorDecisions(object $face, int $treeId, int $mediaId, int $personId, string $sourceSha256, array $region): void
    {
        $selected = clone $face;
        $selected->source_sha256 = $sourceSha256;
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            $selected->{'region_'.$axis} = $region[$axis];
        }
        try {
            $this->faceSources->assertFaceIdentityProposalNotPreviouslyRejected($face->id, $treeId, $mediaId, $personId, $region);
            $this->faceSources->assertFaceRegionNotDismissed($selected, $treeId, $mediaId);
        } catch (Throwable $error) {
            $message = $error->getMessage();
            if (str_contains($message, 'Recorded person rejection')
                || str_contains($message, 'This current source region was rejected as Not a face')) {
                throw new RuntimeException('selected_face_region_rejection_hold', previous: $error);
            }
            if (str_contains($message, 'dismissal history') || str_contains($message, 'dismissal geometry')) {
                throw new RuntimeException('selected_face_region_history_hold', previous: $error);
            }
            if (str_contains($message, 'ownership or region')) {
                throw new RuntimeException('selected_face_region_ownership_hold', previous: $error);
            }

            throw new RuntimeException('selected_face_region_evidence_unavailable', previous: $error);
        }
    }

    private function hasGlobalIdentityReview(int $faceId): bool
    {
        $maximumRows = max(1, min(500, (int) config('file_registry.face_preview.max_suggestion_regions', 100)));
        $rows = DB::table('agent_review_queue')->where('review_type', 'global_face_identity')
            ->where(function ($query) use ($faceId): void {
                $query->whereJsonContains('details->global_identity_decision->face_id', $faceId)
                    ->orWhereJsonContains('details->global_identity_decision->face_id', (string) $faceId);
            })->limit($maximumRows + 1)->get(['details']);
        if ($rows->count() > $maximumRows) {
            throw new RuntimeException('selected_face_region_identity_history_limit');
        }
        foreach ($rows as $row) {
            $details = json_decode((string) $row->details, true);
            if (! is_array($details)) {
                throw new RuntimeException('selected_face_region_identity_history_invalid');
            }
            if ((int) ($details['global_identity_decision']['face_id'] ?? 0) === $faceId) {
                return true;
            }
        }

        return false;
    }

    private function assertSelectedRegionHasNoCurrentSourceDuplicate(array $evidence, array $region): void
    {
        $maximumRows = max(1, min(500, (int) config('file_registry.face_preview.max_suggestion_regions', 100)));
        $faces = DB::table('file_registry_faces')->where('file_registry_id', $evidence['file_registry_id'])
            ->where('source_sha256', $evidence['source_sha256'])->where('source_current', 1)->whereNull('superseded_at')
            ->limit($maximumRows + 1)->get();
        if ($faces->count() > $maximumRows) {
            throw new RuntimeException('selected_face_region_current_history_limit');
        }
        foreach ($faces as $face) {
            $candidate = [];
            foreach (['x', 'y', 'w', 'h'] as $axis) {
                $candidate[$axis] = $face->{'region_'.$axis};
            }
            if ($this->sameRegion($candidate, $region)) {
                throw new RuntimeException('selected_face_region_current_source_exists');
            }
        }
    }

    private function selectedRegionReplay(object $receipt, array $request, string $digest): array
    {
        if ($receipt->action !== self::SELECTED_REGION_ACTION || ! hash_equals((string) $receipt->request_digest, $digest)
            || ($receipt->idempotency_key ?? null) !== $request['idempotency_key'] || $receipt->state !== 'completed') {
            throw new RuntimeException('selected_face_region_idempotency_conflict');
        }
        $payload = json_decode((string) $receipt->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ($payload['schema'] ?? null) !== 'plos.operator_selected_photo_region_stage.v1'
            || ($payload['success'] ?? false) !== true || ($payload['request'] ?? null) !== $request
            || ! is_int($payload['queue_id'] ?? null) || ! is_string($payload['review_reference'] ?? null)
            || ($payload['identity_decision_changed'] ?? true) !== false) {
            throw new RuntimeException('selected_face_region_receipt_invalid');
        }
        $queue = DB::table('genealogy_face_match_queue')->where('id', $payload['queue_id'])->first();
        $details = $queue ? json_decode((string) ($queue->match_details ?? ''), true) : null;
        $context = is_array($details) ? ($details['operator_selected_region_context'] ?? null) : null;
        $review = $queue ? $this->desk->lookup($payload['review_reference']) : null;
        if (! $queue || (int) $queue->tree_id !== $request['tree_id'] || (int) $queue->media_id !== $request['media_id']
            || $queue->status !== 'pending' || trim((string) $queue->face_name) !== '' || $queue->suggested_person_id !== null
            || $queue->match_type !== 'no_match' || ! is_array($context)
            || (int) ($context['legacy_face_id'] ?? 0) !== $request['face_id']
            || (int) ($context['person_context_id'] ?? 0) !== $request['person_id']
            || ! $review || ($review['review_type'] ?? null) !== 'face') {
            throw new RuntimeException('selected_face_region_stage_no_longer_current');
        }

        return ['success' => true, 'staged' => true, 'replayed' => true,
            'identity_decision_changed' => false, 'decision_recorded' => false,
            'queue_id' => $payload['queue_id'], 'review_reference' => $payload['review_reference'],
            'desk_alias' => $payload['desk_alias'] ?? 'face:'.$payload['queue_id'],
            'source_sha256' => $request['source_sha256'], 'proposed_region' => $request['proposed_region'],
            'legacy_face_snapshot_sha256' => $request['face_snapshot_sha256'], 'receipt_id' => $receipt->id];
    }

    private function sameRegion(array $left, array $right): bool
    {
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if (! is_numeric($left[$axis] ?? null) || ! is_numeric($right[$axis] ?? null)
                || ! is_finite((float) $left[$axis]) || ! is_finite((float) $right[$axis])
                || round((float) $left[$axis], 8) !== round((float) $right[$axis], 8)) {
                return false;
            }
        }

        return true;
    }

    private function history(int $faceId, int $treeId, int $mediaId, bool $lock): array
    {
        $maximumRows = max(1, min(500, (int) config('file_registry.face_preview.max_suggestion_regions', 100)));
        $query = DB::table('genealogy_face_match_queue')->where('tree_id', $treeId)
            ->where(function ($query) use ($faceId, $mediaId): void {
                $query->where('file_registry_face_id', $faceId)->orWhere('media_id', $mediaId);
            })->orderBy('id')->limit($maximumRows + 1);
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($rows->count() > $maximumRows) {
            throw new RuntimeException('legacy_face_review_history_limit');
        }

        return $rows->map(fn ($row): array => (array) $row)->all();
    }

    private function assertNoAmbiguousHistory(array $history, int $faceId): void
    {
        foreach ($history as $row) {
            $rowFaceId = (int) ($row['file_registry_face_id'] ?? 0);
            $details = json_decode((string) ($row['match_details'] ?? '{}'), true);
            if (! is_array($details)) {
                if ($rowFaceId === 0 || $rowFaceId === $faceId) {
                    throw new RuntimeException('legacy_face_review_history_invalid');
                }

                continue;
            }
            $legacyFaceId = (int) ($details['legacy_face_reference']['file_registry_face_id'] ?? 0);
            $relevant = $rowFaceId === $faceId || $legacyFaceId === $faceId;
            if (! $relevant) {
                continue;
            }
            if (($row['status'] ?? null) === 'pending') {
                throw new RuntimeException('legacy_face_review_pending_conflict');
            }

            $reviews = $details['false_detection_reviews'] ?? [];
            if (! is_array($reviews)) {
                throw new RuntimeException('legacy_face_review_dismissal_history_invalid');
            }
            foreach ($reviews as $review) {
                $evidence = is_array($review) ? ($review['current_evidence'] ?? null) : null;
                if (! is_array($evidence) || ! preg_match('/\A[a-f0-9]{64}\z/D', (string) ($evidence['source_sha256'] ?? ''))
                    || ! is_array($evidence['region'] ?? null)) {
                    throw new RuntimeException('legacy_face_review_dismissal_history_invalid');
                }
            }
        }
    }

    private function priorDecisionPlanHold(array $history, int $faceId): ?array
    {
        try {
            $this->assertNoPriorDecision($history, ['face_id' => $faceId]);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'legacy_face_review_prior_decision') {
                throw $error;
            }

            return ['success' => false, 'state' => 'held', 'stage_allowed' => false,
                'read_only' => true, 'effects_started' => false, 'identity_decision_changed' => false,
                'legacy_face_id' => $faceId, 'error_code' => $error->getMessage(), 'error' => $error->getMessage()];
        }

        return null;
    }

    private function assertNoPriorDecision(array $history, array $request): void
    {
        foreach ($history as $row) {
            $rowFaceId = (int) ($row['file_registry_face_id'] ?? 0);
            $details = json_decode((string) ($row['match_details'] ?? '{}'), true);
            if (! is_array($details)) {
                if ($rowFaceId === 0 || $rowFaceId === $request['face_id']) {
                    throw new RuntimeException('legacy_face_review_history_invalid');
                }

                continue;
            }
            $legacyFaceId = $details['legacy_face_reference']['file_registry_face_id'] ?? null;
            if ($rowFaceId === $request['face_id']
                || (is_numeric($legacyFaceId) && (int) $legacyFaceId === $request['face_id'])) {
                throw new RuntimeException('legacy_face_review_prior_decision');
            }
        }
    }

    private function assertPriorRejections(object $face, int $treeId, int $mediaId, int $personId, string $sourceSha256): void
    {
        $sourceFace = clone $face;
        $sourceFace->source_sha256 = $sourceSha256;
        try {
            $bridge = $this->faceSources;
            $bridge->assertFaceIdentityProposalNotPreviouslyRejected($face->id, $treeId, $mediaId, $personId, [
                'x' => (float) $face->region_x, 'y' => (float) $face->region_y,
                'w' => (float) $face->region_w, 'h' => (float) $face->region_h,
            ]);
            $bridge->assertFaceRegionNotDismissed($sourceFace, $treeId, $mediaId);
        } catch (Throwable $error) {
            throw new RuntimeException('legacy_face_review_rejection_hold', previous: $error);
        }
    }

    private function assertSourceStillCurrent(array $evidence): void
    {
        $source = $this->faceSources->reviewSourcePath($evidence['current_path'], $evidence['source_sha256'], true);
        if (($source['success'] ?? false) !== true) {
            throw new RuntimeException('legacy_face_review_source_changed');
        }
    }

    private function replay(object $receipt, array $request, string $digest): array
    {
        if ($receipt->action !== self::ACTION || ! hash_equals((string) $receipt->request_digest, $digest)
            || ($receipt->idempotency_key ?? null) !== $request['idempotency_key'] || $receipt->state !== 'completed') {
            throw new RuntimeException('legacy_face_review_idempotency_conflict');
        }
        $payload = json_decode((string) $receipt->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ($payload['schema'] ?? null) !== 'plos.legacy_face_region_review_stage.v1'
            || ($payload['success'] ?? false) !== true || ($payload['request'] ?? null) !== $request
            || ! is_int($payload['queue_id'] ?? null) || ! is_string($payload['review_reference'] ?? null)
            || ($payload['identity_decision_changed'] ?? true) !== false) {
            throw new RuntimeException('legacy_face_review_receipt_invalid');
        }
        $queue = DB::table('genealogy_face_match_queue')->where('id', $payload['queue_id'])->first();
        $review = $queue ? $this->desk->lookup($payload['review_reference']) : null;
        if (! $queue || (int) $queue->tree_id !== $request['tree_id'] || (int) $queue->media_id !== $request['media_id']
            || $queue->status !== 'pending' || ! $review || ($review['review_type'] ?? null) !== 'face') {
            throw new RuntimeException('legacy_face_review_stage_no_longer_current');
        }

        return ['success' => true, 'staged' => true, 'replayed' => true,
            'identity_decision_changed' => false, 'decision_recorded' => false,
            'queue_id' => $payload['queue_id'], 'review_reference' => $payload['review_reference'],
            'desk_alias' => $payload['desk_alias'] ?? 'face:'.$payload['queue_id'],
            'source_sha256' => $request['source_sha256'], 'face_snapshot_sha256' => $request['face_snapshot_sha256'],
            'region_info_sha256' => $request['region_info_sha256'], 'receipt_id' => $receipt->id];
    }

    private function validateSelectors(int $faceId, int $treeId, int $mediaId, int $personId, string $assetUuid): void
    {
        if ($faceId < 1 || $treeId < 1 || $mediaId < 1 || $personId < 1 || ! Str::isUuid($assetUuid)) {
            throw new RuntimeException('legacy_face_review_selection_invalid');
        }
    }

    private function trustedCaller(): array
    {
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        $keys = ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256'];
        if (! is_array($caller) || count($caller) !== count($keys) || array_diff(array_keys($caller), $keys) !== []) {
            throw new RuntimeException('legacy_face_review_trusted_cli_required');
        }
        foreach ($keys as $key) {
            if (! is_string($caller[$key] ?? null) || trim($caller[$key]) === '' || strlen($caller[$key]) > 200) {
                throw new RuntimeException('legacy_face_review_trusted_cli_required');
            }
        }
        foreach (['arguments_sha256', 'approval_scope_sha256'] as $key) {
            if (! preg_match('/\A[a-f0-9]{64}\z/D', $caller[$key])) {
                throw new RuntimeException('legacy_face_review_trusted_cli_required');
            }
        }

        return $caller;
    }

    private function callerDigest(array $caller): string
    {
        return $this->digest($caller);
    }

    private function digest(mixed $value): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $entry) {
                $value[$key] = $canonical($entry);
            }

            return $value;
        };

        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalPath(string $path): string
    {
        $path = preg_replace('~/+~', '/', trim($path)) ?? '';

        return rtrim($path, '/') ?: '/';
    }
}
