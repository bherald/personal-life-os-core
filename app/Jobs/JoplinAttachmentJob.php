<?php

namespace App\Jobs;

use App\Services\JoplinAttachmentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * JoplinAttachmentJob
 *
 * Horizon queue job for processing Joplin attachments
 * Uses v2 extraction pipeline: pdftotext → Tesseract → Claude → (Ollama fallback)
 *
 * Queue: 'long-running' (isolated from regular background work)
 * Timeout: 30 minutes per attachment (OCR/PDF extraction can be expensive)
 * Retries: 1 to avoid repeated timeout storms on oversized/problematic files
 */
class JoplinAttachmentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Job timeout in seconds.
     * Match the Horizon long-running supervisor ceiling.
     */
    public int $timeout = 1800;

    /**
     * Number of retry attempts
     */
    public int $tries = 1;

    /**
     * Hold duplicate attachment admissions while the current copy is queued/running.
     */
    public int $uniqueFor = 3600;

    /**
     * Retry backoff in seconds
     */
    public array $backoff = [60, 180];

    /**
     * Attachment details
     */
    protected string $resourceId;

    protected string $filename;

    protected string $noteId;

    protected bool $force;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $resourceId,
        string $filename,
        string $noteId,
        bool $force = false
    ) {
        $this->resourceId = $resourceId;
        $this->filename = $filename;
        $this->noteId = $noteId;
        $this->force = $force;

        // Isolate attachment OCR/extraction from normal background work.
        $this->onQueue('long-running');
    }

    /**
     * Get middleware for the job.
     */
    public function middleware(): array
    {
        // Prevent duplicate processing of the same attachment admission.
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(60)
                ->expireAfter(300),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::channel('single')->info('JoplinAttachmentJob starting', [
            'resource_id' => $this->resourceId,
            'filename' => $this->filename,
            'note_id' => $this->noteId,
            'force' => $this->force,
        ]);

        try {
            $service = app(JoplinAttachmentService::class);

            // Process the attachment
            $result = $service->processAttachment(
                $this->resourceId,
                $this->filename,
                $this->noteId
            );

            if ($result['success'] && ! empty($result['markdown']) && ! ($result['skipped'] ?? false)) {
                // Append markdown to parent note in RAG
                $this->appendToParentNote($result['markdown']);

                Log::channel('single')->info('JoplinAttachmentJob completed', [
                    'resource_id' => $this->resourceId,
                    'method' => $result['method'],
                    'entities_count' => count($result['entities'] ?? []),
                ]);
            } elseif ($result['skipped'] ?? false) {
                Log::channel('single')->debug('JoplinAttachmentJob skipped (already processed)', [
                    'resource_id' => $this->resourceId,
                ]);
            } else {
                Log::channel('single')->warning('JoplinAttachmentJob produced no output', [
                    'resource_id' => $this->resourceId,
                    'error' => $result['error'] ?? 'unknown',
                ]);
            }

        } catch (\Exception $e) {
            Log::channel('single')->error('JoplinAttachmentJob failed', [
                'resource_id' => $this->resourceId,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Re-throw for retry
        }
    }

    /**
     * Append attachment markdown to parent note in RAG
     */
    protected function appendToParentNote(string $markdown): void
    {
        // Find parent note in RAG
        $sql = "SELECT id, content FROM rag_documents
                WHERE source_id = ? AND designation = 'joplin_note'
                LIMIT 1";

        $notes = DB::connection('pgsql_rag')->select($sql, [$this->noteId]);

        if (empty($notes)) {
            Log::channel('single')->warning('Parent note not found in RAG', [
                'note_id' => $this->noteId,
                'resource_id' => $this->resourceId,
            ]);

            return;
        }

        $note = $notes[0];
        $currentContent = $note->content;

        // Check if attachment already in content (avoid duplicates)
        if (str_contains($currentContent, "## {$this->filename}")) {
            // Replace existing attachment section
            $pattern = '/\n---\n\n## '.preg_quote($this->filename, '/').'.*?(?=\n---\n|$)/s';
            $newContent = preg_replace($pattern, $markdown, $currentContent);
        } else {
            // Append new attachment
            $newContent = $currentContent."\n".$markdown;
        }

        // Update RAG document
        $updateSql = 'UPDATE rag_documents
                      SET content = ?,
                          content_hash = ?,
                          updated_at = NOW()
                      WHERE id = ?';

        DB::connection('pgsql_rag')->update($updateSql, [
            $newContent,
            md5($newContent),
            $note->id,
        ]);

        Log::channel('single')->debug('Appended attachment to parent note', [
            'note_id' => $this->noteId,
            'rag_id' => $note->id,
            'new_content_length' => strlen($newContent),
        ]);
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::channel('single')->error('JoplinAttachmentJob permanently failed', [
            'resource_id' => $this->resourceId,
            'filename' => $this->filename,
            'note_id' => $this->noteId,
            'error' => $exception->getMessage(),
        ]);

        // Update status to error in index
        $sql = "UPDATE joplin_attachment_index
                SET sync_status = 'error',
                    error_log = ?,
                    updated_at = NOW()
                WHERE note_id = ? AND resource_id = ?";

        DB::statement($sql, [
            $exception->getMessage(),
            $this->noteId,
            $this->resourceId,
        ]);
    }

    /**
     * Get unique job ID for deduplication
     */
    public function uniqueId(): string
    {
        return "{$this->noteId}:{$this->resourceId}";
    }

    /**
     * Get job display name for Horizon
     */
    public function displayName(): string
    {
        return "JoplinAttachment:{$this->filename}";
    }

    /**
     * Get job tags for Horizon filtering
     */
    public function tags(): array
    {
        return [
            'joplin',
            'attachment',
            'note:'.$this->noteId,
            'resource:'.$this->resourceId,
        ];
    }
}
