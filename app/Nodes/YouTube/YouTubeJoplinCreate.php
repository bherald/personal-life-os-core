<?php

namespace App\Nodes\YouTube;

use App\Controllers\NotificationController;
use App\Nodes\BaseNode;
use App\Services\YouTubeJoplinService;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * YouTube Joplin Create Node
 *
 * Creates Joplin notes from YouTube video data using templates.
 * Integrates processed video transcripts into Joplin for mobile access.
 *
 * Includes fault tolerance: if Joplin/Nextcloud is unreachable,
 * notes are saved to storage/app/joplin-fallback/ for manual import.
 */
class YouTubeJoplinCreate extends BaseNode
{
    protected const FALLBACK_PATH = 'storage/app/joplin-fallback';

    protected bool $fallbackMode = false;

    public function execute(array $input): array
    {
        try {
            // Check if YouTube feature is enabled
            if (! config('youtube.enabled', false)) {
                return $this->standardOutput($input, [], 'YouTube integration is disabled');
            }

            // Get configuration
            $notebook = $this->getConfigValue('notebook', 'YouTube Research');
            $createNotes = $this->getConfigValue('create_notes', true);

            if (! $createNotes) {
                Log::info('YouTubeJoplinCreate: Note creation disabled, skipping');

                return $this->standardOutput($input);
            }

            // Get videos from input (handles both wrapped and direct input)
            $videos = $input['data']['videos'] ?? $input['videos'] ?? [];

            if (empty($videos)) {
                Log::info('YouTubeJoplinCreate: No videos to process');

                return $this->standardOutput($input);
            }

            Log::info('YouTubeJoplinCreate: Creating Joplin notes', [
                'video_count' => count($videos),
                'notebook' => $notebook,
            ]);

            // Initialize service
            $joplinService = app(YouTubeJoplinService::class);

            // Process each video
            $results = [];
            $successCount = 0;
            $queuedCount = 0;
            $failedCount = 0;
            $skippedCount = 0;
            $fallbackCount = 0;
            $fallbackFiles = [];
            $this->initTimeLimit();

            foreach ($videos as $index => $video) {
                if (! $this->hasTimeRemaining()) {
                    Log::warning('YouTubeJoplinCreate: Wall-clock limit reached', [
                        'elapsed_seconds' => round($this->elapsedSeconds()),
                        'created' => $successCount,
                        'remaining' => count($videos) - $index,
                    ]);
                    break;
                }
                try {
                    // Skip videos without transcripts
                    if (empty($video['transcript_available']) || $video['transcript_available'] !== true) {
                        $skippedCount++;
                        Log::debug('YouTubeJoplinCreate: Skipping video without transcript', [
                            'video_id' => $video['video_id'] ?? 'unknown',
                            'title' => $video['title'] ?? 'Unknown',
                            'transcript_available' => $video['transcript_available'] ?? false,
                        ]);

                        continue;
                    }

                    // If in fallback mode, go straight to fallback
                    if ($this->fallbackMode) {
                        $fallbackResult = $this->writeToFallback($video);
                        if ($fallbackResult['success']) {
                            $fallbackCount++;
                            $fallbackFiles[] = $fallbackResult['filename'];
                        } else {
                            $failedCount++;
                        }

                        continue;
                    }

                    // Try to create Joplin note
                    $result = $joplinService->createVideoNote($video, $notebook);

                    if ($result['success']) {
                        if ($result['queued'] ?? false) {
                            $queuedCount++;
                            Log::info('YouTubeJoplinCreate: Note queued', [
                                'video_id' => $video['video_id'],
                                'job_id' => $result['job_id'],
                            ]);
                        } else {
                            $successCount++;
                            Log::info('YouTubeJoplinCreate: Note created', [
                                'video_id' => $video['video_id'],
                                'note_id' => $result['note_id'],
                            ]);

                            // Update video data with Joplin info
                            $videos[$index]['joplin_note_id'] = $result['note_id'];
                            $videos[$index]['joplin_notebook_id'] = $result['notebook_id'];
                            $videos[$index]['joplin_notebook'] = $result['notebook_name'];
                            $videos[$index]['joplin_url'] = $result['joplin_url'];
                        }

                        $results[] = $result;
                    } else {
                        // Check if this is a connection error
                        $error = $result['error'] ?? '';
                        if ($this->isConnectionError($error)) {
                            Log::warning('YouTubeJoplinCreate: Connection error detected, switching to fallback mode', [
                                'video_id' => $video['video_id'],
                                'error' => $error,
                            ]);

                            $this->fallbackMode = true;

                            // Write this video to fallback
                            $fallbackResult = $this->writeToFallback($video);
                            if ($fallbackResult['success']) {
                                $fallbackCount++;
                                $fallbackFiles[] = $fallbackResult['filename'];
                            } else {
                                $failedCount++;
                            }
                        } else {
                            $failedCount++;
                            Log::error('YouTubeJoplinCreate: Failed to create note', [
                                'video_id' => $video['video_id'],
                                'error' => $error,
                            ]);
                        }
                    }

                } catch (Exception $e) {
                    $error = $e->getMessage();

                    // Check if this is a connection error
                    if ($this->isConnectionError($error)) {
                        Log::warning('YouTubeJoplinCreate: Connection exception, switching to fallback mode', [
                            'video_id' => $video['video_id'] ?? 'unknown',
                            'error' => $error,
                        ]);

                        $this->fallbackMode = true;

                        // Write this video to fallback
                        $fallbackResult = $this->writeToFallback($video);
                        if ($fallbackResult['success']) {
                            $fallbackCount++;
                            $fallbackFiles[] = $fallbackResult['filename'];
                        } else {
                            $failedCount++;
                        }
                    } else {
                        $failedCount++;
                        Log::error('YouTubeJoplinCreate: Exception processing video', [
                            'video_id' => $video['video_id'] ?? 'unknown',
                            'error' => $error,
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
            }

            // Send Pushover notification if any notes were saved to fallback
            if ($fallbackCount > 0) {
                $this->sendFallbackNotification($fallbackCount, $fallbackFiles);
            }

            Log::info('YouTubeJoplinCreate: Batch complete', [
                'total' => count($videos),
                'created' => $successCount,
                'queued' => $queuedCount,
                'failed' => $failedCount,
                'skipped' => $skippedCount,
                'fallback' => $fallbackCount,
            ]);

            // Return updated videos with Joplin metadata
            // Pass through ip_blocked status and transcript counts from previous nodes
            return $this->standardOutput([
                'videos' => $videos,
                'count' => count($videos),
                'transcripts_fetched' => $input['data']['transcripts_fetched'] ?? $input['transcripts_fetched'] ?? 0,
                'transcripts_failed' => $input['data']['transcripts_failed'] ?? $input['transcripts_failed'] ?? 0,
                'ip_blocked' => $input['data']['ip_blocked'] ?? $input['ip_blocked'] ?? false,
                'ip_block_error' => $input['data']['ip_block_error'] ?? $input['ip_block_error'] ?? null,
            ], [
                'joplin_notes_created' => $successCount,
                'joplin_notes_queued' => $queuedCount,
                'joplin_notes_failed' => $failedCount,
                'joplin_notes_skipped' => $skippedCount,
                'joplin_notes_fallback' => $fallbackCount,
                'fallback_files' => $fallbackFiles,
                'notebook' => $notebook,
                'results' => $results,
            ]);

        } catch (Exception $e) {
            Log::error('YouTubeJoplinCreate: Execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->standardOutput($input, [], $e->getMessage());
        }
    }

    /**
     * Check if an error message indicates a connection failure
     *
     * @param  string  $error  Error message to check
     * @return bool True if this is a connection error
     */
    protected function isConnectionError(string $error): bool
    {
        $connectionPatterns = [
            'cURL error 7',           // Failed to connect
            'cURL error 28',          // Connection timed out
            'Connection refused',
            'Failed to connect',
            'Could not resolve host',
            'Network is unreachable',
        ];

        foreach ($connectionPatterns as $pattern) {
            if (stripos($error, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write a video note to the fallback folder
     *
     * @param  array  $video  Video data
     * @return array Result with success status and filename
     */
    protected function writeToFallback(array $video): array
    {
        try {
            $fallbackPath = base_path(self::FALLBACK_PATH);

            // Ensure fallback directory exists
            if (! File::isDirectory($fallbackPath)) {
                File::makeDirectory($fallbackPath, 0755, true);
            }

            $title = $video['title'] ?? 'Untitled';
            $videoId = $video['video_id'] ?? '';
            $channelTitle = $video['channel_title'] ?? '';
            $publishedAt = $video['published_at'] ?? '';
            $durationFormatted = $video['duration_formatted'] ?? '';
            $url = $video['url'] ?? "https://youtube.com/watch?v={$videoId}";
            $content = $video['transcript_full_text'] ?? '';
            $description = $video['description'] ?? '';

            // Clean title for filename
            $cleanTitle = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $title);
            $cleanTitle = trim(substr($cleanTitle, 0, 80));
            $date = substr($publishedAt, 0, 10);

            $filename = "{$cleanTitle}_{$date}.md";
            $filepath = "{$fallbackPath}/{$filename}";

            // Build markdown content
            $markdown = "# {$title}\n\n";
            $markdown .= "**Channel:** {$channelTitle}\n";
            $markdown .= "**Published:** {$publishedAt}\n";
            $markdown .= "**Duration:** {$durationFormatted}\n";
            $markdown .= "**URL:** {$url}\n\n";
            $markdown .= "---\n\n";

            if (! empty($content)) {
                $markdown .= $content."\n";
            } elseif (! empty($description)) {
                $markdown .= "## Description\n\n{$description}\n";
            } else {
                $markdown .= "*No transcript available*\n";
            }

            File::put($filepath, $markdown);

            Log::info('YouTubeJoplinCreate: Note saved to fallback', [
                'video_id' => $videoId,
                'filename' => $filename,
                'size' => strlen($markdown),
            ]);

            return [
                'success' => true,
                'filename' => $filename,
                'filepath' => $filepath,
            ];

        } catch (Exception $e) {
            Log::error('YouTubeJoplinCreate: Failed to write to fallback', [
                'video_id' => $video['video_id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a Pushover notification about fallback notes
     *
     * @param  int  $count  Number of notes saved to fallback
     * @param  array  $files  List of fallback filenames
     */
    protected function sendFallbackNotification(int $count, array $files): void
    {
        try {
            $controller = new NotificationController;

            $message = "⚠️ Joplin/Nextcloud unreachable\n\n";
            $message .= "{$count} YouTube notes saved to fallback folder:\n";
            $message .= "storage/app/joplin-fallback/\n\n";
            $message .= "Files:\n";
            foreach (array_slice($files, 0, 10) as $file) {
                $message .= '• '.substr($file, 0, 50)."\n";
            }
            if (count($files) > 10) {
                $message .= '• ... and '.(count($files) - 10)." more\n";
            }
            $message .= "\nImport manually when Nextcloud is back online.";

            $controller->send('pushover', [
                'source_group' => 'workflow_node_notifications',
                'title' => 'YouTube Joplin Fallback',
                'message' => $message,
                'priority' => 0,
                'sound' => 'intermission',
            ]);

            Log::info('YouTubeJoplinCreate: Fallback notification sent', [
                'count' => $count,
            ]);

        } catch (Exception $e) {
            Log::error('YouTubeJoplinCreate: Failed to send fallback notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
