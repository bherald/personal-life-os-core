<?php

namespace App\Services\Review;

use Illuminate\Support\Str;

class FaceMatchReviewPresentationService
{
    /**
     * Build the minimum browser payload needed for an operator-owned identity decision.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $registeredType
     * @return array<string, mixed>|null
     */
    public function project(array $item, array $registeredType): ?array
    {
        $id = $this->positiveInt($item['id'] ?? null);
        if ($id === null || ($item['unified_id'] ?? null) !== "face:{$id}") {
            return null;
        }

        $region = $this->faceRegion($item['face_region'] ?? null);
        $suggestedName = $this->safeText($item['suggested_person_name'] ?? null, 240);
        $matchType = $this->safeCode($item['match_type'] ?? null);
        $hasSuggestedPerson = $matchType !== 'no_match'
            && $this->positiveInt($item['suggested_person_id'] ?? null) !== null;
        $imageAvailable = filter_var($item['image_available'] ?? false, FILTER_VALIDATE_BOOL);
        $actions = [];
        if ($hasSuggestedPerson && ($registeredType['approve_method'] ?? null) === 'approve') {
            $actions[] = 'approve';
        }
        if ($hasSuggestedPerson && ($registeredType['reject_method'] ?? null) === 'reject') {
            $actions[] = 'reject';
        }

        $blockers = [];
        if (! $hasSuggestedPerson || $suggestedName === '') {
            $blockers[] = 'suggested_identity_missing';
        }
        if (! $imageAvailable) {
            $blockers[] = 'visible_image_missing';
        }
        if ($region === null) {
            $blockers[] = 'detected_region_missing';
        }
        if (! in_array('approve', $actions, true)) {
            $blockers[] = 'approval_route_unavailable';
        }

        $faceName = $this->safeText($item['face_name'] ?? null, 240);

        return [
            'item' => [
                'id' => $id,
                'tree_id' => $this->positiveInt($item['tree_id'] ?? null),
                'unified_id' => "face:{$id}",
                'review_type' => 'face',
                'source' => 'face',
                'category' => 'genealogy',
                'title' => $this->safeText($item['title'] ?? 'Picture identity review', 300),
                'summary' => $this->safeText($item['summary'] ?? '', 1000),
                'confidence' => is_numeric($item['confidence'] ?? null)
                    ? max(0.0, min(1.0, (float) $item['confidence']))
                    : null,
                'status' => 'pending',
                'created_at' => $item['created_at'] ?? null,
                'match_type' => $matchType,
                'face_name' => $faceName,
                'image_url' => "/api/media/face-match-crop/{$id}",
                'image_available' => $imageAvailable,
                'face_region' => $region,
                'suggested_person_name' => $suggestedName,
                'suggested_person_birth_date' => $this->safeDate($item['suggested_person_birth_date'] ?? null),
                'suggested_person_death_date' => $this->safeDate($item['suggested_person_death_date'] ?? null),
                'actions' => $actions,
                'batch_enabled' => false,
            ],
            'face_review' => [
                'schema' => 'plos.face_match_review_context.v2',
                'operator_only' => true,
                'region_reviewed' => ($item['region_reviewed'] ?? false) === true,
                'source_sha256' => $item['source_sha256'] ?? null,
                'global_face' => null,
                'batch_enabled' => false,
                'decision_scope' => 'confirm_face_identity_link',
                'rejection_scope' => 'reject_face_identity_suggestion',
                'approval_ready' => $blockers === [],
                'rejection_ready' => $hasSuggestedPerson && in_array('reject', $actions, true),
                'has_identity_candidate' => $hasSuggestedPerson,
                'blockers' => $blockers,
                'operator_question' => ! $hasSuggestedPerson
                    ? 'Can you provide evidence to identify this face?'
                    : ($suggestedName !== ''
                    ? "Is the detected face {$suggestedName}?"
                    : 'Does the suggested identity match the detected face?'),
                'approval_label' => $suggestedName !== ''
                    ? "Approve and link {$suggestedName}"
                    : 'Approve and link identity',
                'confirmation_label' => 'I compared the detected face with the source photo and confirm the suggested identity.',
                'approval_effect' => 'Verifies this identity and may update the file-registry face identity and genealogy person-media link.',
                'rejection_effect' => 'Rejects this queue suggestion only. It does not create or change an identity link.',
                'evidence_is_proof' => false,
            ],
        ];
    }

