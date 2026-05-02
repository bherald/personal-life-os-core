<?php

namespace App\Services;

class YouTubeJoplinLanguageAuditService
{
    public function __construct(
        private readonly YouTubeTranscriptLanguagePolicy $languagePolicy
    ) {}

    public function isYouTubeVideoNote(array $note): bool
    {
        if (($note['type'] ?? 0) !== 1) {
            return false;
        }

        $content = $note['raw'] ?? ($note['content'] ?? '');

        return str_contains($content, '**Video:** [Watch on YouTube]')
            && str_contains($content, '**Video ID:**');
    }

    public function extractMetadata(array $note): array
    {
        $content = $note['raw'] ?? ($note['content'] ?? '');

        return [
            'video_url' => $this->capture('/\*\*Video:\*\*\s*\[Watch on YouTube\]\(([^)]+)\)/', $content),
            'video_id' => $this->capture('/\*\*Video ID:\*\*\s*([A-Za-z0-9_-]{11})/', $content),
            'channel_name' => $this->capture('/\*\*Channel:\*\*\s*(.+)$/m', $content),
            'duration_formatted' => $this->capture('/\*\*Duration:\*\*\s*(.+)$/m', $content),
            'published_at' => $this->capture('/\*\*Published:\*\*\s*(.+)$/m', $content),
            'tier' => $this->capture('/\*\*Tier:\*\*\s*(.+)$/m', $content),
            'transcript_language' => $this->capture('/^- Language:\s*(.+)$/m', $content),
            'caption_type' => $this->capture('/^- Caption Type:\s*(.+)$/m', $content),
            'word_count' => $this->capture('/^- Word Count:\s*(.+)$/m', $content),
            'joplin_notebook' => $this->capture('/\*\*Joplin Notebook:\*\*\s*(.+)$/m', $content),
            'rag_document_count' => $this->capture('/\*\*RAG Indexed:\*\*\s*.+\(([^)]+)\s+documents\)/', $content),
        ];
    }

    public function shouldRepair(array $metadata, string $targetLanguage = 'en'): bool
    {
        $target = $this->languagePolicy->normalize($targetLanguage);
        $current = $this->languagePolicy->normalize($metadata['transcript_language'] ?? null);

        if ($target === null) {
            return false;
        }

        return $current === null || $current !== $target;
    }

    public function buildReplacementVideoData(
        array $note,
        array $metadata,
        array $transcript,
        array $formattedVideo,
        string $targetLanguage = 'en'
    ): array {
        $videoId = $metadata['video_id'] ?? '';
        $transcriptLanguage = $transcript['language'] ?? $targetLanguage;

        return [
            'title' => $note['title'] ?? 'YouTube Video',
            'url' => $metadata['video_url'] ?: "https://youtube.com/watch?v={$videoId}",
            'video_id' => $videoId,
            'channel_title' => $metadata['channel_name'] ?: 'Unknown Channel',
            'duration_formatted' => $metadata['duration_formatted'] ?: 'Unknown',
            'published_at' => $metadata['published_at'] ?: null,
            'tier' => $metadata['tier'] ?: 'unknown',
            'key_points' => $formattedVideo['key_points'] ?? [],
            'transcript_full_text' => $formattedVideo['transcript_full_text'] ?? ($transcript['full_text'] ?? ''),
            'transcript_language' => $transcriptLanguage,
            'transcript_caption_type' => $transcript['caption_type'] ?? ($metadata['caption_type'] ?: 'Unknown'),
            'transcript_word_count' => $transcript['word_count'] ?? ($metadata['word_count'] ?: 'Unknown'),
            'word_count' => $transcript['word_count'] ?? ($metadata['word_count'] ?: 'Unknown'),
            'caption_type' => $transcript['caption_type'] ?? ($metadata['caption_type'] ?: 'Unknown'),
            'language' => $transcriptLanguage,
            'rag_document_count' => $metadata['rag_document_count'] ?: 'TBD',
            'joplin_notebook' => $metadata['joplin_notebook'] ?: 'YouTube Research',
            'joplin_note_id' => $note['id'] ?? '{TO_BE_SET}',
        ];
    }

    private function capture(string $pattern, string $content): ?string
    {
        if (! preg_match($pattern, $content, $matches)) {
            return null;
        }

        $value = trim($matches[1]);

        return $value === '' ? null : $value;
    }
}
