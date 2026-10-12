<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use App\Services\UnifiedCli\CliWorkflowExecutionScope;
use App\Support\PgVector;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Refresh one already operator-confirmed face vector without changing identity records. */
class FaceProtectedVectorRefreshService
{
    private const SCHEMA = 'plos.confirmed_face_vector_refresh.v1';

    private const LEDGER = 'file_registry_face_vector_refresh_runs';

    public function __construct(private readonly FaceEmbeddingService $faces) {}

    /** Read-only source, decision, old-vector and selected-region-engine plan. */
    public function planConfirmedFaceVectorRefresh(int $faceEmbeddingId): array
    {
        try {
            $state = $this->currentState($faceEmbeddingId);
            $requiredBudget = $this->requiredBudgetSeconds();
            $timeout = $this->registeredTimeoutMs();
            if ($timeout === null || $timeout < ($requiredBudget + 120) * 1000) {
                throw new RuntimeException('refresh_worker_budget_unavailable');
            }
            if ($state['current_engine_sha256'] === $state['old_engine_sha256']) {
                throw new RuntimeException('vector_already_uses_current_engine');
            }
            $binding = $this->binding($state, $timeout, $requiredBudget);

            return ['success' => true, 'eligible' => true, 'mode' => 'dry_run', 'detector_run' => false,
                'face_embedding_id' => $faceEmbeddingId, 'file_registry_face_id' => $state['face_id'],
                'file_registry_id' => $state['file_registry_id'], 'asset_uuid' => $state['asset_uuid'],
                'source_sha256' => $state['source_sha256'], 'face_region' => $state['region'],
                'old_embedding_sha256' => $state['old_vector_sha256'], 'embedding_model' => $state['embedding_model'],
                'identity_decision_scope' => $state['identity_proof']['decision_scope'],
                'identity_decision_receipt_id' => $state['identity_proof']['receipt_id'],
                'identity_proof_sha256' => $state['identity_proof_sha256'],
                'backup_required' => true,
                'profile_preimage_sha256' => hash('sha256', $this->canonicalJson($state['preimage']['profile_snapshot'])),
                'profile_link_pointer_updates' => count(array_filter($state['preimage']['profile_snapshot']['links'],
                    fn ($link) => (int) ($link['face_embedding_id'] ?? 0) === $faceEmbeddingId)),
                'profile_representative_pointer_updates' => count(array_filter($state['preimage']['profile_snapshot']['profiles'],
                    fn ($profile) => (int) ($profile['representative_face_embedding_id'] ?? 0) === $faceEmbeddingId)),
                'engine_provenance_sha256' => $state['current_engine_sha256'],
                'required_budget_seconds' => $requiredBudget, 'registered_timeout_ms' => $timeout,
                'expected_input_digest' => hash('sha256', $this->canonicalJson($binding)),
                'confirmation_required' => true, 'identity_decisions_performed' => false,
                'mysql_identity_rows_changed' => false];
        } catch (Throwable $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }

            return ['success' => false, 'eligible' => false, 'mode' => 'dry_run', 'detector_run' => false,
                'error_code' => $this->errorCode($error)];
        }
    }

    /** Execute only through the registered, confirmed native CLI framework action. */
    public function executeConfirmedFaceVectorRefresh(array $request): array
    {
        $run = null;
        $directory = null;
        $retainArtifacts = false;
        try {
            $this->assertNativeCaller($request);
            $this->validateRequest($request);
            $prior = $this->priorRunResult($request);
            if ($prior !== null) {
                return $prior;
            }
            $before = $this->currentState($request['face_embedding_id']);
            $requiredBudget = $this->requiredBudgetSeconds();
            $timeout = $this->registeredTimeoutMs();
            if ($timeout === null || $timeout < ($requiredBudget + 120) * 1000) {
                throw new RuntimeException('refresh_worker_budget_unavailable');
            }
            if ($before['current_engine_sha256'] === $before['old_engine_sha256']) {
                throw new RuntimeException('vector_already_uses_current_engine');
            }
            $binding = $this->binding($before, $timeout, $requiredBudget);
            $digest = hash('sha256', $this->canonicalJson($binding));
            if (! hash_equals($digest, $request['expected_input_digest'])) {
                throw new RuntimeException('refresh_input_changed');
            }
            $run = $this->claimRun($request, $before, $digest, $binding);
            if ($run['state'] !== 'new') {
                return $run['result'];
            }
            $this->backupPreimage($run['row'], $before);

            $directory = sys_get_temp_dir().'/plos-confirmed-face-vector-refresh-'.Str::uuid();
            if (! @mkdir($directory, 0700)) {
                throw new RuntimeException('refresh_private_snapshot_unavailable');
            }
            $snapshot = $directory.'/source-image';
            if (file_put_contents($snapshot, $before['source_bytes']) !== strlen($before['source_bytes']) || ! chmod($snapshot, 0600)) {
                throw new RuntimeException('refresh_private_snapshot_unavailable');
            }

            $detection = $this->faces->detectFaces($snapshot, true, $directory, false, false, true, $before['region']);
            $this->assertSelectedRegionDetection($detection, $before);
            $after = $this->currentState($request['face_embedding_id']);
            if (! hash_equals($digest, hash('sha256', $this->canonicalJson($this->binding($after, $timeout, $requiredBudget))))) {
                throw new RuntimeException('refresh_input_changed');
            }
            if ($detection['engine_provenance'] !== $this->detectorEngineProvenance($after['engine'])) {
                throw new RuntimeException('refresh_engine_changed');
            }
            $faceResult = $detection['faces'][0];
            $storedVector = json_decode(PgVector::literal($faceResult['embedding'], 6), true, flags: JSON_THROW_ON_ERROR);
            $outputVectorSha = $this->faces->faceVectorRefreshEmbeddingSha256($storedVector);
            if ($outputVectorSha === null) {
                throw new RuntimeException('refresh_embedding_invalid');
            }
            $provenance = $this->provenance($run['row'], $before, $faceResult, $outputVectorSha);
            $provenanceBytes = $this->canonicalJson($provenance);
            $provenanceSha = hash('sha256', $provenanceBytes);
            DB::table(self::LEDGER)->where('run_uuid', $run['row']->run_uuid)->where('stage', 'detector_running')->update([
                'stage' => 'publishing', 'output_embedding_sha256' => $outputVectorSha,
                'provenance_sha256' => $provenanceSha, 'updated_at' => now(),
            ]);

            $newEmbeddingId = $this->publishVector($request, $run['row'], $before, $faceResult, $provenanceBytes, $provenanceSha);

            return $this->completedResult($run['row']->run_uuid, $request, $before, $newEmbeddingId, false);
        } catch (Throwable $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                $retainArtifacts = true;
                if (is_object($run['row'] ?? null)) {
                    $this->holdRun($run['row']->run_uuid, 'refresh_terminal_unknown');
                }
                throw $error;
            }
            $code = $this->errorCode($error);
            if (is_object($run['row'] ?? null)) {
                $this->holdRun($run['row']->run_uuid, $code);
            }

            return ['success' => false, 'state' => 'held', 'error_code' => $code,
                'run_uuid' => $run['row']->run_uuid ?? null, 'automatic_replay_allowed' => false,
                'identity_decisions_performed' => false, 'mysql_identity_rows_changed' => false];
        } finally {
            if (! $retainArtifacts && $directory !== null && is_dir($directory)) {
                foreach (glob($directory.'/*') ?: [] as $path) {
                    if (is_file($path) || is_link($path)) {
                        @unlink($path);
                    }
                }
                @rmdir($directory);
            }
        }
    }

    private function priorRunResult(array $request): ?array
    {
        $run = DB::table(self::LEDGER)->where('idempotency_key', $request['idempotency_key'])->first();
        if ($run === null) {
            return null;
        }
        if ((int) $run->face_embedding_id !== $request['face_embedding_id']
            || $run->input_digest !== $request['expected_input_digest']) {
            throw new RuntimeException('refresh_idempotency_conflict');
        }
        if (! in_array($run->stage, ['publishing', 'complete'], true)) {
            return ['success' => false, 'state' => 'held', 'error_code' => $run->failure_code ?? 'refresh_outcome_unknown',
                'run_uuid' => $run->run_uuid, 'automatic_replay_allowed' => false,
                'identity_decisions_performed' => false, 'mysql_identity_rows_changed' => false];
        }
        $current = DB::connection('pgsql_rag')->table('face_embeddings')->where('file_registry_face_id', $run->file_registry_face_id)
            ->where('source_current', true)->whereNull('superseded_at')->limit(2)->get(['id', 'generation_provenance']);
        if ($current->count() !== 1) {
            return ['success' => false, 'state' => 'held', 'error_code' => 'refresh_outcome_unknown',
                'run_uuid' => $run->run_uuid, 'automatic_replay_allowed' => false,
                'identity_decisions_performed' => false, 'mysql_identity_rows_changed' => false];
        }
        $provenance = is_string($current[0]->generation_provenance) ? json_decode($current[0]->generation_provenance, true) : null;
        if (($provenance['run_uuid'] ?? null) !== $run->run_uuid) {
            return ['success' => false, 'state' => 'held', 'error_code' => 'refresh_outcome_unknown',
                'run_uuid' => $run->run_uuid, 'automatic_replay_allowed' => false,
                'identity_decisions_performed' => false, 'mysql_identity_rows_changed' => false];
        }
        $before = $this->currentState((int) $current[0]->id, true, true);
        $recovered = $this->recoverPublished($run, $request, $before, $run->stage === 'complete');

        return $recovered ?? ['success' => false, 'state' => 'held', 'error_code' => 'refresh_completed_receipt_conflict',
            'run_uuid' => $run->run_uuid, 'automatic_replay_allowed' => false,
            'identity_decisions_performed' => false, 'mysql_identity_rows_changed' => false];
    }

    private function currentState(int $embeddingId, bool $allowCurrentEngine = false, bool $allowReferencedCurrent = false): array
    {
        if ($embeddingId < 1) {
            throw new RuntimeException('refresh_request_invalid');
        }
        $embedding = DB::connection('pgsql_rag')->selectOne('SELECT id, file_registry_id, file_registry_face_id,
            person_cluster_id, embedding, region_x, region_y, region_w, region_h, crop_path, matched_face_id,
            match_confidence, embedding_model, quality_score, is_representative, source_sha256,
            face_region_convention, source_current, superseded_at, generation_provenance
            FROM face_embeddings WHERE id = ?', [$embeddingId]);
        if (! $embedding || ! $this->isTrue($embedding->source_current ?? null) || $embedding->superseded_at !== null
            || ! is_numeric($embedding->file_registry_face_id ?? null) || (int) $embedding->file_registry_face_id < 1
            || ! is_string($embedding->source_sha256 ?? null) || ! $this->hash($embedding->source_sha256)
            || $embedding->face_region_convention !== 'normalized_top_left'
            || ! Schema::connection('pgsql_rag')->hasColumn('face_embeddings', 'generation_provenance')
            || ! is_array($this->normalizedRegion($embedding))) {
            throw new RuntimeException('refresh_current_vector_unavailable');
        }
        $currents = DB::connection('pgsql_rag')->table('face_embeddings')->where('file_registry_face_id', $embedding->file_registry_face_id)
            ->where('source_current', true)->whereNull('superseded_at')->limit(2)->get(['id']);
        if ($currents->count() !== 1 || (int) $currents[0]->id !== $embeddingId) {
            throw new RuntimeException('refresh_current_vector_ambiguous');
        }
        if (! $allowReferencedCurrent) {
            if (! Schema::hasColumns(self::LEDGER, ['preimage_json', 'input_binding_json', 'backup_relative_path', 'backup_sha256'])) {
                throw new RuntimeException('refresh_profile_ledger_unavailable');
            }
            $this->assertUnreferencedVector($embedding);
        }
        $vectorSha = $this->faces->faceVectorRefreshEmbeddingSha256($embedding->embedding);
        if ($vectorSha === null || $embedding->embedding_model !== 'dlib_face_recognition_resnet_model_v1') {
            throw new RuntimeException('refresh_current_vector_invalid');
        }
        $face = DB::selectOne('SELECT face.*, registry.asset_uuid, registry.current_path,
            registry.status AS file_status, registry.updated_at AS registry_revision
            FROM file_registry_faces face JOIN file_registry registry ON registry.id = face.file_registry_id
            WHERE face.id = ?', [(int) $embedding->file_registry_face_id]);
        if (! $face || (int) $face->file_registry_id !== (int) $embedding->file_registry_id
            || $face->file_status !== 'active' || ! $this->isTrue($face->source_current)
            || $face->superseded_at !== null || $this->isTrue($face->hidden)
            || ($face->cluster_id ?? null) !== null
            || $face->source_sha256 !== $embedding->source_sha256 || $face->face_region_convention !== 'normalized_top_left'
            || ! FaceEmbeddingService::faceRegionsMatchStoragePrecision($face, $embedding)) {
            throw new RuntimeException('refresh_face_source_unavailable');
        }
        $proof = app(FaceLinkBridgeService::class)->validateFaceSourceEvidence($face);
        if (($proof['success'] ?? false) !== true || ($proof['source_sha256'] ?? null) !== $embedding->source_sha256
            || ($proof['face_region_convention'] ?? null) !== 'normalized_top_left'
            || ($proof['source_path'] ?? null) !== $face->current_path
            || ! $this->faces->embeddingHasCurrentSourceEvidence($embedding)) {
            throw new RuntimeException('refresh_source_proof_unavailable');
        }
        $identity = $this->operatorIdentityProof($face, $proof['region']);
        $preimage = $allowReferencedCurrent ? null : [
            'registry' => (array) DB::table('file_registry')->where('id', $face->file_registry_id)->first(),
            'face' => (array) DB::table('file_registry_faces')->where('id', $face->id)->first(),
            'vector' => (array) DB::connection('pgsql_rag')->table('face_embeddings')->where('id', $embeddingId)->first(),
            'profile_snapshot' => $this->profileSnapshot($face, $embeddingId),
        ];
        $source = $this->sourceBytes($proof['source_path'], $embedding->source_sha256);
        $engine = $this->faces->faceVectorRefreshEngineProvenance();
        if (($engine['embedding_model'] ?? null) !== $embedding->embedding_model) {
            throw new RuntimeException('refresh_model_contract_changed');
        }
        $oldProvenance = is_string($embedding->generation_provenance ?? null) ? $embedding->generation_provenance : null;
        $oldProvenanceData = $oldProvenance !== null ? json_decode($oldProvenance, true) : null;
        $oldEngineSha = is_array($oldProvenanceData) && is_array($oldProvenanceData['engine_provenance'] ?? null)
            ? hash('sha256', $this->canonicalJson($oldProvenanceData['engine_provenance'])) : null;
        $currentEngineSha = hash('sha256', $this->canonicalJson($engine));
        if (! $allowCurrentEngine && $currentEngineSha === $oldEngineSha) {
            throw new RuntimeException('vector_already_uses_current_engine');
        }

        return ['embedding' => $embedding, 'face' => $face, 'face_id' => (int) $face->id,
            'file_registry_id' => (int) $face->file_registry_id, 'asset_uuid' => strtolower((string) $face->asset_uuid),
            'source_path' => (string) $proof['source_path'], 'source_sha256' => $embedding->source_sha256,
            'source_bytes' => $source['bytes'], 'source_size' => strlen($source['bytes']),
            'source_dimensions' => $source['dimensions'], 'source_path_sha256' => hash('sha256', (string) $face->current_path),
            'registry_revision' => (string) $face->registry_revision,
            'region' => $proof['region'], 'embedding_model' => $embedding->embedding_model,
            'old_vector_sha256' => $vectorSha, 'old_engine_sha256' => $oldEngineSha,
            'old_provenance_sha256' => $oldProvenance === null ? null : hash('sha256', $oldProvenance),
            'identity_proof' => $identity, 'identity_proof_sha256' => hash('sha256', $this->canonicalJson($identity)),
            'engine' => $engine, 'current_engine_sha256' => $currentEngineSha, 'preimage' => $preimage];
    }

    private function operatorIdentityProof(object $face, array $region): array
    {
        if (is_string($face->global_identity_uuid ?? null) && $face->global_identity_uuid !== '') {
            $approved = app(GlobalFaceWritebackApprovalService::class)->approvedFace((int) $face->id);
            if (! $approved || (int) $approved->file_registry_id !== (int) $face->file_registry_id
                || $approved->global_identity_uuid !== $face->global_identity_uuid
                || $approved->source_sha256 !== $face->source_sha256
                || ! FaceEmbeddingService::faceRegionsMatchStoragePrecision($face, $approved)) {
                throw new RuntimeException('refresh_operator_receipt_unavailable');
            }
            $approvalId = (string) $approved->global_identity_approval_receipt_id;
            $nameId = (string) $approved->name_approval_receipt_id;
            $approval = DB::table('review_desk_receipts')->where('id', $approvalId)->where('state', 'completed')->first();
            $name = DB::table('review_desk_receipts')->where('id', $nameId)->where('state', 'completed')->first();
            if (! $approval || ! $name || ! Str::isUuid($approvalId) || ! Str::isUuid($nameId)) {
                throw new RuntimeException('refresh_operator_receipt_unavailable');
            }

            return ['kind' => 'global_face_identity', 'decision_scope' => 'confirm_current_face_global_identity',
                'receipt_id' => $approvalId, 'name_receipt_id' => $nameId,
                'receipt_sha256' => hash('sha256', (string) $approval->payload),
                'name_receipt_sha256' => hash('sha256', (string) $name->payload),
                'identity_uuid' => $face->global_identity_uuid, 'face_id' => (int) $face->id,
                'source_sha256' => $face->source_sha256, 'region' => $region];
        }
        if (($face->global_identity_approval_receipt_id ?? null) !== null
            || ! $this->isTrue($face->verified) || ! is_numeric($face->genealogy_person_id ?? null)
            || (int) $face->genealogy_person_id < 1 || trim((string) ($face->person_name ?? '')) === '') {
            throw new RuntimeException('refresh_operator_receipt_unavailable');
        }
        $queues = DB::table('genealogy_face_match_queue')->where('file_registry_face_id', $face->id)
            ->orderByDesc('id')->limit(17)->get();
        if ($queues->isEmpty() || $queues->count() > 16) {
            throw new RuntimeException('refresh_operator_receipt_unavailable');
        }
        $queue = $queues->first();
        if ($queue->status !== 'approved' || (int) ($queue->suggested_person_id ?? 0) !== (int) $face->genealogy_person_id
            || trim((string) ($queue->match_type ?? '')) === 'no_match') {
            throw new RuntimeException('refresh_operator_receipt_unavailable');
        }
        $details = is_string($queue->match_details ?? null) ? json_decode($queue->match_details, true) : ($queue->match_details ?? null);
        $confirmations = is_array($details) ? ($details['operator_confirmations'] ?? null) : null;
        if (! is_array($details) || ! is_array($confirmations) || ! array_is_list($confirmations)
            || $confirmations === [] || count($confirmations) > 32) {
            throw new RuntimeException('refresh_operator_receipt_unavailable');
        }
        $confirmation = $confirmations[array_key_last($confirmations)];
        if (! is_array($confirmation) || ($confirmation['decision_scope'] ?? null) !== 'confirm_face_identity_link'
            || (int) ($confirmation['person_id'] ?? 0) !== (int) $face->genealogy_person_id
            || (int) ($confirmation['file_registry_face_id'] ?? 0) !== (int) $face->id
            || (int) ($confirmation['media_id'] ?? 0) !== (int) ($queue->media_id ?? 0)
            || ($confirmation['source_sha256'] ?? null) !== $face->source_sha256
            || ($confirmation['face_region_convention'] ?? null) !== 'normalized_top_left'
            || ! $this->sameRegion($confirmation['region'] ?? null, $region)
            || trim((string) ($confirmation['person_name'] ?? '')) === ''
            || trim((string) ($confirmation['reviewer_user_id'] ?? '')) === ''
            || $confirmation['person_name'] !== $face->person_name) {
            throw new RuntimeException('refresh_operator_receipt_unavailable');
        }
        foreach (array_map('intval', is_array($details['rejected_candidate_ids'] ?? null) ? $details['rejected_candidate_ids'] : []) as $rejectedId) {
            if ($rejectedId === (int) $face->genealogy_person_id) {
                throw new RuntimeException('refresh_operator_decision_conflict');
            }
        }
        foreach (is_array($details['candidate_decisions'] ?? null) ? $details['candidate_decisions'] : [] as $decision) {
            if (is_array($decision) && (int) ($decision['genealogy_person_id'] ?? $decision['person_id'] ?? 0) === (int) $face->genealogy_person_id
                && in_array($decision['action'] ?? null, ['not_this_person', 'reject', 'rejected'], true)) {
                throw new RuntimeException('refresh_operator_decision_conflict');
            }
        }
        $faceType = app(ReviewTypeRegistryService::class)->getType('face');
        if (! is_array($faceType) || ($faceType['source_table'] ?? null) !== 'genealogy_face_match_queue') {
            throw new RuntimeException('refresh_operator_receipt_unavailable');
        }
        $sourceConnection = ($faceType['source_connection'] ?? null) === 'pgsql_rag' ? 'pgsql_rag' : 'default';
        $sourceKey = $sourceConnection.':'.$faceType['source_table'];
        $reference = DB::table('review_desk_references')->where('source', $sourceKey)->where('source_id', (string) $queue->id)
            ->where('review_type', 'face')->orderByDesc('generation')->limit(2)->get();
        $currentReference = $reference->first();
        if ($reference->count() > 1 && (int) $reference[0]->generation === (int) $reference[1]->generation) {
            throw new RuntimeException('refresh_operator_receipt_ambiguous');
        }
        if (! $currentReference || $currentReference->status !== 'history' || $currentReference->source_state !== 'approved') {
            throw new RuntimeException('refresh_operator_receipt_unavailable');
        }
        $receipts = DB::table('review_desk_receipts')->where('reference', $currentReference->reference)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(2)->get();
        $receipt = $receipts->first();
        $payload = $receipt ? json_decode((string) $receipt->payload, true) : null;
        if ($receipts->count() > 1 && $receipts[0]->created_at === $receipts[1]->created_at && $receipts[0]->id === $receipts[1]->id) {
            throw new RuntimeException('refresh_operator_receipt_ambiguous');
        }
        if (! $receipt || $receipt->state !== 'completed' || ! in_array($receipt->action, ['approve', 'link'], true)
            || ! is_array($payload) || ($payload['success'] ?? null) !== true || ($payload['decision_recorded'] ?? null) !== true
            || ($payload['effect_status'] ?? null) !== 'completed' || ($payload['after'] ?? null) !== 'approved'
            || ($payload['reference'] ?? null) !== $currentReference->reference
            || ($payload['decision_scope'] ?? null) !== 'confirm_face_identity_link'
            || (int) ($payload['person_id'] ?? 0) !== (int) $face->genealogy_person_id) {
            throw new RuntimeException('refresh_operator_receipt_unavailable');
        }

        return ['kind' => 'face_review_confirmation', 'decision_scope' => 'confirm_face_identity_link',
            'receipt_id' => (string) $receipt->id, 'reference' => (string) $currentReference->reference,
            'queue_id' => (int) $queue->id, 'person_id' => (int) $face->genealogy_person_id,
            'receipt_sha256' => hash('sha256', (string) $receipt->payload),
            'confirmation_sha256' => hash('sha256', $this->canonicalJson($confirmation)),
            'source_sha256' => $face->source_sha256, 'region' => $region];
    }

    private function assertUnreferencedVector(object $embedding, bool $lockReferenceRanges = false): void
    {
        foreach ([['person_cluster_id', 'is_representative', 'match_confidence', 'quality_score'], ['matched_face_id']] as $group) {
            foreach ($group as $column) {
                if (($embedding->{$column} ?? null) !== null && ! in_array($column, ['is_representative'], true)) {
                    throw new RuntimeException('refresh_vector_relationship_unproven');
                }
                if ($column === 'is_representative' && $this->isTrue($embedding->{$column} ?? false)) {
                    throw new RuntimeException('refresh_vector_relationship_unproven');
                }
            }
        }
        $faceId = (int) $embedding->file_registry_face_id;
        $vectorId = (int) $embedding->id;
        if (! Schema::hasTable('person_face_profile_faces') || ! Schema::hasTable('person_face_appearance_profiles')
            || ! Schema::connection('pgsql_rag')->hasTable('face_match_candidates')
            || ! Schema::connection('pgsql_rag')->hasTable('person_clusters')) {
            throw new RuntimeException('refresh_vector_reference_inventory_unavailable');
        }
        if ($lockReferenceRanges) {
            // Indexed locking reads fence profile inserts or retargets while the
            // cross-database vector row is replaced under the MySQL face lock.
            $profileVectorRefs = DB::table('person_face_profile_faces')->where('face_embedding_id', $vectorId)
                ->lockForUpdate()->get(['id']);
            $profileFaceRefs = DB::table('person_face_profile_faces')->where('file_registry_face_id', $faceId)
                ->lockForUpdate()->get(['id']);
            $appearanceVectorRefs = DB::table('person_face_appearance_profiles')->where('representative_face_embedding_id', $vectorId)
                ->lockForUpdate()->get(['id']);
            $appearanceFaceRefs = DB::table('person_face_appearance_profiles')->where('representative_file_registry_face_id', $faceId)
                ->lockForUpdate()->get(['id']);
        }
        if (DB::connection('pgsql_rag')->table('face_match_candidates')->where('face_embedding_id', $vectorId)
            ->orWhere('candidate_face_id', $vectorId)->exists()
            || DB::connection('pgsql_rag')->table('person_clusters')->where('representative_face_id', $vectorId)->exists()
            || DB::connection('pgsql_rag')->table('face_embeddings')->where('matched_face_id', $vectorId)->exists()) {
            throw new RuntimeException('refresh_vector_relationship_unproven');
        }
    }

    /** Full projection/owner preimages, with independent indexed reference locks. */
    private function profileSnapshot(object $face, int $oldId, bool $lock = false, ?int $newId = null): array
    {
        $rows = function ($query) use ($lock): array {
            $limit = (int) config('file_registry.profile_refresh.max_old_links');
            if ($limit < 1 || $limit > 30000) {
                throw new RuntimeException('refresh_profile_bounds_unavailable');
            }
            if ($lock) {
                $query->lockForUpdate();
            }
            $key = match ($query->from) {
                'review_desk_references' => 'reference', 'global_face_identities' => 'identity_uuid', default => 'id',
            };
            $result = $query->orderBy($key)->limit($limit + 1)->get()->map(fn ($row) => (array) $row)->all();
            if (count($result) > $limit) {
                throw new RuntimeException('refresh_profile_scope_too_large');
            }

            return $result;
        };
        $referenced = [];
        foreach (['person_face_profile_faces' => ['face_embedding_id', 'file_registry_face_id'],
            'person_face_appearance_profiles' => ['representative_face_embedding_id', 'representative_file_registry_face_id']] as $table => [$vectorColumn, $faceColumn]) {
            $found = [];
            foreach ([$oldId, ...($newId === null ? [] : [$newId])] as $vectorId) {
                foreach ($rows(DB::table($table)->where($vectorColumn, $vectorId)) as $row) {
                    $found[$row['id']] = $row;
                }
            }
            foreach ($rows(DB::table($table)->where($faceColumn, $face->id)) as $row) {
                $found[$row['id']] = $row;
            }
            foreach ($found as $row) {
                if ((int) ($row[$faceColumn] ?? 0) !== (int) $face->id
                    || ! in_array((int) ($row[$vectorColumn] ?? 0), [$oldId, $newId], true)) {
                    throw new RuntimeException('refresh_profile_pointer_unproven');
                }
            }
            ksort($found);
            $referenced[$table] = array_values($found);
        }
        $profileIds = array_values(array_unique([...array_column($referenced['person_face_appearance_profiles'], 'id'),
            ...array_column($referenced['person_face_profile_faces'], 'profile_id')]));
        $profiles = $rows(DB::table('person_face_appearance_profiles')->whereIn('id', $profileIds));
        if (count($profiles) !== count($profileIds)) {
            throw new RuntimeException('refresh_profile_owner_unavailable');
        }
        $links = $rows(DB::table('person_face_profile_faces')->whereIn('profile_id', $profileIds));
        $faceIds = array_values(array_unique([(int) $face->id, ...array_map('intval', array_column($links, 'file_registry_face_id')),
            ...array_filter(array_map('intval', array_column($profiles, 'representative_file_registry_face_id')))]));
        if (count($faceIds) > (int) config('file_registry.profile_refresh.max_source_faces')) {
            throw new RuntimeException('refresh_profile_scope_too_large');
        }
        $faces = $rows(DB::table('file_registry_faces')->whereIn('id', $faceIds));
        if (count($faces) !== count($faceIds)) {
            throw new RuntimeException('refresh_profile_owner_unavailable');
        }
        $byFace = array_column($faces, null, 'id');
        foreach ($links as $link) {
            if ((int) $link['file_registry_id'] !== (int) $byFace[$link['file_registry_face_id']]['file_registry_id']) {
                throw new RuntimeException('refresh_profile_pointer_unproven');
            }
        }
        $proofs = [];
        $receiptIds = [];
        $queueIds = [];
        $uuids = [];
        foreach ($faces as $member) {
            $registry = DB::table('file_registry')->where('id', $member['file_registry_id'])->first();
            $evidence = $registry ? app(FaceLinkBridgeService::class)->validateFaceSourceEvidence((object) ($member + [
                'file_status' => $registry->status, 'current_path' => $registry->current_path])) : null;
            if (($evidence['success'] ?? false) !== true) {
                throw new RuntimeException('refresh_profile_source_unavailable');
            }
            $proof = $this->operatorIdentityProof((object) $member, $evidence['region']);
            $proofs[$member['id']] = $proof;
            $receiptIds[] = $proof['receipt_id'];
            if (isset($proof['name_receipt_id'])) {
                $receiptIds[] = $proof['name_receipt_id'];
                $uuids[] = $proof['identity_uuid'];
            }
            if (isset($proof['queue_id'])) {
                $queueIds[] = $proof['queue_id'];
            }
        }
        $memberships = [];
        foreach ($profiles as $profile) {
            $members = array_filter($faces, fn ($member) => in_array((int) $member['id'],
                [...array_map('intval', array_column(array_filter($links, fn ($link) => (int) $link['profile_id'] === (int) $profile['id']), 'file_registry_face_id')),
                    (int) ($profile['representative_file_registry_face_id'] ?? 0)], true));
            if ($members === [] || ! in_array($profile['identity_type'], ['name', 'genealogy_person'], true)) {
                throw new RuntimeException('refresh_profile_owner_unproven');
            }
            foreach ($members as $member) {
                $proof = $proofs[$member['id']];
                if ($profile['identity_type'] === 'name') {
                    if (! isset($face->global_identity_uuid) || ($proof['identity_uuid'] ?? null) !== $face->global_identity_uuid
                        || ($profile['tree_id'] ?? null) !== null || ($profile['genealogy_person_id'] ?? null) !== null
                        || ! str_starts_with((string) $profile['identity_key'], 'name:')) {
                        throw new RuntimeException('refresh_profile_owner_unproven');
                    }
                } else {
                    $personId = (int) ($profile['genealogy_person_id'] ?? 0);
                    $treeId = (int) ($profile['tree_id'] ?? 0);
                    if ($personId < 1 || $treeId < 1 || $profile['identity_key'] !== 'person:'.$personId
                        || ! DB::table('genealogy_persons')->where('id', $personId)->where('tree_id', $treeId)->exists()) {
                        throw new RuntimeException('refresh_profile_owner_unproven');
                    }
                    if (isset($proof['identity_uuid'])) {
                        $membership = app(GlobalFaceWritebackApprovalService::class)
                            ->approvedGenealogyMembership($proof['identity_uuid'], $treeId, $personId);
                        if ($membership === null) {
                            throw new RuntimeException('refresh_profile_membership_unproven');
                        }
                        if ($lock) {
                            $lockedMemberships = DB::table('global_face_identity_genealogy_links')->where('tree_id', $treeId)
                                ->where('genealogy_person_id', $personId)->lockForUpdate()->get();
                            if ($lockedMemberships->count() !== 1
                                || $this->canonicalJson((array) $lockedMemberships[0]) !== $this->canonicalJson((array) $membership)) {
                                throw new RuntimeException('refresh_profile_membership_unproven');
                            }
                        }
                        $memberships[$membership->id] = (array) $membership;
                        $receiptIds[] = $membership->approval_receipt_id;
                    } elseif (($proof['person_id'] ?? null) !== $personId) {
                        throw new RuntimeException('refresh_profile_owner_unproven');
                    }
                }
            }
        }
        ksort($memberships);
        $receipts = $rows(DB::table('review_desk_receipts')->whereIn('id', array_unique($receiptIds)));
        $receiptReferences = array_filter(array_column($receipts, 'reference'));
        if ($receiptReferences !== []) {
            $receipts = $rows(DB::table('review_desk_receipts')->whereIn('id', array_unique($receiptIds))
                ->orWhereIn('reference', $receiptReferences));
        }
        $references = Schema::hasTable('review_desk_references') ? $rows(DB::table('review_desk_references')
            ->whereIn('reference', array_filter(array_column($receipts, 'reference')))) : [];
        $queues = $queueIds === [] ? [] : $rows(DB::table('genealogy_face_match_queue')->whereIn('id', array_unique($queueIds)));
        $agentIds = array_filter(array_map(fn ($ref) => ($ref['source'] ?? null) === 'default:agent_review_queue' ? $ref['source_id'] : null, $references));
        $agents = $agentIds === [] ? [] : $rows(DB::table('agent_review_queue')->whereIn('id', $agentIds));

        return ['profiles' => $profiles, 'links' => $links, 'faces' => $faces, 'proofs' => $proofs,
            'registries' => $rows(DB::table('file_registry')->whereIn('id', array_unique(array_column($faces, 'file_registry_id')))),
            'identities' => $uuids === [] ? [] : $rows(DB::table('global_face_identities')->whereIn('identity_uuid', array_unique($uuids))),
            'memberships' => array_values($memberships), 'receipts' => $receipts, 'references' => $references,
            'queues' => $queues, 'agents' => $agents,
            'other_vectors' => $rows(DB::connection('pgsql_rag')->table('face_embeddings')->whereIn('file_registry_face_id', $faceIds)
                ->where('file_registry_face_id', '!=', $face->id))];
    }

    private function retargetedProfileSnapshot(object $run, int $newId, array $snapshot): array
    {
        $expected = $snapshot;
        foreach (['links' => ['face_embedding_id', 'file_registry_face_id'],
            'profiles' => ['representative_face_embedding_id', 'representative_file_registry_face_id']] as $key => [$pointer, $faceColumn]) {
            foreach ($expected[$key] as &$row) {
                if ((int) ($row[$faceColumn] ?? 0) === (int) $run->file_registry_face_id
                    && (int) ($row[$pointer] ?? 0) === (int) $run->face_embedding_id) {
                    $row[$pointer] = $newId;
                }
            }
            unset($row);
        }

        return $expected;
    }

    private function retargetProfilePointers(object $run, int $newId, array $state, bool $completed = false): void
    {
        $preimage = $this->assertPreimageBackup($run, $state['source_sha256']);
        $old = $preimage['profile_snapshot'];
        $expected = $this->retargetedProfileSnapshot($run, $newId, $old);
        $current = $this->profileSnapshot($state['face'], (int) $run->face_embedding_id, true, $newId);
        if ($this->canonicalJson($current) === $this->canonicalJson($expected)) {
            return;
        }
        if ($completed || $this->canonicalJson($current) !== $this->canonicalJson($old)) {
            throw new RuntimeException('refresh_profile_snapshot_changed');
        }
        foreach (['links' => ['person_face_profile_faces', 'face_embedding_id'],
            'profiles' => ['person_face_appearance_profiles', 'representative_face_embedding_id']] as $key => [$table, $pointer]) {
            foreach ($old[$key] as $index => $row) {
                if ($row[$pointer] === $expected[$key][$index][$pointer]) {
                    continue;
                }
                $updates = [$pointer => $newId];
                // The ledger records the technical change; preserve projection timestamps.
                if (array_key_exists('updated_at', $row)) {
                    $updates['updated_at'] = $row['updated_at'];
                }
                if (DB::table($table)->where('id', $row['id'])->where($pointer, $run->face_embedding_id)->update($updates) !== 1) {
                    throw new RuntimeException('refresh_profile_pointer_changed');
                }
            }
        }
        if ($this->canonicalJson($this->profileSnapshot($state['face'], (int) $run->face_embedding_id, true, $newId))
            !== $this->canonicalJson($expected)) {
            throw new RuntimeException('refresh_profile_readback_unverified');
        }
    }

    private function backupDirectory(string $runUuid, bool $create): string
    {
        $root = realpath(storage_path());
        $relative = config('file_registry.confirmed_vector_refresh.private_directory');
        if (! Str::isUuid($runUuid) || $root === false || ! is_string($relative) || ! str_starts_with($relative, 'app/private/')
            || str_contains($relative, '\\') || str_contains($relative, "\0") || array_intersect(['', '.', '..'], explode('/', $relative))) {
            throw new RuntimeException('refresh_backup_unavailable');
        }
        $directory = $root;
        foreach ([...explode('/', $relative), $runUuid] as $part) {
            $directory .= '/'.$part;
            if (is_link($directory) || (file_exists($directory) && ! is_dir($directory))) {
                throw new RuntimeException('refresh_backup_unavailable');
            }
            if ($create && ! is_dir($directory) && ! mkdir($directory, 0700)) {
                throw new RuntimeException('refresh_backup_unavailable');
            }
            if ($create) {
                $parent = @fopen(dirname($directory), 'r');
                if ($parent === false) {
                    throw new RuntimeException('refresh_backup_unavailable');
                }
                try {
                    if (! fsync($parent)) {
                        throw new RuntimeException('refresh_backup_unavailable');
                    }
                } finally {
                    fclose($parent);
                }
            }
        }
        if (! is_dir($directory) || ($create ? ! chmod($directory, 0700) : (fileperms($directory) & 0777) !== 0700)) {
            throw new RuntimeException('refresh_backup_unavailable');
        }

        return $directory;
    }

    protected function backupPreimage(object $run, array $state): void
    {
        if (! is_string($run->preimage_json ?? null) || $run->preimage_json !== $this->canonicalJson($state['preimage'])
            || strlen($run->preimage_json) + strlen($state['source_bytes']) > (int) config('file_registry.profile_refresh.max_backup_bytes')) {
            throw new RuntimeException('refresh_backup_bounds_unavailable');
        }
        $directory = $this->backupDirectory($run->run_uuid, true);
        foreach (['preimage.json' => $run->preimage_json, 'source-image' => $state['source_bytes']] as $name => $bytes) {
            $path = $directory.'/'.$name;
            $handle = @fopen($path, 'x+b');
            if ($handle === false) {
                throw new RuntimeException('refresh_backup_unavailable');
            }
            try {
                if (! chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                    throw new RuntimeException('refresh_backup_unavailable');
                }
            } finally {
                fclose($handle);
            }
            clearstatcache(true, $path);
            if ((fileperms($path) & 0777) !== 0600 || hash_file('sha256', $path) !== hash('sha256', $bytes)) {
                throw new RuntimeException('refresh_backup_unverified');
            }
        }
        $handle = @fopen($directory, 'r');
        if ($handle === false) {
            throw new RuntimeException('refresh_backup_unavailable');
        }
        try {
            if (! fsync($handle)) {
                throw new RuntimeException('refresh_backup_unavailable');
            }
        } finally {
            fclose($handle);
        }
        $updated = DB::table(self::LEDGER)->where('run_uuid', $run->run_uuid)->where('stage', 'detector_running')
            ->where('preimage_json', $run->preimage_json)->whereNull('backup_sha256')->update([
                'backup_relative_path' => config('file_registry.confirmed_vector_refresh.private_directory').'/'.$run->run_uuid,
                'backup_sha256' => hash('sha256', $run->preimage_json), 'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('refresh_backup_unverified');
        }
        $this->assertPreimageBackup(DB::table(self::LEDGER)->where('run_uuid', $run->run_uuid)->first(), $state['source_sha256']);
    }

    private function assertPreimageBackup(object $run, string $sourceSha): array
    {
        $directory = $this->backupDirectory($run->run_uuid, false);
        if (($run->backup_relative_path ?? null) !== config('file_registry.confirmed_vector_refresh.private_directory').'/'.$run->run_uuid
            || ! $this->hash($run->backup_sha256 ?? null) || ! is_string($run->preimage_json ?? null)
            || strlen($run->preimage_json) > (int) config('file_registry.profile_refresh.max_backup_bytes')
            || hash('sha256', $run->preimage_json) !== $run->backup_sha256) {
            throw new RuntimeException('refresh_backup_unverified');
        }
        $binding = is_string($run->input_binding_json ?? null) ? json_decode($run->input_binding_json, true) : null;
        if (! is_array($binding) || hash('sha256', $this->canonicalJson($binding)) !== $run->input_digest
            || ($binding['preimage_sha256'] ?? null) !== $run->backup_sha256) {
            throw new RuntimeException('refresh_backup_unverified');
        }
        foreach (['preimage.json' => $run->backup_sha256, 'source-image' => $sourceSha] as $name => $sha) {
            $path = $directory.'/'.$name;
            clearstatcache(true, $path);
            if (is_link($path) || ! is_file($path) || (fileperms($path) & 0777) !== 0600
                || filesize($path) > (int) config('file_registry.profile_refresh.max_backup_bytes') || hash_file('sha256', $path) !== $sha) {
                throw new RuntimeException('refresh_backup_unverified');
            }
        }
        $preimage = json_decode($run->preimage_json, true, flags: JSON_THROW_ON_ERROR);
        if (($preimage['face']['id'] ?? null) !== (int) $run->file_registry_face_id
            || ($preimage['registry']['id'] ?? null) !== (int) $run->file_registry_id
            || ($preimage['registry']['asset_uuid'] ?? null) !== $run->asset_uuid
            || ($preimage['face']['source_sha256'] ?? null) !== $sourceSha
            || ($preimage['vector']['id'] ?? null) !== (int) $run->face_embedding_id
            || $this->faces->faceVectorRefreshEmbeddingSha256($preimage['vector']['embedding'] ?? null) !== $run->old_vector_sha256
            || ! is_array($preimage['profile_snapshot'] ?? null)) {
            throw new RuntimeException('refresh_backup_unverified');
        }

        return $preimage;
    }

    private function sourceBytes(string $path, string $expectedHash): array
    {
        $root = realpath((string) config('services.nextcloud.data_path', ''));
        $local = app(NextcloudFileApiService::class)->localPath($path);
        $resolved = is_string($local) ? realpath($local) : false;
        if ($root === false || $resolved === false || ! is_file($resolved) || ! is_readable($resolved)
            || ! str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('refresh_source_unavailable');
        }
        $maxBytes = (int) config('file_registry.face_preview.max_source_bytes');
        $maxPixels = (int) config('file_registry.face_preview.max_pixels');
        $maxHeaderPixels = (int) config('metadata_writeback.preview_max_source_pixels');
        $size = @filesize($resolved);
        $bytes = is_int($size) && $size > 0 && $size <= $maxBytes ? @file_get_contents($resolved) : false;
        $dimensions = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if (! is_string($bytes) || strlen($bytes) !== $size || strlen($bytes) > $maxBytes
            || ! hash_equals($expectedHash, hash('sha256', $bytes)) || ! is_array($dimensions)
            || $dimensions[0] < 1 || $dimensions[1] < 1 || $maxHeaderPixels < $dimensions[0] * $dimensions[1]
            || ($maxPixels < $dimensions[0] * $dimensions[1] && ($dimensions[2] ?? null) !== IMAGETYPE_JPEG)
            || ! in_array($dimensions[2] ?? null, $allowedTypes, true)) {
            throw new RuntimeException('refresh_source_resource_limit');
        }

        return ['bytes' => $bytes, 'dimensions' => [(int) $dimensions[0], (int) $dimensions[1]]];
    }

    private function assertSelectedRegionDetection(array $detection, array $before): void
    {
        $engine = $detection['engine_provenance'] ?? null;
        $faces = $detection['faces'] ?? null;
        if (($detection['success'] ?? null) !== true || ! is_array($faces) || ! array_is_list($faces) || count($faces) !== 1
            || ! is_array($engine) || $engine !== $this->detectorEngineProvenance($before['engine'])
            || ($detection['source_sha256'] ?? null) !== $before['source_sha256']
            || ($detection['face_region_convention'] ?? null) !== 'normalized_top_left'
            || ($detection['coordinate_basis'] ?? null) !== 'encoded_pixels'
            || ($detection['image_width'] ?? null) !== $before['source_dimensions'][0]
            || ($detection['image_height'] ?? null) !== $before['source_dimensions'][1]) {
            throw new RuntimeException('refresh_detector_evidence_invalid');
        }
        $face = $faces[0];
        if (! is_array($face) || ($face['source_sha256'] ?? null) !== $before['source_sha256']
            || ($face['face_region_convention'] ?? null) !== 'normalized_top_left'
            || ($face['embedding_model'] ?? null) !== $before['embedding_model']
            || ! is_array($face['embedding'] ?? null) || ! array_is_list($face['embedding']) || count($face['embedding']) !== 128
            || ! $this->sameRegion($face['normalized'] ?? null, $before['region'])
            || $this->faces->faceVectorRefreshEmbeddingSha256($face['embedding']) === null) {
            throw new RuntimeException('refresh_detector_evidence_invalid');
        }
    }

    private function publishVector(array $request, object $run, array $before, array $faceResult, string $provenanceBytes, string $provenanceSha): int
    {
        $pg = DB::connection('pgsql_rag');

        return DB::transaction(function () use ($pg, $request, $run, $before, $faceResult, $provenanceBytes, $provenanceSha): int {
            $currentRun = DB::table(self::LEDGER)->where('run_uuid', $run->run_uuid)->lockForUpdate()->first();
            if (! $currentRun || $currentRun->stage !== 'publishing' || $currentRun->input_digest !== $request['expected_input_digest']) {
                throw new RuntimeException('refresh_receipt_changed');
            }
            $lockedFace = DB::table('file_registry_faces')->where('id', $before['face_id'])->lockForUpdate()->first();
            $lockedRegistry = DB::table('file_registry')->where('id', $before['file_registry_id'])->lockForUpdate()->first();
            if (! $lockedFace || ! $lockedRegistry || $lockedRegistry->status !== 'active'
                || $lockedRegistry->asset_uuid !== $before['asset_uuid'] || $lockedRegistry->current_path !== $before['source_path']
                || (string) $lockedRegistry->updated_at !== $before['registry_revision']
                || ! $this->isTrue($lockedFace->source_current) || $lockedFace->superseded_at !== null
                || $this->isTrue($lockedFace->hidden) || ($lockedFace->cluster_id ?? null) !== null
                || $lockedFace->source_sha256 !== $before['source_sha256']
                || ! FaceEmbeddingService::faceRegionsMatchStoragePrecision($lockedFace, $before['embedding'])) {
                throw new RuntimeException('refresh_input_changed');
            }
            $proof = $this->operatorIdentityProof($lockedFace, $before['region']);
            if (! hash_equals($before['identity_proof_sha256'], hash('sha256', $this->canonicalJson($proof)))) {
                throw new RuntimeException('refresh_operator_receipt_changed');
            }
            $pg->beginTransaction();
            try {
                if ($pg->getDriverName() === 'pgsql') {
                    $timeout = max(100, min(30000, (int) config('file_registry.face_generation.reconciliation_lock_timeout_ms', 5000)));
                    $pg->select("SELECT set_config('lock_timeout', ?, true), set_config('statement_timeout', ?, true)", [(string) $timeout, (string) min(60000, ($this->requiredBudgetSeconds() + 120) * 1000)]);
                    $pg->statement('LOCK TABLE face_embeddings, face_match_candidates, person_clusters IN SHARE ROW EXCLUSIVE MODE');
                }
                $old = $pg->table('face_embeddings')->where('id', $before['embedding']->id)
                    ->where('file_registry_face_id', $before['face_id'])->where('source_current', true)
                    ->whereNull('superseded_at')->lockForUpdate()->first();
                if (! $old || $this->faces->faceVectorRefreshEmbeddingSha256($old->embedding) !== $before['old_vector_sha256']
                    || ! hash_equals($before['current_engine_sha256'], hash('sha256', $this->canonicalJson($before['engine'])))
                    || (int) $old->file_registry_id !== $before['file_registry_id'] || $old->source_sha256 !== $before['source_sha256']
                    || $old->embedding_model !== $before['embedding_model']
                    || ! FaceEmbeddingService::faceRegionsMatchStoragePrecision($lockedFace, $old)) {
                    throw new RuntimeException('refresh_input_changed');
                }
                $currentVectors = $pg->table('face_embeddings')->where('file_registry_face_id', $before['face_id'])
                    ->where('source_current', true)->whereNull('superseded_at')->limit(2)->lockForUpdate()->get(['id']);
                if ($currentVectors->count() !== 1 || (int) $currentVectors[0]->id !== (int) $old->id) {
                    throw new RuntimeException('refresh_current_vector_ambiguous');
                }
                $this->assertUnreferencedVector($old, true);
                $preimage = $this->assertPreimageBackup($currentRun, $before['source_sha256']);
                if ($this->canonicalJson((array) $old) !== $this->canonicalJson($preimage['vector'])) {
                    throw new RuntimeException('refresh_old_vector_changed');
                }
                if ($this->canonicalJson($this->profileSnapshot($lockedFace, (int) $before['embedding']->id, true))
                    !== $this->canonicalJson($before['preimage']['profile_snapshot'])) {
                    throw new RuntimeException('refresh_profile_snapshot_changed');
                }
                $retired = $pg->update('UPDATE face_embeddings SET source_current = FALSE, superseded_at = NOW(), updated_at = NOW()
                    WHERE id = ? AND file_registry_face_id = ? AND source_current = TRUE AND superseded_at IS NULL',
                    [$old->id, $before['face_id']]);
                if ($retired !== 1) {
                    throw new RuntimeException('refresh_old_vector_changed');
                }
                $vector = PgVector::literal($faceResult['embedding'], 6);
                $insert = $pg->select('INSERT INTO face_embeddings (
                    file_registry_id, file_registry_face_id, person_cluster_id, embedding,
                    region_x, region_y, region_w, region_h, crop_path, matched_face_id,
                    match_confidence, embedding_model, quality_score, is_representative,
                    source_sha256, face_region_convention, generation_provenance, source_current, created_at, updated_at
                ) VALUES (?, ?, NULL, ?::vector, ?, ?, ?, ?, NULL, NULL, NULL, ?, NULL, FALSE,
                    ?, \'normalized_top_left\', ?, TRUE, NOW(), NOW()) RETURNING id', [
                    $before['file_registry_id'], $before['face_id'], $vector,
                    $before['region']['x'], $before['region']['y'], $before['region']['w'], $before['region']['h'],
                    $before['embedding_model'], $before['source_sha256'], $provenanceBytes,
                ]);
                $newId = (int) ($insert[0]->id ?? 0);
                if ($newId < 1) {
                    throw new RuntimeException('refresh_vector_publish_unverified');
                }
                $published = $pg->selectOne('SELECT id, file_registry_id, file_registry_face_id, person_cluster_id,
                    embedding, region_x, region_y, region_w, region_h, matched_face_id, match_confidence,
                    embedding_model, quality_score, is_representative, source_sha256, face_region_convention,
                    generation_provenance, source_current, superseded_at FROM face_embeddings WHERE id = ?', [$newId]);
                if (! $published || (int) $published->file_registry_id !== $before['file_registry_id']
                    || (int) $published->file_registry_face_id !== $before['face_id']
                    || $published->person_cluster_id !== null || $published->matched_face_id !== null
                    || $published->match_confidence !== null || $published->quality_score !== null
                    || $this->isTrue($published->is_representative) || $published->source_sha256 !== $before['source_sha256']
                    || $published->face_region_convention !== 'normalized_top_left'
                    || $published->generation_provenance !== $provenanceBytes || ! $this->isTrue($published->source_current)
                    || $published->superseded_at !== null
                    || $this->faces->faceVectorRefreshEmbeddingSha256($published->embedding) !== $currentRun->output_embedding_sha256
                    || ! FaceEmbeddingService::faceRegionsMatchStoragePrecision($lockedFace, $published)
                    || hash('sha256', $this->canonicalJson(json_decode($published->generation_provenance, true, flags: JSON_THROW_ON_ERROR))) !== $provenanceSha) {
                    throw new RuntimeException('refresh_vector_publish_unverified');
                }
                $freshBytes = $this->sourceBytes($before['source_path'], $before['source_sha256']);
                if ($freshBytes['dimensions'] !== $before['source_dimensions']) {
                    throw new RuntimeException('refresh_source_changed');
                }
                $pg->commit();
                $this->retargetProfilePointers($currentRun, $newId, $before);
                $this->completeRun($run->run_uuid, $newId, $before, $currentRun->output_embedding_sha256, $provenanceSha);

                return $newId;
            } catch (Throwable $error) {
                if ($pg->transactionLevel() > 0) {
                    $pg->rollBack();
                }
                throw $error;
            }
        });
    }

    private function claimRun(array $request, array $before, string $digest, array $binding): array
    {
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        $callerSha = hash('sha256', $this->canonicalJson($caller));
        $runUuid = (string) Str::uuid();

        return DB::transaction(function () use ($request, $before, $digest, $binding, $callerSha, $runUuid): array {
            $face = DB::table('file_registry_faces')->where('id', $before['face_id'])->lockForUpdate()->first();
            if (! $face || ! $this->isTrue($face->source_current) || $face->superseded_at !== null
                || $face->source_sha256 !== $before['source_sha256']) {
                throw new RuntimeException('refresh_input_changed');
            }
            $existing = DB::table(self::LEDGER)->where('idempotency_key', $request['idempotency_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->input_digest !== $digest || (int) $existing->face_embedding_id !== $request['face_embedding_id']) {
                    throw new RuntimeException('refresh_idempotency_conflict');
                }
                if ($existing->stage === 'complete') {
                    $recovered = $this->recoverPublished($existing, $request, $before, true);
                    if ($recovered !== null) {
                        return ['state' => 'prior', 'row' => $existing, 'result' => $recovered];
                    }

                    throw new RuntimeException('refresh_completed_receipt_conflict');
                }
                if ($existing->stage === 'publishing') {
                    $recovered = $this->recoverPublished($existing, $request, $before, false);
                    if ($recovered !== null) {
                        return ['state' => 'prior', 'row' => $existing, 'result' => $recovered];
                    }
                }

                return ['state' => 'prior', 'row' => $existing, 'result' => [
                    'success' => false, 'state' => 'held', 'error_code' => $existing->failure_code ?? 'refresh_outcome_unknown',
                    'run_uuid' => $existing->run_uuid, 'automatic_replay_allowed' => false,
                    'identity_decisions_performed' => false, 'mysql_identity_rows_changed' => false]];
            }
            $active = DB::table(self::LEDGER)->where('file_registry_face_id', $before['face_id'])
                ->whereIn('stage', ['detector_running', 'publishing'])->lockForUpdate()->first();
            if ($active !== null) {
                throw new RuntimeException('refresh_prior_outcome_unknown');
            }
            DB::table(self::LEDGER)->insert([
                'run_uuid' => $runUuid, 'idempotency_key' => $request['idempotency_key'],
                'file_registry_id' => $before['file_registry_id'], 'asset_uuid' => $before['asset_uuid'],
                'file_registry_face_id' => $before['face_id'], 'face_embedding_id' => $request['face_embedding_id'],
                'new_embedding_id' => null, 'source_sha256' => $before['source_sha256'],
                'old_vector_sha256' => $before['old_vector_sha256'], 'input_digest' => $digest,
                'identity_proof_sha256' => $before['identity_proof_sha256'],
                'engine_provenance_sha256' => $before['current_engine_sha256'],
                'engine_provenance_json' => $this->canonicalJson($before['engine']),
                'output_embedding_sha256' => null, 'provenance_sha256' => null,
                'caller_binding_sha256' => $callerSha, 'stage' => 'detector_running',
                'preimage_json' => $this->canonicalJson($before['preimage']),
                'input_binding_json' => $this->canonicalJson($binding),
                'failure_code' => null, 'attempts' => 1, 'completed_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['state' => 'new', 'row' => DB::table(self::LEDGER)->where('run_uuid', $runUuid)->first(), 'result' => null];
        });
    }

    /** Read-only bounded, unique completed replacement history and immutable original preimage. */
    public function completedRefreshPreimage(int $oldId, int $currentId): ?array
    {
        try {
            $state = $this->currentState($currentId, true, true);
            $row = DB::connection('pgsql_rag')->table('face_embeddings')->where('id', $currentId)->first();
            $initialVector = (array) $row;
            $limit = max(1, min(256, (int) config('metadata_writeback.max_face_transition_hops', 64)));
            $seen = [];
            for ($hop = 0; $hop < $limit && $row; $hop++) {
                if (isset($seen[$row->id])) {
                    return null;
                }
                $seen[$row->id] = true;
                $provenance = json_decode($row->generation_provenance ?? '', true, flags: JSON_THROW_ON_ERROR);
                $priorId = $provenance['old_face_embedding_id'] ?? null;
                if (! is_int($priorId) || $priorId < 1 || $priorId === (int) $row->id) {
                    return null;
                }
                $runs = DB::table(self::LEDGER)->where('face_embedding_id', $priorId)->limit(2)->get();
                $run = $runs->first();
                if ($runs->count() !== 1 || ! $run || $run->stage !== 'complete' || $run->completed_at === null
                    || $run->failure_code !== null || (int) $run->new_embedding_id !== (int) $row->id
                    || ! $this->hash($run->caller_binding_sha256 ?? null) || ! is_string($run->preimage_json ?? null)
                    || $run->asset_uuid !== $state['asset_uuid'] || $run->source_sha256 !== $state['source_sha256']) {
                    return null;
                }
                $binding = json_decode($run->input_binding_json ?? '', true, flags: JSON_THROW_ON_ERROR);
                if (($binding['schema'] ?? null) !== self::SCHEMA
                    || ($binding['face_embedding_id'] ?? null) !== $priorId
                    || ($binding['file_registry_face_id'] ?? null) !== $state['face_id']
                    || ($binding['file_registry_id'] ?? null) !== $state['file_registry_id']
                    || ($binding['asset_uuid'] ?? null) !== $state['asset_uuid']
                    || ($binding['source_sha256'] ?? null) !== $state['source_sha256']
                    || ($binding['source_path_sha256'] ?? null) !== $state['source_path_sha256']
                    || ($binding['source_dimensions'] ?? null) !== $state['source_dimensions']
                    || ($binding['source_size'] ?? null) !== $state['source_size']
                    || ! $this->sameRegion($binding['face_region'] ?? null, $state['region'])
                    || ($binding['face_region_convention'] ?? null) !== 'normalized_top_left'
                    || ($binding['identity_proof_sha256'] ?? null) !== $state['identity_proof_sha256']
                    || $this->canonicalJson($binding['identity_proof'] ?? null) !== $this->canonicalJson($state['identity_proof'])
                    || ($binding['old_vector_sha256'] ?? null) !== $run->old_vector_sha256
                    || ($binding['engine_provenance_sha256'] ?? null) !== $run->engine_provenance_sha256
                    || $this->canonicalJson($binding['engine_provenance'] ?? null) !== $run->engine_provenance_json
                    || hash('sha256', $run->engine_provenance_json) !== $run->engine_provenance_sha256
                    || ($binding['embedding_model'] ?? null) !== $state['embedding_model']) {
                    return null;
                }
                $hopState = $state;
                // Older producer pins remain immutable; only the final producer must be the installed engine.
                if ($hop > 0) {
                    $hopState['current_engine_sha256'] = $run->engine_provenance_sha256;
                }
                if ($this->publishedVector($run, ['face_embedding_id' => $priorId, 'expected_input_digest' => $run->input_digest],
                    $hopState, $row, $hop > 0) === null) {
                    return null;
                }
                $preimage = $this->assertPreimageBackup($run, $state['source_sha256']);
                $oldProvenance = $preimage['vector']['generation_provenance'] ?? null;
                $oldEngine = is_string($oldProvenance) ? (json_decode($oldProvenance, true, flags: JSON_THROW_ON_ERROR)['engine_provenance'] ?? null) : null;
                $oldEngineSha = is_array($oldEngine) ? hash('sha256', $this->canonicalJson($oldEngine)) : null;
                $oldProvenanceSha = is_string($oldProvenance) ? hash('sha256', $oldProvenance) : null;
                if (($binding['old_engine_sha256'] ?? null) !== $oldEngineSha
                    || ($binding['old_provenance_sha256'] ?? null) !== $oldProvenanceSha
                    || ($provenance['old_engine_provenance_sha256'] ?? null) !== $oldEngineSha
                    || ($provenance['old_generation_provenance_sha256'] ?? null) !== $oldProvenanceSha
                    || ($provenance['source_dimensions'] ?? null) !== $state['source_dimensions']
                    || ! $this->sameRegion($provenance['selected_region'] ?? null, $state['region'])
                    || ($provenance['face_region_convention'] ?? null) !== 'normalized_top_left'
                    || ($provenance['coordinate_basis'] ?? null) !== 'encoded_pixels') {
                    return null;
                }
                $old = DB::connection('pgsql_rag')->table('face_embeddings')->where('id', $priorId)->first();
                if (! $old) {
                    return null;
                }
                $this->assertRetiredVectorUnchanged($old, $preimage['vector']);
                $this->assertUnreferencedVector($old);
                $this->assertUnreferencedVector($row);
                if ($this->canonicalJson((array) DB::table('file_registry_faces')->where('id', $state['face_id'])->first())
                        !== $this->canonicalJson($preimage['face'])
                    || $this->canonicalJson($this->profileSnapshot($state['face'], $priorId, false, $currentId))
                        !== $this->canonicalJson($this->retargetedProfileSnapshot($run, $currentId, $preimage['profile_snapshot']))) {
                    return null;
                }
                if ((array) DB::table(self::LEDGER)->where('run_uuid', $run->run_uuid)->first() !== (array) $run) {
                    return null;
                }
                if ($priorId === $oldId) {
                    $current = DB::connection('pgsql_rag')->table('face_embeddings')->where('id', $currentId)->first();
                    $currents = DB::connection('pgsql_rag')->table('face_embeddings')->where('file_registry_face_id', $state['face_id'])
                        ->where('source_current', true)->whereNull('superseded_at')->limit(2)->get(['id']);
                    if (! $current || $currents->count() !== 1 || (int) $currents[0]->id !== $currentId
                        || $this->canonicalJson((array) $current) !== $this->canonicalJson($initialVector)) {
                        return null;
                    }

                    return $preimage['vector'];
                }
                $row = $old;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function publishedVector(object $run, array $request, array $before, ?object $row = null, bool $retired = false): ?object
    {
        if (($run->stage !== 'publishing' && $run->stage !== 'complete')
            || $run->input_digest !== $request['expected_input_digest']
            || (int) $run->face_embedding_id !== $request['face_embedding_id']
            || $run->identity_proof_sha256 !== $before['identity_proof_sha256']
            || $run->engine_provenance_sha256 !== $before['current_engine_sha256']) {
            return null;
        }
        if ($row === null) {
            $rows = DB::connection('pgsql_rag')->table('face_embeddings')->where('file_registry_face_id', $run->file_registry_face_id)
                ->where('source_current', true)->whereNull('superseded_at')->limit(2)->get();
            if ($rows->count() !== 1) {
                return null;
            }
            $row = $rows[0];
        }
        $old = DB::connection('pgsql_rag')->selectOne('SELECT id, file_registry_id, file_registry_face_id, embedding,
            source_current, superseded_at FROM face_embeddings WHERE id = ?', [(int) $run->face_embedding_id]);
        $provenance = is_string($row->generation_provenance ?? null) ? json_decode($row->generation_provenance, true) : null;
        if (! $old || $this->isTrue($old->source_current) || $old->superseded_at === null
            || (int) $old->file_registry_id !== (int) $run->file_registry_id
            || (int) $old->file_registry_face_id !== (int) $run->file_registry_face_id
            || $this->faces->faceVectorRefreshEmbeddingSha256($old->embedding) !== $run->old_vector_sha256
            || ! is_array($provenance) || ($provenance['schema'] ?? null) !== 'plos.face_vector_technical_refresh.v1'
            || ($provenance['run_uuid'] ?? null) !== $run->run_uuid
            || (int) ($provenance['old_face_embedding_id'] ?? 0) !== (int) $run->face_embedding_id
            || (int) ($provenance['file_registry_id'] ?? 0) !== (int) $run->file_registry_id
            || (int) ($provenance['file_registry_face_id'] ?? 0) !== (int) $run->file_registry_face_id
            || ($provenance['identity_proof_sha256'] ?? null) !== $run->identity_proof_sha256
            || ($provenance['engine_provenance_sha256'] ?? null) !== $run->engine_provenance_sha256
            || ($provenance['engine_provenance'] ?? null) !== json_decode($run->engine_provenance_json, true)
            || ($provenance['old_embedding_float32_sha256'] ?? null) !== $run->old_vector_sha256
            || (int) $row->id !== (int) ($run->new_embedding_id ?? $row->id)
            || $row->source_sha256 !== $run->source_sha256
            || ($retired ? ($this->isTrue($row->source_current ?? null) || $row->superseded_at === null)
                : (! $this->isTrue($row->source_current ?? null) || $row->superseded_at !== null))
            || (int) $row->file_registry_id !== (int) $run->file_registry_id
            || (int) $row->file_registry_face_id !== (int) $run->file_registry_face_id
            || $row->embedding_model !== ($provenance['embedding_model'] ?? null)
            || $row->face_region_convention !== 'normalized_top_left'
            || ! $this->regionMatchesFace($before['face'], $provenance['face_region'] ?? null)
            || ! FaceEmbeddingService::faceRegionsMatchStoragePrecision($before['face'], $row)
            || $this->faces->faceVectorRefreshEmbeddingSha256($row->embedding) !== $run->output_embedding_sha256
            || ($provenance['embedding_float32_sha256'] ?? null) !== $run->output_embedding_sha256
            || hash('sha256', $this->canonicalJson($provenance)) !== $run->provenance_sha256) {
            return null;
        }

        return $row;
    }

    private function recoverPublished(object $run, array $request, array $before, bool $completed): ?array
    {
        $row = $this->publishedVector($run, $request, $before);
        if ($row === null) {
            return null;
        }

        return DB::transaction(function () use ($run, $row, $completed): array {
            $locked = DB::table(self::LEDGER)->where('run_uuid', $run->run_uuid)->lockForUpdate()->first();
            if (! $locked || $locked->stage !== $run->stage || $locked->input_digest !== $run->input_digest
                || $locked->output_embedding_sha256 !== $run->output_embedding_sha256
                || $locked->provenance_sha256 !== $run->provenance_sha256
                || ($locked->preimage_json ?? null) !== ($run->preimage_json ?? null)
                || ($locked->backup_sha256 ?? null) !== ($run->backup_sha256 ?? null)) {
                throw new RuntimeException('refresh_receipt_changed');
            }
            if (($run->preimage_json ?? null) !== null) {
                DB::table('file_registry_faces')->where('id', $run->file_registry_face_id)->lockForUpdate()->first();
                DB::table('file_registry')->where('id', $run->file_registry_id)->lockForUpdate()->first();
                $pg = DB::connection('pgsql_rag');
                $pg->transaction(function () use ($pg, $locked, $run, $row, $completed): void {
                    if ($pg->getDriverName() === 'pgsql') {
                        $timeout = max(100, min(30000, (int) config('file_registry.face_generation.reconciliation_lock_timeout_ms', 5000)));
                        $pg->select("SELECT set_config('lock_timeout', ?, true), set_config('statement_timeout', ?, true)", [(string) $timeout, (string) min(60000, ($this->requiredBudgetSeconds() + 120) * 1000)]);
                        $pg->statement('LOCK TABLE face_embeddings, face_match_candidates, person_clusters IN SHARE ROW EXCLUSIVE MODE');
                    }
                    $fresh = $this->currentState((int) $row->id, true, true);
                    $old = $pg->table('face_embeddings')->where('id', $run->face_embedding_id)->lockForUpdate()->first();
                    $packet = json_decode($fresh['embedding']->generation_provenance, true, flags: JSON_THROW_ON_ERROR);
                    if (! $old || $this->isTrue($old->source_current) || $old->superseded_at === null
                        || $this->faces->faceVectorRefreshEmbeddingSha256($old->embedding) !== $run->old_vector_sha256
                        || $fresh['old_vector_sha256'] !== $run->output_embedding_sha256
                        || $fresh['identity_proof_sha256'] !== $run->identity_proof_sha256
                        || $fresh['current_engine_sha256'] !== $run->engine_provenance_sha256
                        || $fresh['asset_uuid'] !== $run->asset_uuid || $fresh['source_sha256'] !== $run->source_sha256
                        || hash('sha256', $this->canonicalJson($packet)) !== $run->provenance_sha256) {
                        throw new RuntimeException('refresh_input_changed');
                    }
                    $this->assertUnreferencedVector($old, true);
                    $this->assertUnreferencedVector($fresh['embedding'], true);
                    $preimage = $this->assertPreimageBackup($locked, $fresh['source_sha256']);
                    $this->assertRetiredVectorUnchanged($old, $preimage['vector']);
                    $this->retargetProfilePointers($locked, (int) $row->id, $fresh, $completed);
                    if (! $completed) {
                        $this->completeRun($run->run_uuid, (int) $row->id, $fresh, $run->output_embedding_sha256, $run->provenance_sha256);
                    }
                });
            } elseif (! $completed) {
                DB::table(self::LEDGER)->where('run_uuid', $run->run_uuid)->where('stage', 'publishing')->update([
                    'stage' => 'complete', 'new_embedding_id' => (int) $row->id, 'failure_code' => null,
                    'completed_at' => now(), 'updated_at' => now(),
                ]);
            }

            return ['success' => true, 'state' => 'complete', 'run_uuid' => $run->run_uuid,
                'new_face_embedding_id' => (int) $row->id, 'source_sha256' => $run->source_sha256,
                'old_embedding_sha256' => $run->old_vector_sha256, 'new_embedding_sha256' => $run->output_embedding_sha256,
                'replayed' => $completed, 'automatic_replay_allowed' => false,
                'identity_decisions_performed' => false, 'mysql_identity_rows_changed' => false,
                'historical_vector_retained' => true];
        });
    }

    private function assertRetiredVectorUnchanged(object $old, array $original): void
    {
        $retired = (array) $old;
        foreach (['source_current', 'superseded_at', 'updated_at'] as $column) {
            unset($retired[$column], $original[$column]);
        }
        if ($this->isTrue($old->source_current) || $old->superseded_at === null
            || $this->canonicalJson($retired) !== $this->canonicalJson($original)
            || ! $this->retirementTimestampsMatch($old)) {
            throw new RuntimeException('refresh_old_vector_changed');
        }
    }

    /** Compare the retirement instant using the actual PostgreSQL column precision. */
    private function retirementTimestampsMatch(object $old): bool
    {
        $pg = DB::connection('pgsql_rag');
        if ($pg->getDriverName() !== 'pgsql') {
            return $old->updated_at === $old->superseded_at;
        }
        $columns = $pg->select("SELECT column_name, data_type, datetime_precision
            FROM information_schema.columns
            WHERE table_schema = (SELECT n.nspname FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE c.oid = 'face_embeddings'::regclass)
              AND table_name = 'face_embeddings' AND column_name IN ('updated_at', 'superseded_at')");
        $columns = array_column($columns, null, 'column_name');
        foreach (['updated_at' => 'timestamp without time zone', 'superseded_at' => 'timestamp with time zone'] as $name => $type) {
            $column = $columns[$name] ?? null;
            $precision = $column ? filter_var($column->datetime_precision, FILTER_VALIDATE_INT) : false;
            if ($column === null || $column->data_type !== $type || $precision === false || $precision < 0 || $precision > 6) {
                return false;
            }
        }
        $precision = (int) $columns['superseded_at']->datetime_precision;
        $row = $pg->selectOne("SELECT CAST(updated_at AT TIME ZONE current_setting('TimeZone')
                AS timestamp({$precision}) with time zone) = superseded_at AS lifecycle_matches
            FROM face_embeddings WHERE id = ? AND source_current = FALSE
              AND superseded_at IS NOT NULL AND updated_at IS NOT NULL", [(int) $old->id]);

        return $row !== null && $this->isTrue($row->lifecycle_matches);
    }

    private function completeRun(string $runUuid, int $newEmbeddingId, array $before, string $vectorSha, string $provenanceSha): void
    {
        $updated = DB::table(self::LEDGER)->where('run_uuid', $runUuid)->where('stage', 'publishing')
            ->where('output_embedding_sha256', $vectorSha)->where('provenance_sha256', $provenanceSha)
            ->update(['stage' => 'complete', 'new_embedding_id' => $newEmbeddingId,
                'failure_code' => null, 'completed_at' => now(), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new RuntimeException('refresh_receipt_completion_unverified');
        }
        $run = DB::table(self::LEDGER)->where('run_uuid', $runUuid)->first();
        if (! $run || $run->stage !== 'complete' || (int) $run->new_embedding_id !== $newEmbeddingId
            || $run->source_sha256 !== $before['source_sha256']) {
            throw new RuntimeException('refresh_receipt_completion_unverified');
        }
    }

    private function holdRun(string $runUuid, string $failureCode): void
    {
        try {
            DB::table(self::LEDGER)->where('run_uuid', $runUuid)->whereIn('stage', ['detector_running', 'publishing', 'held'])
                ->update(['stage' => DB::raw("CASE WHEN stage = 'publishing' THEN 'publishing' ELSE 'held' END"),
                    'failure_code' => $failureCode, 'updated_at' => now()]);
        } catch (Throwable) {
            // The pre-detector durable claim remains non-replayable if receipt publication is unavailable.
        }
    }

    private function completedResult(string $runUuid, array $request, array $before, int $newId, bool $replayed): array
    {
        return ['success' => true, 'state' => 'complete', 'run_uuid' => $runUuid,
            'face_embedding_id' => $request['face_embedding_id'], 'new_face_embedding_id' => $newId,
            'source_sha256' => $before['source_sha256'], 'old_embedding_sha256' => $before['old_vector_sha256'],
            'replayed' => $replayed, 'automatic_replay_allowed' => false,
            'identity_decisions_performed' => false, 'mysql_identity_rows_changed' => false,
            'historical_vector_retained' => true];
    }

    private function provenance(object $run, array $state, array $face, string $vectorSha): array
    {
        return ['schema' => 'plos.face_vector_technical_refresh.v1', 'run_uuid' => $run->run_uuid,
            'file_registry_id' => $state['file_registry_id'], 'asset_uuid' => $state['asset_uuid'],
            'file_registry_face_id' => $state['face_id'], 'source_sha256' => $state['source_sha256'],
            'face_region' => $state['region'], 'face_region_convention' => 'normalized_top_left',
            'coordinate_basis' => 'encoded_pixels', 'old_face_embedding_id' => (int) $state['embedding']->id,
            'old_embedding_float32_sha256' => $state['old_vector_sha256'],
            'old_engine_provenance_sha256' => $state['old_engine_sha256'],
            'old_generation_provenance_sha256' => $state['old_provenance_sha256'],
            'identity_proof_sha256' => $state['identity_proof_sha256'],
            'engine_provenance' => $state['engine'], 'engine_provenance_sha256' => $state['current_engine_sha256'],
            'embedding_model' => $state['embedding_model'], 'embedding_float32_sha256' => $vectorSha,
            'source_dimensions' => $state['source_dimensions'], 'selected_region' => $state['region']];
    }

    private function binding(array $state, int $timeout, int $budget): array
    {
        return ['schema' => self::SCHEMA, 'face_embedding_id' => (int) $state['embedding']->id,
            'file_registry_face_id' => $state['face_id'], 'file_registry_id' => $state['file_registry_id'],
            'asset_uuid' => $state['asset_uuid'], 'source_sha256' => $state['source_sha256'],
            'source_path_sha256' => $state['source_path_sha256'], 'registry_revision' => $state['registry_revision'],
            'source_size' => $state['source_size'], 'source_dimensions' => $state['source_dimensions'],
            'face_region' => $state['region'], 'face_region_convention' => 'normalized_top_left',
            'old_vector_sha256' => $state['old_vector_sha256'], 'old_engine_sha256' => $state['old_engine_sha256'],
            'old_provenance_sha256' => $state['old_provenance_sha256'], 'embedding_model' => $state['embedding_model'],
            'preimage_sha256' => hash('sha256', $this->canonicalJson($state['preimage'])),
            'identity_proof_sha256' => $state['identity_proof_sha256'], 'identity_proof' => $state['identity_proof'],
            'engine_provenance_sha256' => $state['current_engine_sha256'], 'engine_provenance' => $state['engine'],
            'registered_timeout_ms' => $timeout, 'required_budget_seconds' => $budget];
    }

    private function requiredBudgetSeconds(): int
    {
        $detector = config('file_registry.face_preview.timeout_seconds');
        $engine = config('file_registry.face_generation.engine_version_timeout_seconds');
        if (! is_int($detector) || $detector < 1 || $detector > 300 || ! is_int($engine) || $engine < 1 || $engine > 60) {
            throw new RuntimeException('refresh_runtime_bounds_unavailable');
        }

        return $detector + (3 * $engine) + 30;
    }

    private function registeredTimeoutMs(): ?int
    {
        $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->first();
        $policy = is_string($row->config_value ?? null) ? json_decode($row->config_value, true) : null;
        $timeout = is_array($policy) ? ($policy['tools']['file_confirmed_face_vector_refresh_execute']['timeout_ms'] ?? null) : null;

        return is_int($timeout) && $timeout > 0 && $timeout <= 3600000 ? $timeout : null;
    }

    private function assertNativeCaller(array $request): void
    {
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        $scope = CliWorkflowExecutionScope::current();
        if (! is_array($caller) || count($caller) !== 5
            || array_diff(array_keys($caller), ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256']) !== []
            || ! is_array($scope) || ($scope['settings']['schema'] ?? null) !== CliWorkflowExecutionScope::SCHEMA) {
            throw new RuntimeException('trusted_framework_caller_required');
        }
        foreach (['session_id', 'turn_id', 'action_id'] as $field) {
            if (! is_string($caller[$field] ?? null) || $caller[$field] === '' || strlen($caller[$field]) > 255
                || preg_match('/[\\x00-\\x20\\x7f]/', $caller[$field])) {
                throw new RuntimeException('framework_caller_invalid');
            }
        }
        foreach (['arguments_sha256', 'approval_scope_sha256'] as $field) {
            if (! is_string($caller[$field] ?? null) || ! $this->hash($caller[$field])) {
                throw new RuntimeException('framework_caller_invalid');
            }
        }
        if (! is_array($request) || array_diff(array_keys($request), ['face_embedding_id', 'expected_input_digest', 'idempotency_key']) !== []
            || ! is_int($request['face_embedding_id'] ?? null) || ! $this->hash($request['expected_input_digest'] ?? null)
            || ! Str::isUuid($request['idempotency_key'] ?? '')) {
            throw new RuntimeException('refresh_request_invalid');
        }
    }

    private function validateRequest(array $request): void
    {
        if (array_diff(array_keys($request), ['face_embedding_id', 'expected_input_digest', 'idempotency_key']) !== []
            || ! is_int($request['face_embedding_id'] ?? null) || $request['face_embedding_id'] < 1
            || ! $this->hash($request['expected_input_digest'] ?? null) || ! Str::isUuid($request['idempotency_key'] ?? '')) {
            throw new RuntimeException('refresh_request_invalid');
        }
    }

    private function sameRegion(mixed $candidate, array $expected): bool
    {
        if (is_string($candidate)) {
            $candidate = json_decode($candidate, true);
        }
        if (! is_array($candidate) || count($candidate) !== 4
            || array_diff(array_keys($candidate), ['x', 'y', 'w', 'h']) !== []) {
            return false;
        }
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if (! is_numeric($candidate[$axis] ?? null) || ! is_finite((float) $candidate[$axis])
                || round((float) $candidate[$axis], 8) !== round((float) $expected[$axis], 8)) {
                return false;
            }
        }

        return true;
    }

    private function detectorEngineProvenance(array $engine): array
    {
        unset($engine['script_sha256']);

        return $engine;
    }

    private function normalizedRegion(object $embedding): ?array
    {
        $region = ['x' => $embedding->region_x ?? null, 'y' => $embedding->region_y ?? null,
            'w' => $embedding->region_w ?? null, 'h' => $embedding->region_h ?? null];
        if (array_filter($region, static fn ($value) => ! is_numeric($value) || ! is_finite((float) $value)) !== []) {
            return null;
        }
        $normalized = array_map(static fn ($value) => (float) $value, $region);

        return $normalized['x'] >= 0 && $normalized['y'] >= 0 && $normalized['w'] > 0 && $normalized['h'] > 0
            && $normalized['x'] + $normalized['w'] <= 1 && $normalized['y'] + $normalized['h'] <= 1 ? $normalized : null;
    }

    private function regionMatchesFace(object $face, mixed $region): bool
    {
        return is_array($region) && $this->sameRegion($region, [
            'x' => (float) $face->region_x, 'y' => (float) $face->region_y,
            'w' => (float) $face->region_w, 'h' => (float) $face->region_h,
        ]);
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
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }

            return $item;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function errorCode(Throwable $error): string
    {
        $safe = ['refresh_request_invalid', 'refresh_current_vector_unavailable', 'refresh_current_vector_ambiguous',
            'refresh_vector_relationship_unproven', 'refresh_vector_reference_inventory_unavailable',
            'refresh_current_vector_invalid', 'refresh_face_source_unavailable', 'refresh_source_proof_unavailable',
            'refresh_operator_receipt_unavailable', 'refresh_operator_receipt_ambiguous', 'refresh_operator_decision_conflict',
            'refresh_source_unavailable', 'refresh_source_resource_limit', 'refresh_model_contract_changed',
            'refresh_worker_budget_unavailable', 'refresh_runtime_bounds_unavailable', 'vector_already_uses_current_engine',
            'refresh_input_changed', 'refresh_engine_changed', 'refresh_private_snapshot_unavailable',
            'refresh_detector_evidence_invalid', 'refresh_embedding_invalid', 'refresh_idempotency_conflict',
            'refresh_prior_outcome_unknown', 'refresh_receipt_changed', 'refresh_operator_receipt_changed',
            'refresh_old_vector_changed', 'refresh_vector_publish_unverified', 'refresh_source_changed',
            'refresh_receipt_completion_unverified', 'trusted_framework_caller_required', 'framework_caller_invalid',
            'framework_scope_invalid', 'refresh_profile_ledger_unavailable', 'refresh_profile_bounds_unavailable',
            'refresh_profile_scope_too_large', 'refresh_profile_pointer_unproven', 'refresh_profile_owner_unavailable',
            'refresh_profile_source_unavailable', 'refresh_profile_owner_unproven', 'refresh_profile_membership_unproven',
            'refresh_profile_snapshot_changed', 'refresh_profile_pointer_changed', 'refresh_profile_readback_unverified',
            'refresh_backup_unavailable', 'refresh_backup_unverified', 'refresh_backup_bounds_unavailable'];

        return in_array($error->getMessage(), $safe, true) ? $error->getMessage() : 'refresh_unavailable';
    }
}
