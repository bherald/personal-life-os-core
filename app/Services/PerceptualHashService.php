<?php

namespace App\Services;

use Jenssegers\ImageHash\ImageHash;
use Jenssegers\ImageHash\Implementations\DifferenceHash;
use Jenssegers\ImageHash\Implementations\PerceptualHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Perceptual Hash Service
 *
 * Computes and stores perceptual hashes (dHash 128-bit, pHash 64-bit) for images,
 * enables visual duplicate detection via hamming distance comparison.
 *
 * Uses jenssegers/imagehash library with:
 * - DifferenceHash (16x16 = 128-bit) for primary comparison
 * - PerceptualHash (8x8 DCT = 64-bit) for secondary verification
 *
 * Similarity thresholds:
 * - exact: hamming distance <= 2 (visually identical)
 * - near_duplicate: hamming distance <= 5 (minor edits, compression)
 * - similar: hamming distance <= 10 (same subject, different angle/crop)
 */
class PerceptualHashService
{
    private ImageHash $dHasher;
    private ImageHash $pHasher;

    /** Image extensions supported for hashing */
    private const SUPPORTED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'tif',
    ];

    /** MIME types supported by Intervention Image (used for actual content validation) */
    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/x-ms-bmp',
        'image/tiff',
        'image/avif',
    ];

    public function __construct()
    {
        // 16x16 difference hash = 128 bits (more discriminative than 8x8)
        $this->dHasher = new ImageHash(new DifferenceHash(16));
        // Standard 8x8 DCT perceptual hash = 64 bits
        $this->pHasher = new ImageHash(new PerceptualHash());
    }

    /**
     * Compute perceptual hashes for an image file
     *
     * @param string $filePath Absolute path to image file
     * @return array ['dhash_hex', 'dhash_int_hi', 'dhash_int_lo', 'phash_hex', 'phash_int']
     * @throws Exception If file not found or not a supported image
     */
    public function computeHash(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SUPPORTED_EXTENSIONS)) {
            throw new Exception("Unsupported image format: {$extension}");
        }

        // Validate actual MIME type - some files have valid extensions but unsupported content
        // (e.g. .bmp files that are actually image/vnd.wap.wbmp which Intervention Image rejects)
        $mimeType = function_exists('mime_content_type') ? mime_content_type($filePath) : null;
        if ($mimeType && !in_array($mimeType, self::SUPPORTED_MIME_TYPES)) {
            Log::info('PerceptualHashService: Skipping unsupported MIME type', [
                'file' => $filePath,
                'mime_type' => $mimeType,
                'extension' => $extension,
            ]);
            throw new Exception("Unsupported MIME type for perceptual hashing: {$mimeType}");
        }

        // Pre-validate large/problematic files that crash Imagick at C level
        if (extension_loaded('imagick') && in_array($mimeType, ['image/tiff', 'image/gif'])) {
            try {
                $im = new \Imagick();
                $im->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024); // 256MB
                $im->setResourceLimit(\Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
                $im->setResourceLimit(\Imagick::RESOURCETYPE_AREA, 128 * 1024 * 1024); // 128 megapixels
                $im->pingImage($filePath);
                $w = $im->getImageWidth();
                $h = $im->getImageHeight();
                $im->destroy();
                // Skip files that would exceed 100 megapixels (risk of C-level crash)
                if ($w * $h > 100_000_000) {
                    throw new Exception("Image too large for safe hashing: {$w}x{$h} (" . round($w * $h / 1_000_000) . "MP)");
                }
            } catch (Exception $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new Exception("Pre-validation failed: {$e->getMessage()}");
            }
        }

        try {
            $dHash = $this->dHasher->hash($filePath);
            $pHash = $this->pHasher->hash($filePath);

            $dHashHex = $dHash->toHex();
            $dHashInt = $this->hexToTwoInt64($dHashHex);

            $pHashHex = $pHash->toHex();
            // pHash is 64-bit, fits in single integer
            $pHashInt = $this->hexToUnsignedInt64($pHashHex);

            return [
                'dhash_hex' => $dHashHex,
                'dhash_int_hi' => $dHashInt['hi'],
                'dhash_int_lo' => $dHashInt['lo'],
                'phash_hex' => $pHashHex,
                'phash_int' => $pHashInt,
            ];
        } catch (\Throwable $e) {
            Log::error('PerceptualHashService: Failed to compute hash', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw new Exception("Failed to compute perceptual hash: {$e->getMessage()}");
        }
    }

    /**
     * Convert a 128-bit hex string to two 64-bit unsigned integers
     *
     * @param string $hex 32-character hex string
     * @return array ['hi' => upper 64 bits, 'lo' => lower 64 bits]
     */
    public function hexToTwoInt64(string $hex): array
    {
        // Pad to 32 characters (128 bits)
        $hex = str_pad($hex, 32, '0', STR_PAD_LEFT);

        return [
            'hi' => $this->hexToUnsignedInt64(substr($hex, 0, 16)),
            'lo' => $this->hexToUnsignedInt64(substr($hex, 16, 16)),
        ];
    }

    /**
     * Convert 16-character hex to unsigned 64-bit integer
     * Handles PHP's signed integer limitation
     */
    private function hexToUnsignedInt64(string $hex): string
    {
        // Use GMP for proper unsigned 64-bit handling
        if (function_exists('gmp_init')) {
            return gmp_strval(gmp_init($hex, 16));
        }

        // Fallback: BC math
        if (function_exists('bcmul')) {
            $result = '0';
            $hex = strtolower($hex);
            for ($i = 0; $i < strlen($hex); $i++) {
                $result = bcmul($result, '16');
                $digit = strpos('0123456789abcdef', $hex[$i]);
                $result = bcadd($result, (string) $digit);
            }
            return $result;
        }

        // Last resort: regular hexdec (may lose precision for large values)
        return (string) hexdec($hex);
    }

    /**
     * Calculate hamming distance between two hex hash strings
     *
     * @param string $hash1 Hex hash string
     * @param string $hash2 Hex hash string
     * @return int Number of differing bits
     */
    public function hammingDistance(string $hash1, string $hash2): int
    {
        $distance = 0;
        $len = max(strlen($hash1), strlen($hash2));

        // Pad shorter hash
        $hash1 = str_pad($hash1, $len, '0', STR_PAD_LEFT);
        $hash2 = str_pad($hash2, $len, '0', STR_PAD_LEFT);

        // Process 16 hex chars (64 bits) at a time
        for ($i = 0; $i < $len; $i += 16) {
            $chunk1 = substr($hash1, $i, 16);
            $chunk2 = substr($hash2, $i, 16);

            if (function_exists('gmp_popcount') && function_exists('gmp_xor')) {
                $val1 = gmp_init($chunk1, 16);
                $val2 = gmp_init($chunk2, 16);
                $distance += gmp_popcount(gmp_xor($val1, $val2));
            } else {
                // Fallback: process character by character
                for ($j = 0; $j < strlen($chunk1); $j++) {
                    $xor = hexdec($chunk1[$j]) ^ hexdec($chunk2[$j]);
                    $distance += $this->popcount($xor);
                }
            }
        }

        return $distance;
    }

    /**
     * Count set bits in an integer
     */
    private function popcount(int $n): int
    {
        $count = 0;
        while ($n) {
            $n &= ($n - 1);
            $count++;
        }
        return $count;
    }

    /**
     * Classify similarity based on hamming distance
     *
     * @param int $distance Hamming distance
     * @return string 'exact', 'near_duplicate', 'similar', or 'different'
     */
    public function classifySimilarity(int $distance): string
    {
        return match (true) {
            $distance <= 2 => 'exact',
            $distance <= 5 => 'near_duplicate',
            $distance <= 10 => 'similar',
            default => 'different',
        };
    }

    /**
     * Find similar images in the database using inline BIT_COUNT for hamming distance
     *
     * Uses inline BIT_COUNT calculation which works without SUPER privilege.
     * Pre-filters each 64-bit half to reduce candidates before final calculation.
     *
     * @param string $dhashHex 32-character hex dHash to compare
     * @param int $threshold Maximum hamming distance (default 5 = near_duplicate)
     * @return array List of similar files with distance and file info
     */
    public function findSimilar(string $dhashHex, int $threshold = 5): array
    {
        $ints = $this->hexToTwoInt64($dhashHex);

        // Use inline BIT_COUNT for hamming distance (works without custom function)
        // Pre-filter: each 64-bit half can contribute at most 64 bits to distance
        // So if threshold is T, both halves should have distance <= T
        return DB::select("
            SELECT
                fr.id,
                fr.asset_uuid,
                fr.current_path,
                fr.filename,
                ph.dhash_hex,
                ph.phash_hex,
                (BIT_COUNT(ph.dhash_int_hi ^ ?) + BIT_COUNT(ph.dhash_int_lo ^ ?)) as hamming_distance
            FROM file_registry_perceptual_hashes ph
            JOIN file_registry fr ON fr.id = ph.file_registry_id
            WHERE fr.status = 'active'
            AND ph.algorithm_version != 'skipped'
            AND BIT_COUNT(ph.dhash_int_hi ^ ?) <= ?
            AND BIT_COUNT(ph.dhash_int_lo ^ ?) <= ?
            HAVING hamming_distance <= ?
            ORDER BY hamming_distance ASC
            LIMIT 100
        ", [
            $ints['hi'],
            $ints['lo'],
            $ints['hi'],
            $threshold,
            $ints['lo'],
            $threshold,
            $threshold,
        ]);
    }

    /**
     * Register perceptual hash for a file in the database
     *
     * @param int $fileRegistryId ID in file_registry table
     * @param string $filePath Absolute path to image file
     * @return array Hash data that was stored
     */
    public function registerHash(int $fileRegistryId, string $filePath): array
    {
        $hashes = $this->computeHash($filePath);

        DB::insert("
            INSERT INTO file_registry_perceptual_hashes
            (file_registry_id, dhash_hex, dhash_int_hi, dhash_int_lo, phash_hex, phash_int, algorithm_version, computed_at)
            VALUES (?, ?, ?, ?, ?, ?, '1.0', NOW())
            ON DUPLICATE KEY UPDATE
                dhash_hex = VALUES(dhash_hex),
                dhash_int_hi = VALUES(dhash_int_hi),
                dhash_int_lo = VALUES(dhash_int_lo),
                phash_hex = VALUES(phash_hex),
                phash_int = VALUES(phash_int),
                algorithm_version = VALUES(algorithm_version),
                computed_at = NOW()
        ", [
            $fileRegistryId,
            $hashes['dhash_hex'],
            $hashes['dhash_int_hi'],
            $hashes['dhash_int_lo'],
            $hashes['phash_hex'],
            $hashes['phash_int'],
        ]);

        Log::info('PerceptualHashService: Hash registered', [
            'file_registry_id' => $fileRegistryId,
            'dhash' => substr($hashes['dhash_hex'], 0, 8) . '...',
        ]);

        return $hashes;
    }

    /**
     * Find and record similar images for a given file
     *
     * @param int $fileRegistryId ID of the file to find duplicates for
     * @param int $threshold Hamming distance threshold
     * @return array ['found' => count, 'recorded' => count]
     */
    public function findAndRecordSimilar(int $fileRegistryId, int $threshold = 10): array
    {
        $hash = DB::selectOne("
            SELECT dhash_hex FROM file_registry_perceptual_hashes WHERE file_registry_id = ?
        ", [$fileRegistryId]);

        if (!$hash) {
            return ['found' => 0, 'recorded' => 0, 'error' => 'No hash found for file'];
        }

        $similar = $this->findSimilar($hash->dhash_hex, $threshold);
        $recorded = 0;

        foreach ($similar as $match) {
            // Skip self
            if ($match->id === $fileRegistryId) {
                continue;
            }

            // Ensure ordered pair (smaller ID first)
            $idA = min($fileRegistryId, $match->id);
            $idB = max($fileRegistryId, $match->id);

            $similarityType = $this->classifySimilarity($match->hamming_distance);

            // Skip 'different' results
            if ($similarityType === 'different') {
                continue;
            }

            // Insert or update similar pair
            DB::insert("
                INSERT INTO file_registry_similar_images
                (file_id_a, file_id_b, hamming_distance, similarity_type, algorithm_used, status, created_at)
                VALUES (?, ?, ?, ?, 'dhash', 'pending_review', NOW())
                ON DUPLICATE KEY UPDATE
                    hamming_distance = VALUES(hamming_distance),
                    similarity_type = VALUES(similarity_type)
            ", [$idA, $idB, $match->hamming_distance, $similarityType]);

            $recorded++;
        }

        return [
            'found' => count($similar) - 1, // Exclude self
            'recorded' => $recorded,
        ];
    }

    /**
     * Check if an extension is supported for perceptual hashing
     */
    public function isSupported(string $extension): bool
    {
        return in_array(strtolower($extension), self::SUPPORTED_EXTENSIONS);
    }

    /**
     * Get statistics about perceptual hashes in the system
     */
    public function getStatistics(): array
    {
        $hashStats = DB::selectOne("
            SELECT
                COUNT(*) as total_hashes,
                COUNT(DISTINCT file_registry_id) as unique_files
            FROM file_registry_perceptual_hashes
        ");

        $similarStats = DB::selectOne("
            SELECT
                COUNT(*) as total_pairs,
                SUM(CASE WHEN similarity_type = 'exact' THEN 1 ELSE 0 END) as exact_matches,
                SUM(CASE WHEN similarity_type = 'near_duplicate' THEN 1 ELSE 0 END) as near_duplicates,
                SUM(CASE WHEN similarity_type = 'similar' THEN 1 ELSE 0 END) as similar,
                SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) as pending_review,
                SUM(CASE WHEN status = 'confirmed_duplicate' THEN 1 ELSE 0 END) as confirmed
            FROM file_registry_similar_images
        ");

        return [
            'total_hashes' => (int) ($hashStats->total_hashes ?? 0),
            'unique_files' => (int) ($hashStats->unique_files ?? 0),
            'similar_pairs' => [
                'total' => (int) ($similarStats->total_pairs ?? 0),
                'exact' => (int) ($similarStats->exact_matches ?? 0),
                'near_duplicate' => (int) ($similarStats->near_duplicates ?? 0),
                'similar' => (int) ($similarStats->similar ?? 0),
            ],
            'review_status' => [
                'pending' => (int) ($similarStats->pending_review ?? 0),
                'confirmed' => (int) ($similarStats->confirmed ?? 0),
            ],
        ];
    }
}
