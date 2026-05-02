<?php

namespace App\Console\Commands;

use App\Services\VideoHashService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Video Perceptual Hash Management Command
 *
 * Compute and compare video fingerprints for duplicate detection.
 *
 * Usage:
 *   php artisan video:hash --status
 *   php artisan video:hash --hash="/path/to/video.mp4"
 *   php artisan video:hash --index=123 (file_registry_id)
 *   php artisan video:hash --compare=1,2 (hash_ids)
 *   php artisan video:hash --find-similar=1 --threshold=0.85
 *   php artisan video:hash --pending
 *   php artisan video:hash --batch --limit=10
 */
class VideoHashCommand extends Command
{
    protected $signature = 'video:hash
        {--status : Show video hash statistics}
        {--hash= : Hash a video file by path}
        {--index= : Index a video by file_registry_id}
        {--compare= : Compare two videos by hash IDs (comma-separated)}
        {--find-similar= : Find videos similar to hash ID}
        {--threshold=0.85 : Similarity threshold for find-similar}
        {--pending : Show pending review pairs}
        {--batch : Process unindexed videos in batch}
        {--limit=10 : Batch limit}
        {--interval=10 : Keyframe extraction interval in seconds}';

    protected $description = 'Manage video perceptual hashes for duplicate detection';

    private VideoHashService $service;

    public function handle(): int
    {
        $this->service = app(VideoHashService::class);

        if (!$this->service->isFFmpegAvailable()) {
            $this->warn('FFmpeg is not installed or not in PATH. Video hashing will not work.');
            if (!$this->option('status')) {
                return 1;
            }
        }

        if ($this->option('status')) {
            return $this->showStatus();
        }

        if ($this->option('hash')) {
            return $this->hashVideo($this->option('hash'));
        }

        if ($this->option('index')) {
            return $this->indexVideo((int) $this->option('index'));
        }

        if ($this->option('compare')) {
            return $this->compareVideos($this->option('compare'));
        }

        if ($this->option('find-similar')) {
            return $this->findSimilar(
                (int) $this->option('find-similar'),
                (float) $this->option('threshold')
            );
        }

        if ($this->option('pending')) {
            return $this->showPending();
        }

        if ($this->option('batch')) {
            return $this->batchIndex((int) $this->option('limit'));
        }

        $this->showHelp();
        return 0;
    }

    private function showStatus(): int
    {
        $stats = $this->service->getStatistics();

        $this->info('Video Hash Statistics');
        $this->line('');
        $this->table(
            ['Metric', 'Value'],
            [
                ['FFmpeg Available', $stats['ffmpeg_available'] ? 'Yes' : 'No'],
                ['Total Hashes', number_format($stats['total_hashes'])],
                ['Unique Files', number_format($stats['unique_files'])],
                ['Avg Duration', $stats['avg_duration_seconds'] . ' seconds'],
                ['Avg Keyframes', $stats['avg_keyframes']],
                ['Total Duration', $stats['total_duration_hours'] . ' hours'],
            ]
        );

        $this->line('');
        $this->info('Similar Pairs');
        $this->table(
            ['Type', 'Count'],
            [
                ['Total Pairs', $stats['similar_pairs']['total']],
                ['Exact Matches (>=95%)', $stats['similar_pairs']['exact']],
                ['Near Duplicates (>=85%)', $stats['similar_pairs']['near_duplicate']],
                ['Similar (>=70%)', $stats['similar_pairs']['similar']],
                ['Avg Similarity', number_format($stats['similar_pairs']['avg_similarity'] * 100, 2) . '%'],
            ]
        );

        $this->line('');
        $this->info('Review Status');
        $this->table(
            ['Status', 'Count'],
            [
                ['Pending Review', $stats['review_status']['pending']],
                ['Confirmed', $stats['review_status']['confirmed']],
            ]
        );

        return 0;
    }

