<?php

namespace App\Nodes\YouTube;

use App\Nodes\BaseNode;
use App\Services\YouTubeApiService;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * YouTube Subscriptions Node
 *
 * Fetches recent videos from YouTube subscriptions with multi-tier filtering:
 * - Tier 1: Priority channels (always process)
 * - Tier 2: Keyword-filtered channels (process if title matches keywords)
 */
class YouTubeSubscriptions extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $enabled = config('youtube.enabled', false);
            if (!$enabled) {
                throw new Exception('YouTube integration is disabled. Set YOUTUBE_ENABLED=true in .env');
            }

            // Get configuration
            $tier1Channels = $this->getConfigValue('filter_channels', []);
            $tier2Channels = $this->getConfigValue('tier2_channels', []);
            $tier2Keywords = $this->getConfigValue('tier2_keywords', []);
            $maxAgeHours = $this->getConfigValue('max_age_hours', 24);
            $minDuration = $this->getConfigValue('min_duration', 10); // minutes
            $maxDuration = $this->getConfigValue('max_duration', 60); // minutes
            $limit = $this->getConfigValue('limit', 10);

            Log::info('YouTubeSubscriptions: Starting execution', [
                'tier1_count' => count($tier1Channels),
                'tier2_count' => count($tier2Channels),
                'tier2_keywords' => $tier2Keywords,
                'max_age_hours' => $maxAgeHours,
                'limit' => $limit
            ]);

            $youtubeApi = new YouTubeApiService();

            // Calculate cutoff time
            $cutoffTime = now()->subHours($maxAgeHours);

            // Fetch all subscriptions
            $allVideos = [];
            $subscriptions = $youtubeApi->getSubscriptions(50, null, true);

            foreach ($subscriptions['items'] ?? [] as $subscription) {
                $channelId = $subscription['snippet']['resourceId']['channelId'];
                $channelTitle = $subscription['snippet']['title'];

                // Determine tier
                $isTier1 = in_array($channelId, $tier1Channels);
                $isTier2 = in_array($channelId, $tier2Channels);

                if (!$isTier1 && !$isTier2) {
                    continue; // Skip channels not in any tier
                }

                Log::debug('Processing channel', [
                    'channel' => $channelTitle,
                    'channel_id' => $channelId,
                    'tier' => $isTier1 ? 1 : 2
                ]);

                // Fetch channel's recent uploads
                $channelVideos = $youtubeApi->getChannelUploads(
                    $channelId,
                    10,
                    $cutoffTime,
                    true
                );

                foreach ($channelVideos['items'] ?? [] as $video) {
                    $videoId = $video['id']['videoId'] ?? null;
                    if (!$videoId) {
                        continue;
                    }

                    // Get video details for duration and metadata
                    $videoDetails = $youtubeApi->getVideoDetails([$videoId], true);
                    if (empty($videoDetails['items'])) {
                        continue;
                    }

                    $videoData = $videoDetails['items'][0];
                    $title = $videoData['snippet']['title'];
                    $publishedAt = new \DateTime($videoData['snippet']['publishedAt']);
                    $duration = YouTubeApiService::parseDuration($videoData['contentDetails']['duration']);
                    $durationMinutes = $duration / 60;

                    // Apply duration filters
                    if ($durationMinutes < $minDuration || $durationMinutes > $maxDuration) {
                        Log::debug('Video filtered by duration', [
                            'video' => $title,
                            'duration_minutes' => $durationMinutes
                        ]);
                        continue;
                    }

                    // Apply tier-specific filtering
                    if ($isTier2 && !empty($tier2Keywords)) {
                        $matchesKeyword = false;
                        foreach ($tier2Keywords as $keyword) {
                            if (stripos($title, $keyword) !== false) {
                                $matchesKeyword = true;
                                break;
                            }
                        }

                        if (!$matchesKeyword) {
                            Log::debug('Tier 2 video filtered by keywords', [
                                'video' => $title,
                                'keywords' => $tier2Keywords
                            ]);
                            continue;
                        }
                    }

                    // Video passed all filters
                    $allVideos[] = [
                        'video_id' => $videoId,
                        'title' => $title,
                        'channel_id' => $channelId,
                        'channel_title' => $channelTitle,
                        'published_at' => $publishedAt->format('Y-m-d H:i:s'),
                        'duration_seconds' => $duration,
                        'duration_formatted' => gmdate('H:i:s', $duration),
                        'thumbnail' => $videoData['snippet']['thumbnails']['high']['url'] ?? null,
                        'description' => $videoData['snippet']['description'] ?? '',
                        'view_count' => (int)($videoData['statistics']['viewCount'] ?? 0),
                        'like_count' => (int)($videoData['statistics']['likeCount'] ?? 0),
                        'url' => "https://youtube.com/watch?v={$videoId}",
                        'tier' => $isTier1 ? 1 : 2,
                    ];

                    Log::info('Video accepted', [
                        'video' => $title,
                        'channel' => $channelTitle,
                        'tier' => $isTier1 ? 1 : 2
                    ]);
                }
            }

            // Sort by published date (newest first)
            usort($allVideos, function ($a, $b) {
                return strtotime($b['published_at']) - strtotime($a['published_at']);
            });

            // Limit results
            $limitedVideos = array_slice($allVideos, 0, $limit);

            Log::info('YouTubeSubscriptions: Execution completed', [
                'total_found' => count($allVideos),
                'returned' => count($limitedVideos)
            ]);

            return $this->standardOutput([
                'videos' => $limitedVideos,
                'count' => count($limitedVideos),
            ], [
                'total_found' => count($allVideos),
                'tier1_channels' => count($tier1Channels),
                'tier2_channels' => count($tier2Channels),
                'cutoff_time' => $cutoffTime->toISOString(),
            ]);

        } catch (Exception $e) {
            Log::error('YouTubeSubscriptions: Execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->standardOutput([], [], $e->getMessage());
        }
    }
}
