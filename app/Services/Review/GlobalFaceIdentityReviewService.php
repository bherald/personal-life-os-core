<?php

namespace App\Services\Review;

use App\Services\FaceEmbeddingService;
use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\GlobalFaceIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Explicit person and current-face intents, staged through the existing ReviewDesk queue. */
class GlobalFaceIdentityReviewService
{
    public const REVIEW_TYPE = 'global_face_identity';

    public function __construct(private readonly GlobalFaceIdentityService $identities) {}

    /** Bounded read-only legacy evidence audit; candidates are never registry approvals. */
    public function legacyReferenceCandidates(array $faceIds): array
    {
        $selectorLimit = min(5, (int) config('file_registry.face_preview.max_assets'));
        $limit = min(100, (int) config('file_registry.face_preview.max_suggestion_regions'));
        if ($selectorLimit < 1 || $limit < 1 || ! array_is_list($faceIds) || $faceIds === [] || count($faceIds) > $selectorLimit
            || count(array_unique($faceIds, SORT_REGULAR)) !== count($faceIds)) {
            throw new InvalidArgumentException('Explicit unique face selectors within the preview limit are required.');
        }
        foreach ($faceIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Positive integer face selectors are required.');
            }
        }
        $sources = app(FaceLinkBridgeService::class);
        $embeddings = app(FaceEmbeddingService::class);
        $items = [];
        foreach ($faceIds as $id) {
            $face = DB::table('file_registry_faces as face')->leftJoin('file_registry as file', 'file.id', '=', 'face.file_registry_id')
                ->where('face.id', $id)->first(['face.*', 'file.current_path', 'file.status as file_status']);
            $item = ['face_id' => $id, 'state' => 'held', 'holds' => [], 'legacy_approvals' => [],
                'current_evidence' => null, 'embedding_evidence' => null, 'age_context' => null,
                'benchmark_eligible' => false, 'benchmark_holds' => ['fresh_global_reference_binding_required', 'original_model_checkpoint_unknown'],
                'original_source_sha256' => null, 'original_model_sha256' => null, 'optional_genealogy_mapping' => null,
                'global_rejection_check' => 'requires_explicit_identity_selection', 'identity_evidence' => null];
            if ($face) {
                $item['legacy_label'] = ['person_name' => $face->person_name, 'genealogy_person_id' => $face->genealogy_person_id,
                    'verified' => (bool) $face->verified, 'source' => $face->source];
            }
            $history = DB::table('genealogy_face_match_queue')->where('file_registry_face_id', $id)->orderBy('id')->limit($limit + 1)->get();
            $historyOverflow = $history->count() > $limit;
            if ($historyOverflow) {
                $item['holds'][] = 'approval_history_limit_exceeded';
            }
            $explicit = false;
            $originalHashes = [];
            foreach ($history->take($limit) as $review) {
                if ($review->status !== 'approved') {
                    continue;
                }
                $details = json_decode($review->match_details ?: '{}', true);
                if (! is_array($details)) {
                    $item['holds'][] = 'approval_history_invalid';
                    $item['legacy_approvals'][] = ['queue_id' => (int) $review->id, 'tree_id' => (int) $review->tree_id,
                        'media_id' => $review->media_id ?? null, 'face_region_raw' => $review->face_region ?? null,
                        'person_id' => $review->suggested_person_id, 'scope' => 'unspecified',
                        'reviewed_at' => $review->reviewed_at, 'reviewed_by' => $review->reviewed_by,
                        'review_notes' => $review->review_notes, 'match_type' => $review->match_type,
                        'match_details' => null, 'match_details_raw' => $review->match_details, 'region' => null,
                        'individual_image_review_attested' => false];

                    continue;
                }
                $confirmations = $details['operator_confirmations'] ?? [];
                if (! is_array($confirmations)) {
                    $item['holds'][] = 'approval_history_invalid';
                    $confirmations = [];
                }
                $exactConfirmations = array_filter($confirmations, fn ($confirmation): bool => is_array($confirmation)
                    && ($confirmation['decision_scope'] ?? null) === 'confirm_face_identity_link'
                    && (int) ($confirmation['file_registry_face_id'] ?? 0) === $id
                    && (int) ($confirmation['person_id'] ?? 0) === (int) $review->suggested_person_id);
                $scope = in_array($details['source'] ?? null, ['operator confirmation', 'user confirmation'], true) || $exactConfirmations !== []
                    ? 'individual' : (str_starts_with((string) $review->review_notes, 'Operator override ') ? 'operator_override' : 'unspecified');
                $firstConfirmation = $exactConfirmations === [] ? null : $exactConfirmations[array_key_first($exactConfirmations)];
                $item['legacy_approvals'][] = ['queue_id' => (int) $review->id, 'tree_id' => (int) $review->tree_id,
                    'media_id' => $review->media_id ?? null, 'face_region_raw' => $review->face_region ?? null,
                    'person_id' => $review->suggested_person_id, 'scope' => $scope, 'reviewed_at' => $review->reviewed_at,
                    'reviewed_by' => $review->reviewed_by, 'review_notes' => $review->review_notes,
                    'match_type' => $review->match_type, 'match_details' => $details, 'match_details_raw' => $review->match_details,
                    'region' => is_array($firstConfirmation['region'] ?? null) ? $firstConfirmation['region'] : ($details['region'] ?? null),
                    'override_granularity' => $scope === 'operator_override' ? 'not_recorded' : null,
                    'individual_image_review_attested' => $exactConfirmations !== []];
                foreach ($exactConfirmations as $confirmation) {
                    if (array_key_exists('source_sha256', $confirmation)) {
                        $originalHash = $confirmation['source_sha256'];
                        if (! is_string($originalHash) || ! preg_match('/^[a-f0-9]{64}$/D', $originalHash)) {
                            $item['holds'][] = 'legacy_source_hash_invalid';
                        } else {
                            $originalHashes[] = $originalHash;
                        }
                    }
                }
                if ($face && $face->genealogy_person_id !== null && (int) $review->suggested_person_id !== (int) $face->genealogy_person_id) {
                    $item['holds'][] = 'legacy_person_assignment_conflict';

                    continue;
                }
                if ($scope !== 'unspecified' && $review->reviewed_at !== null) {
                    $explicit = true;
                }
                try {
                    if ($historyOverflow) {
                        continue;
                    }
                    $sources->assertFaceIdentityNotRejected($id, (int) $review->tree_id, (int) $review->suggested_person_id);
                } catch (Throwable $e) {
                    $item['holds'][] = 'person_rejection_requires_reconciliation';
                }
            }
            $originalHashes = array_values(array_unique($originalHashes));
            if (count($originalHashes) === 1) {
                $item['original_source_sha256'] = $originalHashes[0];
            } elseif (count($originalHashes) > 1) {
                $item['holds'][] = 'legacy_source_bindings_conflict';
            }
            if ($face && ! empty($face->global_identity_approval_receipt_id)) {
                try {
                    $item['identity_evidence'] = $this->identities->currentConfirmedFaceEvidence($id);
                    if ($item['identity_evidence'] !== null) {
                        $item['global_rejection_check'] = 'passed_current_individual_confirmation';
                    } else {
                        $item['holds'][] = 'current_individual_confirmation_unavailable';
                    }
                } catch (Throwable) {
                    $item['holds'][] = 'current_individual_confirmation_invalid_or_rejected';
                }
            }
            if (! $explicit && $item['identity_evidence'] === null) {
                $item['holds'][] = 'explicit_legacy_operator_approval_unavailable';
            }
            if (! $face) {
                $item['holds'][] = 'face_unavailable';
                $item['holds'] = array_values(array_unique($item['holds']));
                $items[] = $item;

                continue;
            }
            if ($face->genealogy_person_id !== null) {
                $person = DB::table('genealogy_persons')->where('id', $face->genealogy_person_id)->first(['id', 'tree_id']);
                if ($person) {
                    foreach ($item['legacy_approvals'] as $approval) {
                        if ((int) $approval['person_id'] === (int) $person->id && $approval['tree_id'] !== (int) $person->tree_id) {
                            $item['holds'][] = 'legacy_person_tree_conflict';
                        }
                    }
                    $item['optional_genealogy_mapping'] = ['tree_id' => (int) $person->tree_id, 'genealogy_person_id' => (int) $person->id,
                        'operator_mapping_required' => true];
                } else {
                    $item['holds'][] = 'legacy_person_unavailable';
                }
            }
            $proof = $sources->validateFaceSourceEvidence($face);
            if (($proof['success'] ?? false) !== true) {
                $item['holds'][] = $proof['reason'] ?? 'current_source_unavailable';
            } else {
                $item['current_evidence'] = $proof;
                if ($item['original_source_sha256'] !== null && $item['original_source_sha256'] !== $proof['source_sha256']) {
                    $item['holds'][] = 'legacy_source_binding_changed';
                }
                try {
                    if (! $historyOverflow) {
                        $sources->assertFaceRegionNotDismissed($face);
                    }
                } catch (Throwable $e) {
                    $item['holds'][] = 'region_dismissal_requires_reconciliation';
                }
                try {
                    $vectors = DB::connection('pgsql_rag')->table('face_embeddings')->where('file_registry_face_id', $id)
                        ->where('source_current', true)->whereNull('superseded_at')->orderBy('id')->limit(2)->get(['id']);
                    if ($vectors->count() !== 1) {
                        $item['holds'][] = $vectors->isEmpty() ? 'current_vector_unavailable' : 'current_vector_ambiguous';
                    } else {
                        $vector = $embeddings->comparisonEmbeddingEvidence((int) $vectors[0]->id, true);
                        $same = $vector !== null && ($vector['file_registry_id'] ?? null) === (int) $face->file_registry_id
                            && ($vector['source_sha256'] ?? null) === $proof['source_sha256']
                            && ($vector['face_region_convention'] ?? null) === 'normalized_top_left';
                        $vectorRegion = [];
                        foreach (['x', 'y', 'w', 'h'] as $axis) {
                            $vectorRegion['region_'.$axis] = $vector['region'][$axis] ?? null;
                        }
                        $same = $same && FaceEmbeddingService::faceRegionsMatchStoragePrecision($face, (object) $vectorRegion);
                        if (! $same) {
                            $item['holds'][] = 'current_vector_binding_invalid';
                        } else {
                            $item['embedding_evidence'] = $vector;
                        }
                    }
                } catch (Throwable $e) {
                    $item['holds'][] = 'current_vector_unavailable';
                }

            }
            $age = DB::table('person_face_profile_faces')->where('file_registry_face_id', $id)->first(['age_band', 'age_at_photo', 'photo_date', 'evidence_source']);
            if ($age) {
                $item['age_context'] = (array) $age + ['identity_approval' => false, 'derived_projection' => true];
            }
            $item['holds'] = array_values(array_unique($item['holds']));
            $provenance = $item['embedding_evidence']['generation_provenance'] ?? null;
            $checkpoint = $provenance['model_sha256'] ?? null;
            if (is_string($checkpoint) && preg_match('/^[a-f0-9]{64}$/D', $checkpoint)) {
                $item['original_model_sha256'] = $checkpoint;
                $item['benchmark_holds'] = array_values(array_diff($item['benchmark_holds'], ['original_model_checkpoint_unknown']));
            }
            if ($item['identity_evidence'] !== null) {
                $item['benchmark_holds'] = array_values(array_diff($item['benchmark_holds'], ['fresh_global_reference_binding_required']));
            }
            if ($item['holds'] !== []) {
                $item['benchmark_holds'][] = 'current_reference_evidence_held';
            }
            $item['benchmark_eligible'] = $item['benchmark_holds'] === [];
            $item['state'] = $item['benchmark_eligible'] ? 'confirmed_reference'
                : ($item['holds'] === [] ? 'candidate_for_operator_review' : 'held');
            $items[] = $item;
        }

