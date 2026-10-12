<?php

namespace App\Nodes\YouTube;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Nodes\BaseNode;
use App\Services\SystemConfigService;
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
    private const RUNTIME_POLICY_CONFIG_KEY = 'youtube.transcript_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'default_language' => 'en',
        'include_timestamps_default' => true,
        'default_batch_size' => 5,
        'max_retries' => 3,
        'base_delay_seconds' => 10,
        'max_delay_seconds' => 300,
        'min_request_gap_seconds' => 15,
        'batch_delay_seconds' => 60,
    ];

    public function execute(array $input): array
    {
        try {
            $enabled = config('youtube.enabled', false);
            if (! $enabled) {
                throw new Exception('YouTube integration is disabled. Set YOUTUBE_ENABLED=true in .env');
            }

            // Get configuration
            $language = $this->configuredString('language') ?? $this->runtimePolicyString('default_language');
            $languageValidation = app(YouTubeTranscriptLanguagePolicy::class)->validateRequestedLanguage((string) $language);
            if (! ($languageValidation['success'] ?? false)) {
                return $this->standardOutput([], [], $languageValidation['error']);
            }
            $language = $languageValidation['language'];
            $includeTimestamps = $this->configuredBool('include_timestamps') ?? $this->runtimePolicyBool('include_timestamps_default');
            $batchSize = $this->configuredPositiveInt('batch_size') ?? $this->runtimePolicyInt('default_batch_size');

            // Extract videos from input
            $videos = $input['data']['videos'] ?? $input['videos'] ?? [];
            if (empty($videos)) {
                Log::warning('YouTubeTranscript: No videos in input');

                return $this->standardOutput(array_merge($input['data'] ?? $input, [
                    'videos' => [],
                    'count' => 0,
                    'transcripts_fetched' => 0,
                    'transcripts_failed' => 0,
                ]), [
                    'language' => $language,
                    'include_timestamps' => $includeTimestamps,
                    'batch_size' => $batchSize,
                    'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
                ]);
            }

            Log::info('YouTubeTranscript: Starting execution', [
                'video_count' => count($videos),
                'language' => $language,
                'batch_size' => $batchSize,
            ]);

            $transcriptService = app(YouTubeTranscriptService::class);
            $enrichedVideos = [];
            $successCount = 0;
            $failureCount = 0;
            $ipBlocked = false;
            $ipBlockedError = null;
            $startedAt = microtime(true);
            $maxSeconds = $this->configuredPositiveInt('max_seconds');

            // Process videos in batches
            $batches = array_chunk($videos, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                Log::debug('Processing batch', [
                    'batch' => $batchIndex + 1,
                    'total_batches' => count($batches),
                    'batch_size' => count($batch),
                ]);

                foreach ($batch as $videoIndex => $video) {
                    if ($maxSeconds !== null && microtime(true) - $startedAt >= $maxSeconds) {
                        break 2;
                    }

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
                    $configuredRetries = filter_var($this->getConfigValue('max_retries'), FILTER_VALIDATE_INT);
                    $maxRetries = $configuredRetries !== false && $configuredRetries >= 0
                        ? $configuredRetries : $this->runtimePolicyInt('max_retries');
                    $baseDelay = $this->runtimePolicyInt('base_delay_seconds');
                    $maxDelay = $this->runtimePolicyInt('max_delay_seconds');
                    $minRequestGap = $this->runtimePolicyInt('min_request_gap_seconds');
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
                        if ($maxSeconds !== null && microtime(true) - $startedAt >= $maxSeconds) {
                            break 3;
                        }

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
                    $batchDelay = $this->runtimePolicyInt('batch_delay_seconds');
                    Log::debug('Batch delay', ['seconds' => $batchDelay]);
                    sleep($batchDelay);
                }
            }

            Log::info('YouTubeTranscript: Execution completed', [
                'total_videos' => count($videos),
                'transcripts_fetched' => $successCount,
                'transcripts_failed' => $failureCount,
                'transcripts_deferred' => count($videos) - count($enrichedVideos),
                'ip_blocked' => $ipBlocked,
            ]);

            return $this->standardOutput(array_merge($input['data'] ?? $input, [
                'videos' => $enrichedVideos,
                'count' => count($enrichedVideos),
                'transcripts_fetched' => $successCount,
                'transcripts_failed' => $failureCount,
                'transcripts_deferred' => count($videos) - count($enrichedVideos),
                'ip_blocked' => $ipBlocked,
                'ip_block_error' => $ipBlockedError,
            ]), [
                'language' => $language,
                'include_timestamps' => $includeTimestamps,
                'batch_size' => $batchSize,
                'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
            ]);

        } catch (Exception $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }
            Log::error('YouTubeTranscript: Execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->standardOutput([], [], $e->getMessage());
        }
    }

    private function configuredString(string $key): ?string
    {
        $value = $this->getConfigValue($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function configuredBool(string $key): ?bool
    {
        $value = $this->getConfigValue($key);
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private function configuredPositiveInt(string $key): ?int
    {
        $value = $this->getConfigValue($key);
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function runtimePolicyString(string $key): string
    {
        $value = $this->runtimePolicy()[$key] ?? self::DEFAULT_RUNTIME_POLICY[$key];

        return is_string($value) && trim($value) !== '' ? trim($value) : (string) self::DEFAULT_RUNTIME_POLICY[$key];
    }

    private function runtimePolicyBool(string $key): bool
    {
        $value = $this->runtimePolicy()[$key] ?? self::DEFAULT_RUNTIME_POLICY[$key];
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? (bool) self::DEFAULT_RUNTIME_POLICY[$key];
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }
}
