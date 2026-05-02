<?php

namespace App\Nodes\YouTube;

use App\Nodes\BaseNode;
use App\Services\AgentGuardrailService;
use App\Services\AIService;
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
    private ?AIService $aiService = null;

    private ?AgentGuardrailService $guardrail = null;

    public function execute(array $input): array
    {
        try {
            // Get configuration
            $enabled = $this->getConfigValue('enabled', true);
            $batchSize = $this->getConfigValue('batch_size', 5);
            $skipIfNoTranscript = $this->getConfigValue('skip_if_no_transcript', true);

            if (! $enabled) {
                Log::info('YouTubeTranscriptFormatter: Formatting disabled, skipping');

                return $this->standardOutput($input);
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
            $this->initTimeLimit();

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

                        // INF-9: Retry once if key points are empty (LLM didn't follow format)
                        if (empty($extracted['key_points'])) {
                            Log::info('YouTubeTranscriptFormatter: Key points missing, retrying', [
                                'video_id' => $video['video_id'],
                            ]);
                            $retryResult = $this->formatTranscript(
                                $video['transcript_full_text'],
                                $video['title']
                            );
                            if ($retryResult) {
                                $retryExtracted = $this->extractKeyPoints($retryResult);
                                if (! empty($retryExtracted['key_points'])) {
                                    $extracted = $retryExtracted;
                                }
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
                if (($formattedCount % $batchSize) === 0 && $formattedCount > 0) {
                    Log::debug('YouTubeTranscriptFormatter: Batch complete, brief pause');
                    sleep(1); // 1 second pause between batches
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
            if (empty($formatted) || strlen($formatted) < 100) {
                Log::warning('YouTubeTranscriptFormatter: AI returned suspiciously short result', [
                    'original_length' => strlen($transcript),
                    'formatted_length' => strlen($formatted),
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
