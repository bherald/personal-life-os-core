<?php

namespace App\Services\Files;

use App\Services\NextcloudFileApiService;
use App\Services\PhotoDateExtractionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

class ScanDateLegacyMetadataAuditService
{
    private const EXIFTOOL = '/usr/bin/exiftool';

    /** Evidence only: checked weak dates remain untouched, including every analysis/review marker. */
    public function auditWeakDates(int $limit, int $offset, int $timeoutSeconds, bool $includeItems): array
    {
        $query = DB::table('file_registry')->where('status', 'active')
            ->whereIn('extension', config('file_types.image'))->whereNotNull('date_taken')->where('exif_checked', 1)
            ->where(fn ($q) => $q->where('date_taken_source', 'file_modified')->orWhereNull('date_taken_source'));
        $total = (clone $query)->count();
        $rows = $query->select($this->weakDateSnapshotFields())->orderBy('id')->limit($limit)->offset($offset)->get();
        $counts = ['metadata_evidence' => 0, 'no_valid_date_evidence' => 0, 'held' => 0];
        $reasons = $items = [];
        foreach ($rows as $row) {
            $item = $this->auditWeakDateRow($row, $timeoutSeconds);
            $counts[$item['classification']]++;
            if ($item['reason_code'] !== null) {
                $reasons[$item['reason_code']] = ($reasons[$item['reason_code']] ?? 0) + 1;
            }
            if ($includeItems) {
                $items[] = $item;
            }
        }
        $payload = ['schema' => 'plos.files_weak_date_metadata_audit.v1', 'status' => 'ok', 'mode' => 'weak_date_evidence',
            'total_weak_rows' => $total, 'selected' => count($rows), 'offset' => $offset,
            'remaining_after_page' => max(0, $total - $offset - count($rows)), 'classifications' => $counts,
            'reason_codes' => $reasons, 'automatic_correction_available' => false,
            'posture' => ['read_only' => true, 'database_writes' => false, 'file_writes' => false,
                'metadata_writes' => false, 'review_items_created' => false, 'paths_included' => false,
                'filenames_included' => false, 'raw_tags_included' => false]];
        if ($includeItems) {
            $payload['items'] = $items;
        }

        return $payload;
    }

    private function weakDateSnapshotFields(): array
    {
        return ['id', 'asset_uuid', 'current_path', 'filename', 'status', 'extension', 'content_hash', 'file_size',
            'nextcloud_modified_at', 'lifecycle_generation_key', 'date_taken', 'date_taken_source', 'date_taken_precision',
            'date_taken_confidence', 'date_taken_reasoning', 'is_scan', 'scan_date', 'date_needs_review', 'exif_checked', 'exif_written'];
    }

    private function weakDateProtected(object $row): bool
    {
        return DB::table('file_date_review_decisions')->where('file_registry_id', $row->id)->exists()
            || DB::table('review_desk_receipts')->where('reference', $row->asset_uuid)
                ->whereIn('state', ['processing', 'unknown'])->exists();
    }

