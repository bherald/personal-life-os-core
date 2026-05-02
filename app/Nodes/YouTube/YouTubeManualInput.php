<?php

namespace App\Nodes\YouTube;

use App\Nodes\BaseNode;
use App\Services\YouTubeApiService;
use App\Services\YouTubeTranscriptService;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * YouTube Manual Input Node
 *
 * Accepts a YouTube URL or video ID as input and fetches video metadata.
 * Used for manual/on-demand video processing workflows.
 */
class YouTubeManualInput extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            // Get configuration
            $acceptUrl = $this->getConfigValue('accept_url', true);
            $extractVideoId = $this->getConfigValue('extract_video_id', true);
            $validate = $this->getConfigValue('validate', true);

            // Get video URL/ID from input
            $videoInput = $input['url'] ?? $input['video_url'] ?? $input['video_id'] ?? null;

            if (!$videoInput) {
                throw new Exception('No YouTube URL or video ID provided in input');
            }

            Log::info('YouTubeManualInput: Processing manual input', [
                'input' => $videoInput
            ]);

            // Extract video ID if URL provided
            $videoId = $extractVideoId
                ? YouTubeTranscriptService::extractVideoId($videoInput)
                : $videoInput;

            if (!$videoId) {
                throw new Exception('Invalid YouTube URL or video ID: ' . $videoInput);
            }

            // Validate and fetch video metadata
            if ($validate) {
                $youtubeApi = new YouTubeApiService();
                $videoDetails = $youtubeApi->getVideoDetails([$videoId], false);

                if (empty($videoDetails['items'])) {
                    throw new Exception('Video not found or unavailable: ' . $videoId);
                }

                $videoData = $videoDetails['items'][0];
                $duration = YouTubeApiService::parseDuration($videoData['contentDetails']['duration']);

                $video = [
                    'video_id' => $videoId,
                    'title' => $videoData['snippet']['title'],
                    'channel_id' => $videoData['snippet']['channelId'],
                    'channel_title' => $videoData['snippet']['channelTitle'],
                    'published_at' => $videoData['snippet']['publishedAt'],
                    'duration_seconds' => $duration,
                    'duration_formatted' => gmdate('H:i:s', $duration),
                    'thumbnail' => $videoData['snippet']['thumbnails']['high']['url'] ?? null,
                    'description' => $videoData['snippet']['description'] ?? '',
                    'view_count' => (int)($videoData['statistics']['viewCount'] ?? 0),
                    'like_count' => (int)($videoData['statistics']['likeCount'] ?? 0),
                    'url' => "https://youtube.com/watch?v={$videoId}",
                    'tier' => 'manual', // Manual processing
                ];

                Log::info('YouTubeManualInput: Video validated', [
                    'video_id' => $videoId,
                    'title' => $video['title']
                ]);

            } else {
                // Minimal video data without validation
                $video = [
                    'video_id' => $videoId,
                    'url' => "https://youtube.com/watch?v={$videoId}",
                    'tier' => 'manual',
                ];

                Log::info('YouTubeManualInput: Video ID extracted (not validated)', [
                    'video_id' => $videoId
                ]);
            }

            // Return video as array (workflow expects array of videos)
            return $this->standardOutput([
                'videos' => [$video],
                'count' => 1,
            ], [
                'source' => 'manual_input',
                'original_input' => $videoInput,
                'video_id' => $videoId,
            ]);

        } catch (Exception $e) {
            Log::error('YouTubeManualInput: Execution failed', [
                'error' => $e->getMessage(),
                'input' => $input,
                'trace' => $e->getTraceAsString()
            ]);

            return $this->standardOutput([], [], $e->getMessage());
        }
    }
}
