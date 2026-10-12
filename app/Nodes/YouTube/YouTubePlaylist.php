<?php

namespace App\Nodes\YouTube;

use App\Nodes\BaseNode;
use App\Services\YouTubeApiService;
use App\Services\YouTubeJoplinDeliveryService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * YouTube Playlist Node
 *
 * Fetches videos from a YouTube playlist (typically Watch Later = 'WL').
 * Supports tracking last processed video to avoid duplicates.
 */
class YouTubePlaylist extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $enabled = config('youtube.enabled', false);
            if (! $enabled) {
                throw new Exception('YouTube integration is disabled. Set YOUTUBE_ENABLED=true in .env');
            }

            // Get configuration
            $playlistId = $this->getConfigValue('playlist_id', 'WL'); // WL = Watch Later
            $sinceLastRun = $this->getConfigValue('since_last_run', true);
            $configuredLimit = $this->getConfigValue('limit', 10); // Default to 10 for safety
            $firstRunLimit = $this->getConfigValue('first_run_limit', 10); // Limit when no cache exists

            $youtubeApi = app(YouTubeApiService::class);
            $delivery = app(YouTubeJoplinDeliveryService::class);

            // Get last processed video ID and its date if tracking enabled
            $lastProcessedId = null;
            $lastProcessedDate = null;
            $isCacheMiss = false;

            if ($sinceLastRun) {
                $lastProcessedData = $this->getLastProcessedData($playlistId);
                $lastProcessedId = $lastProcessedData['video_id'] ?? null;
                $lastProcessedDate = $lastProcessedData['added_date'] ?? null;

                // Detect cache miss - when since_last_run is enabled but no cache data exists
                $isCacheMiss = ($lastProcessedId === null && $lastProcessedDate === null);

                if ($isCacheMiss) {
                    Log::warning('YouTubePlaylist: Cache miss detected - no last processed data found', [
                        'playlist_id' => $playlistId,
                        'configured_limit' => $configuredLimit,
                        'applying_first_run_limit' => $firstRunLimit,
                    ]);
                } else {
                    Log::debug('Last processed video', [
                        'video_id' => $lastProcessedId,
                        'added_date' => $lastProcessedDate,
                    ]);
                }
            }

            // Use first_run_limit when cache is missing to prevent processing too many videos
            $limit = ($sinceLastRun && $isCacheMiss) ? min($configuredLimit, $firstRunLimit) : $configuredLimit;

            Log::info('YouTubePlaylist: Starting execution', [
                'playlist_id' => $playlistId,
                'since_last_run' => $sinceLastRun,
                'limit' => $limit,
                'cache_miss' => $isCacheMiss,
                'first_run_limit_applied' => ($sinceLastRun && $isCacheMiss),
            ]);

            // Fetch ALL playlist items first (up to reasonable limit)
            // NOTE: Must fetch ALL items because API returns in playlist order, not date order.
            // Newer videos may be at any position in the playlist, so we must fetch everything
            // then sort by date to find truly new videos.
            $allItems = [];
            $pageToken = null;
            $maxFetch = 1000; // Increased from 200 - must be higher than playlist size

            do {
                $response = $youtubeApi->getPlaylistItems($playlistId, 50, $pageToken, false);

                foreach ($response['items'] ?? [] as $item) {
                    $videoId = $item['contentDetails']['videoId'] ?? null;
                    if (! $videoId) {
                        continue;
                    }
                    $allItems[] = [
                        'video_id' => $videoId,
                        'added_to_playlist_at' => $item['snippet']['publishedAt'],
                        'item' => $item,
                    ];
                }

                $pageToken = $response['nextPageToken'] ?? null;

            } while ($pageToken && count($allItems) < $maxFetch);

            Log::info('YouTube playlist items fetched', [
                'playlist_id' => $playlistId,
                'total_items' => count($allItems),
            ]);

            // A legacy discovery watermark is not proof of downstream delivery.
            $pending = $delivery->pendingItems($playlistId, $allItems);
            if (! $sinceLastRun) {
                $heldVideos = array_column($delivery->heldSourceWrites(), 'video_id');
                $pending = array_values(array_filter($allItems, fn (array $item): bool => ! in_array($item['video_id'], $heldVideos, true)));
            }
            $newItems = array_slice($pending, 0, $limit);
            $foundLastProcessed = false;
            foreach ($newItems as $itemData) {
                $delivery->attempted($playlistId, $itemData);
            }

            // Now fetch full video details for new items
            $videos = [];
            foreach ($newItems as $itemData) {
                $videoId = $itemData['video_id'];
                $item = $itemData['item'];

                $videoDetails = $youtubeApi->getVideoDetails([$videoId], true);
                if (empty($videoDetails['items'])) {
                    continue;
                }

                $videoData = $videoDetails['items'][0];
                $duration = YouTubeApiService::parseDuration($videoData['contentDetails']['duration']);

                $videos[] = [
                    'video_id' => $videoId,
                    'title' => $videoData['snippet']['title'],
                    'channel_id' => $videoData['snippet']['channelId'],
                    'channel_title' => $videoData['snippet']['channelTitle'],
                    'published_at' => $videoData['snippet']['publishedAt'],
                    'added_to_playlist_at' => $item['snippet']['publishedAt'],
                    'duration_seconds' => $duration,
                    'duration_formatted' => gmdate('H:i:s', $duration),
                    'thumbnail' => $videoData['snippet']['thumbnails']['high']['url'] ?? null,
                    'description' => $videoData['snippet']['description'] ?? '',
                    'view_count' => (int) ($videoData['statistics']['viewCount'] ?? 0),
                    'like_count' => (int) ($videoData['statistics']['likeCount'] ?? 0),
                    'url' => "https://youtube.com/watch?v={$videoId}",
                    'tier' => 3, // Watch Later is always Tier 3
                ];
            }

            Log::info('YouTubePlaylist: Execution completed', [
                'playlist_id' => $playlistId,
                'videos_found' => count($videos),
                'found_last_processed' => $foundLastProcessed,
                'cache_miss' => $isCacheMiss,
                'limit_used' => $limit,
            ]);

            return $this->standardOutput([
                'videos' => $videos,
                'count' => count($videos),
                'playlist_id' => $playlistId,
                'delivery_candidates' => array_map(fn (array $item): array => [
                    'video_id' => $item['video_id'], 'added_to_playlist_at' => $item['added_to_playlist_at'],
                ], $newItems),
                'delivery_backlog' => count($pending),
            ], [
                'playlist_id' => $playlistId,
                'since_last_run' => $sinceLastRun,
                'last_processed_id' => $lastProcessedId,
            ]);

        } catch (Exception $e) {
            Log::error('YouTubePlaylist: Execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->standardOutput([], [], $e->getMessage());
        }
    }

    /**
     * Get last processed video data for a playlist from database
     * More reliable than cache - survives restarts and cache clears
     *
     * @return array{video_id: string|null, added_date: string|null}
     */
    private function getLastProcessedData(string $playlistId): array
    {
        $record = DB::selectOne(
            'SELECT * FROM youtube_playlist_progress WHERE playlist_id = ?',
            [$playlistId]
        );

        if ($record) {
            return [
                'video_id' => $record->last_video_id,
                'added_date' => $record->last_video_added_at,
            ];
        }

        return ['video_id' => null, 'added_date' => null];
    }
}
