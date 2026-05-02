<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * YouTube Joplin Service
 *
 * Handles integration between YouTube video processing and Joplin note creation.
 * Renders video summary templates and creates structured notes in Joplin.
 */
class YouTubeJoplinService
{
    protected JoplinWriteService $joplinService;

    protected JoplinFilesService $joplinFilesService;

    public function __construct(
        JoplinWriteService $joplinService,
        JoplinFilesService $joplinFilesService
    ) {
        $this->joplinService = $joplinService;
        $this->joplinFilesService = $joplinFilesService;
    }

    /**
     * Create a Joplin note from YouTube video data
     *
     * @param  array  $videoData  Video metadata and transcript
     * @param  string|null  $notebookName  Notebook name (will be created if doesn't exist)
     * @return array Result with note_id and metadata
     */
    public function createVideoNote(array $videoData, ?string $notebookName = 'YouTube Research'): array
    {
        try {
            // Debug: Log what data we're receiving
            Log::debug('YouTubeJoplinService: Video data received', [
                'video_id' => $videoData['video_id'] ?? 'N/A',
                'has_transcript' => isset($videoData['transcript_full_text']),
                'has_ai_summary' => isset($videoData['ai_summary']),
                'has_key_points' => isset($videoData['key_points']),
                'keys' => array_keys($videoData),
            ]);

            // Find or create notebook
            $notebookId = $this->findOrCreateNotebook($notebookName);

            if (! $notebookId) {
                throw new Exception("Failed to find or create notebook: {$notebookName}");
            }

            // Render template
            $noteContent = $this->renderVideoTemplate($videoData);

            // Create note title
            $title = $this->sanitizeTitle($videoData['title'] ?? 'YouTube Video');

            // Create note in Joplin
            $result = $this->joplinService->createNote(
                $title,
                $noteContent,
                $notebookId,
                [
                    'metadata' => [
                        'video_id' => $videoData['video_id'] ?? null,
                        'channel_id' => $videoData['channel_id'] ?? null,
                        'source' => 'youtube_automation',
                    ],
                ]
            );

            if (! $result['success']) {
                throw new Exception($result['error'] ?? 'Unknown error creating note');
            }

            // Handle queued vs immediate creation
            if ($result['queued'] ?? false) {
                Log::info('Queued YouTube video Joplin note', [
                    'job_id' => $result['job_id'],
                    'video_id' => $videoData['video_id'] ?? null,
                    'title' => $title,
                    'notebook' => $notebookName,
                ]);

                return [
                    'success' => true,
                    'queued' => true,
                    'job_id' => $result['job_id'],
                    'notebook_id' => $notebookId,
                    'notebook_name' => $notebookName,
                    'title' => $title,
                ];
            }

            Log::info('Created YouTube video Joplin note', [
                'note_id' => $result['note_id'],
                'video_id' => $videoData['video_id'] ?? null,
                'title' => $title,
                'notebook' => $notebookName,
            ]);

            return [
                'success' => true,
                'note_id' => $result['note_id'],
                'notebook_id' => $notebookId,
                'notebook_name' => $notebookName,
                'title' => $title,
                'joplin_url' => "joplin://x-callback-url/openNote?id={$result['note_id']}",
                'queued' => false,
            ];

        } catch (Exception $e) {
            Log::error('Failed to create YouTube Joplin note', [
                'error' => $e->getMessage(),
                'video_id' => $videoData['video_id'] ?? null,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Render YouTube note content without creating a new note.
     */
    public function renderVideoNoteContent(array $videoData): string
    {
        return $this->renderVideoTemplate($videoData);
    }

    /**
     * Render video summary template with video data
     *
     * @param  array  $videoData  Video metadata and transcript
     * @return string Rendered markdown content
     */
    protected function renderVideoTemplate(array $videoData): string
    {
        $templatePath = resource_path('templates/joplin/video_summary.md');

        if (! File::exists($templatePath)) {
            Log::warning('Video summary template not found, using default format');

            return $this->generateDefaultTemplate($videoData);
        }

        $template = File::get($templatePath);

        // Prepare template variables
        $variables = [
            'VIDEO_TITLE' => $videoData['title'] ?? 'Untitled Video',
            'VIDEO_URL' => $videoData['url'] ?? "https://youtube.com/watch?v={$videoData['video_id']}",
            'VIDEO_ID' => $videoData['video_id'] ?? 'Unknown',
            'CHANNEL_NAME' => $videoData['channel_title'] ?? $videoData['channel'] ?? 'Unknown Channel',
            'CHANNEL_SLUG' => Str::slug($videoData['channel_title'] ?? $videoData['channel'] ?? 'unknown'),
            'DURATION_FORMATTED' => $videoData['duration_formatted'] ?? $this->formatDuration($videoData['duration_seconds'] ?? 0),
            'PUBLISHED_DATE' => isset($videoData['published_at']) ? date('Y-m-d', strtotime($videoData['published_at'])) : 'Unknown',
            'PROCESSED_DATE' => date('Y-m-d H:i:s'),
            'TIER' => $videoData['tier'] ?? 'unknown',
            'KEY_POINTS_BULLETS' => $this->formatKeyPoints($videoData['key_points'] ?? []),
            'FULL_TRANSCRIPT' => $videoData['transcript_full_text'] ?? '*Transcript not available*',
            'WORD_COUNT' => $videoData['word_count'] ?? ($videoData['transcript_word_count'] ?? 'Unknown'),
            'CAPTION_TYPE' => $videoData['caption_type'] ?? ($videoData['transcript_caption_type'] ?? 'Unknown'),
            'LANGUAGE' => $videoData['language'] ?? ($videoData['transcript_language'] ?? 'en'),
            'DOCUMENT_COUNT' => $videoData['rag_document_count'] ?? 'TBD',
            'JOPLIN_NOTEBOOK' => $videoData['joplin_notebook'] ?? 'YouTube Research',
            'JOPLIN_NOTE_ID' => $videoData['joplin_note_id'] ?? '{TO_BE_SET}',
        ];

        // Replace all variables in template
        $content = $template;
        foreach ($variables as $key => $value) {
            $content = str_replace('{'.$key.'}', $value, $content);
        }

        return $content;
    }

    /**
     * Generate default template if template file doesn't exist
     */
    protected function generateDefaultTemplate(array $videoData): string
    {
        $title = $videoData['title'] ?? 'Untitled Video';
        $url = $videoData['url'] ?? "https://youtube.com/watch?v={$videoData['video_id']}";
        $channel = $videoData['channel_title'] ?? 'Unknown Channel';

        return "# {$title}\n\n**Video:** [Watch on YouTube]({$url})\n**Channel:** {$channel}\n\n---\n\n*Processed by PLOS YouTube Integration*";
    }

    /**
     * Format duration in seconds to HH:MM:SS
     */
    protected function formatDuration(int $seconds): string
    {
        return gmdate('H:i:s', $seconds);
    }

    /**
     * Sanitize title for Joplin (remove special chars that might cause issues)
     */
    protected function sanitizeTitle(string $title): string
    {
        // Remove newlines and excessive whitespace
        $title = preg_replace('/\s+/', ' ', $title);
        $title = trim($title);

        // Limit length (Joplin can handle long titles, but be reasonable)
        if (mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 197).'...';
        }

        return $title;
    }

    /**
     * Format key points array into bullet point markdown
     *
     * @param  array  $keyPoints  Array of key point strings
     * @return string Markdown bullet list or placeholder
     */
    protected function formatKeyPoints(array $keyPoints): string
    {
        if (empty($keyPoints)) {
            return '*Key points will be extracted*';
        }

        $bullets = [];
        foreach ($keyPoints as $point) {
            $bullets[] = "- {$point}";
        }

        return implode("\n\n", $bullets);
    }

    /**
     * Find or create a Joplin notebook by name
     *
     * Optimized to cache specific notebook IDs instead of loading all notebooks.
     * The background RAG job handles full notebook cataloging separately.
     *
     * @return string|null Notebook ID
     */
    protected function findOrCreateNotebook(string $notebookName): ?string
    {
        try {
            // Use notebook-specific cache key (24 hour TTL)
            $cacheKey = 'joplin_notebook_id:'.Str::slug($notebookName);

            return Cache::remember($cacheKey, 86400, function () use ($notebookName) {
                Log::debug('Looking up or creating Joplin notebook', ['name' => $notebookName]);

                // Try to create the notebook (will return existing ID if already exists)
                $result = $this->joplinService->createNotebook($notebookName);

                if (! $result['success']) {
                    // If creation failed, try searching in the full notebook list as fallback
                    // (This is slower but ensures we don't miss existing notebooks)
                    Log::debug('Notebook creation returned error, trying fallback search', [
                        'name' => $notebookName,
                        'error' => $result['error'] ?? 'Unknown error',
                    ]);

                    $notebooks = $this->joplinFilesService->getNotebooks();
                    foreach ($notebooks as $notebook) {
                        if ($notebook['title'] === $notebookName) {
                            Log::info('Found existing notebook via fallback search', [
                                'name' => $notebookName,
                                'id' => $notebook['id'],
                            ]);

                            return $notebook['id'];
                        }
                    }

                    Log::error('Failed to find or create notebook', [
                        'name' => $notebookName,
                        'error' => $result['error'] ?? 'Unknown error',
                    ]);

                    return null;
                }

                Log::info('Notebook ready', [
                    'name' => $notebookName,
                    'id' => $result['notebook_id'],
                    'was_created' => $result['created'] ?? false,
                ]);

                return $result['notebook_id'];
            });

        } catch (Exception $e) {
            Log::error('Failed to find or create notebook', [
                'name' => $notebookName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Update an existing note with Joplin note ID
     * (Called after note creation to update the template with actual note ID)
     */
    public function updateNoteWithId(string $noteId): array
    {
        try {
            $note = $this->joplinFilesService->getNote($noteId);

            if (! $note) {
                throw new Exception("Note not found: {$noteId}");
            }

            // Replace placeholder with actual note ID
            $updatedContent = str_replace('{TO_BE_SET}', $noteId, $note['content']);

            if ($updatedContent === $note['content']) {
                // No changes needed
                return [
                    'success' => true,
                    'note_id' => $noteId,
                    'updated' => false,
                ];
            }

            // Update note
            $result = $this->joplinService->updateNote($noteId, [
                'content' => $updatedContent,
            ], false); // No conflict detection

            return [
                'success' => $result['success'],
                'note_id' => $noteId,
                'updated' => true,
                'error' => $result['error'] ?? null,
            ];

        } catch (Exception $e) {
            Log::error('Failed to update note with ID', [
                'note_id' => $noteId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
