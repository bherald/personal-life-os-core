<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Exception;

/**
 * Video Perceptual Hash Service (vHash)
 *
 * Computes video fingerprints by extracting keyframes via FFmpeg
 * and generating perceptual hashes for each frame using the existing
 * PerceptualHashService. Combines frame hashes into a video signature.
 *
 * Video similarity is determined by comparing keyframe hashes and
 * measuring temporal alignment between videos.
 *
 * Requires: FFmpeg installed and in PATH
 */
class VideoHashService
{
    private PerceptualHashService $imageHasher;

    /** Video extensions supported for hashing */
    private const SUPPORTED_EXTENSIONS = [
        'mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'mpg', 'mpeg', '3gp',
    ];

    /** Similarity thresholds */
    private const SIMILARITY_EXACT = 0.95;
    private const SIMILARITY_NEAR_DUPLICATE = 0.85;
    private const SIMILARITY_SIMILAR = 0.70;

    /** Max keyframe hamming distance for a "match" */
    private const KEYFRAME_MATCH_THRESHOLD = 10;

    private bool $ffmpegAvailable;
    private ?string $ffmpegPath = null;
    private ?string $ffprobePath = null;

    public function __construct(PerceptualHashService $imageHasher)
    {
        $this->imageHasher = $imageHasher;
        $this->detectFFmpeg();
    }

