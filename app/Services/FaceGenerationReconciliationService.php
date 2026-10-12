<?php

namespace App\Services;

use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Retain anonymous historical detections while retiring an exact approved cohort. */
class FaceGenerationReconciliationService
{
    public function __construct(private FaceEmbeddingService $faces) {}

    public function plan(string $assetUuid, ?array $legacyRetirementIntent = null): array
    {
        try {
            $legacyRetirementIntent = $this->legacyRetirementIntent($legacyRetirementIntent);
            $plan = $this->sourcePlan($assetUuid);
            if (($plan['hold_reason'] ?? null) === 'anonymous_generation_reconciliation_pending') {
                throw new RuntimeException('anonymous_generation_reconciliation_pending');
            }
            if (DB::table('file_registry_face_reconciliation_runs')->where('asset_uuid', $assetUuid)->where('stage', '!=', 'complete')->exists()) {
                throw new RuntimeException('anonymous_generation_reconciliation_pending');
            }
            $manifest = $this->manifest($assetUuid, $plan, false, $legacyRetirementIntent);

            return ['success' => true, 'eligible' => true, 'mode' => 'dry_run', 'detector_run' => false,
                'asset_uuid' => $assetUuid, 'source_sha256' => $plan['source_sha256'],
                'binding_sha256' => $plan['binding_sha256'], 'manifest_sha256' => $this->digest($manifest),
                'manifest_type' => $manifest['manifest_type'] ?? 'source_bound_anonymous',
                'face_ids' => array_column($manifest['faces'], 'id'),
                'face_count' => count($manifest['faces']), 'vector_count' => count($manifest['vectors'])];
        } catch (\Throwable $e) {
            return ['success' => false, 'eligible' => false, 'error' => $this->errorCode($e)];
        }
    }

