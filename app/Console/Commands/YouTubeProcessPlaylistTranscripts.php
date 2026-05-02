<?php

namespace App\Console\Commands;

use App\Services\YouTubeApiService;
use App\Services\YouTubeTranscriptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Process YouTube Playlist Transcripts with Rate Limiting Awareness
 *
 * This command fetches transcripts for videos in a YouTube playlist
 * with intelligent rate limiting, exponential backoff, and resume capability.
 * Designed to work around YouTube's aggressive rate limiting.
 */
class YouTubeProcessPlaylistTranscripts extends Command
{
    protected $signature = 'youtube:process-playlist-transcripts
                            {--playlist=WL : Playlist ID (WL for Watch Later)}
                            {--delay=60 : Delay in seconds between videos}
                            {--batch=3 : Number of videos per batch}
                            {--batch-delay=300 : Delay in seconds between batches}
                            {--max=0 : Maximum videos to process (0 for all)}
                            {--skip-cached : Skip videos that already have cached transcripts}
                            {--resume : Resume from last processed video}
                            {--dry-run : Show what would be processed without fetching}';

    protected $description = 'Process YouTube playlist transcripts with rate limiting awareness';

    private string $progressCacheKey = 'youtube:playlist_transcript_progress';

    public function handle(): int
    {
        $playlistId = $this->option('playlist');
        $delay = (int) $this->option('delay');
        $batchSize = (int) $this->option('batch');
        $batchDelay = (int) $this->option('batch-delay');
        $maxVideos = (int) $this->option('max');
        $skipCached = $this->option('skip-cached');
        $resume = $this->option('resume');
        $dryRun = $this->option('dry-run');

        $this->info("🎬 YouTube Playlist Transcript Processor");
        $this->info("═══════════════════════════════════════════");
        $this->newLine();

        $this->table(['Option', 'Value'], [
            ['Playlist', $playlistId],
            ['Delay between videos', "{$delay}s"],
            ['Batch size', $batchSize],
            ['Batch delay', "{$batchDelay}s"],
            ['Max videos', $maxVideos ?: 'All'],
            ['Skip cached', $skipCached ? 'Yes' : 'No'],
            ['Resume', $resume ? 'Yes' : 'No'],
            ['Dry run', $dryRun ? 'Yes' : 'No'],
        ]);
        $this->newLine();

        // Get YouTube API service
        $youtubeApi = new YouTubeApiService();
        $transcriptService = new YouTubeTranscriptService();

        // Get playlist videos
        $this->info("Fetching playlist videos...");
        $playlistResult = $youtubeApi->getPlaylistItems($playlistId, 50, null, true);

        // Handle both raw YouTube API response and wrapped response
        $items = $playlistResult['items'] ?? [];

        if (empty($items)) {
            $this->error("No videos found in playlist or API error");
            if (isset($playlistResult['error'])) {
                $this->error("Error: " . $playlistResult['error']);
            }
            return self::FAILURE;
        }

        // Transform items to extract video_id and title
        $videos = [];
        foreach ($items as $item) {
            $videoId = $item['snippet']['resourceId']['videoId'] ?? $item['video_id'] ?? null;
            $title = $item['snippet']['title'] ?? $item['title'] ?? 'Unknown';

            if ($videoId) {
                $videos[] = [
                    'video_id' => $videoId,
                    'title' => $title,
                    'channel_title' => $item['snippet']['videoOwnerChannelTitle'] ?? $item['channel_title'] ?? '',
                    'published_at' => $item['snippet']['publishedAt'] ?? $item['published_at'] ?? '',
                ];
            }
        }

        $totalVideos = count($videos);
        $this->info("Found {$totalVideos} videos in playlist");

        // Check for resume point
        $startIndex = 0;
        if ($resume) {
            $progress = Cache::get($this->progressCacheKey);
            if ($progress && isset($progress['last_index'])) {
                $startIndex = $progress['last_index'] + 1;
                $this->info("Resuming from video #{$startIndex} ({$progress['last_video_id']})");
            }
        }

        // Filter videos
        $videosToProcess = array_slice($videos, $startIndex);
        if ($maxVideos > 0) {
            $videosToProcess = array_slice($videosToProcess, 0, $maxVideos);
        }

        $this->info("Processing " . count($videosToProcess) . " videos");
        $this->newLine();

        if ($dryRun) {
            $this->warn("DRY RUN - Not actually fetching transcripts");
            foreach ($videosToProcess as $index => $video) {
                $actualIndex = $startIndex + $index;
                $this->line("[{$actualIndex}] {$video['video_id']} - {$video['title']}");
            }
            return self::SUCCESS;
        }

        // Process videos
        $successCount = 0;
        $failCount = 0;
        $skippedCount = 0;
        $rateLimited = false;

        $bar = $this->output->createProgressBar(count($videosToProcess));
        $bar->start();

        foreach ($videosToProcess as $index => $video) {
            $actualIndex = $startIndex + $index;
            $videoId = $video['video_id'];
            $title = $video['title'] ?? 'Unknown';

            // Check for cached transcript
            if ($skipCached) {
                $cached = Cache::get("youtube_transcript:{$videoId}:en");
                if ($cached && ($cached['word_count'] ?? 0) > 0) {
                    $skippedCount++;
                    $bar->advance();
                    continue;
                }
            }

            // Save progress
            Cache::put($this->progressCacheKey, [
                'last_index' => $actualIndex,
                'last_video_id' => $videoId,
                'timestamp' => now()->toIso8601String(),
            ], now()->addDays(7));

            // Fetch transcript with backoff
            Log::info("Processing video transcript", [
                'index' => $actualIndex,
                'video_id' => $videoId,
                'title' => $title
            ]);

            $result = $transcriptService->getTranscriptWithBackoff($videoId, 'en', true, 0);

            if ($result['success'] && ($result['word_count'] ?? 0) > 0) {
                $successCount++;
                $bar->advance();

                Log::info("Transcript fetched successfully", [
                    'video_id' => $videoId,
                    'word_count' => $result['word_count'],
                    'method' => $result['method'] ?? 'unknown'
                ]);

            } else {
                $errorType = $result['error_type'] ?? 'Unknown';
                $failCount++;
                $bar->advance();

                Log::warning("Transcript fetch failed", [
                    'video_id' => $videoId,
                    'error_type' => $errorType,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);

                // Check for rate limiting
                if ($errorType === 'TooManyRequests') {
                    $rateLimited = true;
                    $this->newLine();
                    $this->warn("⚠️  Rate limited! Increasing delay and continuing...");

                    // Double the delay when rate limited
                    $delay = min($delay * 2, 600); // Max 10 minutes
                    $batchDelay = min($batchDelay * 2, 1800); // Max 30 minutes

                    $this->info("New delay: {$delay}s, New batch delay: {$batchDelay}s");
                }
            }

            // Delay between videos
            if ($index < count($videosToProcess) - 1) {
                // Batch delay
                if (($index + 1) % $batchSize === 0) {
                    $this->newLine();
                    $this->info("Batch complete. Waiting {$batchDelay}s before next batch...");
                    sleep($batchDelay);
                } else {
                    sleep($delay);
                }
            }
        }

        $bar->finish();
        $this->newLine(2);

        // Summary
        $this->info("═══════════════════════════════════════════");
        $this->info("Processing Complete");
        $this->info("═══════════════════════════════════════════");
        $this->newLine();

        $this->table(['Metric', 'Count'], [
            ['Total processed', count($videosToProcess)],
            ['Successful', $successCount],
            ['Failed', $failCount],
            ['Skipped (cached)', $skippedCount],
            ['Rate limited', $rateLimited ? 'Yes' : 'No'],
        ]);

        // Clear progress if completed all
        if ($actualIndex >= $totalVideos - 1) {
            Cache::forget($this->progressCacheKey);
            $this->info("✅ All videos processed!");
        } else {
            $this->warn("⚠️  Not all videos processed. Use --resume to continue.");
        }

        return $successCount > 0 ? self::SUCCESS : self::FAILURE;
    }
}