    private function auditWeakDateRow(object $row, int $timeoutSeconds): array
    {
        $dates = app(PhotoDateExtractionService::class);
        $base = ['file_registry_id' => (int) $row->id, 'asset_uuid' => $row->asset_uuid,
            'catalog_date' => $dates->parseExifDate($row->date_taken), 'catalog_source' => $row->date_taken_source,
            'classification' => 'held', 'reason_code' => null, 'capture_date_confirmed' => false];
        $reason = 'catalog_protected';
        try {
            if ($this->weakDateProtected($row)) {
                throw new \RuntimeException;
            }
            $reason = 'date_review_hold';
            if ((int) $row->date_needs_review === 1) {
                throw new \RuntimeException;
            }
            $reason = 'scan_context_hold';
            if ((int) $row->is_scan === 1 || $dates->isScanContext($row->current_path, $row->filename) !== false) {
                throw new \RuntimeException;
            }
            $reason = 'source_unproven';
            if (! is_string($row->content_hash) || preg_match('/\A[a-f0-9]{64}\z/', $row->content_hash) !== 1) {
                throw new \RuntimeException;
            }
            $reason = 'source_unavailable';
            $sourcePath = (string) $row->current_path;
            $dataPath = trim((string) config('services.nextcloud.data_path', ''));
            $root = $dataPath === '' ? false : realpath($dataPath);
            $nextcloud = app(NextcloudFileApiService::class);
            if ($root === false || $sourcePath === '' || str_contains($sourcePath, "\0") || str_contains($sourcePath, '\\')
                || in_array('..', explode('/', $sourcePath), true)) {
                throw new \RuntimeException;
            }
            $local = realpath($sourcePath) ?: $nextcloud->localPath($sourcePath);
            $physical = is_string($local) ? realpath($local) : false;
            if ($root === false || $physical === false || ! is_file($physical) || ! is_readable($physical)
                || ! str_starts_with($physical, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException;
            }
            $hashPath = '/'.ltrim(substr($physical, strlen(rtrim($root, DIRECTORY_SEPARATOR))), '/');
            $bound = $nextcloud->localPath($hashPath);
            if (! is_string($bound) || realpath($bound) !== $physical) {
                throw new \RuntimeException;
            }
            $reason = 'read_policy_unavailable';
            $hashTimeout = config('file_registry.photo_review.hash_timeout_seconds');
            $executable = config('file_registry.exif_read.executable');
            if (! is_numeric($hashTimeout) || ! is_finite((float) $hashTimeout) || $hashTimeout <= 0
                || ! is_string($executable) || trim($executable) === '' || str_contains($executable, "\0")) {
                throw new \RuntimeException;
            }
            $reason = 'source_unproven';
            clearstatcache(true, $physical);
            $stat = stat($physical);
            $proof = $nextcloud->computeFileHash($hashPath, hrtime(true) / 1e9 + (float) $hashTimeout, true);
            if ($stat === false || ! ($proof['success'] ?? false) || ! is_string($proof['hash'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $proof['hash']) !== 1
                || ! hash_equals((string) $row->content_hash, $proof['hash'])
                || ($proof['source_fingerprint'] ?? null) !== array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime']))) {
                throw new \RuntimeException;
            }
            $reason = 'metadata_read_failed';
            $result = Process::timeout($timeoutSeconds)->run([$executable, '-json', '-G1', '-a', '-s',
                '-DateTimeOriginal', '-CreateDate', '-ModifyDate', '-XMP-dc:Source',
                '-XMP-photoshop:DateCreated', '-IPTC:DateCreated', $physical]);
            $records = $result->successful() ? json_decode($result->output(), true) : null;
            if (! is_array($records) || ! array_is_list($records) || count($records) !== 1
                || ! is_array($records[0]) || ($records[0]['SourceFile'] ?? null) !== $physical
                || isset($records[0]['Error']) || isset($records[0]['Warning'])
                || isset($records[0]['ExifTool:Error']) || isset($records[0]['ExifTool:Warning'])) {
                throw new \RuntimeException;
            }
            $tags = $records[0];
            $reason = 'operator_marker_hold';
            if ($dates->operatorDateFromExifMetadata($tags) !== null) {
                throw new \RuntimeException;
            }
            $normalized = [];
            $reason = 'metadata_date_ambiguous';
            $fields = ['ExifIFD:DateTimeOriginal' => 'original_field', 'XMP-exif:DateTimeOriginal' => 'original_field',
                'ExifIFD:CreateDate' => 'digitized_field', 'XMP-xmp:CreateDate' => 'digitized_field',
                'IFD0:ModifyDate' => 'modified_field', 'PNG:CreateDate' => 'digitized_field', 'PNG:ModifyDate' => 'modified_field'];
            foreach ($tags as $tag => $value) {
                if (str_contains($tag, ' (')) {
                    throw new \RuntimeException;
                }
                if (isset($fields[$tag])) {
                    $field = $fields[$tag];
                    $parsed = $dates->parseExifDate($value);
                    if (array_key_exists($field, $normalized) && $parsed !== $normalized[$field]) {
                        throw new \RuntimeException;
                    }
                    $normalized[$field] = $parsed;
                }
            }
            $reason = 'source_changed';
            $after = $nextcloud->computeFileHash($hashPath, hrtime(true) / 1e9 + (float) $hashTimeout, true);
            if (! ($after['success'] ?? false) || ($after['hash'] ?? null) !== $proof['hash']
                || ($after['source_fingerprint'] ?? null) !== $proof['source_fingerprint']) {
                throw new \RuntimeException;
            }
            $reason = 'catalog_changed';
            $current = DB::table('file_registry')->select($this->weakDateSnapshotFields())->where('id', $row->id)->first();
            if ($current === null || (array) $current !== (array) $row || $this->weakDateProtected($row)) {
                throw new \RuntimeException;
            }
            ksort($normalized);

            return [...$base, 'classification' => count(array_filter($normalized)) > 0 ? 'metadata_evidence' : 'no_valid_date_evidence',
                'reason_code' => null, 'source_sha256' => $proof['hash'],
                'catalog_snapshot_sha256' => hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR)),
                'date_evidence_sha256' => hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR)),
                'date_evidence' => $normalized];
        } catch (\Throwable) {
            return [...$base, 'reason_code' => $reason];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function audit(int $limit, int $offset, int $timeoutSeconds, bool $includeItems): array
    {
        $total = (int) (DB::selectOne(
            "SELECT COUNT(*) AS total
             FROM file_registry
             WHERE status = 'active' AND is_scan = 1 AND exif_written = 1
               AND date_needs_review = 1
               AND date_taken IS NOT NULL
               AND date_taken_source IN ('ai_visual_high','ai_visual_medium','ai_visual_low')"
        )->total ?? 0);
        $rows = DB::select(
            "SELECT id, current_path, asset_uuid, extension, date_taken, date_taken_source,
                    date_taken_confidence, scan_date, exif_written
             FROM file_registry
             WHERE status = 'active' AND is_scan = 1 AND exif_written = 1
               AND date_needs_review = 1
               AND date_taken IS NOT NULL
               AND date_taken_source IN ('ai_visual_high','ai_visual_medium','ai_visual_low')
             ORDER BY id ASC
             LIMIT ? OFFSET ?",
            [$limit, $offset],
        );

        $counts = array_fill_keys([
            'confirmed_legacy_write',
            'strong_legacy_write',
            'partial_legacy_write',
            'metadata_conflict',
            'no_legacy_evidence',
            'file_missing',
            'metadata_unreadable',
        ], 0);
        $items = [];

        foreach ($rows as $row) {
            $item = $this->auditRow($row, $timeoutSeconds);
            $counts[$item['classification']]++;
            if ($includeItems) {
                $items[] = $item;
            }
        }

        $payload = [
            'schema' => 'plos.files_scan_date_legacy_metadata_audit.v1',
            'status' => 'ok',
            'mode' => 'observe',
            'total_legacy_rows' => $total,
            'selected' => count($rows),
            'offset' => $offset,
            'remaining_after_page' => max(0, $total - $offset - count($rows)),
            'classifications' => $counts,
            'correction_plan' => [
                'automatic_correction_available' => false,
                'legacy_writer_recorded_previous_field_values' => false,
                'pre_legacy_state_recoverable_from_catalog' => false,
                'required_before_future_file_mutation' => [
                    'operator_per_file_decision',
                    'external_pre_write_version_or_approved_replacement_values',
                    'full_file_backup_with_sha256',
                    'field_level_before_snapshot',
                    'tested_restore_receipt',
                    'bounded_execute_limit',
                ],
            ],
            'posture' => [
                'read_only' => true,
                'database_writes' => false,
                'file_writes' => false,
                'metadata_writes' => false,
                'paths_included' => false,
                'filenames_included' => false,
                'raw_comments_included' => false,
            ],
        ];
        if ($includeItems) {
            $payload['items'] = $items;
        }

        return $payload;
    }

    /** @return array<string,mixed>|null */
    public function auditFile(int $fileRegistryId, int $timeoutSeconds): ?array
    {
        $row = DB::selectOne(
            "SELECT id, current_path, asset_uuid, extension, date_taken, date_taken_source,
                    date_taken_confidence, scan_date, exif_written
             FROM file_registry
             WHERE id = ? AND status = 'active' AND is_scan = 1 AND exif_written = 1
               AND date_needs_review = 1
               AND date_taken IS NOT NULL
               AND date_taken_source IN ('ai_visual_high','ai_visual_medium','ai_visual_low')",
            [$fileRegistryId],
        );

        return $row === null ? null : $this->auditRow($row, $timeoutSeconds);
    }

    /** @return array<string,mixed> */
    private function auditRow(object $row, int $timeoutSeconds): array
    {
        $base = [
            'target_ref' => $this->targetRef($row),
            'classification' => 'metadata_unreadable',
            'catalog_estimate_date' => $this->normalizeDate($row->date_taken),
            'catalog_scan_date' => $this->normalizeDate($row->scan_date),
            'catalog_source' => $row->date_taken_source,
            'catalog_confidence' => $row->date_taken_confidence === null
                ? null
                : round((float) $row->date_taken_confidence, 3),
            'file_state' => [
                'extension' => strtolower((string) $row->extension),
                'size_bytes' => null,
                'modified_at' => null,
            ],
            'metadata' => null,
            'metadata_snapshot_sha256' => null,
            'recommended_next_action' => 'resolve_file_access',
        ];

        $path = (string) $row->current_path;
        if ($path === '' || ! is_file($path)) {
            return [...$base, 'classification' => 'file_missing'];
        }
        $base['file_state']['size_bytes'] = @filesize($path) ?: 0;
        $modifiedAt = @filemtime($path);
        $base['file_state']['modified_at'] = $modifiedAt === false ? null : gmdate('c', $modifiedAt);

        $result = Process::timeout($timeoutSeconds)->run([
            self::EXIFTOOL,
            '-json',
            '-G1',
            '-s',
            '-EXIF:DateTimeOriginal',
            '-EXIF:CreateDate',
            '-EXIF:DateTimeDigitized',
            '-XMP-photoshop:DateCreated',
            '-IPTC:DateCreated',
            '-IPTC:TimeCreated',
            '-EXIF:UserComment',
            $path,
        ]);
        if (! $result->successful()) {
            return $base;
        }

        $decoded = json_decode($result->output(), true);
        $record = is_array($decoded) && is_array($decoded[0] ?? null) ? $decoded[0] : null;
        if ($record === null) {
            return $base;
        }

        $metadata = [
            'exif_original' => $this->normalizeDate($this->first($record, ['EXIF:DateTimeOriginal'])),
            'exif_digitized' => $this->normalizeDate($this->first($record, ['EXIF:CreateDate', 'EXIF:DateTimeDigitized'])),
            'xmp_created' => $this->normalizeDate($this->first($record, ['XMP-photoshop:DateCreated', 'XMP:DateCreated'])),
            'iptc_created' => $this->normalizeIptc(
                $this->first($record, ['IPTC:DateCreated']),
                $this->first($record, ['IPTC:TimeCreated']),
            ),
            'legacy_comment_marker' => str_starts_with(
                trim((string) $this->first($record, ['EXIF:UserComment'])),
                'Est. original:',
            ),
        ];
        $expected = $base['catalog_estimate_date'];
        $xmpMatches = $expected !== null && $metadata['xmp_created'] === $expected;
        $iptcMatches = $expected !== null && $metadata['iptc_created'] === $expected;
        $marker = $metadata['legacy_comment_marker'];

        $classification = match (true) {
            $xmpMatches && $iptcMatches && $marker => 'confirmed_legacy_write',
            ($xmpMatches && $iptcMatches) || ($marker && ($xmpMatches || $iptcMatches)) => 'strong_legacy_write',
            $xmpMatches || $iptcMatches || $marker => 'partial_legacy_write',
            $metadata['xmp_created'] !== null || $metadata['iptc_created'] !== null => 'metadata_conflict',
            default => 'no_legacy_evidence',
        };
        $nextAction = match ($classification) {
            'confirmed_legacy_write', 'strong_legacy_write' => 'operator_decide_keep_or_restore_from_external_version',
            'partial_legacy_write', 'metadata_conflict' => 'manual_metadata_forensics',
            default => 'verify_legacy_catalog_write_flag',
        };

        return [
            ...$base,
            'classification' => $classification,
            'metadata' => $metadata,
            'metadata_snapshot_sha256' => hash(
                'sha256',
                json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ),
            'recommended_next_action' => $nextAction,
        ];
    }

    /** @param array<string,mixed> $record @param list<string> $keys */
    private function first(array $record, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $record) && $record[$key] !== '') {
                return $record[$key];
            }
        }

        return null;
    }

    private function normalizeIptc(mixed $date, mixed $time): ?string
    {
        $dateOnly = $this->normalizeDate($date);
        if ($dateOnly === null) {
            return null;
        }
        $dateOnly = substr($dateOnly, 0, 10);
        $timeText = trim((string) $time);
        if (preg_match('/^(\d{2}):?(\d{2}):?(\d{2})/', $timeText, $matches) !== 1) {
            return $dateOnly.' 00:00:00';
        }

        return sprintf('%s %s:%s:%s', $dateOnly, $matches[1], $matches[2], $matches[3]);
    }

    private function normalizeDate(mixed $value): ?string
    {
        $text = trim((string) $value);
        if (preg_match(
            '/^(\d{4})[:-](\d{2})[:-](\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?/',
            $text,
            $matches,
        ) !== 1) {
            return null;
        }

        return sprintf(
            '%s-%s-%s %s:%s:%s',
            $matches[1],
            $matches[2],
            $matches[3],
            $matches[4] ?? '00',
            $matches[5] ?? '00',
            $matches[6] ?? '00',
        );
    }

    private function targetRef(object $row): string
    {
        $basis = implode('|', [
            'scan_date_legacy_metadata',
            (string) $row->id,
            (string) $row->asset_uuid,
            (string) $row->date_taken,
        ]);
        $key = (string) config('app.key', '');
        $digest = $key !== '' ? hash_hmac('sha256', $basis, $key) : hash('sha256', $basis);

        return 'scan_date_legacy_metadata:target-'.substr($digest, 0, 12);
    }
}