    public function execute(string $assetUuid, string $sourceSha256, string $bindingSha256, string $manifestSha256, string $idempotencyKey, ?array $legacyRetirementIntent = null): array
    {
        $runId = null;
        $lease = null;
        try {
            $legacyRetirementIntent = $this->legacyRetirementIntent($legacyRetirementIntent);
            if (! Str::isUuid($assetUuid) || ! Str::isUuid($idempotencyKey)) {
                throw new RuntimeException('reconciliation_request_invalid');
            }
            foreach ([$sourceSha256, $bindingSha256, $manifestSha256] as $digest) {
                if (! preg_match('/^[a-f0-9]{64}$/D', $digest)) {
                    throw new RuntimeException('reconciliation_request_invalid');
                }
            }
            $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
            $callerHash = $this->callerDigest($caller);
            $lease = (string) Str::uuid();
            $pg = DB::connection('pgsql_rag');
            // Reference tables have no cross-store FKs. Fence PostgreSQL insertion
            // throughout the MySQL retirement and PostgreSQL completion.
            $result = $pg->transaction(function () use ($assetUuid, $sourceSha256, $bindingSha256, $manifestSha256, $idempotencyKey, $callerHash, $lease, $pg, $legacyRetirementIntent, &$runId) {
                $this->lockPostgresqlReferences($pg);
                $run = DB::transaction(function () use ($assetUuid, $sourceSha256, $bindingSha256, $manifestSha256, $idempotencyKey, $callerHash, $lease, $legacyRetirementIntent, &$runId) {
                    $registry = DB::table('file_registry')->where('asset_uuid', $assetUuid)->lockForUpdate()->first();
                    if (! $registry) {
                        throw new RuntimeException('current_source_unavailable');
                    }
                    $existing = DB::table('file_registry_face_reconciliation_runs')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                    if ($existing) {
                        foreach (['asset_uuid' => $assetUuid, 'source_sha256' => $sourceSha256,
                            'binding_sha256' => $bindingSha256, 'manifest_sha256' => $manifestSha256] as $key => $value) {
                            if ($value !== $existing->$key) {
                                throw new RuntimeException('reconciliation_receipt_conflict');
                            }
                        }
                        $manifest = json_decode($existing->manifest_json, true, 512, JSON_THROW_ON_ERROR);
                        if (($manifest['legacy_retirement_intent'] ?? null) !== $legacyRetirementIntent
                            || $this->digest($manifest) !== $manifestSha256) {
                            throw new RuntimeException('reconciliation_manifest_changed');
                        }
                        if ($existing->stage === 'complete') {
                            return $existing;
                        }
                        if ($existing->lease_expires_at !== null && strtotime($existing->lease_expires_at) > time()) {
                            throw new RuntimeException('reconciliation_lease_active');
                        }
                        if ($existing->stage !== 'mysql_retired') {
                            throw new RuntimeException('reconciliation_receipt_held');
                        }
                        if ((int) $existing->attempts >= max(1, (int) config('file_registry.face_generation.max_attempts', 8))) {
                            throw new RuntimeException('reconciliation_attempt_limit');
                        }
                        $currentPlan = $this->sourcePlan($assetUuid);
                        if ($currentPlan['source_sha256'] !== $sourceSha256 || $currentPlan['binding_sha256'] !== $bindingSha256) {
                            throw new RuntimeException('source_binding_changed');
                        }
                        $manifest = json_decode($existing->manifest_json, true, 512, JSON_THROW_ON_ERROR);
                        if ($this->digest($manifest) !== $manifestSha256) {
                            throw new RuntimeException('reconciliation_manifest_changed');
                        }
                        $this->assertRetiredMysql($manifest);
                        $this->assertReferencesAbsent($manifest, true);
                        DB::table('file_registry_face_reconciliation_runs')->where('id', $existing->id)->update([
                            'lease_token' => $lease, 'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                            'attempts' => $existing->attempts + 1, 'updated_at' => now()]);
                        $runId = $existing->id;

                        return DB::table('file_registry_face_reconciliation_runs')->where('id', $existing->id)->first();
                    }
                    if (DB::table('file_registry_face_reconciliation_runs')->where('file_registry_id', $registry->id)
                        ->where('stage', '!=', 'complete')->exists()) {
                        throw new RuntimeException('reconciliation_pending');
                    }
                    $plan = $this->sourcePlan($assetUuid);
                    if ($plan['source_sha256'] !== $sourceSha256 || $plan['binding_sha256'] !== $bindingSha256) {
                        throw new RuntimeException('source_binding_changed');
                    }
                    $manifest = $this->manifest($assetUuid, $plan, true, $legacyRetirementIntent);
                    if ($this->digest($manifest) !== $manifestSha256) {
                        throw new RuntimeException('reconciliation_manifest_changed');
                    }
                    if ($legacyRetirementIntent !== null) {
                        $this->assertSourceBinding($assetUuid, $sourceSha256, $bindingSha256);
                    }
                    $runId = DB::table('file_registry_face_reconciliation_runs')->insertGetId([
                        'run_uuid' => (string) Str::uuid(), 'idempotency_key' => $idempotencyKey,
                        'file_registry_id' => $registry->id, 'asset_uuid' => $assetUuid,
                        'source_sha256' => $sourceSha256, 'binding_sha256' => $bindingSha256,
                        'manifest_sha256' => $manifestSha256, 'manifest_json' => json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                        'caller_binding_sha256' => $callerHash, 'stage' => 'authorized', 'attempts' => 1,
                        'lease_token' => $lease, 'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                        'created_at' => now(), 'updated_at' => now()]);
                    $ids = array_column($manifest['faces'], 'id');
                    $retiredAt = now();
                    $retirement = ['source_current' => 0, 'superseded_at' => $retiredAt];
                    if (array_key_exists('updated_at', $manifest['faces'][0])) {
                        $retirement['updated_at'] = $retiredAt;
                    }
                    $changed = DB::table('file_registry_faces')->whereIn('id', $ids)->where('source_current', 1)
                        ->whereNull('superseded_at')->update($retirement);
                    if ($changed !== count($ids)) {
                        throw new RuntimeException('reconciliation_manifest_changed');
                    }
                    DB::table('file_registry_face_reconciliation_runs')->where('id', $runId)->update(['stage' => 'mysql_retired', 'updated_at' => now()]);

                    return DB::table('file_registry_face_reconciliation_runs')->where('id', $runId)->first();
                });
                if ($run->stage === 'complete') {
                    return ['success' => true, 'state' => 'complete', 'replayed' => true, 'run_uuid' => $run->run_uuid];
                }

                // Initial MySQL retirement is already durable. Reacquire source custody
                // for the separate PG write phase without changing that recovery boundary.
                return DB::transaction(function () use ($run, $pg, $assetUuid, $sourceSha256, $bindingSha256) {
                    $manifest = json_decode($run->manifest_json, true, 512, JSON_THROW_ON_ERROR);
                    $this->faces->assertAutomaticAssignmentSourceResolved((int) $manifest['file_registry_id'], true);
                    $this->assertVectorsUnchanged($manifest);
                    $this->assertReferencesAbsent($manifest, true);
                    $ids = array_column($manifest['vectors'], 'id');
                    $changed = $pg->table('face_embeddings')->whereIn('id', $ids)->where('source_current', true)
                        ->whereNull('superseded_at')->update(['source_current' => false, 'superseded_at' => now()]);
                    if ($pg->table('face_embeddings')->whereIn('id', $ids)->where(function ($query) {
                        $query->where('source_current', true)->orWhereNull('superseded_at');
                    })->exists()) {
                        throw new RuntimeException('reconciliation_vector_changed');
                    }
                    $this->assertSourceBinding($assetUuid, $sourceSha256, $bindingSha256);

                    // PostgreSQL commit must precede final MySQL receipt publication.
                    return ['success' => true, 'state' => 'postgresql_retired', 'run_uuid' => $run->run_uuid];
                });
            });
            if (($result['state'] ?? null) === 'postgresql_retired') {
                $updated = $pg->transaction(function () use ($pg, $assetUuid, $sourceSha256, $bindingSha256, $manifestSha256, $runId, $lease) {
                    $this->lockPostgresqlReferences($pg);

                    return DB::transaction(function () use ($assetUuid, $sourceSha256, $bindingSha256, $manifestSha256, $runId, $lease) {
                        DB::table('file_registry')->where('asset_uuid', $assetUuid)->lockForUpdate()->first();
                        $receipt = DB::table('file_registry_face_reconciliation_runs')->where('id', $runId)->lockForUpdate()->first();
                        if (! $receipt || $receipt->stage !== 'mysql_retired' || $receipt->lease_token !== $lease) {
                            throw new RuntimeException('reconciliation_receipt_changed');
                        }
                        $manifest = json_decode($receipt->manifest_json, true, 512, JSON_THROW_ON_ERROR);
                        if ($receipt->manifest_sha256 !== $manifestSha256 || $this->digest($manifest) !== $manifestSha256) {
                            throw new RuntimeException('reconciliation_manifest_changed');
                        }
                        $this->assertSourceBinding($assetUuid, $sourceSha256, $bindingSha256);
                        $this->assertRetiredMysql($manifest);
                        $this->assertVectorsUnchanged($manifest, true);
                        $this->assertReferencesAbsent($manifest, true);

                        return DB::table('file_registry_face_reconciliation_runs')->where('id', $runId)->where('lease_token', $lease)
                            ->update(['stage' => 'complete', 'failure_code' => null, 'completed_at' => now(), 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
                    });
                });
                if ($updated !== 1) {
                    throw new RuntimeException('reconciliation_receipt_changed');
                }
                $result['state'] = 'complete';
            }

            return $result;
        } catch (\Throwable $e) {
            if ($runId !== null) {
                DB::table('file_registry_face_reconciliation_runs')->where('id', $runId)->where('lease_token', $lease)->where('stage', '!=', 'complete')
                    ->update(['failure_code' => $this->errorCode($e, 'cross_store_retirement_pending'), 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
            }

            return ['success' => false, 'error' => $this->errorCode($e)];
        }
    }

    /** Internal writer boundary: acquire before nested MySQL source/reference locks. */
    public function lockPostgresqlReferences(Connection $connection): void
    {
        if ($connection->getDriverName() === 'pgsql') {
            $timeout = max(100, min(30000, (int) config('file_registry.face_generation.reconciliation_lock_timeout_ms', 5000)));
            $connection->select("SELECT set_config('lock_timeout', ?, true), set_config('statement_timeout', ?, true)", [(string) $timeout, (string) min(60000, $this->leaseSeconds() * 1000)]);
            $connection->statement('LOCK TABLE face_embeddings, face_match_candidates, person_clusters IN SHARE ROW EXCLUSIVE MODE');
        }
    }

    private function assertVectorsUnchanged(array $manifest, bool $requireRetired = false): void
    {
        foreach ($manifest['vectors'] as $vector) {
            $stored = DB::connection('pgsql_rag')->table('face_embeddings')->where('id', $vector['id'])->lockForUpdate()->first();
            $current = $stored ? (array) $stored : [];
            $alreadyRetired = $stored && empty($stored->source_current) && ! empty($stored->superseded_at);
            if ($requireRetired && ! $alreadyRetired) {
                throw new RuntimeException('reconciliation_vector_changed');
            }
            if ($alreadyRetired) {
                unset($current['source_current'], $current['superseded_at']);
                unset($vector['source_current'], $vector['superseded_at']);
            }
            if (! $stored || $current !== $vector) {
                throw new RuntimeException('reconciliation_vector_changed');
            }
        }
    }

    private function assertSourceBinding(string $assetUuid, string $sourceSha256, string $bindingSha256): void
    {
        $plan = $this->sourcePlan($assetUuid);
        if ($plan['source_sha256'] !== $sourceSha256 || $plan['binding_sha256'] !== $bindingSha256) {
            throw new RuntimeException('source_binding_changed');
        }
    }

    private function sourcePlan(string $assetUuid): array
    {
        $plan = $this->faces->planAnonymousFaceGeneration($assetUuid);
        if (! ($plan['success'] ?? false) && ! in_array($plan['hold_reason'] ?? null, ['prior_current_generation_reconciliation_required', 'anonymous_generation_reconciliation_pending'], true)) {
            throw new RuntimeException($plan['error'] ?? $plan['hold_reason'] ?? 'current_source_unavailable');
        }
        foreach (['source_sha256', 'binding_sha256', 'detector_version'] as $key) {
            if (! is_string($plan[$key] ?? null)) {
                throw new RuntimeException('source_binding_unavailable');
            }
        }

        return $plan;
    }

    private function manifest(string $assetUuid, array $plan, bool $lock = false, ?array $legacyRetirementIntent = null): array
    {
        $registry = DB::table('file_registry')->where('asset_uuid', $assetUuid)->first();
        if (! $registry || $registry->status !== 'active') {
            throw new RuntimeException('current_source_unavailable');
        }
        $generation = hash('sha256', json_encode(['schema' => 'plos.anonymous_face_generation.v1',
            'asset_uuid' => strtolower($assetUuid), 'source_sha256' => $plan['source_sha256'],
            'detector_version' => $plan['detector_version']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $query = DB::table('file_registry_faces')->where('file_registry_id', $registry->id)
            ->where('source', 'ai_detection')->where('source_current', 1)->whereNull('superseded_at')
            ->where(function ($query) use ($generation) {
                $query->whereNull('source_generation_key')->orWhere('source_generation_key', '!=', $generation);
            })
            ->orderBy('id')->limit(max(1, (int) config('file_registry.face_generation.max_faces_per_asset', 100)) + 1);
        $faces = $query->when($lock, fn ($query) => $query->lockForUpdate())->get()->map(fn ($row) => (array) $row)->all();
        if ($faces === [] || count($faces) > max(1, (int) config('file_registry.face_generation.max_faces_per_asset', 100))) {
            throw new RuntimeException('reconciliation_cohort_unavailable');
        }
        // A legacy manifest authorizes retirement, never a claim that old rows
        // described these current bytes or used any known coordinate convention.
        $legacy = $legacyRetirementIntent !== null;
        if ($legacy && (! array_reduce($faces, fn ($all, $face) => $all
            && array_key_exists('source_sha256', $face) && $face['source_sha256'] === null
            && array_key_exists('face_region_convention', $face) && $face['face_region_convention'] === null, true)
            || array_column($faces, 'id') !== $legacyRetirementIntent['face_ids'])) {
            throw new RuntimeException('reconciliation_legacy_cohort_changed');
        }
        foreach ($faces as $face) {
            if (! $legacy && (($face['source_sha256'] ?? null) !== $plan['source_sha256'] || ($face['face_region_convention'] ?? null) !== 'normalized_top_left')) {
                throw new RuntimeException('reconciliation_unbound_face');
            }
            foreach (['person_name', 'genealogy_person_id', 'global_identity_uuid', 'global_identity_approval_receipt_id', 'verified', 'hidden', 'favorite', 'cluster_id'] as $key) {
                $safe = match ($key) {
                    'person_name' => is_string($face[$key] ?? null) && trim($face[$key]) === '',
                    'verified', 'hidden', 'favorite' => in_array($face[$key] ?? null, [0, '0', false], true),
                    default => array_key_exists($key, $face) && $face[$key] === null,
                };
                if (! $safe) {
                    throw new RuntimeException('reconciliation_protected_face');
                }
            }
        }
        $pg = DB::connection('pgsql_rag');
        $vectors = $pg->table('face_embeddings')->where(function ($query) use ($registry, $faces) {
            $query->where(function ($query) use ($registry) {
                $query->where('file_registry_id', $registry->id)->where('source_current', true)->whereNull('superseded_at');
            })
                ->orWhereIn('file_registry_face_id', array_column($faces, 'id'));
        })->orderBy('id')
            ->limit(max(1, (int) config('file_registry.face_generation.max_faces_per_asset', 100)) + 1)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->map(fn ($row) => (array) $row)->all();
        if (count($vectors) > max(1, (int) config('file_registry.face_generation.max_faces_per_asset', 100))) {
            throw new RuntimeException('reconciliation_cohort_unavailable');
        }
        $paired = [];
        $faceMap = array_column($faces, null, 'id');
        foreach ($vectors as $vector) {
            $faceId = $vector['file_registry_face_id'] ?? null;
            if (! isset($faceMap[$faceId])) {
                throw new RuntimeException('reconciliation_ambiguous_vector');
            }
            if ((int) $vector['file_registry_id'] !== (int) $registry->id || ! in_array($vector['source_current'] ?? null, [1, '1', true], true) || $vector['superseded_at'] !== null) {
                throw new RuntimeException('reconciliation_ambiguous_vector');
            }
            if (isset($paired[$faceId]) || (! $legacy && $vector['person_cluster_id'] !== null) || $vector['matched_face_id'] !== null || ! in_array($vector['is_representative'] ?? null, [0, '0', false], true)) {
                throw new RuntimeException('reconciliation_protected_vector');
            }
            $face = $faceMap[$faceId];
            if ($legacy ? (! array_key_exists('source_sha256', $vector) || $vector['source_sha256'] !== null
                || ! array_key_exists('face_region_convention', $vector) || $vector['face_region_convention'] !== null)
                : (! is_string($face['source_sha256'] ?? null) || $face['source_sha256'] !== $plan['source_sha256']
                || ($vector['source_sha256'] ?? null) !== $face['source_sha256']
                || ($face['face_region_convention'] ?? null) !== 'normalized_top_left'
                || ($vector['face_region_convention'] ?? null) !== 'normalized_top_left')) {
                throw new RuntimeException('reconciliation_unbound_vector');
            }
            if (! $legacy && ! FaceEmbeddingService::faceRegionsMatchStoragePrecision((object) $face, (object) $vector)) {
                throw new RuntimeException('reconciliation_unbound_vector');
            }
            foreach (['x', 'y', 'w', 'h'] as $axis) {
                if (! is_numeric($face['region_'.$axis] ?? null) || ! is_numeric($vector['region_'.$axis] ?? null)
                    || ($legacy && (! is_finite((float) $face['region_'.$axis]) || ! is_finite((float) $vector['region_'.$axis])))) {
                    throw new RuntimeException('reconciliation_unbound_vector');
                }
            }
            $paired[$faceId] = true;
        }
        $manifest = ['file_registry_id' => $registry->id, 'faces' => $faces, 'vectors' => $vectors];
        if ($legacy) {
            $clusterIds = array_values(array_unique(array_map('intval', array_filter(array_column($vectors, 'person_cluster_id'), fn ($id) => $id !== null))));
            sort($clusterIds);
            if (array_column($vectors, 'id') !== $legacyRetirementIntent['vector_ids'] || $clusterIds !== $legacyRetirementIntent['preserved_cluster_ids']) {
                throw new RuntimeException('reconciliation_legacy_cohort_changed');
            }
            $clusters = $pg->table('person_clusters')->whereIn('id', $clusterIds)->orderBy('id')
                ->when($lock, fn ($query) => $query->lockForUpdate())->get()->map(fn ($row) => (array) $row)->all();
            if (array_column($clusters, 'id') !== $clusterIds) {
                throw new RuntimeException('reconciliation_cluster_changed');
            }
            $manifest['manifest_type'] = 'operator_reviewed_legacy_retirement_v1';
            $manifest['legacy_retirement_intent'] = $legacyRetirementIntent;
            $manifest['preserved_clusters'] = $clusters;
            $manifest['current_source_sha256'] = $plan['source_sha256'];
            $manifest['current_binding_sha256'] = $plan['binding_sha256'];
        }
        if (strlen(json_encode($manifest, JSON_THROW_ON_ERROR)) > max(1024, (int) config('file_registry.face_generation.max_packet_bytes', 4194304))) {
            throw new RuntimeException('reconciliation_manifest_resource_limit');
        }
        $this->assertReferencesAbsent($manifest, $lock);

        return $manifest;
    }

    /** Exact source-index retirement cohort; never supplies operator identity or legacy intent. */
    public function assertSourceIndexRetirementReferences(int $fileRegistryId, array $faceIds, array $vectorIds, bool $lock): void
    {
        $limit = (int) config('file_registry.profile_refresh.max_source_faces');
        if ($fileRegistryId < 1 || $limit < 1 || max(count($faceIds), count($vectorIds)) > $limit
            || array_filter([...$faceIds, ...$vectorIds], fn ($id) => ! is_int($id) || $id < 1)
            || count(array_unique($faceIds)) !== count($faceIds) || count(array_unique($vectorIds)) !== count($vectorIds)) {
            throw new RuntimeException('reconciliation_reference_scope_invalid');
        }
        $this->assertReferencesAbsent(['file_registry_id' => $fileRegistryId,
            'faces' => array_map(fn ($id) => ['id' => $id], $faceIds),
            'vectors' => array_map(fn ($id) => ['id' => $id], $vectorIds)], $lock);
    }

    private function assertReferencesAbsent(array $manifest, bool $lock): void
    {
        // The caller's registry lock serializes against the separately durable assignment claim.
        // Terminal readbacks bypass this writer boundary; unresolved custody never authorizes retirement.
        $this->faces->assertAutomaticAssignmentSourceResolved((int) $manifest['file_registry_id'], $lock);
        $mediaIds = $this->faces->anonymousSourceMediaIds((int) $manifest['file_registry_id'], $lock);
        $faceIds = array_column($manifest['faces'], 'id');
        $vectorIds = array_column($manifest['vectors'], 'id');
        $queries = [DB::table('genealogy_face_match_queue')->where(fn ($query) => $query->whereIn('file_registry_face_id', $faceIds)->orWhereIn('media_id', $mediaIds)),
            DB::table('person_face_profile_faces')->whereIn('file_registry_face_id', $faceIds)->orWhereIn('face_embedding_id', $vectorIds),
            DB::table('person_face_appearance_profiles')->whereIn('representative_file_registry_face_id', $faceIds)->orWhereIn('representative_face_embedding_id', $vectorIds),
            DB::connection('pgsql_rag')->table('face_match_candidates')->whereIn('face_embedding_id', $vectorIds)->orWhereIn('candidate_face_id', $vectorIds),
            DB::connection('pgsql_rag')->table('person_clusters')->whereIn('representative_face_id', $vectorIds),
            DB::connection('pgsql_rag')->table('face_embeddings')->whereIn('matched_face_id', $vectorIds)];
        if (($manifest['manifest_type'] ?? null) === 'operator_reviewed_legacy_retirement_v1') {
            foreach ($manifest['preserved_clusters'] as $cluster) {
                $current = DB::connection('pgsql_rag')->table('person_clusters')->where('id', $cluster['id'])
                    ->when($lock, fn ($query) => $query->lockForUpdate())->first();
                if (! $current || (array) $current !== $cluster) {
                    throw new RuntimeException('reconciliation_cluster_changed');
                }
            }
        }
        // Retained operator provenance protects references independently of visible face fields/manifest type.
        $queries[] = DB::table('face_identity_provenance')->whereIn('file_registry_face_id', $faceIds);
        // A confirmed photo-region association may have no face FK. Bound
        // detector coordinates do not prove it belongs to a different region.
        $queries[] = DB::table('genealogy_person_media')->whereIn('media_id', $mediaIds)->where('face_confirmed', 1);
        foreach ($queries as $query) {
            if ($query->limit(1)->when($lock, fn ($query) => $query->lockForUpdate())
                ->get([DB::raw('1 AS reference_present')])->isNotEmpty()) {
                throw new RuntimeException('reconciliation_referenced_face');
            }
        }
        // Lock the proposal range as well: proposals do not have face foreign keys.
        $rowLimit = config('file_registry.face_matching.max_total_proof_inputs');
        $byteLimit = config('file_registry.face_generation.max_packet_bytes');
        if (! is_int($rowLimit) || $rowLimit < 1 || $rowLimit === PHP_INT_MAX || ! is_int($byteLimit) || $byteLimit < 1) {
            throw new RuntimeException('reconciliation_reference_resource_limit');
        }
        $lengthSql = match (DB::connection()->getDriverName()) {
            'mysql' => 'OCTET_LENGTH(details)',
            'sqlite' => 'LENGTH(CAST(details AS BLOB))',
            default => throw new RuntimeException('reconciliation_reference_resource_limit'),
        };
        $reviewQuery = DB::table('agent_review_queue')->where('review_type', 'global_face_identity')
            ->orderBy('id')->limit($rowLimit + 1)->when($lock, fn ($query) => $query->lockForUpdate());
        $metadata = (clone $reviewQuery)->get(['id', DB::raw($lengthSql.' AS details_bytes')]);
        if ($metadata->count() > $rowLimit) {
            throw new RuntimeException('reconciliation_reference_resource_limit');
        }
        $bytes = 0;
        foreach ($metadata as $review) {
            $length = filter_var($review->details_bytes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($length === false) {
                throw new RuntimeException('reconciliation_invalid_review_evidence');
            }
            if ($length > $byteLimit - $bytes) {
                throw new RuntimeException('reconciliation_reference_resource_limit');
            }
            $bytes += $length;
        }
        foreach ($metadata as $review) {
            // The SQL length predicate bounds PDO buffering even if an unlocked
            // planning row grows after the metadata snapshot.
            $payload = DB::table('agent_review_queue')->where('review_type', 'global_face_identity')->where('id', $review->id)
                ->whereRaw($lengthSql.' = ?', [(int) $review->details_bytes])
                ->selectRaw('CASE WHEN '.$lengthSql.' <= ? THEN details ELSE NULL END AS details', [(int) $review->details_bytes])
                ->when($lock, fn ($query) => $query->lockForUpdate())->first();
            if ($payload === null || ! is_string($payload->details) || strlen($payload->details) !== (int) $review->details_bytes) {
                throw new RuntimeException('reconciliation_invalid_review_evidence');
            }
            $details = json_decode($payload->details, true);
            if (! is_array($details)) {
                throw new RuntimeException('reconciliation_invalid_review_evidence');
            }
            $decision = $details['global_identity_decision'] ?? null;
            if (is_array($decision) && ($decision['operation'] ?? null) === 'confirm_face'
                && in_array((int) ($decision['face_id'] ?? 0), $faceIds, true)) {
                throw new RuntimeException('reconciliation_referenced_face');
            }
        }
        if (! $lock && (clone $reviewQuery)->get(['id', DB::raw($lengthSql.' AS details_bytes')])->map(fn ($row) => (array) $row)->all()
            !== $metadata->map(fn ($row) => (array) $row)->all()) {
            throw new RuntimeException('reconciliation_invalid_review_evidence');
        }
    }

    private function assertRetiredMysql(array $manifest): void
    {
        foreach ($manifest['faces'] as $face) {
            $stored = DB::table('file_registry_faces')->where('id', $face['id'])->lockForUpdate()->first();
            if (! $stored || ! empty($stored->source_current) || empty($stored->superseded_at)) {
                throw new RuntimeException('reconciliation_manifest_changed');
            }
            $values = (array) $stored;
            // MySQL may advance its automatic audit timestamp during retirement.
            // Allow only the original value or the exact recorded retirement time.
            if (array_key_exists('updated_at', $face)) {
                if ($values['updated_at'] !== $face['updated_at'] && $values['updated_at'] !== $values['superseded_at']) {
                    throw new RuntimeException('reconciliation_manifest_changed');
                }
                unset($values['updated_at'], $face['updated_at']);
            }
            unset($values['source_current'], $values['superseded_at']);
            unset($face['source_current'], $face['superseded_at']);
            if ($values !== $face) {
                throw new RuntimeException('reconciliation_manifest_changed');
            }
        }
    }

    private function legacyRetirementIntent(?array $intent): ?array
    {
        if ($intent === null) {
            return null;
        }
        $keys = array_keys($intent);
        sort($keys);
        if ($keys !== ['face_ids', 'preserved_cluster_ids', 'reason', 'vector_ids']
            || ! is_string($intent['reason']) || trim($intent['reason']) !== $intent['reason']
            || $intent['reason'] === '' || mb_strlen($intent['reason']) > 500
            || preg_match('/[\x00-\x1F\x7F]/u', $intent['reason'])) {
            throw new RuntimeException('reconciliation_legacy_intent_invalid');
        }
        $limit = max(1, (int) config('file_registry.face_preview.max_assets', 5));
        foreach (['face_ids', 'vector_ids', 'preserved_cluster_ids'] as $key) {
            $ids = $intent[$key];
            if (! is_array($ids) || ! array_is_list($ids) || count($ids) > $limit
                || ($key === 'face_ids' && $ids === [])) {
                throw new RuntimeException('reconciliation_legacy_intent_invalid');
            }
            foreach ($ids as $id) {
                if (! is_int($id) || $id < 1) {
                    throw new RuntimeException('reconciliation_legacy_intent_invalid');
                }
            }
            $ordered = array_values(array_unique($ids));
            sort($ordered);
            if ($ordered !== $ids) {
                throw new RuntimeException('reconciliation_legacy_intent_invalid');
            }
        }

        return ['face_ids' => $intent['face_ids'], 'vector_ids' => $intent['vector_ids'],
            'preserved_cluster_ids' => $intent['preserved_cluster_ids'], 'reason' => $intent['reason']];
    }

    private function callerDigest(mixed $caller): string
    {
        $keys = ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256'];
        if (! is_array($caller) || count($caller) !== 5 || array_diff(array_keys($caller), $keys) !== []) {
            throw new RuntimeException('trusted_framework_caller_required');
        }
        foreach (['session_id', 'turn_id', 'action_id'] as $key) {
            if (! is_string($caller[$key]) || $caller[$key] === '' || strlen($caller[$key]) > 255 || preg_match('/[\x00-\x20\x7f]/', $caller[$key])) {
                throw new RuntimeException('trusted_framework_caller_required');
            }
        }
        foreach (['arguments_sha256', 'approval_scope_sha256'] as $key) {
            if (! is_string($caller[$key]) || ! preg_match('/^[a-f0-9]{64}$/D', $caller[$key])) {
                throw new RuntimeException('trusted_framework_caller_required');
            }
        }

        return $this->digest(array_map(fn ($key) => $caller[$key], $keys));
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function errorCode(\Throwable $error, string $fallback = 'reconciliation_evidence_unavailable'): string
    {
        return $error instanceof RuntimeException && preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $error->getMessage())
            ? $error->getMessage() : $fallback;
    }

    private function leaseSeconds(): int
    {
        return max(30, (int) config('file_registry.face_generation.lease_seconds', 300));
    }
}
