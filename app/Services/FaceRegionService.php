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
 * Historical private backlog label: E23.
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
    public function readFaceRegions(string $imagePath, bool $includeUnidentified = false, bool $throwOnFailure = false): array
    {
        if (! file_exists($imagePath)) {
            Log::warning('FaceRegionService: File not found', ['path' => $imagePath]);
            if ($throwOnFailure) {
                throw new \RuntimeException('Face metadata source is unavailable');
            }

            return [];
        }

        $exiftoolPath = $this->findExiftoolPath();
        if (! $exiftoolPath) {
            Log::warning('FaceRegionService: ExifTool not found');
            if ($throwOnFailure) {
                throw new \RuntimeException('Face metadata reader is unavailable');
            }

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
                        if ($throwOnFailure && (! is_array($data[0]) || isset($data[0]['Error']) || isset($data[0]['Warning']))) {
                            throw new \RuntimeException('Face metadata reader reported invalid source evidence');
                        }
                        $regions = $this->parseRegions($data[0], $includeUnidentified);
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
                if ($throwOnFailure) {
                    throw new \RuntimeException('Face metadata read failed');
                }

                return [];
            }

            $output = $result->output();
            if (empty($output)) {
                if ($throwOnFailure) {
                    throw new \RuntimeException('Face metadata reader returned no result');
                }

                return [];
            }

            $data = json_decode($output, true);
            if (! $data || ! isset($data[0])) {
                if ($throwOnFailure) {
                    throw new \RuntimeException('Face metadata reader returned an invalid result');
                }

                return [];
            }

            if ($throwOnFailure && (! is_array($data[0]) || isset($data[0]['Error']) || isset($data[0]['Warning']))) {
                throw new \RuntimeException('Face metadata reader reported invalid source evidence');
            }

            return $this->parseFlatRegions($data[0], $includeUnidentified);

        } catch (\Exception $e) {
            Log::error('FaceRegionService: Read failed', [
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            if ($throwOnFailure) {
                throw $e;
            }

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
            $result = app(ExifWritebackService::class)->writeVerifiedMetadata($imagePath, [
                '-overwrite_original',
                '-XMP-mwg-rs:Regions<='.$jsonFile,
            ]);

            @unlink($jsonFile);

            if ($result['success']) {
                Log::info('FaceRegionService: Wrote face regions', [
                    'path' => $imagePath,
                    'count' => count($regionList),
                ]);

                return true;
            } elseif (empty($result['source_replaced']) && str_starts_with($result['error'] ?? '', 'Metadata preparation failed:')) {
                // Alternative approach: write each field separately
                return $this->writeRegionsFallback($imagePath, $regions, $dimensions, $exiftoolPath);
            }

            return false;

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
            // Clear and replace only on the isolated, verified temp source.
            $args = [
                '-overwrite_original',
                '-XMP-mwg-rs:all=',
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

            $result = app(ExifWritebackService::class)->writeVerifiedMetadata($imagePath, $args);

            if ($result['success']) {
                Log::info('FaceRegionService: Wrote face regions (fallback method)', [
                    'path' => $imagePath,
                    'count' => count($regions),
                ]);

                return true;
            }

            Log::warning('FaceRegionService: Fallback write also failed', [
                'path' => $imagePath,
                'error' => $result['error'] ?? 'Verified metadata write failed',
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

    /** Read the exact structured metadata used by an explicit operator repair. */
    public function readFaceMetadataEvidence(string $imagePath, ?string $expectedSourceHash = null): array
    {
        $path = realpath($imagePath);
        $exiftool = $this->findExiftoolPath();
        if (! $path || ! is_file($path) || ! $exiftool) {
            throw new \RuntimeException('Face metadata source or reader is unavailable');
        }
        $sourceHash = hash_file('sha256', $path);
        if (! is_string($sourceHash)) {
            throw new \RuntimeException('Face metadata source bytes are unavailable');
        }
        if ($expectedSourceHash !== null && (! preg_match('/^[a-f0-9]{64}$/D', $expectedSourceHash)
            || ! hash_equals($expectedSourceHash, $sourceHash))) {
            throw new \RuntimeException('Face metadata source changed');
        }
        $read = Process::timeout(30)->run([$exiftool, '-json', '-struct', '-XMP-mwg-rs:RegionInfo', $path]);
        $records = $read->successful() ? json_decode($read->output(), true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_array($records) || count($records) !== 1 || ! is_array($records[0] ?? null)
            || ($records[0]['SourceFile'] ?? null) !== $path || isset($records[0]['Error']) || isset($records[0]['Warning'])) {
            throw new \RuntimeException('Face metadata evidence could not be read');
        }
        $info = array_key_exists('RegionInfo', $records[0]) ? $records[0]['RegionInfo'] : ($records[0]['Regions'] ?? []);
        if (! is_array($info) || ! is_array($info['RegionList'] ?? []) || ! array_is_list($info['RegionList'] ?? [])) {
            throw new \RuntimeException('Face metadata region structure is ambiguous');
        }
        foreach ($info['RegionList'] ?? [] as $region) {
            if (! is_array($region)) {
                throw new \RuntimeException('Face metadata region structure is ambiguous');
            }
        }
        $dimensions = $this->decodedImageDimensions($path);
        if (! hash_equals($sourceHash, hash_file('sha256', $path))) {
            throw new \RuntimeException('Face metadata source changed during inspection');
        }

        return ['source_path' => $path, 'source_sha256' => $sourceHash, 'source_dimensions' => $dimensions,
            'region_info_sha256' => $this->faceMetadataDigest($info),
            'region_info' => $info];
    }

    /** Stable structural digest; the accompanying source SHA binds the original bytes. */
    public function faceMetadataDigest(array $info): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (is_array($value)) {
                ksort($value);

                return array_map($sort, $value);
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($info), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Merge individually confirmed MWG regions while preserving the raw metadata.
     * Read failures are distinct from a successful read with no regions.
     */
    public function mergeConfirmedFaceRegions(string $imagePath, array $confirmedRegions): bool
    {
        if (! $this->metadataWritebackEnabled() || ! is_file($imagePath) || $confirmedRegions === []) {
            return false;
        }
        $exiftoolPath = $this->findExiftoolPath();
        if (! $exiftoolPath) {
            return false;
        }

        $jsonFile = null;
        try {
            $read = Process::timeout(30)->run([
                $exiftoolPath, '-json', '-struct', '-XMP-mwg-rs:RegionInfo', $imagePath,
            ]);
            if (! $read->successful()) {
                return false;
            }
            $data = json_decode($read->output(), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || ! isset($data[0]) || ! is_array($data[0])
                || ($data[0]['SourceFile'] ?? null) !== $imagePath
                || isset($data[0]['Error']) || isset($data[0]['Warning'])) {
                return false;
            }
            $regionInfo = array_key_exists('RegionInfo', $data[0]) ? $data[0]['RegionInfo'] : ($data[0]['Regions'] ?? []);
            if (! is_array($regionInfo)) {
                return false;
            }
            $regions = array_key_exists('RegionList', $regionInfo) ? $regionInfo['RegionList'] : [];
            if (! is_array($regions) || ! array_is_list($regions)) {
                return false;
            }
            foreach ($regions as $region) {
                if (! is_array($region)) {
                    return false;
                }
            }

            $dimensions = $this->decodedImageDimensions($imagePath);
            if ($dimensions === null || (($regions !== [] || array_key_exists('AppliedToDimensions', $regionInfo))
                && $this->appliedDimensionsHoldReason($regionInfo['AppliedToDimensions'] ?? null, $dimensions) !== null)) {
                return false;
            }

            $tolerance = max(0.0, (float) config('genealogy.face_region_match_tolerance'));
            foreach ($confirmedRegions as $confirmed) {
                if (! is_string($confirmed['name'] ?? null) || trim($confirmed['name']) === '') {
                    return false;
                }
                foreach (['x', 'y', 'w', 'h'] as $axis) {
                    if (! is_numeric($confirmed[$axis] ?? null)
                        || (float) $confirmed[$axis] < 0 || (float) $confirmed[$axis] > 1) {
                        return false;
                    }
                }
                $matched = false;
                foreach ($regions as &$region) {
                    if (($region['Type'] ?? 'Face') !== 'Face' || ($region['Area']['Unit'] ?? '') !== 'normalized') {
                        continue;
                    }
                    $sameRegion = true;
                    foreach (['x' => 'X', 'y' => 'Y', 'w' => 'W', 'h' => 'H'] as $axis => $tag) {
                        if (! is_numeric($region['Area'][$tag] ?? null)
                            || abs((float) $region['Area'][$tag] - (float) $confirmed[$axis]) > $tolerance) {
                            $sameRegion = false;
                            break;
                        }
                    }
                    if ($sameRegion) {
                        $region['Name'] = $confirmed['name'];
                        $matched = true;
                        break;
                    }
                }
                unset($region);
                if (! $matched) {
                    $regions[] = [
                        'Area' => ['X' => $confirmed['x'], 'Y' => $confirmed['y'], 'W' => $confirmed['w'], 'H' => $confirmed['h'], 'Unit' => 'normalized'],
                        'Name' => $confirmed['name'], 'Type' => 'Face',
                    ];
                }
            }

            if (! isset($regionInfo['AppliedToDimensions'])) {
                $regionInfo['AppliedToDimensions'] = ['W' => $dimensions['width'], 'H' => $dimensions['height'], 'Unit' => 'pixel'];
            }
            $regionInfo['RegionList'] = $regions;
            $jsonFile = tempnam(sys_get_temp_dir(), 'face_region_merge_');
            if ($jsonFile === false || file_put_contents($jsonFile, json_encode([
                ['SourceFile' => '*', 'XMP-mwg-rs:RegionInfo' => $regionInfo],
            ], JSON_THROW_ON_ERROR)) === false) {
                return false;
            }

            // The common writer preserves a durable preimage and proves unchanged
            // pixels/display geometry before advancing existing source receipts.
            return app(ExifWritebackService::class)->writeVerifiedMetadata($imagePath, [
                '-overwrite_original', '-preserve', '-struct', '-json='.$jsonFile,
            ])['success'];
        } catch (\Throwable $e) {
            Log::warning('FaceRegionService: Confirmed region merge failed', ['error' => $e->getMessage()]);

            return false;
        } finally {
            if (is_string($jsonFile)) {
                @unlink($jsonFile);
            }
        }
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
            $result = app(ExifWritebackService::class)->writeVerifiedMetadata($imagePath, [
                '-overwrite_original',
                '-XMP-mwg-rs:all=',
            ]);

            return $result['success'];

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
    protected function parseFlatRegions(array $data, bool $includeUnidentified = false): array
    {
        $regions = [];

        // Get the arrays - try both with and without prefix
        $names = $data['RegionName'] ?? $data['XMP:RegionName'] ?? [];
        $xCoords = $data['RegionAreaX'] ?? $data['XMP:RegionAreaX'] ?? [];
        $yCoords = $data['RegionAreaY'] ?? $data['XMP:RegionAreaY'] ?? [];
        $widths = $data['RegionAreaW'] ?? $data['XMP:RegionAreaW'] ?? [];
        $heights = $data['RegionAreaH'] ?? $data['XMP:RegionAreaH'] ?? [];
        $types = $data['RegionType'] ?? $data['XMP:RegionType'] ?? [];
        $units = $data['RegionAreaUnit'] ?? $data['XMP:RegionAreaUnit'] ?? [];

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

        if (! is_array($units)) {
            $units = [$units];
        }

        $count = $includeUnidentified ? max(count($names), count($xCoords), count($yCoords), count($widths), count($heights)) : count($names);

        for ($i = 0; $i < $count; $i++) {
            $name = $names[$i] ?? null;

            // Skip unnamed or "Unknown" regions
            if (! $includeUnidentified && (empty($name) || $name === 'Unknown')) {
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
            if ($includeUnidentified) {
                $regions[array_key_last($regions)]['unit'] = $units[$i] ?? null;
                $regions[array_key_last($regions)]['raw_area'] = [
                    'X' => $xCoords[$i] ?? null, 'Y' => $yCoords[$i] ?? null,
                    'W' => $widths[$i] ?? null, 'H' => $heights[$i] ?? null, 'Unit' => $units[$i] ?? null,
                ];
                $regions[array_key_last($regions)]['applied_to_dimensions'] = [
                    'W' => $data['RegionAppliedToDimensionsW'] ?? $data['XMP:RegionAppliedToDimensionsW'] ?? null,
                    'H' => $data['RegionAppliedToDimensionsH'] ?? $data['XMP:RegionAppliedToDimensionsH'] ?? null,
                    'Unit' => $data['RegionAppliedToDimensionsUnit'] ?? $data['XMP:RegionAppliedToDimensionsUnit'] ?? null,
                ];
            }
        }

        return $regions;
    }

    /**
     * Parse regions from ExifTool output
     */
    protected function parseRegions(array $data, bool $includeUnidentified = false): array
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

            if ($includeUnidentified) {
                $parsed['unit'] = $area['Unit'] ?? $area['unit'] ?? null;
                $parsed['raw_area'] = $area;
                $parsed['applied_to_dimensions'] = $regionData['AppliedToDimensions'] ?? null;
            }

            // Named-only callers retain their existing projection; imports may retain unidentified evidence.
            if ($parsed['name'] || $includeUnidentified) {
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

    /** Verify the declared MWG pixel basis without interpreting legacy coordinates. */
    public function appliedDimensionsHoldReason(mixed $applied, ?array $dimensions): ?string
    {
        if (! is_array($applied)) {
            return 'applied_dimensions_missing';
        }
        if (($applied['Unit'] ?? null) !== 'pixel') {
            return 'applied_dimensions_unit_unproven';
        }
        foreach (['W', 'H'] as $axis) {
            if (! is_numeric($applied[$axis] ?? null) || ! is_finite((float) $applied[$axis]) || (float) $applied[$axis] <= 0) {
                return 'applied_dimensions_invalid';
            }
        }
        if ($dimensions === null) {
            return 'source_dimensions_unproven';
        }
        if ((float) $applied['W'] !== (float) $dimensions['width'] || (float) $applied['H'] !== (float) $dimensions['height']) {
            return 'applied_dimensions_mismatch';
        }

        return null;
    }

    /** Decode the encoded source dimensions; never auto-orient or repair metadata. */
    public function decodedImageDimensions(string $imagePath): ?array
    {
        $size = @getimagesize($imagePath);
        $bytes = @filesize($imagePath);
        if (! $size || $size[0] <= 0 || $size[1] <= 0 || ! is_int($bytes)
            || $bytes > (int) config('metadata_writeback.max_verification_bytes', 67108864)
            || (int) config('metadata_writeback.max_verification_pixels', 16000000) < $size[0] * $size[1]
            || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit !== '-1') {
            $scale = match (strtolower(substr($memoryLimit, -1))) {
                'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
            };
            if ((int) $memoryLimit * $scale - memory_get_usage(true) < $size[0] * $size[1] * 8 + $bytes * 2) {
                return null;
            }
        }
        $content = @file_get_contents($imagePath);
        $decoded = is_string($content) ? @imagecreatefromstring($content) : false;
        if (! $decoded) {
            return null;
        }
        try {
            if (imagesx($decoded) !== $size[0] || imagesy($decoded) !== $size[1]) {
                return null;
            }

            return ['width' => $size[0], 'height' => $size[1]];
        } finally {
            imagedestroy($decoded);
        }
    }

    /**
     * Get image dimensions
     */
    protected function getImageDimensions(string $imagePath): ?array
    {
        // Try PHP getimagesize first
        $size = @getimagesize($imagePath);
        if ($size && isset($size[0], $size[1]) && $size[0] > 0 && $size[1] > 0) {
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
                    if ($data && isset($data[0]['ImageWidth'], $data[0]['ImageHeight'])
                        && is_numeric($data[0]['ImageWidth']) && is_numeric($data[0]['ImageHeight'])
                        && is_finite((float) $data[0]['ImageWidth']) && is_finite((float) $data[0]['ImageHeight'])
                        && (int) $data[0]['ImageWidth'] > 0 && (int) $data[0]['ImageHeight'] > 0) {
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
