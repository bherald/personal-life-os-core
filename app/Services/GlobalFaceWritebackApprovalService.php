<?php

namespace App\Services;

use App\Services\Genealogy\FaceLinkBridgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Read-only proof of one global identity on one current, explicitly reviewed face. */
class GlobalFaceWritebackApprovalService
{
    public const TRANSITION_ACTION = 'metadata_transition';

    public function forFile(int $fileId): array
    {
        try {
            $rows = DB::select('SELECT id FROM file_registry_faces WHERE file_registry_id = ? AND global_identity_uuid IS NOT NULL', [$fileId]);
            $approved = [];
            foreach ($rows as $row) {
                $face = $this->approvedFace((int) $row->id);
                if ($face !== null) {
                    $approved[] = $face;
                }
            }

            return ['available' => true, 'claimed_ids' => array_map(fn ($row) => (int) $row->id, $rows), 'approved' => $approved];
        } catch (Throwable) {
            return ['available' => false, 'claimed_ids' => [], 'approved' => []];
        }
    }

    public function approvedFace(int $faceId): ?object
    {
        try {
            $face = DB::selectOne('SELECT f.*, fr.current_path, fr.status AS file_status,
                identity.canonical_name, identity.status AS identity_status, identity.approval_receipt_id AS name_approval_receipt_id
                FROM file_registry_faces f JOIN file_registry fr ON fr.id = f.file_registry_id
                JOIN global_face_identities identity ON identity.identity_uuid = f.global_identity_uuid WHERE f.id = ?', [$faceId]);
            if (! $face || $face->identity_status !== 'operator_confirmed' || trim($face->canonical_name) === '') {
                return null;
            }
            $nameReceipt = $this->receipt($face->name_approval_receipt_id, 'create_identity');
            $nameDecision = $nameReceipt['payload']['global_identity_decision'] ?? [];
            if ($nameReceipt === null || ($nameDecision['identity_uuid'] ?? null) !== $face->global_identity_uuid
                || ($nameDecision['canonical_name'] ?? null) !== $face->canonical_name) {
                return null;
            }
            $approval = $this->receipt($face->global_identity_approval_receipt_id, 'confirm_face');
            $decision = $approval['payload']['global_identity_decision'] ?? [];
            if ($approval === null || ($approval['payload']['decision_scope'] ?? null) !== 'confirm_current_face_global_identity'
                || ($decision['identity_uuid'] ?? null) !== $face->global_identity_uuid
                || ($decision['face_id'] ?? null) !== (int) $face->id
                || ($decision['face_region_convention'] ?? null) !== 'normalized_top_left'
                || ($decision['coordinate_basis'] ?? null) !== 'encoded_pixels'
                || ! $this->hash($decision['source_sha256'] ?? null) || ! $this->sameRegion($face, $decision['region'] ?? null)) {
                return null;
            }
            // A later rejection or unresolved decision never becomes permission to write.
            if (DB::selectOne("SELECT id FROM review_desk_receipts WHERE reference = ? AND id <> ?
                AND (state IN ('processing', 'unknown') OR (state = 'completed' AND action IN ('reject', 'false_detection') AND created_at >= ?)) LIMIT 1",
                [$approval['row']->reference, $approval['row']->id, $approval['row']->created_at])) {
                return null;
            }
            $proof = app(FaceLinkBridgeService::class)->validateFaceSourceEvidence($face);
            if (($proof['success'] ?? false) !== true || ! $this->sourceChain($face, $approval, $decision['source_sha256'])) {
                return null;
            }
            $face->person_name = $face->canonical_name;

            return $face;
        } catch (Throwable) {
            return null;
        }
    }

    /** Read an existing approved mapping; matching names never prove membership. */
    public function approvedGenealogyMembership(string $identityUuid, int $treeId, int $personId): ?object
    {
        try {
            $rows = DB::table('global_face_identity_genealogy_links')->where('identity_uuid', $identityUuid)
                ->where('tree_id', $treeId)->where('genealogy_person_id', $personId)->limit(2)->get();
            if ($rows->count() !== 1 || DB::table('global_face_identity_genealogy_links')
                ->where('tree_id', $treeId)->where('genealogy_person_id', $personId)->where('identity_uuid', '!=', $identityUuid)->exists()) {
                return null;
            }
            $membership = $rows[0];
            $proof = $this->receipt($membership->approval_receipt_id, 'map_genealogy');
            $decision = $proof['payload']['global_identity_decision'] ?? [];
            if ($proof === null || ($decision['identity_uuid'] ?? null) !== $identityUuid
                || ($decision['tree_id'] ?? null) !== $treeId || ($decision['genealogy_person_id'] ?? null) !== $personId
                || ! DB::table('genealogy_persons')->where('id', $personId)->where('tree_id', $treeId)->exists()
                || DB::table('review_desk_receipts')->where('reference', $proof['row']->reference)
                    ->where('id', '!=', $proof['row']->id)
                    ->where(fn ($q) => $q->whereIn('state', ['processing', 'unknown'])
                        ->orWhere(fn ($q) => $q->where('state', 'completed')->where('action', 'reject')
                            ->where('created_at', '>=', $proof['row']->created_at)))->exists()) {
                return null;
            }

            return $membership;
        } catch (Throwable) {
            return null;
        }
    }

    private function receipt(mixed $id, string $operation): ?array
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }
        $row = DB::selectOne('SELECT * FROM review_desk_receipts WHERE id = ?', [$id]);
        $payload = $row ? json_decode($row->payload, true) : null;
        if (! $row || $row->state !== 'completed' || ! is_array($payload)
            || ($payload['success'] ?? false) !== true || ($payload['decision_recorded'] ?? false) !== true
            || ! is_string($payload['actor'] ?? null) || trim($payload['actor']) === ''
            || ($payload['decision_scope'] ?? null) !== (GlobalFaceIdentityService::SCOPES[$operation] ?? null)
            || ($payload['global_identity_decision']['operation'] ?? null) !== $operation) {
            return null;
        }
        if ($row->action === $operation && $payload['actor'] === 'operator') {
            return ['row' => $row, 'payload' => $payload];
        }
        if ($row->action !== 'approve' || ($payload['reference'] ?? null) !== $row->reference) {
            return null;
        }
        $reference = DB::selectOne("SELECT * FROM review_desk_references WHERE reference = ? AND review_type = 'global_face_identity'
            AND source = 'default:agent_review_queue' AND source_state = 'approved'", [$row->reference]);
        $queue = $reference ? DB::selectOne("SELECT details FROM agent_review_queue WHERE id = ? AND review_type = 'global_face_identity' AND status = 'approved'", [$reference->source_id]) : null;
        $details = $queue ? json_decode($queue->details, true) : null;
        if (! is_array($details) || $this->digest($details['global_identity_decision'] ?? null) !== $this->digest($payload['global_identity_decision'])) {
            return null;
        }

        return ['row' => $row, 'payload' => $payload];
    }

