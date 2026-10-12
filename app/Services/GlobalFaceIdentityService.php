<?php

namespace App\Services;

use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\Review\GlobalFaceIdentityReviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Operator records independent of genealogy, names, contacts and detection clusters. */
class GlobalFaceIdentityService
{
    public const SCOPES = [
        'create_identity' => 'confirm_global_face_identity',
        'add_alias' => 'confirm_global_face_identity_alias',
        'map_genealogy' => 'map_global_face_identity_to_tree_person',
        'confirm_face' => 'confirm_current_face_global_identity',
    ];

    public function __construct(private readonly FaceLinkBridgeService $sourceEvidence) {}

    /**
     * The canonical ReviewDesk handler supplies an exact receipt-bound decision.
     * Creation supplies a fresh identity_uuid; repeating a receipt is idempotent.
     * Processing receipts are admitted only within the handler's transaction.
     * No names/nicknames are searched to select or merge an individual.
     */
    public function apply(array $decision, string $receiptId, string $decisionScope): array
    {
        $decision = $this->validateIntent($decision, $decisionScope);

        return DB::transaction(function () use ($decision, $receiptId, $decisionScope): array {
            $this->validateReceipt($receiptId, $decisionScope, $decision);
            $uuid = $decision['identity_uuid'];
            $identity = DB::table('global_face_identities')->where('identity_uuid', $uuid)->lockForUpdate()->first();
            if ($decision['operation'] === 'create_identity') {
                if ($identity) {
                    if ($identity->canonical_name !== $decision['canonical_name'] || $identity->approval_receipt_id !== $receiptId) {
                        throw new RuntimeException('Identity UUID already belongs to another operator decision.');
                    }
                } else {
                    DB::table('global_face_identities')->insert([
                        'identity_uuid' => $uuid, 'canonical_name' => $decision['canonical_name'],
                        'status' => 'operator_confirmed', 'approval_receipt_id' => $receiptId,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            } else {
                if (! $identity || $identity->status !== 'operator_confirmed') {
                    throw new RuntimeException('Explicit confirmed global identity required.');
                }
                match ($decision['operation']) {
                    'add_alias' => $this->addAlias($decision, $receiptId),
                    'map_genealogy' => $this->mapGenealogy($decision, $receiptId),
                    'confirm_face' => $this->confirmFace($decision, $receiptId),
                };
            }

            return ['success' => true, 'operation' => $decision['operation'], 'identity_uuid' => $uuid,
                'approval_receipt_id' => $receiptId];
        });
    }

    public function validateIntent(array $decision, string $scope): array
    {
        $operation = $decision['operation'] ?? null;
        if (! is_string($operation) || (self::SCOPES[$operation] ?? null) !== $scope
            || ! is_string($decision['identity_uuid'] ?? null) || ! Str::isUuid($decision['identity_uuid'])) {
            throw new InvalidArgumentException('Explicit identity UUID and operator decision scope required.');
        }
        $fields = match ($operation) {
            'create_identity' => ['canonical_name'], 'add_alias' => ['alias'],
            'map_genealogy' => ['tree_id', 'genealogy_person_id'],
            'confirm_face' => ['face_id', 'source_sha256', 'region', 'face_region_convention', 'coordinate_basis'],
        };
        $keys = array_keys($decision);
        sort($keys);
        $allowed = ['operation', 'identity_uuid', ...$fields];
        sort($allowed);
        if ($keys !== $allowed) {
            throw new InvalidArgumentException('Decision fields must exactly describe the selected operation.');
        }
        foreach (['canonical_name', 'alias'] as $field) {
            if (array_key_exists($field, $decision) && (! is_string($decision[$field]) || trim($decision[$field]) !== $decision[$field]
                || $decision[$field] === '' || mb_strlen($decision[$field]) > 255
                || preg_match('/[\x00-\x1F\x7F]/u', $decision[$field]))) {
                throw new InvalidArgumentException('A nonempty operator-selected name is required.');
            }
        }
        foreach (['tree_id', 'genealogy_person_id', 'face_id'] as $field) {
            if (array_key_exists($field, $decision) && (! is_int($decision[$field]) || $decision[$field] < 1)) {
                throw new InvalidArgumentException('Explicit positive integer identifiers required.');
            }
        }
        if ($operation === 'confirm_face') {
            if (! is_string($decision['source_sha256']) || ! preg_match('/^[a-f0-9]{64}$/D', $decision['source_sha256'])
                || $decision['face_region_convention'] !== 'normalized_top_left' || $decision['coordinate_basis'] !== 'encoded_pixels') {
                throw new InvalidArgumentException('Exact current source and encoded-pixel convention required.');
            }
            $this->validateRegion($decision['region']);
        }

        return $decision;
    }

    public function intentDigest(array $decision): string
    {
        return hash('sha256', $this->canonicalJson($decision));
    }

    private function validateReceipt(string $id, string $scope, array $decision): void
    {
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('Operator receipt UUID required.');
        }
        $receipt = DB::table('review_desk_receipts')->where('id', $id)->lockForUpdate()->first();
        $payload = $receipt ? json_decode($receipt->payload, true) : null;
        $staged = $receipt && $this->isStagedReviewReceipt($receipt, $decision);
        if (! $receipt || ! is_array($payload) || ! in_array($receipt->state, ['processing', 'completed'], true)
            || ($receipt->action !== $decision['operation'] && ! $staged)
            || ($receipt->state === 'processing' && DB::connection()->transactionLevel() < 2)
            || ($receipt->state === 'completed' && (($payload['success'] ?? false) !== true || ($payload['decision_recorded'] ?? false) !== true))
            || ! is_string($payload['actor'] ?? null) || trim($payload['actor']) === ''
            || (! $staged && $payload['actor'] !== 'operator')
            || ($staged && ($payload['reference'] ?? null) !== $receipt->reference)
            || ($payload['decision_scope'] ?? null) !== $scope
            || $this->canonicalJson($payload['global_identity_decision'] ?? null) !== $this->canonicalJson($decision)) {
            throw new RuntimeException('A matching operator receipt is required.');
        }
    }

    private function isStagedReviewReceipt(object $receipt, array $decision): bool
    {
        if ($receipt->action !== 'approve') {
            return false;
        }
        $policy = DB::table('review_desk_policy')->where('review_type', 'global_face_identity')->where('action', 'approve')->first();
        $conditions = $policy ? json_decode($policy->conditions, true) : null;
        if (! $policy || ! $policy->enabled || ! $policy->sensitive || $policy->bulk_enabled
            || $policy->service_class !== GlobalFaceIdentityReviewService::class
            || $policy->method !== 'approve' || ($conditions['operator_only'] ?? false) !== true) {
            return false;
        }
        $reference = DB::table('review_desk_references')->where('reference', $receipt->reference ?? null)->lockForUpdate()->first();
        if (! $reference || $reference->review_type !== 'global_face_identity' || $reference->source !== 'default:agent_review_queue') {
            return false;
        }
        $row = DB::table('agent_review_queue')->where('id', $reference->source_id)->where('review_type', 'global_face_identity')->lockForUpdate()->first();
        $details = $row ? json_decode($row->details, true) : null;
        if ($receipt->state === 'processing' && (! $row || $row->status !== 'pending'
            || (! empty($row->expires_at) && strtotime($row->expires_at) <= time())
            || ! hash_equals($reference->source_revision, hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR))))) {
            return false;
        }

        return $row && in_array($row->status, ['pending', 'approved'], true)
            && $this->canonicalJson($details['global_identity_decision'] ?? null) === $this->canonicalJson($decision);
    }

