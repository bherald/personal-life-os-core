<?php

namespace App\Services;

use App\Services\Files\ExactCaptureDateReconciliationService;
use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * ExifWritebackService - Embeds metadata into physical files
 *
 * Writes enrichment data from database back to physical files:
 * - Dates: EXIF:DateTimeOriginal, EXIF:CreateDate
 * - Faces: XMP:PersonInImage, XMP-mwg-rs:RegionInfo (MWG standard)
 * - AI Tags: IPTC:Keywords, XMP:Subject
 *
 * Uses exiftool for all metadata operations.
 */
class ExifWritebackService
{
    /** @var list<string> */
    private const EXACT_CAPTURE_DATE_TAGS = [
        'ExifIFD:DateTimeOriginal',
        'ExifIFD:CreateDate',
        'ExifIFD:DateTimeDigitized',
        'ExifIFD:ModifyDate',
        'IFD0:ModifyDate',
        'PNG:CreateDate',
        'PNG:ModifyDate',
        'IPTC:DateCreated',
        'IPTC:TimeCreated',
        'IPTC:DigitalCreationDate',
        'IPTC:DigitalCreationTime',
        'XMP-exif:DateTimeOriginal',
        'XMP-exif:DateTimeDigitized',
        'XMP-xmp:CreateDate',
        'XMP-photoshop:DateCreated',
        'XMP-dc:Source',
    ];

    private const TRUSTED_FACE_PROVENANCE_STATUSES = [
        'export_exact',
        'copy_export_exact',
        'export_variant_supported',
        'copy_export_variant_supported',
    ];

    private string $exiftoolPath = '/usr/bin/exiftool';

    private ?bool $sudoCopyBackAvailable = null;

    /**
     * Per-process warning suppression for repeated operational failures.
     *
     * File writeback can touch many files in one run; when the environment lacks
     * copy-back permissions, logging each file as a warning turns the log noisy
     * without adding new information.
     *
     * @var array<string, bool>
     */
    private static array $warningKeysLogged = [];

    /** Approval-to-copy-back source guard, scoped to the synchronous face merge call. */
    private static array $faceWriteSourceGuards = [];