    private function sourceChain(object $face, array $approval, string $originalHash): bool
    {
        if ($originalHash === $face->source_sha256) {
            return true;
        }
        $limit = max(1, min(256, (int) config('metadata_writeback.max_face_transition_hops', 64)));
        $rows = DB::select('SELECT * FROM review_desk_receipts WHERE reference = ? AND action = ? AND state = ? ORDER BY created_at ASC, id ASC LIMIT '.($limit + 1),
            [$approval['row']->reference, self::TRANSITION_ACTION, 'completed']);
        if (count($rows) > $limit) {
            return false;
        }
        $hash = $originalHash;
        $seen = [];
        $imageProof = null;
        for ($hop = 0; $hop < $limit && $hash !== $face->source_sha256; $hop++) {
            $matches = [];
            foreach ($rows as $row) {
                $payload = json_decode($row->payload, true);
                if (is_array($payload) && ($payload['before_sha256'] ?? null) === $hash
                    && ($payload['face_id'] ?? null) === (int) $face->id) {
                    $matches[] = [$row, $payload];
                }
            }
            if (count($matches) !== 1 || isset($seen[$hash])) {
                return false;
            }
            $seen[$hash] = true;
            [$row, $transition] = $matches[0];
            if (! $this->transitionMatches($face, $approval['row'], $row, $transition, $imageProof)) {
                return false;
            }
            $imageProof = $transition['image_proof'];
            $hash = $transition['after_sha256'];
        }

        return $hash === $face->source_sha256;
    }

