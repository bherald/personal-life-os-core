<?php

namespace App\Nodes\YouTube;

use App\DTOs\TrustEnvelope;
use App\Nodes\BaseNode;
use App\Services\AIService;
use App\Services\JoplinYouTubeOrganizer;
use App\Services\TrustBoundaryFormatterService;
use Illuminate\Support\Facades\Log;
use Exception;

class YouTubeKeyPointsPostProcessor extends BaseNode
{
    private const PLACEHOLDER = '*Key points will be extracted*';

    private ?TrustBoundaryFormatterService $trustBoundaryFormatter = null;

    private function trustBoundaryFormatter(): TrustBoundaryFormatterService
    {
        return $this->trustBoundaryFormatter ??= app(TrustBoundaryFormatterService::class);
    }

    public function execute(array $input): array
    {
        try {
            $limit = (int) $this->getConfigValue('limit', 50);
            $dryRun = $this->getConfigValue('dry_run', false);

            Log::info('YouTubeKeyPointsPostProcessor: Starting', [
                'limit' => $limit,
                'dry_run' => $dryRun,
            ]);

            $organizer = app(JoplinYouTubeOrganizer::class);
            $aiService = app(AIService::class);

            $result = $this->processPlaceholderNotes($organizer, $aiService, $limit, $dryRun);

            Log::info('YouTubeKeyPointsPostProcessor: Complete', $result);

            return $this->standardOutput(
                array_merge($input['data'] ?? $input, ['key_points_stats' => $result]),
                [
                    'notes_scanned' => $result['scanned'],
                    'placeholders_found' => $result['found'],
                    'notes_updated' => $result['updated'],
                    'notes_failed' => $result['failed'],
                ]
            );

        } catch (Exception $e) {
            Log::error('YouTubeKeyPointsPostProcessor: Failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->standardOutput($input['data'] ?? $input, [], $e->getMessage());
        }
    }

    /**
     * Scan notes for placeholder key points and backfill with AI
     */
    public function processPlaceholderNotes(
        JoplinYouTubeOrganizer $organizer,
        AIService $aiService,
        int $limit = 20,
        bool $dryRun = false,
        ?object $output = null
    ): array {
        $stats = ['scanned' => 0, 'found' => 0, 'updated' => 0, 'failed' => 0, 'notes' => []];

        $joplinPath = $organizer->getJoplinPath();
        $watchLaterFolderId = $organizer->getWatchLaterFolderId();

        // Fetch all Joplin files
        $files = $organizer->listDirectory($joplinPath);
        $allContents = $organizer->getFileContentsBatch($files, 50);

        // Find the Watch Later folder and all its subfolders
        $watchLaterFolderIds = [$watchLaterFolderId];
        foreach ($allContents as $content) {
            $note = $organizer->parseNote($content);
            if ($note['type'] == 2 && $note['parent_id'] === $watchLaterFolderId) {
                $watchLaterFolderIds[] = $note['id'];
            }
        }

        // Scan notes in Watch Later tree for placeholder
        $placeholderNotes = [];
        foreach ($allContents as $filename => $content) {
            $note = $organizer->parseNote($content);
            $note['filename'] = $filename;
            $stats['scanned']++;

            if ($note['type'] != 1 || !in_array($note['parent_id'], $watchLaterFolderIds)) {
                continue;
            }

            if (strpos($content, self::PLACEHOLDER) !== false) {
                $placeholderNotes[] = $note;
                $stats['found']++;

                if (count($placeholderNotes) >= $limit) {
                    break;
                }
            }
        }

        $this->logMessage($output, "Found {$stats['found']} notes with placeholder key points (scanned {$stats['scanned']})");

        // Process each placeholder note with wall-clock limit
        $maxSeconds = 900; // 15 minutes — 468 note backlog needs larger window
        $startTime = microtime(true);
        foreach ($placeholderNotes as $note) {
            if ((microtime(true) - $startTime) >= $maxSeconds) {
                $this->logMessage($output, "Wall-clock limit reached ({$maxSeconds}s), stopping");
                break;
            }
            $title = substr($note['title'], 0, 50);

            // Extract transcript from note body
            $transcript = $this->extractTranscript($note['raw']);
            if (empty($transcript)) {
                $this->logMessage($output, "  Skip (no transcript): {$title}");
                $stats['failed']++;
                continue;
            }

            // Generate key points via AI
            try {
                $keyPoints = $this->generateKeyPoints($aiService, $transcript, $note['title']);
            } catch (Exception $e) {
                $this->logMessage($output, "  <error>AI failed:</error> {$title} - {$e->getMessage()}");
                $stats['failed']++;
                continue;
            }

            if (empty($keyPoints)) {
                $this->logMessage($output, "  Skip (AI returned empty): {$title}");
                $stats['failed']++;
                continue;
            }

            // Format key points as markdown bullets
            $bullets = [];
            foreach ($keyPoints as $point) {
                $bullets[] = "- {$point}";
            }
            $formattedKeyPoints = implode("\n\n", $bullets);

            $this->logMessage($output, "  OK (" . count($keyPoints) . " points): {$title}");
            $stats['notes'][] = ['title' => $note['title'], 'key_points_count' => count($keyPoints)];

            if ($dryRun) {
                $stats['updated']++;
                continue;
            }

            // Replace placeholder in note content and write back
            $updatedContent = str_replace(self::PLACEHOLDER, $formattedKeyPoints, $note['raw']);

            // Update timestamp
            $now = date('Y-m-d\TH:i:s.v\Z');
            $updatedContent = preg_replace('/^updated_time:\s*.*$/m', "updated_time: {$now}", $updatedContent);

            $success = $organizer->putFileContent($joplinPath . $note['filename'], $updatedContent);
            if ($success) {
                $stats['updated']++;
            } else {
                $this->logMessage($output, "  <error>Write failed:</error> {$title}");
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Extract transcript text from note body (between ## Full Transcript and ---)
     */
    private function extractTranscript(string $noteContent): ?string
    {
        // Match content between "## Full Transcript" section and the next "---" separator
        if (preg_match('/## Full Transcript.*?\n\n(.*?)(?=\n---)/s', $noteContent, $matches)) {
            $transcript = trim($matches[1]);

            // Strip the stats block (Word Count, Caption Type, Language lines)
            $transcript = preg_replace('/^\*\*Transcript Stats:\*\*\n(?:- .*\n)*/m', '', $transcript);

            $transcript = trim($transcript);
            return strlen($transcript) > 50 ? $transcript : null;
        }

        return null;
    }

    /**
     * Generate key points from transcript using AI
     */
    private function generateKeyPoints(AIService $aiService, string $transcript, string $title): array
    {
        // Truncate very long transcripts to fit context window
        $wrappedTranscript = $this->trustBoundaryFormatter()->format(new TrustEnvelope(
            sourceType: 'transcript',
            contentType: 'text/plain',
            origin: (string) $title,
            payload: $transcript,
            maxChars: 12000,
        ));

        $prompt = <<<PROMPT
Generate 3-8 bullet point key points from this YouTube video transcript.

Video Title: {$title}

RULES:
- Each point: 1-2 sentences of SPECIFIC factual content (names, numbers, dates, technical claims)
- Do NOT write generic summaries like "The video discusses various topics"
- Do NOT write "The speaker talks about..." — state the actual facts
- Chronological order
- Return ONLY bullet points, one per line, each starting with "- "

GOOD example:
- SpaceX launched Starship SN20 from Boca Chica, achieving a 6-minute powered flight before a controlled ocean landing
- The Raptor 2 engines produced 230 tons of thrust each, a 15% improvement over the previous version

BAD example:
- The video covers recent space launches and developments
- Various technical details about rockets are discussed

Transcript:
{$wrappedTranscript}
PROMPT;

        $result = $aiService->process($prompt, [
            'max_tokens' => 1000,
            'temperature' => 0,
        ]);

        if (!($result['success'] ?? false)) {
            throw new Exception($result['error'] ?? 'AI processing failed');
        }

        $response = $result['response'] ?? '';
        $points = [];

        // Parse bullet points from response
        if (preg_match_all('/^-\s+(.+)$/m', $response, $matches)) {
            $points = array_map('trim', $matches[1]);
        }

        return $points;
    }

    private function logMessage(?object $output, string $message): void
    {
        if ($output) {
            $output->writeln($message);
        }
        Log::info('[YouTubeKeyPointsPostProcessor] ' . strip_tags($message));
    }
}
