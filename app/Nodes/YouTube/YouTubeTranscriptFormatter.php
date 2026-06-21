<?php

namespace App\Nodes\YouTube;

use App\Nodes\BaseNode;
use App\Services\AgentGuardrailService;
use App\Services\AIService;
use App\Services\SystemConfigService;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * YouTube Transcript Formatter Node
 *
 * Formats YouTube video transcripts using AI to improve readability:
 * - Adds logical paragraph breaks
 * - Fixes spelling and grammar
 * - Improves overall readability
 * - Maintains the original meaning and content
 *
 * Uses AIService for resilience (circuit breaker, retry, fallback).
 */
class YouTubeTranscriptFormatter extends BaseNode
{
    private const RUNTIME_POLICY_CONFIG_KEY = 'youtube.transcript_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'formatter_enabled_default' => true,
        'formatter_batch_size' => 5,
        'formatter_skip_if_no_transcript_default' => true,
        'formatter_missing_key_points_retries' => 1,
        'formatter_batch_delay_seconds' => 1,
        'formatter_min_formatted_chars' => 100,
        'formatter_max_seconds' => 240,
    ];

    private ?AIService $aiService = null;

    private ?AgentGuardrailService $guardrail = null;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function execute(array $input): array
    {
        try {
            // Get configuration
            $enabled = $this->configuredBool('enabled') ?? $this->runtimePolicyBool('formatter_enabled_default');
            $batchSize = $this->configuredPositiveInt('batch_size') ?? $this->runtimePolicyInt('formatter_batch_size');
            $skipIfNoTranscript = $this->configuredBool('skip_if_no_transcript')
                ?? $this->runtimePolicyBool('formatter_skip_if_no_transcript_default');
            $missingKeyPointRetries = $this->configuredNonNegativeInt('missing_key_points_retries')
                ?? $this->runtimePolicyNonNegativeInt('formatter_missing_key_points_retries');
            $batchDelaySeconds = $this->configuredNonNegativeInt('batch_delay_seconds')
                ?? $this->runtimePolicyNonNegativeInt('formatter_batch_delay_seconds');
            $maxSeconds = $this->configuredPositiveInt('max_seconds') ?? $this->runtimePolicyInt('formatter_max_seconds');

            if (! $enabled) {
                Log::info('YouTubeTranscriptFormatter: Formatting disabled, skipping');

                return $this->standardOutput($input, [
                    'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
                ]);
            }

            // Get videos from input (handles both wrapped and direct input)
            $videos = $input['data']['videos'] ?? $input['videos'] ?? [];

            if (empty($videos)) {
                Log::info('YouTubeTranscriptFormatter: No videos to process');

                return $this->standardOutput($input);
            }

            Log::info('YouTubeTranscriptFormatter: Starting transcript formatting', [
                'video_count' => count($videos),
                'batch_size' => $batchSize,
            ]);

            // Use AIService for resilience (circuit breaker, retry, fallback)
            $this->aiService = app(AIService::class);
            $formattedCount = 0;
            $skippedCount = 0;
            $failedCount = 0;
            $this->initTimeLimit($maxSeconds);

            foreach ($videos as $index => &$video) {
                if (! $this->hasTimeRemaining()) {
                    Log::warning('YouTubeTranscriptFormatter: Wall-clock limit reached', [
                        'elapsed_seconds' => round($this->elapsedSeconds()),
                        'processed' => $formattedCount,
                        'remaining' => count($videos) - $index,
                    ]);
                    break;
                }

                try {
                    // Skip if no transcript
                    if (empty($video['transcript_full_text'])) {
                        if ($skipIfNoTranscript) {
                            $skippedCount++;
                            Log::debug('YouTubeTranscriptFormatter: Skipping video without transcript', [
                                'video_id' => $video['video_id'],
                            ]);

                            continue;
                        }
                    }

                    // Format transcript with AI
                    $formattedTranscript = $this->formatTranscript(
                        $video['transcript_full_text'],
                        $video['title']
                    );

                    if ($formattedTranscript) {
                        // Store original transcript
                        $video['transcript_original'] = $video['transcript_full_text'];

                        // Extract key points from the formatted output
                        $extracted = $this->extractKeyPoints($formattedTranscript);

                        // INF-9: Retry if key points are empty (LLM didn't follow format)
                        for ($retry = 1; empty($extracted['key_points']) && $retry <= $missingKeyPointRetries; $retry++) {
                            Log::info('YouTubeTranscriptFormatter: Key points missing, retrying', [
                                'video_id' => $video['video_id'],
                                'retry' => $retry,
                                'max_retries' => $missingKeyPointRetries,
                            ]);
                            $retryResult = $this->formatTranscript(
                                $video['transcript_full_text'],
                                $video['title']
                            );
                            if (! $retryResult) {
                                continue;
                            }

                            $retryExtracted = $this->extractKeyPoints($retryResult);
                            if (! empty($retryExtracted['key_points'])) {
                                $extracted = $retryExtracted;
                            }
                        }

                        // Store key points as separate array
                        $video['key_points'] = $extracted['key_points'];

                        // Store formatted transcript WITHOUT key points section
                        $video['transcript_full_text'] = $extracted['transcript'];
                        $video['transcript_formatted'] = true;
                        $formattedCount++;

                        Log::info('YouTubeTranscriptFormatter: Transcript formatted', [
                            'video_id' => $video['video_id'],
                            'original_length' => strlen($video['transcript_original']),
                            'formatted_length' => strlen($extracted['transcript']),
                            'key_points_count' => count($extracted['key_points']),
                        ]);
                    } else {
                        $failedCount++;
                        $video['transcript_formatted'] = false;
                    }

                } catch (Exception $e) {
                    $failedCount++;
                    $video['transcript_formatted'] = false;
                    Log::error('YouTubeTranscriptFormatter: Exception formatting transcript', [
                        'video_id' => $video['video_id'],
                        'error' => $e->getMessage(),
                    ]);
                }

                // Batch processing delay to avoid rate limits
                if (($formattedCount % $batchSize) === 0 && $formattedCount > 0 && $batchDelaySeconds > 0) {
                    Log::debug('YouTubeTranscriptFormatter: Batch complete, brief pause', [
                        'seconds' => $batchDelaySeconds,
                    ]);
                    sleep($batchDelaySeconds);
                }
            }
            unset($video); // Break reference

            Log::info('YouTubeTranscriptFormatter: Batch formatting complete', [
                'total' => count($videos),
                'formatted' => $formattedCount,
                'skipped' => $skippedCount,
                'failed' => $failedCount,
            ]);

            // Return videos with formatted transcripts
            // Pass through ip_blocked status from previous node
            return $this->standardOutput([
                'videos' => $videos,
                'transcripts_fetched' => $input['data']['transcripts_fetched'] ?? $input['transcripts_fetched'] ?? 0,
                'transcripts_failed' => $input['data']['transcripts_failed'] ?? $input['transcripts_failed'] ?? 0,
                'ip_blocked' => $input['data']['ip_blocked'] ?? $input['ip_blocked'] ?? false,
                'ip_block_error' => $input['data']['ip_block_error'] ?? $input['ip_block_error'] ?? null,
            ], [
                'transcripts_formatted' => $formattedCount,
                'transcripts_skipped' => $skippedCount,
                'transcripts_failed' => $failedCount,
                'batch_size' => $batchSize,
                'batch_delay_seconds' => $batchDelaySeconds,
                'missing_key_points_retries' => $missingKeyPointRetries,
                'max_seconds' => $maxSeconds,
                'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
            ]);

        } catch (Exception $e) {
            Log::error('YouTubeTranscriptFormatter: Execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return original input on error
            return $this->standardOutput($input, [], $e->getMessage());
        }
    }

    /**
     * Format a transcript using AI
     *
     * @param  string  $transcript  Raw transcript text
     * @param  string  $videoTitle  Video title for context
     * @return string|null Formatted transcript with summary or null on failure
     */
    private function formatTranscript(string $transcript, string $videoTitle): ?string
    {
        try {
            // Build AI prompt for transcript formatting with summary
            $prompt = $this->buildFormattingPrompt($transcript, $videoTitle);

            // Use AIService for resilience (circuit breaker, retry, fallback)
            $result = $this->aiService->process($prompt, $this->config);

            if (! $result['success']) {
                Log::warning('YouTubeTranscriptFormatter: AI processing failed', [
                    'error' => $result['error'],
                ]);

                return null;
            }

            $formatted = $result['response'];

            // Validate result
            $minFormattedChars = $this->configuredPositiveInt('min_formatted_chars')
                ?? $this->runtimePolicyInt('formatter_min_formatted_chars');
            if (empty($formatted) || strlen($formatted) < $minFormattedChars) {
                Log::warning('YouTubeTranscriptFormatter: AI returned suspiciously short result', [
                    'original_length' => strlen($transcript),
                    'formatted_length' => strlen($formatted),
                    'min_formatted_chars' => $minFormattedChars,
                ]);

                return null;
            }

            return $formatted;

        } catch (Exception $e) {
            Log::error('YouTubeTranscriptFormatter: AI formatting failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build AI prompt for transcript formatting with CONCISE summary
     *
     * @param  string  $transcript  Raw transcript
     * @param  string  $videoTitle  Video title
     * @return string Prompt for AI
     */
    private function buildFormattingPrompt(string $transcript, string $videoTitle): string
    {
        $safeTranscript = $this->getGuardrail()->sanitizeUntrustedText($transcript);

        return <<<PROMPT
Format this YouTube video transcript. Your output MUST have exactly this structure:

## Key Points

- [first key point]

- [second key point]

- [third key point]

[3 to 8 total bullet points]

---

[Full formatted transcript here]

Video Title: {$videoTitle}

RULES FOR KEY POINTS (MANDATORY — do not skip this section):
- Write "## Key Points" as the FIRST line
- Write 3-8 bullet points summarizing the main facts, arguments, and technical details
- Each bullet starts with "- " and is 1-2 sentences of SPECIFIC content (names, numbers, claims)
- Put a blank line between each bullet point
- Do NOT write generic points like "The video discusses..." — be specific

RULES FOR TRANSCRIPT (after the "---" separator):
- Write "---" on its own line after the key points
- Then write the full formatted transcript with logical paragraph breaks
- Fix spelling and grammar, proper punctuation and capitalization
- Remove excessive filler words only
- Keep ALL original content — do NOT summarize or omit
- Maintain chronological order

Raw Transcript:
{$safeTranscript}

CRITICAL: Your output MUST start with "## Key Points" and MUST contain "---" separator. Do NOT use placeholder text.

PROMPT;
    }

    private function getGuardrail(): AgentGuardrailService
    {
        if (! $this->guardrail) {
            $this->guardrail = app(AgentGuardrailService::class);
        }

        return $this->guardrail;
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
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function configuredNonNegativeInt(string $key): ?int
    {
        $value = $this->getConfigValue($key);
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue >= 0 ? $intValue : null;
    }

    private function runtimePolicyBool(string $key): bool
    {
        $fallback = (bool) self::DEFAULT_RUNTIME_POLICY[$key];
        $value = $this->runtimePolicy()[$key] ?? $fallback;
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $fallback;
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    private function runtimePolicyNonNegativeInt(string $key): int
    {
        return $this->nonNegativeInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        if ($this->runtimePolicy !== null) {
            return $this->runtimePolicy;
        }

        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return $this->runtimePolicy = array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }

    private function nonNegativeInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue >= 0 ? $intValue : $fallback;
    }

    /**
     * Extract key points from AI-formatted transcript
     *
     * Parses the AI output to separate key points from the formatted transcript
     *
     * @param  string  $formattedText  AI-generated text with key points and transcript
     * @return array ['key_points' => array, 'transcript' => string]
     */
    private function extractKeyPoints(string $formattedText): array
    {
        // Default result
        $result = [
            'key_points' => [],
            'transcript' => $formattedText, // Default to full text if parsing fails
        ];

        // Look for "## Key Points" section
        if (! preg_match('/##\s*Key Points/i', $formattedText)) {
            Log::warning('YouTubeTranscriptFormatter: No "## Key Points" section found in AI output');

            return $result;
        }

        // Split on "---" separator between key points and transcript
        $parts = preg_split('/^---+\s*$/m', $formattedText, 2);

        if (count($parts) < 2) {
            Log::warning('YouTubeTranscriptFormatter: No "---" separator found between key points and transcript');

            return $result;
        }

        $keyPointsSection = $parts[0];
        $transcriptSection = trim($parts[1]);

        // Extract bullet points from key points section
        // Match lines starting with "- " after the "## Key Points" heading
        if (preg_match_all('/^-\s+(.+)$/m', $keyPointsSection, $matches)) {
            $result['key_points'] = array_map('trim', $matches[1]);
            $result['transcript'] = $transcriptSection;

            Log::debug('YouTubeTranscriptFormatter: Extracted key points', [
                'count' => count($result['key_points']),
                'transcript_length' => strlen($transcriptSection),
            ]);
        } else {
            Log::warning('YouTubeTranscriptFormatter: No bullet points found in key points section');
        }

        return $result;
    }
}