    /**
     * Write estimated original date for a scanned photograph to XMP and IPTC fields.
     *
     * Standard EXIF field semantics for scanned photos:
     *   DateTimeOriginal  — PRESERVE as-is (contains the scan/digitization date written by scanner)
     *   DateTimeDigitized — WRITE scan date here (this is the correct EXIF field for "when digitized")
     *   XMP-photoshop:DateCreated — WRITE estimated original date (Lightroom reads this as capture date)
     *   IPTC:DateCreated + IPTC:TimeCreated — WRITE estimated original date (archival standard)
     *
     * This is non-destructive: DateTimeOriginal is never touched so the scan date is preserved.
     */
    public function writeScanEstimatedDate(
        string $filePath,
        string $estimatedDate,
        ?string $scanDate,
        string $source,
        float $confidence,
        string $precision = 'year',
        bool $operatorApproved = false,
        ?string $expectedSourceHash = null
    ): array {
        if (! $operatorApproved && ! $this->metadataWritebackEnabled()) {
            return $this->metadataWritebackDisabledResult();
        }

        if ($precision !== 'year') {
            return ['success' => false, 'error' => 'Only approved single-year estimates can be written to scan metadata'];
        }

        if ($expectedSourceHash !== null && preg_match('/\A[a-f0-9]{64}\z/', $expectedSourceHash) !== 1) {
            return ['success' => false, 'error' => 'Approved source hash is invalid'];
        }

        if (! file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        if (! $this->exactCaptureDatePathAllowed($filePath)) {
            return ['success' => false, 'error' => 'Source is outside the configured Nextcloud root'];
        }
        if ($expectedSourceHash !== null && ! hash_equals($expectedSourceHash, (string) hash_file('sha256', $filePath))) {
            return ['success' => false, 'error' => 'Authorized source bytes changed before metadata preparation', 'source_replaced' => false];
        }

        if ($confidence < 0.35) {
            return ['success' => false, 'error' => 'Confidence too low for EXIF write', 'confidence' => $confidence];
        }

        try {
            $dt = new \DateTime($estimatedDate);
        } catch (\Exception $e) {
            Log::debug('ExifWritebackService: invalid estimated date', ['date' => $estimatedDate, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Invalid estimated date'];
        }

        // Preserve the approved year precision. XMP DateCreated accepts a partial
        // date; IPTC IIM requires a full date, so omit it rather than inventing Jan 1.
        $year = $dt->format('Y');

        $confidencePct = round($confidence * 100);
        $precisionLabel = $precision === 'year' ? 'approximate year' : $precision;
        $note = "Operator-approved {$precisionLabel}: {$year} (year precision; {$confidencePct}% confidence, {$source}). Scan/digitization date preserved in DateTimeOriginal.";

        $args = ['-overwrite_original', '-preserve'];

        // Estimated original photo date → XMP (Lightroom/Apple Photos) and IPTC (archival)
        $args[] = "-XMP-photoshop:DateCreated=$year";

        // Move scan date to DateTimeDigitized (the correct EXIF field for digitization date)
        if ($scanDate && ! str_starts_with($scanDate, '1970-01-01')) {
            try {
                $scanExif = (new \DateTime($scanDate))->format('Y:m:d H:i:s');
                $args[] = "-DateTimeDigitized=$scanExif";
            } catch (\Exception $e) {
                Log::debug('ExifWritebackService: scan date formatting failed, skipping DateTimeDigitized', ['error' => $e->getMessage()]);
            }
        }

        // Human-readable note explaining the provenance
        $args[] = "-UserComment=$note";

        $dateIntent = ['changes' => [
            'XMP-photoshop:DateCreated' => $year,
            ...(($scanDate && ! str_starts_with($scanDate, '1970-01-01') && isset($scanExif))
                ? ['XMP-exif:DateTimeDigitized' => $scanExif] : []),
        ]];
        $result = $this->runExiftool($filePath, $args, $operatorApproved, $expectedSourceHash, expectedDateMetadata: $dateIntent);

        if ($result['success']) {
            Log::info('ExifWriteback: Scan estimated date written', [
                'file' => basename($filePath),
                'estimated_year' => $year,
                'scan_date' => $scanDate,
                'confidence' => $confidencePct.'%',
                'source' => $source,
            ]);
        }

        return $result;
    }

    /** Read only the source and date tags used by a date-only capture correction. */
    public function readExactCaptureDateEvidence(string $filePath, ?string $expectedSourceHash = null): array
    {
        if ($expectedSourceHash !== null && preg_match('/\A[a-f0-9]{64}\z/', $expectedSourceHash) !== 1) {
            return ['success' => false, 'error' => 'Expected source hash is invalid'];
        }
        if (! $this->exactCaptureDatePathAllowed($filePath)) {
            return ['success' => false, 'error' => 'Source is outside the configured Nextcloud root'];
        }
        $sourceHash = hash_file('sha256', $filePath);
        if (! is_string($sourceHash) || ($expectedSourceHash !== null && ! hash_equals($expectedSourceHash, $sourceHash))) {
            return ['success' => false, 'error' => 'Current source bytes do not match the expected hash'];
        }

        try {
            $tags = $this->readExactCaptureDateTags($filePath);
            $afterHash = hash_file('sha256', $filePath);
            if (! is_string($afterHash) || ! hash_equals($sourceHash, $afterHash)) {
                throw new \RuntimeException('Source changed while date metadata was read');
            }

            return ['success' => true, 'source_sha256' => $sourceHash, 'date_tags' => $tags,
                'date_tags_sha256' => $this->exactCaptureDateDigest($tags)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Read date-only tags only from an immutable, source-bound verified transition packet. */
    public function readVerifiedDateTagTransition(
        string $transitionPath,
        int $fileRegistryId,
        string $assetUuid,
        string $currentRegistryPath,
        string $expectedBeforeHash,
        string $expectedAfterHash,
        string $expectedBeforeDateTagsHash,
        string $expectedAfterDateTagsHash,
        string $currentSourcePath,
        string $currentSourceHash,
    ): array {
        try {
            foreach ([$expectedBeforeHash, $expectedAfterHash, $expectedBeforeDateTagsHash,
                $expectedAfterDateTagsHash, $currentSourceHash] as $hash) {
                if (! preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
                    throw new \RuntimeException('Transition source or tag digest is invalid');
                }
            }
            $privateRoot = realpath(storage_path('app/private'));
            $requested = storage_path($transitionPath);
            $packet = realpath($requested);
            if (! $privateRoot || ! $packet || is_link($requested) || ! str_starts_with($packet, $privateRoot.'/')
                || ! is_file($packet) || filesize($packet) > 1048576) {
                throw new \RuntimeException('Private date transition proof is unavailable');
            }
            $transition = json_decode((string) file_get_contents($packet), true, flags: JSON_THROW_ON_ERROR);
            $registry = $transition['registry_before'] ?? null;
            $date = $transition['date_metadata'] ?? null;
            if (($transition['kind'] ?? null) !== 'verified_metadata_only'
                || ($transition['before_sha256'] ?? null) !== $expectedBeforeHash
                || ($transition['after_sha256'] ?? null) !== $expectedAfterHash
                || ! is_array($registry) || (int) ($registry['id'] ?? 0) !== $fileRegistryId
                || ($registry['asset_uuid'] ?? null) !== $assetUuid
                || ($registry['current_path'] ?? null) !== $currentRegistryPath
                || ! is_string($transition['source_path'] ?? null)
                || realpath($transition['source_path']) !== $transition['source_path']
                || ! $this->exactCaptureDatePathAllowed($transition['source_path'])
                || ! is_array($date) || ! is_array($date['after_tags'] ?? null)
                || ($date['before_tags_sha256'] ?? null) !== $expectedBeforeDateTagsHash
                || ($date['after_tags_sha256'] ?? null) !== $expectedAfterDateTagsHash
                || ! hash_equals($expectedBeforeDateTagsHash, $this->exactCaptureDateDigest($date['before_tags'] ?? []))
                || ! hash_equals($expectedAfterDateTagsHash, $this->exactCaptureDateDigest($date['after_tags']))) {
                throw new \RuntimeException('Private date transition does not match its completed receipt');
            }
            $backupRelative = $transition['backup']['backup_path'] ?? null;
            $backup = is_string($backupRelative) ? realpath(storage_path($backupRelative)) : false;
            if (! $backup || is_link(storage_path((string) $backupRelative)) || ! str_starts_with($backup, $privateRoot.'/')
                || hash_file('sha256', $backup) !== $expectedBeforeHash
                || ! $this->exactCaptureDatePathAllowed($currentSourcePath)
                || realpath($currentSourcePath) !== $currentSourcePath
                || ! hash_equals($currentSourceHash, (string) hash_file('sha256', $currentSourcePath))) {
                throw new \RuntimeException('Transition backup or current source changed');
            }
            $imageProof = $transition['image_proof'] ?? null;
            if (! is_array($imageProof) || $this->metadataImageProof($backup) !== $imageProof
                || $this->metadataImageProof($currentSourcePath) !== $imageProof) {
                throw new \RuntimeException('Transition source image proof no longer matches');
            }

            return ['success' => true, 'transition_path' => $transitionPath,
                'before_sha256' => $expectedBeforeHash, 'after_sha256' => $expectedAfterHash,
                'date_tags' => $date['after_tags'], 'date_tags_sha256' => $expectedAfterDateTagsHash];
        } catch (\Throwable $error) {
            return ['success' => false, 'error' => 'Verified date transition is unavailable or changed.'];
        }
    }

    /** Apply a date-only capture correction while preserving digitization date tags. */
    public function writeExactCaptureDateMetadata(
        string $filePath,
        string $captureDate,
        string $expectedSourceHash,
        string $expectedDateTagsHash,
        ?callable $beforeCopyBack = null,
        ?array $restoredDateTags = null,
    ): array {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $captureDate);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (! $date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $date->format('Y-m-d') !== $captureDate
            || preg_match('/\A[a-f0-9]{64}\z/', $expectedSourceHash) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $expectedDateTagsHash) !== 1) {
            return ['success' => false, 'error' => 'Exact date and source metadata proof are required', 'source_replaced' => false];
        }

        $evidence = $this->readExactCaptureDateEvidence($filePath, $expectedSourceHash);
        if (! ($evidence['success'] ?? false) || ! hash_equals($expectedDateTagsHash, (string) ($evidence['date_tags_sha256'] ?? ''))) {
            return ['success' => false, 'error' => 'Current date metadata differs from the approved preimage', 'source_replaced' => false];
        }

        $beforeTags = $evidence['date_tags'];
        $afterTags = $beforeTags;
        $afterTags['ExifIFD:DateTimeOriginal'] = null;
        $afterTags['IPTC:DateCreated'] = $date->format('Y:m:d');
        $afterTags['IPTC:TimeCreated'] = null;
        $afterTags['XMP-exif:DateTimeOriginal'] = null;
        $afterTags['XMP-photoshop:DateCreated'] = $date->format('Y:m:d');
        if (! in_array($beforeTags['XMP-dc:Source'], [null, 'plos:operator-confirmed-date-only'], true)) {
            return ['success' => false, 'error' => 'Existing source description cannot be replaced', 'source_replaced' => false];
        }
        $afterTags['XMP-dc:Source'] = 'plos:operator-confirmed-date-only';
        if ($restoredDateTags !== null) {
            if (array_diff(array_keys($afterTags), array_keys($restoredDateTags)) !== []
                || array_diff(array_keys($restoredDateTags), array_keys($afterTags)) !== []) {
                return ['success' => false, 'error' => 'Verified historical date-tag map is incomplete.', 'source_replaced' => false];
            }
            foreach ($restoredDateTags as $tag => $value) {
                if (! in_array($tag, self::EXACT_CAPTURE_DATE_TAGS, true)
                    || ($value !== null && (! is_string($value) || trim($value) === '' || strlen($value) > 128))) {
                    return ['success' => false, 'error' => 'Verified historical date-tag map is invalid.', 'source_replaced' => false];
                }
            }
            $afterTags = $restoredDateTags;
        }
        ksort($afterTags);

        $args = ['-overwrite_original', '-preserve'];
        if ($restoredDateTags === null) {
            $args = array_merge($args, [
                '-EXIF:DateTimeOriginal=',
                '-XMP-exif:DateTimeOriginal=',
                '-IPTC:DateCreated='.$date->format('Ymd'),
                '-IPTC:TimeCreated=',
                '-XMP-photoshop:DateCreated='.$date->format('Y-m-d'),
                '-XMP-dc:Source=plos:operator-confirmed-date-only',
            ]);
        } else {
            foreach ($restoredDateTags as $tag => $value) {
                $args[] = '-'.$tag.'='.($value ?? '');
            }
        }
        $dateProof = [
            'before_tags' => $beforeTags,
            'before_sha256' => $expectedDateTagsHash,
            'after_tags' => $afterTags,
            'after_sha256' => $this->exactCaptureDateDigest($afterTags),
        ];

        return $this->runExiftool(
            $filePath,
            $args,
            true,
            $expectedSourceHash,
            null,
            $beforeCopyBack,
            $dateProof,
        );
    }

    /**
     * Write date metadata to file
     */
    public function writeDate(string $filePath, string $dateTaken, string $source, float $confidence): array
    {
        if (! $this->metadataWritebackEnabled()) {
            return $this->metadataWritebackDisabledResult();
        }

        if (preg_match('/^(?:ai_|exif_|scan_|inferred|file_modified)/i', $source) === 1) {
            return ['success' => false, 'error' => 'Inferred and digitization dates cannot replace original EXIF dates'];
        }

        if (! file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        // Only write if confidence is high enough (0.3 excludes unreliable file_modified dates at 0.20)
        if ($confidence < 0.3) {
            return ['success' => false, 'error' => 'Confidence too low', 'confidence' => $confidence];
        }

        // Format date for EXIF (YYYY:MM:DD HH:MM:SS)
        $exifDate = $this->formatDateForExif($dateTaken);
        if (! $exifDate) {
            return ['success' => false, 'error' => 'Invalid date format'];
        }

        // Build exiftool command
        $args = [
            '-overwrite_original',
            '-preserve',
            "-DateTimeOriginal=$exifDate",
            "-CreateDate=$exifDate",
            "-ModifyDate=$exifDate",
            // Add source info as comment
            "-UserComment=Date source: $source (confidence: ".round($confidence * 100).'%)',
        ];

        $dateIntent = ['changes' => [
            'ExifIFD:DateTimeOriginal' => $exifDate,
        ] + (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'png' ? [
            'ExifIFD:CreateDate' => $exifDate,
            'PNG:CreateDate' => $exifDate,
            'PNG:ModifyDate' => $exifDate,
            'IFD0:ModifyDate' => $exifDate,
        ] : [
            'ExifIFD:CreateDate' => $exifDate,
            'IFD0:ModifyDate' => $exifDate,
        ]),
        ];
        $result = $this->runExiftool($filePath, $args, expectedDateMetadata: $dateIntent);

        if ($result['success']) {
            Log::info('ExifWriteback: Date written', [
                'file' => basename($filePath),
                'date' => $exifDate,
                'source' => $source,
            ]);
        }

        return $result;
    }

    /**
     * Write face/person metadata to file
     * Uses MWG (Metadata Working Group) standard for face regions
     */
    public function writeFaces(string $filePath, array $faces): array
    {
        if (! $this->metadataWritebackEnabled()) {
            return $this->metadataWritebackDisabledResult();
        }

        if (! file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        $regions = [];
        $skipped = 0;
        $approvedSourceHash = null;
        foreach ($faces as $suppliedFace) {
            $suppliedFace = (object) $suppliedFace;
            $faceId = (int) ($suppliedFace->id ?? $suppliedFace->face_id ?? 0);
            $personId = (int) ($suppliedFace->genealogy_person_id ?? 0);
            if ($faceId < 1) {
                $skipped++;

                continue;
            }
            try {
                if ($personId < 1 || ! empty($suppliedFace->global_identity_uuid)) {
                    $face = app(GlobalFaceWritebackApprovalService::class)->approvedFace($faceId);
                    $approval = ['success' => $face !== null, 'operator_approved' => $face !== null];
                } else {
                    $face = DB::selectOne("SELECT frf.id, frf.genealogy_person_id, frf.global_identity_uuid,
                    CONCAT(gp.given_name, ' ', gp.surname) AS person_name,
                    frf.region_x, frf.region_y, frf.region_w, frf.region_h, frf.source_sha256, fr.current_path
                FROM file_registry_faces frf
                JOIN file_registry fr ON fr.id = frf.file_registry_id
                JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
                JOIN face_identity_provenance fip ON fip.file_registry_face_id = frf.id
                WHERE frf.id = ? AND frf.genealogy_person_id = ?
                  AND {$this->faceWritebackEligibilitySql()}", [$faceId, $personId]);
                    if (! $face) {
                        $skipped++;

                        continue;
                    }
                    if (! empty($face->global_identity_uuid)) {
                        $face = app(GlobalFaceWritebackApprovalService::class)->approvedFace($faceId);
                        $approval = ['success' => $face !== null, 'operator_approved' => $face !== null];
                    } else {
                        $approval = app(FaceLinkBridgeService::class)->syncAutomaticFaceLink($faceId, $personId);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('ExifWritebackService: face approval unavailable', ['face_id' => $faceId, 'error' => $e->getMessage()]);
                $skipped++;

                continue;
            }
            if (! $face || ! ($approval['success'] ?? false) || ! ($approval['operator_approved'] ?? false)
                || ! $this->faceSourceMatchesFile((string) $face->current_path, $filePath)) {
                $skipped++;

                continue;
            }
            $faceHash = $face->source_sha256 ?? @hash_file('sha256', $filePath);
            if (! is_string($faceHash) || ($approvedSourceHash !== null && $approvedSourceHash !== $faceHash)) {
                $skipped++;

                continue;
            }
            $approvedSourceHash = $faceHash;
            $regions[] = [
                'name' => trim($face->person_name),
                'x' => (float) $face->region_x + (float) $face->region_w / 2,
                'y' => (float) $face->region_y + (float) $face->region_h / 2,
                'w' => (float) $face->region_w,
                'h' => (float) $face->region_h,
            ];
        }
        if ($regions === []) {
            return ['success' => false, 'code' => -4, 'skipped' => $skipped, 'written' => 0, 'error' => 'No individually approved face regions to write; operator review required'];
        }

        $guardPath = realpath($filePath);
        $previousGuard = self::$faceWriteSourceGuards[$guardPath] ?? null;
        self::$faceWriteSourceGuards[$guardPath] = $approvedSourceHash;
        try {
            $success = app(FaceRegionService::class)->mergeConfirmedFaceRegions($filePath, $regions);
        } finally {
            if ($previousGuard === null) {
                unset(self::$faceWriteSourceGuards[$guardPath]);
            } else {
                self::$faceWriteSourceGuards[$guardPath] = $previousGuard;
            }
        }

        return ['success' => $success, 'written' => $success ? count($regions) : 0, 'skipped' => $skipped,
            'error' => $success ? null : 'Approved face region preservation/write failed'];
    }

    /** Explicit full replacement of legacy MWG geometry from current individual approvals. */
    public function replaceReviewedFaceMetadata(string $filePath, string $expectedSourceHash, string $expectedRegionInfoHash, array $replacements, bool $dryRun = true, ?array $authority = null): array
    {
        return $this->writeReviewedFaceMetadata($filePath, $expectedSourceHash, $expectedRegionInfoHash,
            $replacements, false, null, null, $dryRun, $authority);
    }

    /** Append exactly one currently approved face to an exact empty RegionList. */
    public function appendReviewedFaceMetadata(string $filePath, string $expectedSourceHash, string $expectedRegionInfoHash,
        int $approvedFaceId, string $approvalReceiptId, bool $dryRun = true, ?array $authority = null): array
    {
        return $this->writeReviewedFaceMetadata($filePath, $expectedSourceHash, $expectedRegionInfoHash,
            [], true, $approvedFaceId, $approvalReceiptId, $dryRun, $authority);
    }

    private function writeReviewedFaceMetadata(string $filePath, string $expectedSourceHash, string $expectedRegionInfoHash,
        array $replacements, bool $append, ?int $appendFaceId, ?string $appendApprovalReceiptId,
        bool $dryRun, ?array $authority): array
    {
        $jsonFile = null;
        $dispatched = false;
        try {
            if (! $dryRun) {
                $this->requireFaceMetadataRepairAuthority($authority);
            }
            foreach ([$expectedSourceHash, $expectedRegionInfoHash] as $sha) {
                if (! preg_match('/^[a-f0-9]{64}$/D', $sha)) {
                    throw new \RuntimeException('Exact source and RegionInfo SHA-256 are required');
                }
            }
            if (! $this->faceSourceMatchesFile($filePath, $filePath)) {
                throw new \RuntimeException('Face metadata source is outside the configured Nextcloud root');
            }
            $service = app(FaceRegionService::class);
            $evidence = $service->readFaceMetadataEvidence($filePath, $expectedSourceHash);
            if (! hash_equals($expectedRegionInfoHash, $evidence['region_info_sha256']) || $evidence['source_dimensions'] === null) {
                throw new \RuntimeException('Current raw metadata or encoded source dimensions require review');
            }
            $info = $evidence['region_info'];
            $regions = $info['RegionList'] ?? [];
            if ($append) {
                if ($regions !== [] || $replacements !== [] || ! is_int($appendFaceId) || $appendFaceId < 1
                    || ! is_string($appendApprovalReceiptId) || ! Str::isUuid($appendApprovalReceiptId)
                    || strtolower($appendApprovalReceiptId) !== $appendApprovalReceiptId) {
                    throw new \RuntimeException('Empty RegionList and one exact approved face are required');
                }
                $replacements = [['region_index' => 0, 'approved_face_id' => $appendFaceId,
                    'approval_receipt_id' => $appendApprovalReceiptId]];
            } elseif ($regions === [] || ! array_is_list($replacements) || count($replacements) !== count($regions)) {
                throw new \RuntimeException('Every existing metadata region must be explicitly reviewed');
            }
            $seen = $faces = $approved = [];
            foreach ($replacements as $replacement) {
                if (! is_array($replacement) || count($replacement) !== 3
                    || array_diff(array_keys($replacement), ['region_index', 'approved_face_id', 'approval_receipt_id']) !== []
                    || ! is_int($replacement['region_index'] ?? null) || ! is_int($replacement['approved_face_id'] ?? null)
                    || $replacement['approved_face_id'] < 1 || ! is_string($replacement['approval_receipt_id'] ?? null)
                    || ! Str::isUuid($replacement['approval_receipt_id'])) {
                    throw new \RuntimeException('Exact reviewed index, approved face ID and receipt are required');
                }
                $index = $replacement['region_index'];
                $faceId = $replacement['approved_face_id'];
                if ((! $append && (! array_key_exists($index, $regions) || ($regions[$index]['Type'] ?? null) !== 'Face'))
                    || ($append && ($index !== 0 || $faceId !== $appendFaceId || $replacement['approval_receipt_id'] !== $appendApprovalReceiptId))
                    || isset($seen[$index]) || isset($faces[$faceId])) {
                    throw new \RuntimeException('Unknown, duplicate or non-face metadata region requires review');
                }
                $face = app(GlobalFaceWritebackApprovalService::class)->approvedFace($faceId);
                if (! $face || ($face->global_identity_approval_receipt_id ?? null) !== $replacement['approval_receipt_id']
                    || ($face->source_sha256 ?? null) !== $expectedSourceHash
                    || ($face->face_region_convention ?? null) !== 'normalized_top_left'
                    || ($face->source_current ?? null) != 1 || ($face->superseded_at ?? null) !== null
                    || ! $this->faceSourceMatchesFile((string) $face->current_path, $filePath)
                    || ! is_string($face->person_name ?? null) || trim($face->person_name) === '') {
                    throw new \RuntimeException('Current individual face approval does not match the repair source');
                }
                $box = [];
                foreach (['x', 'y', 'w', 'h'] as $axis) {
                    $value = $face->{'region_'.$axis} ?? null;
                    if (! is_numeric($value) || ! is_finite((float) $value)) {
                        throw new \RuntimeException('Approved encoded face region is invalid');
                    }
                    $box[$axis] = (float) $value;
                }
                if ($box['x'] < 0 || $box['y'] < 0 || $box['w'] <= 0 || $box['h'] <= 0
                    || $box['x'] + $box['w'] > 1 || $box['y'] + $box['h'] > 1
                    || floor($box['w'] * $evidence['source_dimensions']['width']) < 1
                    || floor($box['h'] * $evidence['source_dimensions']['height']) < 1) {
                    throw new \RuntimeException('Approved encoded face crop is unavailable');
                }
                $region = $append ? ['Type' => 'Face'] : $regions[$index];
                $region['Name'] = $face->person_name;
                // Source ROIs are stored at DECIMAL(10,8); their midpoint can
                // have at most nine meaningful fractional digits. Canonicalize
                // that midpoint before both the JSON write and digest comparison
                // so ExifTool sees the same decimal value on its real read-back.
                $region['Area'] = array_replace(is_array($region['Area'] ?? null) ? $region['Area'] : [], [
                    'X' => round($box['x'] + $box['w'] / 2, 9), 'Y' => round($box['y'] + $box['h'] / 2, 9),
                    'W' => $box['w'], 'H' => $box['h'], 'Unit' => 'normalized',
                ]);
                $regions[$index] = $region;
                $seen[$index] = true;
                $faces[$faceId] = true;
                $approved[$faceId] = clone $face;
            }
            $info['RegionList'] = $regions;
            $info['AppliedToDimensions'] = array_replace(is_array($info['AppliedToDimensions'] ?? null) ? $info['AppliedToDimensions'] : [], [
                'W' => $evidence['source_dimensions']['width'], 'H' => $evidence['source_dimensions']['height'], 'Unit' => 'pixel',
            ]);
            $preview = ['dry_run' => $dryRun, 'metadata_file_written' => false, 'source_sha256' => $expectedSourceHash,
                'region_info_sha256' => $expectedRegionInfoHash, 'proposed_region_info' => $info,
                'proposed_region_info_sha256' => $service->faceMetadataDigest($info),
                'regions_reviewed' => count($seen), 'identity_approval' => false];
            if ($dryRun) {
                return ['success' => true] + $preview;
            }
            // Recheck authority and source just before the verified temporary write.
            $this->requireFaceMetadataRepairAuthority($authority);
            $jsonFile = tempnam(sys_get_temp_dir(), 'face_reviewed_repair_');
            if ($jsonFile === false || file_put_contents($jsonFile, json_encode([
                ['SourceFile' => '*', 'XMP-mwg-rs:RegionInfo' => $info],
            ], JSON_THROW_ON_ERROR)) === false) {
                throw new \RuntimeException('Reviewed metadata preparation failed');
            }
            // The exact framework-approved repair grants a bounded operator override;
            // global automatic metadata writeback remains unchanged.
            ksort($approved);
            $originalReceipts = [];
            foreach ($approved as $face) {
                foreach ([$face->global_identity_approval_receipt_id, $face->name_approval_receipt_id] as $id) {
                    $receipt = DB::selectOne('SELECT id, reference, action, state, payload, created_at FROM review_desk_receipts WHERE id = ?', [$id]);
                    if (! $receipt || $receipt->state !== 'completed') {
                        throw new \RuntimeException('Reviewed approval receipt changed before metadata preparation');
                    }
                    $originalReceipts[$id] = $receipt;
                }
            }
            $finalProof = function ($connection) use ($approved, $originalReceipts, $service): void {
                // ReviewDesk admits decisions under reference -> source queue -> receipt
                // locks, then the canonical handler locks identity and face rows.
                $references = array_unique(array_map(fn ($receipt) => $receipt->reference, $originalReceipts));
                sort($references);
                foreach ($references as $reference) {
                    $record = $connection->select('SELECT * FROM review_desk_references WHERE reference = ? FOR UPDATE', [$reference])[0] ?? null;
                    $queue = $record && $record->source === 'default:agent_review_queue'
                        ? ($connection->select('SELECT * FROM agent_review_queue WHERE id = ? FOR UPDATE', [$record->source_id])[0] ?? null) : null;
                    // Current locking reads are authoritative under MySQL repeatable-read;
                    // the normal proof reader may otherwise retain an older snapshot.
                    $receipts = $connection->select('SELECT id, reference, action, state, payload, created_at FROM review_desk_receipts WHERE reference = ? ORDER BY id FOR UPDATE', [$reference]);
                    $earliest = null;
                    foreach ($originalReceipts as $id => $originalReceipt) {
                        if ($originalReceipt->reference !== $reference) {
                            continue;
                        }
                        $currentReceipt = null;
                        foreach ($receipts as $receipt) {
                            if ($receipt->id === $id) {
                                $currentReceipt = $receipt;
                            }
                        }
                        if (! $currentReceipt || (array) $currentReceipt !== (array) $originalReceipt) {
                            throw new \RuntimeException('Reviewed approval receipt changed before metadata copy-back');
                        }
                        $earliest = $earliest === null ? $originalReceipt->created_at : min($earliest, $originalReceipt->created_at);
                        if ($originalReceipt->action === 'approve') {
                            $payload = json_decode($originalReceipt->payload, true, flags: JSON_THROW_ON_ERROR);
                            $details = $queue ? json_decode($queue->details, true, flags: JSON_THROW_ON_ERROR) : null;
                            if (! $record || $record->review_type !== 'global_face_identity' || $record->source_state !== 'approved'
                                || ! $queue || $queue->review_type !== 'global_face_identity' || $queue->status !== 'approved'
                                || ! is_array($details['global_identity_decision'] ?? null)
                                || ! is_array($payload['global_identity_decision'] ?? null)
                                || $service->faceMetadataDigest($details['global_identity_decision']) !== $service->faceMetadataDigest($payload['global_identity_decision'])) {
                                throw new \RuntimeException('Reviewed approval queue changed before metadata copy-back');
                            }
                        }
                    }
                    foreach ($receipts as $receipt) {
                        if (in_array($receipt->state, ['processing', 'unknown'], true)
                            || ($receipt->state === 'completed' && in_array($receipt->action, ['reject', 'false_detection'], true) && $receipt->created_at >= $earliest)) {
                            throw new \RuntimeException('Current individual approval changed before metadata copy-back');
                        }
                    }
                }
                $identities = array_unique(array_map(fn ($face) => $face->global_identity_uuid, $approved));
                sort($identities);
                foreach ($identities as $uuid) {
                    $identity = $connection->select('SELECT * FROM global_face_identities WHERE identity_uuid = ? FOR UPDATE', [$uuid])[0] ?? null;
                    foreach ($approved as $original) {
                        if ($original->global_identity_uuid === $uuid && (! $identity || $identity->status !== 'operator_confirmed'
                            || $identity->canonical_name !== $original->person_name || $identity->approval_receipt_id !== $original->name_approval_receipt_id)) {
                            throw new \RuntimeException('Reviewed identity changed before metadata copy-back');
                        }
                    }
                }
                $files = array_unique(array_map(fn ($face) => $face->file_registry_id, $approved));
                sort($files);
                foreach ($files as $fileId) {
                    $file = $connection->select('SELECT id, current_path, status FROM file_registry WHERE id = ? FOR UPDATE', [$fileId])[0] ?? null;
                    foreach ($approved as $original) {
                        if ($original->file_registry_id === $fileId && (! $file || $file->status !== 'active' || $file->current_path !== $original->current_path)) {
                            throw new \RuntimeException('Reviewed source registry changed before metadata copy-back');
                        }
                    }
                }
                foreach ($approved as $id => $original) {
                    $locked = $connection->select('SELECT * FROM file_registry_faces WHERE id = ? FOR UPDATE', [$id])[0] ?? null;
                    foreach (['id', 'file_registry_id', 'global_identity_uuid', 'global_identity_approval_receipt_id',
                        'source_sha256', 'source_current', 'superseded_at', 'hidden', 'face_region_convention',
                        'region_x', 'region_y', 'region_w', 'region_h'] as $field) {
                        if (! $locked || $locked->{$field} !== $original->{$field}) {
                            throw new \RuntimeException('Reviewed face changed before metadata copy-back');
                        }
                    }
                    $current = app(GlobalFaceWritebackApprovalService::class)->approvedFace($id);
                    foreach (['id', 'file_registry_id', 'global_identity_uuid', 'global_identity_approval_receipt_id',
                        'name_approval_receipt_id', 'person_name', 'source_sha256', 'current_path',
                        'region_x', 'region_y', 'region_w', 'region_h'] as $field) {
                        if (! $current || $current->{$field} !== $original->{$field}) {
                            throw new \RuntimeException('Current individual approval changed before metadata copy-back');
                        }
                    }
                }
            };
            $dispatched = true;
            $result = $this->writeVerifiedMetadata($filePath, ['-overwrite_original', '-preserve', '-struct', '-json='.$jsonFile], true, $expectedSourceHash, $info, $finalProof);
            $preview['metadata_file_written'] = (($result['success'] ?? false) && empty($result['unchanged'])) || ! empty($result['source_replaced']);
            $preview['physical_write_outcome_unknown'] = ! empty($result['repair_required']) || ! empty($result['source_replaced']);

            return array_merge($preview, $result);
        } catch (\Throwable $e) {
            return ['success' => false, 'status' => 'held', 'dry_run' => $dryRun, 'metadata_file_written' => false,
                'physical_write_outcome_unknown' => $dispatched, 'error' => $e->getMessage()];
        } finally {
            if (is_string($jsonFile)) {
                @unlink($jsonFile);
            }
        }
    }

    /** Only the hidden caller captured by the registered worker can authorize this repair. */
    private function requireFaceMetadataRepairAuthority(?array $authority): void
    {
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        $keys = ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256'];
        if (! is_array($caller) || count($caller) !== count($keys) || array_diff(array_keys($caller), $keys)
            || $authority !== $caller) {
            throw new \RuntimeException('Trusted framework repair authority is required');
        }
        foreach ($keys as $key) {
            if (! is_string($caller[$key]) || $caller[$key] === '' || strlen($caller[$key]) > 255
                || preg_match('/[\x00-\x20\x7f]/', $caller[$key])
                || (str_ends_with($key, 'sha256') && ! preg_match('/^[a-f0-9]{64}$/D', $caller[$key]))) {
                throw new \RuntimeException('Trusted framework repair authority is required');
            }
        }
    }

    private function exactCaptureDatePathAllowed(string $filePath): bool
    {
        $root = realpath((string) config('services.nextcloud.data_path'));
        $path = realpath($filePath);

        return $root !== false && $path !== false && is_file($path) && is_readable($path) && ! is_link($filePath)
            && str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    /** @return array<string, string|null> */
    private function readExactCaptureDateTags(string $filePath): array
    {
        $result = Process::timeout(30)->run([
            $this->exiftoolPath, '-json', '-G1', '-a', '-s', '-time:all', '-XMP-dc:Source', $filePath,
        ]);
        $records = $result->successful() ? json_decode($result->output(), true) : null;
        if (! is_array($records) || count($records) !== 1 || ! is_array($records[0] ?? null)
            || ($records[0]['SourceFile'] ?? null) !== $filePath
            || isset($records[0]['Error']) || isset($records[0]['Warning'])) {
            throw new \RuntimeException('Exact date tag evidence is unavailable or ambiguous');
        }

        $tags = array_fill_keys(self::EXACT_CAPTURE_DATE_TAGS, null);
        foreach ($records[0] as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            foreach (self::EXACT_CAPTURE_DATE_TAGS as $tag) {
                if ($key === $tag) {
                    // ExifTool JSON represents an exact partial XMP year as a number.
                    if ($tag === 'XMP-photoshop:DateCreated' && is_int($value) && $value >= 1000 && $value <= 9999) {
                        $value = (string) $value;
                    }
                    if (! is_string($value) || trim($value) === '' || strlen($value) > 128) {
                        throw new \RuntimeException('Exact date tag value is malformed');
                    }
                    $tags[$tag] = $value;

                    continue 2;
                }
                if (str_starts_with($key, $tag.' (')) {
                    throw new \RuntimeException('Duplicate exact date tag requires review');
                }
            }
        }
        ksort($tags);

        return $tags;
    }

    /** @param array<string, string|null> $tags */
    private function exactCaptureDateDigest(array $tags): string
    {
        ksort($tags);

        return hash('sha256', json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** Bind intended date-tag changes to an already-read exact source tag map. */
    private function captureDateMetadataProof(array $before, array $changes): array
    {
        $after = $before;
        foreach ($changes as $tag => $value) {
            if (! in_array($tag, self::EXACT_CAPTURE_DATE_TAGS, true) || ! is_string($value) || trim($value) === '') {
                throw new \RuntimeException('Explicit date metadata change is invalid');
            }
            $after[$tag] = $value;
        }
        ksort($after);

        return ['before_tags' => $before, 'before_sha256' => $this->exactCaptureDateDigest($before),
            'after_tags' => $after, 'after_sha256' => $this->exactCaptureDateDigest($after)];
    }

    private function faceSourceMatchesFile(string $source, string $filePath): bool
    {
        $target = realpath($filePath);
        $configuredRoot = trim((string) config('services.nextcloud.data_path', ''));
        $root = $configuredRoot !== '' ? realpath($configuredRoot) : false;
        if ($target === false || $root === false || ! str_starts_with($target, rtrim($root, '/').'/')) {
            return false;
        }
        foreach ([$source, rtrim($root, '/').'/'.ltrim($source, '/')] as $candidate) {
            if (realpath($candidate) === $target) {
                return true;
            }
        }

        return false;
    }

    public function faceWritebackEligibilitySql(string $faceAlias = 'frf', string $provenanceAlias = 'fip'): string
    {
        $faceAlias = $this->safeSqlAlias($faceAlias);
        $provenanceAlias = $this->safeSqlAlias($provenanceAlias);
        $trustedStatuses = $this->trustedFaceStatusSql();

        return "{$faceAlias}.source_current = 1
            AND {$faceAlias}.superseded_at IS NULL
            AND COALESCE({$faceAlias}.hidden, 0) = 0
            AND COALESCE({$faceAlias}.verified, 0) = 1
            AND {$faceAlias}.genealogy_person_id IS NOT NULL
            AND NULLIF(TRIM(COALESCE({$faceAlias}.person_name, '')), '') IS NOT NULL
            AND LOWER(TRIM({$faceAlias}.person_name)) != 'unknown'
            AND {$provenanceAlias}.status IN ({$trustedStatuses})";
    }

    public function faceWritebackExistsSql(
        string $fileAlias = 'fr',
        string $faceAlias = 'frf',
        string $provenanceAlias = 'fip'
    ): string {
        $fileAlias = $this->safeSqlAlias($fileAlias);
        $faceAlias = $this->safeSqlAlias($faceAlias);
        $provenanceAlias = $this->safeSqlAlias($provenanceAlias);

        return "(EXISTS (
            SELECT 1
            FROM file_registry_faces {$faceAlias}
            JOIN face_identity_provenance {$provenanceAlias}
              ON {$provenanceAlias}.file_registry_face_id = {$faceAlias}.id
            WHERE {$faceAlias}.file_registry_id = {$fileAlias}.id
              AND {$this->faceWritebackEligibilitySql($faceAlias, $provenanceAlias)}
        ) OR EXISTS (
            SELECT 1 FROM file_registry_faces global_face
            JOIN global_face_identities global_person ON global_person.identity_uuid = global_face.global_identity_uuid
            WHERE global_face.file_registry_id = {$fileAlias}.id
              AND global_face.source_current = 1 AND global_face.superseded_at IS NULL
              AND COALESCE(global_face.hidden, 0) = 0
              AND global_face.global_identity_approval_receipt_id IS NOT NULL
              AND global_person.status = 'operator_confirmed'
        ))";
    }

    /**
     * @return array<int, object>
     */
    public function facesForWriteback(int $fileId): array
    {
        try {
            $global = app(GlobalFaceWritebackApprovalService::class)->forFile($fileId);
            if (! $global['available']) {
                return [];
            }
            $faces = DB::select("
                SELECT frf.id, CONCAT(gp.given_name, ' ', gp.surname) AS person_name, frf.genealogy_person_id,
                       frf.region_x, frf.region_y, frf.region_w, frf.region_h,
                       frf.confidence
                FROM file_registry_faces frf
                JOIN face_identity_provenance fip ON fip.file_registry_face_id = frf.id
                JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
                WHERE frf.file_registry_id = ?
                  AND {$this->faceWritebackEligibilitySql()}
                ORDER BY frf.id ASC
            ", [$fileId]);

            $legacy = array_values(array_filter($faces, static function (object $face) use ($global): bool {
                if (in_array((int) $face->id, $global['claimed_ids'], true)) {
                    return false;
                }
                $approval = app(FaceLinkBridgeService::class)->syncAutomaticFaceLink((int) $face->id, (int) $face->genealogy_person_id);

                return ($approval['success'] ?? false) && ($approval['operator_approved'] ?? false);
            }));

            return [...$legacy, ...$global['approved']];
        } catch (\Throwable $e) {
            Log::warning('ExifWritebackService: trusted face writeback query failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Write AI tags/keywords to file
     */
    public function writeTags(string $filePath, array $tags, ?string $description = null): array
    {
        if (! $this->metadataWritebackEnabled()) {
            return $this->metadataWritebackDisabledResult();
        }

        if (! file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        if (empty($tags) && ! $description) {
            return ['success' => true, 'message' => 'No tags to write'];
        }

        $args = ['-overwrite_original', '-preserve'];

        // Write keywords (IPTC and XMP for maximum compatibility)
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if ($tag) {
                $args[] = "-IPTC:Keywords+=$tag";
                $args[] = "-XMP:Subject+=$tag";
            }
        }

        // Write description if provided
        if ($description) {
            $args[] = "-IPTC:Caption-Abstract=$description";
            $args[] = "-XMP:Description=$description";
        }

        $result = $this->runExiftool($filePath, $args);

        if ($result['success']) {
            Log::info('ExifWriteback: Tags written', [
                'file' => basename($filePath),
                'tag_count' => count($tags),
            ]);
        }

        return $result;
    }

    /**
     * Write GPS-derived location to IPTC:City, Province-State, Country-PrimaryLocationName.
     * Only writes fields that are currently blank in the file — never overwrites existing data.
     */
    public function writeLocation(string $filePath, string $gpsLocation): array
    {
        if (! $this->metadataWritebackEnabled()) {
            return $this->metadataWritebackDisabledResult();
        }

        if (! file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        if (! $gpsLocation) {
            return ['success' => true, 'message' => 'No location to write'];
        }

        // Parse "City, State, Country" from Nominatim result
        $parts = array_map('trim', explode(',', $gpsLocation));
        $city = $parts[0] ?? null;
        $state = count($parts) >= 3 ? $parts[1] : null;
        $country = $parts[count($parts) - 1] ?? null;

        if (! $city && ! $country) {
            return ['success' => true, 'message' => 'No parseable location parts'];
        }

        // Read existing IPTC location fields — only write where blank
        $exiftoolPath = $this->exiftoolPath;
        $json = Process::timeout(30)->run([
            $exiftoolPath,
            '-json',
            '-IPTC:City',
            '-IPTC:Province-State',
            '-IPTC:Country-PrimaryLocationName',
            $filePath,
        ])->output();
        $existing = json_decode($json ?? '[]', true)[0] ?? [];

        $args = ['-overwrite_original', '-preserve'];

        if ($city && empty($existing['City'])) {
            $args[] = '-IPTC:City='.$city;
        }
        if ($state && empty($existing['Province-State'])) {
            $args[] = '-IPTC:Province-State='.$state;
        }
        if ($country && empty($existing['Country-PrimaryLocationName'])) {
            $args[] = '-IPTC:Country-PrimaryLocationName='.$country;
        }

        // Nothing to write — all fields already populated
        if (count($args) === 2) {
            return ['success' => true, 'message' => 'IPTC location fields already set'];
        }

        $result = $this->runExiftool($filePath, $args);

        if ($result['success']) {
            Log::info('ExifWriteback: Location written', [
                'file' => basename($filePath),
                'city' => $city,
                'state' => $state,
                'country' => $country,
            ]);
        }

        return $result;
    }

    /**
     * Write all available metadata for a file
     */
    public function writeAll(int $fileId, string $filePath): array
    {
        $results = [
            'file_id' => $fileId,
            'date' => null,
            'faces' => null,
            'tags' => null,
            'location' => null,
            'success' => true,
        ];

        if (! $this->metadataWritebackEnabled()) {
            return array_merge($results, $this->metadataWritebackDisabledResult());
        }

        if (! $this->canCopyBackToFile($filePath)) {
            return $this->permissionDeniedResult($filePath);
        }

        // Get file registry data
        $file = DB::selectOne('
            SELECT date_taken, date_taken_precision, date_taken_source, date_taken_confidence, is_scan,
                   ai_description, ai_document_type, gps_location
            FROM file_registry
            WHERE id = ?
        ', [$fileId]);

        if (! $file) {
            return ['success' => false, 'error' => 'File not found in registry'];
        }

        // Write date if available and not from EXIF (avoid overwriting original)
        if (
            $file->date_taken
            && ($file->date_taken_precision ?? 'exact') === 'exact'
            && empty($file->is_scan)
            && $file->date_taken_source
            && ! str_starts_with($file->date_taken_source, 'exif_')
            && (float) ($file->date_taken_confidence ?? 0) >= 0.3
        ) {
            $results['date'] = $this->writeDate(
                $filePath,
                $file->date_taken,
                $file->date_taken_source,
                $file->date_taken_confidence ?? 0.5
            );
            if (! $results['date']['success']) {
                $results['success'] = false;
            }
        }

        // Get and write only trusted, visible, operator-confirmed face labels.
        $faces = $this->facesForWriteback($fileId);
        $faceRowCount = (int) (DB::selectOne(
            'SELECT COUNT(*) AS count FROM file_registry_faces WHERE file_registry_id = ? AND source_current = 1 AND superseded_at IS NULL',
            [$fileId]
        )->count ?? 0);

        if ($faceRowCount > 0) {
            $results['faces'] = $this->writeFaces($filePath, $faces);
            if (! $results['faces']['success']) {
                $results['success'] = false;
            }
        }

        // Write AI tags if available
        $tags = [];
        if ($file->ai_document_type) {
            $tags[] = 'AI:'.$file->ai_document_type;
        }

        // Get auto-tags (table may not exist yet)
        try {
            $autoTags = DB::select("
                SELECT tag
                FROM file_registry_tags
                WHERE file_registry_id = ? AND source = 'ai'
            ", [$fileId]);

            foreach ($autoTags as $tag) {
                $tags[] = $tag->tag;
            }
        } catch (\Exception $e) {
            Log::debug('ExifWritebackService: file_registry_tags query failed', ['error' => $e->getMessage()]);
        }

        if (! empty($tags) || $file->ai_description) {
            $results['tags'] = $this->writeTags($filePath, $tags, $file->ai_description);
            if (! $results['tags']['success']) {
                $results['success'] = false;
            }
        }

        // Write GPS-derived location to IPTC fields (blank fields only)
        if (! empty($file->gps_location)) {
            $results['location'] = $this->writeLocation($filePath, $file->gps_location);
            DB::update(
                'UPDATE file_registry SET exif_location_written = ? WHERE id = ?',
                [$results['location']['success'] ? 1 : -1, $fileId]
            );
            if (! $results['location']['success']) {
                $results['success'] = false;
            }
        }

        return $results;
    }

    /**
     * Get stats on files needing writeback
     */
    public function getStats(): array
    {
        // Files with dates extracted (not from EXIF) that haven't been written back
        // Uses exif_written column (existing) - values: 0=pending, 1=written, -1=error
        $datesPending = DB::selectOne("
            SELECT COUNT(*) as count
            FROM file_registry
            WHERE date_taken IS NOT NULL
            AND COALESCE(date_taken_precision, 'exact') = 'exact'
            AND COALESCE(is_scan, 0) = 0
            AND date_taken_source NOT LIKE 'exif_%'
            AND (exif_written IS NULL OR exif_written = 0)
            AND extension IN ('jpg', 'jpeg', 'png', 'tiff', 'webp', 'heic')
            AND date_taken_confidence >= 0.3
        ")->count ?? 0;

        // Files with faces that haven't been written
        $facesPending = DB::selectOne("
            SELECT COUNT(DISTINCT fr.id) as count
            FROM file_registry fr
            WHERE (fr.exif_faces_written IS NULL OR fr.exif_faces_written = 0)
            AND fr.extension IN ('jpg', 'jpeg', 'png', 'tiff', 'webp', 'heic')
            AND {$this->faceWritebackExistsSql()}
        ")->count ?? 0;

        // Files with AI tags that haven't been written
        // Note: file_registry_tags may not exist yet - handle gracefully
        try {
            $tagsPending = DB::selectOne("
                SELECT COUNT(DISTINCT fr.id) as count
                FROM file_registry fr
                INNER JOIN file_registry_tags ft ON ft.file_registry_id = fr.id
                WHERE ft.source = 'ai'
                AND (fr.exif_tags_written IS NULL OR fr.exif_tags_written = 0)
                AND fr.extension IN ('jpg', 'jpeg', 'png', 'tiff', 'webp', 'heic')
            ")->count ?? 0;
        } catch (\Exception $e) {
            Log::debug('ExifWritebackService: tags pending count failed', ['error' => $e->getMessage()]);
            $tagsPending = 0;
        }

        return [
            'dates_pending' => $datesPending,
            'faces_pending' => $facesPending,
            'tags_pending' => $tagsPending,
            'total_pending' => max($datesPending, $facesPending, $tagsPending),
        ];
    }

    /**
     * Format date for EXIF standard
     */
    private function formatDateForExif(string $date): ?string
    {
        try {
            $dt = new \DateTime($date);

            return $dt->format('Y:m:d H:i:s');
        } catch (\Exception $e) {
            Log::debug('ExifWritebackService: EXIF date formatting failed', ['date' => $date, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get image dimensions using exiftool
     */
    private function getImageDimensions(string $filePath): ?array
    {
        $result = Process::timeout(30)->run([
            $this->exiftoolPath,
            '-ImageWidth',
            '-ImageHeight',
            '-s',
            '-s',
            '-s',
            $filePath,
        ]);
        $output = preg_split('/\r?\n/', trim($result->output())) ?: [];
        $returnCode = $result->exitCode();

        if ($returnCode !== 0 || count($output) < 2) {
            return null;
        }

        return [
            'width' => (int) $output[0],
            'height' => (int) $output[1],
        ];
    }

    private function trustedFaceStatusSql(): string
    {
        return "'".implode("','", self::TRUSTED_FACE_PROVENANCE_STATUSES)."'";
    }

    private function safeSqlAlias(string $alias): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new \InvalidArgumentException('Invalid SQL alias.');
        }

        return $alias;
    }

    /**
     * Build MWG region metadata for faces
     */
    private function buildMwgRegions(array $faces, array $dimensions, array &$personNames): ?string
    {
        $regions = [];

        foreach ($faces as $face) {
            $name = $face->person_name ?? $face->person ?? 'Unknown';
            if ($name && $name !== 'Unknown' && $name !== '') {
                $personNames[] = $name;
            }

            // Convert normalized coordinates (0-1) to MWG format
            // MWG uses center point + width/height, all normalized
            $x = $face->region_x ?? 0;
            $y = $face->region_y ?? 0;
            $w = $face->region_w ?? 0;
            $h = $face->region_h ?? 0;

            if ($w > 0 && $h > 0) {
                $regions[] = [
                    'Area' => [
                        'X' => $x + ($w / 2), // Center X
                        'Y' => $y + ($h / 2), // Center Y
                        'W' => $w,
                        'H' => $h,
                        'Unit' => 'normalized',
                    ],
                    'Name' => $name ?: 'Unknown',
                    'Type' => 'Face',
                ];
            }
        }

        if (empty($regions)) {
            return null;
        }

        // Build the full MWG structure
        $mwgData = [
            [
                'SourceFile' => '*',
                'RegionInfo' => [
                    'AppliedToDimensions' => [
                        'W' => $dimensions['width'],
                        'H' => $dimensions['height'],
                        'Unit' => 'pixel',
                    ],
                    'RegionList' => $regions,
                ],
            ],
        ];

        return json_encode($mwgData);
    }

    /**
     * Run exiftool command
     *
     * Uses temp copy approach to handle permission issues:
     * 1. Copy file to /tmp
     * 2. Run exiftool on temp copy
     * 3. Copy modified file back using sudo
     */
    /** Reuse the verified staged writer for structured face metadata too. */
    public function writeVerifiedMetadata(string $filePath, array $args, bool $operatorApproved = false, ?string $expectedSourceHash = null, ?array $expectedFaceMetadata = null, ?callable $beforeFaceMetadataCopy = null): array
    {
        return $this->runExiftool($filePath, $args, $operatorApproved, $expectedSourceHash, $expectedFaceMetadata, $beforeFaceMetadataCopy);
    }

    private function runExiftool(string $filePath, array $args, bool $operatorApproved = false, ?string $expectedSourceHash = null, ?array $expectedFaceMetadata = null, ?callable $beforeFaceMetadataCopy = null, ?array $expectedDateMetadata = null): array
    {
        if (! $operatorApproved && ! $this->metadataWritebackEnabled()) {
            return $this->metadataWritebackDisabledResult();
        }
        $root = realpath((string) config('services.nextcloud.data_path'));
        $path = realpath($filePath);
        if (! $root || ! $path || ! str_starts_with($path, rtrim($root, '/').'/')) {
            return ['success' => false, 'error' => 'Metadata source is outside the configured Nextcloud root'];
        }
        $tempPath = tempnam(sys_get_temp_dir(), 'exif_verified_');
        $backup = [];
        $transitionPath = null;
        $replaced = false;
        $mysql = null;
        $pg = null;
        $mysqlOpen = $pgOpen = false;
        $pgCommitted = false;
        $failureStage = 'source_preflight';
        try {
            $sourceHash = hash_file('sha256', $path);
            if ($expectedSourceHash !== null && ! hash_equals($expectedSourceHash, $sourceHash)) {
                throw new \RuntimeException('Authorized source bytes changed before metadata preparation');
            }
            if (isset(self::$faceWriteSourceGuards[$path]) && self::$faceWriteSourceGuards[$path] !== $sourceHash) {
                throw new \RuntimeException('Approved face source changed before metadata preparation');
            }
            $sourceSize = filesize($path);
            if (! copy($path, $tempPath) || hash_file('sha256', $tempPath) !== $sourceHash) {
                throw new \RuntimeException('Failed to create verified temp copy');
            }
            // Enforce existing image/resource guards before invoking ExifTool.
            $before = $this->metadataImageProof($tempPath);
            $sourceDateTags = $this->readExactCaptureDateTags($tempPath);
            if ($expectedDateMetadata !== null) {
                if (is_array($expectedDateMetadata['changes'] ?? null)) {
                    if (($sourceDateTags['XMP-dc:Source'] ?? null) === 'plos:operator-confirmed-date-only') {
                        throw new \RuntimeException('Date-only capture metadata requires the dedicated correction workflow');
                    }
                    $expectedDateMetadata = $this->captureDateMetadataProof($sourceDateTags, $expectedDateMetadata['changes']);
                }
                if (! is_array($expectedDateMetadata['before_tags'] ?? null)
                    || ! is_array($expectedDateMetadata['after_tags'] ?? null)
                    || ! is_string($expectedDateMetadata['before_sha256'] ?? null)
                    || ! is_string($expectedDateMetadata['after_sha256'] ?? null)) {
                    throw new \RuntimeException('Exact date metadata proof is malformed');
                }
                if ($sourceDateTags !== $expectedDateMetadata['before_tags']
                    || ! hash_equals($expectedDateMetadata['before_sha256'], $this->exactCaptureDateDigest($sourceDateTags))) {
                    throw new \RuntimeException('Date metadata changed before verified preparation');
                }
            }
            $failureStage = 'metadata_preparation';
            $result = Process::timeout(60)->run(array_merge([$this->exiftoolPath], $args, [$tempPath]));
            if (! $result->successful()) {
                throw new \RuntimeException('Metadata preparation failed: '.trim($result->errorOutput()));
            }
            if ($expectedDateMetadata !== null) {
                $preparedDateTags = $this->readExactCaptureDateTags($tempPath);
                if ($preparedDateTags !== $expectedDateMetadata['after_tags']
                    || ! hash_equals($expectedDateMetadata['after_sha256'], $this->exactCaptureDateDigest($preparedDateTags))) {
                    throw new \RuntimeException('Exact date metadata did not round-trip on the prepared file');
                }
            } else {
                $failureStage = 'temporary_date_tag_preservation';
                $preparedDateTags = $this->readExactCaptureDateTags($tempPath);
                if ($preparedDateTags !== $sourceDateTags
                    || ! hash_equals($this->exactCaptureDateDigest($sourceDateTags), $this->exactCaptureDateDigest($preparedDateTags))) {
                    throw new \RuntimeException('Non-date metadata preparation changed capture-date tags');
                }
            }
            if ($expectedFaceMetadata !== null) {
                $failureStage = 'temporary_face_metadata_roundtrip';
                $metadata = Process::timeout(30)->run([$this->exiftoolPath, '-json', '-struct', '-XMP-mwg-rs:RegionInfo', $tempPath]);
                $records = $metadata->successful() ? json_decode($metadata->output(), true, flags: JSON_THROW_ON_ERROR) : null;
                $actual = $records[0]['RegionInfo'] ?? null;
                $faces = app(FaceRegionService::class);
                if (! is_array($records) || count($records) !== 1 || ($records[0]['SourceFile'] ?? null) !== $tempPath
                    || isset($records[0]['Error']) || isset($records[0]['Warning']) || ! is_array($actual)
                    || ! hash_equals($faces->faceMetadataDigest($expectedFaceMetadata), $faces->faceMetadataDigest($actual))) {
                    throw new \RuntimeException('Reviewed face metadata did not round-trip exactly; source write held');
                }
            }
            $failureStage = 'prepared_file_verification';
            $preparedHash = hash_file('sha256', $tempPath);
            $after = $this->metadataImageProof($tempPath);
            if (hash_file('sha256', $tempPath) !== $preparedHash) {
                throw new \RuntimeException('Prepared file changed during image verification');
            }
            if ($before !== $after) {
                throw new \RuntimeException('Metadata write changed pixels, dimensions, orientation or color profile');
            }
            if (hash_file('sha256', $path) !== $sourceHash) {
                throw new \RuntimeException('Source file changed during metadata preparation');
            }
            if ($expectedSourceHash !== null && ! hash_equals($expectedSourceHash, hash_file('sha256', $path))) {
                throw new \RuntimeException('Authorized source bytes changed during metadata preparation');
            }
            try {
                $backup = $this->backupOriginalFile($path, $sourceHash, $sourceSize);
                $backup['backup_receipt_path'] = $backup['receipt_path'];
                foreach ([$backup['backup_path'], $backup['receipt_path'], dirname($backup['receipt_path'])] as $relativeArtifact) {
                    $artifact = fopen(storage_path($relativeArtifact), 'r');
                    if (! $artifact || ! fsync($artifact)) {
                        if ($artifact) {
                            fclose($artifact);
                        }
                        throw new \RuntimeException('Private original snapshot could not be synchronized');
                    }
                    fclose($artifact);
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException('Verified private original backup failed', previous: $e);
            }
            if (hash_file('sha256', $path) !== $sourceHash) {
                throw new \RuntimeException('Source file changed after backup; metadata write cancelled');
            }
            if ($expectedSourceHash !== null && ! hash_equals($expectedSourceHash, hash_file('sha256', $path))) {
                throw new \RuntimeException('Authorized source bytes changed after backup; metadata write cancelled');
            }
            if ($preparedHash === $sourceHash) {
                return array_merge(['success' => true, 'unchanged' => true, 'backup_sha256' => $sourceHash,
                    'written_sha256' => $sourceHash, 'faces_advanced' => 0, 'vectors_advanced' => 0,
                    'date_metadata' => $expectedDateMetadata === null ? null : [
                        'after_tags_sha256' => $expectedDateMetadata['after_sha256'],
                        'after_tags' => $expectedDateMetadata['after_tags'],
                    ]], $backup);
            }
            $mysql = DB::connection();
            $mysql->beginTransaction();
            $mysqlOpen = true;
            if ($beforeFaceMetadataCopy !== null) {
                $beforeFaceMetadataCopy($mysql);
            }
            $relative = '/'.ltrim(substr($path, strlen(rtrim($root, '/'))), '/');
            $files = $mysql->select("SELECT * FROM file_registry WHERE current_path IN (?, ?) AND status = 'active' FOR UPDATE", [$path, $relative]);
            if (count($files) > 1) {
                $file = app(FileRegistryService::class)->canonicalExactSourceIndexAlias($files, $relative, $sourceHash);
            } else {
                $file = $files[0] ?? null;
            }
            $faces = $vectors = $queues = [];
            if ($file) {
                $faces = $mysql->select("SELECT * FROM file_registry_faces WHERE file_registry_id = ? AND source_current = 1 AND superseded_at IS NULL AND source_sha256 = ? AND face_region_convention = 'normalized_top_left' FOR UPDATE", [$file->id, $sourceHash]);
                $pg = DB::connection('pgsql_rag');
                $pg->beginTransaction();
                $pgOpen = true;
                // Only vectors linked to these exact detector rows/regions may advance.
                foreach ($faces as $face) {
                    $candidates = $pg->select("SELECT * FROM face_embeddings WHERE file_registry_id = ? AND file_registry_face_id = ? AND source_current = TRUE AND superseded_at IS NULL AND source_sha256 = ? AND face_region_convention = 'normalized_top_left' FOR UPDATE", [$file->id, $face->id, $sourceHash]);
                    if (count($candidates) > 1) {
                        throw new \RuntimeException('Multiple current vectors are linked to one face');
                    }
                    if ($candidates !== []) {
                        if (! FaceEmbeddingService::faceRegionsMatchStoragePrecision($face, $candidates[0])) {
                            throw new \RuntimeException('Face vector geometry differs beyond storage rounding bound');
                        }
                        $vectors[] = $candidates[0];
                    }
                }
                $queues = $mysql->select('SELECT q.* FROM genealogy_face_match_queue q WHERE q.file_registry_face_id IN (SELECT id FROM file_registry_faces WHERE file_registry_id = ?) OR q.media_id IN (SELECT id FROM genealogy_media WHERE nextcloud_path = ? OR original_path = ?) FOR UPDATE', [$file->id, $file->current_path, $file->current_path]);
            }
            // A malformed decision history cannot be safely extended. Hold before
            // replacing source bytes, even when its pixels would remain identical.
            foreach ($queues as $queue) {
                $details = json_decode($queue->match_details ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($details)
                    || (isset($details['operator_confirmations']) && (! is_array($details['operator_confirmations']) || ! array_is_list($details['operator_confirmations'])))
                    || (isset($details['metadata_source_transitions']) && ! is_array($details['metadata_source_transitions']))) {
                    throw new \RuntimeException('Decision receipt is malformed');
                }
            }
            $transition = [
                'kind' => 'verified_metadata_only', 'source_path' => $path,
                'before_sha256' => $sourceHash, 'after_sha256' => $preparedHash,
                'image_proof' => $before, 'recorded_at' => now()->utc()->toIso8601String(),
                'backup' => $backup, 'registry_before' => $file, 'faces_before' => $faces,
                'vectors_before' => $vectors, 'queue_before' => $queues,
                'approval_scope' => 'Inherited existing decisions; no new identity approval',
            ];
            if ($expectedDateMetadata !== null) {
                $transition['date_metadata'] = [
                    'before_tags' => $expectedDateMetadata['before_tags'],
                    'before_tags_sha256' => $expectedDateMetadata['before_sha256'],
                    'after_tags' => $expectedDateMetadata['after_tags'],
                    'after_tags_sha256' => $expectedDateMetadata['after_sha256'],
                ];
            }
            $transitionPath = $this->storeMetadataTransition($backup, $transition);
            if (hash_file('sha256', $path) !== $sourceHash || hash_file('sha256', $tempPath) !== $preparedHash) {
                throw new \RuntimeException('Source or prepared file changed before copy-back');
            }
            if ($expectedSourceHash !== null && ! hash_equals($expectedSourceHash, hash_file('sha256', $path))) {
                throw new \RuntimeException('Authorized source bytes changed before metadata copy-back');
            }
            if ($beforeFaceMetadataCopy !== null) {
                $beforeFaceMetadataCopy($mysql);
            }
            $failureStage = 'source_copyback';
            if (! @copy($tempPath, $path)) {
                $copy = Process::timeout(30)->run(['sudo', '-n', 'cp', $tempPath, $path]);
                if (! $copy->successful()) {
                    $replaced = hash_file('sha256', $path) !== $sourceHash;
                    throw new \RuntimeException('Metadata copy-back failed');
                }
            }
            $replaced = true;
            clearstatcache(true, $path);
            $failureStage = 'source_provenance_commit';
            if (hash_file('sha256', $path) !== $preparedHash) {
                throw new \RuntimeException('Metadata copy-back could not be verified');
            }
            if ($expectedDateMetadata !== null) {
                $writtenDateTags = $this->readExactCaptureDateTags($path);
                if ($writtenDateTags !== $expectedDateMetadata['after_tags']
                    || ! hash_equals($expectedDateMetadata['after_sha256'], $this->exactCaptureDateDigest($writtenDateTags))) {
                    throw new \RuntimeException('Exact date metadata did not round-trip on the copied source');
                }
            }
            if ($file) {
                foreach ($faces as $face) {
                    $changed = $mysql->update('UPDATE file_registry_faces SET source_sha256 = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND file_registry_id = ? AND source_sha256 = ? AND source_current = 1 AND superseded_at IS NULL', [$preparedHash, $face->id, $file->id, $sourceHash]);
                    if ($changed !== 1 && $preparedHash !== $sourceHash) {
                        throw new \RuntimeException('Face source changed during metadata transition');
                    }
                    $this->recordGlobalFaceMetadataTransition($mysql, $face, $file, $sourceHash, $preparedHash, $transitionPath, $before);
                }
                foreach ($vectors as $vector) {
                    if ($pg->update('UPDATE face_embeddings SET source_sha256 = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND file_registry_face_id = ? AND source_sha256 = ? AND source_current = TRUE AND superseded_at IS NULL', [$preparedHash, $vector->id, $vector->file_registry_face_id, $sourceHash]) !== 1) {
                        throw new \RuntimeException('Vector source changed during metadata transition');
                    }
                }
                foreach ($queues as $queue) {
                    $details = json_decode($queue->match_details ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($details)) {
                        throw new \RuntimeException('Decision receipt is malformed');
                    }
                    $original = $details;
                    $inherited = [];
                    foreach ($details['operator_confirmations'] ?? [] as $receipt) {
                        if (! is_array($receipt) || ($receipt['source_sha256'] ?? null) !== $sourceHash
                            || ($receipt['source_path'] ?? null) !== $file->current_path
                            || ($receipt['face_region_convention'] ?? null) !== 'normalized_top_left'
                            || ($receipt['source_dimensions'] ?? null) !== [$before['width'], $before['height']]) {
                            continue;
                        }
                        $receiptFace = null;
                        foreach ($faces as $candidate) {
                            if ((int) ($receipt['file_registry_face_id'] ?? 0) === (int) $candidate->id) {
                                $receiptFace = $candidate;
                                break;
                            }
                        }
                        $matches = $receiptFace !== null;
                        foreach (['x', 'y', 'w', 'h'] as $axis) {
                            $matches = $matches && is_numeric($receipt['region'][$axis] ?? null)
                                && abs((float) $receipt['region'][$axis] - (float) $receiptFace->{'region_'.$axis}) <= (float) config('genealogy.face_region_match_tolerance', 0.00015);
                        }
                        if (! $matches) {
                            continue;
                        }
                        // Old receipts remain immutable. Append a clearly derived proof, not a new decision.
                        $receipt['source_sha256'] = $preparedHash;
                        $receipt['metadata_transition_receipt'] = $transitionPath;
                        $receipt['inherited_source_sha256'] = $sourceHash;
                        $receipt['proof_scope'] = 'verified_metadata_only; original operator decision unchanged';
                        $inherited[] = $receipt;
                    }
                    if ($inherited !== []) {
                        $details['operator_confirmations'] = array_merge($details['operator_confirmations'], $inherited);
                    }
                    $boundFace = null;
                    foreach ($faces as $candidate) {
                        if ((int) ($queue->file_registry_face_id ?? 0) === (int) $candidate->id) {
                            $boundFace = $candidate;
                            break;
                        }
                    }
                    $queueRegion = json_decode($queue->face_region ?? '{}', true);
                    $sameRegion = $boundFace !== null && is_array($queueRegion);
                    foreach (['x', 'y', 'w', 'h'] as $axis) {
                        $sameRegion = $sameRegion && is_numeric($queueRegion[$axis] ?? null)
                            && abs((float) $queueRegion[$axis] - (float) $boundFace->{'region_'.$axis}) <= (float) config('genealogy.face_region_match_tolerance', 0.00015);
                    }
                    if ($sameRegion && ($details['source_sha256'] ?? null) === $sourceHash
                        && ($details['source_path'] ?? null) === $file->current_path
                        && ($details['face_region_convention'] ?? null) === 'normalized_top_left') {
                        $details['source_sha256'] = $preparedHash;
                    }
                    if ($details !== $original) {
                        $details['metadata_source_transitions'][] = [
                            'before_sha256' => $sourceHash, 'after_sha256' => $preparedHash,
                            'receipt' => $transitionPath, 'identity_decision_changed' => false,
                        ];
                        $lockedQueue = $mysql->select('SELECT match_details FROM genealogy_face_match_queue WHERE id = ? FOR UPDATE', [$queue->id]);
                        if (! isset($lockedQueue[0]) || $lockedQueue[0]->match_details !== $queue->match_details) {
                            throw new \RuntimeException('Operator decision changed during metadata transition');
                        }
                        if ($mysql->update('UPDATE genealogy_face_match_queue SET match_details = ? WHERE id = ?', [json_encode($details, JSON_THROW_ON_ERROR), $queue->id]) !== 1) {
                            throw new \RuntimeException('Operator decision changed during metadata transition');
                        }
                    }
                }
                $mysql->update('UPDATE file_registry SET content_hash = ?, content_hash_verified_at = CURRENT_TIMESTAMP, file_size = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND current_path = ?', [$preparedHash, filesize($path), $file->id, $file->current_path]);
            }
            if (hash_file('sha256', $path) !== $preparedHash) {
                throw new \RuntimeException('Source changed before metadata provenance commit');
            }
            if ($pgOpen) {
                $pg->commit();
                $pgOpen = false;
                $pgCommitted = true;
            }
            $mysql->commit();
            $mysqlOpen = false;

            return array_merge(['success' => true, 'backup_sha256' => $sourceHash, 'written_sha256' => $preparedHash,
                'metadata_transition_receipt' => $transitionPath, 'faces_advanced' => count($faces), 'vectors_advanced' => count($vectors),
                'date_metadata' => $expectedDateMetadata === null ? null : [
                    'before_tags_sha256' => $expectedDateMetadata['before_sha256'],
                    'after_tags_sha256' => $expectedDateMetadata['after_sha256'],
                    'after_tags' => $expectedDateMetadata['after_tags'],
                ]], $backup);
        } catch (\Throwable $e) {
            if ($pgOpen) {
                $pg->rollBack();
            }
            if ($mysqlOpen) {
                $mysql->rollBack();
            }

            $failureCode = $e->getMessage() === 'Reviewed face metadata did not round-trip exactly; source write held'
                ? 'reviewed_face_metadata_roundtrip_mismatch'
                : ($e->getMessage() === 'Date-only capture metadata requires the dedicated correction workflow'
                    ? 'date_only_capture_metadata_protected'
                    : ($e->getMessage() === 'Non-date metadata preparation changed capture-date tags'
                    ? 'capture_date_tags_changed'
                    : ($e->getMessage() === 'Operator decision changed during metadata transition'
                    ? 'decision_preimage_changed'
                    : ($replaced ? 'metadata_transition_partial_after_copyback' : 'metadata_transition_failed_before_copyback'))));
            $failure = ['success' => false, 'error' => $e->getMessage(),
                'failure_code' => $failureCode, 'failure_stage' => $failureStage,
                'source_replaced' => $replaced,
                'repair_required' => $replaced, 'pg_committed' => $pgCommitted,
                'metadata_transition_receipt' => $transitionPath];
            if ($e->getMessage() === 'Metadata copy-back failed') {
                $failure = array_merge($this->permissionDeniedResult($path), $failure);
            }

            return array_merge($failure, $backup);
        } finally {
            if (is_string($tempPath)) {
                $this->cleanupStaleExiftoolTemps($tempPath);
                @unlink($tempPath);
            }
        }
    }

    /** Single-frame JPEG/PNG only. GD raw stored RGBA + orientation + ICC prevent display changes. */
    /** Restore only physical bytes after an interrupted transition; provenance stays untouched. */
    public function restoreMetadataPreimage(string $transitionPath, string $packetSha256, string $afterSha256, string $beforeSha256, string $originalReceiptId, ?string $expectedStateSha256 = null, bool $dryRun = true): array
    {
        $mysql = DB::connection();
        $pg = DB::connection('pgsql_rag');
        $lock = null;
        $temporary = null;
        $replaced = false;
        $journal = null;
        $mysqlOpen = false;
        $pgOpen = false;
        try {
            if ($mysql->transactionLevel() !== 0 || $pg->transactionLevel() !== 0) {
                throw new \RuntimeException('Recovery requires independent database transactions');
            }
            foreach ([$packetSha256, $afterSha256, $beforeSha256] as $digest) {
                if (! preg_match('/^[a-f0-9]{64}$/D', $digest)) {
                    throw new \RuntimeException('Exact recovery SHA256 selectors are required');
                }
            }
            if (! Str::isUuid($originalReceiptId)) {
                throw new \RuntimeException('Original unknown receipt selector is required');
            }
            $privateRoot = realpath(storage_path('app/private'));
            $packet = realpath(storage_path($transitionPath));
            if (! $privateRoot || ! $packet || is_link(storage_path($transitionPath))
                || ! str_starts_with($packet, $privateRoot.'/') || hash_file('sha256', $packet) !== $packetSha256) {
                throw new \RuntimeException('Private transition packet changed or escaped private storage');
            }
            $transition = json_decode(file_get_contents($packet), true, flags: JSON_THROW_ON_ERROR);
            if (($transition['kind'] ?? null) !== 'verified_metadata_only'
                || ($transition['before_sha256'] ?? null) !== $beforeSha256 || ($transition['after_sha256'] ?? null) !== $afterSha256
                || $beforeSha256 === $afterSha256 || ! is_array($transition['registry_before'] ?? null)) {
                throw new \RuntimeException('Transition selectors do not match');
            }
            $path = $transition['source_path'];
            if (! $this->exactCaptureDatePathAllowed($path) || realpath($path) !== $path) {
                throw new \RuntimeException('Recovery source path is unsafe');
            }
            $backup = realpath(storage_path($transition['backup']['backup_path'] ?? ''));
            if (! $backup || ! str_starts_with($backup, $privateRoot.'/')
                || is_link(storage_path($transition['backup']['backup_path'])) || hash_file('sha256', $backup) !== $beforeSha256) {
                throw new \RuntimeException('Original private backup does not verify');
            }
            $lock = fopen($path, 'rb');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Recovery source is busy');
            }
            $stat = fstat($lock);
            $assertSource = function () use ($path, $stat, $afterSha256, $packet, $packetSha256): void {
                clearstatcache(true, $path);
                $current = lstat($path);
                if (! $current || $current['ino'] !== $stat['ino'] || $current['dev'] !== $stat['dev']
                    || is_link($path) || hash_file('sha256', $path) !== $afterSha256
                    || hash_file('sha256', $packet) !== $packetSha256) {
                    throw new \RuntimeException('Recovery source or transition changed');
                }
            };
            $assertSource();
            if ($this->metadataImageProof($path) !== $transition['image_proof']
                || $this->metadataImageProof($backup) !== $transition['image_proof']) {
                throw new \RuntimeException('Recovery image proof differs');
            }
            $mysql->beginTransaction();
            $mysqlOpen = true;
            $pg->beginTransaction();
            $pgOpen = true;
            $registry = $transition['registry_before'];
            $rows = $mysql->select('SELECT * FROM file_registry WHERE id = ? FOR UPDATE', [$registry['id']]);
            $this->assertRecoveryRows($rows, [$registry]);
            $faces = $mysql->select('SELECT * FROM file_registry_faces WHERE file_registry_id = ? AND source_current = 1 AND superseded_at IS NULL FOR UPDATE', [$registry['id']]);
            $this->assertRecoveryRows($faces, $transition['faces_before']);
            $vectors = $pg->select('SELECT * FROM face_embeddings WHERE file_registry_id = ? AND source_current = TRUE AND superseded_at IS NULL FOR UPDATE', [$registry['id']]);
            $this->assertRecoveryRows($vectors, $transition['vectors_before']);
            $queues = $mysql->select('SELECT q.* FROM genealogy_face_match_queue q WHERE q.file_registry_face_id IN (SELECT id FROM file_registry_faces WHERE file_registry_id = ?) OR q.media_id IN (SELECT id FROM genealogy_media WHERE nextcloud_path = ? OR original_path = ?) FOR UPDATE', [$registry['id'], $registry['current_path'], $registry['current_path']]);
            $this->assertRecoveryRows($queues, $transition['queue_before']);
            $receiptRows = $mysql->select('SELECT * FROM review_desk_receipts WHERE id = ? FOR UPDATE', [$originalReceiptId]);
            $receipt = $receiptRows[0] ?? null;
            $payload = $receipt ? json_decode($receipt->payload, true, flags: JSON_THROW_ON_ERROR) : null;
            if (! $receipt || $receipt->state !== 'unknown' || $receipt->reference !== $registry['asset_uuid']
                || ($payload['writer_result']['metadata_transition_receipt'] ?? null) !== $transitionPath) {
                throw new \RuntimeException('Original unknown receipt does not match transition');
            }
            $approvals = [];
            foreach ($faces as $face) {
                if (! empty($face->global_identity_approval_receipt_id)) {
                    $approval = $mysql->select('SELECT * FROM review_desk_receipts WHERE id = ? FOR UPDATE', [$face->global_identity_approval_receipt_id]);
                    if (count($approval) !== 1 || $approval[0]->state !== 'completed') {
                        throw new \RuntimeException('Identity approval is unavailable');
                    }
                    $approvals[] = (array) $approval[0];
                }
            }
            $stateHash = hash('sha256', json_encode(['receipt' => (array) $receipt, 'approvals' => $approvals], JSON_THROW_ON_ERROR));
            if ($dryRun) {
                return ['success' => true, 'dry_run' => true, 'state_sha256' => $stateHash, 'source_replaced' => false];
            }
            $this->requireFaceMetadataRepairAuthority(Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT));
            if ($expectedStateSha256 !== $stateHash) {
                throw new \RuntimeException('Receipt or identity approval changed after recovery preview');
            }
            // Retain the corrected bytes before restoring; this uses the existing durable backup verifier.
            $corrected = $this->backupOriginalFile($path, $afterSha256, filesize($path));
            $record = ['kind' => 'metadata_preimage_reconciliation', 'asset_uuid' => $registry['asset_uuid'], 'original_receipt_id' => $originalReceiptId,
                'transition_path' => $transitionPath, 'transition_sha256' => $packetSha256,
                'before_sha256' => $beforeSha256, 'after_sha256' => $afterSha256,
                'state_sha256' => $stateHash, 'corrected_backup' => $corrected, 'effect_status' => 'physical_outcome_unknown',
                'identity_approval' => false, 'recorded_at' => now()->utc()->toIso8601String()];
            foreach (['backup_path', 'receipt_path'] as $artifactKey) {
                $artifact = fopen(storage_path($corrected[$artifactKey]), 'r');
                if (! $artifact || ! fsync($artifact)) {
                    throw new \RuntimeException('Corrected private backup could not be synchronized');
                }
                fclose($artifact);
            }
            $journal = $this->storeMetadataTransition($corrected, $record);
            $temporary = tempnam(dirname($path), '.metadata-recovery-');
            if (! $temporary || ! chmod($temporary, $stat['mode'] & 0777) || ! copy($backup, $temporary)
                || hash_file('sha256', $temporary) !== $beforeSha256) {
                throw new \RuntimeException('Recovery staging failed');
            }
            $handle = fopen($temporary, 'rb');
            if (! $handle || ! fsync($handle)) {
                throw new \RuntimeException('Recovery staging could not be synchronized');
            }
            fclose($handle);
            $staged = stat($temporary);
            $assertSource();
            if (hash_file('sha256', $backup) !== $beforeSha256) {
                throw new \RuntimeException('Recovery backup changed');
            }
            if ($staged['uid'] === $stat['uid'] && $staged['gid'] === $stat['gid']) {
                if (! rename($temporary, $path)) {
                    throw new \RuntimeException('Recovery replacement failed');
                }
                $replaced = true;
                $temporary = null;
            } else {
                // Preserve foreign ownership using the locked inode. A partial copy remains unknown.
                $replaced = true;
                if (! copy($temporary, $path)) {
                    throw new \RuntimeException('Recovery same-inode copy failed');
                }
            }
            $restored = fopen($path, 'r');
            if (! $restored || ! fsync($restored)) {
                throw new \RuntimeException('Restored source could not be synchronized');
            }
            fclose($restored);
            $directory = fopen(dirname($path), 'r');
            if (! $directory || ! fsync($directory)) {
                throw new \RuntimeException('Recovery directory could not be synchronized');
            }
            fclose($directory);
            clearstatcache(true, $path);
            $restoredStat = lstat($path);
            if (! $restoredStat || is_link($path) || $restoredStat['uid'] !== $stat['uid'] || $restoredStat['gid'] !== $stat['gid']
                || ($restoredStat['mode'] & 0777) !== ($stat['mode'] & 0777)
                || hash_file('sha256', $path) !== $beforeSha256 || $this->metadataImageProof($path) !== $transition['image_proof']) {
                throw new \RuntimeException('Restored image does not verify');
            }
            // Separate append-only completion record; the intent and original unknown receipt remain intact.
            $record['kind'] = 'metadata_original_restored';
            $record['effect_status'] = 'known_preimage_restored';
            $record['intent_receipt'] = $journal;
            $completed = $this->storeMetadataTransition($corrected, $record);
            $completionId = (string) Str::uuid();
            $completion = $record + ['success' => true, 'identity_decision_changed' => false,
                'restored_sha256' => $beforeSha256, 'restored_image_proof' => $transition['image_proof'],
                'private_completion_path' => $completed, 'private_completion_sha256' => hash_file('sha256', storage_path($completed))];
            $mysql->table('review_desk_receipts')->insert([
                'id' => $completionId, 'reference' => $registry['asset_uuid'], 'idempotency_key' => $completionId,
                'request_digest' => hash('sha256', json_encode($completion, JSON_THROW_ON_ERROR)),
                'action' => 'metadata_original_restored', 'state' => 'completed',
                'payload' => json_encode($completion, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $mysql->commit();
            $mysqlOpen = false;

            return ['success' => true, 'dry_run' => false, 'source_replaced' => true, 'restored_sha256' => $beforeSha256,
                'reconciliation_receipt' => $completed, 'completion_receipt_id' => $completionId, 'original_receipt_preserved' => true, 'identity_approval' => false];
        } catch (\Throwable $e) {
            return ['success' => false, 'dry_run' => $dryRun, 'error' => $e->getMessage(), 'source_replaced' => $replaced,
                'repair_required' => $replaced, 'reconciliation_receipt' => $journal];
        } finally {
            if ($pgOpen) {
                $pg->rollBack();
            }
            if ($mysqlOpen) {
                $mysql->rollBack();
            }
            if ($temporary !== null) {
                @unlink($temporary);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Admission proof only; an unknown receipt is never rewritten or replayed. */
    public function isOriginalRestored(object $unknownReceipt, ?string $expectedHistoricalRestoredSourceHash = null): bool
    {
        return $this->originalRestorationProof($unknownReceipt, $expectedHistoricalRestoredSourceHash) !== null;
    }

    /** Scanner callers defer current pixels only until their final fresh image-proof gate. */
    private function originalRestorationProof(
        object $unknownReceipt,
        ?string $expectedHistoricalRestoredSourceHash = null,
        ?array $receipts = null,
        bool $verifyCurrentImage = true,
        ?array $bounds = null,
        ?int &$bytesRead = null,
    ): ?array {
        try {
            if ($unknownReceipt->state !== 'unknown'
                || ($expectedHistoricalRestoredSourceHash !== null
                    && ! preg_match('/\A[a-f0-9]{64}\z/', $expectedHistoricalRestoredSourceHash))) {
                return null;
            }
            $unknown = json_decode($unknownReceipt->payload, true, flags: JSON_THROW_ON_ERROR);
            $transitionPath = $unknown['writer_result']['metadata_transition_receipt'] ?? null;
            $privateRoot = realpath(storage_path('app/private'));
            $boundPrivate = static function (string $relative, int $maxBytes) use ($privateRoot, $bounds, &$bytesRead): void {
                if ($bounds === null) {
                    return;
                }
                if (! str_starts_with($relative, 'app/private/') || str_contains($relative, "\0")
                    || str_contains($relative, '\\') || array_intersect(['', '.', '..'], explode('/', $relative)) !== []) {
                    throw new \RuntimeException('Restoration proof path invalid');
                }
                $cursor = storage_path();
                foreach (explode('/', $relative) as $segment) {
                    $cursor .= DIRECTORY_SEPARATOR.$segment;
                    if (is_link($cursor)) {
                        throw new \RuntimeException('Restoration proof path invalid');
                    }
                }
                $path = storage_path($relative);
                clearstatcache(true, $path);
                $stat = lstat($path);
                if (! $stat || (($stat['mode'] & 0170000) !== 0100000) || realpath($path) !== $path
                    || ! $privateRoot || ! str_starts_with($path, $privateRoot.'/')
                    || $stat['size'] <= 0 || $stat['size'] > $maxBytes
                    || ($bytesRead += $stat['size']) > (int) $bounds['max_total_bytes']) {
                    throw new \RuntimeException('Restoration proof bounds exceeded');
                }
            };
            $readPrivate = static function (string $relative, string $hash) use ($privateRoot, $bounds, $boundPrivate): array {
                $boundPrivate($relative, (int) ($bounds['max_packet_bytes'] ?? 0));
                $requested = storage_path($relative);
                $path = realpath($requested);
                if (! $privateRoot || ! $path || is_link($requested) || ! str_starts_with($path, $privateRoot.'/')
                    || ! preg_match('/^[a-f0-9]{64}$/D', $hash) || hash_file('sha256', $path) !== $hash) {
                    throw new \RuntimeException('Restoration proof changed');
                }

                $bytes = file_get_contents($path);
                if (! is_string($bytes) || hash('sha256', $bytes) !== $hash) {
                    throw new \RuntimeException('Restoration proof changed');
                }

                return json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            };
            foreach ($receipts ?? DB::table('review_desk_receipts')->where('action', 'metadata_original_restored')
                ->where('reference', $unknownReceipt->reference)->where('state', 'completed')->get()->all() as $receipt) {
                $payload = json_decode($receipt->payload, true, flags: JSON_THROW_ON_ERROR);
                if (($payload['original_receipt_id'] ?? null) !== $unknownReceipt->id) {
                    continue;
                }
                if (hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)) !== $receipt->request_digest
                    || ($payload['success'] ?? null) !== true || ($payload['identity_decision_changed'] ?? null) !== false
                    || ($payload['effect_status'] ?? null) !== 'known_preimage_restored'
                    || ($payload['asset_uuid'] ?? null) !== $unknownReceipt->reference
                    || ($payload['transition_path'] ?? null) !== $transitionPath) {
                    return null;
                }
                $completion = $readPrivate($payload['private_completion_path'], $payload['private_completion_sha256']);
                foreach ($completion as $key => $value) {
                    if (! array_key_exists($key, $payload) || $payload[$key] !== $value) {
                        return null;
                    }
                }
                if (($completion['kind'] ?? null) !== 'metadata_original_restored') {
                    return null;
                }
                $transition = $readPrivate($transitionPath, $payload['transition_sha256']);
                $before = $payload['before_sha256'];
                if (($transition['before_sha256'] ?? null) !== $before
                    || ($transition['after_sha256'] ?? null) !== $payload['after_sha256']
                    || ($payload['restored_sha256'] ?? null) !== $before
                    || ($transition['registry_before']['asset_uuid'] ?? null) !== $unknownReceipt->reference) {
                    return null;
                }
                $registry = DB::table('file_registry')->where('id', $transition['registry_before']['id'])->first();
                $path = $transition['source_path'];
                $boundPrivate($transition['backup']['backup_path'], (int) ($bounds['max_backup_bytes'] ?? 0));
                $backup = realpath(storage_path($transition['backup']['backup_path']));

                $historicalAdmission = $expectedHistoricalRestoredSourceHash !== null;

                $valid = $receipt->state === 'completed' && $receipt->action === 'metadata_original_restored'
                    && $receipt->reference === $unknownReceipt->reference && $registry && $registry->asset_uuid === $unknownReceipt->reference
                    && (! $historicalAdmission || hash_equals($before, $expectedHistoricalRestoredSourceHash))
                    && ($historicalAdmission || $registry->content_hash === $before)
                    && $registry->current_path === $transition['registry_before']['current_path']
                    && $this->exactCaptureDatePathAllowed($path) && realpath($path) === $path
                    && ($historicalAdmission || hash_file('sha256', $path) === $before)
                    && $backup && str_starts_with($backup, $privateRoot.'/')
                    && ! is_link(storage_path($transition['backup']['backup_path'])) && hash_file('sha256', $backup) === $before
                    && (! $verifyCurrentImage || $this->metadataImageProof($path) === $transition['image_proof']);

                return $valid ? ['receipt' => (array) $receipt, 'transition' => $transition,
                    'image_proof' => $transition['image_proof']] : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function assertRecoveryRows(array $actual, array $expected): void
    {
        $index = static function (array $rows): array {
            $result = [];
            foreach ($rows as $row) {
                $row = (array) $row;
                $result[(string) $row['id']] = $row;
            }
            ksort($result);

            return $result;
        };
        if ($index($actual) != $index($expected)) {
            throw new \RuntimeException('Database recovery preimage changed; no physical restore permitted');
        }
    }

    /**
     * Re-run the canonical pixel/display proof for one exact physical image.
     * This is an inspection seam for private receipt audits; it never returns
     * embedded metadata, proof contents, or the selected path.
     */
    public function verifyMetadataImageProofForSource(
        string $path,
        string $expectedSourceSha256,
        array $expectedImageProof,
        ?float $secondsBudget = null,
        bool $boundedReads = false,
    ): array {
        $held = ['success' => false, 'status' => 'held', 'read_only' => true,
            'source_sha256' => null, 'image_proof_sha256' => null, 'reason_code' => 'proof_unavailable'];
        try {
            $configuredSeconds = (float) config('metadata_writeback.max_verification_seconds', 30);
            $secondsBudget = $secondsBudget === null ? $configuredSeconds : min($configuredSeconds, $secondsBudget);
            if ($secondsBudget <= 0) {
                return $held;
            }
            $deadline = microtime(true) + $secondsBudget;
            if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedSourceSha256)
                || strlen(json_encode($expectedImageProof, JSON_THROW_ON_ERROR)) > 16384
                || ! $this->validMetadataImageProofShape($expectedImageProof)) {
                return $held;
            }
            $privateRoot = realpath(storage_path('app/private'));
            $nextcloudRoot = realpath((string) config('services.nextcloud.data_path'));
            $resolved = realpath($path);
            $rootAllowed = ($privateRoot && str_starts_with($path, $privateRoot.DIRECTORY_SEPARATOR)
                    && str_starts_with($resolved ?: '', $privateRoot.DIRECTORY_SEPARATOR))
                || ($nextcloudRoot && str_starts_with($path, $nextcloudRoot.DIRECTORY_SEPARATOR)
                    && str_starts_with($resolved ?: '', $nextcloudRoot.DIRECTORY_SEPARATOR));
            if (! $rootAllowed || ! $resolved || $resolved !== $path || is_link($path)
                || is_link(storage_path('app')) || is_link(storage_path('app/private'))
                || ! is_file($resolved) || ! is_readable($resolved)) {
                return $held;
            }
            clearstatcache(true, $resolved);
            $beforeStat = lstat($resolved);
            $maxBytes = (int) config('metadata_writeback.max_verification_bytes', 67108864);
            if (! $beforeStat || (($beforeStat['mode'] & 0170000) !== 0100000)
                || $beforeStat['size'] <= 0 || $beforeStat['size'] > $maxBytes) {
                return $held;
            }
            $beforeHash = $boundedReads ? $this->metadataProofSourceHash($resolved, $deadline) : hash_file('sha256', $resolved);
            if (! is_string($beforeHash) || ! hash_equals($expectedSourceSha256, $beforeHash)) {
                return $held;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return $held;
            }
            $actualProof = $boundedReads
                ? $this->metadataImageProof($resolved, $remaining, true)
                : $this->metadataImageProof($resolved, $remaining);
            if ($boundedReads && microtime(true) >= $deadline) {
                return $held;
            }
            clearstatcache(true, $resolved);
            $afterStat = lstat($resolved);
            $afterHash = $boundedReads ? $this->metadataProofSourceHash($resolved, $deadline) : hash_file('sha256', $resolved);
            if ($boundedReads && microtime(true) >= $deadline) {
                return $held;
            }
            if (! $afterStat || (($afterStat['mode'] & 0170000) !== 0100000)
                || $afterStat['dev'] !== $beforeStat['dev'] || $afterStat['ino'] !== $beforeStat['ino']
                || $afterStat['size'] !== $beforeStat['size'] || $afterStat['mtime'] !== $beforeStat['mtime']
                || $afterStat['ctime'] !== $beforeStat['ctime'] || ! is_string($afterHash)
                || ! hash_equals($expectedSourceSha256, $afterHash) || $actualProof !== $expectedImageProof) {
                return $held;
            }
            $proofDigest = hash('sha256', json_encode($actualProof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return ['success' => true, 'status' => 'verified', 'read_only' => true,
                'source_sha256' => $afterHash, 'image_proof_sha256' => $proofDigest, 'reason_code' => null];
        } catch (\Throwable) {
            return $held;
        }
    }

    /** Chunked hashing only for the optional bounded producer reader; clock is microtime. */
    private function metadataProofSourceHash(string $path, float $deadline, ?string &$retainedBytes = null): string|false
    {
        $chunkBytes = config('file_registry.discovery.hash_chunk_bytes');
        if (! is_int($chunkBytes) || $chunkBytes < 1 || $chunkBytes > 1048576
            || microtime(true) >= $deadline) {
            return false;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $before = fstat($handle);
            if (! $before || ($before['mode'] & 0170000) !== 0100000 || $before['size'] < 1
                || $before['size'] > min(67108864, (int) config('metadata_writeback.max_verification_bytes', 67108864))) {
                return false;
            }
            $hash = hash_init('sha256');
            $read = 0;
            while ($read < $before['size']) {
                if (microtime(true) >= $deadline) {
                    return false;
                }
                $length = min($chunkBytes, $before['size'] - $read);
                if ($retainedBytes !== null) {
                    $chunk = fread($handle, $length);
                    $chunkRead = is_string($chunk) ? strlen($chunk) : false;
                    if (is_string($chunk)) {
                        hash_update($hash, $chunk);
                        $retainedBytes .= $chunk;
                    }
                } else {
                    $chunkRead = hash_update_stream($hash, $handle, $length);
                }
                if ($chunkRead !== $length) {
                    return false;
                }
                $read += $length;
            }
            $after = fstat($handle);
            clearstatcache(true, $path);
            $current = lstat($path);
            foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $pin) {
                if (($before[$pin] ?? null) !== ($after[$pin] ?? null)
                    || ($before[$pin] ?? null) !== ($current[$pin] ?? null)) {
                    return false;
                }
            }

            return microtime(true) < $deadline ? hash_final($hash) : false;
        } finally {
            fclose($handle);
        }
    }

    private function validMetadataImageProofShape(array $proof): bool
    {
        $expectedKeys = ['method', 'width', 'height', 'image_type', 'pixels_sha256', 'orientation', 'icc_sha256', 'png_content_sha256'];
        $keys = array_keys($proof);
        sort($keys);
        sort($expectedKeys);
        if ($keys !== $expectedKeys || ($proof['method'] ?? null) !== 'gd_decoded_rgba_sha256_v1'
            || ! is_int($proof['width'] ?? null) || $proof['width'] < 1
            || ! is_int($proof['height'] ?? null) || $proof['height'] < 1
            || ! in_array($proof['image_type'] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            || ! is_array($proof['orientation'] ?? null)) {
            return false;
        }
        foreach (['pixels_sha256', 'icc_sha256'] as $hashKey) {
            if (! is_string($proof[$hashKey] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $proof[$hashKey])) {
                return false;
            }
        }

        return $proof['image_type'] === IMAGETYPE_PNG
            ? is_string($proof['png_content_sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $proof['png_content_sha256']) === 1
            : ($proof['png_content_sha256'] ?? null) === null;
    }

    /** Inspect a unique metadata-only source chain for one exact registry asset. */
    public function inspectVerifiedMetadataTransitionChain(
        int $registryId,
        string $assetUuid,
        string $beforeSha256,
        string $afterSha256,
        string $physicalPath,
        ?float $secondsBudget = null,
    ): array {
        $held = ['success' => false, 'status' => 'held', 'read_only' => true,
            'registry_id' => $registryId, 'asset_uuid' => $assetUuid,
            'before_sha256' => $beforeSha256, 'after_sha256' => $afterSha256,
            'receipt_scan_complete' => false, 'matching_transition_count' => 0,
            'unique_path_count' => 0, 'reason_code' => 'transition_provenance_unavailable',
            'transitions' => [], 'current_source_sha256' => null,
            'current_image_proof_status' => 'not_verified', 'current_image_proof_sha256' => null,
            'unknown_receipts_preserved' => true];
        try {
            if ($registryId < 1 || ! Str::isUuid($assetUuid)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $beforeSha256)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $afterSha256) || hash_equals($beforeSha256, $afterSha256)) {
                return $held;
            }
            $registry = DB::table('file_registry')->where('id', $registryId)->where('asset_uuid', $assetUuid)
                ->where('status', 'active')->first();
            $dataRoot = realpath((string) config('services.nextcloud.data_path'));
            $resolvedCurrent = realpath($physicalPath);
            $currentRelative = is_string($registry->current_path ?? null) ? '/'.ltrim($registry->current_path, '/') : null;
            $expectedCurrent = $dataRoot && $currentRelative ? $dataRoot.$currentRelative : null;
            if (! $registry || ! $dataRoot || ! is_string($expectedCurrent) || $resolvedCurrent !== $physicalPath
                || $physicalPath !== $expectedCurrent || ! str_starts_with($physicalPath, $dataRoot.DIRECTORY_SEPARATOR)
                || is_link($physicalPath) || ! is_file($physicalPath)) {
                return $held;
            }
            $bounds = config('file_registry.image_audit.metadata_transition_provenance');
            if (! is_array($bounds) || ($bounds['max_receipts'] ?? 0) < 1 || ($bounds['max_seconds'] ?? 0) < 1) {
                return $held;
            }
            if ($secondsBudget !== null) {
                if (! is_finite($secondsBudget) || $secondsBudget <= 0) {
                    return [...$held, 'reason_code' => 'scan_deadline_exceeded'];
                }
                $bounds['max_seconds'] = min((float) $bounds['max_seconds'], $secondsBudget);
                $bounds['bounded_producer_read'] = true;
            }
            $started = microtime(true);
            $scan = $this->readMetadataTransitionEdges($registryId, $assetUuid, $physicalPath, $dataRoot, $bounds, $started);
            $result = [...$held, 'receipt_scan_complete' => $scan['complete'],
                'matching_transition_count' => $scan['matching_receipts'], 'stage' => $scan['stage'] ?? 'receipt_scan'];
            if (! $scan['complete']) {
                $result['reason_code'] = $scan['reason_code'] ?? 'receipt_scan_incomplete';

                return $result;
            }
            if ($scan['matching_receipts'] === 0) {
                $result['status'] = 'no_evidence';
                $result['reason_code'] = 'no_matching_transition_receipts';

                return $result;
            }
            $graphRemaining = (float) $bounds['max_seconds'] - (microtime(true) - $started);
            if ($graphRemaining <= 0) {
                $result['status'] = 'partial_or_unknown';
                $result['reason_code'] = 'scan_deadline_exceeded';
                $result['stage'] = 'graph_search';

                return $result;
            }
            $graph = $this->metadataTransitionPath(
                $scan['edges'], $beforeSha256, $afterSha256,
                (int) ($bounds['max_graph_edges'] ?? 256),
                (int) ($bounds['max_graph_visits'] ?? 2048),
                $graphRemaining,
            );
            $result['unique_path_count'] = $graph['count'];
            $result['reason_code'] = $graph['reason_code'];
            if ($graph['count'] !== 1 || $graph['edges'] === []) {
                $result['status'] = 'partial_or_unknown';
                $result['stage'] = 'graph_search';

                return $result;
            }
            $proof = $graph['edges'][0]['image_proof'];
            $remaining = (float) $bounds['max_seconds'] - (microtime(true) - $started);
            if ($remaining <= 0) {
                $result['status'] = 'partial_or_unknown';
                $result['reason_code'] = 'scan_deadline_exceeded';
                $result['stage'] = 'current_image_proof';

                return $result;
            }
            $currentProof = $secondsBudget === null
                ? $this->verifyMetadataImageProofForSource($physicalPath, $afterSha256, $proof, $remaining)
                : $this->verifyMetadataImageProofForSource($physicalPath, $afterSha256, $proof, $remaining, true);
            $result['current_image_proof_status'] = $currentProof['status'];
            $result['current_source_sha256'] = $currentProof['source_sha256'];
            $result['current_image_proof_sha256'] = $currentProof['image_proof_sha256'];
            if (! $currentProof['success']) {
                $result['status'] = 'partial_or_unknown';
                $result['reason_code'] = microtime(true) - $started >= (float) $bounds['max_seconds']
                    ? 'scan_deadline_exceeded' : 'current_image_proof_held';
                $result['stage'] = 'current_image_proof';

                return $result;
            }
            $result['success'] = true;
            $result['status'] = 'verified';
            $result['reason_code'] = 'unique_same_asset_chain_verified';
            $result['stage'] = 'complete';
            $result['transitions'] = array_map(static fn (array $edge): array => [
                'packet_sha256' => $edge['packet_sha256'], 'before_sha256' => $edge['before_sha256'],
                'after_sha256' => $edge['after_sha256'], 'preimage_bytes_verified' => true,
                'image_proof_sha256' => $edge['image_proof_sha256'],
                'completed_receipt_sha256' => $edge['completed_receipt_sha256'],
            ], $graph['edges']);

            return $result;
        } catch (Throwable) {
            return $held;
        }
    }

    /** @return array{complete:bool,matching_receipts:int,edges:array} */
    private function readMetadataTransitionEdges(
        int $registryId,
        string $assetUuid,
        string $physicalRegistryPath,
        string $dataRoot,
        array $bounds,
        float $started,
    ): array {
        $scan = ['complete' => false, 'matching_receipts' => 0, 'edges' => [],
            'stage' => 'private_storage', 'reason_code' => 'receipt_scan_incomplete'];
        try {
            $privateRoot = realpath(storage_path('app/private'));
            if (! $privateRoot || is_link(storage_path('app')) || is_link(storage_path('app/private'))) {
                $scan['reason_code'] = 'private_storage_unavailable';

                return $scan;
            }
            $scan['stage'] = 'receipt_inventory';
            $limit = max(1, min(5000, (int) ($bounds['max_receipts'] ?? 0)));
            if ($limit < 1) {
                $scan['reason_code'] = 'receipt_limit_invalid';

                return $scan;
            }
            if (microtime(true) - $started >= (float) $bounds['max_seconds']) {
                $scan['reason_code'] = 'scan_deadline_exceeded';

                return $scan;
            }

            // Enumerate every retained face owner, including retired generations.
            // A missing approval row or malformed reference makes ownership
            // incomplete; an inner join would silently lose that evidence.
            $scan['stage'] = 'face_approval_owners';
            $faceOwners = DB::table('file_registry_faces as face')
                ->leftJoin('review_desk_receipts as approval', 'approval.id', '=', 'face.global_identity_approval_receipt_id')
                ->where('face.file_registry_id', $registryId)
                ->whereNotNull('face.global_identity_approval_receipt_id')
                ->orderBy('face.id')->limit($limit + 1)
                ->get(['face.global_identity_approval_receipt_id', 'approval.reference']);
            if ($faceOwners->count() > $limit) {
                $scan['reason_code'] = 'face_approval_limit_exceeded';

                return $scan;
            }
            if (microtime(true) - $started >= (float) $bounds['max_seconds']) {
                $scan['reason_code'] = 'scan_deadline_exceeded';

                return $scan;
            }
            $approvalReferences = [];
            foreach ($faceOwners as $owner) {
                if (! is_string($owner->global_identity_approval_receipt_id ?? null)
                    || ! Str::isUuid($owner->global_identity_approval_receipt_id)
                    || ! is_string($owner->reference ?? null)
                    || ! Str::isUuid($owner->reference)) {
                    $scan['stage'] = 'face_approval_owners';
                    $scan['reason_code'] = 'face_approval_owner_unresolved';

                    return $scan;
                }
                $approvalReferences[] = $owner->reference;
            }
            $approvalReferences = array_values(array_unique($approvalReferences));
            sort($approvalReferences, SORT_STRING);
            $transitionReceipts = DB::table('review_desk_receipts')->where('action', 'metadata_transition')
                ->whereIn('reference', $approvalReferences)
                ->orderBy('created_at')->orderBy('id')->limit($limit + 1)
                ->get(['id', 'reference', 'action', 'state', 'payload', 'request_digest', 'created_at']);
            $dateReceipts = DB::table('review_desk_receipts')->where('action', 'write_exact_capture_date')
                ->where('reference', $assetUuid)
                ->orderBy('created_at')->orderBy('id')->limit($limit + 1)
                ->get();
            $reconciliationReceipts = DB::table('review_desk_receipts')->where('action', ExactCaptureDateReconciliationService::ACTION)
                ->where('reference', $assetUuid)
                ->orderBy('created_at')->orderBy('id')->limit($limit + 1)
                ->get();
            $restorationReceipts = $dateReceipts->contains(fn ($row) => $row->state === 'unknown')
                ? DB::table('review_desk_receipts')->where('action', 'metadata_original_restored')->where('reference', $assetUuid)
                    ->orderBy('created_at')->orderBy('id')->limit($limit + 1)->get() : collect();
            $scan['stage'] = 'receipt_inventory';
            if ($transitionReceipts->count() + $dateReceipts->count() + $reconciliationReceipts->count() + $restorationReceipts->count() > $limit) {
                $scan['reason_code'] = 'receipt_limit_exceeded';

                return $scan;
            }
            if (microtime(true) - $started >= (float) $bounds['max_seconds']) {
                $scan['reason_code'] = 'scan_deadline_exceeded';

                return $scan;
            }

            $receipts = $transitionReceipts->concat($dateReceipts)->concat($reconciliationReceipts)->values();
            if ($receipts->isEmpty()) {
                // Empty indexed selectors cannot rule out legacy packets that never
                // had a source-bound owner receipt, so do not report no_evidence.
                $scan['reason_code'] = 'source_bound_receipts_absent';

                return $scan;
            }
            $scan['matching_receipts'] = $receipts->count();
            $bytesRead = 0;
            $uniqueEdges = [];
            $packets = [];
            $restoredUnknown = [];
            foreach ($receipts as $receipt) {
                if (microtime(true) - $started >= (float) $bounds['max_seconds']) {
                    $scan['stage'] = 'receipt_paths';
                    $scan['reason_code'] = 'scan_deadline_exceeded';

                    return $scan;
                }
                $scan['stage'] = 'receipt_paths';
                $payload = json_decode((string) ($receipt->payload ?? ''), true);
                if (! is_array($payload)) {
                    $scan['reason_code'] = 'owner_receipt_invalid';

                    return $scan;
                }
                if ($receipt->action === 'write_exact_capture_date' && $receipt->state === 'unknown') {
                    $matchingRestorations = $restorationReceipts->filter(function ($row) use ($receipt): bool {
                        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

                        return ($payload['original_receipt_id'] ?? null) === $receipt->id;
                    })->all();
                    if (($bounds['bounded_producer_read'] ?? false) === true) {
                        // These independent owner readers have not acquired an outer
                        // deadline contract. Retain their proof; never enter them here.
                        $scan['stage'] = 'owner_budget_admission';
                        $scan['reason_code'] = 'budget_unsupported:'.($matchingRestorations !== []
                            ? 'metadata_original_restored' : 'exact_completed_date_reconciliation');

                        return $scan;
                    }
                    if ($matchingRestorations !== []) {
                        $scan['stage'] = 'owner_restoration_receipt';
                        $request = $payload['request'] ?? null;
                        $restoration = count($matchingRestorations) === 1 && is_array($request)
                            && ($payload['schema'] ?? null) === 'plos.exact_capture_date_write.v1'
                            && $receipt->reference === $assetUuid && $receipt->idempotency_key === $receipt->id
                            && ($request['idempotency_key'] ?? null) === $receipt->id
                            && ($request['asset_uuid'] ?? null) === $assetUuid
                            && ($request['file_registry_id'] ?? null) === $registryId
                            && preg_match('/\A[a-f0-9]{64}\z/', (string) ($request['source_sha256'] ?? ''))
                            && hash_equals($receipt->request_digest, hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))
                            ? $this->originalRestorationProof($receipt, $request['source_sha256'], $matchingRestorations, false, $bounds, $bytesRead) : null;
                        $terminal = $restoration['receipt'] ?? null;
                        if (! $restoration || ! is_array($terminal) || ! Str::isUuid((string) ($terminal['id'] ?? ''))
                            || ($terminal['idempotency_key'] ?? null) !== $terminal['id']
                            || strtotime((string) ($terminal['created_at'] ?? '')) === false
                            || strtotime((string) ($receipt->created_at ?? '')) === false
                            || new \DateTimeImmutable($terminal['created_at']) < new \DateTimeImmutable($receipt->created_at)
                            || ($request['registry_path_sha256'] ?? null) !== hash('sha256', (string) ($restoration['transition']['registry_before']['current_path'] ?? ''))) {
                            $scan['reason_code'] = 'restoration_receipt_invalid';

                            return $scan;
                        }
                        $restoredUnknown[$receipt->id] = $restoration + ['original' => (array) $receipt,
                            'before_sha256' => $request['source_sha256'],
                            'receipt_sha256' => hash('sha256', $receipt->id."\0".$receipt->payload."\0".$terminal['id']."\0".$terminal['payload'])];
                    }
                }
                $paths = match ($receipt->action) {
                    'metadata_transition' => [$payload['packet_path'] ?? null],
                    ExactCaptureDateReconciliationService::ACTION => [$payload['proof']['writer']['metadata_transition_receipt'] ?? null],
                    default => [$payload['writer_result']['metadata_transition_receipt'] ?? null,
                        $payload['result']['metadata_transition_receipt'] ?? null],
                };
                $paths = array_values(array_filter($paths, static fn ($path): bool => is_string($path) && $path !== ''));
                if ($paths === [] || count(array_unique($paths)) > 1) {
                    $scan['reason_code'] = 'owner_receipt_path_invalid';

                    return $scan;
                }
                foreach ($paths as $packetRelative) {
                    if (! str_starts_with($packetRelative, 'app/private/') || str_contains($packetRelative, "\0")
                        || str_contains($packetRelative, '\\') || in_array('..', explode('/', $packetRelative), true)
                        || in_array('', explode('/', $packetRelative), true) || in_array('.', explode('/', $packetRelative), true)) {
                        $scan['reason_code'] = 'owner_receipt_path_invalid';

                        return $scan;
                    }
                    $packets[$packetRelative] ??= [];
                    $packets[$packetRelative][$receipt->id] = $receipt;
                }
            }
            if (count($packets) > $limit || count($packets) > (int) ($bounds['max_graph_edges'] ?? 0)) {
                $scan['stage'] = 'packet_inventory';
                $scan['reason_code'] = 'packet_limit_exceeded';

                return $scan;
            }
            $reconciliationCache = [];
            foreach ($packets as $packetRelative => $packetReceiptMap) {
                $scan['stage'] = 'packet_path';
                $packetReceipts = array_values($packetReceiptMap);
                $packetPath = storage_path($packetRelative);
                $cursor = storage_path();
                foreach (explode('/', $packetRelative) as $segment) {
                    $cursor .= DIRECTORY_SEPARATOR.$segment;
                    if (is_link($cursor)) {
                        $scan['reason_code'] = 'packet_path_invalid';

                        return $scan;
                    }
                }
                $resolvedPacket = realpath($packetPath);
                if (! $resolvedPacket || $resolvedPacket !== $packetPath
                    || ! str_starts_with($resolvedPacket, $privateRoot.DIRECTORY_SEPARATOR)) {
                    $scan['reason_code'] = 'packet_path_invalid';

                    return $scan;
                }
                $scan['stage'] = 'packet_file';
                clearstatcache(true, $packetPath);
                $packetStat = lstat($packetPath);
                if (is_link($packetPath) || ! $packetStat || (($packetStat['mode'] & 0170000) !== 0100000)
                    || $packetStat['size'] <= 0 || $packetStat['size'] > (int) $bounds['max_packet_bytes']
                    || ($bytesRead += $packetStat['size']) > (int) $bounds['max_total_bytes']) {
                    $scan['reason_code'] = 'packet_bounds_exceeded';

                    return $scan;
                }
                if (($bounds['bounded_producer_read'] ?? false) === true) {
                    $packetBytes = '';
                    $packetDigest = $this->metadataProofSourceHash($packetPath, $started + (float) $bounds['max_seconds'], $packetBytes);
                } else {
                    $packetBytes = file_get_contents($packetPath);
                }
                $packet = is_string($packetBytes) ? json_decode($packetBytes, true) : null;
                if (! is_array($packet) || (($bounds['bounded_producer_read'] ?? false) === true
                    ? $packetDigest : hash_file('sha256', $packetPath)) !== hash('sha256', (string) $packetBytes)) {
                    $scan['reason_code'] = 'packet_integrity_invalid';

                    return $scan;
                }
                $scan['stage'] = 'packet_binding';
                $preimage = $packet['registry_before'] ?? null;
                if (! is_array($preimage) || (int) ($preimage['id'] ?? 0) !== $registryId
                    || ($preimage['asset_uuid'] ?? null) !== $assetUuid
                    || ($packet['source_path'] ?? null) !== $physicalRegistryPath) {
                    $scan['reason_code'] = 'packet_source_binding_invalid';

                    return $scan;
                }
                $before = $packet['before_sha256'] ?? null;
                $after = $packet['after_sha256'] ?? null;
                $proof = $packet['image_proof'] ?? null;
                $sourcePath = $packet['source_path'] ?? null;
                $registryPath = $preimage['current_path'] ?? null;
                if (($packet['kind'] ?? null) !== 'verified_metadata_only' || ! is_string($before)
                    || ! preg_match('/\A[a-f0-9]{64}\z/', $before) || ! is_string($after)
                    || ! preg_match('/\A[a-f0-9]{64}\z/', $after) || hash_equals($before, $after)
                    || ! is_array($proof) || ! $this->validMetadataImageProofShape($proof)
                    || ! is_string($sourcePath) || ! is_string($registryPath)) {
                    $scan['stage'] = 'packet_proof';
                    $scan['reason_code'] = 'packet_proof_invalid';

                    return $scan;
                }
                $relativeSource = '/'.ltrim($registryPath, '/');
                if (str_contains($relativeSource, "\0") || str_contains($relativeSource, '\\')
                    || in_array('..', explode('/', $relativeSource), true)
                    || $sourcePath !== $dataRoot.$relativeSource || ! str_starts_with($sourcePath, $dataRoot.DIRECTORY_SEPARATOR)) {
                    $scan['reason_code'] = 'packet_source_path_invalid';

                    return $scan;
                }
                $packetDirectory = dirname($packetRelative);
                $backupRelative = $packetDirectory.'/'.$before.'.original';
                $receiptRelative = $packetDirectory.'/'.$before.'.json';
                if (($packet['backup']['backup_path'] ?? null) !== $backupRelative) {
                    $scan['stage'] = 'backup_binding';
                    $scan['reason_code'] = 'backup_path_binding_invalid';

                    return $scan;
                }
                $backup = storage_path($backupRelative);
                $receiptPath = storage_path($receiptRelative);
                foreach ([$backup, $receiptPath] as $sidecar) {
                    $cursor = storage_path();
                    foreach (explode('/', ltrim(substr($sidecar, strlen(storage_path())), DIRECTORY_SEPARATOR)) as $segment) {
                        $cursor .= DIRECTORY_SEPARATOR.$segment;
                        if (is_link($cursor)) {
                            $scan['stage'] = 'backup_path';
                            $scan['reason_code'] = 'backup_path_invalid';

                            return $scan;
                        }
                    }
                }
                clearstatcache(true, $backup);
                clearstatcache(true, $receiptPath);
                $scan['stage'] = 'backup_file';
                $backupStat = lstat($backup);
                $receiptStat = lstat($receiptPath);
                if (is_link($backup) || is_link($receiptPath) || ! $backupStat || ! $receiptStat
                    || (($backupStat['mode'] & 0170000) !== 0100000) || (($receiptStat['mode'] & 0170000) !== 0100000)
                    || $backupStat['size'] <= 0 || $backupStat['size'] > (int) $bounds['max_backup_bytes']
                    || $receiptStat['size'] <= 0 || $receiptStat['size'] > (int) $bounds['max_backup_receipt_bytes']
                    || ($bytesRead += $backupStat['size'] + $receiptStat['size']) > (int) $bounds['max_total_bytes']) {
                    $scan['reason_code'] = 'backup_bounds_exceeded';

                    return $scan;
                }
                if (($bounds['bounded_producer_read'] ?? false) === true) {
                    $receiptBytes = '';
                    $receiptDigest = $this->metadataProofSourceHash($receiptPath, $started + (float) $bounds['max_seconds'], $receiptBytes);
                } else {
                    $receiptBytes = file_get_contents($receiptPath);
                }
                $receipt = is_string($receiptBytes) ? json_decode($receiptBytes, true) : null;
                if (! is_array($receipt) || (($bounds['bounded_producer_read'] ?? false) === true
                    ? $receiptDigest : hash_file('sha256', $receiptPath)) !== hash('sha256', (string) $receiptBytes)
                    || ($receipt['source_sha256'] ?? null) !== $before || ($receipt['backup_sha256'] ?? null) !== $before
                    || ($receipt['backup_path'] ?? null) !== $backupRelative || ($receipt['source_path'] ?? null) !== $sourcePath
                    || (int) ($receipt['source_size'] ?? -1) !== $backupStat['size']
                    || filesize($backup) !== $backupStat['size'] || (($bounds['bounded_producer_read'] ?? false) === true
                        ? $this->metadataProofSourceHash($backup, $started + (float) $bounds['max_seconds']) : hash_file('sha256', $backup)) !== $before) {
                    $scan['reason_code'] = 'backup_integrity_invalid';

                    return $scan;
                }
                $retiredOwners = array_intersect(array_keys($packetReceiptMap), array_keys($restoredUnknown));
                if ($retiredOwners !== []) {
                    if (count($packetReceiptMap) !== 1 || count($retiredOwners) !== 1
                        || $restoredUnknown[$retiredOwners[0]]['image_proof'] !== $proof
                        || $restoredUnknown[$retiredOwners[0]]['before_sha256'] !== $before) {
                        $scan['stage'] = 'owner_restoration_receipt';
                        $scan['reason_code'] = 'restored_transition_owner_ambiguous';

                        return $scan;
                    }

                    // A rolled-back attempt is never a committed forward edge.
                    continue;
                }
                $scan['stage'] = 'backup_image_proof';
                $remaining = (float) $bounds['max_seconds'] - (microtime(true) - $started);
                if ($remaining <= 0) {
                    $scan['reason_code'] = 'scan_deadline_exceeded';

                    return $scan;
                }
                $proofCheck = ($bounds['bounded_producer_read'] ?? false) === true
                    ? $this->verifyMetadataImageProofForSource($backup, $before, $proof, $remaining, true)
                    : $this->verifyMetadataImageProofForSource($backup, $before, $proof, $remaining);
                if (microtime(true) - $started > (float) $bounds['max_seconds']) {
                    $scan['reason_code'] = 'scan_deadline_exceeded';

                    return $scan;
                }
                if (! $proofCheck['success']) {
                    $scan['reason_code'] = 'backup_image_proof_invalid';

                    return $scan;
                }
                $packetHash = hash('sha256', (string) $packetBytes);
                $scan['stage'] = 'owner_receipt_proof';
                $receiptProof = $this->completedMetadataTransitionReceipt(
                    $registryId, $assetUuid, $before, $after, $packetRelative, $packetHash,
                    (string) ($preimage['current_path'] ?? ''), $receipts->all(), $reconciliationCache,
                    $started + (float) $bounds['max_seconds'], $restoredUnknown, $proof);
                if (! ($receiptProof['success'] ?? false)
                    || microtime(true) - $started >= (float) $bounds['max_seconds']) {
                    $scan['reason_code'] = microtime(true) >= $started + (float) $bounds['max_seconds']
                        ? 'scan_deadline_exceeded' : ($receiptProof['reason_code'] ?? 'owner_receipt_invalid');
                    if (($receiptProof['reason_code'] ?? null) === 'reconciliation_receipt_invalid') {
                        $scan['stage'] = 'owner_reconciliation_receipt';
                    }

                    return $scan;
                }
                $edge = ['packet_sha256' => hash('sha256', (string) $packetBytes), 'before_sha256' => $before,
                    'after_sha256' => $after, 'image_proof_sha256' => $proofCheck['image_proof_sha256'],
                    'completed_receipt_sha256' => $receiptProof['receipt_sha256'], 'image_proof' => $proof];
                $edgeKey = $before.'|'.$after.'|'.$proofCheck['image_proof_sha256'].'|'.$receiptProof['receipt_sha256'];
                $uniqueEdges[$edgeKey] ??= $edge;
                if (count($uniqueEdges) > (int) $bounds['max_graph_edges']) {
                    $scan['stage'] = 'edge_collection';
                    $scan['reason_code'] = 'edge_limit_exceeded';

                    return $scan;
                }
            }
            if ($restoredUnknown !== []) {
                $freshRestorations = DB::table('review_desk_receipts')->where('action', 'metadata_original_restored')->where('reference', $assetUuid)
                    ->orderBy('created_at')->orderBy('id')->limit($limit + 1)->get();
                if ($freshRestorations->map(fn ($row) => (array) $row)->all() !== $restorationReceipts->map(fn ($row) => (array) $row)->all()) {
                    $scan['reason_code'] = 'restoration_receipt_changed';

                    return $scan;
                }
                foreach ($restoredUnknown as $id => $restoration) {
                    $original = DB::table('review_desk_receipts')->where('id', $id)->first();
                    if ((array) $original !== $restoration['original']) {
                        $scan['reason_code'] = 'restored_original_receipt_changed';

                        return $scan;
                    }
                    $freshProof = $this->originalRestorationProof($original, $restoration['before_sha256'],
                        [(object) $restoration['receipt']], false, $bounds, $bytesRead);
                    if ($freshProof !== array_intersect_key($restoration, array_flip(['receipt', 'transition', 'image_proof']))) {
                        $scan['reason_code'] = 'restoration_private_proof_changed';

                        return $scan;
                    }
                }
            }
            $scan['edges'] = array_values($uniqueEdges);
            $scan['complete'] = true;
            $scan['stage'] = 'complete';
            $scan['reason_code'] = null;
        } catch (Throwable) {
            $scan['reason_code'] = 'receipt_scan_error';
        }

        return $scan;
    }

    /** Match a packet to an existing completed owner receipt; unknown attempts are never rewritten. */
    private function completedMetadataTransitionReceipt(
        int $registryId,
        string $assetUuid,
        string $beforeSha256,
        string $afterSha256,
        string $packetRelative,
        string $packetSha256,
        string $registryPath,
        array $receipts,
        array &$reconciliationCache,
        float $deadline,
        array $restoredUnknown = [],
        ?array $packetImageProof = null,
    ): array {
        try {
            $completed = [];
            $ownerTimes = [];
            $restoredBefore = null;
            foreach ($receipts as $receipt) {
                $payload = json_decode((string) ($receipt->payload ?? ''), true);
                if (! is_array($payload)) {
                    continue;
                }
                $writerPath = $payload['writer_result']['metadata_transition_receipt']
                    ?? $payload['proof']['writer']['metadata_transition_receipt'] ?? null;
                $resultPath = $payload['result']['metadata_transition_receipt'] ?? null;
                $packetPath = $payload['packet_path'] ?? null;
                $sameAsset = $receipt->action === 'metadata_transition'
                    ? (int) ($payload['file_registry_id'] ?? 0) === $registryId
                    : (($receipt->reference ?? null) === $assetUuid
                        || ($payload['request']['asset_uuid'] ?? null) === $assetUuid);
                $sourceBound = $sameAsset
                    && (int) ($payload['request']['file_registry_id'] ?? $payload['file_registry_id'] ?? 0) === $registryId
                    && (($payload['request']['source_sha256'] ?? null) === $beforeSha256
                        || ($payload['before_sha256'] ?? null) === $beforeSha256);
                $exactPath = in_array($packetRelative, [$writerPath, $resultPath, $packetPath], true);
                if (! $exactPath && ! $sourceBound) {
                    continue;
                }
                if (isset($restoredUnknown[$receipt->id])) {
                    $restoration = $restoredUnknown[$receipt->id];
                    if ($exactPath || ! $sourceBound || $restoration['before_sha256'] !== $beforeSha256
                        || $restoration['image_proof'] !== $packetImageProof) {
                        return ['success' => false, 'receipt_sha256' => null, 'reason_code' => 'restored_transition_owner_ambiguous'];
                    }
                    // Bind the closed attempt without substituting it for the later writer.
                    $completed[] = $restoration['receipt_sha256'];
                    $restoredAt = new \DateTimeImmutable($restoration['receipt']['created_at']);
                    $restoredBefore = $restoredBefore === null ? $restoredAt : max($restoredBefore, $restoredAt);

                    continue;
                }
                if ($receipt->state !== 'completed') {
                    $matches = $receipt->action === 'write_exact_capture_date' && $receipt->state === 'unknown'
                        && $this->isReconciledUnknownDateReceipt(
                            $receipt, $receipts, $registryId, $assetUuid, $beforeSha256, $afterSha256,
                            $packetRelative, $packetSha256, $registryPath, $reconciliationCache, $deadline,
                        );
                    if (! $matches) {
                        return ['success' => false, 'receipt_sha256' => null,
                            'reason_code' => $receipt->action === 'write_exact_capture_date' && $receipt->state === 'unknown'
                                ? 'reconciliation_receipt_invalid' : 'owner_receipt_invalid'];
                    }
                } elseif ($receipt->action === 'metadata_transition') {
                    $matches = $exactPath && ($payload['schema'] ?? null) === 'plos.global_face_metadata_transition.v1'
                        && ($payload['kind'] ?? null) === 'verified_metadata_only' && ($payload['success'] ?? null) === true
                        && ($payload['decision_recorded'] ?? null) === false && ($payload['identity_decision_changed'] ?? null) === false
                        && (int) ($payload['file_registry_id'] ?? 0) === $registryId
                        && Str::isUuid((string) ($payload['identity_uuid'] ?? ''))
                        && ($payload['before_sha256'] ?? null) === $beforeSha256
                        && ($payload['after_sha256'] ?? null) === $afterSha256
                        && ($payload['packet_sha256'] ?? null) === $packetSha256
                        && ($payload['source_path'] ?? null) === $registryPath
                        && hash_equals((string) ($receipt->request_digest ?? ''), app(GlobalFaceWritebackApprovalService::class)->digest($payload));
                    if ($matches) {
                        $approvalId = $payload['approval_receipt_id'] ?? null;
                        $approval = is_string($approvalId) && Str::isUuid($approvalId)
                            ? DB::table('review_desk_receipts')->where('id', $approvalId)
                                ->first(['id', 'reference', 'action', 'state', 'payload']) : null;
                        $approvalPayload = $approval ? json_decode((string) $approval->payload, true) : null;
                        $decision = $approvalPayload['global_identity_decision'] ?? null;
                        $matches = $approval && $approval->state === 'completed'
                            && $approval->reference === $receipt->reference && is_array($approvalPayload)
                            && ($approvalPayload['success'] ?? null) === true && ($approvalPayload['decision_recorded'] ?? null) === true
                            && ($approvalPayload['decision_scope'] ?? null) === 'confirm_current_face_global_identity'
                            && (($approval->action === 'confirm_face' && ($approvalPayload['actor'] ?? null) === 'operator')
                                || ($approval->action === 'approve' && ($approvalPayload['reference'] ?? null) === $approval->reference))
                            && is_array($decision) && ($decision['operation'] ?? null) === 'confirm_face'
                            && ($decision['identity_uuid'] ?? null) === $payload['identity_uuid']
                            && ($decision['face_id'] ?? null) === (int) ($payload['face_id'] ?? 0);
                    }
                } elseif ($receipt->action === ExactCaptureDateReconciliationService::ACTION) {
                    $matches = $exactPath && ($reconciliationCache[$receipt->id] ?? false) === true;
                } else {
                    $request = $payload['request'] ?? null;
                    $writer = $payload['writer_result'] ?? null;
                    $result = $payload['result'] ?? null;
                    $matches = $exactPath && is_array($request) && is_array($writer) && is_array($result)
                        && ($payload['effect_status'] ?? null) === 'completed'
                        && ($receipt->reference ?? null) === $assetUuid
                        && ($request['asset_uuid'] ?? null) === $assetUuid
                        && (int) ($request['file_registry_id'] ?? 0) === $registryId
                        && ($request['source_sha256'] ?? null) === $beforeSha256
                        && ($writer['success'] ?? null) === true
                        && ($writer['backup_sha256'] ?? null) === $beforeSha256
                        && ($writer['written_sha256'] ?? null) === $afterSha256
                        && ($result['written_sha256'] ?? null) === $afterSha256
                        && $writerPath === $packetRelative && $resultPath === $packetRelative
                        && hash_equals((string) ($receipt->request_digest ?? ''), hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
                }
                if (! $matches) {
                    return ['success' => false, 'receipt_sha256' => null, 'reason_code' => 'owner_receipt_invalid'];
                }
                if ($receipt->state === 'completed') {
                    $created = (string) ($receipt->created_at ?? '');
                    $ownerTimes[] = strtotime($created) === false ? false : new \DateTimeImmutable($created);
                }
                $completed[] = hash('sha256', (string) $receipt->id."\0".(string) $receipt->payload);
            }
            if ($restoredBefore !== null && ($ownerTimes === [] || in_array(false, $ownerTimes, true) || min($ownerTimes) < $restoredBefore)) {
                return ['success' => false, 'receipt_sha256' => null, 'reason_code' => 'restored_transition_owner_chronology_invalid'];
            }
            $digests = array_values(array_unique($completed));
            sort($digests, SORT_STRING);
            if ($digests === []) {
                return ['success' => false, 'receipt_sha256' => null, 'reason_code' => 'owner_receipt_missing'];
            }

            return ['success' => true, 'receipt_sha256' => hash('sha256', implode("\n", $digests))];
        } catch (Throwable) {
            return ['success' => false, 'receipt_sha256' => null, 'reason_code' => 'owner_receipt_error'];
        }
    }

    /** A retained UNKNOWN is usable only when its own append-only completion proof validates. */
    private function isReconciledUnknownDateReceipt(
        object $original,
        array $receipts,
        int $registryId,
        string $assetUuid,
        string $beforeSha256,
        string $afterSha256,
        string $packetRelative,
        string $packetSha256,
        string $registryPath,
        array &$reconciliationCache,
        float $deadline,
    ): bool {
        try {
            $originalPayload = json_decode((string) ($original->payload ?? ''), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($originalPayload)
                || ($original->action ?? null) !== 'write_exact_capture_date'
                || ($original->state ?? null) !== 'unknown'
                || ($original->reference ?? null) !== $assetUuid
                || ($originalPayload['writer_result']['metadata_transition_receipt'] ?? null) !== $packetRelative
                || ($originalPayload['request']['asset_uuid'] ?? null) !== $assetUuid
                || (int) ($originalPayload['request']['file_registry_id'] ?? 0) !== $registryId
                || ($originalPayload['request']['source_sha256'] ?? null) !== $beforeSha256) {
                return false;
            }
            foreach ($receipts as $receipt) {
                if (microtime(true) >= $deadline) {
                    return false;
                }
                if (($receipt->action ?? null) !== ExactCaptureDateReconciliationService::ACTION
                    || ($receipt->state ?? null) !== 'completed') {
                    continue;
                }
                $payload = json_decode((string) ($receipt->payload ?? ''), true, flags: JSON_THROW_ON_ERROR);
                $result = is_array($payload) ? ($payload['result'] ?? null) : null;
                $proof = is_array($payload) ? ($payload['proof'] ?? null) : null;
                $writer = is_array($proof) ? ($proof['writer'] ?? null) : null;
                $matches = ($receipt->reference ?? null) === $assetUuid
                    && ($receipt->idempotency_key ?? null) === ($receipt->id ?? null)
                    && is_array($result) && is_array($proof) && is_array($writer)
                    && ($payload['schema'] ?? null) === 'plos.exact_capture_date_reconciliation.v1'
                    && ($result['original_receipt_id'] ?? null) === $original->id
                    && ($result['success'] ?? null) === true && ($result['status'] ?? null) === 'completed'
                    && ($result['receipt_id'] ?? null) === $receipt->id
                    && ($result['asset_uuid'] ?? null) === $assetUuid
                    && ($result['metadata_file_written'] ?? null) === false
                    && ($result['identity_decision_changed'] ?? null) === false
                    && ($result['effect_status'] ?? null) === 'verified_committed_writer'
                    && (int) ($proof['registry']['id'] ?? 0) === $registryId
                    && ($proof['registry']['asset_uuid'] ?? null) === $assetUuid
                    && ($proof['registry']['current_path'] ?? null) === $registryPath
                    && ($proof['transition_sha256'] ?? null) === $packetSha256
                    && ($writer['backup_sha256'] ?? null) === $beforeSha256
                    && ($writer['written_sha256'] ?? null) === $afterSha256
                    && ($writer['metadata_transition_receipt'] ?? null) === $packetRelative;
                if (! $matches) {
                    continue;
                }

                if (microtime(true) >= $deadline) {
                    return false;
                }
                $reconciliationCache[$receipt->id] = app(ExactCaptureDateReconciliationService::class)
                    ->isExactCommittedReceipt($original, $receipt);

                return microtime(true) < $deadline && $reconciliationCache[$receipt->id];
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private function metadataTransitionPath(
        array $edges,
        string $from,
        string $to,
        int $maxEdges,
        int $maxVisits,
        float $remainingSeconds,
    ): array {
        if (count($edges) > $maxEdges || $maxVisits < 1 || $remainingSeconds <= 0) {
            return ['count' => 0, 'edges' => [], 'reason_code' => 'graph_bound_exceeded'];
        }
        $adjacency = [];
        foreach ($edges as $edge) {
            $adjacency[$edge['before_sha256']][] = $edge;
        }
        $paths = [];
        $visits = 0;
        $bounded = false;
        $deadline = microtime(true) + $remainingSeconds;
        $walk = function (string $hash, array $seen, array $path) use (
            &$walk, &$paths, &$visits, &$bounded, $adjacency, $to, $maxVisits, $deadline,
        ): void {
            if (++$visits > $maxVisits || microtime(true) >= $deadline) {
                $bounded = true;

                return;
            }
            if (count($paths) > 1) {
                return;
            }
            if (hash_equals($hash, $to)) {
                $paths[] = $path;

                return;
            }
            foreach ($adjacency[$hash] ?? [] as $edge) {
                if ($bounded || count($paths) > 1) {
                    return;
                }
                $next = $edge['after_sha256'];
                if (isset($seen[$next])) {
                    continue;
                }
                $walk($next, $seen + [$next => true], [...$path, $edge]);
            }
        };
        $walk($from, [$from => true], []);
        if ($bounded) {
            return ['count' => 0, 'edges' => [], 'reason_code' => 'graph_search_bound_exceeded'];
        }
        if (count($paths) !== 1) {
            return ['count' => min(2, count($paths)), 'edges' => [],
                'reason_code' => count($paths) > 1 ? 'ambiguous_path' : 'no_path'];
        }
        $path = $paths[0];
        if (count(array_unique(array_column($path, 'image_proof_sha256'))) !== 1) {
            return ['count' => 0, 'edges' => [], 'reason_code' => 'image_proof_chain_mismatch'];
        }

        return ['count' => 1, 'edges' => $path, 'reason_code' => 'unique_path'];
    }

    private function metadataImageProof(string $path, ?float $secondsBudget = null, bool $boundedReads = false): array
    {
        $verificationSeconds = (float) config('metadata_writeback.max_verification_seconds', 30);
        if ($secondsBudget !== null) {
            $verificationSeconds = min($verificationSeconds, $secondsBudget);
        }
        $deadline = microtime(true) + $verificationSeconds;
        if ($boundedReads) {
            $bytes = '';
            $memory = ini_get('memory_limit');
            if ($memory !== '-1') {
                $scale = match (strtolower(substr($memory, -1))) {
                    'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
                };
                if ((int) $memory * $scale - memory_get_usage(true) < filesize($path) * 2) {
                    throw new \RuntimeException('Image verification exceeds the available memory budget');
                }
            }
            if ($this->metadataProofSourceHash($path, $deadline, $bytes) === false) {
                throw new \RuntimeException('Image verification exceeds the byte or time budget');
            }
            $size = @getimagesizefromstring($bytes);
        } else {
            $size = @getimagesize($path);
        }
        $maxPixels = (int) config('metadata_writeback.max_verification_pixels', 16000000);
        if (! $size || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            || $size[0] <= 0 || $size[1] <= 0 || $maxPixels < $size[0] * $size[1]
            || ! function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('Image decoder or verification resource budget unavailable; metadata write held');
        }
        $maxBytes = (int) config('metadata_writeback.max_verification_bytes', 67108864);
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit !== '-1') {
            $scale = match (strtolower(substr($memoryLimit, -1))) {
                'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
            };
            if ((int) $memoryLimit * $scale - memory_get_usage(true) < $size[0] * $size[1] * 12 + filesize($path) * 2) {
                throw new \RuntimeException('Image verification exceeds the available memory budget');
            }
        }
        if (filesize($path) > $maxBytes) {
            throw new \RuntimeException('Image verification exceeds the byte budget');
        }
        if (! $boundedReads) {
            $bytes = file_get_contents($path);
        }
        if (microtime(true) >= $deadline) {
            throw new \RuntimeException('Image verification exceeds the time budget');
        }
        if ($size[2] === IMAGETYPE_PNG && str_contains($bytes, 'acTL')) {
            throw new \RuntimeException('Animated image metadata transition requires review');
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image || microtime(true) >= $deadline) {
            if ($image) {
                imagedestroy($image);
            }
            throw new \RuntimeException('Source pixels could not be decoded; metadata write held');
        }
        try {
            $pixelSha256 = $this->metadataRgbaPixelSha256($image, $size[0], $size[1], $deadline);
        } finally {
            imagedestroy($image);
        }
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new \RuntimeException('Image verification exceeds the time budget');
        }
        $read = Process::timeout(max(1, (int) ceil($remaining)))->run([$this->exiftoolPath, '-json', '-G1', '-n', '-Orientation', '-Rotation', '-Mirror', '-ColorSpace', '-Gamma', $path]);
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new \RuntimeException('Image verification exceeds the time budget');
        }
        $profile = Process::timeout(max(1, (int) ceil($remaining)))->run([$this->exiftoolPath, '-b', '-ICC_Profile', $path]);
        if (! $read->successful() || ! $profile->successful()) {
            throw new \RuntimeException('Display metadata could not be verified');
        }
        $records = json_decode($read->output(), true, flags: JSON_THROW_ON_ERROR);
        $orientation = $records[0] ?? null;
        if (! is_array($orientation) || count($records) !== 1 || ($orientation['SourceFile'] ?? null) !== $path) {
            throw new \RuntimeException('Display metadata is ambiguous');
        }
        foreach (array_keys($orientation) as $tag) {
            if (preg_match('/(?:^|:)(Error|Warning)$/', $tag)) {
                throw new \RuntimeException('Display metadata is ambiguous');
            }
        }
        unset($orientation['SourceFile']);
        foreach ($orientation as $key => $value) {
            // ExifTool adds the EXIF "uncalibrated" sentinel when creating an
            // EXIF block. It declares no color conversion, like an absent tag.
            if (str_ends_with($key, ':ColorSpace') && (int) $value === 65535) {
                unset($orientation[$key]);
            }
        }
        ksort($orientation);

        return ['method' => 'gd_decoded_rgba_sha256_v1', 'width' => $size[0], 'height' => $size[1],
            'image_type' => $size[2], 'pixels_sha256' => $pixelSha256,
            'orientation' => $orientation, 'icc_sha256' => hash('sha256', $profile->output()),
            // GD reduces PNG alpha to seven bits; retaining every non-metadata
            // PNG chunk also proves the underlying full-depth samples unchanged.
            'png_content_sha256' => $size[2] === IMAGETYPE_PNG ? $this->pngContentHash($bytes) : null];
    }

    private function metadataRgbaPixelSha256(\GdImage $image, int $width, int $height, float $deadline): string
    {
        $hash = hash_init('sha256');
        $trueColor = imageistruecolor($image);
        for ($y = 0; $y < $height; $y++) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Image verification exceeds the time budget');
            }
            if ($trueColor) {
                // AARRGGBB -> RRGGBBAA preserves the existing RGBA byte stream.
                // Bound the temporary integers and pack each chunk in C.
                for ($start = 0; $start < $width; $start += 1024) {
                    if (microtime(true) > $deadline) {
                        throw new \RuntimeException('Image verification exceeds the time budget');
                    }
                    $end = min($width, $start + 1024);
                    $pixels = [];
                    for ($x = $start; $x < $end; $x++) {
                        $color = imagecolorat($image, $x, $y);
                        $pixels[] = (($color & 0xFFFFFF) << 8) | (($color >> 24) & 0x7F);
                    }
                    hash_update($hash, pack('N*', ...$pixels));
                }

                continue;
            }
            $row = '';
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $row .= pack('CCCC', $rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']);
            }
            hash_update($hash, $row);
        }

        return hash_final($hash);
    }

    private function pngContentHash(string $bytes): string
    {
        $hash = hash_init('sha256');
        $offset = 8;
        while ($offset + 12 <= strlen($bytes)) {
            $length = unpack('N', substr($bytes, $offset, 4))[1];
            if ($offset + $length + 12 > strlen($bytes)) {
                throw new \RuntimeException('PNG chunk structure cannot be verified');
            }
            $type = substr($bytes, $offset + 4, 4);
            if (! in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'], true)) {
                hash_update($hash, substr($bytes, $offset, $length + 12));
            }
            $offset += $length + 12;
        }
        if ($offset !== strlen($bytes)) {
            throw new \RuntimeException('PNG trailing data requires review');
        }

        return hash_final($hash);
    }

    private function storeMetadataTransition(array $backup, array $transition): string
    {
        $relative = dirname($backup['receipt_path']).'/transition-'.bin2hex(random_bytes(16)).'.json';
        $path = storage_path($relative);
        $handle = fopen($path, 'xb');
        if (! $handle || ! chmod($path, 0600)) {
            throw new \RuntimeException('Cannot secure metadata transition receipt');
        }
        try {
            $bytes = json_encode($transition, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                throw new \RuntimeException('Cannot persist metadata transition receipt');
            }
        } finally {
            fclose($handle);
        }
        $directory = fopen(dirname($path), 'r');
        if (! $directory || ! fsync($directory)) {
            if ($directory) {
                fclose($directory);
            }
            throw new \RuntimeException('Cannot synchronize metadata receipt directory');
        }
        fclose($directory);

        return $relative;
    }

    /** Append proof of a committed source change; never rewrite an identity decision. */
    private function recordGlobalFaceMetadataTransition($connection, object $face, object $file, string $beforeHash, string $afterHash, string $packetPath, array $imageProof): void
    {
        if ($beforeHash === $afterHash) {
            return;
        }
        $identity = $face->global_identity_uuid ?? null;
        $approvalId = $face->global_identity_approval_receipt_id ?? null;
        if (! is_string($identity) || ! Str::isUuid($identity) || ! is_string($approvalId) || ! Str::isUuid($approvalId)) {
            return;
        }
        $receipts = $connection->select('SELECT * FROM review_desk_receipts WHERE id = ? FOR UPDATE', [$approvalId]);
        $approval = $receipts[0] ?? null;
        $payload = $approval ? json_decode($approval->payload, true) : null;
        $decision = $payload['global_identity_decision'] ?? [];
        if (! $approval || $approval->state !== 'completed' || ! Str::isUuid($approval->reference)
            || ($payload['success'] ?? false) !== true || ($payload['decision_recorded'] ?? false) !== true
            || ($decision['operation'] ?? null) !== 'confirm_face' || ($decision['identity_uuid'] ?? null) !== $identity
            || ($decision['face_id'] ?? null) !== (int) $face->id) {
            return;
        }
        $region = [];
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            $region[$axis] = (float) $face->{'region_'.$axis};
        }
        $proof = [
            'schema' => 'plos.global_face_metadata_transition.v1', 'kind' => 'verified_metadata_only',
            'actor' => 'system/verified-metadata', 'success' => true, 'decision_recorded' => false, 'identity_decision_changed' => false,
            'face_id' => (int) $face->id, 'file_registry_id' => (int) $file->id,
            'identity_uuid' => $identity, 'approval_receipt_id' => $approvalId, 'source_path' => $file->current_path,
            'region' => $region, 'face_region_convention' => 'normalized_top_left', 'coordinate_basis' => 'encoded_pixels',
            'before_sha256' => $beforeHash, 'after_sha256' => $afterHash, 'image_proof' => $imageProof,
            'packet_path' => $packetPath, 'packet_sha256' => hash_file('sha256', storage_path($packetPath)),
        ];
        $digest = app(GlobalFaceWritebackApprovalService::class)->digest($proof);
        $connection->insert('INSERT INTO review_desk_receipts (id, reference, idempotency_key, request_digest, action, state, payload, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)', [
            (string) Str::uuid(), $approval->reference, 'metadata-transition:'.$digest, $digest,
            GlobalFaceWritebackApprovalService::TRANSITION_ACTION, 'completed', json_encode($proof, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        ]);
    }

    /** @return array{backup_path:string,receipt_path:string} */
    private function backupOriginalFile(string $sourcePath, string $sourceHash, int $sourceSize): array
    {
        $appRoot = storage_path('app');
        $privateRoot = storage_path('app/private');
        if (is_link($appRoot) || is_link($privateRoot)) {
            throw new \RuntimeException('Private storage root cannot be a symlink');
        }
        if (! is_dir($privateRoot) && ! mkdir($privateRoot, 0700, true) && ! is_dir($privateRoot)) {
            throw new \RuntimeException('Cannot create private storage root');
        }

        $tableBackupPath = app(SystemConfigService::class)->get('metadata_writeback.backup_path');
        $configuredPath = is_string($tableBackupPath) && trim($tableBackupPath) !== ''
            ? $tableBackupPath
            : config('metadata_writeback.backup_path');
        if (is_string($configuredPath) && str_starts_with($configuredPath, '/')) {
            throw new \RuntimeException('Configured private backup path must be relative to private storage');
        }
        $relativeRoot = is_string($configuredPath) && trim($configuredPath) !== ''
            ? trim($configuredPath, '/')
            : (function_exists('posix_geteuid') ? 'metadata-writeback-'.posix_geteuid() : null);
        if ($relativeRoot === null) {
            throw new \RuntimeException('Private backup directory requires a configured path or effective user ID');
        }
        if (str_starts_with($relativeRoot, '/')
            || preg_match('~(^|/)\.\.(/|$)|[^A-Za-z0-9._/-]~', $relativeRoot) === 1
            || in_array('', explode('/', $relativeRoot), true)
            || in_array('.', explode('/', $relativeRoot), true)) {
            throw new \RuntimeException('Configured private backup path is invalid');
        }

        $backupDirectory = $privateRoot;
        foreach (explode('/', $relativeRoot) as $segment) {
            $backupDirectory .= '/'.$segment;
            if (is_link($backupDirectory)) {
                throw new \RuntimeException('Private backup directory cannot contain symlinks');
            }
            if (! is_dir($backupDirectory) && ! mkdir($backupDirectory, 0700) && ! is_dir($backupDirectory)) {
                throw new \RuntimeException('Cannot create private backup directory');
            }
            if (! chmod($backupDirectory, 0700)) {
                throw new \RuntimeException('Cannot secure private backup directory');
            }
        }

        $backupPath = $backupDirectory.'/'.$sourceHash.'.original';
        $receiptPath = $backupDirectory.'/'.$sourceHash.'.json';
        if (is_link($backupPath) || is_link($receiptPath)) {
            throw new \RuntimeException('Private backup artifacts cannot be symlinks');
        }

        if (file_exists($backupPath) || file_exists($receiptPath)) {
            if (! is_file($backupPath) || ! is_file($receiptPath)
                || @hash_file('sha256', $backupPath) !== $sourceHash
                || @filesize($backupPath) !== $sourceSize) {
                throw new \RuntimeException('Existing private backup does not match the source');
            }
            $receipt = json_decode((string) @file_get_contents($receiptPath), true);
            if (! is_array($receipt)
                || ($receipt['source_sha256'] ?? null) !== $sourceHash
                || ($receipt['backup_sha256'] ?? null) !== $sourceHash
                || (int) ($receipt['source_size'] ?? -1) !== $sourceSize) {
                throw new \RuntimeException('Existing private backup receipt does not verify');
            }
            if (! chmod($backupPath, 0600) || ! chmod($receiptPath, 0600)
                || (fileperms($backupPath) & 0777) !== 0600
                || (fileperms($receiptPath) & 0777) !== 0600) {
                throw new \RuntimeException('Existing private backup permissions cannot be secured');
            }
        } else {
            $input = @fopen($sourcePath, 'rb');
            $output = @fopen($backupPath, 'xb');
            if ($input === false || $output === false) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }

                throw new \RuntimeException('Cannot open private original backup');
            }
            $createdBackup = true;
            $copied = stream_copy_to_stream($input, $output);
            fflush($output);
            fclose($input);
            fclose($output);
            if (! chmod($backupPath, 0600)) {
                @unlink($backupPath);

                throw new \RuntimeException('Cannot secure private original backup permissions');
            }
            if ($copied !== $sourceSize || @filesize($backupPath) !== $sourceSize
                || @hash_file('sha256', $backupPath) !== $sourceHash) {
                @unlink($backupPath);

                throw new \RuntimeException('Private original backup failed byte verification');
            }

            $relativeBackupPath = 'app/private/'.substr($backupPath, strlen($privateRoot) + 1);
            $receipt = [
                'source_path' => $sourcePath,
                'source_sha256' => $sourceHash,
                'backup_sha256' => $sourceHash,
                'source_size' => $sourceSize,
                'captured_at' => now()->toIso8601String(),
                'backup_path' => $relativeBackupPath,
            ];
            $receiptTemp = $receiptPath.'.tmp-'.bin2hex(random_bytes(6));
            $receiptBytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            $receiptStream = @fopen($receiptTemp, 'xb');
            if ($receiptStream === false) {
                @unlink($backupPath);

                throw new \RuntimeException('Cannot create private original backup receipt');
            }
            $written = 0;
            $receiptLength = strlen($receiptBytes);
            while ($written < $receiptLength) {
                $count = fwrite($receiptStream, substr($receiptBytes, $written));
                if ($count === false || $count === 0) {
                    break;
                }
                $written += $count;
            }
            fflush($receiptStream);
            fclose($receiptStream);
            if ($written !== $receiptLength) {
                @unlink($receiptTemp);
                @unlink($backupPath);

                throw new \RuntimeException('Cannot write private original backup receipt');
            }
            if (! chmod($receiptTemp, 0600)) {
                @unlink($receiptTemp);
                @unlink($backupPath);

                throw new \RuntimeException('Cannot secure private backup receipt permissions');
            }
            if (! @link($receiptTemp, $receiptPath)) {
                @unlink($receiptTemp);
                if ($createdBackup) {
                    @unlink($backupPath);
                }

                throw new \RuntimeException('Cannot finalize private original backup receipt');
            }
            @unlink($receiptTemp);
            chmod($receiptPath, 0600);
        }

        return [
            'backup_path' => 'app/private/'.substr($backupPath, strlen($privateRoot) + 1),
            'receipt_path' => 'app/private/'.substr($receiptPath, strlen($privateRoot) + 1),
        ];
    }

    private function cleanupStaleExiftoolTemps(string $tempPath): void
    {
        foreach ([$tempPath.'_exiftool_tmp', $tempPath.'_original'] as $candidate) {
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }
    }

    private function canCopyBackToFile(string $filePath): bool
    {
        $directory = dirname($filePath);

        if (is_writable($filePath) || ($directory !== '' && is_writable($directory))) {
            return true;
        }

        return $this->hasSudoCopyBackAccess();
    }

    private function hasSudoCopyBackAccess(): bool
    {
        if ($this->sudoCopyBackAvailable !== null) {
            return $this->sudoCopyBackAvailable;
        }

        $result = Process::timeout(5)->run(['sudo', '-n', '-l']);
        $output = strtolower(trim($result->output()."\n".$result->errorOutput()));

        $this->sudoCopyBackAvailable = $result->successful() && (
            str_contains($output, '/bin/cp') ||
            str_contains($output, ' cp ') ||
            str_contains($output, '(all) all')
        );

        return $this->sudoCopyBackAvailable;
    }

    private function metadataWritebackEnabled(): bool
    {
        return (bool) config('metadata_writeback.enabled', false)
            && (bool) config('metadata_writeback.in_place_enabled', false);
    }

    private function metadataWritebackDisabledResult(): array
    {
        return [
            'success' => false,
            'error' => 'Metadata writeback disabled. Set PLOS_METADATA_WRITEBACK_ENABLED=true and PLOS_METADATA_WRITEBACK_IN_PLACE=true only after operator approval.',
            'code' => -3,
        ];
    }

    private function permissionDeniedResult(string $filePath): array
    {
        $this->logWarningOncePerProcess('copy_back_permission_denied', 'ExifWriteback: copy back failed - permission denied', [
            'file' => basename($filePath),
            'hint' => 'Run script as www-data or add NOPASSWD sudo for cp',
        ]);

        return [
            'success' => false,
            'error' => 'Permission denied - run as www-data user',
            'code' => -2,
        ];
    }

    private function logWarningOncePerProcess(string $key, string $message, array $context = []): void
    {
        if (isset(self::$warningKeysLogged[$key])) {
            return;
        }

        self::$warningKeysLogged[$key] = true;

        Log::warning($message, $context);
    }
}
