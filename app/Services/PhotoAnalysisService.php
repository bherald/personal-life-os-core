<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Photo Analysis Service
 *
 * Provides deep analysis of image files for organization decisions.
 * Extracts EXIF metadata, face regions, keywords, and suggests organization.
 *
 * Integration Points:
 * - E13: File Catalog uses this for photo album analysis
 * - E23: PhotoWise face regions (MWG-rs format)
 * - E20: Links face names to GEDCOM genealogy persons
 *
 * Historical private backlog labels: E13, E20, E23.
 * @see docs/GEDCOM Analysis Reference Document.md
 * @see docs/EXIF-Photowise-Faces/
 */
class PhotoAnalysisService
{
    private NextcloudFileApiService $nextcloudApi;
    private ?ContentExtractionService $contentExtraction = null;

    // Common image extensions
    /** @see config/file_types.php */

    public function __construct(NextcloudFileApiService $nextcloudApi)
    {
        $this->nextcloudApi = $nextcloudApi;
    }

    /**
     * Set content extraction service (optional, for vision analysis)
     */
    public function setContentExtractionService(ContentExtractionService $service): void
    {
        $this->contentExtraction = $service;
    }

    /**
     * Analyze a folder of images (photo album analysis)
     *
     * Provides rich metadata to help with organization decisions.
     *
     * @param array $files List of file info arrays from Nextcloud
     * @param string $folderPath Base folder path
     * @return array Analysis results
     */
    public function analyzePhotoFolder(array $files, string $folderPath): array
    {
        $analysis = [
            'total_images' => 0,
            'with_exif' => 0,
            'with_gps' => 0,
            'with_faces' => 0,
            'face_count' => 0,
            'date_range' => null,
            'earliest_date' => null,
            'latest_date' => null,
            'locations' => [],
            'cameras' => [],
            'people' => [],
            'keywords' => [],
            'suggested_name' => null,
            'suggested_organization' => null,
            'images_by_year' => [],
            'images_by_month' => [],
            'sample_images' => [],
        ];

        $dates = [];
        $imageFiles = [];

        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file['path'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, array_merge(config('file_types.image'), config('file_types.image_raw')))) {
                continue;
            }

            $analysis['total_images']++;
            $imageFiles[] = $file;

