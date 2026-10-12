<?php

namespace App\Services\Genealogy;

use App\Services\FileRegistryService;
use App\Services\NextcloudFileApiService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FaceMatchReviewDecisionService
{
    public function __construct(private FaceLinkBridgeService $faceLinkBridgeService) {}

    /**
     * Confirm the queued suggestion and materialize its face/person-media bridge.
     * This method is only called from explicit operator review actions.
     *
     * @return array<string, mixed>
     */
    public function approve(int $matchId, ?string $notes = null, array $decisionMeta = []): array
    {
        return $this->confirm($matchId, null, $notes, $decisionMeta);
    }

    public function link(int $matchId, int $personId, ?string $notes = null, array $decisionMeta = []): array
    {
        return $this->confirm($matchId, $personId, $notes, $decisionMeta);
    }

    /** Save only an explicitly selected current region. The identity remains undecided. */
    public function refreshRegion(int $matchId, ?string $notes = null, array $meta = []): array
    {
        if (($meta['decision_scope'] ?? null) !== 'review_current_face_region') {
            return $this->error('Explicit current-region review is required.', 'face_region_review_required');
        }
        if (! is_string($meta['receipt_id'] ?? null) || ! Str::isUuid($meta['receipt_id'])) {
            return $this->error('An owned current-region review receipt is required.', 'face_region_receipt_required');
        }
        try {
            return DB::transaction(function () use ($matchId, $notes, $meta): array {
                $match = DB::table('genealogy_face_match_queue')->where('id', $matchId)->lockForUpdate()->first();
                if (! $match || $match->status !== 'pending') {
                    return $this->error('Queue entry is no longer pending.', 'already_resolved');
                }
                $proof = $this->faceLinkBridgeService->validateRegionReviewEvidence($match, $meta);
                if (! ($proof['success'] ?? false)) {
                    return $proof;
                }
                $details = json_decode($match->match_details ?: '{}', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($details) || (isset($details['region_reviews']) && ! array_is_list($details['region_reviews']))) {
                    return $this->error('Existing review history requires repair.', 'review_details_invalid');
                }
                $face = empty($match->file_registry_face_id) ? null : DB::table('file_registry_faces')
                    ->where('id', $match->file_registry_face_id)->lockForUpdate()->first();
                if ($face && ! empty($face->genealogy_person_id) && ! empty($face->source_current) && empty($face->superseded_at)
                    && ($face->source_sha256 ?? null) === $proof['source_sha256']) {
                    return $this->error('This current face already has an identity link; review that conflict first.', 'confirmed_face_conflict');
                }
                if (! empty($match->file_registry_face_id) && empty($match->media_id)) {
                    return $this->error('A tree-scoped media record is required before detaching this reference.', 'media_tree_mismatch');
                }
                // FRF coordinates are DECIMAL(10,8). Persist the same canonical
                // box in queue/history so exact crop pins survive storage rounding.
                foreach (['x', 'y', 'w', 'h'] as $axis) {
                    $proof['region'][$axis] = round($proof['region'][$axis], 8);
                }
                if (floor($proof['region']['w'] * $proof['source_dimensions'][0]) < 1
                    || floor($proof['region']['h'] * $proof['source_dimensions'][1]) < 1
                    || $proof['region']['x'] + $proof['region']['w'] > 1 || $proof['region']['y'] + $proof['region']['h'] > 1) {
                    return $this->error('Selected region cannot be stored precisely; select it again.', 'face_region_invalid');
                }
                $this->assertRegionNotDismissed($match, $proof);
                $registry = app(FileRegistryService::class)->registerReviewedFaceSource($proof, $meta['receipt_id']);
                $currentFaceId = $this->materializeAnonymousRegion((int) $registry['id'], $proof);
                $details['region_reviews'][] = [
                    'decision_scope' => 'review_current_face_region', 'reviewed_at' => now()->utc()->toIso8601String(),
                    'actor' => $meta['actor'] ?? 'operator', 'reviewer_user_id' => $this->reviewerUserId(), 'notes' => $notes,
                    'previous' => ['file_registry_face_id' => $match->file_registry_face_id ?? null,
                        'face_region' => $match->face_region ?? null, 'face_name' => $match->face_name ?? null,
                        'suggested_person_id' => $match->suggested_person_id ?? null, 'match_type' => $match->match_type ?? null,
                        'review_notes' => $match->review_notes ?? null, 'reviewed_at' => $match->reviewed_at ?? null,
                        'source_sha256' => $details['source_sha256'] ?? null, 'source_path' => $details['source_path'] ?? null,
                        'face_region_convention' => $details['face_region_convention'] ?? null,
                        'detector_row' => $face ? (array) $face : null],
                    'current' => $proof, 'current_face_record_id' => $currentFaceId,
                    'receipt_id' => $meta['receipt_id'], 'identity_decision_changed' => false,
                ];
                foreach (['source_sha256', 'source_path', 'source_dimensions', 'coordinate_basis', 'face_region_convention'] as $field) {
                    $details[$field] = $proof[$field];
                }
                $current = app(NextcloudFileApiService::class)->computeFileHash($proof['source_path']);
                if (! ($current['success'] ?? false) || ! hash_equals($proof['source_sha256'], (string) ($current['hash'] ?? ''))) {
                    throw new \RuntimeException('Photo changed after current-source registration.');
                }
                $updated = DB::table('genealogy_face_match_queue')->where('id', $matchId)->where('status', 'pending')->update([
                    'file_registry_face_id' => $currentFaceId, 'face_region' => json_encode($proof['region'], JSON_THROW_ON_ERROR),
                    'face_name' => '', 'suggested_person_id' => null, 'match_type' => 'no_match', 'confidence_score' => 0,
                    'match_details' => json_encode($details, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
                if ($updated !== 1) {
                    throw new \RuntimeException('Pending queue changed during region review.');
                }

                return ['success' => true, 'message' => 'Anonymous current face saved. Name and tree links remain unconfirmed.',
                    'file_registry_face_id' => $currentFaceId,
                    'desk_source_state' => 'pending', 'operator_identity_decision' => false];
            });
        } catch (Throwable) {
            return $this->error('Region review could not be saved.', 'region_review_failed');
        }
    }

    /** Preserve explicit Not a face decisions for this exact source and box. */
    private function assertRegionNotDismissed(object $match, array $proof): void
    {
        $rows = DB::table('genealogy_face_match_queue')->where('tree_id', $match->tree_id)
            ->where('media_id', $match->media_id)->where('status', 'rejected')->get(['match_details']);
        foreach ($rows as $row) {
            $details = json_decode($row->match_details ?: '{}', true, flags: JSON_THROW_ON_ERROR);
            foreach ($details['false_detection_reviews'] ?? [] as $review) {
                $evidence = $review['current_evidence'] ?? [];
                if (($evidence['source_sha256'] ?? null) === $proof['source_sha256']
                    && $this->sameRegion($evidence['region'] ?? [], $proof['region'])) {
                    throw new \RuntimeException('This source region was explicitly rejected as Not a face.');
                }
            }
        }
    }

    /** One selected anonymous observation; this does not claim a complete face scan. */
    private function materializeAnonymousRegion(int $fileId, array $proof): int
    {
        $faces = DB::table('file_registry_faces')->where('file_registry_id', $fileId)
            ->where('source_sha256', $proof['source_sha256'])->where('source_current', 1)->lockForUpdate()->get();
        $matches = [];
        foreach ($faces as $face) {
            $region = [];
            foreach (['x', 'y', 'w', 'h'] as $axis) {
                $region[$axis] = $face->{'region_'.$axis};
            }
            if (! $this->sameRegion($region, $proof['region'])) {
                continue;
            }
            $matches[] = $face;
        }
        if (count($matches) > 1) {
            throw new \RuntimeException('Multiple current records share this region; reconcile them first.');
        }
        if ($matches !== []) {
            $face = $matches[0];
            if (! empty($face->hidden) || ! empty($face->superseded_at) || ! empty($face->verified)
                || trim((string) $face->person_name) !== '' || ! empty($face->genealogy_person_id)
                || ! empty($face->global_identity_uuid) || ! empty($face->global_identity_approval_receipt_id)
                || ! empty($face->cluster_id) || $face->face_region_convention !== 'normalized_top_left') {
                throw new \RuntimeException('Selected region already has evidence requiring separate review.');
            }

            return (int) $face->id;
        }
        $lastIndex = DB::table('file_registry_faces')->where('file_registry_id', $fileId)->max('face_index');
        $index = $lastIndex === null ? 0 : (int) $lastIndex + 1;
        if ($index > 65535) {
            throw new \RuntimeException('Face index capacity requires reconciliation.');
        }

        return (int) DB::table('file_registry_faces')->insertGetId([
            'file_registry_id' => $fileId, 'face_index' => $index, 'person_name' => '', 'source' => 'manual',
            'region_x' => $proof['region']['x'], 'region_y' => $proof['region']['y'],
            'region_w' => $proof['region']['w'], 'region_h' => $proof['region']['h'],
            'source_sha256' => $proof['source_sha256'], 'source_generation_key' => $proof['source_sha256'],
            'face_region_convention' => 'normalized_top_left', 'source_current' => 1,
            'superseded_at' => null, 'hidden' => 0, 'verified' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sameRegion(array $left, array $right): bool
    {
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if (! is_numeric($left[$axis] ?? null) || ! is_numeric($right[$axis] ?? null)
                || abs((float) $left[$axis] - (float) $right[$axis]) > 0.00000001) {
                return false;
            }
        }

        return true;
    }

    public function dismissFalseDetection(int $matchId, ?string $notes = null, array $meta = []): array
    {
        if (($meta['decision_scope'] ?? null) !== 'dismiss_false_face_detection') {
            return $this->error('Explicit Not a face review is required.', 'false_detection_review_required');
        }
        try {
            return DB::transaction(function () use ($matchId, $notes, $meta): array {
                $match = DB::table('genealogy_face_match_queue')->where('id', $matchId)->lockForUpdate()->first();
                if (! $match || $match->status !== 'pending') {
                    return $this->error('Queue entry is no longer pending.', 'already_resolved');
                }
                $proof = $this->faceLinkBridgeService->validateRegionReviewEvidence($match, $meta);
                if (! ($proof['success'] ?? false)) {
                    return $proof;
                }
                $details = json_decode($match->match_details ?: '{}', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($details) || (isset($details['false_detection_reviews']) && ! array_is_list($details['false_detection_reviews']))) {
                    return $this->error('Existing review history requires repair.', 'review_details_invalid');
                }
                $details['false_detection_reviews'][] = ['decision_scope' => 'dismiss_false_face_detection',
                    'reason_code' => 'false_detection', 'actor' => $meta['actor'] ?? 'operator',
                    'reviewer_user_id' => $this->reviewerUserId(),
                    'reviewed_at' => now()->utc()->toIso8601String(), 'notes' => $notes,
                    'previous' => ['file_registry_face_id' => $match->file_registry_face_id ?? null,
                        'face_region' => $match->face_region ?? null, 'face_name' => $match->face_name ?? null,
                        'suggested_person_id' => $match->suggested_person_id ?? null,
                        'review_notes' => $match->review_notes ?? null, 'reviewed_at' => $match->reviewed_at ?? null],
                    'current_evidence' => $proof, 'identity_decision_changed' => false];
                $current = app(NextcloudFileApiService::class)->computeFileHash($proof['source_path']);
                if (! ($current['success'] ?? false) || ! hash_equals($proof['source_sha256'], (string) ($current['hash'] ?? ''))) {
                    return $this->error('Photo changed; reload.', 'source_photo_changed');
                }
                $changed = DB::table('genealogy_face_match_queue')->where('id', $matchId)->where('status', 'pending')->update([
                    'status' => 'rejected', 'match_details' => json_encode($details, JSON_THROW_ON_ERROR),
                    'review_notes' => $notes, 'reviewed_by' => $this->reviewerUserId(), 'reviewed_at' => now(), 'updated_at' => now(),
                ]);
                if ($changed !== 1) {
                    throw new \RuntimeException('Queue changed during Not a face review.');
                }

                return ['success' => true, 'desk_source_state' => 'rejected', 'operator_identity_decision' => false,
                    'message' => 'Recorded Not a face. Only this queue entry was closed.'];
            });
        } catch (Throwable) {
            return $this->error('Not a face review could not be saved.', 'false_detection_review_failed');
        }
    }

    private function confirm(int $matchId, ?int $selectedPersonId, ?string $notes, array $decisionMeta): array
    {
        if (($decisionMeta['decision_scope'] ?? null) !== 'confirm_face_identity_link') {
            return $this->error(
                'Open the picture match detail and confirm the visible identity evidence before linking this face.',
                'face_identity_confirmation_required'
            );
        }
        $reviewerUserId = $this->reviewerUserId();
        DB::beginTransaction();
        try {
            $match = DB::selectOne('SELECT * FROM genealogy_face_match_queue WHERE id = ? FOR UPDATE', [$matchId]);
            if (! $match || ($match->status ?? null) !== 'pending') {
                DB::rollBack();

                return $this->error('Face match queue entry is missing or no longer pending.', $match ? 'already_resolved' : 'not_found');
            }
            $personId = $selectedPersonId ?? (int) ($match->suggested_person_id ?? 0);
            if ($personId <= 0 || ($selectedPersonId === null && ($match->match_type ?? null) === 'no_match')) {
                DB::rollBack();

                return $this->error('No suggested person is available; use Link to Person instead.', 'person_required');
            }
            $evidence = $this->faceLinkBridgeService->validateReviewEvidence($match, $personId, $decisionMeta);
            if (! ($evidence['success'] ?? false)) {
                DB::rollBack();

                return $this->error($evidence['error'] ?? 'Current picture evidence requires review.', $evidence['error_code'] ?? 'face_evidence_unavailable');
            }
            $details = is_string($match->match_details ?? null) ? json_decode($match->match_details, true) : ($match->match_details ?? []);
            $confirmations = $details['operator_confirmations'] ?? [];
            if (! is_array($confirmations)) {
                throw new \RuntimeException('Existing operator decision history is invalid.');
            }
            if (in_array($personId, array_map('intval', $details['rejected_candidate_ids'] ?? []), true)) {
                DB::rollBack();

                return $this->error('This person was previously rejected; resolve that recorded decision first.', 'prior_person_rejection');
            }
            foreach ($details['candidate_decisions'] ?? [] as $decision) {
                if ((int) ($decision['genealogy_person_id'] ?? $decision['person_id'] ?? 0) === $personId
                    && in_array($decision['action'] ?? '', ['not_this_person', 'reject', 'rejected'], true)) {
                    DB::rollBack();

                    return $this->error('This person was previously rejected; resolve that recorded decision first.', 'prior_person_rejection');
                }
            }
            if (! empty($match->media_id)) {
                $links = DB::table('genealogy_person_media')->where('media_id', $match->media_id)->where('face_confirmed', 1)->lockForUpdate()->get();
                foreach ($links as $link) {
                    $sameRegion = true;
                    foreach (['x', 'y', 'w', 'h'] as $axis) {
                        $sameRegion = $sameRegion && is_numeric($link->{'face_region_'.$axis})
                            && abs((float) $link->{'face_region_'.$axis} - $evidence['region'][$axis]) <= (float) config('genealogy.face_region_match_tolerance');
                    }
                    if (($sameRegion && (int) $link->person_id !== $personId) || (! $sameRegion && (int) $link->person_id === $personId)) {
                        DB::rollBack();

                        return $this->error('An existing confirmed photo link conflicts with this selection.', 'confirmed_media_link_conflict');
                    }
                }
            }
            $bridgeResult = null;
            if (! empty($match->file_registry_face_id)) {
                $bridgeResult = $this->faceLinkBridgeService->syncFaceLink(
                    (int) $match->file_registry_face_id, $personId,
                    ! empty($match->media_id) ? (int) $match->media_id : null, $evidence
                );
                if (! ($bridgeResult['success'] ?? false)) {
                    throw new \RuntimeException('Referenced face evidence could not be linked.');
                }
            } else {
                // A genuinely media-only operator review has already validated its source and exact region.
                $this->materializeGenealogyMediaLink($match, $personId, $evidence['region']);
            }
            $confirmations[] = [
                'person_id' => $personId,
                'file_registry_face_id' => $match->file_registry_face_id ?? null,
                'media_id' => $bridgeResult['media_id'] ?? $match->media_id ?? null,
                'region' => $evidence['region'], 'face_region_convention' => 'normalized_top_left',
                'source_sha256' => $evidence['source_sha256'], 'source_path' => $evidence['source_path'],
                'source_dimensions' => $evidence['source_dimensions'],
                'previous_person_name' => $evidence['previous_person_name'],
                'previous_person_id' => $evidence['previous_person_id'] ?? null,
                'previous_verified' => $evidence['previous_verified'] ?? null,
                'previous_suggested_person_id' => $match->suggested_person_id ?? null,
                'previous_match_type' => $match->match_type ?? null,
                'decision_scope' => 'confirm_face_identity_link',
                'queued_face_name' => $match->face_name ?? null,
                'previous_face_region' => $match->face_region ?? null,
                'previous_review_notes' => $match->review_notes ?? null,
                'person_name' => $evidence['person_name'], 'notes' => $notes,
                'reviewer_user_id' => $reviewerUserId,
                'reviewed_at' => now()->utc()->toIso8601String(),
            ];
            $details['operator_confirmations'] = $confirmations;
            $updated = DB::update(
                "UPDATE genealogy_face_match_queue
                 SET status = 'approved', suggested_person_id = ?, reviewed_at = NOW(), review_notes = ?,
                     match_details = ?, match_type = CASE WHEN ? = 1 THEN 'manual' ELSE match_type END, reviewed_by = ?, updated_at = NOW()
                 WHERE id = ? AND status = 'pending'",
                [$personId, $notes, json_encode($details, JSON_THROW_ON_ERROR), (int) ($selectedPersonId !== null), $reviewerUserId, $matchId]
            );
            if ($updated !== 1) {
                throw new \RuntimeException('Face match changed before approval completed.');
            }
            DB::commit();
            Log::info('Face identity confirmed and linked by operator review', ['queue_id' => $matchId, 'person_id' => $personId, 'media_id' => $match->media_id ?? null]);

            return [
                'success' => true, 'message' => 'Picture match approved and linked to the confirmed person.',
                'genealogy_bridge' => $bridgeResult, 'operator_identity_decision' => true,
                'person_id' => $personId, 'person_name' => $evidence['person_name'],
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Face match operator approval failed', ['queue_id' => $matchId, 'exception' => $e::class]);

            return $this->error('Failed to approve and link picture match.', 'approval_failed');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(int $matchId, ?string $reason = null, array $decisionMeta = []): array
    {
        if (($decisionMeta['decision_scope'] ?? null) !== 'reject_face_identity_suggestion') {
            return $this->error(
                'Open the picture match detail before rejecting this identity suggestion.',
                'face_identity_review_required'
            );
        }

        $updated = DB::update(
            "UPDATE genealogy_face_match_queue
             SET status = 'rejected', reviewed_at = NOW(), review_notes = ?, reviewed_by = ?, updated_at = NOW()
             WHERE id = ? AND status = 'pending'
               AND suggested_person_id IS NOT NULL AND suggested_person_id > 0
               AND (match_type IS NULL OR match_type != 'no_match')",
            [$reason, $this->reviewerUserId(), $matchId]
        );

        if ($updated !== 1) {
            return $this->error('No pending identity suggestion is available to reject. Unidentified faces need research, not rejection.', 'identity_suggestion_unavailable');
        }

        Log::info('Face match rejected by operator review', ['queue_id' => $matchId]);

        return [
            'success' => true,
            'message' => 'Picture match rejected; no identity link was changed.',
            'operator_identity_decision' => true,
        ];
    }

    /** Session attribution is never accepted from decision metadata or request input. */
    private function reviewerUserId(): ?int
    {
        $id = Auth::guard('web')->id();
        if ($id === null) {
            // Non-web operator commands retain unknown attribution.
            return null;
        }
        $validated = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new \RuntimeException('Authenticated reviewer identity is invalid.');
        }

        return $validated;
    }

    private function materializeGenealogyMediaLink(object $match, int $personId, array $faceRegion): void
    {
        $mediaId = (int) $match->media_id;
        $existing = DB::selectOne('SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?', [$personId, $mediaId]);
        $coordinates = [$faceRegion['x'], $faceRegion['y'], $faceRegion['w'], $faceRegion['h']];
        if ($existing) {
            DB::update('UPDATE genealogy_person_media SET face_region_x = ?, face_region_y = ?, face_region_w = ?, face_region_h = ?, face_confirmed = 1 WHERE id = ?', [...$coordinates, $existing->id]);
        } else {
            DB::insert('INSERT INTO genealogy_person_media (person_id, media_id, face_region_x, face_region_y, face_region_w, face_region_h, face_confirmed, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())', [$personId, $mediaId, ...$coordinates]);
        }
    }

    /**
     * @return array{success:false,error:string,error_code:string}
     */
    private function error(string $message, string $code): array
    {
        return [
            'success' => false,
            'error' => $message,
            'error_code' => $code,
        ];
    }
}
