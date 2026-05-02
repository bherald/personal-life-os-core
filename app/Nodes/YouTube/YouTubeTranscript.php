<?php

namespace App\Nodes\YouTube;

use App\Nodes\BaseNode;
use App\Services\YouTubeTranscriptLanguagePolicy;
use App\Services\YouTubeTranscriptService;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * YouTube Transcript Node
 *
 * Fetches transcripts for YouTube videos using the Python bridge.
 * Supports batch processing with configurable batch size.
 */
class YouTubeTranscript extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $enabled = config('youtube.enabled', false);
            if (! $enabled) {
                throw new Exception('YouTube integration is disabled. Set YOUTUBE_ENABLED=true in .env');
            }

            // Get configuration
            $language = $this->getConfigValue('language', 'en');
            $languageValidation = app(YouTubeTranscriptLanguagePolicy::class)->validateRequestedLanguage((string) $language);
            if (! ($languageValidation['success'] ?? false)) {
                return $this->standardOutput([], [], $languageValidation['error']);
            }
            $language = $languageValidation['language'];
            $includeTimestamps = $this->getConfigValue('include_timestamps', true);
            $batchSize = $this->getConfigValue('batch_size', 5);

            // Extract videos from input
            $videos = $input['data']['videos'] ?? $input['videos'] ?? [];
            if (empty($videos)) {
                Log::warning('YouTubeTranscript: No videos in input');

                return $this->standardOutput([
                    'videos' => [],
                    'count' => 0,
                    'transcripts_fetched' => 0,
                    'transcripts_failed' => 0,
                ]);
            }

            Log::info('YouTubeTranscript: Starting execution', [
                'video_count' => count($videos),
                'language' => $language,
                'batch_size' => $batchSize,
            ]);

            $transcriptService = new YouTubeTranscriptService;
            $enrichedVideos = [];
            $successCount = 0;
            $failureCount = 0;
            $ipBlocked = false;
            $ipBlockedError = null;

            // Process videos in batches
            $batches = array_chunk($videos, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                Log::debug('Processing batch', [
                    'batch' => $batchIndex + 1,
                    'total_batches' => count($batches),
                    'batch_size' => count($batch),
                ]);

                foreach ($batch as $videoIndex => $video) {
                    $videoId = $video['video_id'] ?? null;
                    if (! $videoId) {
                        Log::warning('Video missing video_id', ['video' => $video]);
                        $enrichedVideos[] = array_merge($video, [
                            'transcript_available' => false,
                            'transcript_error' => 'Missing video ID',
                        ]);
                        $failureCount++;

                        continue;
                    }

                    Log::debug('Fetching transcript', [
                        'video_id' => $videoId,
                        'title' => $video['title'] ?? 'Unknown',
                    ]);

                    // Fetch transcript with enhanced retry logic and multiple methods
                    // Uses exponential backoff to avoid rate limiting
                    $maxRetries = config('youtube.transcript.max_retries', 3);
                    $baseDelay = config('youtube.transcript.base_delay', 10);
                    $maxDelay = config('youtube.transcript.max_delay', 300);
                    $minRequestGap = config('youtube.transcript.min_request_gap', 15);
                    $transcriptResult = null;
                    $attempt = 0;

                    // Ensure minimum gap between requests
                    static $lastRequestTime = 0;
                    $timeSinceLastRequest = time() - $lastRequestTime;
                    if ($lastRequestTime > 0 && $timeSinceLastRequest < $minRequestGap) {
                        $waitTime = $minRequestGap - $timeSinceLastRequest;
                        Log::debug('Enforcing minimum request gap', [
                            'wait_seconds' => $waitTime,
                        ]);
                        sleep($waitTime);
                    }

                    while ($attempt <= $maxRetries) {
                        // Use the enhanced method with multiple fallbacks
                        $transcriptResult = $transcriptService->getTranscriptWithBackoff(
                            $videoId,
                            $language,
                            true,
                            $attempt
                        );

                        $lastRequestTime = time();

                        if ($transcriptResult['success']) {
                            break;
                        }

                        // Stop retrying if rate limited
                        if (($transcriptResult['error_type'] ?? '') === 'TooManyRequests') {
                            Log::warning('Rate limited, stopping retries for this video', [
                                'video_id' => $videoId,
                                'attempt' => $attempt,
                            ]);
                            break;
                        }

                        $attempt++;
                        if ($attempt <= $maxRetries) {
                            // Exponential backoff
                            $retryDelay = min($baseDelay * pow(2, $attempt), $maxDelay);
                            Log::info('Transcript fetch failed, retrying with exponential backoff', [
                                'video_id' => $videoId,
                                'attempt' => $attempt,
                                'max_retries' => $maxRetries,
                                'retry_delay' => $retryDelay,
                            ]);
                            sleep($retryDelay);
                        }
                    }

                    if ($transcriptResult['success']) {
                        $enrichedVideo = array_merge($video, [
                            'transcript_available' => true,
                            'transcript_language' => $transcriptResult['language'],
                            'transcript_caption_type' => $transcriptResult['caption_type'] ?? 'unknown',
                            'transcript_word_count' => $transcriptResult['word_count'],
                            'transcript_full_text' => $transcriptResult['full_text'],
                        ]);

                        // Optionally include timestamped transcript
                        if ($includeTimestamps) {
                            $enrichedVideo['transcript_data'] = $transcriptResult['transcript'];
                        }

                        $enrichedVideos[] = $enrichedVideo;
                        $successCount++;

                        Log::info('Transcript fetched successfully', [
                            'video_id' => $videoId,
                            'word_count' => $transcriptResult['word_count'],
                            'caption_type' => $transcriptResult['caption_type'] ?? 'unknown',
                        ]);

                    } else {
                        // Check if this is a genuine IP block/rate limit error
                        // Note: Be specific to avoid false positives (e.g., "transcript" contains "ip")
                        $errorType = $transcriptResult['error_type'] ?? '';
                        $errorMsg = $transcriptResult['error'] ?? '';

                        // Only treat as IP block for specific rate-limiting error types
                        // AllMethodsFailed just means all fallbacks failed - not necessarily IP block
                        $isRateLimitError = in_array($errorType, [
                            'TooManyRequests',
                            'IPBlocked',
                            'RateLimited',
                            'Forbidden',
                        ], true);

                        // Check message with word boundary to avoid "transcript" matching "ip"
                        $msgIndicatesBlock = preg_match('/\b(IP|IPs)\s*(blocked|banned|block)/i', $errorMsg) ||
                            stripos($errorMsg, 'rate limit') !== false ||
                            stripos($errorMsg, 'too many requests') !== false ||
                            stripos($errorMsg, 'access denied') !== false;

                        if ($isRateLimitError || $msgIndicatesBlock) {
                            $ipBlocked = true;
                            $ipBlockedError = $transcriptResult['error'];

                            Log::error('YouTube rate limit/IP block detected', [
                                'video_id' => $videoId,
                                'error' => $errorMsg,
                                'error_type' => $errorType,
                                'detection_reason' => $isRateLimitError ? 'error_type' : 'message_pattern',
                            ]);
                        }

                        $enrichedVideos[] = array_merge($video, [
                            'transcript_available' => false,
                            'transcript_error' => $transcriptResult['error'],
                            'transcript_error_type' => $transcriptResult['error_type'],
                        ]);
                        $failureCount++;

                        Log::warning('Transcript fetch failed', [
                            'video_id' => $videoId,
                            'error' => $transcriptResult['error'],
                            'error_type' => $transcriptResult['error_type'],
                        ]);

                        // Stop processing if IP is blocked
                        if ($ipBlocked) {
                            Log::error('Stopping workflow due to IP block');
                            break 2; // Break out of both foreach loops
                        }
                    }
                }

                // Brief pause between batches to avoid rate limiting
                if ($batchIndex < count($batches) - 1) {
                    $batchDelay = config('youtube.transcript.batch_delay', 1);
                    Log::debug('Batch delay', ['seconds' => $batchDelay]);
                    sleep($batchDelay);
                }
            }

            Log::info('YouTubeTranscript: Execution completed', [
                'total_videos' => count($videos),
                'transcripts_fetched' => $successCount,
                'transcripts_failed' => $failureCount,
                'ip_blocked' => $ipBlocked,
            ]);

            return $this->standardOutput([
                'videos' => $enrichedVideos,
                'count' => count($enrichedVideos),
                'transcripts_fetched' => $successCount,
                'transcripts_failed' => $failureCount,
                'ip_blocked' => $ipBlocked,
                'ip_block_error' => $ipBlockedError,
            ], [
                'language' => $language,
                'include_timestamps' => $includeTimestamps,
                'batch_size' => $batchSize,
            ]);

        } catch (Exception $e) {
            Log::error('YouTubeTranscript: Execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->standardOutput([], [], $e->getMessage());
        }
    }
}
