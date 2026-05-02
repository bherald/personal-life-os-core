<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

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
        float $confidence
    ): array {
        if (! $this->metadataWritebackEnabled()) {
            return $this->metadataWritebackDisabledResult();
        }

        if (! file_exists($filePath)) {
            return ['success' => false, 'error' => 'File not found'];
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

        // EXIF format: YYYY:MM:DD HH:MM:SS
        $exifFormatted = $dt->format('Y:m:d H:i:s');
        // IPTC date: YYYYMMDD, IPTC time: HHMMSS+offset
        $iptcDate = $dt->format('Ymd');
        $iptcTime = $dt->format('His').'+0000';

        $confidencePct = round($confidence * 100);
        $note = "Est. original: {$exifFormatted} ({$confidencePct}% confidence, {$source}). Scan/digitization date preserved in DateTimeOriginal.";

        $args = ['-overwrite_original', '-preserve'];

        // Estimated original photo date → XMP (Lightroom/Apple Photos) and IPTC (archival)
        $args[] = "-XMP-photoshop:DateCreated=$exifFormatted";
        $args[] = "-IPTC:DateCreated=$iptcDate";
        $args[] = "-IPTC:TimeCreated=$iptcTime";

        // Move scan date to DateTimeDigitized (the correct EXIF field for digitization date)
        if ($scanDate) {
            try {
                $scanExif = (new \DateTime($scanDate))->format('Y:m:d H:i:s');
                $args[] = "-DateTimeDigitized=$scanExif";
            } catch (\Exception $e) {
                Log::debug('ExifWritebackService: scan date formatting failed, skipping DateTimeDigitized', ['error' => $e->getMessage()]);
            }
        }

        // Human-readable note explaining the provenance
        $args[] = "-UserComment=$note";

        $result = $this->runExiftool($filePath, $args);

        if ($result['success']) {
            Log::info('ExifWriteback: Scan estimated date written', [
                'file' => basename($filePath),
                'estimated' => $exifFormatted,
                'scan_date' => $scanDate,
                'confidence' => $confidencePct.'%',
                'source' => $source,
            ]);
        }

        return $result;
    }

    /**
     * Write date metadata to file
     */
    public function writeDate(string $filePath, string $dateTaken, string $source, float $confidence): array
    {
        if (! $this->metadataWritebackEnabled()) {
            return $this->metadataWritebackDisabledResult();
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

        $result = $this->runExiftool($filePath, $args);

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

        if (empty($faces)) {
            return ['success' => true, 'message' => 'No faces to write'];
        }

        // Get image dimensions for region calculations
        $dimensions = $this->getImageDimensions($filePath);
        if (! $dimensions) {
            return ['success' => false, 'error' => 'Could not read image dimensions'];
        }

        $args = ['-overwrite_original', '-preserve'];

        // Collect person names for PersonInImage tag
        $personNames = [];

        // Build MWG region list for face boxes
        $regionJson = $this->buildMwgRegions($faces, $dimensions, $personNames);

        // Add PersonInImage for simple face list (widely supported)
        if (! empty($personNames)) {
            foreach ($personNames as $name) {
                $args[] = "-XMP-iptcExt:PersonInImage+=$name";
            }
        }

        // Write MWG regions if we have face coordinates
        if ($regionJson) {
            // Write region info using exiftool's JSON format
            $tempFile = tempnam(sys_get_temp_dir(), 'mwg_');
            file_put_contents($tempFile, $regionJson);
            $args[] = "-json=$tempFile";
        }

        $result = $this->runExiftool($filePath, $args);

        // Cleanup temp file
        if (isset($tempFile) && file_exists($tempFile)) {
            unlink($tempFile);
        }

        if ($result['success']) {
            Log::info('ExifWriteback: Faces written', [
                'file' => basename($filePath),
                'face_count' => count($faces),
                'named' => count($personNames),
            ]);
        }

        return $result;
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
     * Write GPS-derived location to IPTC:City, Province-State, Country-PrimaryLocation.
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
            '-IPTC:Country-PrimaryLocation',
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
        if ($country && empty($existing['Country-PrimaryLocation'])) {
            $args[] = '-IPTC:Country-PrimaryLocation='.$country;
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
            SELECT date_taken, date_taken_source, date_taken_confidence,
                   ai_description, ai_document_type, gps_location
            FROM file_registry
            WHERE id = ?
        ', [$fileId]);

        if (! $file) {
            return ['success' => false, 'error' => 'File not found in registry'];
        }

        // Write date if available and not from EXIF (avoid overwriting original)
        if ($file->date_taken && $file->date_taken_source && ! str_starts_with($file->date_taken_source, 'exif_')) {
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

        // Get and write faces
        $faces = DB::select('
            SELECT person_name, genealogy_person_id, region_x, region_y, region_w, region_h, confidence
            FROM file_registry_faces
            WHERE file_registry_id = ?
        ', [$fileId]);

        if (! empty($faces)) {
            // Enrich with genealogy names if linked
            foreach ($faces as &$face) {
                if ($face->genealogy_person_id && ! $face->person_name) {
                    $person = DB::selectOne("
                        SELECT CONCAT(given_name, ' ', surname) as name
                        FROM genealogy_persons
                        WHERE id = ?
                    ", [$face->genealogy_person_id]);
                    if ($person) {
                        $face->person_name = $person->name;
                    }
                }
            }

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
            AND date_taken_source NOT LIKE 'exif_%'
            AND (exif_written IS NULL OR exif_written = 0)
            AND extension IN ('jpg', 'jpeg', 'png', 'tiff', 'webp', 'heic')
            AND date_taken_confidence >= 0.3
        ")->count ?? 0;

        // Files with faces that haven't been written
        $facesPending = DB::selectOne("
            SELECT COUNT(DISTINCT fr.id) as count
            FROM file_registry fr
            INNER JOIN file_registry_faces ff ON ff.file_registry_id = fr.id
            WHERE (fr.exif_faces_written IS NULL OR fr.exif_faces_written = 0)
            AND fr.extension IN ('jpg', 'jpeg', 'png', 'tiff', 'webp', 'heic')
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
    private function runExiftool(string $filePath, array $args): array
    {
        if (! $this->metadataWritebackEnabled()) {
            return $this->metadataWritebackDisabledResult();
        }

        // Create temp copy to handle permission issues
        $tempPath = sys_get_temp_dir().'/exif_'.uniqid().'_'.basename($filePath);
        $this->cleanupStaleExiftoolTemps($tempPath);

        if (! copy($filePath, $tempPath)) {
            return [
                'success' => false,
                'error' => 'Failed to create temp copy',
            ];
        }

        // Build command - use temp file
        $command = array_merge([$this->exiftoolPath], $args, [$tempPath]);
        $result = Process::timeout(60)->run($command);
        $returnCode = $result->exitCode();
        $outputStr = trim($result->output()."\n".$result->errorOutput());

        if ($returnCode !== 0) {
            $this->cleanupStaleExiftoolTemps($tempPath);
            @unlink($tempPath);
            $this->logWarningOncePerProcess('exiftool_error:'.md5((string) $returnCode.'|'.$outputStr), 'ExifWriteback: exiftool error', [
                'file' => basename($filePath),
                'code' => $returnCode,
                'output' => $outputStr,
            ]);

            return [
                'success' => false,
                'error' => $outputStr,
                'code' => $returnCode,
            ];
        }

        // Try to copy modified file back
        // First try direct copy (works if running as www-data or have write permissions)
        $copySuccess = @copy($tempPath, $filePath);

        if (! $copySuccess) {
            // Try sudo cp (requires NOPASSWD in sudoers)
            $copyResult = Process::timeout(30)->run(['sudo', '-n', 'cp', $tempPath, $filePath]);
            $copySuccess = $copyResult->successful();

            if (! $copySuccess) {
                $this->cleanupStaleExiftoolTemps($tempPath);
                @unlink($tempPath);

                return $this->permissionDeniedResult($filePath);
            }
        }

        $this->cleanupStaleExiftoolTemps($tempPath);
        @unlink($tempPath);

        return [
            'success' => true,
            'output' => $outputStr,
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
