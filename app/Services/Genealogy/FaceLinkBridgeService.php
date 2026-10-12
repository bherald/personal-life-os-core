<?php

namespace App\Services\Genealogy;

use App\Services\FaceEmbeddingService;
use App\Services\NextcloudFileApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps file-registry face links and genealogy person-media links in sync.
 */
class FaceLinkBridgeService
{
    public function syncFaceLink(int $fileRegistryFaceId, int $personId, ?int $mediaId = null, ?array $reviewEvidence = null): array
    {
        $face = DB::selectOne(
            'SELECT frf.id, frf.file_registry_id, frf.person_name, frf.region_x, frf.region_y, frf.region_w, frf.region_h, frf.cluster_id, frf.hidden, frf.source, frf.source_sha256, frf.face_region_convention, frf.source_current, frf.superseded_at, frf.global_identity_uuid,
                    fr.current_path, fr.original_path, fr.filename, fr.mime_type, fr.file_size, fr.status AS file_status
             FROM file_registry_faces frf
             JOIN file_registry fr ON fr.id = frf.file_registry_id
             WHERE frf.id = ?',
            [$fileRegistryFaceId]
        );

        if (! $face) {
            return ['success' => false, 'error' => 'file_registry_face not found'];
        }
        if (($face->file_status ?? null) !== 'active' || ! empty($face->hidden)
            || $this->validRegion(['x' => $face->region_x, 'y' => $face->region_y, 'w' => $face->region_w, 'h' => $face->region_h]) === null
        ) {
            return ['success' => false, 'error' => 'Current visible face region is unavailable'];
        }

        $proof = clone $face;
        if (empty($proof->source_sha256) && ($reviewEvidence['success'] ?? false)
            && ($reviewEvidence['source_path'] ?? null) === $face->current_path
            && $this->sameFaceRegion($face, $reviewEvidence['region'] ?? [])) {
            // Current explicit review evidence can validate legacy geometry without backfilling it.
            $proof->source_sha256 = $reviewEvidence['source_sha256'] ?? null;
            $proof->face_region_convention = 'normalized_top_left';
        }
        $evidence = $this->validateFaceSourceEvidence($proof);
        if (! ($evidence['success'] ?? false)) {
            return ['success' => false, 'skipped' => true, 'reason' => $evidence['reason'], 'error' => 'Current face source requires review'];
        }

        $person = DB::selectOne(
            'SELECT id, tree_id, given_name, surname FROM genealogy_persons WHERE id = ?',
            [$personId]
        );

        if (! $person) {
            return ['success' => false, 'error' => 'genealogy_person not found'];
        }

        if (! empty($face->global_identity_uuid) && ! $this->globalFaceMapsToPerson($face, (int) $person->tree_id, $personId)) {
            return ['success' => false, 'error' => 'An exact approved global person mapping is required before linking this tree person.'];
        }
        $personName = trim(($person->given_name ?? '').' '.($person->surname ?? ''));
        if ($personName === '') {
            return ['success' => false, 'error' => 'Canonical person name is unavailable'];
        }
        $media = $this->resolveMedia((int) $person->tree_id, $face, $mediaId);
        if ($mediaId !== null && ! $media) {
            return ['success' => false, 'error' => 'Selected media does not match this tree and current source'];
        }
        DB::update(
            'UPDATE file_registry_faces
             SET genealogy_person_id = ?, person_name = ?, verified = 1, updated_at = NOW()
             WHERE id = ?',
            [$personId, $personName, $fileRegistryFaceId]
        );

        DB::update(
            'UPDATE file_registry SET exif_faces_written = 0 WHERE id = ?',
            [$face->file_registry_id]
        );

        // A selected face does not authorize assigning an entire PG cluster.
        // Explicit cluster confirmation owns that projection.

        if (! $media) {
            return [
                'success' => true,
                'file_registry_face_id' => $fileRegistryFaceId,
                'person_id' => $personId,
                'media_id' => null,
                'person_media_action' => 'skipped',
                'warning' => 'genealogy_media could not be resolved',
            ];
        }

        $existing = DB::selectOne(
            'SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?',
            [$personId, $media->id]
        );

        $coordinates = [
            $this->nullableFloat($face->region_x),
            $this->nullableFloat($face->region_y),
            $this->nullableFloat($face->region_w),
            $this->nullableFloat($face->region_h),
        ];

        if ($existing) {
            DB::update(
                'UPDATE genealogy_person_media
                 SET face_region_x = ?, face_region_y = ?, face_region_w = ?, face_region_h = ?, face_confirmed = 1
                 WHERE id = ?',
                [...$coordinates, $existing->id]
            );
            $personMediaAction = 'updated';
        } else {
            DB::insert(
                'INSERT INTO genealogy_person_media
                 (person_id, media_id, face_region_x, face_region_y, face_region_w, face_region_h, face_confirmed, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, NOW())',
                [$personId, $media->id, ...$coordinates]
            );
            $personMediaAction = 'created';
        }

        $faceCount = DB::selectOne(
            'SELECT COUNT(*) as count FROM genealogy_person_media WHERE media_id = ?',
            [$media->id]
        );

        DB::update(
            'UPDATE genealogy_media SET has_faces = 1, face_count = ?, last_face_sync_at = NOW(), updated_at = NOW() WHERE id = ?',
            [(int) ($faceCount->count ?? 1), $media->id]
        );

        return [
            'success' => true,
            'file_registry_face_id' => $fileRegistryFaceId,
            'person_id' => $personId,
            'media_id' => $media->id,
            'person_media_action' => $personMediaAction,
        ];
    }