    /**
     * Detect FFmpeg availability
     */
    private function detectFFmpeg(): void
    {
        $this->ffmpegAvailable = false;

        // Check common paths
        $paths = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg'];
        foreach ($paths as $path) {
            try {
                $result = Process::timeout(5)->run([$path, '-version']);
                if ($result->successful() && str_contains($result->output(), 'ffmpeg version')) {
                    $this->ffmpegPath = $path;
                    $this->ffmpegAvailable = true;
                    break;
                }
            } catch (\Exception $e) {
                // Path not available, try next
                continue;
            }
        }

        if ($this->ffmpegAvailable) {
            $probePaths = ['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe'];
            foreach ($probePaths as $path) {
                try {
                    $result = Process::timeout(5)->run([$path, '-version']);
                    if ($result->successful() && str_contains($result->output(), 'ffprobe version')) {
                        $this->ffprobePath = $path;
                        break;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }
    }

    /**
     * Check if FFmpeg is available
     */
    public function isFFmpegAvailable(): bool
    {
        return $this->ffmpegAvailable;
    }

    /**
     * Check if file extension is supported
     */
    public function isSupported(string $extension): bool
    {
        return in_array(strtolower($extension), self::SUPPORTED_EXTENSIONS);
    }

    /**
     * Hash a video file, extracting keyframes and computing perceptual hashes
     *
     * @param string $filePath Absolute path to video file
     * @param int $interval Seconds between keyframe extractions (default 10)
     * @return array Video hash data including keyframe hashes and combined fingerprint
     * @throws Exception If FFmpeg unavailable or file not found
     */
    public function hashVideo(string $filePath, int $interval = 10): array
    {
        if (!$this->ffmpegAvailable) {
            throw new Exception('FFmpeg is not installed or not in PATH');
        }

        if (!file_exists($filePath)) {
            throw new Exception("Video file not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!$this->isSupported($extension)) {
            throw new Exception("Unsupported video format: {$extension}");
        }

        // Get video metadata
        $metadata = $this->getVideoMetadata($filePath);
        if (!$metadata || !isset($metadata['duration'])) {
            throw new Exception("Could not read video metadata: {$filePath}");
        }

        // Extract keyframes
        $keyframes = $this->extractKeyframes($filePath, $interval, $metadata['duration']);

        if (empty($keyframes)) {
            throw new Exception("No keyframes could be extracted from: {$filePath}");
        }

        // Generate combined hash from keyframe hashes
        $combinedHash = $this->combineKeyframeHashes($keyframes);

        return [
            'duration_seconds' => (int) $metadata['duration'],
            'keyframe_count' => count($keyframes),
            'keyframe_hashes' => $keyframes,
            'combined_hash' => $combinedHash,
            'width' => $metadata['width'] ?? null,
            'height' => $metadata['height'] ?? null,
            'codec' => $metadata['codec'] ?? null,
            'fps' => $metadata['fps'] ?? null,
        ];
    }

    /**
     * Extract keyframes from video at specified interval
     *
     * @param string $filePath Video file path
     * @param int $interval Seconds between frames
     * @param float $duration Video duration in seconds
     * @return array Array of ['timestamp' => float, 'phash' => string, 'dhash' => string]
     */
    public function extractKeyframes(string $filePath, int $interval = 10, ?float $duration = null): array
    {
        if (!$this->ffmpegAvailable) {
            return [];
        }

        if ($duration === null) {
            $metadata = $this->getVideoMetadata($filePath);
            $duration = $metadata['duration'] ?? 0;
        }

        if ($duration <= 0) {
            return [];
        }

        $keyframes = [];
        $tempDir = sys_get_temp_dir() . '/vhash_' . uniqid();
        @mkdir($tempDir, 0755, true);

        try {
            // Calculate timestamps for extraction
            $timestamps = [];
            $timestamp = 0;
            while ($timestamp < $duration) {
                $timestamps[] = $timestamp;
                $timestamp += $interval;
            }

            // Also grab a frame near the end
            if ($duration > $interval && ($duration - end($timestamps)) > 3) {
                $timestamps[] = $duration - 2;
            }

            foreach ($timestamps as $index => $ts) {
                $framePath = "{$tempDir}/frame_{$index}.jpg";

                // Extract frame at timestamp
                $returnCode = Process::timeout(120)->run([
                    $this->ffmpegPath,
                    '-ss',
                    sprintf('%.2f', $ts),
                    '-i',
                    $filePath,
                    '-vframes',
                    '1',
                    '-q:v',
                    '2',
                    '-y',
                    $framePath,
                ])->exitCode();

                if ($returnCode === 0 && file_exists($framePath) && filesize($framePath) > 0) {
                    try {
                        $hashes = $this->imageHasher->computeHash($framePath);
                        $keyframes[] = [
                            'timestamp' => round($ts, 2),
                            'phash' => $hashes['phash_hex'],
                            'dhash' => $hashes['dhash_hex'],
                        ];
                    } catch (Exception $e) {
                        Log::warning('VideoHashService: Failed to hash frame', [
                            'timestamp' => $ts,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Clean up frame
                @unlink($framePath);
            }
        } finally {
            // Clean up temp directory
            @rmdir($tempDir);
        }

        return $keyframes;
    }

    /**
     * Get video metadata using ffprobe
     */
    private function getVideoMetadata(string $filePath): ?array
    {
        if (!$this->ffprobePath) {
            return null;
        }

        try {
            $result = Process::timeout(30)->run([
                $this->ffprobePath, '-v', 'quiet', '-print_format', 'json',
                '-show_format', '-show_streams', $filePath,
            ]);

            if ($result->failed()) {
                Log::debug('VideoHashService: ffprobe failed', [
                    'path' => $filePath,
                    'exitCode' => $result->exitCode(),
                    'error' => mb_substr($result->errorOutput(), 0, 200),
                ]);
                return null;
            }

            $output = $result->output();
        } catch (\Exception $e) {
            Log::warning('VideoHashService: ffprobe exception', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$output) {
            return null;
        }

        $data = json_decode($output, true);
        if (!$data) {
            return null;
        }

        $videoStream = null;
        foreach (($data['streams'] ?? []) as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                $videoStream = $stream;
                break;
            }
        }

        $duration = (float) ($data['format']['duration'] ?? 0);

        return [
            'duration' => $duration,
            'width' => $videoStream['width'] ?? null,
            'height' => $videoStream['height'] ?? null,
            'codec' => $videoStream['codec_name'] ?? null,
            'fps' => $this->parseFps($videoStream['r_frame_rate'] ?? null),
        ];
    }

    /**
     * Parse frame rate string (e.g., "30/1" or "29.97")
     */
    private function parseFps(?string $fpsString): ?float
    {
        if (!$fpsString) {
            return null;
        }

        if (str_contains($fpsString, '/')) {
            [$num, $den] = explode('/', $fpsString);
            return $den > 0 ? round((float) $num / (float) $den, 2) : null;
        }

        return (float) $fpsString;
    }

    /**
     * Combine keyframe hashes into a single video fingerprint
     *
     * Uses majority voting across keyframe pHash values to create
     * a representative hash for the entire video.
     */
    private function combineKeyframeHashes(array $keyframes): string
    {
        if (empty($keyframes)) {
            return str_repeat('0', 128);
        }

        // Use pHash for combination (64-bit = 16 hex chars)
        $hashes = array_column($keyframes, 'phash');
        $hashes = array_filter($hashes);

        if (empty($hashes)) {
            return str_repeat('0', 128);
        }

        // Convert to binary and perform majority voting
        $binaryHashes = [];
        foreach ($hashes as $hash) {
            $hash = str_pad($hash, 16, '0', STR_PAD_LEFT);
            $binary = '';
            for ($i = 0; $i < strlen($hash); $i++) {
                $binary .= str_pad(base_convert($hash[$i], 16, 2), 4, '0', STR_PAD_LEFT);
            }
            $binaryHashes[] = $binary;
        }

        // Majority vote for each bit position
        $combinedBinary = '';
        $len = strlen($binaryHashes[0]);
        for ($i = 0; $i < $len; $i++) {
            $ones = 0;
            foreach ($binaryHashes as $bh) {
                if (isset($bh[$i]) && $bh[$i] === '1') {
                    $ones++;
                }
            }
            $combinedBinary .= ($ones > count($binaryHashes) / 2) ? '1' : '0';
        }

        // Convert back to hex, pad to 128 chars for consistency with storage
        $hex = '';
        for ($i = 0; $i < strlen($combinedBinary); $i += 4) {
            $hex .= base_convert(substr($combinedBinary, $i, 4), 2, 16);
        }

        // Pad combined hash to 128 chars (we store both phash combined + temporal info)
        // First 16 chars: combined pHash
        // Remaining: temporal signature based on hash sequence
        $temporalSig = $this->computeTemporalSignature($keyframes);

        return str_pad($hex, 16, '0', STR_PAD_LEFT) . str_pad($temporalSig, 112, '0', STR_PAD_LEFT);
    }

    /**
     * Compute temporal signature from keyframe sequence
     *
     * Encodes the "shape" of how the video changes over time
     */
    private function computeTemporalSignature(array $keyframes): string
    {
        if (count($keyframes) < 2) {
            return '';
        }

        $sig = '';
        for ($i = 1; $i < count($keyframes) && $i < 28; $i++) {
            $prev = $keyframes[$i - 1]['phash'] ?? '';
            $curr = $keyframes[$i]['phash'] ?? '';

            if ($prev && $curr) {
                $distance = $this->imageHasher->hammingDistance($prev, $curr);
                // Encode distance as single hex char (0-15, clamped)
                $sig .= dechex(min($distance, 15));
            }
        }

        return $sig;
    }

    /**
     * Compare two videos by their hash IDs
     *
     * @param int $hashId1 First video hash ID
     * @param int $hashId2 Second video hash ID
     * @return float Similarity score 0.0 to 1.0
     */
    public function compareVideos(int $hashId1, int $hashId2): float
    {
        $hash1 = DB::selectOne(
            "SELECT keyframe_hashes, combined_hash, duration_seconds FROM file_registry_video_hashes WHERE id = ?",
            [$hashId1]
        );
        $hash2 = DB::selectOne(
            "SELECT keyframe_hashes, combined_hash, duration_seconds FROM file_registry_video_hashes WHERE id = ?",
            [$hashId2]
        );

        if (!$hash1 || !$hash2) {
            return 0.0;
        }

        $frames1 = json_decode($hash1->keyframe_hashes, true) ?? [];
        $frames2 = json_decode($hash2->keyframe_hashes, true) ?? [];

        if (empty($frames1) || empty($frames2)) {
            // Fall back to combined hash comparison
            return $this->compareCombinedHashes($hash1->combined_hash, $hash2->combined_hash);
        }

        // Compare keyframe sequences
        return $this->compareKeyframeSequences($frames1, $frames2);
    }

    /**
     * Compare two combined hash strings
     */
    private function compareCombinedHashes(?string $hash1, ?string $hash2): float
    {
        if (!$hash1 || !$hash2) {
            return 0.0;
        }

        // Compare first 16 chars (combined pHash)
        $phash1 = substr($hash1, 0, 16);
        $phash2 = substr($hash2, 0, 16);

        $distance = $this->imageHasher->hammingDistance($phash1, $phash2);
        // 64-bit hash, max distance = 64
        return 1.0 - ($distance / 64.0);
    }

    /**
     * Compare two keyframe sequences
     *
     * Uses dynamic time warping-inspired matching to handle
     * videos of different lengths or slightly different timing
     */
    private function compareKeyframeSequences(array $frames1, array $frames2): float
    {
        $matchedFrames = 0;
        $totalComparisons = 0;
        $totalDistance = 0;

        // For each frame in video 1, find best match in video 2
        foreach ($frames1 as $f1) {
            $bestDistance = PHP_INT_MAX;

            foreach ($frames2 as $f2) {
                $distance = $this->imageHasher->hammingDistance(
                    $f1['phash'] ?? '',
                    $f2['phash'] ?? ''
                );
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                }
            }

            if ($bestDistance < self::KEYFRAME_MATCH_THRESHOLD) {
                $matchedFrames++;
            }
            $totalDistance += $bestDistance;
            $totalComparisons++;
        }

        if ($totalComparisons === 0) {
            return 0.0;
        }

        // Weight: 70% matched frame ratio, 30% average distance score
        $matchRatio = $matchedFrames / $totalComparisons;
        $avgDistance = $totalDistance / $totalComparisons;
        $distanceScore = 1.0 - min($avgDistance / 64.0, 1.0);

        return ($matchRatio * 0.7) + ($distanceScore * 0.3);
    }

    /**
     * Find videos similar to a given video hash
     *
     * @param int $hashId Video hash ID to compare against
     * @param float $threshold Minimum similarity score (default 0.85)
     * @return array List of similar videos with scores
     */
    public function findSimilarVideos(int $hashId, float $threshold = 0.85): array
    {
        $sourceHash = DB::selectOne(
            "SELECT id, combined_hash, duration_seconds FROM file_registry_video_hashes WHERE id = ?",
            [$hashId]
        );

        if (!$sourceHash) {
            return [];
        }

        // Pre-filter candidates by duration (within 20% difference)
        $minDuration = (int) ($sourceHash->duration_seconds * 0.8);
        $maxDuration = (int) ($sourceHash->duration_seconds * 1.2);

        $candidates = DB::select("
            SELECT vh.id, vh.file_registry_id, vh.combined_hash, vh.duration_seconds, vh.keyframe_count,
                   fr.current_path, fr.filename
            FROM file_registry_video_hashes vh
            JOIN file_registry fr ON fr.id = vh.file_registry_id
            WHERE vh.id != ?
            AND fr.status = 'active'
            AND vh.duration_seconds BETWEEN ? AND ?
        ", [$hashId, $minDuration, $maxDuration]);

        $results = [];
        foreach ($candidates as $candidate) {
            $similarity = $this->compareVideos($hashId, $candidate->id);

            if ($similarity >= $threshold) {
                $results[] = [
                    'hash_id' => $candidate->id,
                    'file_registry_id' => $candidate->file_registry_id,
                    'current_path' => $candidate->current_path,
                    'filename' => $candidate->filename,
                    'similarity_score' => round($similarity, 4),
                    'duration_seconds' => $candidate->duration_seconds,
                    'classification' => $this->classifySimilarity($similarity),
                ];
            }
        }

        // Sort by similarity descending
        usort($results, fn($a, $b) => $b['similarity_score'] <=> $a['similarity_score']);

        return $results;
    }

    /**
     * Classify similarity level
     */
    private function classifySimilarity(float $score): string
    {
        return match (true) {
            $score >= self::SIMILARITY_EXACT => 'exact',
            $score >= self::SIMILARITY_NEAR_DUPLICATE => 'near_duplicate',
            $score >= self::SIMILARITY_SIMILAR => 'similar',
            default => 'different',
        };
    }

    /**
     * Index a video file in the registry
     *
     * @param int $fileRegistryId ID of file in file_registry table
     * @return int Video hash ID
     * @throws Exception On hashing failure
     */
    public function indexVideo(int $fileRegistryId): int
    {
        // Get file path from registry
        $file = DB::selectOne(
            "SELECT current_path, filename FROM file_registry WHERE id = ?",
            [$fileRegistryId]
        );

        if (!$file) {
            throw new Exception("File registry entry not found: {$fileRegistryId}");
        }

        $filePath = $file->current_path;
        if (!file_exists($filePath)) {
            throw new Exception("Video file not found: {$filePath}");
        }

        // Compute video hash
        $hashData = $this->hashVideo($filePath);

        // Insert or update hash record
        $existing = DB::selectOne(
            "SELECT id FROM file_registry_video_hashes WHERE file_registry_id = ?",
            [$fileRegistryId]
        );

        if ($existing) {
            DB::update("
                UPDATE file_registry_video_hashes SET
                    duration_seconds = ?,
                    keyframe_count = ?,
                    keyframe_hashes = ?,
                    combined_hash = ?,
                    width = ?,
                    height = ?,
                    codec = ?,
                    fps = ?,
                    updated_at = NOW()
                WHERE id = ?
            ", [
                $hashData['duration_seconds'],
                $hashData['keyframe_count'],
                json_encode($hashData['keyframe_hashes']),
                $hashData['combined_hash'],
                $hashData['width'],
                $hashData['height'],
                $hashData['codec'],
                $hashData['fps'],
                $existing->id,
            ]);

            $hashId = $existing->id;
        } else {
            DB::insert("
                INSERT INTO file_registry_video_hashes
                (file_registry_id, duration_seconds, keyframe_count, keyframe_hashes, combined_hash,
                 width, height, codec, fps, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $fileRegistryId,
                $hashData['duration_seconds'],
                $hashData['keyframe_count'],
                json_encode($hashData['keyframe_hashes']),
                $hashData['combined_hash'],
                $hashData['width'],
                $hashData['height'],
                $hashData['codec'],
                $hashData['fps'],
            ]);

            $hashId = (int) DB::getPdo()->lastInsertId();
        }

        Log::info('VideoHashService: Video indexed', [
            'file_registry_id' => $fileRegistryId,
            'hash_id' => $hashId,
            'keyframes' => $hashData['keyframe_count'],
            'duration' => $hashData['duration_seconds'],
        ]);

        return $hashId;
    }

    /**
     * Find and record similar videos for a given video hash
     *
     * @param int $hashId Video hash ID
     * @param float $threshold Minimum similarity score
     * @return array Stats about found and recorded similarities
     */
    public function findAndRecordSimilar(int $hashId, float $threshold = 0.85): array
    {
        $similar = $this->findSimilarVideos($hashId, $threshold);
        $recorded = 0;

        foreach ($similar as $match) {
            // Ensure ordered pair (smaller ID first)
            $id1 = min($hashId, $match['hash_id']);
            $id2 = max($hashId, $match['hash_id']);

            // Skip 'different' classifications
            if ($match['classification'] === 'different') {
                continue;
            }

            // Calculate matched keyframes count
            $matchedKeyframes = $this->countMatchedKeyframes($hashId, $match['hash_id']);

            // Insert or update similar pair
            $existing = DB::selectOne(
                "SELECT id FROM file_registry_similar_videos WHERE video_hash_id_1 = ? AND video_hash_id_2 = ?",
                [$id1, $id2]
            );

            if ($existing) {
                DB::update("
                    UPDATE file_registry_similar_videos SET
                        similarity_score = ?,
                        matched_keyframes = ?
                    WHERE id = ?
                ", [$match['similarity_score'], $matchedKeyframes, $existing->id]);
            } else {
                DB::insert("
                    INSERT INTO file_registry_similar_videos
                    (video_hash_id_1, video_hash_id_2, similarity_score, matched_keyframes, status, created_at)
                    VALUES (?, ?, ?, ?, 'pending_review', NOW())
                ", [$id1, $id2, $match['similarity_score'], $matchedKeyframes]);
            }

            $recorded++;
        }

        return [
            'found' => count($similar),
            'recorded' => $recorded,
        ];
    }

    /**
     * Count matched keyframes between two videos
     */
    private function countMatchedKeyframes(int $hashId1, int $hashId2): int
    {
        $hash1 = DB::selectOne(
            "SELECT keyframe_hashes FROM file_registry_video_hashes WHERE id = ?",
            [$hashId1]
        );
        $hash2 = DB::selectOne(
            "SELECT keyframe_hashes FROM file_registry_video_hashes WHERE id = ?",
            [$hashId2]
        );

        if (!$hash1 || !$hash2) {
            return 0;
        }

        $frames1 = json_decode($hash1->keyframe_hashes, true) ?? [];
        $frames2 = json_decode($hash2->keyframe_hashes, true) ?? [];

        $matched = 0;
        foreach ($frames1 as $f1) {
            foreach ($frames2 as $f2) {
                $distance = $this->imageHasher->hammingDistance(
                    $f1['phash'] ?? '',
                    $f2['phash'] ?? ''
                );
                if ($distance < self::KEYFRAME_MATCH_THRESHOLD) {
                    $matched++;
                    break; // Count each frame only once
                }
            }
        }

        return $matched;
    }

    /**
     * Get statistics about video hashes in the system
     */
    public function getStatistics(): array
    {
        $hashStats = DB::selectOne("
            SELECT
                COUNT(*) as total_hashes,
                COUNT(DISTINCT file_registry_id) as unique_files,
                AVG(duration_seconds) as avg_duration,
                AVG(keyframe_count) as avg_keyframes,
                SUM(duration_seconds) as total_duration
            FROM file_registry_video_hashes
        ");

        $similarStats = DB::selectOne("
            SELECT
                COUNT(*) as total_pairs,
                AVG(similarity_score) as avg_similarity,
                SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) as pending_review,
                SUM(CASE WHEN status = 'confirmed_duplicate' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN similarity_score >= 0.95 THEN 1 ELSE 0 END) as exact_matches,
                SUM(CASE WHEN similarity_score >= 0.85 AND similarity_score < 0.95 THEN 1 ELSE 0 END) as near_duplicates,
                SUM(CASE WHEN similarity_score >= 0.70 AND similarity_score < 0.85 THEN 1 ELSE 0 END) as similar
            FROM file_registry_similar_videos
        ");

        return [
            'total_hashes' => (int) ($hashStats->total_hashes ?? 0),
            'unique_files' => (int) ($hashStats->unique_files ?? 0),
            'avg_duration_seconds' => round((float) ($hashStats->avg_duration ?? 0), 1),
            'avg_keyframes' => round((float) ($hashStats->avg_keyframes ?? 0), 1),
            'total_duration_hours' => round((float) ($hashStats->total_duration ?? 0) / 3600, 1),
            'similar_pairs' => [
                'total' => (int) ($similarStats->total_pairs ?? 0),
                'exact' => (int) ($similarStats->exact_matches ?? 0),
                'near_duplicate' => (int) ($similarStats->near_duplicates ?? 0),
                'similar' => (int) ($similarStats->similar ?? 0),
                'avg_similarity' => round((float) ($similarStats->avg_similarity ?? 0), 4),
            ],
            'review_status' => [
                'pending' => (int) ($similarStats->pending_review ?? 0),
                'confirmed' => (int) ($similarStats->confirmed ?? 0),
            ],
            'ffmpeg_available' => $this->ffmpegAvailable,
        ];
    }

    /**
     * Get pending similar video pairs for review
     */
    public function getPendingReviewPairs(int $limit = 50): array
    {
        return DB::select("
            SELECT
                sv.id,
                sv.video_hash_id_1,
                sv.video_hash_id_2,
                sv.similarity_score,
                sv.matched_keyframes,
                sv.created_at,
                vh1.file_registry_id as file_id_1,
                vh2.file_registry_id as file_id_2,
                vh1.duration_seconds as duration_1,
                vh2.duration_seconds as duration_2,
                fr1.current_path as path_1,
                fr1.filename as filename_1,
                fr2.current_path as path_2,
                fr2.filename as filename_2
            FROM file_registry_similar_videos sv
            JOIN file_registry_video_hashes vh1 ON vh1.id = sv.video_hash_id_1
            JOIN file_registry_video_hashes vh2 ON vh2.id = sv.video_hash_id_2
            JOIN file_registry fr1 ON fr1.id = vh1.file_registry_id
            JOIN file_registry fr2 ON fr2.id = vh2.file_registry_id
            WHERE sv.status = 'pending_review'
            ORDER BY sv.similarity_score DESC
            LIMIT ?
        ", [$limit]);
    }

    /**
     * Update review status for a similar video pair
     */
    public function updateReviewStatus(int $similarPairId, string $status): bool
    {
        $validStatuses = ['pending_review', 'confirmed_duplicate', 'false_positive', 'different_versions'];
        if (!in_array($status, $validStatuses)) {
            return false;
        }

        $affected = DB::update("
            UPDATE file_registry_similar_videos
            SET status = ?, reviewed_at = NOW()
            WHERE id = ?
        ", [$status, $similarPairId]);

        return $affected > 0;
    }
}
