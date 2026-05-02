<?php

namespace App\Nodes\YouTube;

use App\Nodes\BaseNode;
use App\Services\RAGService;
use App\Services\AIService;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * YouTube RAG Index Node
 *
 * Indexes YouTube video transcripts into RAG with hybrid chunking strategy:
 * 1. Index full transcript as one document
 * 2. Use AI to extract 3-5 key quotes
 * 3. Index each quote as a separate document
 * 4. Link all documents via metadata
 *
 * Uses DI container for proper AIService resilience (circuit breaker, retry, fallback).
 */
class YouTubeRAGIndex extends BaseNode
{
    private ?AIService $aiService = null;

    public function execute(array $input): array
    {
        try {
            // Get configuration
            $chunkStrategy = $this->getConfigValue('chunk_strategy', 'hybrid');
            $designation = $this->getConfigValue('designation', 'youtube_video');
            $includeMetadata = $this->getConfigValue('include_metadata', true);
            $extractQuotes = $this->getConfigValue('extract_quotes', true);
            $quoteCount = $this->getConfigValue('quote_count', 5);

            // Extract videos from input
            $videos = $input['data']['videos'] ?? $input['videos'] ?? [];
            if (empty($videos)) {
                Log::warning('YouTubeRAGIndex: No videos in input');
                return $this->standardOutput([
                    'videos' => [],
                    'count' => 0,
                    'documents_indexed' => 0,
                ]);
            }

            Log::info('YouTubeRAGIndex: Starting execution', [
                'video_count' => count($videos),
                'chunk_strategy' => $chunkStrategy,
                'extract_quotes' => $extractQuotes
            ]);

            // Use DI container for proper AIService resilience
            $ragService = app(RAGService::class);
            $this->aiService = app(AIService::class);
            $enrichedVideos = [];
            $totalDocuments = 0;
            $this->initTimeLimit();

            foreach ($videos as $video) {
                if (!$this->hasTimeRemaining()) {
                    Log::warning('YouTubeRAGIndex: Wall-clock limit reached', [
                        'elapsed_seconds' => round($this->elapsedSeconds()),
                        'indexed' => $totalDocuments,
                        'remaining' => count($videos) - count($enrichedVideos),
                    ]);
                    break;
                }
                $videoId = $video['video_id'] ?? null;
                $transcriptText = $video['transcript_full_text'] ?? null;

                if (!$videoId || !$transcriptText) {
                    Log::warning('Video missing transcript', [
                        'video_id' => $videoId,
                        'has_transcript' => !empty($transcriptText)
                    ]);

                    $enrichedVideos[] = array_merge($video, [
                        'rag_indexed' => false,
                        'rag_error' => 'Missing video ID or transcript'
                    ]);
                    continue;
                }

                try {
                    $documentIds = [];

                    // Prepare metadata
                    $baseMetadata = $this->prepareMetadata($video, $includeMetadata);

                    // Strategy 1: Index full transcript
                    $fullDocTitle = $video['title'] ?? "YouTube Video {$videoId}";
                    $fullDoc = $ragService->indexDocument(
                        $designation,
                        $transcriptText,
                        $fullDocTitle,
                        array_merge($baseMetadata, [
                            'chunk_type' => 'full_transcript',
                            'chunk_index' => 0,
                        ]),
                        $videoId,
                        'youtube_video'
                    );

                    $documentIds[] = $fullDoc->id;
                    $totalDocuments++;

                    Log::info('Indexed full transcript', [
                        'video_id' => $videoId,
                        'document_id' => $fullDoc->id,
                        'content_length' => strlen($transcriptText)
                    ]);

                    // Strategy 2: Extract and index key quotes (if enabled)
                    if ($extractQuotes && $chunkStrategy === 'hybrid') {
                        $quotes = $this->extractKeyQuotes($transcriptText, $quoteCount);

                        foreach ($quotes as $index => $quote) {
                            $quoteTitle = "{$fullDocTitle} - Quote " . ($index + 1);
                            $quoteDoc = $ragService->indexDocument(
                                $designation,
                                $quote['text'],
                                $quoteTitle,
                                array_merge($baseMetadata, [
                                    'chunk_type' => 'key_quote',
                                    'chunk_index' => $index + 1,
                                    'quote_timestamp' => $quote['timestamp'] ?? null,
                                    'parent_document_id' => $fullDoc->id,
                                ]),
                                $videoId,
                                'youtube_video'
                            );

                            $documentIds[] = $quoteDoc->id;
                            $totalDocuments++;
                        }

                        Log::info('Indexed key quotes', [
                            'video_id' => $videoId,
                            'quote_count' => count($quotes)
                        ]);
                    }

                    $enrichedVideos[] = array_merge($video, [
                        'rag_indexed' => true,
                        'rag_document_ids' => $documentIds,
                        'rag_document_count' => count($documentIds),
                    ]);

                    Log::info('Video indexed to RAG', [
                        'video_id' => $videoId,
                        'documents_created' => count($documentIds)
                    ]);

                } catch (Exception $e) {
                    $enrichedVideos[] = array_merge($video, [
                        'rag_indexed' => false,
                        'rag_error' => $e->getMessage()
                    ]);

                    Log::error('Failed to index video', [
                        'video_id' => $videoId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            Log::info('YouTubeRAGIndex: Execution completed', [
                'videos_processed' => count($videos),
                'total_documents_indexed' => $totalDocuments
            ]);

            return $this->standardOutput([
                'videos' => $enrichedVideos,
                'count' => count($enrichedVideos),
                'documents_indexed' => $totalDocuments,
            ], [
                'chunk_strategy' => $chunkStrategy,
                'designation' => $designation,
                'extract_quotes' => $extractQuotes,
            ]);

        } catch (Exception $e) {
            Log::error('YouTubeRAGIndex: Execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->standardOutput([], [], $e->getMessage());
        }
    }

    /**
     * Prepare metadata for RAG document
     *
     * @param array $video
     * @param bool $includeMetadata
     * @return array
     */
    private function prepareMetadata(array $video, bool $includeMetadata): array
    {
        if (!$includeMetadata) {
            return [];
        }

        $metadata = [
            'video_id' => $video['video_id'] ?? null,
            'channel_id' => $video['channel_id'] ?? null,
            'channel_title' => $video['channel_title'] ?? null,
            'published_at' => $video['published_at'] ?? null,
            'duration_seconds' => $video['duration_seconds'] ?? null,
            'duration_formatted' => $video['duration_formatted'] ?? null,
            'view_count' => $video['view_count'] ?? null,
            'like_count' => $video['like_count'] ?? null,
            'url' => $video['url'] ?? null,
            'thumbnail' => $video['thumbnail'] ?? null,
            'caption_type' => $video['transcript_caption_type'] ?? null,
            'word_count' => $video['transcript_word_count'] ?? null,
            'tier' => $video['tier'] ?? null,
        ];

        // Add Joplin metadata if available (bidirectional linking)
        if (!empty($video['joplin_note_id'])) {
            $metadata['joplin_note_id'] = $video['joplin_note_id'];
            $metadata['joplin_notebook'] = $video['joplin_notebook'] ?? null;
            $metadata['joplin_url'] = $video['joplin_url'] ?? null;
        }

        return $metadata;
    }

    /**
     * Extract key quotes from transcript using AI
     *
     * @param string $transcript
     * @param int $quoteCount
     * @return array
     */
    private function extractKeyQuotes(string $transcript, int $quoteCount): array
    {
        try {
            // Use AI to extract key quotes with AIService resilience
            $prompt = <<<PROMPT
Extract {$quoteCount} key quotes from this YouTube video transcript.
Each quote should be:
- A self-contained, meaningful excerpt (1-3 sentences)
- Represent an important point, insight, or takeaway
- Include enough context to be understood independently

Return the quotes as a JSON array with this format:
[
    {"text": "quote text here", "importance": "brief explanation"},
    ...
]

Transcript:
{$transcript}
PROMPT;

            $result = $this->aiService->process($prompt, [
                'factual_mode' => true,
                'max_tokens' => 2000
            ]);

            if (!$result['success']) {
                Log::warning('AI quote extraction failed', ['error' => $result['error']]);
                return [];
            }

            $content = $result['response'] ?? '';

            // Try to parse JSON from response
            if (preg_match('/\[.*\]/s', $content, $matches)) {
                $quotes = json_decode($matches[0], true);
                if (is_array($quotes)) {
                    return array_slice($quotes, 0, $quoteCount);
                }
            }

            Log::warning('Failed to parse AI quotes response', ['response' => $content]);
            return [];

        } catch (Exception $e) {
            Log::error('Failed to extract key quotes', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