    private function transitionMatches(object $face, object $approval, object $row, array $p, ?array $imageProof): bool
    {
        if (($p['schema'] ?? null) !== 'plos.global_face_metadata_transition.v1' || ($p['kind'] ?? null) !== 'verified_metadata_only'
            || ($p['actor'] ?? null) !== 'system/verified-metadata' || ($p['success'] ?? false) !== true
            || ($p['decision_recorded'] ?? null) !== false || ($p['identity_decision_changed'] ?? null) !== false
            || ($p['face_id'] ?? null) !== (int) $face->id || ($p['file_registry_id'] ?? null) !== (int) $face->file_registry_id
            || ($p['identity_uuid'] ?? null) !== $face->global_identity_uuid || ($p['approval_receipt_id'] ?? null) !== $approval->id
            || ($p['source_path'] ?? null) !== $face->current_path || ! $this->sameRegion($face, $p['region'] ?? null)
            || ($p['face_region_convention'] ?? null) !== 'normalized_top_left' || ($p['coordinate_basis'] ?? null) !== 'encoded_pixels'
            || ! $this->hash($p['before_sha256'] ?? null) || ! $this->hash($p['after_sha256'] ?? null)
            || $p['before_sha256'] === $p['after_sha256'] || ! is_array($p['image_proof'] ?? null)
            || ($p['image_proof']['method'] ?? null) !== 'gd_decoded_rgba_sha256_v1'
            || ! is_int($p['image_proof']['width'] ?? null) || $p['image_proof']['width'] < 1
            || ! is_int($p['image_proof']['height'] ?? null) || $p['image_proof']['height'] < 1
            || ! $this->hash($p['image_proof']['pixels_sha256'] ?? null) || ! $this->hash($p['image_proof']['icc_sha256'] ?? null)
            || ! is_array($p['image_proof']['orientation'] ?? null)
            || ($imageProof !== null && $this->digest($imageProof) !== $this->digest($p['image_proof']))
            || ! hash_equals($row->request_digest, $this->digest($p))) {
            return false;
        }
        $packetPath = $this->privatePath($p['packet_path'] ?? null);
        if ($packetPath === null || ! $this->hash($p['packet_sha256'] ?? null)
            || hash_file('sha256', $packetPath) !== $p['packet_sha256']) {
            return false;
        }
        $maxBytes = max(1, (int) config('metadata_writeback.max_transition_receipt_bytes', 8388608));
        if (filesize($packetPath) > $maxBytes) {
            return false;
        }
        $packet = json_decode(file_get_contents($packetPath), true);
        $backupPath = $this->privatePath($packet['backup']['backup_path'] ?? null);
        $backupReceiptPath = $this->privatePath($packet['backup']['backup_receipt_path'] ?? $packet['backup']['receipt_path'] ?? null);
        $backupReceipt = $backupReceiptPath ? json_decode(file_get_contents($backupReceiptPath), true) : null;
        if (! is_array($packet) || ($packet['kind'] ?? null) !== 'verified_metadata_only'
            || ($packet['before_sha256'] ?? null) !== $p['before_sha256'] || ($packet['after_sha256'] ?? null) !== $p['after_sha256']
            || $this->digest($packet['image_proof'] ?? null) !== $this->digest($p['image_proof'])
            || (int) ($packet['registry_before']['id'] ?? 0) !== (int) $face->file_registry_id
            || ($packet['registry_before']['current_path'] ?? null) !== $face->current_path
            || $backupPath === null || hash_file('sha256', $backupPath) !== $p['before_sha256']
            || ! is_array($backupReceipt) || ($backupReceipt['source_sha256'] ?? null) !== $p['before_sha256']
            || ($backupReceipt['backup_sha256'] ?? null) !== $p['before_sha256']) {
            return false;
        }
        foreach ($packet['faces_before'] ?? [] as $beforeFace) {
            if ((int) ($beforeFace['id'] ?? 0) === (int) $face->id) {
                return ($beforeFace['global_identity_uuid'] ?? null) === $face->global_identity_uuid
                    && ($beforeFace['global_identity_approval_receipt_id'] ?? null) === $approval->id
                    && ($beforeFace['source_sha256'] ?? null) === $p['before_sha256']
                    && ($beforeFace['face_region_convention'] ?? null) === 'normalized_top_left'
                    && ! empty($beforeFace['source_current']) && empty($beforeFace['superseded_at'])
                    && ($beforeFace['source_generation_key'] ?? null) === ($face->source_generation_key ?? null)
                    && $this->sameRegion((object) $beforeFace, $p['region']);
            }
        }

        return false;
    }

    private function privatePath(mixed $relative): ?string
    {
        if (! is_string($relative) || ! str_starts_with($relative, 'app/private/') || str_contains($relative, '\\')
            || str_contains($relative, "\0") || array_intersect(['', '.', '..'], explode('/', $relative))) {
            return null;
        }
        $path = storage_path();
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            if (is_link($path)) {
                return null;
            }
        }
        $root = realpath(storage_path('app/private'));
        $real = realpath($path);

        return $root && $real && is_file($real) && is_readable($real) && str_starts_with($real, $root.'/') ? $real : null;
    }

    private function sameRegion(object $face, mixed $region): bool
    {
        if (! is_array($region) || count($region) !== 4) {
            return false;
        }
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if (! is_numeric($region[$axis] ?? null) || ! is_finite((float) $region[$axis])
                || ! is_numeric($face->{'region_'.$axis} ?? null)
                || abs((float) $face->{'region_'.$axis} - (float) $region[$axis]) > 0.00000001) {
                return false;
            }
        }

        return $region['x'] >= 0 && $region['y'] >= 0 && $region['w'] > 0 && $region['h'] > 0
            && $region['x'] + $region['w'] <= 1 && $region['y'] + $region['h'] <= 1;
    }

    public function digest(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (is_array($item)) {
                ksort($item);

                return array_map($sort, $item);
            }

            return $item;
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
