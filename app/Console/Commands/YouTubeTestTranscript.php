<?php

namespace App\Console\Commands;

use App\Services\YouTubeTranscriptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Test YouTube Transcript - Phase 2 Testing
 *
 * Test YouTube transcript fetching with single videos.
 * Part of YouTube Integration Phase 2: Python Bridge & Transcript Testing
 */
class YouTubeTestTranscript extends Command
{
    protected $signature = 'youtube:test-transcript
                            {video : YouTube video URL or ID}
                            {--language=en : Language code for transcript}
                            {--no-cache : Bypass cache and fetch fresh}
                            {--detailed : Show detailed transcript output}
                            {--method=auto : Method to use: auto, direct, invidious, piped, phplib, ytdlp, whisper}
                            {--backoff : Use enhanced method with exponential backoff}';

    protected $description = 'Test YouTube transcript fetching (Phase 2 testing)';

    public function handle(YouTubeTranscriptService $transcriptService): int
    {
        $videoInput = $this->argument('video');
        $language = $this->option('language');
        $useCache = !$this->option('no-cache');
        $verbose = $this->option('detailed');
        $method = $this->option('method');
        $useBackoff = $this->option('backoff');

        $this->info("🎥 YouTube Transcript Test - Phase 2");
        $this->info("═══════════════════════════════════");
        $this->newLine();

        // Extract video ID
        $videoId = YouTubeTranscriptService::extractVideoId($videoInput);

        if (!$videoId) {
            $this->error("❌ Invalid YouTube URL or video ID");
            $this->line("Please provide a valid YouTube URL or 11-character video ID");
            return self::FAILURE;
        }

        $this->info("Video ID: {$videoId}");
        $this->info("Language: {$language}");
        $this->info("Use Cache: " . ($useCache ? 'Yes' : 'No'));
        $this->info("Method: {$method}");
        $this->info("Backoff: " . ($useBackoff ? 'Yes' : 'No'));
        $this->newLine();

        // Show video URL
        $videoUrl = "https://youtube.com/watch?v={$videoId}";
        $this->line("🔗 Video URL: {$videoUrl}");
        $this->newLine();

        $this->info("Fetching transcript...");
        $startTime = microtime(true);

        try {
            // Use appropriate method based on options
            if ($useBackoff) {
                $result = $transcriptService->getTranscriptWithBackoff($videoId, $language, $useCache, 0);
            } elseif ($method !== 'auto') {
                // Use specific method
                $youtubeApi = new \App\Services\YouTubeApiService();
                $result = match($method) {
                    'direct' => $youtubeApi->getTranscriptViaDirect($videoId, $language),
                    'invidious' => $youtubeApi->getTranscriptViaInvidious($videoId, $language, $useCache),
                    'piped' => $youtubeApi->getTranscriptViaPiped($videoId, $language, $useCache),
                    'phplib' => $youtubeApi->getTranscriptViaPhpLib($videoId, $language),
                    'ytdlp' => $youtubeApi->getTranscriptViaYtDlp($videoId, $language),
                    'whisper' => $youtubeApi->getTranscriptViaWhisper($videoId, $language),
                    default => $transcriptService->getTranscript($videoId, $language, $useCache),
                };
            } else {
                $result = $transcriptService->getTranscript($videoId, $language, $useCache);
            }
            $duration = round(microtime(true) - $startTime, 2);

            $this->newLine();
            $this->info("═══════════════════════════════════");

            if ($result['success']) {
                $this->info("✅ Transcript Fetched Successfully");
                $this->info("═══════════════════════════════════");
                $this->newLine();

                // Display metadata
                $this->table(
                    ['Property', 'Value'],
                    [
                        ['Video ID', $result['video_id']],
                        ['Language', $result['language'] ?? 'en'],
                        ['Caption Type', $this->formatCaptionType($result['caption_type'] ?? 'unknown')],
                        ['Word Count', number_format($result['word_count'] ?? 0)],
                        ['Transcript Entries', number_format($result['duration_estimate'] ?? count($result['transcript'] ?? []))],
                        ['Method', $result['method'] ?? 'unknown'],
                        ['Fetch Time', "{$duration}s"],
                    ]
                );

                $this->newLine();

                // Show transcript preview
                $this->info("📝 Transcript Preview (first 500 characters):");
                $this->line(str_repeat('─', 60));
                $preview = substr($result['full_text'], 0, 500);
                $this->line($preview . (strlen($result['full_text']) > 500 ? '...' : ''));
                $this->line(str_repeat('─', 60));

                // Verbose output
                if ($verbose) {
                    $this->newLine();
                    $this->info("📊 Detailed Transcript Data:");
                    $this->newLine();

                    // Show first 5 entries with timestamps
                    $entries = array_slice($result['transcript'], 0, 5);
                    foreach ($entries as $index => $entry) {
                        $timestamp = $this->formatTimestamp($entry['start']);
                        $this->line("[{$timestamp}] {$entry['text']}");
                    }

                    if (count($result['transcript']) > 5) {
                        $this->line("... (" . (count($result['transcript']) - 5) . " more entries)");
                    }
                }

                $this->newLine();
                $this->info("✅ Quality Check:");
                $this->line("- Transcript accuracy: " . $this->estimateAccuracy($result['caption_type']));
                $this->line("- Word count: " . $this->assessWordCount($result['word_count']));
                $this->line("- Formatting: " . $this->assessFormatting($result['full_text']));

                $this->newLine();
                $this->info("✅ Next Steps:");
                $this->line("1. Review transcript quality - is it accurate?");
                $this->line("2. Check for formatting issues");
                $this->line("3. Verify word count is reasonable");
                $this->line("4. Test with different video types");
                $this->line("5. Proceed to Phase 2, Step 2.5 (Edge Cases)");

                return self::SUCCESS;

            } else {
                $this->error("❌ Transcript Fetch Failed");
                $this->info("═══════════════════════════════════");
                $this->newLine();

                $this->table(
                    ['Property', 'Value'],
                    [
                        ['Video ID', $result['video_id']],
                        ['Error Type', $result['error_type'] ?? 'Unknown'],
                        ['Error Message', $result['error']],
                        ['Fetch Time', "{$duration}s"],
                    ]
                );

                $this->newLine();
                $this->warn("💡 Troubleshooting:");
                $this->line($this->getTroubleshootingTip($result['error_type'] ?? 'Unknown'));

                return self::FAILURE;
            }

        } catch (\Exception $e) {
            $this->newLine();
            $this->error("❌ Exception occurred:");
            $this->error($e->getMessage());
            $this->newLine();
            $this->line("Check logs: tail -50 storage/logs/laravel.log | grep -i youtube");
            return self::FAILURE;
        }
    }

