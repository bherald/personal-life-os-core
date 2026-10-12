<?php

namespace App\Services\Genealogy;

use App\Services\NextcloudFileApiService;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Exact, same-byte genealogy media path repair with full-row preimage custody. */
class GenealogyMediaPathRetargetService
{
    private const ACTION = 'genealogy_media_path_retarget';

    private const PLAN_SCHEMA = 'plos.genealogy_media_path_retarget_plan.v1';

    private const RECEIPT_SCHEMA = 'plos.genealogy_media_path_retarget_receipt.v1';

    private const REFERENCE_TABLES = [
        'person_face_profile_faces',
        'person_face_appearance_profiles',
        'genealogy_face_match_queue',
        'face_identity_provenance',
    ];

    public function __construct(
        private readonly NextcloudFileApiService $files,
        private readonly GenealogyTreeRootResolver $roots,
    ) {}

    /** Read-only qualification for one exact tree/media/source/target tuple. */
    public function plan(int $tree_id, int $media_id, int $source_registry_id, int $target_registry_id): array
    {
        try {
            $selector = $this->selector($tree_id, $media_id, $source_registry_id, $target_registry_id);
            $snapshot = $this->snapshot($selector, false);
            $body = [
                'schema' => self::PLAN_SCHEMA,
                'success' => true,
                'eligible' => true,
                'mode' => 'dry_run',
                'identity_decisions_performed' => false,
                'source_bytes_changed' => false,
                'media_path_changed' => false,
                'selector' => $selector,
                'source_path' => $snapshot['source']['current_path'],
                'target_path' => $snapshot['target']['current_path'],
                'source_sha256' => $snapshot['source']['content_hash'],
                'target_sha256' => $snapshot['target']['content_hash'],
                'file_size' => (int) $snapshot['media']['file_size'],
                'snapshot_sha256' => $this->digest($snapshot),
                'face_equivalence_sha256' => $this->digest($snapshot['face_equivalence']),
                'plan_sha256' => null,
            ];
            $body['plan_sha256'] = $this->digest(array_diff_key($body, ['plan_sha256' => true]));

            return $body;
        } catch (Throwable $error) {
            return ['success' => false, 'eligible' => false, 'mode' => 'dry_run',
                'identity_decisions_performed' => false, 'error' => $this->errorCode($error)];
        }
    }