    /** Current byte and coordinate proof for candidate admission; never changes identity. */
    public function validateFaceSourceEvidence(object $face, ?float $deadline = null): array
    {
        $invalid = fn (string $reason): array => ['success' => false, 'reason' => $reason];
        if ($deadline !== null && (! is_finite($deadline) || hrtime(true) / 1e9 >= $deadline)) {
            return $invalid('face_proof_deadline_exceeded');
        }
        if (empty($face->source_current) || ! empty($face->superseded_at)) {
            return $invalid('face_source_superseded');
        }
        $region = $this->validRegion(['x' => $face->region_x ?? null, 'y' => $face->region_y ?? null,
            'w' => $face->region_w ?? null, 'h' => $face->region_h ?? null]);
        if ($region === null || ! empty($face->hidden) || ($face->file_status ?? $face->status ?? null) !== 'active') {
            return $invalid('face_evidence_unavailable');
        }
        $hash = $face->source_sha256 ?? null;
        if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)
            || ($face->face_region_convention ?? null) !== 'normalized_top_left') {
            return $invalid('face_source_provenance_missing');
        }
        $path = $face->current_path ?? null;
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || in_array('..', explode('/', $path), true)) {
            return $invalid('source_photo_unavailable');
        }
        try {
            $api = app(NextcloudFileApiService::class);
            $current = $deadline === null ? $api->computeFileHash($path) : $api->computeFileHash($path, $deadline, true);
            if ($deadline !== null && hrtime(true) / 1e9 >= $deadline) {
                return $invalid('face_proof_deadline_exceeded');
            }
            if (! ($current['success'] ?? false) || ! is_string($current['hash'] ?? null) || ! hash_equals($hash, $current['hash'])) {
                return $invalid('face_source_changed');
            }

            return ['success' => true, 'region' => $region, 'source_sha256' => $hash,
                'face_region_convention' => 'normalized_top_left', 'source_path' => $path];
        } catch (Throwable $e) {
            return $invalid('source_photo_unavailable');
        }
    }

    /**
     * Validate exactly the source and region presented for an operator review.
     * No detector replacement, coordinate inference, or identity writes occur here.
     */
    public function validateReviewEvidence(object $match, int $personId, array $decisionMeta = []): array
    {
        $invalid = fn (string $code): array => ['success' => false, 'error' => 'Current picture evidence requires review.', 'error_code' => $code];
        $treeId = (int) ($match->tree_id ?? 0);
        $person = DB::selectOne('SELECT id, tree_id, given_name, surname FROM genealogy_persons WHERE id = ? AND tree_id = ? FOR UPDATE', [$personId, $treeId]);
        if (! $person || $treeId <= 0) {
            return $invalid('person_tree_mismatch');
        }
        if (trim(($person->given_name ?? '').' '.($person->surname ?? '')) === '') {
            return $invalid('person_name_unavailable');
        }
        $face = null;
        if (! empty($match->file_registry_face_id)) {
            $face = DB::selectOne(
                'SELECT frf.*, fr.current_path, fr.status AS file_status
                 FROM file_registry_faces frf JOIN file_registry fr ON fr.id = frf.file_registry_id
                 WHERE frf.id = ? FOR UPDATE',
                [(int) $match->file_registry_face_id]
            );
            if (! $face || empty($face->source_current) || ! empty($face->superseded_at) || ($face->file_status ?? null) !== 'active' || ! empty($face->hidden)) {
                return $invalid('referenced_face_unavailable');
            }
        }
        if ($face && ! empty($face->global_identity_uuid) && ! $this->globalFaceMapsToPerson($face, $treeId, $personId)) {
            return $invalid('global_face_mapping_required');
        }
        $media = null;
        if (! empty($match->media_id)) {
            $media = DB::selectOne(
                'SELECT id, tree_id, nextcloud_path, original_path FROM genealogy_media WHERE id = ? AND tree_id = ? FOR UPDATE',
                [(int) $match->media_id, $treeId]
            );
            if (! $media) {
                return $invalid('media_tree_mismatch');
            }
        }
        if ($face === null && $media === null) {
            return $invalid('source_photo_unavailable');
        }
        $path = $face?->current_path ?? ($media->nextcloud_path ?: $media->original_path);
        if (! is_string($path) || trim($path) === '' || str_contains($path, '\\')
            || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            return $invalid('source_photo_unavailable');
        }
        if ($face && $media && $this->canonicalReviewPath($media->nextcloud_path ?: $media->original_path) !== $this->canonicalReviewPath($path)) {
            return $invalid('face_media_source_mismatch');
        }
        $details = is_string($match->match_details ?? null) ? json_decode($match->match_details, true) : ($match->match_details ?? []);
        if (! is_array($details)) {
            return $invalid('review_details_invalid');
        }
        $regionValue = is_string($match->face_region ?? null) ? json_decode($match->face_region, true) : ($match->face_region ?? null);
        $region = $this->validRegion($regionValue);
        $convention = $details['face_region_convention'] ?? $details['coordinate_convention'] ?? $decisionMeta['face_region_convention'] ?? $face?->face_region_convention ?? null;
        if ($convention !== null && $convention !== 'normalized_top_left') {
            return $invalid('face_region_convention_ambiguous');
        }
        if ($face) {
            $faceRegion = $this->validRegion(['x' => $face->region_x, 'y' => $face->region_y, 'w' => $face->region_w, 'h' => $face->region_h]);
            if ($faceRegion === null) {
                return $invalid('face_region_invalid');
            }
            if ($region !== null && ! $this->sameFaceRegion($face, $region)) {
                return $invalid('face_region_changed');
            }
            if ($region === null && $regionValue !== null
                && (! is_array($regionValue) || array_keys($regionValue) !== ['file_registry_face_id']
                    || (int) $regionValue['file_registry_face_id'] !== (int) $face->id)
            ) {
                return $invalid('face_region_invalid');
            }
            if ($convention === null) {
                return $invalid('face_region_convention_ambiguous');
            }
            $region = $faceRegion;
        } elseif ($region === null) {
            return $invalid('face_region_invalid');
        } elseif ($convention === null) {
            return $invalid('face_region_convention_ambiguous');
        }

        $api = app(NextcloudFileApiService::class);
        try {
            $local = $api->localPath($path);
            if ($local) {
                $bytes = @file_get_contents($local);
            } else {
                $download = $api->downloadFile($path);
                $bytes = ($download['success'] ?? false) ? ($download['content'] ?? null) : null;
            }
            if (! is_string($bytes) || $bytes === '') {
                return $invalid('source_photo_unavailable');
            }
            $sourceHash = hash('sha256', $bytes);
            $dimensions = @getimagesizefromstring($bytes);
            // Header dimensions alone do not prove that the displayed crop is decodable.
            $decoded = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;
            if ($decoded !== false) {
                imagedestroy($decoded);
            } elseif (class_exists(\Imagick::class)) {
                $image = new \Imagick;
                try {
                    $image->readImageBlob($bytes);
                    $decoded = $image->getImageWidth() > 0 && $image->getImageHeight() > 0;
                } finally {
                    $image->clear();
                }
            }
            if (! $dimensions || $decoded === false
                || floor($region['w'] * $dimensions[0]) < 1 || floor($region['h'] * $dimensions[1]) < 1
            ) {
                return $invalid('source_crop_unavailable');
            }
            $current = $api->computeFileHash($path);
            if (! ($current['success'] ?? false) || ! hash_equals($sourceHash, (string) ($current['hash'] ?? ''))) {
                return $invalid('source_photo_changed');
            }
            $storedHash = $face->source_sha256 ?? null;
            $recordedHash = $details['source_sha256'] ?? $decisionMeta['source_sha256'] ?? null;
            if ($storedHash === null && $recordedHash === null) {
                return $invalid('face_source_provenance_missing');
            }
            if ($storedHash !== null && (! is_string($storedHash) || ! hash_equals($storedHash, $sourceHash))) {
                return $invalid('source_photo_changed');
            }
            if ($face && ($face->face_region_convention ?? null) !== null && $face->face_region_convention !== 'normalized_top_left') {
                return $invalid('face_region_convention_ambiguous');
            }
            // A legacy row needs an explicitly reviewed current region, not an ID-only shortcut.
            if ($storedHash === null && $this->validRegion($regionValue) === null) {
                return $invalid('face_source_provenance_missing');
            }
            if ($recordedHash !== null && (! is_string($recordedHash) || ! hash_equals($recordedHash, $sourceHash))) {
                return $invalid('source_photo_changed');
            }

            return [
                'success' => true, 'region' => $region, 'source_path' => $path,
                'source_sha256' => $sourceHash, 'source_dimensions' => [$dimensions[0], $dimensions[1]],
                'previous_person_name' => $face->person_name ?? null,
                'previous_person_id' => $face->genealogy_person_id ?? null,
                'previous_verified' => $face->verified ?? null,
                'person_name' => trim(($person->given_name ?? '').' '.($person->surname ?? '')),
                'media_id' => $media?->id,
            ];
        } catch (Throwable $e) {
            return $invalid('source_photo_unavailable');
        }
    }

    /** Read current encoded pixels for an operator region review; never changes identity or legacy rows. */
    public function reviewSourcePhoto(object $match, ?string $expectedHash = null): array
    {
        $invalid = fn (string $code): array => ['success' => false, 'error' => 'Current source photo requires review.', 'error_code' => $code];
        $treeId = (int) ($match->tree_id ?? 0);
        if ($treeId <= 0) {
            return $invalid('media_tree_mismatch');
        }
        $media = empty($match->media_id) ? null : DB::table('genealogy_media')
            ->where('id', $match->media_id)->where('tree_id', $treeId)->first();
        if (! empty($match->media_id) && ! $media) {
            return $invalid('media_tree_mismatch');
        }
        $face = empty($match->file_registry_face_id) ? null : DB::table('file_registry_faces as f')
            ->join('file_registry as r', 'r.id', '=', 'f.file_registry_id')->where('f.id', $match->file_registry_face_id)
            ->where('r.status', 'active')->select('r.current_path')->first();
        $path = $media ? ($media->nextcloud_path ?: $media->original_path) : ($face->current_path ?? null);
        if (! is_string($path) || trim($path) === '' || str_contains($path, '\\') || str_contains($path, "\0")
            || in_array('..', explode('/', $path), true)) {
            return $invalid('source_photo_unavailable');
        }

        return $this->reviewSourcePath($path, $expectedHash);
    }

    /** Read and verify one source path without assigning identity or changing any record. */
    public function reviewSourcePath(string $path, ?string $expectedHash = null, bool $localOnly = false): array
    {
        $invalid = fn (string $code): array => ['success' => false, 'error' => 'Current source photo requires review.', 'error_code' => $code];
        if ($expectedHash !== null && ! preg_match('/^[a-f0-9]{64}$/D', $expectedHash)) {
            return $invalid('source_photo_changed');
        }
        if (trim($path) === '' || str_contains($path, '\\') || str_contains($path, "\0")
            || in_array('..', explode('/', $path), true)) {
            return $invalid('source_photo_unavailable');
        }

        try {
            $api = app(NextcloudFileApiService::class);
            $maxBytes = max(1, (int) config('metadata_writeback.max_verification_bytes', 67108864));
            $memoryLimit = ini_get('memory_limit');
            $readBudget = $maxBytes;
            if ($memoryLimit !== '-1') {
                $unit = match (strtolower(substr($memoryLimit, -1))) {
                    'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
                };
                $readBudget = min($maxBytes, max(0, (int) floor(((int) $memoryLimit * $unit - memory_get_usage(true)) / 3)));
            }
            if ($readBudget < 1) {
                return $invalid('source_resource_budget');
            }
            $local = $api->localPath($path);
            if ($localOnly && $local === null) {
                return $invalid('source_photo_unavailable');
            }
            if ($local !== null) {
                $configuredRoot = trim((string) config('services.nextcloud.data_path', ''));
                $root = $configuredRoot !== '' ? realpath($configuredRoot) : false;
                $resolved = realpath($local);
                if ($root === false || $resolved === false || ! str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
                    || ! is_file($resolved) || ! is_readable($resolved)) {
                    return $invalid('source_photo_unavailable');
                }
                $length = filesize($resolved);
                if ($length === false || $length > $readBudget) {
                    return $invalid('source_resource_budget');
                }
                $bytes = @file_get_contents($resolved, false, null, 0, $readBudget + 1);
            } else {
                // Existing WebDAV client enforces its HTTP timeout; reject over-budget bodies.
                $download = $api->downloadFile($path);
                $bytes = ($download['success'] ?? false) ? ($download['content'] ?? null) : null;
            }
            if (is_string($bytes) && strlen($bytes) > $readBudget) {
                return $invalid('source_resource_budget');
            }
            if (! is_string($bytes) || $bytes === '') {
                return $invalid('source_photo_unavailable');
            }
            $hash = hash('sha256', $bytes);
            if ($expectedHash !== null && ! hash_equals($expectedHash, $hash)) {
                return $invalid('source_photo_changed');
            }
            $size = @getimagesizefromstring($bytes);
            $preview = [];
            if ($size && (int) config('metadata_writeback.max_verification_pixels', 16000000) < $size[0] * $size[1]) {
                if ($size[2] !== IMAGETYPE_JPEG || (int) config('metadata_writeback.preview_max_source_pixels') < $size[0] * $size[1]
                    || strlen($bytes) > (int) config('file_registry.face_preview.max_source_bytes')) {
                    return $invalid('source_resource_budget');
                }
                $preview = app(FaceEmbeddingService::class)->renderSourcePreview($bytes);
                if (! ($preview['success'] ?? false)) {
                    return $invalid('source_resource_budget');
                }
                $bytes = $preview['bytes'];
            }
            if (! $size || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || ! function_exists('imagecreatefromstring')) {
                return $invalid('source_crop_unavailable');
            }
            $memoryLimit = ini_get('memory_limit');
            if ($memoryLimit !== '-1') {
                $unit = match (strtolower(substr($memoryLimit, -1))) {
                    'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
                };
                $decodeSize = $preview['preview_dimensions'] ?? [$size[0], $size[1]];
                if ((int) $memoryLimit * $unit - memory_get_usage(true) < $decodeSize[0] * $decodeSize[1] * 8 + strlen($bytes) * 2) {
                    return $invalid('source_resource_budget');
                }
            }
            $decoded = @imagecreatefromstring($bytes);
            if (! $decoded) {
                return $invalid('source_crop_unavailable');
            }
            imagedestroy($decoded);
            if ($localOnly) {
                $hashBudget = max(0.001, (float) config('file_registry.face_preview.timeout_seconds', 120));
                $deadline = hrtime(true) / 1e9 + $hashBudget;
                $current = $api->computeFileHash($path, $deadline, true);
            } else {
                $current = $api->computeFileHash($path);
            }
            if (! ($current['success'] ?? false) || ! hash_equals($hash, (string) ($current['hash'] ?? ''))) {
                return $invalid('source_photo_changed');
            }

            return ['success' => true, 'source_sha256' => $hash, 'source_path' => $path,
                'source_dimensions' => [$size[0], $size[1]], 'coordinate_basis' => 'encoded_pixels',
                'face_region_convention' => 'normalized_top_left', 'bytes' => $bytes]
                + array_intersect_key($preview, array_flip(['preview_dimensions', 'orientation_provenance', 'decode_provenance']));
        } catch (Throwable) {
            return $invalid('source_photo_unavailable');
        }
    }

    public function validateRegionReviewEvidence(object $match, array $meta): array
    {
        $region = $this->validRegion($meta['region'] ?? null);
        if ($region === null || ($meta['face_region_convention'] ?? null) !== 'normalized_top_left'
            || ! is_string($meta['source_sha256'] ?? null)) {
            return ['success' => false, 'error' => 'Select a valid current photo region.', 'error_code' => 'face_region_invalid'];
        }
        $proof = $this->reviewSourcePhoto($match, $meta['source_sha256']);
        if (! ($proof['success'] ?? false)) {
            return $proof;
        }
        if (floor($region['w'] * $proof['source_dimensions'][0]) < 1 || floor($region['h'] * $proof['source_dimensions'][1]) < 1) {
            return ['success' => false, 'error' => 'Selected region is smaller than one source pixel.', 'error_code' => 'face_region_invalid'];
        }
        unset($proof['bytes']);

        return $proof + ['region' => $region];
    }

    private function validRegion(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $region = [];
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if (! is_numeric($value[$axis] ?? null) || ! is_finite((float) $value[$axis])) {
                return null;
            }
            $region[$axis] = (float) $value[$axis];
        }
        if ($region['x'] < 0 || $region['y'] < 0 || $region['w'] <= 0 || $region['h'] <= 0
            || $region['x'] + $region['w'] > 1 || $region['y'] + $region['h'] > 1) {
            return null;
        }

        return $region;
    }

    /**
     * Automatic matching may retain a current bridge, but cannot decide identity
     * or recreate a face/person-media link removed by an operator.
     */
    public function syncAutomaticFaceLink(int $fileRegistryFaceId, int $personId, ?int $mediaId = null): array
    {
        try {
            $link = DB::selectOne(
                "SELECT frf.id, gpm.id AS person_media_id, gm.id AS media_id,
                        frf.region_x, frf.region_y, frf.region_w, frf.region_h,
                        frf.source_current, frf.superseded_at, frf.source_sha256, frf.face_region_convention, fr.current_path, fr.status AS file_status,
                        gpm.face_region_x, gpm.face_region_y, gpm.face_region_w, gpm.face_region_h
                 FROM file_registry_faces frf
                 JOIN file_registry fr ON fr.id = frf.file_registry_id
                 JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
                 JOIN genealogy_media gm ON gm.tree_id = gp.tree_id
                    AND (gm.nextcloud_path = fr.current_path OR gm.original_path = fr.original_path)
                 JOIN genealogy_person_media gpm ON gpm.media_id = gm.id AND gpm.person_id = gp.id
                 WHERE frf.id = ? AND gp.id = ? AND frf.verified = 1
                   AND COALESCE(frf.hidden, 0) = 0
                   AND TRIM(COALESCE(frf.person_name, '')) <> ''
                   AND gpm.face_confirmed = 1 AND (? IS NULL OR gm.id = ?)",
                [$fileRegistryFaceId, $personId, $mediaId, $mediaId]
            );

            if (! $link) {
                return $this->automaticSkip('operator_identity_confirmation_required');
            }

            $evidence = $this->validateFaceSourceEvidence($link);
            if (! ($evidence['success'] ?? false)) {
                return $this->automaticSkip($evidence['reason']);
            }

            // A person can have another approved face in a group photo. Automatic
            // matching must not replace that selected region with a cluster member.
            $tolerance = max(0.0, (float) config('genealogy.face_region_match_tolerance'));
            foreach (['x', 'y', 'w', 'h'] as $axis) {
                $faceCoordinate = $this->nullableFloat($link->{'region_'.$axis});
                $mediaCoordinate = $this->nullableFloat($link->{'face_region_'.$axis});
                if ($faceCoordinate === null || $mediaCoordinate === null || abs($faceCoordinate - $mediaCoordinate) > $tolerance) {
                    return $this->automaticSkip('face_region_confirmation_required');
                }
            }

            $operatorApproved = false;
            if ($this->faceIdentityRejected($fileRegistryFaceId, $personId, (int) $link->media_id, $link, $operatorApproved)) {
                return $this->automaticSkip('operator_identity_rejected');
            }

            return [
                'success' => true,
                'file_registry_face_id' => $fileRegistryFaceId,
                'person_id' => $personId,
                'media_id' => (int) $link->media_id,
                'person_media_action' => 'retained',
                'operator_approved' => $operatorApproved,
            ];
        } catch (Throwable $e) {
            Log::warning('FaceLinkBridgeService: Automatic bridge evidence unavailable', [
                'file_registry_face_id' => $fileRegistryFaceId,
                'person_id' => $personId,
                'error' => $e->getMessage(),
            ]);

            return $this->automaticSkip('identity_evidence_unavailable');
        }
    }

    /** A named confirmation cannot reverse Not a face for this current source and box. */
    public function assertFaceRegionNotDismissed(object $face, ?int $treeId = null, ?int $mediaId = null): void
    {
        $region = $this->validRegion(['x' => $face->region_x ?? null, 'y' => $face->region_y ?? null,
            'w' => $face->region_w ?? null, 'h' => $face->region_h ?? null]);
        if ($region === null || ! is_string($face->source_sha256 ?? null)) {
            throw new \RuntimeException('Current face region proof is unavailable.');
        }
        if (($treeId === null) !== ($mediaId === null)
            || ($treeId !== null && ($treeId <= 0 || $mediaId <= 0))) {
            throw new \RuntimeException('Exact tree and media scope are required together.');
        }
        $query = DB::table('genealogy_face_match_queue');
        if ($treeId !== null) {
            $query->where('tree_id', $treeId)->where('media_id', $mediaId);
        } else {
            $query->where('file_registry_face_id', $face->id);
        }
        $rows = $query->lockForUpdate()->get(['match_details']);
        foreach ($rows as $row) {
            $details = json_decode($row->match_details ?: '{}', true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($details) || ! is_array($details['false_detection_reviews'] ?? [])) {
                throw new \RuntimeException('Face dismissal history requires reconciliation.');
            }
            foreach ($details['false_detection_reviews'] ?? [] as $review) {
                $evidence = is_array($review) ? ($review['current_evidence'] ?? null) : null;
                if (! is_array($evidence) || ($evidence['source_sha256'] ?? null) !== $face->source_sha256) {
                    continue;
                }
                $dismissed = $this->validRegion($evidence['region'] ?? null);
                if ($dismissed === null) {
                    throw new \RuntimeException('Current source dismissal geometry requires reconciliation.');
                }
                $same = true;
                foreach (['x', 'y', 'w', 'h'] as $axis) {
                    $same = $same && abs($dismissed[$axis] - $region[$axis]) <= 0.00000001;
                }
                if ($same) {
                    throw new \RuntimeException('This current source region was rejected as Not a face.');
                }
            }
        }
    }

    /** An identity confirmation cannot silently reverse a recorded person rejection. */
    public function assertFaceIdentityNotRejected(int $faceId, int $treeId, int $personId): void
    {
        $rows = DB::table('genealogy_face_match_queue')->where('file_registry_face_id', $faceId)
            ->where('tree_id', $treeId)->lockForUpdate()->get(['status', 'suggested_person_id', 'match_details']);
        foreach ($rows as $row) {
            $details = json_decode($row->match_details ?: '{}', true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($details) || (($row->status ?? null) === 'rejected' && (int) $row->suggested_person_id === $personId)
                || in_array($personId, array_map('intval', $details['rejected_candidate_ids'] ?? []), true)) {
                throw new \RuntimeException('Recorded person rejection requires separate reconciliation.');
            }
            foreach ($details['candidate_decisions'] ?? [] as $decision) {
                if ((int) ($decision['genealogy_person_id'] ?? $decision['person_id'] ?? 0) === $personId
                    && in_array($decision['action'] ?? '', ['not_this_person', 'reject', 'rejected'], true)) {
                    throw new \RuntimeException('Recorded person rejection requires separate reconciliation.');
                }
            }
        }
    }

    /** Read retained decisions before staging a proposal; this does not confirm identity. */
    public function assertFaceIdentityProposalNotPreviouslyRejected(int $faceId, int $treeId, int $mediaId, int $personId, array $canonicalRegion): void
    {
        $region = $this->validRegion($canonicalRegion);
        $owner = DB::selectOne('SELECT p.id FROM genealogy_persons p
            JOIN genealogy_media gm ON gm.tree_id = p.tree_id
            WHERE p.id = ? AND p.tree_id = ? AND gm.id = ?', [$personId, $treeId, $mediaId]);
        if ($faceId <= 0 || $region === null || ! $owner) {
            throw new \RuntimeException('Face proposal ownership or region requires reconciliation.');
        }
        $face = (object) ['region_x' => $region['x'], 'region_y' => $region['y'],
            'region_w' => $region['w'], 'region_h' => $region['h']];
        $operatorApproved = false;
        if ($this->faceIdentityRejected($faceId, $personId, $mediaId, $face, $operatorApproved, $treeId)) {
            throw new \RuntimeException('Recorded person rejection requires separate reconciliation.');
        }
    }

    private function faceIdentityRejected(int $fileRegistryFaceId, int $personId, ?int $mediaId = null, ?object $face = null, ?bool &$operatorApproved = null, ?int $treeId = null): bool
    {
        $operatorApproved = false;
        if ($face === null) {
            $face = DB::selectOne(
                'SELECT frf.region_x, frf.region_y, frf.region_w, frf.region_h, gm.id AS media_id
                 FROM file_registry_faces frf
                 JOIN file_registry fr ON fr.id = frf.file_registry_id
                 JOIN genealogy_persons gp ON gp.id = ?
                 LEFT JOIN genealogy_media gm ON gm.tree_id = gp.tree_id
                    AND (gm.nextcloud_path = fr.current_path OR gm.original_path = fr.original_path)
                 WHERE frf.id = ?',
                [$personId, $fileRegistryFaceId]
            );
            $mediaId ??= isset($face->media_id) ? (int) $face->media_id : null;
        }

        $decisions = $treeId === null
            ? DB::select('SELECT file_registry_face_id, suggested_person_id, status, match_details, face_region
                 FROM genealogy_face_match_queue WHERE file_registry_face_id = ? OR media_id = ?
                 ORDER BY COALESCE(reviewed_at, updated_at, created_at) DESC, id DESC', [$fileRegistryFaceId, $mediaId])
            : DB::select('SELECT file_registry_face_id, suggested_person_id, status, match_details, face_region
                 FROM genealogy_face_match_queue WHERE (file_registry_face_id = ? OR media_id = ?) AND tree_id = ?
                 ORDER BY COALESCE(reviewed_at, updated_at, created_at) DESC, id DESC', [$fileRegistryFaceId, $mediaId, $treeId]);

        foreach ($decisions as $decision) {
            $details = json_decode((string) ($decision->match_details ?? ''), true);
            $latest = is_array($details) ? ($details['latest_candidate_decision'] ?? []) : [];
            $rejectedIds = is_array($details) ? ($details['rejected_candidate_ids'] ?? []) : [];
            $history = is_array($details) && is_array($details['candidate_decisions'] ?? null)
                ? $details['candidate_decisions'] : [];
            $historyRejectsPerson = false;
            foreach (array_reverse($history) as $candidate) {
                if (is_array($candidate) && (int) ($candidate['genealogy_person_id'] ?? 0) === $personId) {
                    $historyRejectsPerson = ($candidate['action'] ?? null) === 'not_this_person';
                    break;
                }
            }
            $candidateRejected = (is_array($latest) && ($latest['action'] ?? null) === 'not_this_person'
                    && (int) ($latest['genealogy_person_id'] ?? 0) === $personId)
                || (is_array($rejectedIds) && in_array($personId, array_map('intval', $rejectedIds), true))
                || $historyRejectsPerson;
            if ((int) ($decision->suggested_person_id ?? 0) !== $personId && ! $candidateRejected) {
                continue;
            }

            if ((int) ($decision->file_registry_face_id ?? 0) !== $fileRegistryFaceId) {
                $region = json_decode((string) ($decision->face_region ?? ''), true);
                if (is_array($region) && $region !== []) {
                    // Detector rows can be replaced. Rejection follows the exact
                    // photo region, rather than only the old detector row id.
                    if (! $this->sameFaceRegion($face, $region)) {
                        continue;
                    }
                } elseif (! empty($decision->file_registry_face_id)) {
                    continue;
                } elseif ($decision->status !== 'rejected') {
                    // A media-only approval without a region cannot establish
                    // approval of this particular cluster member.
                    continue;
                }
            }

            // An explicit later approval is the current decision even when the
            // queue row retains historical rejected-candidate audit details.
            if ($decision->status === 'approved' && (int) ($decision->suggested_person_id ?? 0) === $personId) {
                $operatorApproved = true;
                break;
            }
            if ($candidateRejected) {
                return true;
            }

            if ((int) ($decision->suggested_person_id ?? 0) === $personId) {
                if ($decision->status === 'rejected') {
                    return true;
                }
            }
        }

        return false;
    }

    private function sameFaceRegion(?object $face, array $region): bool
    {
        $tolerance = max(0.0, (float) config('genealogy.face_region_match_tolerance'));
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if (! is_numeric($region[$axis] ?? null) || ! is_numeric($face->{'region_'.$axis} ?? null)
                || abs((float) $region[$axis] - (float) $face->{'region_'.$axis}) > $tolerance) {
                return false;
            }
        }

        return true;
    }

    private function automaticSkip(string $reason): array
    {
        return ['success' => false, 'skipped' => true, 'reason' => $reason, 'person_media_action' => 'skipped'];
    }

    public function syncClusterLinks(int $clusterId, int $personId, bool $operatorConfirmed = false): array
    {
        $members = DB::connection('pgsql_rag')->select('SELECT * FROM face_embeddings
            WHERE person_cluster_id = ? AND file_registry_face_id IS NOT NULL
              AND source_current = TRUE AND superseded_at IS NULL', [$clusterId]);
        $held = [];
        $members = array_values(array_filter($members, function ($member) use (&$held): bool {
            if (app(FaceEmbeddingService::class)->embeddingHasCurrentSourceEvidence($member)) {
                return true;
            }
            $held[] = ['file_registry_face_id' => (int) $member->file_registry_face_id, 'reason' => 'face_source_evidence_unavailable'];

            return false;
        }));
        $ids = array_values(array_unique(array_map(fn ($member) => (int) $member->file_registry_face_id, $members)));
        $faces = $ids === [] ? [] : DB::select('SELECT id, genealogy_person_id FROM file_registry_faces
            WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).') AND hidden = 0
              AND source_current = 1 AND superseded_at IS NULL', $ids);

        $results = [
            'processed' => count($held),
            'linked' => 0,
            'media_linked' => 0,
            'failed' => 0,
            'skipped' => count($held),
            'skipped_faces' => $held,
            'confirmed_face_ids' => [],
            'errors' => [],
        ];

        foreach ($faces as $face) {
            $results['processed']++;
            if ($operatorConfirmed) {
                if (! empty($face->genealogy_person_id) && (int) $face->genealogy_person_id !== $personId) {
                    $result = $this->automaticSkip('conflicting_face_identity');
                } elseif ($this->faceIdentityRejected((int) $face->id, $personId)) {
                    $result = $this->automaticSkip('operator_identity_rejected');
                } else {
                    $result = $this->syncFaceLink((int) $face->id, $personId);
                }
            } else {
                $result = $this->syncAutomaticFaceLink((int) $face->id, $personId);
            }
            if ($result['success'] ?? false) {
                $results['linked']++;
                if ($operatorConfirmed) {
                    $results['confirmed_face_ids'][] = (int) $face->id;
                }
                if (($result['person_media_action'] ?? null) !== 'skipped') {
                    $results['media_linked']++;
                }
            } elseif ($result['skipped'] ?? false) {
                $results['skipped']++;
                $results['skipped_faces'][] = ['face_id' => (int) $face->id, 'reason' => $result['reason'] ?? 'operator_review_required'];
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'face_id' => (int) $face->id,
                    'error' => $result['error'] ?? 'unknown',
                ];
            }
        }

        return $results;
    }

    private function globalFaceMapsToPerson(object $face, int $treeId, int $personId): bool
    {
        return DB::table('global_face_identity_genealogy_links')->where('identity_uuid', $face->global_identity_uuid)
            ->where('tree_id', $treeId)->where('genealogy_person_id', $personId)->lockForUpdate()->exists();
    }

    /** Registry paths have one leading slash; no other path aliases are inferred. */
    private function canonicalReviewPath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return '/'.ltrim($path, '/');
    }

    private function resolveMedia(int $treeId, object $face, ?int $mediaId): ?object
    {
        if ($mediaId !== null) {
            $media = DB::selectOne('SELECT id, nextcloud_path, original_path FROM genealogy_media WHERE id = ? AND tree_id = ?', [$mediaId, $treeId]);
            if ($media && ($this->canonicalReviewPath($media->nextcloud_path ?: $media->original_path) === null
                || $this->canonicalReviewPath($media->nextcloud_path ?: $media->original_path) !== $this->canonicalReviewPath($face->current_path))) {
                $media = null;
            }
            if ($media) {
                return $media;
            }

            Log::warning('FaceLinkBridgeService: Selected media does not match current face source', [
                'media_id' => $mediaId,
                'tree_id' => $treeId,
                'file_registry_face_id' => $face->id,
            ]);

            return null;
        }

        $path = $face->current_path ?: $face->original_path;
        if (! $path) {
            Log::warning('FaceLinkBridgeService: Cannot resolve media without a file path', [
                'file_registry_face_id' => $face->id,
            ]);

            return null;
        }

        $media = DB::selectOne(
            "SELECT id FROM genealogy_media
             WHERE tree_id = ? AND (nextcloud_path = ? OR ((nextcloud_path IS NULL OR nextcloud_path = '') AND original_path = ?))
             LIMIT 1",
            [$treeId, $path, $face->original_path]
        );
        if ($media) {
            return $media;
        }

        DB::insert(
            "INSERT INTO genealogy_media
             (tree_id, media_type, nextcloud_path, original_path, local_filename, mime_type, file_size, file_exists, has_faces, face_count, imported_at, created_at, updated_at)
             VALUES (?, 'photo', ?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW(), NOW())",
            [
                $treeId,
                $path,
                $face->original_path,
                $face->filename ?: basename((string) $path),
                $face->mime_type,
                $this->normalizeFileSize($face->file_size),
                $face->file_status === 'active' ? 1 : 0,
            ]
        );

        return DB::selectOne(
            'SELECT id FROM genealogy_media WHERE tree_id = ? AND nextcloud_path = ? ORDER BY id DESC LIMIT 1',
            [$treeId, $path]
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeName(?string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value ?? '')));
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function normalizeFileSize(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return max(0, min((int) $value, 4294967295));
    }
}
