<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * FaceRegionService - MWG Face Region CRUD Operations (E23)
 *
 * Provides read/write operations for MWG-rs (Metadata Working Group) face regions
 * embedded in image XMP metadata. Compatible with PhotoPrism, digiKam, Lightroom,
 * and other tools that support the MWG-rs standard.
 * This service is a PLOS implementation of the metadata standard; it does not
 * use PhotoPrism, LibrePhotos, digiKam, or Lightroom source code.
 *
 * MWG-rs Format:
 * - Regions stored in XMP-mwg-rs namespace
 * - Coordinates normalized (0-1 range) relative to image dimensions
 * - x, y represent center point of face region
 * - w, h represent width and height as fraction of image
 *
 * @see https://www.metadataworkinggroup.org/specs/
 * @see docs/future-enhancements.md E23
 */
class FaceRegionService
{
    protected const EXIFTOOL_PATHS = [
        '/usr/bin/exiftool',
        '/usr/local/bin/exiftool',
    ];

    /** Default max retry attempts for transient failures */
    protected const DEFAULT_MAX_RETRIES = 3;

    /** Base delay for exponential backoff in milliseconds */
    protected const BASE_RETRY_DELAY_MS = 500;

    /**
     * Read face regions from an image file
     *
     * @param  string  $imagePath  Path to image file
     * @return array Array of face regions with name, x, y, w, h
     */
    public function readFaceRegions(string $imagePath): array
    {
        if (! file_exists($imagePath)) {
            Log::warning('FaceRegionService: File not found', ['path' => $imagePath]);

            return [];
        }

        $exiftoolPath = $this->findExiftoolPath();
        if (! $exiftoolPath) {
            Log::warning('FaceRegionService: ExifTool not found');

            return [];
        }

        try {
            // First try structured Regions format
            $result = Process::timeout(30)->run([
                $exiftoolPath,
                '-json',
                '-struct',
                '-XMP-mwg-rs:Regions',
                $imagePath,
            ]);

            if ($result->successful()) {
                $output = $result->output();
                if (! empty($output)) {
                    $data = json_decode($output, true);
                    if ($data && isset($data[0])) {
                        $regions = $this->parseRegions($data[0]);
                        if (! empty($regions)) {
                            return $regions;
                        }
                    }
                }
            }

            // Fallback: Try the ExifTool flat-array representation.
            $result = Process::timeout(30)->run([
                $exiftoolPath,
                '-json',
                '-XMP-mwg-rs:all',
                $imagePath,
            ]);

            if (! $result->successful()) {
                Log::debug('FaceRegionService: No regions found or ExifTool error', [
                    'path' => $imagePath,
                    'error' => $result->errorOutput(),
                ]);

                return [];
            }

            $output = $result->output();
            if (empty($output)) {
                return [];
            }

            $data = json_decode($output, true);
            if (! $data || ! isset($data[0])) {
                return [];
            }

            return $this->parseFlatRegions($data[0]);

        } catch (\Exception $e) {
            Log::error('FaceRegionService: Read failed', [
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Write face regions to an image file
     *
     * @param  string  $imagePath  Path to image file
     * @param  array  $regions  Array of regions: [['name' => 'John', 'x' => 0.5, 'y' => 0.3, 'w' => 0.2, 'h' => 0.25], ...]
     * @return bool Success
     */
    public function writeFaceRegions(string $imagePath, array $regions): bool
    {
        if (! $this->metadataWritebackEnabled()) {
            Log::info('FaceRegionService: metadata writeback disabled');

            return false;
        }

        if (! file_exists($imagePath)) {
            Log::warning('FaceRegionService: File not found for write', ['path' => $imagePath]);

            return false;
        }

        $exiftoolPath = $this->findExiftoolPath();
        if (! $exiftoolPath) {
            Log::warning('FaceRegionService: ExifTool not found');

            return false;
        }

        try {
            // Get image dimensions for AppliedToDimensions
            $dimensions = $this->getImageDimensions($imagePath);
            if (! $dimensions) {
                Log::warning('FaceRegionService: Could not get image dimensions', ['path' => $imagePath]);

                return false;
            }

            // Build the MWG-rs region structure
            $regionList = [];
            foreach ($regions as $region) {
                if (empty($region['name'])) {
                    continue;
                }

                $regionList[] = [
                    'Area' => [
                        'X' => $region['x'] ?? 0.5,
                        'Y' => $region['y'] ?? 0.5,
                        'W' => $region['w'] ?? 0.1,
                        'H' => $region['h'] ?? 0.1,
                        'Unit' => 'normalized',
                    ],
                    'Name' => $region['name'],
                    'Type' => $region['type'] ?? 'Face',
                ];
            }

            if (empty($regionList)) {
                Log::debug('FaceRegionService: No valid regions to write');

                return true; // Nothing to write is not an error
            }

            // Create the full Regions structure
            $regionsStruct = [
                'AppliedToDimensions' => [
                    'W' => $dimensions['width'],
                    'H' => $dimensions['height'],
                    'Unit' => 'pixel',
                ],
                'RegionList' => $regionList,
            ];

            // Write to temp JSON file for ExifTool
            $jsonFile = tempnam(sys_get_temp_dir(), 'face_regions_').'.json';
            file_put_contents($jsonFile, json_encode([$regionsStruct], JSON_PRETTY_PRINT));

            // Write regions using ExifTool
            $result = Process::timeout(60)->run([
                $exiftoolPath,
                '-overwrite_original',
                '-XMP-mwg-rs:Regions<='.$jsonFile,
                $imagePath,
            ]);

            @unlink($jsonFile);

            if ($result->successful()) {
                Log::info('FaceRegionService: Wrote face regions', [
                    'path' => $imagePath,
                    'count' => count($regionList),
                ]);

                return true;
            } else {
                // Alternative approach: write each field separately
                return $this->writeRegionsFallback($imagePath, $regions, $dimensions, $exiftoolPath);
            }

        } catch (\Exception $e) {
            Log::error('FaceRegionService: Write failed', [
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Fallback method to write regions using individual ExifTool commands
     */
    protected function writeRegionsFallback(string $imagePath, array $regions, array $dimensions, string $exiftoolPath): bool
    {
        try {
            // First, clear existing regions
            Process::timeout(30)->run([
                $exiftoolPath,
                '-overwrite_original',
                '-XMP-mwg-rs:all=',
                $imagePath,
            ]);

            // Build ExifTool arguments for MWG-rs
            $args = [
                $exiftoolPath,
                '-overwrite_original',
                '-XMP-mwg-rs:RegionAppliedToDimensionsW='.$dimensions['width'],
                '-XMP-mwg-rs:RegionAppliedToDimensionsH='.$dimensions['height'],
                '-XMP-mwg-rs:RegionAppliedToDimensionsUnit=pixel',
            ];

            // Add each region
            foreach ($regions as $i => $region) {
                if (empty($region['name'])) {
                    continue;
                }

                // ExifTool list index syntax
                $idx = $i;
                $args[] = sprintf('-XMP-mwg-rs:RegionAreaX[%d]=%s', $idx, $region['x'] ?? 0.5);
                $args[] = sprintf('-XMP-mwg-rs:RegionAreaY[%d]=%s', $idx, $region['y'] ?? 0.5);
                $args[] = sprintf('-XMP-mwg-rs:RegionAreaW[%d]=%s', $idx, $region['w'] ?? 0.1);
                $args[] = sprintf('-XMP-mwg-rs:RegionAreaH[%d]=%s', $idx, $region['h'] ?? 0.1);
                $args[] = sprintf('-XMP-mwg-rs:RegionAreaUnit[%d]=normalized', $idx);
                $args[] = sprintf('-XMP-mwg-rs:RegionName[%d]=%s', $idx, $region['name']);
                $args[] = sprintf('-XMP-mwg-rs:RegionType[%d]=%s', $idx, $region['type'] ?? 'Face');
            }

            $args[] = $imagePath;

            $result = Process::timeout(60)->run($args);

            if ($result->successful()) {
                Log::info('FaceRegionService: Wrote face regions (fallback method)', [
                    'path' => $imagePath,
                    'count' => count($regions),
                ]);

                return true;
            }

            Log::warning('FaceRegionService: Fallback write also failed', [
                'path' => $imagePath,
                'error' => $result->errorOutput(),
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('FaceRegionService: Fallback write error', [
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Add a single face region to an image
     *
     * @param  string  $imagePath  Path to image file
     * @param  array  $region  Region data: ['name' => 'John', 'x' => 0.5, 'y' => 0.3, 'w' => 0.2, 'h' => 0.25]
     * @return bool Success
     */
    public function addFaceRegion(string $imagePath, array $region): bool
    {
        // Read existing regions
        $existingRegions = $this->readFaceRegions($imagePath);

        // Add new region
        $existingRegions[] = $region;

        // Write all regions back
        return $this->writeFaceRegions($imagePath, $existingRegions);
    }

    /**
     * Remove a face region by name
     *
     * @param  string  $imagePath  Path to image file
     * @param  string  $name  Name to remove
     * @return bool Success
     */
    public function removeFaceRegion(string $imagePath, string $name): bool
    {
        $regions = $this->readFaceRegions($imagePath);

        // Filter out regions with matching name
        $filtered = array_filter($regions, fn ($r) => ($r['name'] ?? '') !== $name);

        if (count($filtered) === count($regions)) {
            Log::debug('FaceRegionService: Region not found for removal', [
                'path' => $imagePath,
                'name' => $name,
            ]);

            return true; // Nothing to remove
        }

        // Re-index array
        $filtered = array_values($filtered);

        return $this->writeFaceRegions($imagePath, $filtered);
    }

    /**
     * Update a face region by name
     *
     * @param  string  $imagePath  Path to image file
     * @param  string  $name  Name to update
     * @param  array  $updates  Updates to apply: ['x' => 0.5, 'y' => 0.4, 'w' => 0.15, 'h' => 0.2, 'name' => 'New Name']
     * @return bool Success
     */
    public function updateFaceRegion(string $imagePath, string $name, array $updates): bool
    {
        $regions = $this->readFaceRegions($imagePath);

        $found = false;
        foreach ($regions as &$region) {
            if (($region['name'] ?? '') === $name) {
                $region = array_merge($region, $updates);
                $found = true;
                break;
            }
        }

        if (! $found) {
            Log::debug('FaceRegionService: Region not found for update', [
                'path' => $imagePath,
                'name' => $name,
            ]);

            return false;
        }

        return $this->writeFaceRegions($imagePath, $regions);
    }

    /**
     * Clear all face regions from an image
     *
     * @param  string  $imagePath  Path to image file
     * @return bool Success
     */
    public function clearFaceRegions(string $imagePath): bool
    {
        if (! $this->metadataWritebackEnabled()) {
            Log::info('FaceRegionService: metadata writeback disabled');

            return false;
        }

        $exiftoolPath = $this->findExiftoolPath();
        if (! $exiftoolPath) {
            return false;
        }

        try {
            $result = Process::timeout(30)->run([
                $exiftoolPath,
                '-overwrite_original',
                '-XMP-mwg-rs:all=',
                $imagePath,
            ]);

            return $result->successful();

        } catch (\Exception $e) {
            Log::error('FaceRegionService: Clear failed', [
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get face region count for an image
     *
     * @param  string  $imagePath  Path to image file
     * @return int Number of face regions
     */
    public function getFaceCount(string $imagePath): int
    {
        return count($this->readFaceRegions($imagePath));
    }

    /**
     * Get all person names in an image
     *
     * @param  string  $imagePath  Path to image file
     * @return array List of names
     */
    public function getPersonNames(string $imagePath): array
    {
        $regions = $this->readFaceRegions($imagePath);

        return array_filter(array_column($regions, 'name'));
    }

    /**
     * Check if a person is in an image
     *
     * @param  string  $imagePath  Path to image file
     * @param  string  $name  Person name
     * @return bool Whether the person is in the image
     */
    public function hasPersonInImage(string $imagePath, string $name): bool
    {
        $names = $this->getPersonNames($imagePath);

        return in_array($name, $names, true);
    }

    /**
     * Parse flat array format regions (ExifTool flat-array representation)
     * Handles data like: RegionAreaX: [0.45, 0.36], RegionName: ["Name1", "Name2"]
     */
    protected function parseFlatRegions(array $data): array
    {
        $regions = [];

        // Get the arrays - try both with and without prefix
        $names = $data['RegionName'] ?? $data['XMP:RegionName'] ?? [];
        $xCoords = $data['RegionAreaX'] ?? $data['XMP:RegionAreaX'] ?? [];
        $yCoords = $data['RegionAreaY'] ?? $data['XMP:RegionAreaY'] ?? [];
        $widths = $data['RegionAreaW'] ?? $data['XMP:RegionAreaW'] ?? [];
        $heights = $data['RegionAreaH'] ?? $data['XMP:RegionAreaH'] ?? [];
        $types = $data['RegionType'] ?? $data['XMP:RegionType'] ?? [];

        // Ensure arrays
        if (! is_array($names)) {
            $names = [$names];
        }
        if (! is_array($xCoords)) {
            $xCoords = [$xCoords];
        }
        if (! is_array($yCoords)) {
            $yCoords = [$yCoords];
        }
        if (! is_array($widths)) {
            $widths = [$widths];
        }
        if (! is_array($heights)) {
            $heights = [$heights];
        }
        if (! is_array($types)) {
            $types = [$types];
        }

        $count = count($names);

        for ($i = 0; $i < $count; $i++) {
            $name = $names[$i] ?? null;

            // Skip unnamed or "Unknown" regions
            if (empty($name) || $name === 'Unknown') {
                continue;
            }

            $regions[] = [
                'name' => $name,
                'type' => $types[$i] ?? 'Face',
                'x' => $this->normalizeCoordinate($xCoords[$i] ?? null),
                'y' => $this->normalizeCoordinate($yCoords[$i] ?? null),
                'w' => $this->normalizeCoordinate($widths[$i] ?? null),
                'h' => $this->normalizeCoordinate($heights[$i] ?? null),
            ];
        }

        return $regions;
    }

    /**
     * Parse regions from ExifTool output
     */
    protected function parseRegions(array $data): array
    {
        $regions = [];

        // Try different possible structures
        $regionData = $data['Regions'] ?? $data['RegionInfo'] ?? null;

        if (! $regionData) {
            return $regions;
        }

        $regionList = $regionData['RegionList'] ?? $regionData ?? [];

        if (! is_array($regionList)) {
            return $regions;
        }

        foreach ($regionList as $region) {
            $parsed = [
                'name' => null,
                'type' => 'Face',
                'x' => null,
                'y' => null,
                'w' => null,
                'h' => null,
            ];

            // Get name (try multiple field names)
            $parsed['name'] = $region['Name']
                ?? $region['PersonDisplayName']
                ?? $region['name']
                ?? null;

            // Get type
            $parsed['type'] = $region['Type'] ?? $region['type'] ?? 'Face';

            // Get area coordinates
            $area = $region['Area'] ?? $region['area'] ?? $region;
            if (is_array($area)) {
                $parsed['x'] = $this->normalizeCoordinate($area['X'] ?? $area['x'] ?? $area['CenterX'] ?? null);
                $parsed['y'] = $this->normalizeCoordinate($area['Y'] ?? $area['y'] ?? $area['CenterY'] ?? null);
                $parsed['w'] = $this->normalizeCoordinate($area['W'] ?? $area['w'] ?? $area['Width'] ?? null);
                $parsed['h'] = $this->normalizeCoordinate($area['H'] ?? $area['h'] ?? $area['Height'] ?? null);
            }

            // Only add if we have a name
            if ($parsed['name']) {
                $regions[] = $parsed;
            }
        }

        return $regions;
    }

    /**
     * Normalize a coordinate value to float
     */
    protected function normalizeCoordinate($value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        // Handle fraction strings like "1/2"
        if (is_string($value) && str_contains($value, '/')) {
            $parts = explode('/', $value);
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && $parts[1] != 0) {
                return (float) $parts[0] / (float) $parts[1];
            }
        }

        return null;
    }

    /**
     * Get image dimensions
     */
    protected function getImageDimensions(string $imagePath): ?array
    {
        // Try PHP getimagesize first
        $size = @getimagesize($imagePath);
        if ($size && isset($size[0], $size[1])) {
            return ['width' => $size[0], 'height' => $size[1]];
        }

        // Try ExifTool for HEIC and other formats
        $exiftoolPath = $this->findExiftoolPath();
        if ($exiftoolPath) {
            try {
                $result = Process::timeout(10)->run([
                    $exiftoolPath,
                    '-json',
                    '-ImageWidth',
                    '-ImageHeight',
                    $imagePath,
                ]);

                if ($result->successful()) {
                    $data = json_decode($result->output(), true);
                    if ($data && isset($data[0]['ImageWidth'], $data[0]['ImageHeight'])) {
                        return [
                            'width' => (int) $data[0]['ImageWidth'],
                            'height' => (int) $data[0]['ImageHeight'],
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::debug('FaceRegionService: Could not get dimensions via ExifTool');
            }
        }

        return null;
    }

    /**
     * Find ExifTool executable
     */
    protected function findExiftoolPath(): ?string
    {
        foreach (self::EXIFTOOL_PATHS as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try which
        try {
            $result = Process::timeout(5)->run(['which', 'exiftool']);
            if ($result->successful()) {
                $path = trim($result->output());
                if (! empty($path) && file_exists($path) && is_executable($path)) {
                    return $path;
                }
            }
        } catch (\Exception $e) {
            Log::debug('FaceRegionService: exiftool path lookup failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Check if ExifTool is available
     */
    public function isAvailable(): bool
    {
        return $this->findExiftoolPath() !== null;
    }

    /**
     * Get service status
     */
    public function getStatus(): array
    {
        return [
            'available' => $this->isAvailable(),
            'exiftool_path' => $this->findExiftoolPath(),
            'metadata_writeback_enabled' => $this->metadataWritebackEnabled(),
            'version' => 'v1.1',
        ];
    }

    protected function metadataWritebackEnabled(): bool
    {
        return (bool) config('metadata_writeback.enabled', false)
            && (bool) config('metadata_writeback.in_place_enabled', false);
    }

    /**
     * Write face regions with retry logic and exponential backoff
     *
     * @param  string  $imagePath  Path to image file
     * @param  array  $regions  Array of regions
     * @param  int  $maxRetries  Maximum retry attempts (default: 3)
     * @return array Result with success status and error details
     */
    public function writeFaceRegionsWithRetry(string $imagePath, array $regions, int $maxRetries = self::DEFAULT_MAX_RETRIES): array
    {
        $lastError = null;
        $attempts = 0;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $attempts++;

            try {
                $success = $this->writeFaceRegions($imagePath, $regions);

                if ($success) {
                    Log::info('FaceRegionService: Write succeeded', [
                        'path' => $imagePath,
                        'attempts' => $attempts,
                        'regions_count' => count($regions),
                    ]);

                    return [
                        'success' => true,
                        'attempts' => $attempts,
                        'regions_written' => count($regions),
                    ];
                }

                $lastError = 'Write operation returned false';

            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                Log::warning('FaceRegionService: Write attempt failed', [
                    'path' => $imagePath,
                    'attempt' => $attempt + 1,
                    'max_retries' => $maxRetries,
                    'error' => $lastError,
                ]);
            }

            // Don't sleep after the last attempt
            if ($attempt < $maxRetries) {
                $delayMs = self::BASE_RETRY_DELAY_MS * pow(2, $attempt);
                usleep($delayMs * 1000);
            }
        }

        Log::error('FaceRegionService: All write attempts failed', [
            'path' => $imagePath,
            'total_attempts' => $attempts,
            'last_error' => $lastError,
        ]);

        return [
            'success' => false,
            'attempts' => $attempts,
            'error' => $lastError,
        ];
    }

    /**
     * Get detailed write operation result for debugging
     *
     * @param  string  $imagePath  Path to image file
     * @param  array  $regions  Array of regions
     * @return array Detailed result with diagnostics
     */
    public function writeFaceRegionsWithDiagnostics(string $imagePath, array $regions): array
    {
        $diagnostics = [
            'started_at' => now()->toIso8601String(),
            'file_exists' => file_exists($imagePath),
            'file_writable' => is_writable($imagePath),
            'exiftool_path' => $this->findExiftoolPath(),
            'regions_count' => count($regions),
        ];

        if (! $diagnostics['file_exists']) {
            return [
                'success' => false,
                'error' => 'File not found',
                'diagnostics' => $diagnostics,
            ];
        }

        if (! $diagnostics['file_writable']) {
            return [
                'success' => false,
                'error' => 'File not writable',
                'diagnostics' => $diagnostics,
            ];
        }

        if (! $diagnostics['exiftool_path']) {
            return [
                'success' => false,
                'error' => 'ExifTool not found',
                'diagnostics' => $diagnostics,
            ];
        }

        // Get dimensions before write
        $diagnostics['dimensions'] = $this->getImageDimensions($imagePath);

        // Read existing regions before write
        $diagnostics['existing_regions'] = $this->readFaceRegions($imagePath);

        // Attempt write with retry
        $result = $this->writeFaceRegionsWithRetry($imagePath, $regions);

        // Verify write
        if ($result['success']) {
            $diagnostics['regions_after_write'] = $this->readFaceRegions($imagePath);
            $diagnostics['verified'] = count($diagnostics['regions_after_write']) === count($regions);
        }

        $diagnostics['completed_at'] = now()->toIso8601String();

        return array_merge($result, ['diagnostics' => $diagnostics]);
    }

    /**
     * Sync face regions from database to image file
     *
     * @param  string  $imagePath  Path to image file
     * @param  array  $personMedia  Array of person_media records with face_region data
     * @return array Result with success status
     */
    public function syncFaceRegionsFromDatabase(string $imagePath, array $personMedia): array
    {
        $regions = [];

        foreach ($personMedia as $pm) {
            // Skip if no face region coordinates
            if (! isset($pm['face_region_x']) || $pm['face_region_x'] === null) {
                continue;
            }

            // Build person name
            $name = trim(($pm['given_name'] ?? '').' '.($pm['surname'] ?? ''));
            if (empty($name) && isset($pm['person_name'])) {
                $name = $pm['person_name'];
            }

            if (empty($name)) {
                continue;
            }

            $regions[] = [
                'name' => $name,
                'type' => 'Face',
                'x' => (float) $pm['face_region_x'],
                'y' => (float) $pm['face_region_y'],
                'w' => (float) ($pm['face_region_w'] ?? 0.1),
                'h' => (float) ($pm['face_region_h'] ?? 0.1),
            ];
        }

        if (empty($regions)) {
            return [
                'success' => true,
                'message' => 'No face regions to sync',
                'regions_count' => 0,
            ];
        }

        return $this->writeFaceRegionsWithRetry($imagePath, $regions);
    }
}