    private function hashVideo(string $filePath): int
    {
        $this->info("Hashing video: {$filePath}");

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        try {
            $interval = (int) $this->option('interval');
            $result = $this->service->hashVideo($filePath, $interval);

            $this->info('Hash computed successfully');
            $this->table(['Property', 'Value'], [
                ['Duration', $result['duration_seconds'] . ' seconds'],
                ['Keyframes', $result['keyframe_count']],
                ['Combined Hash', substr($result['combined_hash'], 0, 32) . '...'],
                ['Resolution', ($result['width'] ?? '?') . 'x' . ($result['height'] ?? '?')],
                ['Codec', $result['codec'] ?? 'unknown'],
                ['FPS', $result['fps'] ?? 'unknown'],
            ]);

            $this->line('');
            $this->info('Keyframe Hashes:');
            foreach ($result['keyframe_hashes'] as $kf) {
                $this->line(sprintf(
                    '  [%6.1fs] pHash: %s  dHash: %s...',
                    $kf['timestamp'],
                    $kf['phash'],
                    substr($kf['dhash'], 0, 16)
                ));
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to hash video: {$e->getMessage()}");
            return 1;
        }
    }

    private function indexVideo(int $fileRegistryId): int
    {
        $this->info("Indexing video for file_registry_id: {$fileRegistryId}");

        try {
            $hashId = $this->service->indexVideo($fileRegistryId);
            $this->info("Video indexed successfully. Hash ID: {$hashId}");

            // Find similar videos
            $similar = $this->service->findAndRecordSimilar($hashId);
            $this->info("Found {$similar['found']} similar videos, recorded {$similar['recorded']} pairs");

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to index video: {$e->getMessage()}");
            return 1;
        }
    }

    private function compareVideos(string $ids): int
    {
        $parts = explode(',', $ids);
        if (count($parts) !== 2) {
            $this->error('Please provide exactly two hash IDs, comma-separated');
            return 1;
        }

        $id1 = (int) trim($parts[0]);
        $id2 = (int) trim($parts[1]);

        $this->info("Comparing video hash IDs: {$id1} and {$id2}");

        $similarity = $this->service->compareVideos($id1, $id2);
        $percentage = round($similarity * 100, 2);

        $classification = match (true) {
            $similarity >= 0.95 => 'Exact Match',
            $similarity >= 0.85 => 'Near Duplicate',
            $similarity >= 0.70 => 'Similar',
            default => 'Different',
        };

        $this->info("Similarity: {$percentage}% ({$classification})");

        return 0;
    }

    private function findSimilar(int $hashId, float $threshold): int
    {
        $this->info("Finding videos similar to hash ID: {$hashId} (threshold: {$threshold})");

        $similar = $this->service->findSimilarVideos($hashId, $threshold);

        if (empty($similar)) {
            $this->info('No similar videos found.');
            return 0;
        }

        $this->info("Found " . count($similar) . " similar videos:");
        $this->table(
            ['Hash ID', 'File ID', 'Similarity', 'Type', 'Duration', 'Filename'],
            array_map(fn($v) => [
                $v['hash_id'],
                $v['file_registry_id'],
                number_format($v['similarity_score'] * 100, 2) . '%',
                $v['classification'],
                $v['duration_seconds'] . 's',
                basename($v['filename']),
            ], $similar)
        );

        return 0;
    }

    private function showPending(): int
    {
        $pairs = $this->service->getPendingReviewPairs(25);

        if (empty($pairs)) {
            $this->info('No pending review pairs.');
            return 0;
        }

        $this->info('Pending Review Pairs (' . count($pairs) . ' shown):');
        $this->table(
            ['ID', 'Similarity', 'Matched', 'Video 1', 'Video 2'],
            array_map(fn($p) => [
                $p->id,
                number_format($p->similarity_score * 100, 2) . '%',
                $p->matched_keyframes,
                basename($p->filename_1) . ' (' . $p->duration_1 . 's)',
                basename($p->filename_2) . ' (' . $p->duration_2 . 's)',
            ], $pairs)
        );

        return 0;
    }

    private function batchIndex(int $limit): int
    {
        $this->info("Batch indexing videos (limit: {$limit})");

        // Find video files in registry that haven't been hashed
        $videos = DB::select("
            SELECT fr.id, fr.current_path, fr.filename
            FROM file_registry fr
            LEFT JOIN file_registry_video_hashes vh ON vh.file_registry_id = fr.id
            WHERE fr.status = 'active'
            AND fr.extension IN ('mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'mpg', 'mpeg', '3gp')
            AND vh.id IS NULL
            LIMIT ?
        ", [$limit]);

        if (empty($videos)) {
            $this->info('No unindexed videos found.');
            return 0;
        }

        $this->info("Found " . count($videos) . " videos to index.");

        $success = 0;
        $failed = 0;

        foreach ($videos as $video) {
            $this->line("  Processing: {$video->filename}");

            try {
                $hashId = $this->service->indexVideo($video->id);
                $similar = $this->service->findAndRecordSimilar($hashId);
                $this->info("    Indexed (hash_id: {$hashId}, similar: {$similar['found']})");
                $success++;
            } catch (\Exception $e) {
                $this->warn("    Failed: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->line('');
        $this->info("Batch complete. Success: {$success}, Failed: {$failed}");

        return $failed > 0 ? 1 : 0;
    }

    private function showHelp(): void
    {
        $this->info('Video Perceptual Hash Management');
        $this->line('');
        $this->line('Usage:');
        $this->line('  --status                     Show hash statistics');
        $this->line('  --hash=<path>                Hash a video file');
        $this->line('  --index=<id>                 Index video by file_registry_id');
        $this->line('  --compare=<id1,id2>          Compare two video hashes');
        $this->line('  --find-similar=<id>          Find similar videos');
        $this->line('  --threshold=0.85             Similarity threshold');
        $this->line('  --pending                    Show pairs pending review');
        $this->line('  --batch --limit=10           Batch process unindexed videos');
        $this->line('  --interval=10                Keyframe interval (seconds)');
    }
}
