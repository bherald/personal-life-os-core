<?php

namespace App\Console\Commands;

use App\Nodes\YouTube\YouTubeTranscriptFormatter;
use App\Services\JoplinWriteService;
use App\Services\JoplinYouTubeOrganizer;
use App\Services\YouTubeJoplinLanguageAuditService;
use App\Services\YouTubeJoplinService;
use App\Services\YouTubeTranscriptLanguagePolicy;
use App\Services\YouTubeTranscriptService;
use Illuminate\Console\Command;

class YouTubeRepairJoplinLanguage extends Command
{
    protected $signature = 'youtube:repair-joplin-language
                            {--target=en : Required transcript language for rebuilt notes}
                            {--limit=0 : Maximum non-compliant notes to process (0 = all)}
                            {--batch=50 : Joplin batch fetch size}
                            {--write : Apply updates instead of dry run}';

    protected $description = 'Audit YouTube Joplin notes and rebuild non-target-language notes from fresh transcripts';

    public function handle(
        JoplinYouTubeOrganizer $organizer,
        YouTubeJoplinLanguageAuditService $auditService,
        YouTubeTranscriptLanguagePolicy $languagePolicy,
        YouTubeTranscriptService $transcriptService,
        YouTubeJoplinService $joplinService,
        JoplinWriteService $joplinWriteService
    ): int {
        $targetOption = (string) $this->option('target');
        $languageValidation = $languagePolicy->validateRequestedLanguage($targetOption);

        if (! ($languageValidation['success'] ?? false)) {
            $this->error($languageValidation['error']);

            return self::FAILURE;
        }

        $targetLanguage = $languageValidation['language'];
        $limit = max(0, (int) $this->option('limit'));
        $batchSize = max(1, (int) $this->option('batch'));
        $write = (bool) $this->option('write');

        $this->line(sprintf(
            'Scanning YouTube Joplin notes. Target language: %s. Mode: %s.',
            $languagePolicy->describe($targetLanguage),
            $write ? 'write' : 'dry-run'
        ));

        $files = $organizer->listDirectory($organizer->getJoplinPath());
        $contents = $organizer->getFileContentsBatch($files, $batchSize);

        $stats = [
            'files_scanned' => count($files),
            'youtube_notes' => 0,
            'candidates' => 0,
            'updated' => 0,
            'queued' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $languageCounts = [];
        $candidates = [];

        foreach ($contents as $filename => $content) {
            $note = $organizer->parseNote($content);
            $note['filename'] = $filename;

            if (! $auditService->isYouTubeVideoNote($note)) {
                continue;
            }

            $stats['youtube_notes']++;
            $metadata = $auditService->extractMetadata($note);
            $currentLanguage = $languagePolicy->normalize($metadata['transcript_language'] ?? null) ?? 'unknown';
            $languageCounts[$currentLanguage] = ($languageCounts[$currentLanguage] ?? 0) + 1;

            if (! $auditService->shouldRepair($metadata, $targetLanguage)) {
                $stats['skipped']++;

                continue;
            }

            $stats['candidates']++;
            $candidates[] = [
                'note' => $note,
                'metadata' => $metadata,
            ];

            if ($limit > 0 && count($candidates) >= $limit) {
                break;
            }
        }

        ksort($languageCounts);

        $this->line(sprintf('Scanned %d Joplin files and found %d YouTube notes.', $stats['files_scanned'], $stats['youtube_notes']));
        $this->line('Current transcript language distribution: '.json_encode($languageCounts, JSON_UNESCAPED_SLASHES));
        $this->line(sprintf('Found %d non-compliant YouTube notes.', count($candidates)));

        if (! $write) {
            foreach (array_slice($candidates, 0, 10) as $candidate) {
                $metadata = $candidate['metadata'];
                $this->line(sprintf(
                    '- %s [%s] %s',
                    $candidate['note']['title'] ?: '(untitled)',
                    $metadata['transcript_language'] ?? 'missing',
                    $metadata['video_id'] ?? 'missing-video-id'
                ));
            }

            return self::SUCCESS;
        }

        $formatter = new YouTubeTranscriptFormatter([
            'enabled' => true,
            'skip_if_no_transcript' => true,
            'batch_size' => 1,
        ]);

        foreach ($candidates as $candidate) {
            $note = $candidate['note'];
            $metadata = $candidate['metadata'];
            $videoId = $metadata['video_id'] ?? null;

            if (! $videoId) {
                $stats['failed']++;
                $this->warn("Missing video ID for note {$note['id']} ({$note['title']})");

                continue;
            }

            $transcript = $transcriptService->refreshTranscript($videoId, $targetLanguage);
            if (! ($transcript['success'] ?? false)) {
                $stats['failed']++;
                $this->warn("Transcript refresh failed for {$videoId}: ".($transcript['error'] ?? 'unknown error'));

                continue;
            }

            $formattedVideo = $this->formatTranscript($formatter, $note, $transcript);
            $videoData = $auditService->buildReplacementVideoData(
                $note,
                $metadata,
                $transcript,
                $formattedVideo,
                $targetLanguage
            );

            $noteContent = $joplinService->renderVideoNoteContent($videoData);
            $update = $joplinWriteService->updateNote($note['id'], [
                'content' => $noteContent,
            ], false);

            if (! ($update['success'] ?? false)) {
                $stats['failed']++;
                $this->warn("Failed to update note {$note['id']} ({$note['title']}): ".($update['error'] ?? 'unknown error'));

                continue;
            }

            if ($update['queued'] ?? false) {
                $stats['queued']++;
                $this->line("Queued note rebuild for {$note['title']} ({$videoId})");

                continue;
            }

            $stats['updated']++;
            $this->line("Rebuilt note {$note['title']} ({$videoId})");
        }

        $this->line('Summary: '.json_encode($stats, JSON_UNESCAPED_SLASHES));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function formatTranscript(
        YouTubeTranscriptFormatter $formatter,
        array $note,
        array $transcript
    ): array {
        $result = $formatter->execute([
            'videos' => [[
                'video_id' => $transcript['video_id'] ?? ($note['id'] ?? ''),
                'title' => $note['title'] ?? 'YouTube Video',
                'transcript_full_text' => $transcript['full_text'] ?? '',
                'transcript_word_count' => $transcript['word_count'] ?? null,
                'transcript_language' => $transcript['language'] ?? 'en',
            ]],
        ]);

        return $result['data']['videos'][0] ?? [
            'transcript_full_text' => $transcript['full_text'] ?? '',
            'key_points' => [],
        ];
    }
}
