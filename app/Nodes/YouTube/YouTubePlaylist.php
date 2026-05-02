<?php

namespace App\Nodes\YouTube;

use App\Nodes\BaseNode;
use App\Services\YouTubeApiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

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
            if (!$enabled) {
                throw new Exception('YouTube integration is disabled. Set YOUTUBE_ENABLED=true in .env');
            }

            // Get configuration
            $playlistId = $this->getConfigValue('playlist_id', 'WL'); // WL = Watch Later
            $sinceLastRun = $this->getConfigValue('since_last_run', true);
            $configuredLimit = $this->getConfigValue('limit', 10); // Default to 10 for safety
            $firstRunLimit = $this->getConfigValue('first_run_limit', 10); // Limit when no cache exists

            $youtubeApi = new YouTubeApiService();

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
                        'applying_first_run_limit' => $firstRunLimit
                    ]);
                } else {
                    Log::debug('Last processed video', [
                        'video_id' => $lastProcessedId,
                        'added_date' => $lastProcessedDate
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
                'first_run_limit_applied' => ($sinceLastRun && $isCacheMiss)
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
                    if (!$videoId) {
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
                'total_items' => count($allItems)
            ]);

            // Sort by added_to_playlist_at descending (newest first)
            usort($allItems, function ($a, $b) {
                return strcmp($b['added_to_playlist_at'], $a['added_to_playlist_at']);
            });

            // Filter to only new videos (added after last processed)
            $newItems = [];
            $foundLastProcessed = false;

            foreach ($allItems as $itemData) {
                // If tracking enabled, check if this is newer than last processed
                if ($sinceLastRun && $lastProcessedDate) {
                    // Stop if we've reached or passed the last processed date
                    if ($itemData['added_to_playlist_at'] <= $lastProcessedDate) {
                        $foundLastProcessed = true;
                        Log::info('Reached last processed date, stopping', [
                            'video_id' => $itemData['video_id'],
                            'added_date' => $itemData['added_to_playlist_at'],
                            'last_processed_date' => $lastProcessedDate
                        ]);
                        break;
                    }
                } elseif ($sinceLastRun && $lastProcessedId) {
                    // Fallback: check by video ID if no date stored
                    if ($itemData['video_id'] === $lastProcessedId) {
                        $foundLastProcessed = true;
                        Log::info('Found last processed video by ID, stopping', [
                            'video_id' => $itemData['video_id']
                        ]);
                        break;
                    }
                }

                $newItems[] = $itemData;

                // Limit results
                if (count($newItems) >= $limit) {
                    break;
                }
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
                    'view_count' => (int)($videoData['statistics']['viewCount'] ?? 0),
                    'like_count' => (int)($videoData['statistics']['likeCount'] ?? 0),
                    'url' => "https://youtube.com/watch?v={$videoId}",
                    'tier' => 3, // Watch Later is always Tier 3
                ];
            }

            // Update last processed data with the newest video
            if (!empty($videos) && $sinceLastRun) {
                $this->setLastProcessedData($playlistId, $videos[0]['video_id'], $videos[0]['added_to_playlist_at']);
            }

            Log::info('YouTubePlaylist: Execution completed', [
                'playlist_id' => $playlistId,
                'videos_found' => count($videos),
                'found_last_processed' => $foundLastProcessed,
                'cache_miss' => $isCacheMiss,
                'limit_used' => $limit
            ]);

            return $this->standardOutput([
                'videos' => $videos,
                'count' => count($videos),
            ], [
                'playlist_id' => $playlistId,
                'since_last_run' => $sinceLastRun,
                'last_processed_id' => $videos[0]['video_id'] ?? null,
            ]);

        } catch (Exception $e) {
            Log::error('YouTubePlaylist: Execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->standardOutput([], [], $e->getMessage());
        }
    }

    /**
     * Get last processed video data for a playlist from database
     * More reliable than cache - survives restarts and cache clears
     *
     * @param string $playlistId
     * @return array{video_id: string|null, added_date: string|null}
     */
    private function getLastProcessedData(string $playlistId): array
    {
        $record = DB::selectOne(
            "SELECT * FROM youtube_playlist_progress WHERE playlist_id = ?",
            [$playlistId]
        );

        if ($record) {
            return [
                'video_id' => $record->last_video_id,
                'added_date' => $record->last_video_added_at
            ];
        }

        return ['video_id' => null, 'added_date' => null];
    }

    /**
     * Set last processed video data for a playlist in database
     * More reliable than cache - survives restarts and cache clears
     *
     * @param string $playlistId
     * @param string $videoId
     * @param string $addedDate ISO 8601 date when video was added to playlist
     * @return void
     */
    private function setLastProcessedData(string $playlistId, string $videoId, string $addedDate): void
    {
        // Convert ISO 8601 date to MySQL datetime format
        $parsedDate = \Carbon\Carbon::parse($addedDate)->format('Y-m-d H:i:s');

        // Check if record exists
        $existing = DB::selectOne("SELECT id FROM youtube_playlist_progress WHERE playlist_id = ?", [$playlistId]);

        if ($existing) {
            DB::update(
                "UPDATE youtube_playlist_progress SET last_video_id = ?, last_video_added_at = ?, last_run_at = ?, videos_processed_count = videos_processed_count + 1, updated_at = ? WHERE playlist_id = ?",
                [$videoId, $parsedDate, now(), now(), $playlistId]
            );
        } else {
            DB::insert(
                "INSERT INTO youtube_playlist_progress (playlist_id, last_video_id, last_video_added_at, last_run_at, videos_processed_count, updated_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$playlistId, $videoId, $parsedDate, now(), 1, now(), now()]
            );
        }

        Log::debug('Updated last processed video data in DB', [
            'playlist_id' => $playlistId,
            'video_id' => $videoId,
            'added_date' => $parsedDate
        ]);
    }
}