    /** Execute one exact reviewed plan through the native confirmed local route. */
    public function execute(int $tree_id, int $media_id, int $source_registry_id, int $target_registry_id,
        string $expected_plan_sha256, string $idempotency_key): array
    {
        $request = compact('tree_id', 'media_id', 'source_registry_id', 'target_registry_id', 'expected_plan_sha256', 'idempotency_key');
        $receiptId = strtolower($idempotency_key);
        $backupCreated = false;
        $custodyAttempted = false;
        $effectTransaction = false;
        $commitAttempted = false;
        $rollbackProved = false;
        $backup = null;
        $caller = null;
        $processingJson = null;
        $terminalJson = null;

        try {
            $this->validateExecuteRequest($request);
            $caller = $this->assertNativeCaller($request);
            $selector = $this->selector($tree_id, $media_id, $source_registry_id, $target_registry_id);
            $prior = DB::table('review_desk_receipts')->where('id', $receiptId)->first();
            if ($prior !== null) {
                return $this->priorResult($prior, $request);
            }
            // Processing custody must commit independently before the domain transaction.
            if (DB::connection()->transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
                throw new RuntimeException('retarget_ambient_transaction_not_supported');
            }
            $prepared = $this->snapshot($selector, false);
            $plan = $this->planFromSnapshot($selector, $prepared);
            if (! hash_equals($request['expected_plan_sha256'], $plan['plan_sha256'])) {
                throw new RuntimeException('retarget_plan_changed');
            }
            $preimage = ['schema' => self::RECEIPT_SCHEMA, 'request' => $request,
                'trusted_caller' => $caller, 'plan_sha256' => $plan['plan_sha256'], 'before' => $prepared];
            $backup = $this->writeBackup($receiptId, $preimage);
            $backupCreated = true;
            $digest = $this->digest($request);
            $custodyAttempted = true;
            $processingPayload = ['schema' => self::RECEIPT_SCHEMA, 'request' => $request,
                'plan_sha256' => $plan['plan_sha256'], 'preimage_sha256' => $backup['sha256'],
                'backup_relative_path' => $backup['relative_path'], 'backup_bytes' => $backup['bytes'],
                'trusted_caller' => $caller, 'state' => 'processing', 'automatic_replay_allowed' => false];
            $processingJson = json_encode($processingPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            DB::table('review_desk_receipts')->insert([
                'id' => $receiptId, 'reference' => $prepared['target']['asset_uuid'],
                'idempotency_key' => $receiptId, 'request_digest' => $digest,
                'action' => self::ACTION, 'state' => 'processing', 'payload' => $processingJson,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $effectTransaction = true;
            DB::beginTransaction();
            $snapshot = $this->snapshot($selector, true, false, $receiptId);
            if ($snapshot !== $prepared || $this->planFromSnapshot($selector, $snapshot)['plan_sha256'] !== $request['expected_plan_sha256']) {
                throw new RuntimeException('retarget_plan_changed');
            }
            $owned = DB::table('review_desk_receipts')->where('id', $receiptId)->lockForUpdate()->first();
            if ($owned === null || $owned->state !== 'processing' || $owned->action !== self::ACTION
                || $owned->idempotency_key !== $receiptId || $owned->reference !== $snapshot['target']['asset_uuid']
                || $owned->request_digest !== $digest) {
                throw new RuntimeException('retarget_processing_custody_changed');
            }
            $ownedPayload = json_decode((string) $owned->payload, true, flags: JSON_THROW_ON_ERROR);
            if ($this->canonicalJson($ownedPayload) !== $this->canonicalJson($processingPayload)
                || $this->canonicalJson($this->verifyBackup($ownedPayload)) !== $this->canonicalJson($preimage)) {
                throw new RuntimeException('retarget_processing_custody_changed');
            }
            $updated = DB::table('genealogy_media')->where('id', $selector['media_id'])
                ->where('tree_id', $selector['tree_id'])->where('nextcloud_path', $snapshot['media']['nextcloud_path'])
                ->where('updated_at', $snapshot['media']['updated_at'])->where('file_exists', 1)
                ->update(['nextcloud_path' => $snapshot['target']['current_path'], 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new RuntimeException('retarget_media_row_changed');
            }
            $after = $this->snapshot($selector, true, true, $receiptId);
            $this->assertAfterSnapshot($snapshot, $after);
            $postimage = $this->writeBackup($receiptId, ['schema' => self::RECEIPT_SCHEMA,
                'request' => $request, 'trusted_caller' => $caller, 'plan_sha256' => $plan['plan_sha256'],
                'after' => $after], 'postimage.json');
            $payload = ['schema' => self::RECEIPT_SCHEMA, 'request' => $request,
                'plan_sha256' => $plan['plan_sha256'], 'preimage_sha256' => $backup['sha256'],
                'backup_relative_path' => $backup['relative_path'], 'backup_bytes' => $backup['bytes'],
                'postimage_sha256' => $postimage['sha256'], 'postimage_relative_path' => $postimage['relative_path'],
                'postimage_bytes' => $postimage['bytes'],
                'before_snapshot_sha256' => $this->digest($snapshot), 'after_snapshot_sha256' => $this->digest($after),
                'trusted_caller' => $caller, 'state' => 'completed', 'automatic_replay_allowed' => false,
                'identity_decisions_performed' => false,
                'changed_fields' => ['genealogy_media.nextcloud_path', 'genealogy_media.updated_at']];
            $terminalJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $changed = DB::table('review_desk_receipts')->where('id', $receiptId)->where('state', 'processing')->where('payload', $processingJson)
                ->update(['state' => 'completed', 'payload' => $terminalJson,
                    'updated_at' => now()]);
            if ($changed !== 1) {
                throw new RuntimeException('retarget_terminal_receipt_unavailable');
            }
            $stored = DB::table('review_desk_receipts')->where('id', $receiptId)->first();
            if ($stored === null || $stored->state !== 'completed' || $stored->request_digest !== $digest || $stored->payload !== $terminalJson) {
                throw new RuntimeException('retarget_terminal_receipt_unverified');
            }
            $commitAttempted = true;
            DB::commit();
            $effectTransaction = false;

            return ['success' => true, 'state' => 'completed', 'receipt_id' => $receiptId,
                'plan_sha256' => $plan['plan_sha256'], 'preimage_sha256' => $backup['sha256'],
                'changed_fields' => $payload['changed_fields'], 'identity_decisions_performed' => false,
                'source_bytes_changed' => false, 'automatic_replay_allowed' => false];
        } catch (Throwable $error) {
            if ($effectTransaction) {
                try {
                    if (DB::connection()->transactionLevel() > 0 || DB::connection()->getPdo()->inTransaction()) {
                        DB::rollBack();
                    }
                    $rollbackProved = ! $commitAttempted && DB::connection()->transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction();
                } catch (Throwable) {
                    // A lost rollback acknowledgement is not proof that the effect was undone.
                }
            }
            $unknown = $custodyAttempted && (! $effectTransaction || ! $rollbackProved);
            $state = $unknown ? 'unknown' : 'held';
            if ($custodyAttempted) {
                // Best effort: the independently durable processing receipt already fences replay.
                try {
                    $owned = DB::table('review_desk_receipts')->where('id', $receiptId)->first();
                    if ($owned !== null && $owned->action === self::ACTION && $owned->request_digest === $this->digest($request)) {
                        $payload = json_decode((string) $owned->payload, true, flags: JSON_THROW_ON_ERROR);
                        $payload['state'] = $unknown ? 'unknown' : 'failed';
                        $payload['failure_code'] = $this->errorCode($error);
                        $payload['rollback_proved'] = $rollbackProved;
                        $payload['automatic_replay_allowed'] = false;
                        DB::table('review_desk_receipts')->where('id', $receiptId)->where('request_digest', $owned->request_digest)
                            ->where('action', self::ACTION)->where('reference', $prepared['target']['asset_uuid'])->where('idempotency_key', $receiptId)
                            ->where(function ($query) use ($processingJson, $terminalJson): void {
                                $query->where(function ($builder) use ($processingJson): void {
                                    $builder->where('state', 'processing')->where('payload', $processingJson);
                                });
                                if ($terminalJson !== null) {
                                    $query->orWhere(function ($builder) use ($terminalJson): void {
                                        $builder->where('state', 'completed')->where('payload', $terminalJson);
                                    });
                                }
                            })->update(['state' => $payload['state'],
                                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
                    }
                } catch (Throwable) {
                    // Preserve private preimage and processing custody for explicit reconciliation.
                }
            }

            return ['success' => false, 'state' => $state, 'error' => $this->errorCode($error),
                'receipt_id' => $receiptId, 'backup_created' => $backupCreated, 'rollback_proved' => $rollbackProved,
                'identity_decisions_performed' => false, 'source_bytes_changed' => false,
                'automatic_replay_allowed' => false, 'manual_reconciliation_required' => $custodyAttempted];
        }
    }

    private function snapshot(array $selector, bool $lock, bool $after = false, ?string $excludeReceiptId = null): array
    {
        $this->requireColumns('genealogy_trees', ['id', 'name']);
        $tree = DB::table('genealogy_trees')->where('id', $selector['tree_id'])
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($tree === null) {
            throw new RuntimeException('retarget_tree_scope_unavailable');
        }
        $mediaQuery = DB::table('genealogy_media')->where('tree_id', $selector['tree_id'])->where('id', $selector['media_id']);
        if ($lock) {
            $mediaQuery->lockForUpdate();
        }
        $media = $mediaQuery->first();
        if ($media === null || (int) $media->file_exists !== 1 || ! is_string($media->nextcloud_path)
            || $media->nextcloud_path === '' || (int) $media->file_size < 0) {
            throw new RuntimeException('retarget_media_not_current');
        }
        $registryQuery = DB::table('file_registry')->whereIn('id', [$selector['source_registry_id'], $selector['target_registry_id']])
            ->orderBy('id');
        if ($lock) {
            $registryQuery->lockForUpdate();
        }
        $registry = $registryQuery->get()->keyBy('id');
        $source = $registry->get($selector['source_registry_id']);
        $target = $registry->get($selector['target_registry_id']);
        $registryIds = [$selector['source_registry_id'], $selector['target_registry_id']];
        if ($source === null || $target === null || $source->status !== 'active' || $target->status !== 'active'
            || (string) $media->nextcloud_path !== (string) ($after ? $target->current_path : $source->current_path)
            || ! is_string($source->content_hash) || ! $this->hash($source->content_hash)
            || ! is_string($target->content_hash) || ! $this->hash($target->content_hash)
            || ! $this->pathHashMatches($source) || ! $this->pathHashMatches($target)
            || $source->content_hash_verified_at === null || $target->content_hash_verified_at === null
            || $source->content_hash !== $target->content_hash
            || (int) $source->file_size !== (int) $target->file_size || (int) $media->file_size !== (int) $source->file_size) {
            throw new RuntimeException('retarget_registry_binding_invalid');
        }
        $root = rtrim($this->roots->mediaRoot($selector['tree_id']), '/');
        $sourcePath = $this->normalizePath($source->current_path);
        $targetPath = $this->normalizePath($target->current_path);
        if ($root === '' || $sourcePath === null || $targetPath === null
            || str_starts_with($sourcePath.'/', $root.'/') || $targetPath === $root || ! str_starts_with($targetPath.'/', $root.'/')) {
            throw new RuntimeException('retarget_tree_root_scope_invalid');
        }
        // MySQL may compare text paths without case. Linux source identity is exact bytes.
        $pathClaimants = collect($this->boundedRows(DB::table('file_registry')->where('status', 'active')
            ->whereIn('current_path', [$sourcePath, $targetPath])->orderBy('id'), $lock, count($registryIds)))->map(static fn ($row) => (object) $row);
        $caseDistinctIds = $pathClaimants->filter(static fn ($row): bool => ! in_array($row->current_path, [$sourcePath, $targetPath], true))
            ->pluck('id')->all();
        // They are different physical Linux paths, never aliases. If the existing
        // SQL reader nevertheless sees faces through its collation, require explicit qualification.
        if ($caseDistinctIds !== [] && DB::table('file_registry_faces')->whereIn('file_registry_id', $caseDistinctIds)->exists()) {
            throw new RuntimeException('retarget_derived_registry_scope_unavailable');
        }
        $aliases = $pathClaimants->filter(static fn ($row): bool => ! in_array((int) $row->id, $registryIds, true)
            && in_array($row->current_path, [$sourcePath, $targetPath], true))
            ->map(static fn ($row): array => (array) $row)->values()->all();
        $pathOwners = DB::table('genealogy_media')->whereIn('nextcloud_path', [$sourcePath, $targetPath])->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->filter(static fn ($row): bool => in_array($row->nextcloud_path, [$sourcePath, $targetPath], true))
            ->map(static fn ($row): array => (array) $row)->values()->all();
        foreach ($pathOwners as $owner) {
            if ((int) $owner['id'] !== $selector['media_id'] && $owner['nextcloud_path'] === $sourcePath) {
                throw new RuntimeException('retarget_source_path_not_exclusively_owned');
            }
        }
        $duplicates = count($pathOwners) !== 1;
        if ($duplicates) {
            throw new RuntimeException('retarget_target_path_already_claimed');
        }
        $sourceProof = $this->files->computeFileHash($sourcePath, hrtime(true) / 1e9 + $this->hashTimeoutSeconds(), true);
        $targetProof = $this->files->computeFileHash($targetPath, hrtime(true) / 1e9 + $this->hashTimeoutSeconds(), true);
        if (($sourceProof['success'] ?? false) !== true || ($targetProof['success'] ?? false) !== true
            || ($sourceProof['hash'] ?? null) !== $source->content_hash || ($targetProof['hash'] ?? null) !== $target->content_hash
            || (int) ($sourceProof['size'] ?? -1) !== (int) $source->file_size
            || (int) ($targetProof['size'] ?? -1) !== (int) $target->file_size) {
            throw new RuntimeException('retarget_physical_hash_mismatch');
        }

        $this->requireColumns('file_registry_faces', ['id', 'file_registry_id', 'source_current', 'superseded_at',
            'person_name', 'genealogy_person_id', 'verified', 'hidden', 'favorite', 'cluster_id',
            'global_identity_uuid', 'global_identity_approval_receipt_id', 'source', 'source_sha256',
            'source_generation_key', 'face_region_convention', 'region_x', 'region_y', 'region_w', 'region_h']);
        $faceQuery = DB::table('file_registry_faces')->whereIn('file_registry_id', $registryIds)->orderBy('id');
        if ($lock) {
            $faceQuery->lockForUpdate();
        }
        $faces = $faceQuery->get()->map(static fn ($row): array => (array) $row)->all();
        $faceIds = array_map(static fn (array $row): int => (int) $row['id'], $faces);
        $sourceFaces = array_values(array_filter($faces, static fn (array $row): bool => (int) $row['file_registry_id'] === $selector['source_registry_id']
            && self::isCurrentFace($row)));
        $targetFaces = array_values(array_filter($faces, static fn (array $row): bool => (int) $row['file_registry_id'] === $selector['target_registry_id']
            && self::isCurrentFace($row)));
        if ($this->faceEquivalence($sourceFaces) !== $this->faceEquivalence($targetFaces)) {
            throw new RuntimeException('retarget_current_face_equivalence_failed');
        }
        foreach ($faces as $face) {
            if (($face['source'] ?? null) !== 'ai_detection' || ! is_string($face['person_name'] ?? null) || $face['person_name'] !== ''
                || ($face['genealogy_person_id'] ?? null) !== null || ($face['global_identity_uuid'] ?? null) !== null
                || ($face['global_identity_approval_receipt_id'] ?? null) !== null || (int) ($face['verified'] ?? 0) !== 0
                || (int) ($face['hidden'] ?? 0) !== 0 || (int) ($face['favorite'] ?? 0) !== 0 || ($face['cluster_id'] ?? null) !== null) {
                throw new RuntimeException('retarget_protected_face_present');
            }
        }

        foreach (array_merge($sourceFaces, $targetFaces) as $face) {
            if (($face['source_sha256'] ?? null) !== $source->content_hash || ! $this->hash($face['source_generation_key'] ?? null)
                || ($face['face_region_convention'] ?? null) !== 'normalized_top_left') {
                throw new RuntimeException('retarget_current_face_source_unproved');
            }
            $region = [];
            foreach (['x', 'y', 'w', 'h'] as $axis) {
                if (! is_numeric($face['region_'.$axis]) || ! is_finite((float) $face['region_'.$axis])) {
                    throw new RuntimeException('retarget_current_face_region_invalid');
                }
                $region[$axis] = (float) $face['region_'.$axis];
            }
            if ($region['x'] < 0 || $region['y'] < 0 || $region['w'] <= 0 || $region['h'] <= 0
                || $region['x'] + $region['w'] > 1 || $region['y'] + $region['h'] > 1) {
                throw new RuntimeException('retarget_current_face_region_invalid');
            }
        }
        $vectors = DB::connection('pgsql_rag')->table('face_embeddings')->where(function ($query) use ($registryIds, $faceIds): void {
            $query->whereIn('file_registry_id', $registryIds);
            if ($faceIds !== []) {
                $query->orWhereIn('file_registry_face_id', $faceIds);
            }
        })->orderBy('id')->get()
            ->map(static fn ($row): array => (array) $row)->all();
        if ($vectors !== []) {
            throw new RuntimeException('retarget_existing_vectors_require_review');
        }

        $ledgers = $this->sourceLedgers($registryIds, [$source->asset_uuid, $target->asset_uuid], $faceIds, $lock);
        $mediaRelations = $this->mediaRelations($selector, $lock);
        if ($mediaRelations !== []) {
            throw new RuntimeException('retarget_media_relationship_present');
        }
        $this->requireColumns('file_registry_duplicates', ['canonical_file_id', 'duplicate_file_id', 'content_hash', 'status']);
        $aliasIds = array_column($aliases, 'id');
        $relationScope = array_merge($registryIds, $aliasIds);
        $duplicateQuery = DB::table('file_registry_duplicates')->where(function ($query) use ($relationScope): void {
            $query->whereIn('canonical_file_id', $relationScope)->orWhereIn('duplicate_file_id', $relationScope);
        })->orderBy('id');
        if ($lock) {
            $duplicateQuery->lockForUpdate();
        }
        $duplicateRows = $duplicateQuery->get()->map(static fn ($row): array => (array) $row)->all();
        foreach ($duplicateRows as $relation) {
            if (! in_array((int) $relation['canonical_file_id'], array_map('intval', $relationScope), true)
                || ! in_array((int) $relation['duplicate_file_id'], array_map('intval', $relationScope), true)
                || (int) $relation['canonical_file_id'] === (int) $relation['duplicate_file_id']
                || ($relation['content_hash'] ?? null) !== $source->content_hash
                || ! in_array($relation['status'] ?? null, ['pending_review', 'keep_both', 'ignored'], true)) {
                throw new RuntimeException('retarget_registry_duplicate_relation_present');
            }
        }
        $faceReferences = $this->faceReferences($selector, $registryIds, $faceIds, $lock);
        if ($faceReferences !== []) {
            throw new RuntimeException('retarget_protected_reference_present');
        }
        $approvalIds = array_values(array_unique(array_filter(array_map(static fn (array $row): ?string => $row['global_identity_approval_receipt_id'] ?? null, $faces))));
        $assetUuids = [$source->asset_uuid, $target->asset_uuid];
        $receiptQuery = DB::table('review_desk_receipts')->where(function ($query) use ($assetUuids, $approvalIds): void {
            $query->whereIn('reference', $assetUuids);
            if ($approvalIds !== []) {
                $query->orWhereIn('id', $approvalIds);
            }
        })->orderBy('id');
        if ($excludeReceiptId !== null) {
            $receiptQuery->where('id', '<>', $excludeReceiptId);
        }
        if ($lock) {
            $receiptQuery->lockForUpdate();
        }
        $receipts = $receiptQuery->get()->map(static fn ($row): array => (array) $row)->all();
        if ($receipts !== []) {
            throw new RuntimeException('retarget_approval_receipt_present');
        }

        $aliasSnapshot = $this->aliasSnapshot($selector, (array) $media, $aliases, $sourcePath, $targetPath,
            $source->content_hash, (int) $source->file_size, $lock, $excludeReceiptId);
        $mediaArray = (array) $media;
        if ($after) {
            $mediaArray['nextcloud_path'] = $targetPath;
        }
        $snapshot = [
            'selector' => $selector,
            'tree_root' => $root,
            'tree' => (array) $tree,
            'media' => $mediaArray,
            'source' => (array) $source,
            'target' => (array) $target,
            'source_physical' => ['sha256' => $sourceProof['hash'], 'size' => (int) $sourceProof['size']],
            'target_physical' => ['sha256' => $targetProof['hash'], 'size' => (int) $targetProof['size']],
            'faces' => $faces,
            'vectors' => $vectors,
            'source_ledgers' => $ledgers,
            'media_relations' => $mediaRelations,
            'registry_duplicate_relations' => $duplicateRows,
            'face_references' => $faceReferences,
            'approval_receipts' => $receipts,
            'face_equivalence' => ['source_current' => $this->faceEquivalence($sourceFaces),
                'target_current' => $this->faceEquivalence($targetFaces)],
        ];
        // Keep the original unique-path snapshot shape, including completed receipts.
        if ($aliases !== []) {
            $snapshot['path_alias_evidence'] = $aliasSnapshot;
        }
        if (strlen($this->canonicalJson($snapshot)) > $this->maxBackupBytes()) {
            throw new RuntimeException('retarget_preimage_exceeds_backup_bound');
        }

        return $snapshot;
    }

    /** Retain protected aliases as independent records, never as replacement identities. */
    private function aliasSnapshot(array $selector, array $media, array $aliases, string $sourcePath,
        string $targetPath, string $sourceHash, int $sourceSize, bool $lock, ?string $excludeReceiptId): array
    {
        $this->requireColumns('file_registry', ['original_path']);
        $this->requireColumns('genealogy_media', ['original_path']);
        if ($aliases === []) {
            // Original-path-only claimants participate in the real resolver even
            // without a current-path alias. Safe unique plans retain their old shape.
            $before = $this->derivedAliasAssociations($media, $sourcePath, [], [], $selector, $lock);
            $after = $this->derivedAliasAssociations($media, $targetPath, [], [], $selector, $lock);
            $this->assertDerivedAssociations($before, $after);

            return [];
        }
        if (count($aliases) > $this->maxReviewRows()) {
            throw new RuntimeException('retarget_alias_scope_exceeds_bound');
        }
        foreach ($aliases as $alias) {
            if (! $this->pathHashMatches((object) $alias) || ($alias['content_hash'] ?? null) !== $sourceHash
                || ($alias['content_hash_verified_at'] ?? null) === null || (int) $alias['file_size'] !== $sourceSize
                || ! Str::isUuid($alias['asset_uuid'] ?? '')) {
                throw new RuntimeException('retarget_alias_current_source_unproved');
            }
        }
        // Both exact paths were just physically hashed. Every alias names one of those same bytes.
        $ids = array_map('intval', array_column($aliases, 'id'));
        $assets = array_column($aliases, 'asset_uuid');
        $faces = $this->boundedRows(DB::table('file_registry_faces')->whereIn('file_registry_id', $ids)->orderBy('id'), $lock);
        $faceIds = array_map('intval', array_column($faces, 'id'));
        foreach ($faces as $face) {
            if (self::isCurrentFace($face)) {
                if (($face['source_sha256'] ?? null) !== $sourceHash
                    || ($face['face_region_convention'] ?? null) !== 'normalized_top_left') {
                    throw new RuntimeException('retarget_alias_current_face_unproved');
                }
                foreach (['x', 'y', 'w', 'h'] as $axis) {
                    if (! is_numeric($face['region_'.$axis]) || ! is_finite((float) $face['region_'.$axis])) {
                        throw new RuntimeException('retarget_alias_current_face_unproved');
                    }
                }
                if ((float) $face['region_x'] < 0 || (float) $face['region_y'] < 0
                    || (float) $face['region_w'] <= 0 || (float) $face['region_h'] <= 0
                    || (float) $face['region_x'] + (float) $face['region_w'] > 1
                    || (float) $face['region_y'] + (float) $face['region_h'] > 1) {
                    throw new RuntimeException('retarget_alias_current_face_unproved');
                }
            }
        }
        $vectors = $this->boundedRows(DB::connection('pgsql_rag')->table('face_embeddings')->where(function ($query) use ($ids, $faceIds): void {
            $query->whereIn('file_registry_id', $ids)->orWhereIn('file_registry_face_id', $faceIds);
        })->orderBy('id'), false);
        // The existing route has no cross-database vector publication/custody capability.
        if ($vectors !== []) {
            throw new RuntimeException('retarget_existing_vectors_require_review');
        }
        $references = $this->faceReferences($selector, $ids, $faceIds, $lock);
        // An alias review tied to this media is affected, even when the alias itself is retained.
        foreach ($references['genealogy_face_match_queue'] ?? [] as $row) {
            if ((int) $row['media_id'] === $selector['media_id']) {
                throw new RuntimeException('retarget_protected_reference_present');
            }
        }
        foreach (['agent_review_queue' => 'details', 'review_desk_references' => 'snapshot'] as $table => $column) {
            foreach ($references[$table] ?? [] as $row) {
                $packet = json_decode((string) $row[$column], true, flags: JSON_THROW_ON_ERROR);
                if ($this->reviewReferencesSource($packet, $selector, [], [], [], [])) {
                    throw new RuntimeException('retarget_protected_reference_present');
                }
            }
        }
        $this->requireColumns('person_face_profile_faces', ['profile_id']);
        $profileIds = array_values(array_unique(array_column($references['person_face_profile_faces'] ?? [], 'profile_id')));
        $profiles = [];
        $profileMembers = [];
        if ($profileIds !== []) {
            $this->requireColumns('person_face_appearance_profiles', ['id']);
            $profiles = $this->boundedRows(DB::table('person_face_appearance_profiles')->whereIn('id', $profileIds)->orderBy('id'), $lock);
            $profileMembers = $this->boundedRows(DB::table('person_face_profile_faces')->whereIn('profile_id', $profileIds)->orderBy('id'), $lock);
            if (count($profiles) !== count($profileIds)) {
                throw new RuntimeException('retarget_alias_profile_unavailable');
            }
        }
        foreach ($profileMembers as $member) {
            if (($member['face_embedding_id'] ?? null) !== null) {
                throw new RuntimeException('retarget_existing_vectors_require_review');
            }
        }
        foreach ($profiles as $profile) {
            if (($profile['representative_face_embedding_id'] ?? null) !== null) {
                throw new RuntimeException('retarget_existing_vectors_require_review');
            }
        }
        $personIds = array_values(array_unique(array_filter(array_merge(array_column($faces, 'genealogy_person_id'), array_column($profiles, 'genealogy_person_id')), static fn ($id): bool => $id !== null)));
        $persons = $this->boundedRows(DB::table('genealogy_persons')->whereIn('id', $personIds)->orderBy('id'), $lock);
        if (count($persons) !== count($personIds)) {
            throw new RuntimeException('retarget_alias_person_unavailable');
        }
        $clusterIds = array_values(array_unique(array_filter(array_merge(array_column($faces, 'cluster_id'),
            array_column($profileMembers, 'person_cluster_id'), array_column($profiles, 'representative_person_cluster_id')),
            static fn ($id): bool => $id !== null)));
        $clusters = $this->boundedRows(DB::connection('pgsql_rag')->table('person_clusters')->whereIn('id', $clusterIds)->orderBy('id'), false);
        if (count($clusters) !== count($clusterIds)) {
            throw new RuntimeException('retarget_alias_cluster_unavailable');
        }
        $matches = $this->boundedRows(DB::connection('pgsql_rag')->table('face_match_candidates')->where(function ($query) use ($faceIds, $clusterIds): void {
            $query->whereIn('candidate_face_id', $faceIds)->orWhereIn('candidate_cluster_id', $clusterIds);
        })->orderBy('id'), false);
        // Global identity dependencies are outside this narrow historical alias qualification.
        foreach ($faces as $face) {
            if (($face['global_identity_uuid'] ?? null) !== null || ($face['global_identity_approval_receipt_id'] ?? null) !== null) {
                throw new RuntimeException('retarget_alias_identity_scope_unavailable');
            }
        }
        $ledgers = $this->sourceLedgers($ids, $assets, $faceIds, $lock);
        $receipts = $this->boundedRows(DB::table('review_desk_receipts')->whereIn('reference', $assets)
            ->when($excludeReceiptId !== null, fn ($query) => $query->where('id', '<>', $excludeReceiptId))->orderBy('id'), $lock);
        foreach ($receipts as $receipt) {
            if (! in_array($receipt['state'], ['completed'], true)) {
                throw new RuntimeException('retarget_alias_receipt_requires_reconciliation');
            }
        }
        $this->requireColumns('file_content_extraction_provenance', ['file_registry_id']);
        $provenance = $this->boundedRows(DB::table('file_content_extraction_provenance')->whereIn('file_registry_id', $ids)->orderBy('file_registry_id'), $lock);
        // Query with the same SQL comparisons as both real readers; do not substitute
        // a case-folded Linux path or assume that preserved original_path is irrelevant.
        $before = $this->derivedAliasAssociations($media, $sourcePath, $ids, $faceIds, $selector, $lock);
        $after = $this->derivedAliasAssociations($media, $targetPath, $ids, $faceIds, $selector, $lock);
        $this->assertDerivedAssociations($before, $after);

        return ['registry' => $aliases, 'faces' => $faces, 'vectors' => $vectors,
            'references' => $references, 'profiles' => $profiles, 'profile_members' => $profileMembers, 'persons' => $persons, 'clusters' => $clusters, 'match_candidates' => $matches, 'source_ledgers' => $ledgers,
            'receipts' => $receipts, 'content_provenance' => $provenance, 'derived_associations' => $before];
    }

    private function assertDerivedAssociations(array $before, array $after): void
    {
        if ($before['first_current_face_semantics'] !== $after['first_current_face_semantics']) {
            throw new RuntimeException('retarget_resolver_face_context_changed');
        }
        if ($before !== $after) {
            throw new RuntimeException('retarget_protected_alias_association_changed');
        }
    }

    private function derivedAliasAssociations(array $media, string $currentPath, array $aliasIds, array $faceIds, array $selector, bool $lock): array
    {
        $original = $media['original_path'];
        $query = DB::table('file_registry')->where(function ($query) use ($currentPath, $original): void {
            $query->where('current_path', $currentPath)->orWhere('original_path', $currentPath);
            if ($original !== null) {
                $query->orWhere('current_path', $original)->orWhere('original_path', $original);
            }
        })->orderBy('id');
        $resolved = $this->boundedRows($query, $lock, count([$selector['source_registry_id'], $selector['target_registry_id']]));
        $all = array_column($resolved, 'id');
        $known = array_merge($aliasIds, [$selector['source_registry_id'], $selector['target_registry_id']]);
        $foreign = array_values(array_diff(array_map('intval', $all), $known));
        if ($foreign !== [] && DB::table('file_registry_faces')->whereIn('file_registry_id', $foreign)->exists()) {
            throw new RuntimeException('retarget_derived_registry_scope_unavailable');
        }
        $first = isset($resolved[0]) ? (int) $resolved[0]['id'] : null;
        $bridgeIds = [];
        foreach ($resolved as $row) {
            // Bridge reader uses just current/current OR original/original.
            $matches = DB::table('file_registry')->where('id', $row['id'])->where(function ($query) use ($currentPath, $original): void {
                $query->where('current_path', $currentPath);
                if ($original !== null) {
                    $query->orWhere('original_path', $original);
                }
            })->exists();
            if ($matches && in_array((int) $row['id'], $aliasIds, true)) {
                $bridgeIds[] = (int) $row['id'];
            }
        }
        $aliasResolved = array_values(array_map('intval', array_intersect($all, $aliasIds)));

        $firstFaces = $this->boundedRows(DB::table('file_registry_faces')->where('file_registry_id', $first ?? -1)
            ->where('hidden', 0)->where('source_current', 1)->whereNull('superseded_at')->orderBy('id'), $lock);

        return ['first_current_face_semantics' => $this->faceEquivalence($firstFaces),
            'registry' => $this->boundedRows(DB::table('file_registry')->whereIn('id', $aliasIds)->orderBy('id'), $lock),
            'alias_current_faces' => $this->boundedRows(DB::table('file_registry_faces')->whereIn('id', $faceIds)
                ->whereIn('file_registry_id', $aliasResolved)->where('source_current', 1)->whereNull('superseded_at')->orderBy('id'), $lock),
            'first_alias_current_faces' => $this->boundedRows(DB::table('file_registry_faces')->whereIn('id', $faceIds)
                ->where('file_registry_id', $first ?? -1)->where('source_current', 1)->whereNull('superseded_at')->orderBy('id'), $lock),
            'bridge_alias_current_faces' => $this->boundedRows(DB::table('file_registry_faces')->whereIn('id', $faceIds)
                ->whereIn('file_registry_id', $bridgeIds)->where('source_current', 1)->whereNull('superseded_at')->orderBy('id'), $lock)];
    }

    private function boundedRows($query, bool $lock, int $selectedPairRows = 0): array
    {
        $rows = $query->limit($this->maxReviewRows() + $selectedPairRows + 1)->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($rows->count() > $this->maxReviewRows() + $selectedPairRows) {
            throw new RuntimeException('retarget_alias_scope_exceeds_bound');
        }

        return $rows->map(static fn ($row): array => (array) $row)->all();
    }

    private function sourceLedgers(array $registryIds, array $assetUuids, array $faceIds, bool $lock): array
    {
        $rows = [];
        foreach (['file_registry_face_generation_runs', 'file_registry_face_reconciliation_runs', 'file_registry_face_vector_refresh_runs'] as $table) {
            $required = ['id', 'file_registry_id', 'asset_uuid', 'stage'];
            $required[] = match ($table) {
                'file_registry_face_generation_runs' => 'mysql_face_ids',
                'file_registry_face_reconciliation_runs' => 'manifest_json',
                default => 'file_registry_face_id',
            };
            $this->requireColumns($table, $required);
            $query = DB::table($table)->where(function ($builder) use ($registryIds, $assetUuids, $faceIds, $table): void {
                $builder->whereIn('file_registry_id', $registryIds)->orWhereIn('asset_uuid', $assetUuids);
                if ($table === 'file_registry_face_vector_refresh_runs' && $faceIds !== []) {
                    $builder->orWhereIn('file_registry_face_id', $faceIds);
                } elseif ($table === 'file_registry_face_generation_runs') {
                    foreach ($faceIds as $faceId) {
                        $builder->orWhereJsonContains('mysql_face_ids', $faceId)->orWhereJsonContains('mysql_face_ids', (string) $faceId);
                    }
                } elseif ($table === 'file_registry_face_reconciliation_runs') {
                    foreach ($faceIds as $faceId) {
                        $builder->orWhereJsonContains('manifest_json->faces', ['id' => $faceId])
                            ->orWhereJsonContains('manifest_json->faces', ['id' => (string) $faceId]);
                    }
                }
            })->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate());
            $rows[$table] = $query->get()->map(static fn ($row): array => (array) $row)->all();
            foreach ($rows[$table] as $run) {
                if ($run['stage'] !== 'complete') {
                    throw new RuntimeException('retarget_source_ledger_requires_reconciliation');
                }
            }
        }

        return $rows;
    }

    private function reviewReferencesSource(array $packet, array $selector, array $registryIds, array $faceIds, array $assetUuids, array $paths): bool
    {
        $selectors = [
            'file_id' => $registryIds,
            'face_id' => $faceIds, 'file_registry_face_id' => $faceIds, 'representative_file_registry_face_id' => $faceIds,
            'face_ids' => $faceIds, 'file_registry_face_ids' => $faceIds, 'mysql_face_ids' => $faceIds,
            'file_registry_id' => $registryIds, 'registry_id' => $registryIds, 'source_registry_id' => $registryIds,
            'target_registry_id' => $registryIds, 'registry_ids' => $registryIds,
            'media_id' => [$selector['media_id']], 'primary_photo_id' => [$selector['media_id']],
            'asset_uuid' => $assetUuids, 'source_asset_uuid' => $assetUuids, 'target_asset_uuid' => $assetUuids,
            'path' => $paths, 'source_path' => $paths, 'target_path' => $paths, 'current_path' => $paths, 'nextcloud_path' => $paths,
        ];
        foreach ($packet as $key => $value) {
            if (isset($selectors[$key])) {
                foreach (is_array($value) ? $value : [$value] as $item) {
                    if ((is_string($item) || is_int($item)) && in_array((string) $item, array_map('strval', $selectors[$key]), true)) {
                        return true;
                    }
                }
            }
            if (is_array($value) && $this->reviewReferencesSource($value, $selector, $registryIds, $faceIds, $assetUuids, $paths)) {
                return true;
            }
        }

        return false;
    }

    private function mediaRelations(array $selector, bool $lock): array
    {
        $specs = [
            'genealogy_person_media' => ['media_id'],
            'genealogy_family_media' => ['media_id'],
            'genealogy_citations' => ['media_id'],
            'genealogy_media_crops' => ['media_id'],
            'genealogy_persons' => ['primary_photo_id'],
        ];
        $rows = [];
        foreach ($specs as $table => [$column]) {
            $this->requireColumns($table, [$column]);
            $query = DB::table($table)->where($column, $selector['media_id'])->orderBy('id');
            if ($lock) {
                $query->lockForUpdate();
            }
            $found = $query->get()->map(static fn ($row): array => (array) $row)->all();
            if ($found !== []) {
                $rows[$table] = $found;
            }
        }

        return $rows;
    }

    private function faceReferences(array $selector, array $registryIds, array $faceIds, bool $lock): array
    {
        $rows = [];
        $selectors = [
            'person_face_profile_faces' => ['file_registry_face_id' => $faceIds, 'file_registry_id' => $registryIds],
            'person_face_appearance_profiles' => ['representative_file_registry_face_id' => $faceIds,
                'representative_face_embedding_id' => [], 'representative_person_cluster_id' => []],
            'genealogy_face_match_queue' => ['file_registry_face_id' => $faceIds, 'media_id' => [$selector['media_id']]],
            'face_identity_provenance' => ['file_registry_face_id' => $faceIds, 'file_registry_id' => $registryIds],
        ];
        foreach (self::REFERENCE_TABLES as $table) {
            $conditions = $selectors[$table];
            $this->requireColumns($table, array_keys($conditions));
            $query = DB::table($table)->where(function ($builder) use ($conditions): void {
                foreach ($conditions as $column => $values) {
                    if ($values !== []) {
                        $builder->orWhereIn($column, $values);
                    }
                }
            })->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate());
            // No face IDs means there can be no selected representative face. Vectors/clusters already hold.
            if ($table === 'person_face_appearance_profiles' && $faceIds === []) {
                continue;
            }
            $found = $query->get()->map(static fn ($row): array => (array) $row)->all();
            if ($found !== []) {
                $rows[$table] = $found;
            }
        }

        $registry = DB::table('file_registry')->whereIn('id', $registryIds)->get();
        $assetUuids = $registry->pluck('asset_uuid')->all();
        $paths = $registry->pluck('current_path')->all();
        $this->requireColumns('agent_review_queue', ['id', 'review_type', 'details']);
        $this->requireColumns('review_desk_references', ['reference', 'source', 'source_id', 'review_type', 'snapshot']);
        // JSON selectors can appear inside an existing review packet, not just at its top level.
        $reviewQuery = DB::table('agent_review_queue')->orderBy('id');
        $referenceQuery = DB::table('review_desk_references')->orderBy('reference');
        $needles = array_merge($faceIds, $registryIds, [$selector['media_id']], $assetUuids, $paths, array_map('basename', $paths));
        foreach ([[$reviewQuery, 'details'], [$referenceQuery, 'snapshot']] as [$query, $column]) {
            $query->where(function ($builder) use ($needles, $column): void {
                foreach ($needles as $needle) {
                    // Coarse selection only; exact typed fields below determine protection.
                    $builder->orWhere($column, 'like', '%'.(string) $needle.'%');
                }
            });
        }
        $referenceQuery->orWhere(function ($query) use ($faceIds, $registryIds, $selector): void {
            $query->whereIn('source', ['file_registry_faces', 'file_registry', 'genealogy_media'])
                ->whereIn('source_id', array_merge($faceIds, $registryIds, [$selector['media_id']]));
        });
        foreach ([['agent_review_queue', $reviewQuery, 'details'], ['review_desk_references', $referenceQuery, 'snapshot']] as [$table, $query, $column]) {
            $found = $query->limit($this->maxReviewRows() + 1)->when($lock, fn ($query) => $query->lockForUpdate())->get();
            if ($found->count() > $this->maxReviewRows()) {
                throw new RuntimeException('retarget_review_scope_exceeds_bound');
            }
            foreach ($found as $row) {
                $packet = json_decode((string) $row->{$column}, true);
                if (! is_array($packet)) {
                    throw new RuntimeException('retarget_review_evidence_unavailable');
                }
                $retainedFaceHold = $table === 'agent_review_queue' && in_array($row->review_type, ['face', 'global_face_identity'], true)
                    && array_filter($faceIds, static fn ($faceId): bool => str_contains((string) $row->{$column}, (string) $faceId)) !== [];
                if ($retainedFaceHold || $this->reviewReferencesSource($packet, $selector, $registryIds, $faceIds, $assetUuids, $paths)
                    || ($table === 'review_desk_references' && in_array($row->source, ['file_registry_faces', 'file_registry', 'genealogy_media'], true))) {
                    $rows[$table][] = (array) $row;
                }
            }
        }

        $pg = DB::connection('pgsql_rag');
        $vectorIds = $pg->table('face_embeddings')->whereIn('file_registry_id', $registryIds)->pluck('id')->all();
        $clusterIds = array_values(array_unique(array_filter(array_map(static fn (array $face): ?int => isset($face['cluster_id']) ? (int) $face['cluster_id'] : null,
            DB::table('file_registry_faces')->whereIn('file_registry_id', $registryIds)->get(['cluster_id'])->map(static fn ($row): array => (array) $row)->all()))));
        if ($vectorIds !== []) {
            $query = $pg->table('face_match_candidates')->where(function ($builder) use ($vectorIds, $faceIds): void {
                $builder->whereIn('face_embedding_id', $vectorIds)->orWhereIn('candidate_face_id', $vectorIds)->orWhereIn('candidate_face_id', $faceIds);
            })->orderBy('id');
            if ($lock) {
                $query->lockForUpdate();
            }
            $found = $query->get()->map(static fn ($row): array => (array) $row)->all();
            if ($found !== []) {
                $rows['face_match_candidates'] = $found;
            }
        }
        if ($clusterIds !== []) {
            $query = $pg->table('person_clusters')->whereIn('id', $clusterIds)->orderBy('id');
            if ($lock) {
                $query->lockForUpdate();
            }
            $found = $query->get()->map(static fn ($row): array => (array) $row)->all();
            if ($found !== []) {
                $rows['person_clusters'] = $found;
            }
        }

        return $rows;
    }

    private function planFromSnapshot(array $selector, array $snapshot): array
    {
        $body = ['schema' => self::PLAN_SCHEMA, 'success' => true, 'eligible' => true, 'mode' => 'dry_run',
            'identity_decisions_performed' => false, 'source_bytes_changed' => false, 'media_path_changed' => false,
            'selector' => $selector, 'source_path' => $snapshot['source']['current_path'],
            'target_path' => $snapshot['target']['current_path'], 'source_sha256' => $snapshot['source']['content_hash'],
            'target_sha256' => $snapshot['target']['content_hash'], 'file_size' => (int) $snapshot['media']['file_size'],
            'snapshot_sha256' => $this->digest($snapshot), 'face_equivalence_sha256' => $this->digest($snapshot['face_equivalence']),
            'plan_sha256' => null];
        $body['plan_sha256'] = $this->digest(array_diff_key($body, ['plan_sha256' => true]));

        return $body;
    }

    private function assertAfterSnapshot(array $before, array $after): void
    {
        $expected = $before;
        $expected['media']['nextcloud_path'] = $after['media']['nextcloud_path'];
        $expected['media']['updated_at'] = $after['media']['updated_at'];
        if ($expected !== $after) {
            throw new RuntimeException('retarget_postwrite_snapshot_changed');
        }
    }

    private function writeBackup(string $receiptId, array $preimage, string $filename = 'preimage.json'): array
    {
        $relative = config('file_registry.media_path_retarget.private_directory');
        if (! is_string($relative) || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')
            || in_array('..', explode('/', $relative), true)) {
            throw new RuntimeException('retarget_backup_configuration_invalid');
        }
        $base = storage_path();
        foreach (explode('/', $relative) as $part) {
            $base .= '/'.$part;
            if (is_link($base)) {
                throw new RuntimeException('retarget_backup_unavailable');
            }
            if (! is_dir($base)) {
                if (! mkdir($base, 0700)) {
                    throw new RuntimeException('retarget_backup_unavailable');
                }
                // Make every newly created private directory binding durable as well as its contents.
                $this->syncDirectory(dirname($base));
                $this->syncDirectory($base);
            }
        }
        if (! chmod($base, 0700)) {
            throw new RuntimeException('retarget_backup_unavailable');
        }
        $directory = $base.'/'.$receiptId;
        if (is_link($directory) || (! is_dir($directory) && ! mkdir($directory, 0700, true)) || ! chmod($directory, 0700)) {
            throw new RuntimeException('retarget_backup_unavailable');
        }
        $this->syncDirectory($base);
        $bytes = $this->canonicalJson($preimage);
        if (strlen($bytes) > $this->maxBackupBytes()) {
            throw new RuntimeException('retarget_preimage_exceeds_backup_bound');
        }
        $path = $directory.'/'.$filename;
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('retarget_backup_unavailable');
        }
        try {
            if (! chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('retarget_backup_unavailable');
            }
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== 0600 || filesize($path) !== strlen($bytes) || hash_file('sha256', $path) !== hash('sha256', $bytes)) {
            throw new RuntimeException('retarget_backup_unverified');
        }
        $this->syncDirectory($directory);

        return ['relative_path' => $relative.'/'.$receiptId.'/'.$filename, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    private function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'r');
        if ($handle === false) {
            throw new RuntimeException('retarget_backup_unavailable');
        }
        try {
            if (! fsync($handle)) {
                throw new RuntimeException('retarget_backup_unavailable');
            }
        } finally {
            fclose($handle);
        }
    }

    private function assertNativeCaller(array $request): array
    {
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        if (! is_array($caller) || count($caller) !== 5
            || array_diff(array_keys($caller), ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256']) !== []
            || ! is_string($caller['arguments_sha256'] ?? null)
            || ! hash_equals($caller['arguments_sha256'], hash('sha256', json_encode($request, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('retarget_trusted_native_caller_required');
        }
        foreach (['session_id', 'turn_id', 'action_id'] as $field) {
            if (! is_string($caller[$field] ?? null) || $caller[$field] === '' || preg_match('/[\x00-\x20\x7f]/', $caller[$field])) {
                throw new RuntimeException('retarget_native_caller_invalid');
            }
        }
        foreach (['arguments_sha256', 'approval_scope_sha256'] as $field) {
            if (! $this->hash($caller[$field] ?? null)) {
                throw new RuntimeException('retarget_native_caller_invalid');
            }
        }

        return $caller;
    }

    private function verifyBackup(array $payload, bool $postimage = false): array
    {
        $relative = config('file_registry.media_path_retarget.private_directory');
        $receiptId = $payload['request']['idempotency_key'] ?? null;
        $filename = $postimage ? 'postimage.json' : 'preimage.json';
        $stored = $payload[$postimage ? 'postimage_relative_path' : 'backup_relative_path'] ?? null;
        $hash = $payload[$postimage ? 'postimage_sha256' : 'preimage_sha256'] ?? null;
        $bytes = $payload[$postimage ? 'postimage_bytes' : 'backup_bytes'] ?? null;
        if (! is_string($relative) || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')
            || in_array('..', explode('/', $relative), true) || ! is_string($receiptId) || ! Str::isUuid($receiptId)
            || $stored !== $relative.'/'.$receiptId.'/'.$filename || ! $this->hash($hash)
            || ! is_int($bytes) || $bytes < 1 || $bytes > $this->maxBackupBytes()) {
            throw new RuntimeException('retarget_backup_receipt_invalid');
        }
        $directory = storage_path($relative.'/'.$receiptId);
        $backupBase = storage_path($relative);
        $path = $directory.'/'.$filename;
        $base = storage_path();
        foreach (explode('/', $relative.'/'.$receiptId) as $part) {
            $base .= '/'.$part;
            if (is_link($base)) {
                throw new RuntimeException('retarget_backup_unavailable');
            }
        }
        clearstatcache(true, $path);
        if (! is_dir($backupBase) || (fileperms($backupBase) & 0777) !== 0700 || ! is_dir($directory)
            || (fileperms($directory) & 0777) !== 0700 || is_link($path) || ! is_file($path)
            || (fileperms($path) & 0777) !== 0600 || filesize($path) !== $bytes || hash_file('sha256', $path) !== $hash) {
            throw new RuntimeException('retarget_backup_unverified');
        }
        $contents = file_get_contents($path);
        if (! is_string($contents) || strlen($contents) !== $bytes || hash('sha256', $contents) !== $hash) {
            throw new RuntimeException('retarget_backup_unverified');
        }
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('retarget_backup_receipt_invalid');
        }

        return $decoded;
    }

    private function priorResult(object $receipt, array $request): array
    {
        if ($receipt->action !== self::ACTION || $receipt->idempotency_key !== $request['idempotency_key']
            || $receipt->request_digest !== $this->digest($request)) {
            throw new RuntimeException('retarget_idempotency_conflict');
        }
        if ($receipt->state !== 'completed') {
            throw new RuntimeException('retarget_prior_outcome_requires_reconciliation');
        }
        $payload = json_decode((string) $receipt->payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ($payload['schema'] ?? null) !== self::RECEIPT_SCHEMA
            || ($payload['request'] ?? null) !== $request || ($payload['state'] ?? null) !== 'completed') {
            throw new RuntimeException('retarget_receipt_conflict');
        }
        $preimage = $this->verifyBackup($payload);
        $postimage = $this->verifyBackup($payload, true);
        foreach ([$preimage, $postimage] as $image) {
            if (($image['schema'] ?? null) !== self::RECEIPT_SCHEMA || $this->canonicalize($image['request'] ?? null) !== $this->canonicalize($request)
                || ($image['plan_sha256'] ?? null) !== $request['expected_plan_sha256']
                || $this->canonicalize($image['trusted_caller'] ?? null) !== $this->canonicalize($payload['trusted_caller'] ?? null)) {
                throw new RuntimeException('retarget_receipt_conflict');
            }
        }
        $before = $preimage['before'] ?? null;
        $after = $postimage['after'] ?? null;
        $selector = $this->selector($request['tree_id'], $request['media_id'], $request['source_registry_id'], $request['target_registry_id']);
        if (! is_array($before) || ! is_array($after) || $this->canonicalize($before['selector'] ?? null) !== $this->canonicalize($selector)
            || $this->canonicalize($after['selector'] ?? null) !== $this->canonicalize($selector) || $receipt->reference !== ($before['target']['asset_uuid'] ?? null)
            || ($payload['plan_sha256'] ?? null) !== $request['expected_plan_sha256']
            || $this->planFromSnapshot($selector, $before)['plan_sha256'] !== $request['expected_plan_sha256']
            || ($payload['before_snapshot_sha256'] ?? null) !== $this->digest($before)
            || ($payload['after_snapshot_sha256'] ?? null) !== $this->digest($after)
            || ($payload['changed_fields'] ?? null) !== ['genealogy_media.nextcloud_path', 'genealogy_media.updated_at']
            || ($payload['automatic_replay_allowed'] ?? null) !== false || ($payload['identity_decisions_performed'] ?? null) !== false) {
            throw new RuntimeException('retarget_receipt_conflict');
        }
        $this->assertAfterSnapshot($before, $after);
        if ($this->canonicalJson($this->snapshot($selector, false, true, $receipt->id)) !== $this->canonicalJson($after)) {
            throw new RuntimeException('retarget_completed_postimage_changed');
        }

        return ['success' => true, 'state' => 'completed', 'receipt_id' => $receipt->id,
            'plan_sha256' => $payload['plan_sha256'] ?? null, 'preimage_sha256' => $payload['preimage_sha256'] ?? null,
            'changed_fields' => $payload['changed_fields'] ?? [], 'identity_decisions_performed' => false,
            'source_bytes_changed' => false, 'automatic_replay_allowed' => false, 'reused_complete' => true];
    }

    private function selector(int $treeId, int $mediaId, int $sourceRegistryId, int $targetRegistryId): array
    {
        if ($treeId < 1 || $mediaId < 1 || $sourceRegistryId < 1 || $targetRegistryId < 1 || $sourceRegistryId === $targetRegistryId) {
            throw new RuntimeException('retarget_selector_invalid');
        }

        return ['tree_id' => $treeId, 'media_id' => $mediaId, 'source_registry_id' => $sourceRegistryId,
            'target_registry_id' => $targetRegistryId];
    }

    private function validateExecuteRequest(array $request): void
    {
        if (! $this->hash($request['expected_plan_sha256'] ?? null) || ! Str::isUuid($request['idempotency_key'] ?? '')
            || strtolower($request['idempotency_key']) !== $request['idempotency_key']) {
            throw new RuntimeException('retarget_execute_request_invalid');
        }
    }

    private function requireColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumns($table, $columns)) {
            throw new RuntimeException('retarget_reference_schema_unavailable');
        }
    }

    private function faceEquivalence(array $faces): array
    {
        $out = [];
        foreach ($faces as $face) {
            // Detection time describes each independently retained copy, not face semantics.
            unset($face['id'], $face['file_registry_id'], $face['created_at'], $face['updated_at'], $face['detected_at']);
            $out[] = $this->canonicalize($face);
        }
        usort($out, fn (array $a, array $b): int => strcmp($this->canonicalJson($a), $this->canonicalJson($b)));

        return $out;
    }

    private static function isCurrentFace(array $face): bool
    {
        return (int) ($face['source_current'] ?? 0) === 1 && ($face['superseded_at'] ?? null) === null;
    }

    private function normalizePath(string $path): ?string
    {
        if ($path !== trim($path)) {
            return null;
        }
        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '/' || str_contains($path, "\0") || preg_match('~(^|/)\.\.?(/|$)~', $path)
            || str_contains($path, '//') || preg_match('~^https?://~i', $path)) {
            return null;
        }

        return rtrim($path, '/');
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }

    private function canonicalJson(array $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function digest(array $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    private function pathHashMatches(object $row): bool
    {
        if (! is_string($row->current_path ?? null) || ! is_string($row->path_hash ?? null)) {
            return false;
        }

        return hash_equals($row->path_hash, hash('sha256', $row->current_path))
            || hash_equals($row->path_hash, hash('sha256', strtolower($row->current_path)));
    }

    private function hashTimeoutSeconds(): int
    {
        $value = filter_var(config('file_registry.media_path_retarget.hash_timeout_seconds'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 600]]);
        if ($value === false) {
            throw new RuntimeException('retarget_hash_timeout_policy_unavailable');
        }

        return $value;
    }

    private function maxReviewRows(): int
    {
        $value = filter_var(config('file_registry.media_path_retarget.max_review_rows'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new RuntimeException('retarget_review_bound_unavailable');
        }

        return $value;
    }

    private function maxBackupBytes(): int
    {
        $value = filter_var(config('file_registry.media_path_retarget.max_backup_bytes'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new RuntimeException('retarget_backup_bound_unavailable');
        }

        return $value;
    }

    private function errorCode(Throwable $error): string
    {
        $message = $error->getMessage();

        return preg_match('/\A[a-z0-9_]{1,96}\z/', $message) === 1 ? $message : 'retarget_held';
    }
}