    /**
     * Format caption type with emoji
     */
    private function formatCaptionType(string $type): string
    {
        return match($type) {
            'manual' => '✍️ Manual (High Quality)',
            'auto-generated' => '🤖 Auto-Generated',
            default => $type
        };
    }

    /**
     * Format timestamp in MM:SS format
     */
    private function formatTimestamp(float $seconds): string
    {
        $minutes = floor($seconds / 60);
        $secs = floor($seconds % 60);
        return sprintf("%02d:%02d", $minutes, $secs);
    }

    /**
     * Estimate transcript accuracy based on caption type
     */
    private function estimateAccuracy(string $captionType): string
    {
        return match($captionType) {
            'manual' => '✅ High (>95%) - Manual captions',
            'auto-generated' => '⚠️ Medium (80-90%) - Auto-generated',
            default => '❓ Unknown'
        };
    }

    /**
     * Assess word count
     */
    private function assessWordCount(int $wordCount): string
    {
        if ($wordCount === 0) {
            return '❌ Empty transcript';
        } elseif ($wordCount < 100) {
            return '⚠️ Very short (' . number_format($wordCount) . ' words)';
        } elseif ($wordCount < 1000) {
            return '✅ Short video (' . number_format($wordCount) . ' words)';
        } elseif ($wordCount < 5000) {
            return '✅ Medium video (' . number_format($wordCount) . ' words)';
        } else {
            return '✅ Long video (' . number_format($wordCount) . ' words)';
        }
    }

    /**
     * Assess formatting quality
     */
    private function assessFormatting(string $text): string
    {
        $hasMultipleSpaces = preg_match('/\s{3,}/', $text);
        $hasExcessiveNewlines = preg_match('/\n{3,}/', $text);

        if ($hasMultipleSpaces || $hasExcessiveNewlines) {
            return '⚠️ Some formatting issues detected';
        }

        return '✅ Clean formatting';
    }

    /**
     * Get troubleshooting tip based on error type
     */
    private function getTroubleshootingTip(string $errorType): string
    {
        return match($errorType) {
            'TranscriptsDisabled' =>
                "- This video has transcripts disabled by the creator\n" .
                "- Try a different video with captions enabled",

            'NoTranscriptFound' =>
                "- No transcript available for the requested language\n" .
                "- Try a different language or check if captions exist",

            'VideoUnavailable' =>
                "- Video may be private, deleted, or age-restricted\n" .
                "- Verify the video ID is correct",

            'TooManyRequests' =>
                "- YouTube API rate limit reached\n" .
                "- Wait a few minutes and try again",

            'ScriptNotFound' =>
                "- Python transcript script not found\n" .
                "- Ensure scripts/youtube_transcript.py exists",

            'JsonParseError' =>
                "- Failed to parse Python script output\n" .
                "- Check Python script execution manually",

            default =>
                "- Check the error message above\n" .
                "- Verify video has captions enabled\n" .
                "- Check logs for more details"
        };
    }
}