        return ['read_only' => true, 'identity_assignments' => false, 'items' => $items];
    }

    public function intent(object $row): ?array
    {
        if (($row->review_type ?? null) !== self::REVIEW_TYPE) {
            return null;
        }
        $details = json_decode((string) ($row->details ?? ''), true);
        if (! is_array($details) || ! is_array($details['global_identity_decision'] ?? null) || ! is_string($details['decision_scope'] ?? null)) {
            return null;
        }
        try {
            $this->registryIntent($details['global_identity_decision'], $details['decision_scope'], 'operator');
        } catch (InvalidArgumentException) {
            return null;
        }

        return $details['global_identity_decision'];
    }

    public function presentation(object $row): array
    {
        $decision = $this->intent($row);
        if ($decision === null) {
            return ['global_identity_decision' => null, 'approval_ready' => false,
                'blockers' => ['Exact independent person registry intent is unavailable.']];
        }
        $operation = $decision['operation'];
        $canonicalName = $operation === 'create_identity' ? $decision['canonical_name']
            : DB::table('global_face_identities')->where('identity_uuid', $decision['identity_uuid'])->value('canonical_name');
        $ready = is_string($canonicalName) && $canonicalName !== '';
        $blockers = $ready ? [] : ['Selected global individual is unavailable.'];
        $selectedPersonName = null;
        $faceEvidence = null;
        if ($operation === 'confirm_face') {
            try {
                $this->identities->validateCurrentFaceIntent($decision);
                $faceEvidence = array_intersect_key($decision, array_flip(['face_id', 'source_sha256', 'region', 'face_region_convention', 'coordinate_basis']));
                $region = [];
                foreach (['x', 'y', 'w', 'h'] as $axis) {
                    $region[$axis] = (float) $decision['region'][$axis];
                }
                $faceEvidence['region_fingerprint'] = hash('sha256', $decision['source_sha256'].json_encode($region, JSON_THROW_ON_ERROR));
                $faceEvidence['crop_url'] = '/api/media/faces/registry-crop/'.$decision['face_id'].'?source_sha256='.$decision['source_sha256']
                    .'&region_fingerprint='.$faceEvidence['region_fingerprint'];
            } catch (InvalidArgumentException|RuntimeException $exception) {
                $ready = false;
                $blockers[] = $exception->getMessage();
            }
        }
        if ($operation === 'map_genealogy') {
            $person = DB::table('genealogy_persons')->where('id', $decision['genealogy_person_id'])
                ->where('tree_id', $decision['tree_id'])->first(['given_name', 'surname']);
            $selectedPersonName = $person ? trim(($person->given_name ?? '').' '.($person->surname ?? '')) : null;
            if ($selectedPersonName === null || $selectedPersonName === '') {
                $ready = false;
                $blockers[] = $person ? 'Selected family-tree person has no display name.' : 'Selected person is unavailable in the exact family tree.';
            }
        }

        return ['global_identity_decision' => $decision, 'canonical_name' => $canonicalName,
            'selected_genealogy_person_name' => $selectedPersonName,
            'selected_face_evidence' => $faceEvidence,
            'decision_scope' => GlobalFaceIdentityService::SCOPES[$operation],
            'rejection_scope' => 'reject_global_face_identity_proposal', 'approval_ready' => $ready,
            'question' => $operation === 'confirm_face' ? 'Confirm this face belongs to the selected individual?' : 'Confirm this individual person registry decision?',
            'current' => $operation === 'create_identity' ? 'No independent person record created by this proposal.' : 'Selected individual: '.($canonicalName ?? 'unavailable'),
            'proposed' => match ($operation) {
                'create_identity' => 'Canonical name: '.$decision['canonical_name'],
                'add_alias' => 'Person-specific alias: '.$decision['alias'],
                'map_genealogy' => 'Optional mapping to tree '.$decision['tree_id'].' person '.$decision['genealogy_person_id'],
                'confirm_face' => 'Assign the selected current face to '.($canonicalName ?? 'unavailable'),
            },
            'effect' => $operation === 'confirm_face' ? 'Confirms this current face for the selected individual and schedules guarded metadata writeback using their canonical name. No family tree photo link is created.' : 'Records only this individual registry decision. Picture associations require separate current-source review.',
            'face_association' => $operation === 'confirm_face', 'bulk_eligible' => false, 'sensitive' => true,
            'blockers' => $blockers];
    }

    public function stage(array $decision, string $scope, string $actor = 'operator', ?string $requestKey = null): array
    {
        $this->registryIntent($decision, $scope, $actor);
        if ($requestKey !== null && ! Str::isUuid($requestKey)) {
            throw new InvalidArgumentException('A UUID staging request key is required.');
        }

        return DB::transaction(function () use ($decision, $scope, $actor, $requestKey): array {
            $digest = $this->identities->intentDigest(['decision' => $decision, 'scope' => $scope, 'actor' => $actor]);
            if ($requestKey !== null) {
                $prior = DB::table('review_desk_receipts')->where('id', $requestKey)->lockForUpdate()->first();
                if ($prior) {
                    $payload = json_decode($prior->payload, true);
                    if ($prior->action !== 'stage_identity' || $prior->state !== 'completed'
                        || ! hash_equals($prior->request_digest, $digest)
                        || ($payload['actor'] ?? null) !== $actor || ($payload['decision_scope'] ?? null) !== $scope
                        || ($payload['success'] ?? false) !== true || ! is_array($payload['stage_result'] ?? null)) {
                        throw new RuntimeException('This request key binds another proposal or requires reconciliation.');
                    }

                    return array_replace($payload['stage_result'], ['replayed' => true]);
                }
                // Receipt primary-key uniqueness serializes staging, including after the
                // proposal closes. The queue token alone has no uniqueness guarantee.
                DB::table('review_desk_receipts')->insert([
                    'id' => $requestKey, 'reference' => (string) Str::uuid(), 'idempotency_key' => $requestKey,
                    'request_digest' => $digest, 'action' => 'stage_identity', 'state' => 'processing',
                    'payload' => json_encode(['actor' => $actor, 'decision_scope' => $scope,
                        'decision_recorded' => false, 'effect_status' => 'not_started', 'success' => false], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($decision['operation'] === 'confirm_face') {
                $this->identities->validateCurrentFaceIntent($decision);
                // The current-face lock serializes proposal admission across request keys.
                // Exact request replay has already returned above; another pending proposal
                // for this face requires reconciliation, even when it names the same person.
                $pending = DB::table('agent_review_queue')->where('review_type', self::REVIEW_TYPE)->where('status', 'pending')
                    ->where(fn ($expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->whereRaw("JSON_EXTRACT(details, '$.global_identity_decision.operation') = ?", ['confirm_face'])
                    ->whereRaw("JSON_EXTRACT(details, '$.global_identity_decision.face_id') = ?", [$decision['face_id']])->exists();
                if ($pending) {
                    throw new RuntimeException('This face already has a pending person confirmation. Review or close that proposal first.');
                }
            }
            $token = $requestKey ?? (string) Str::uuid();
            $id = DB::table('agent_review_queue')->insertGetId([
                'agent_id' => 'operator', 'review_type' => self::REVIEW_TYPE,
                // Existing pending dedup uses the first 80 title characters. Distinct
                // individuals and same-name proposals must not collapse by operation.
                'title' => 'Person registry '.$token,
                'summary' => match ($decision['operation']) {
                    'create_identity' => 'Create independent person: '.$decision['canonical_name'],
                    'add_alias' => 'Add alias to the selected individual: '.$decision['alias'],
                    'map_genealogy' => 'Map the selected individual to tree '.$decision['tree_id'].' person '.$decision['genealogy_person_id'],
                    'confirm_face' => 'Confirm the selected current face for the explicitly selected individual.',
                },
                'details' => json_encode(['global_identity_decision' => $decision, 'decision_scope' => $scope,
                    'operator_only' => true, 'face_association' => $decision['operation'] === 'confirm_face', 'staged_by' => $actor], JSON_THROW_ON_ERROR),
                'status' => 'pending', 'priority' => 0, 'token' => $token,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $result = ['success' => true, 'review_id' => $id, 'unified_id' => self::REVIEW_TYPE.':'.$id,
                'identity_uuid' => $decision['identity_uuid'], 'identity_confirmed' => false,
                'face_association' => false, 'replayed' => false];
            if ($requestKey !== null) {
                $item = app(ReviewDeskService::class)->materializeReference($result['unified_id']);
                if (! $item) {
                    throw new RuntimeException('Canonical proposal review could not be materialized.');
                }
                $result['reference'] = $item['reference'];
                DB::table('review_desk_receipts')->where('id', $requestKey)->update([
                    'reference' => $item['reference'], 'state' => 'completed',
                    'payload' => json_encode(['id' => $requestKey, 'reference' => $item['reference'],
                        'actor' => $actor, 'decision_scope' => $scope, 'action' => 'stage_identity',
                        'global_identity_decision' => $decision, 'decision_recorded' => false,
                        'effect_status' => 'proposal_staged', 'before' => null, 'after' => 'pending',
                        'success' => true, 'stage_result' => $result], JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }

            return $result;
        }, 3);
    }

    public function approve(int $id, ?string $notes = null, array $decisionMeta = []): array
    {
        return $this->decide($id, true, $notes, $decisionMeta);
    }

    public function reject(int $id, ?string $notes = null, array $decisionMeta = []): array
    {
        return $this->decide($id, false, $notes, $decisionMeta);
    }

    private function decide(int $id, bool $approve, ?string $notes, array $meta): array
    {
        if (DB::connection()->transactionLevel() < 1 || ! is_string($meta['actor'] ?? null) || trim($meta['actor']) === '') {
            throw new RuntimeException('Owned canonical operator review transaction required.');
        }

        return DB::transaction(function () use ($id, $approve, $notes, $meta): array {
            $row = DB::table('agent_review_queue')->where('id', $id)->where('review_type', self::REVIEW_TYPE)->lockForUpdate()->first();
            $details = $row ? json_decode($row->details, true) : null;
            if (! $row || $row->status !== 'pending' || ($row->expires_at !== null && strtotime($row->expires_at) <= time()) || ! is_array($details)) {
                throw new RuntimeException('Current pending global identity intent required.');
            }
            $decision = $details['global_identity_decision'] ?? [];
            $scope = $details['decision_scope'] ?? '';
            $this->registryIntent($decision, $scope, $meta['actor']);
            $expectedScope = $approve ? $scope : 'reject_global_face_identity_proposal';
            if (($meta['decision_scope'] ?? null) !== $expectedScope
                || (array_key_exists('global_identity_decision', $meta) && (! is_array($meta['global_identity_decision'])
                    || $this->identities->intentDigest($meta['global_identity_decision']) !== $this->identities->intentDigest($decision)))) {
                throw new RuntimeException('Exact staged operator decision and scope required.');
            }
            $receipt = DB::table('review_desk_receipts')->where('id', $meta['receipt_id'] ?? null)->lockForUpdate()->first();
            $reference = $receipt ? DB::table('review_desk_references')->where('reference', $receipt->reference)->lockForUpdate()->first() : null;
            $payload = $receipt ? json_decode($receipt->payload, true) : null;
            if (! $receipt || $receipt->state !== 'processing' || $receipt->action !== ($approve ? 'approve' : 'reject')
                || ! $reference || $reference->source !== 'default:agent_review_queue' || $reference->review_type !== self::REVIEW_TYPE
                || (string) $reference->source_id !== (string) $id
                || ! hash_equals($reference->source_revision, hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR)))
                || ! is_array($payload) || ($payload['reference'] ?? null) !== $reference->reference
                || ($payload['actor'] ?? null) !== $meta['actor'] || ($payload['decision_scope'] ?? null) !== $expectedScope
                || ! is_array($payload['global_identity_decision'] ?? null)
                || $this->identities->intentDigest($payload['global_identity_decision']) !== $this->identities->intentDigest($decision)) {
                throw new RuntimeException('Matching in-flight ReviewDesk receipt required.');
            }
            $policy = DB::table('review_desk_policy')->where('review_type', self::REVIEW_TYPE)->where('action', $receipt->action)->first();
            $conditions = $policy ? json_decode($policy->conditions, true) : null;
            if (! $policy || ! $policy->enabled || ! $policy->sensitive || $policy->bulk_enabled
                || $policy->service_class !== self::class || $policy->method !== $receipt->action
                || ($conditions['operator_only'] ?? false) !== true) {
                throw new RuntimeException('Individual operator policy is not admitted.');
            }
            $result = $approve ? $this->identities->apply($decision, $receipt->id, $scope) : ['success' => true];
            $status = $approve ? 'approved' : 'rejected';
            DB::table('agent_review_queue')->where('id', $id)->update([
                'status' => $status, 'reviewer_notes' => $notes, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);

            return $result + ['desk_source_state' => $status, 'face_association' => $approve && $decision['operation'] === 'confirm_face'];
        });
    }

    private function registryIntent(array $decision, string $scope, string $actor): void
    {
        if (trim($actor) === '' || ! in_array($decision['operation'] ?? null, ['create_identity', 'add_alias', 'map_genealogy', 'confirm_face'], true)) {
            throw new InvalidArgumentException('Explicit individual operator intent required.');
        }
        $this->identities->validateIntent($decision, $scope);
    }
}