    private function addAlias(array $decision, string $receipt): void
    {
        // Only spelling/case normalization within this explicitly selected person.
        $key = hash('sha256', mb_strtolower(preg_replace('/\s+/u', ' ', $decision['alias'])));
        $query = DB::table('global_face_identity_aliases')->where('identity_uuid', $decision['identity_uuid'])->where('alias_key', $key);
        $existing = $query->first();
        if ($existing) {
            if ($existing->approval_receipt_id !== $receipt || $existing->alias !== $decision['alias']) {
                throw new RuntimeException('Alias already has a different operator decision.');
            }

            return;
        }
        DB::table('global_face_identity_aliases')->insert([
            'identity_uuid' => $decision['identity_uuid'], 'alias' => $decision['alias'], 'alias_key' => $key,
            'approval_receipt_id' => $receipt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mapGenealogy(array $decision, string $receipt): void
    {
        $person = DB::table('genealogy_persons')->where('id', $decision['genealogy_person_id'])
            ->where('tree_id', $decision['tree_id'])->lockForUpdate()->first();
        if (! $person) {
            throw new RuntimeException('Selected genealogy person does not belong to the selected tree.');
        }
        $otherIdentity = DB::table('global_face_identity_genealogy_links')->where('tree_id', $decision['tree_id'])
            ->where('genealogy_person_id', $decision['genealogy_person_id'])->where('identity_uuid', '!=', $decision['identity_uuid'])->first();
        if ($otherIdentity) {
            throw new RuntimeException('Tree person already belongs to another global identity; explicit reconciliation required.');
        }
        $query = DB::table('global_face_identity_genealogy_links')->where('identity_uuid', $decision['identity_uuid'])
            ->where('tree_id', $decision['tree_id'])->where('genealogy_person_id', $decision['genealogy_person_id']);
        $existing = $query->first();
        if ($existing) {
            if ($existing->approval_receipt_id !== $receipt) {
                throw new RuntimeException('Mapping already has a different operator decision.');
            }

            return;
        }
        $faces = DB::table('file_registry_faces')->where('global_identity_uuid', $decision['identity_uuid'])->get(['id']);
        foreach ($faces as $face) {
            $this->sourceEvidence->assertFaceIdentityNotRejected((int) $face->id, $decision['tree_id'], $decision['genealogy_person_id']);
        }
        DB::table('global_face_identity_genealogy_links')->insert([
            'identity_uuid' => $decision['identity_uuid'], 'tree_id' => $decision['tree_id'],
            'genealogy_person_id' => $decision['genealogy_person_id'], 'approval_receipt_id' => $receipt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Read-only admission for an operator-selected, current source-bound face. */
    public function validateCurrentFaceIntent(array $decision): void
    {
        $this->validateIntent($decision, self::SCOPES['confirm_face']);
        if ($decision['operation'] !== 'confirm_face'
            || ! DB::table('global_face_identities')->where('identity_uuid', $decision['identity_uuid'])
                ->where('status', 'operator_confirmed')->exists()) {
            throw new RuntimeException('Explicit confirmed global identity required.');
        }
        $this->currentFaceForDecision($decision);
    }

    /** Read a completed individual confirmation and recheck all current-source/rejection guards. */
    public function currentConfirmedFaceEvidence(int $faceId): ?array
    {
        $face = DB::table('file_registry_faces')->where('id', $faceId)->first();
        $receiptId = $face->global_identity_approval_receipt_id ?? null;
        if (! is_string($receiptId) || ! Str::isUuid($receiptId)
            || ! is_string($face->global_identity_uuid ?? null)) {
            return null;
        }
        $receipt = DB::table('review_desk_receipts')->where('id', $receiptId)->where('state', 'completed')->first();
        $payload = $receipt ? json_decode($receipt->payload, true) : null;
        $decision = $payload['global_identity_decision'] ?? null;
        if (! is_array($decision) || ($decision['operation'] ?? null) !== 'confirm_face'
            || ($decision['face_id'] ?? null) !== $faceId || ($decision['identity_uuid'] ?? null) !== $face->global_identity_uuid) {
            return null;
        }
        $this->validateIntent($decision, self::SCOPES['confirm_face']);
        $this->validateReceipt($receiptId, self::SCOPES['confirm_face'], $decision);
        if (! DB::table('global_face_identities')->where('identity_uuid', $decision['identity_uuid'])
            ->where('status', 'operator_confirmed')->exists()) {
            return null;
        }
        $currentDecision = $decision;
        $sourceAdvanced = $face->source_sha256 !== $decision['source_sha256'];
        if ($sourceAdvanced) {
            // Metadata writeback retains the original operator receipt and records
            // separate same-face source transitions. Reuse that existing proof.
            $approved = app(GlobalFaceWritebackApprovalService::class)->approvedFace($faceId);
            if (! $approved || (int) $approved->id !== $faceId
                || $approved->global_identity_uuid !== $face->global_identity_uuid
                || $approved->global_identity_approval_receipt_id !== $receiptId
                || $approved->source_sha256 !== $face->source_sha256) {
                throw new RuntimeException('Original individual confirmation does not prove the current metadata source.');
            }
            $registry = DB::table('file_registry')->where('id', $face->file_registry_id)->where('status', 'active')->first();
            $path = $registry ? app(NextcloudFileApiService::class)->localPath($registry->current_path) : null;
            if (! $registry || ! is_string($path) || $path === '') {
                throw new RuntimeException('Current identity source transition is unavailable.');
            }
            $chain = app(ExifWritebackService::class)->inspectVerifiedMetadataTransitionChain(
                (int) $registry->id, $registry->asset_uuid, $decision['source_sha256'], $face->source_sha256, $path);
            if (($chain['status'] ?? null) !== 'verified' || ($chain['registry_id'] ?? null) !== (int) $registry->id
                || ($chain['asset_uuid'] ?? null) !== $registry->asset_uuid
                || ($chain['before_sha256'] ?? null) !== $decision['source_sha256']
                || ($chain['after_sha256'] ?? null) !== $face->source_sha256
                || ! is_array($chain['transitions'] ?? null) || $chain['transitions'] === []) {
                throw new RuntimeException('Current identity source transition is not uniquely proved.');
            }
            $hashes = [$decision['source_sha256']];
            $hash = $decision['source_sha256'];
            foreach ($chain['transitions'] as $transition) {
                if (($transition['before_sha256'] ?? null) !== $hash
                    || ! is_string($transition['after_sha256'] ?? null)
                    || ! preg_match('/^[a-f0-9]{64}$/D', $transition['after_sha256'])) {
                    throw new RuntimeException('Current identity source transition is discontinuous.');
                }
                $hashes[] = $hash = $transition['after_sha256'];
            }
            if ($hash !== $face->source_sha256) {
                throw new RuntimeException('Current identity source transition does not reach this source.');
            }
            // Same pixels and region retain every dismissal/rejection across byte
            // revisions, including decisions on an intermediate metadata source.
            foreach (array_unique($hashes) as $hash) {
                $equivalentFace = clone $face;
                $equivalentFace->source_sha256 = $hash;
                $this->sourceEvidence->assertFaceRegionNotDismissed($equivalentFace);
                $this->assertGlobalFaceIntentNotRejected(array_replace($decision, ['source_sha256' => $hash]));
            }
            $currentDecision['source_sha256'] = $face->source_sha256;
        }
        $this->currentFaceForDecision($currentDecision, $receiptId);

        return ['operator_confirmed' => true, 'receipt' => $receiptId, 'decision' => $decision]
            + ($sourceAdvanced ? ['current_source_sha256' => $face->source_sha256, 'metadata_source_transition_verified' => true] : []);
    }

    private function currentFaceForDecision(array $decision, ?string $receipt = null): object
    {
        $face = DB::table('file_registry_faces as face')->join('file_registry as file', 'file.id', '=', 'face.file_registry_id')
            ->where('face.id', $decision['face_id'])->select('face.*', 'file.current_path', 'file.status as file_status')->lockForUpdate()->first();
        if (! $face || ($face->global_identity_uuid !== null && $face->global_identity_uuid !== $decision['identity_uuid'])
            || ($face->global_identity_approval_receipt_id !== null && $face->global_identity_approval_receipt_id !== $receipt)
            || $face->source_sha256 !== $decision['source_sha256']) {
            throw new RuntimeException('Current face or existing operator identity conflicts with this decision.');
        }
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if (abs((float) $face->{'region_'.$axis} - (float) $decision['region'][$axis]) > 0.00000001) {
                throw new RuntimeException('Selected face region changed.');
            }
        }
        if ($face->genealogy_person_id !== null) {
            $mapped = DB::table('global_face_identity_genealogy_links as link')->join('genealogy_persons as person', 'person.id', '=', 'link.genealogy_person_id')
                ->whereColumn('person.tree_id', 'link.tree_id')->where('link.identity_uuid', $decision['identity_uuid'])
                ->where('link.genealogy_person_id', $face->genealogy_person_id)->exists();
            if (! $mapped) {
                throw new RuntimeException('Existing genealogy assignment requires an explicit matching global mapping.');
            }
        }
        $this->sourceEvidence->assertFaceRegionNotDismissed($face);
        $this->assertGlobalFaceIntentNotRejected($decision);
        $mappings = DB::table('global_face_identity_genealogy_links')->where('identity_uuid', $decision['identity_uuid'])
            ->get(['tree_id', 'genealogy_person_id']);
        foreach ($mappings as $mapping) {
            $this->sourceEvidence->assertFaceIdentityNotRejected((int) $face->id, (int) $mapping->tree_id, (int) $mapping->genealogy_person_id);
        }
        $proof = $this->sourceEvidence->validateFaceSourceEvidence($face);
        if (($proof['success'] ?? false) !== true) {
            throw new RuntimeException('Current face source proof failed.');
        }

        return $face;
    }

    private function assertGlobalFaceIntentNotRejected(array $decision): void
    {
        $rows = DB::table('agent_review_queue')->where('review_type', 'global_face_identity')->where('status', 'rejected')
            ->where('details->global_identity_decision->operation', 'confirm_face')
            ->where('details->global_identity_decision->identity_uuid', $decision['identity_uuid'])
            ->where('details->global_identity_decision->source_sha256', $decision['source_sha256'])->lockForUpdate()->get(['details']);
        foreach ($rows as $row) {
            $details = json_decode($row->details, true, flags: JSON_THROW_ON_ERROR);
            $rejected = $details['global_identity_decision']['region'] ?? null;
            $this->validateRegion($rejected);
            $same = true;
            foreach (['x', 'y', 'w', 'h'] as $axis) {
                $same = $same && abs((float) $rejected[$axis] - (float) $decision['region'][$axis]) <= 0.00000001;
            }
            if ($same) {
                throw new RuntimeException('This individual and current source region were rejected; reconcile that decision first.');
            }
        }
    }

    private function confirmFace(array $decision, string $receipt): void
    {
        $face = $this->currentFaceForDecision($decision, $receipt);
        if ($face->global_identity_uuid === $decision['identity_uuid'] && $face->global_identity_approval_receipt_id === $receipt) {
            return;
        }
        // Reuse the existing metadata intake marker, as the FT face bridge does.
        // Canonical-name, alias and optional tree-mapping proposals never schedule a photo rewrite.
        DB::table('file_registry')->where('id', $face->file_registry_id)->where('status', 'active')
            ->update(['exif_faces_written' => 0]);
        // person_name is a previous internal label, not proof of an embedded label.
        // Canonical name, source proof and legacy verification/FT decisions remain separate.
        DB::table('file_registry_faces')->where('id', $face->id)->update([
            'global_identity_uuid' => $decision['identity_uuid'], 'global_identity_approval_receipt_id' => $receipt,
            'updated_at' => now(),
        ]);
    }

    private function validateRegion(mixed $region): void
    {
        if (! is_array($region) || count($region) !== 4 || array_diff(['x', 'y', 'w', 'h'], array_keys($region))) {
            throw new InvalidArgumentException('Exact normalized region required.');
        }
        foreach ($region as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException('Finite numeric face coordinates required.');
            }
        }
        if ($region['x'] < 0 || $region['y'] < 0 || $region['w'] <= 0 || $region['h'] <= 0
            || $region['x'] + $region['w'] > 1 || $region['y'] + $region['h'] > 1) {
            throw new InvalidArgumentException('Face region is outside the encoded image.');
        }
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);
            foreach ($value as $key => $child) {
                $value[$key] = json_decode($this->canonicalJson($child), true);
            }
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