            // Limit detailed analysis to first 50 images for performance
            if (count($imageFiles) <= 50) {
                $imageAnalysis = $this->analyzeImage($file['path'], $file);

                if (!empty($imageAnalysis['exif'])) {
                    $analysis['with_exif']++;

                    // Date analysis
                    if (!empty($imageAnalysis['exif']['date_taken'])) {
                        $dates[] = $imageAnalysis['exif']['date_taken'];
                    }

                    // GPS analysis
                    if (!empty($imageAnalysis['exif']['gps_latitude'])) {
                        $analysis['with_gps']++;
                        $location = $this->reverseGeocode(
                            $imageAnalysis['exif']['gps_latitude'],
                            $imageAnalysis['exif']['gps_longitude']
                        );
                        if ($location) {
                            $analysis['locations'][$location] = ($analysis['locations'][$location] ?? 0) + 1;
                        }
                    }

                    // Camera analysis
                    $camera = trim(($imageAnalysis['exif']['camera_make'] ?? '') . ' ' . ($imageAnalysis['exif']['camera_model'] ?? ''));
                    if ($camera) {
                        $analysis['cameras'][$camera] = ($analysis['cameras'][$camera] ?? 0) + 1;
                    }
                }

                // Face analysis (E23)
                if (!empty($imageAnalysis['faces'])) {
                    $analysis['with_faces']++;
                    $analysis['face_count'] += count($imageAnalysis['faces']);
                    foreach ($imageAnalysis['faces'] as $face) {
                        $name = $face['name'] ?? 'Unknown';
                        $analysis['people'][$name] = ($analysis['people'][$name] ?? 0) + 1;
                    }
                }

                // Keywords
                if (!empty($imageAnalysis['keywords'])) {
                    foreach ($imageAnalysis['keywords'] as $keyword) {
                        $analysis['keywords'][$keyword] = ($analysis['keywords'][$keyword] ?? 0) + 1;
                    }
                }

                // Sample images (first 5)
                if (count($analysis['sample_images']) < 5) {
                    $analysis['sample_images'][] = [
                        'path' => $file['path'],
                        'filename' => basename($file['path']),
                        'date' => $imageAnalysis['exif']['date_taken'] ?? null,
                        'has_faces' => !empty($imageAnalysis['faces']),
                    ];
                }
            }
        }

        // Process dates
        if (!empty($dates)) {
            sort($dates);
            $analysis['earliest_date'] = $dates[0];
            $analysis['latest_date'] = end($dates);
            $analysis['date_range'] = $this->formatDateRange($dates[0], end($dates));

            // Group by year/month
            foreach ($dates as $date) {
                $year = substr($date, 0, 4);
                $month = substr($date, 0, 7);
                $analysis['images_by_year'][$year] = ($analysis['images_by_year'][$year] ?? 0) + 1;
                $analysis['images_by_month'][$month] = ($analysis['images_by_month'][$month] ?? 0) + 1;
            }
            ksort($analysis['images_by_year']);
            ksort($analysis['images_by_month']);
        }

        // Sort by frequency
        arsort($analysis['locations']);
        arsort($analysis['cameras']);
        arsort($analysis['people']);
        arsort($analysis['keywords']);

        // Limit to top items
        $analysis['locations'] = array_slice($analysis['locations'], 0, 5, true);
        $analysis['cameras'] = array_slice($analysis['cameras'], 0, 3, true);
        $analysis['people'] = array_slice($analysis['people'], 0, 10, true);
        $analysis['keywords'] = array_slice($analysis['keywords'], 0, 15, true);

        // Generate suggestions
        $analysis['suggested_name'] = $this->suggestAlbumName($analysis, $folderPath);
        $analysis['suggested_organization'] = $this->suggestOrganization($analysis);

        // Try to link people to GEDCOM (E20)
        $analysis['genealogy_links'] = $this->linkToGenealogy($analysis['people']);

        return $analysis;
    }

    /**
     * Analyze a single image file
     *
     * @param string $path Image path
     * @param array|null $fileInfo Optional file info from Nextcloud
     * @return array Image analysis
     */
    public function analyzeImage(string $path, ?array $fileInfo = null): array
    {
        $analysis = [
            'path' => $path,
            'filename' => basename($path),
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'size' => $fileInfo['size'] ?? 0,
            'exif' => [],
            'faces' => [],
            'keywords' => [],
            'parsed_filename' => $this->parseFilename(basename($path)),
        ];

        // Only extract EXIF for supported formats
        if (in_array($analysis['extension'], config('file_types.exif'))) {
            $analysis['exif'] = $this->extractExifMetadata($path);
            $analysis['faces'] = $this->extractFaceRegions($path);
            $analysis['keywords'] = $this->extractKeywords($path);
        }

        return $analysis;
    }

    /**
     * Extract EXIF metadata from an image
     *
     * @param string $path Image path (Nextcloud path)
     * @return array EXIF data
     */
    private function extractExifMetadata(string $path): array
    {
        $exif = [];

        try {
            // Download file temporarily for EXIF extraction
            $tempFile = $this->downloadToTemp($path);
            if (!$tempFile) {
                return $exif;
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            // Use PHP's built-in exif_read_data for JPEG/TIFF
            if (in_array($extension, ['jpg', 'jpeg', 'tiff', 'tif'])) {
                $rawExif = @exif_read_data($tempFile, 'ANY_TAG', true);
                if ($rawExif) {
                    $exif = $this->normalizeExifData($rawExif);
                }
            }

            // Use ExifTool for more complete extraction
            $exiftoolData = $this->runExiftool($tempFile);
            if (!empty($exiftoolData)) {
                $exif = array_merge($exif, $exiftoolData);
            }

            // Clean up temp file
            @unlink($tempFile);

        } catch (\Exception $e) {
            Log::debug('PhotoAnalysis: EXIF extraction failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return $exif;
    }

    /**
     * Extract MWG face regions from image XMP metadata (E23)
     *
     * Many photo managers embed face data in XMP-mwg-rs format:
     * - RegionList contains face regions
     * - Each region has Name, Area (x, y, w, h normalized)
     *
     * @param string $path Image path
     * @return array Face data
     */
    private function extractFaceRegions(string $path): array
    {
        $faces = [];

        try {
            $tempFile = $this->downloadToTemp($path);
            if (!$tempFile) {
                return $faces;
            }

            // Use ExifTool to extract MWG regions
            $exiftoolPath = $this->getExiftoolPath();
            if (!$exiftoolPath) {
                @unlink($tempFile);
                return $faces;
            }

            // Extract XMP-mwg-rs data
            $result = Process::timeout(30)->run([
                $exiftoolPath,
                '-json',
                '-XMP-mwg-rs:all',
                $tempFile,
            ]);
            $output = $result->output();
            @unlink($tempFile);

            if (!$output) {
                return $faces;
            }

            $data = json_decode($output, true);
            if (!$data || !isset($data[0])) {
                return $faces;
            }

            // Parse region list
            $regions = $data[0]['RegionList'] ?? $data[0]['RegionInfo']['RegionList'] ?? [];

            foreach ($regions as $region) {
                if (isset($region['Name']) || isset($region['PersonDisplayName'])) {
                    $face = [
                        'name' => $region['Name'] ?? $region['PersonDisplayName'] ?? 'Unknown',
                        'type' => $region['Type'] ?? 'Face',
                    ];

                    // Extract area coordinates (normalized 0-1)
                    if (isset($region['Area'])) {
                        $area = $region['Area'];
                        $face['x'] = $area['X'] ?? $area['x'] ?? null;
                        $face['y'] = $area['Y'] ?? $area['y'] ?? null;
                        $face['w'] = $area['W'] ?? $area['w'] ?? null;
                        $face['h'] = $area['H'] ?? $area['h'] ?? null;
                    }

                    $faces[] = $face;
                }
            }

        } catch (\Exception $e) {
            Log::debug('PhotoAnalysis: Face extraction failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return $faces;
    }

    /**
     * Extract keywords/tags from image metadata
     *
     * @param string $path Image path
     * @return array Keywords
     */
    private function extractKeywords(string $path): array
    {
        $keywords = [];

        try {
            $tempFile = $this->downloadToTemp($path);
            if (!$tempFile) {
                return $keywords;
            }

            $exiftoolPath = $this->getExiftoolPath();
            if (!$exiftoolPath) {
                @unlink($tempFile);
                return $keywords;
            }

            // Extract keywords from multiple sources
            $result = Process::timeout(30)->run([
                $exiftoolPath,
                '-json',
                '-Keywords',
                '-Subject',
                '-HierarchicalSubject',
                '-XMP:Subject',
                $tempFile,
            ]);
            $output = $result->output();
            @unlink($tempFile);

            if (!$output) {
                return $keywords;
            }

            $data = json_decode($output, true);
            if (!$data || !isset($data[0])) {
                return $keywords;
            }

            // Merge all keyword sources
            foreach (['Keywords', 'Subject', 'HierarchicalSubject'] as $field) {
                if (isset($data[0][$field])) {
                    $values = is_array($data[0][$field]) ? $data[0][$field] : [$data[0][$field]];
                    $keywords = array_merge($keywords, $values);
                }
            }

            $keywords = array_unique(array_filter($keywords));

        } catch (\Exception $e) {
            Log::debug('PhotoAnalysis: Keywords extraction failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return $keywords;
    }

    /**
     * Parse filename for date/event hints
     *
     * Common patterns:
     * - IMG_20231225_143052.jpg (Android)
     * - DSC_1234.jpg (Camera)
     * - 2023-12-25 Christmas.jpg (Manual)
     * - Screenshot_2023-12-25.png
     *
     * @param string $filename Filename
     * @return array Parsed info
     */
    private function parseFilename(string $filename): array
    {
        $parsed = [
            'date' => null,
            'event' => null,
            'camera_prefix' => null,
        ];

        // Pattern: IMG_YYYYMMDD_HHMMSS or IMG-YYYYMMDD-HHMMSS
        if (preg_match('/(?:IMG|VID|PXL|MVIMG)[_-](\d{4})(\d{2})(\d{2})[_-](\d{2})(\d{2})(\d{2})/i', $filename, $m)) {
            $parsed['date'] = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
        }
        // Pattern: YYYY-MM-DD or YYYYMMDD at start
        elseif (preg_match('/^(\d{4})[-_]?(\d{2})[-_]?(\d{2})/', $filename, $m)) {
            $parsed['date'] = "{$m[1]}-{$m[2]}-{$m[3]}";
            // Check for event description after date
            $rest = preg_replace('/^\d{4}[-_]?\d{2}[-_]?\d{2}[-_\s]*/', '', pathinfo($filename, PATHINFO_FILENAME));
            if ($rest && strlen($rest) > 2) {
                $parsed['event'] = trim($rest);
            }
        }
        // Pattern: Screenshot_YYYY-MM-DD
        elseif (preg_match('/Screenshot[_-](\d{4})[-_](\d{2})[-_](\d{2})/i', $filename, $m)) {
            $parsed['date'] = "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        // Camera prefixes
        if (preg_match('/^(DSC|DSCN|DSCF|IMG|P\d+|SAM)[_-]/i', $filename, $m)) {
            $parsed['camera_prefix'] = strtoupper($m[1]);
        }

        return $parsed;
    }

    /**
     * Normalize raw EXIF data to useful fields
     */
    private function normalizeExifData(array $rawExif): array
    {
        $normalized = [];

        // Date/Time
        $dateFields = ['DateTimeOriginal', 'DateTime', 'DateTimeDigitized'];
        foreach ($dateFields as $field) {
            if (!empty($rawExif['EXIF'][$field])) {
                $normalized['date_taken'] = $rawExif['EXIF'][$field];
                break;
            }
        }

        // Camera info
        if (!empty($rawExif['IFD0']['Make'])) {
            $normalized['camera_make'] = trim($rawExif['IFD0']['Make']);
        }
        if (!empty($rawExif['IFD0']['Model'])) {
            $normalized['camera_model'] = trim($rawExif['IFD0']['Model']);
        }

        // GPS coordinates
        if (!empty($rawExif['GPS']['GPSLatitude']) && !empty($rawExif['GPS']['GPSLongitude'])) {
            $lat = $this->gpsToDecimal(
                $rawExif['GPS']['GPSLatitude'],
                $rawExif['GPS']['GPSLatitudeRef'] ?? 'N'
            );
            $lon = $this->gpsToDecimal(
                $rawExif['GPS']['GPSLongitude'],
                $rawExif['GPS']['GPSLongitudeRef'] ?? 'E'
            );
            if ($lat !== null && $lon !== null) {
                $normalized['gps_latitude'] = $lat;
                $normalized['gps_longitude'] = $lon;
            }
        }

        // Image dimensions
        if (!empty($rawExif['COMPUTED']['Width'])) {
            $normalized['width'] = $rawExif['COMPUTED']['Width'];
        }
        if (!empty($rawExif['COMPUTED']['Height'])) {
            $normalized['height'] = $rawExif['COMPUTED']['Height'];
        }

        // Orientation
        if (!empty($rawExif['IFD0']['Orientation'])) {
            $normalized['orientation'] = $rawExif['IFD0']['Orientation'];
        }

        return $normalized;
    }

    /**
     * Run ExifTool for advanced metadata extraction
     */
    private function runExiftool(string $filePath): array
    {
        $exiftoolPath = $this->getExiftoolPath();
        if (!$exiftoolPath) {
            return [];
        }

        try {
            $result = Process::timeout(30)->run([
                $exiftoolPath,
                '-json',
                '-DateTimeOriginal',
                '-CreateDate',
                '-GPSLatitude',
                '-GPSLongitude',
                '-Make',
                '-Model',
                '-ImageWidth',
                '-ImageHeight',
                '-Orientation',
                $filePath,
            ]);
            $output = $result->output();
            if (!$output) {
                return [];
            }

            $data = json_decode($output, true);
            if (!$data || !isset($data[0])) {
                return [];
            }

            $result = [];
            $d = $data[0];

            if (!empty($d['DateTimeOriginal'])) {
                $result['date_taken'] = $d['DateTimeOriginal'];
            } elseif (!empty($d['CreateDate'])) {
                $result['date_taken'] = $d['CreateDate'];
            }

            if (!empty($d['Make'])) $result['camera_make'] = trim($d['Make']);
            if (!empty($d['Model'])) $result['camera_model'] = trim($d['Model']);
            if (!empty($d['GPSLatitude'])) $result['gps_latitude'] = $this->parseExiftoolGps($d['GPSLatitude']);
            if (!empty($d['GPSLongitude'])) $result['gps_longitude'] = $this->parseExiftoolGps($d['GPSLongitude']);
            if (!empty($d['ImageWidth'])) $result['width'] = $d['ImageWidth'];
            if (!empty($d['ImageHeight'])) $result['height'] = $d['ImageHeight'];

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Convert GPS coordinates from EXIF format to decimal
     */
    private function gpsToDecimal(array $coordinate, string $ref): ?float
    {
        if (count($coordinate) !== 3) return null;

        $degrees = $this->exifFractionToFloat($coordinate[0]);
        $minutes = $this->exifFractionToFloat($coordinate[1]);
        $seconds = $this->exifFractionToFloat($coordinate[2]);

        if ($degrees === null) return null;

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        if (in_array(strtoupper($ref), ['S', 'W'])) {
            $decimal = -$decimal;
        }

        return round($decimal, 6);
    }

    /**
     * Convert EXIF fraction string to float
     */
    private function exifFractionToFloat($fraction): ?float
    {
        if (is_numeric($fraction)) return (float) $fraction;
        if (!is_string($fraction)) return null;

        $parts = explode('/', $fraction);
        if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && $parts[1] != 0) {
            return (float) $parts[0] / (float) $parts[1];
        }

        return null;
    }

    /**
     * Parse ExifTool GPS format (e.g., "41 deg 24' 32.40\" N")
     */
    private function parseExiftoolGps($value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        // Parse "41 deg 24' 32.40" N" format
        if (preg_match('/(-?[\d.]+)\s*deg\s*([\d.]+)\'\s*([\d.]+)"?\s*([NSEW])?/i', $value, $m)) {
            $decimal = abs((float)$m[1]) + ((float)$m[2] / 60) + ((float)$m[3] / 3600);
            if (isset($m[4]) && in_array(strtoupper($m[4]), ['S', 'W'])) {
                $decimal = -$decimal;
            }
            return round($decimal, 6);
        }

        return null;
    }

    /**
     * Reverse geocode GPS coordinates to a human-readable location string.
     * Uses Nominatim (OpenStreetMap) — max 1 req/sec per usage policy.
     * Returns the most specific available: "City, State, Country" or subset.
     */
    public function reverseGeocode(float $lat, float $lon): ?string
    {
        try {
            $url = sprintf(
                'https://nominatim.openstreetmap.org/reverse?format=json&lat=%s&lon=%s&zoom=10&addressdetails=1',
                $lat, $lon
            );

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_USERAGENT      => 'PLOS/3.7 (Personal Life OS; GPS enrichment)',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                Log::debug('PhotoAnalysis: reverseGeocode transport error', ['lat' => $lat, 'lon' => $lon, 'error' => $error]);
                return null;
            }

            if ($httpCode !== 200 || !$response) {
                return null;
            }

            $data = json_decode($response, true);
            if (empty($data['address'])) {
                return null;
            }

            $addr = $data['address'];
            $parts = array_filter([
                $addr['city']        ?? $addr['town']    ?? $addr['village'] ?? $addr['hamlet'] ?? null,
                $addr['state']       ?? $addr['region']  ?? null,
                $addr['country']     ?? null,
            ]);

            return implode(', ', $parts) ?: null;
        } catch (\Throwable $e) {
            Log::debug('PhotoAnalysis: reverseGeocode failed', ['lat' => $lat, 'lon' => $lon, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Format a date range for display
     */
    private function formatDateRange(string $start, string $end): string
    {
        $startDate = substr($start, 0, 10);
        $endDate = substr($end, 0, 10);

        if ($startDate === $endDate) {
            return $startDate;
        }

        // Same month
        if (substr($startDate, 0, 7) === substr($endDate, 0, 7)) {
            return substr($startDate, 0, 7);
        }

        // Same year
        if (substr($startDate, 0, 4) === substr($endDate, 0, 4)) {
            return substr($startDate, 0, 4);
        }

        return substr($startDate, 0, 4) . '-' . substr($endDate, 0, 4);
    }

    /**
     * Suggest an album name based on analysis
     */
    private function suggestAlbumName(array $analysis, string $folderPath): ?string
    {
        $parts = [];

        // Use date range
        if ($analysis['date_range']) {
            $parts[] = $analysis['date_range'];
        }

        // Use top location
        if (!empty($analysis['locations'])) {
            $topLocation = array_key_first($analysis['locations']);
            if ($topLocation) {
                $parts[] = $topLocation;
            }
        }

        // Use top people (if significant)
        if (!empty($analysis['people'])) {
            $topPeople = array_slice(array_keys($analysis['people']), 0, 2);
            if (!empty($topPeople)) {
                $parts[] = implode(' & ', $topPeople);
            }
        }

        // Fallback to folder name
        if (empty($parts)) {
            return basename($folderPath);
        }

        return implode(' - ', $parts);
    }

    /**
     * Suggest organization strategy
     */
    private function suggestOrganization(array $analysis): array
    {
        $suggestions = [];

        // Suggest by year if spanning multiple years
        if (count($analysis['images_by_year'] ?? []) > 1) {
            $suggestions[] = [
                'strategy' => 'by_year',
                'description' => 'Split into year-based subfolders',
                'folders' => array_keys($analysis['images_by_year']),
            ];
        }

        // Suggest by person if many faces detected
        if (count($analysis['people'] ?? []) > 3) {
            $suggestions[] = [
                'strategy' => 'by_person',
                'description' => 'Create person-tagged collections',
                'people' => array_keys($analysis['people']),
            ];
        }

        // Suggest keeping together if cohesive
        if (count($analysis['images_by_year'] ?? []) <= 1 && !empty($analysis['date_range'])) {
            $suggestions[] = [
                'strategy' => 'keep_together',
                'description' => 'Keep as single album - cohesive date range',
            ];
        }

        return $suggestions;
    }

    /**
     * Link detected people to GEDCOM genealogy persons (E20)
     *
     * Searches for matching names in the genealogy database.
     *
     * @param array $people Person names and counts
     * @return array Links to genealogy records
     */
    private function linkToGenealogy(array $people): array
    {
        $links = [];

        if (empty($people)) {
            return $links;
        }

        try {
            // Check if genealogy tables exist
            $hasGenealogyTable = DB::select("SHOW TABLES LIKE 'genealogy_persons'");
            if (empty($hasGenealogyTable)) {
                return $links;
            }

            foreach (array_keys($people) as $name) {
                // Split name into parts
                $nameParts = preg_split('/\s+/', trim($name));
                if (count($nameParts) < 2) {
                    continue;
                }

                $firstName = $nameParts[0];
                $lastName = end($nameParts);

                // Search for matching person in genealogy
                $matches = DB::select("
                    SELECT gedcom_id, given_name, surname, birth_date, death_date
                    FROM genealogy_persons
                    WHERE given_name LIKE ? AND surname LIKE ?
                    LIMIT 3
                ", ["%{$firstName}%", "%{$lastName}%"]);

                if (!empty($matches)) {
                    $links[$name] = array_map(fn($m) => [
                        'gedcom_id' => $m->gedcom_id,
                        'full_name' => trim($m->given_name . ' ' . $m->surname),
                        'birth' => $m->birth_date,
                        'death' => $m->death_date,
                    ], $matches);
                }
            }
        } catch (\Exception $e) {
            // Genealogy table doesn't exist yet, that's OK
            Log::debug('PhotoAnalysis: Genealogy lookup skipped', ['reason' => $e->getMessage()]);
        }

        return $links;
    }

    /**
     * Download a file from Nextcloud to a temporary location.
     * Uses filesystem-first copy when NEXTCLOUD_DATA_PATH is configured.
     */
    private function downloadToTemp(string $path): ?string
    {
        try {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $tempFile = tempnam(sys_get_temp_dir(), 'photo_');
            if ($ext) {
                $newTempFile = $tempFile . '.' . $ext;
                rename($tempFile, $newTempFile);
                $tempFile = $newTempFile;
            }

            // Filesystem-first: avoid WebDAV when local path available
            $localFsPath = $this->nextcloudApi->localPath($path);
            if ($localFsPath) {
                if (!copy($localFsPath, $tempFile)) {
                    Log::debug('PhotoAnalysis: Failed to copy local file', ['path' => $path]);
                    return null;
                }
                return $tempFile;
            }

            $result = $this->nextcloudApi->downloadFile($path);
            if (!($result['success'] ?? false) || empty($result['content'])) {
                Log::debug('PhotoAnalysis: Download failed', ['path' => $path, 'error' => $result['error'] ?? 'No content']);
                @unlink($tempFile);
                return null;
            }

            file_put_contents($tempFile, $result['content']);
            return $tempFile;
        } catch (\Exception $e) {
            Log::debug('PhotoAnalysis: Failed to download file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get ExifTool path
     */
    private function getExiftoolPath(): ?string
    {
        // Check common locations
        $paths = [
            '/usr/bin/exiftool',
            '/usr/local/bin/exiftool',
            'exiftool', // In PATH
        ];

        foreach ($paths as $path) {
            if ($path === 'exiftool') {
                $check = trim(Process::timeout(5)->run(['which', 'exiftool'])->output());
                if ($check !== '' && file_exists($check) && is_executable($check)) {
                    return $check;
                }
            } elseif (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Check if a file is an image based on extension
     */
    public function isImage(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, array_merge(config('file_types.image'), config('file_types.image_raw')));
    }

    /**
     * Get image extensions list
     */
    public function getImageExtensions(): array
    {
        return array_merge(config('file_types.image'), config('file_types.image_raw'));
    }
}