    /** Stored snapshots only; pinned crop and proposal admission recheck physical bytes. */
    public function globalFaceForReview(object $queue, array $details, ?object $face): ?array
    {
        $id = $this->positiveInt($queue->file_registry_face_id ?? null);
        $mediaId = $this->positiveInt($queue->media_id ?? null);
        $treeId = $this->positiveInt($queue->tree_id ?? null);
        $hash = $details['source_sha256'] ?? null;
        if (! $face || $id === null || $mediaId === null || $treeId === null || ($queue->status ?? null) !== 'pending'
            || ($queue->match_type ?? null) !== 'no_match' || trim((string) ($queue->face_name ?? '')) !== ''
            || ! empty($queue->suggested_person_id) || $this->positiveInt($face->face_id ?? null) !== $id
            || $this->positiveInt($face->media_id ?? null) !== $mediaId || $this->positiveInt($face->media_tree_id ?? null) !== $treeId
            || ($face->file_status ?? null) !== 'active' || ! in_array($face->source_current ?? null, [1, '1', true], true)
            || ($face->superseded_at ?? null) !== null || ! in_array($face->hidden ?? null, [0, '0', false], true)
            || ! in_array($face->verified ?? null, [0, '0', false], true) || trim((string) ($face->person_name ?? '')) !== ''
            || ! property_exists($face, 'genealogy_person_id') || $face->genealogy_person_id !== null
            || ! property_exists($face, 'global_identity_uuid') || $face->global_identity_uuid !== null
            || ! property_exists($face, 'global_identity_approval_receipt_id') || $face->global_identity_approval_receipt_id !== null
            || ! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || ($face->source_sha256 ?? null) !== $hash
            || ($details['face_region_convention'] ?? null) !== 'normalized_top_left'
            || ($details['coordinate_basis'] ?? null) !== 'encoded_pixels'
            || ($face->face_region_convention ?? null) !== 'normalized_top_left') {
            return null;
        }
        $path = $this->normalizedSourcePath(($face->nextcloud_path ?? null) ?: ($face->media_original_path ?? null));
        if ($path === null || $this->normalizedSourcePath($face->current_path ?? null) !== $path
            || $this->normalizedSourcePath($details['source_path'] ?? null) !== $path) {
            return null;
        }
        $region = $this->faceRegion($queue->face_region ?? null);
        $stored = $this->faceRegion(['x' => $face->region_x ?? null, 'y' => $face->region_y ?? null,
            'w' => $face->region_w ?? null, 'h' => $face->region_h ?? null]);
        $reviews = $details['region_reviews'] ?? null;
        if ($region === null || $stored !== $region || ! is_array($reviews) || ! array_is_list($reviews) || $reviews === []) {
            return null;
        }
        $review = $reviews[array_key_last($reviews)];
        $current = is_array($review) ? ($review['current'] ?? null) : null;
        if (! is_array($current) || ($review['decision_scope'] ?? null) !== 'review_current_face_region'
            || ! is_string($review['actor'] ?? null) || trim($review['actor']) === ''
            || ! is_string($review['reviewed_at'] ?? null) || trim($review['reviewed_at']) === ''
            || ($review['identity_decision_changed'] ?? null) !== false
            || $this->positiveInt($review['current_face_record_id'] ?? null) !== $id
            || ! is_string($review['receipt_id'] ?? null) || ! Str::isUuid($review['receipt_id'])
            || ($current['success'] ?? null) !== true || ($current['source_sha256'] ?? null) !== $hash
            || ($current['face_region_convention'] ?? null) !== 'normalized_top_left'
            || ($current['coordinate_basis'] ?? null) !== 'encoded_pixels'
            || $this->normalizedSourcePath($current['source_path'] ?? null) !== $path
            || $this->faceRegion($current['region'] ?? null) !== $region) {
            return null;
        }
        $fingerprint = hash('sha256', $hash.json_encode($region, JSON_THROW_ON_ERROR));

        return ['face_id' => $id, 'file_status' => 'active', 'source_current' => 1, 'superseded_at' => null, 'hidden' => 0,
            'source_sha256' => $hash, 'face_region_convention' => 'normalized_top_left',
            'global_face_evidence' => ['face_id' => $id, 'source_sha256' => $hash, 'region' => $region,
                'face_region_convention' => 'normalized_top_left', 'coordinate_basis' => 'encoded_pixels',
                'region_fingerprint' => $fingerprint,
                'crop_url' => '/api/media/faces/registry-crop/'.$id.'?source_sha256='.$hash.'&region_fingerprint='.$fingerprint]];
    }

    private function normalizedSourcePath(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || str_contains($value, '\\') || str_contains($value, "\0")
            || in_array('..', explode('/', $value), true) || in_array('.', explode('/', $value), true)) {
            return null;
        }
        $path = ltrim($value, '/');

        return $path === '' ? null : $path;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $integer = (int) $value;

        return $integer > 0 && (string) $integer === trim((string) $value) ? $integer : null;
    }

    /** @return array{x: float, y: float, w: float, h: float}|null */
    private function faceRegion(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return null;
        }

        $region = [];
        foreach (['x', 'y', 'w', 'h'] as $key) {
            if (! is_numeric($value[$key] ?? null) || ! is_finite((float) $value[$key])) {
                return null;
            }
            $region[$key] = (float) $value[$key];
        }

        if ($region['x'] < 0 || $region['y'] < 0 || $region['w'] <= 0 || $region['h'] <= 0
            || $region['x'] + $region['w'] > 1 || $region['y'] + $region['h'] > 1) {
            return null;
        }

        return $region;
    }

    private function safeDate(mixed $value): ?string
    {
        $date = $this->safeText($value, 40);

        return $date !== '' && preg_match('/^[0-9?\-\/ .]+$/', $date) === 1 ? $date : null;
    }

    private function safeCode(mixed $value): string
    {
        $code = strtolower($this->safeText($value, 120));

        return preg_match('/^[a-z0-9_.:-]+$/', $code) === 1 ? $code : '';
    }

    private function safeText(mixed $value, int $limit): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = trim((string) $value);
        $text = (string) preg_replace([
            '/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/i',
            '/\b(?:sk|ghp|github_pat|glpat|xox[baprs]?)-[A-Za-z0-9_=\-]{8,}\b/i',
            '/\/(?:home|Users|root)\/[^\s,"\')\]}]+/',
            '/[A-Za-z]:\\\\[^\s,"\')\]}]+/',
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
        ], [
            'Bearer [redacted]',
            '[redacted token]',
            '[redacted path]',
            '[redacted path]',
            '',
        ], $text);

        return mb_substr($text, 0, $limit);
    }
}
