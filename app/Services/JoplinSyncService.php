<?php

namespace App\Services;

use App\Jobs\JoplinAttachmentJob;
use App\Support\JoplinPaths;
use App\Support\PgVector;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Enhanced Joplin Sync Service with Attachment Processing (E17)
 *
 * Now uses centralized ContentExtractionService and MediaUrlService
 * This is an independent PLOS interoperability adapter for an operator-managed
 * sync target; it does not use upstream Joplin application or server source
 * code.
 *
 * Features:
 * - Sync manifest tracking (hash-based change detection)
 * - Attachment processing via ContentExtractionService
 * - Update existing notes, add new ones, remove deleted ones
 * - Derived data appended to note text
 * - Retry logic with exponential backoff
 * - Chunked processing for large batches
 * - Health check and monitoring support
 * - Media URL generation for source links
 */
class JoplinSyncService
{
    private RAGService $ragService;

    private ?AIService $aiService = null;

    private ?ContentExtractionService $extractionService = null;

    private ?MediaUrlService $mediaUrlService = null;

    private string $nextcloudUrl;

    private string $username;

    private string $password;

    private string $joplinPath = '/Joplin-data';

    private ?string $localPath = null;

    private array $processedFormats = [];

    private array $unprocessableFormats = [];

    private bool $enableAISummarization = true;

    private bool $enableOCR = true;

    private bool $enableVision = true;

    /** @var int Maximum retry attempts for HTTP calls — matches RetryService::DEFAULT_MAX_ATTEMPTS (SC-2.4) */
    private const MAX_RETRIES = 3;

    /** @var int Default chunk size for batch processing */
    private const DEFAULT_CHUNK_SIZE = 50;

    /** @var string Cache key for last sync timestamp */
    private const LAST_SYNC_CACHE_KEY = 'joplin_sync_last_run';

    /** @var string Cache key for sync health status */
    private const HEALTH_CACHE_KEY = 'joplin_sync_health';

    /** @var string Cache key prefix for failure tracking */
    private const FAILURE_CACHE_PREFIX = 'joplin_sync_failure:';

    /** @var int Maximum failures before quarantine */
    private const MAX_FAILURES_BEFORE_QUARANTINE = 3;

    /** @var int Quarantine duration in hours */
    private const QUARANTINE_HOURS = 72;

    private const HTTP_CONNECT_TIMEOUT = 5;

    private const HTTP_TIMEOUT = 120;

    public function __construct(
        RAGService $ragService,
        ?AIService $aiService = null,
        ?ContentExtractionService $extractionService = null,
        ?MediaUrlService $mediaUrlService = null
    ) {
        $this->ragService = $ragService;
        $this->aiService = $aiService;
        $this->extractionService = $extractionService;
        $this->mediaUrlService = $mediaUrlService;
        $this->nextcloudUrl = rtrim(config('services.nextcloud.url') ?? '', '/');
        $this->username = config('services.nextcloud.username') ?? '';
        $this->password = config('services.nextcloud.password') ?? '';
        $this->joplinPath = JoplinPaths::syncPath(false);

        // Filesystem-first: direct reads ~1000x faster than WebDAV
        $this->localPath = JoplinPaths::localRoot();
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT)
            ->timeout(self::HTTP_TIMEOUT)
            ->withBasicAuth($this->username, $this->password);
    }

    /**
     * Set AI summarization on/off
     */
    public function setAISummarization(bool $enabled): self
    {
        $this->enableAISummarization = $enabled;

        return $this;
    }

    /**
     * Set OCR processing on/off
     */
    public function setOCR(bool $enabled): self
    {
        $this->enableOCR = $enabled;

        return $this;
    }

    /**
     * Set Vision model processing on/off
     */
    public function setVision(bool $enabled): self
    {
        $this->enableVision = $enabled;

        return $this;
    }

    /**
     * Sync all notes with optional limit (main entry point for CLI command)
     *
     * This method wraps sync() with:
     * - Optional limit on number of notes to process
     * - Chunked processing for large batches
     * - Health status tracking
     * - Formatted statistics for CLI output
     *
     * @param  int|null  $limit  Maximum notes to process (null for all)
     * @param  int  $chunkSize  Notes per chunk for batch processing
     * @return array Sync statistics with CLI-friendly format
     */
    public function syncAll(?int $limit = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE): array
    {
        $startTime = Carbon::now();
        Log::info('Starting Joplin syncAll', ['limit' => $limit, 'chunk_size' => $chunkSize]);

        $this->updateHealthStatus('running');

        try {
            // Fetch all note metadata first
            $allNotes = $this->fetchAllNotes();
            $totalFiles = count($allNotes);

            // Get existing notes from RAG (raw SQL — no Eloquent)
            $existingDocs = DB::connection('pgsql_rag')->select(
                'SELECT id, source_id, title, updated_at, content_hash FROM rag_documents WHERE designation = ?',
                ['joplin_note']
            );

            $existing = [];
            foreach ($existingDocs as $doc) {
                $existing[$doc->source_id] = $doc;
            }

            $changedCount = null;

            // Prioritize actual work before applying bounded scheduled limits.
            if ($limit !== null && $limit > 0) {
                $changedNotes = [];
                $unchangedNotes = [];

                foreach ($allNotes as $noteData) {
                    $noteId = $noteData['id'] ?? null;
                    $existingNote = $noteId ? ($existing[$noteId] ?? null) : null;
                    $contentHash = md5($noteData['content'] ?? '');

                    if (! $existingNote || $existingNote->content_hash !== $contentHash) {
                        $changedNotes[] = $noteData;
                    } else {
                        $unchangedNotes[] = $noteData;
                    }
                }

                $changedCount = count($changedNotes);
                $allNotes = array_slice(array_merge($changedNotes, $unchangedNotes), 0, $limit);
            }

            $notesToProcess = count($allNotes);
            Log::info('Joplin syncAll: Notes to process', [
                'total_files' => $totalFiles,
                'processing' => $notesToProcess,
                'limit_applied' => $limit !== null,
            ]);

            $stats = [
                'notes_indexed' => 0,
                'notes_updated' => 0,
                'notes_deleted' => 0,
                'notes_skipped' => 0,
                'attachments_processed' => 0,
                'errors' => 0,
            ];

            if (($changedCount ?? $notesToProcess) > 0 && ! $this->hasFastEmbeddingProvider()) {
                $endTime = Carbon::now();
                $durationSeconds = $startTime->diffInSeconds($endTime);

                Log::warning('Joplin syncAll deferred: no fast embedding provider available', [
                    'total_files' => $totalFiles,
                    'notes_to_process' => $notesToProcess,
                    'changed_notes' => $changedCount,
                ]);

                $this->updateHealthStatus('healthy', [
                    'last_sync' => $endTime->toIso8601String(),
                    'deferred' => true,
                    'reason' => 'no_fast_embedding_provider',
                    'notes_to_process' => $notesToProcess,
                    'changed_notes' => $changedCount,
                ]);

                Cache::put(self::LAST_SYNC_CACHE_KEY, $endTime->toIso8601String(), now()->addDays(7));

                return array_merge($stats, [
                    'deferred' => true,
                    'defer_reason' => 'no_fast_embedding_provider',
                    'total_files' => $totalFiles,
                    'duration_seconds' => $durationSeconds,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ]);
            }

            // Process in chunks to prevent memory issues
            $chunks = array_chunk($allNotes, $chunkSize);
            $processedNoteIds = [];

            foreach ($chunks as $chunkIndex => $chunk) {
                Log::debug('Processing chunk', [
                    'chunk' => $chunkIndex + 1,
                    'total_chunks' => count($chunks),
                    'notes_in_chunk' => count($chunk),
                ]);

                foreach ($chunk as $noteData) {
                    try {
                        $noteId = $noteData['id'];
                        $processedNoteIds[] = $noteId;
                        $existingNote = $existing[$noteId] ?? null;

                        // Skip notes without valid ID
                        if (empty($noteId)) {
                            $stats['notes_skipped']++;

                            continue;
                        }

                        // Skip notes with empty content (can't generate embeddings)
                        if (empty(trim($noteData['content'] ?? ''))) {
                            Log::debug('Joplin syncAll: Skipping note with empty content', [
                                'note_id' => $noteId,
                                'title' => $noteData['title'] ?? 'unknown',
                            ]);
                            $stats['notes_skipped']++;

                            continue;
                        }

                        // Skip quarantined notes (repeated failures)
                        if ($this->isQuarantined($noteId)) {
                            Log::debug('Joplin syncAll: Skipping quarantined note', [
                                'note_id' => $noteId,
                                'title' => $noteData['title'] ?? 'unknown',
                            ]);
                            $stats['notes_skipped']++;

                            continue;
                        }

                        $contentHash = md5($noteData['content']);

                        if (! $existingNote) {
                            $this->indexNote($noteData, $contentHash);
                            $stats['notes_indexed']++;
                            $this->clearQuarantine($noteId); // Success, clear any failure history
                        } elseif ($existingNote->content_hash !== $contentHash) {
                            $this->updateNote($existingNote, $noteData, $contentHash);
                            $stats['notes_updated']++;
                            $this->clearQuarantine($noteId);
                        } else {
                            $stats['notes_skipped']++;
                        }

                        // Attachments are queued by indexNote() for new/changed notes.
                        // Do not run the legacy inline attachment processor for unchanged
                        // notes; it re-enters RAG indexing and can stall routine syncs.
                    } catch (\Exception $e) {
                        Log::error('Error processing note in syncAll', [
                            'note_id' => $noteData['id'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]);
                        $stats['errors']++;
                        // Track failure for quarantine
                        $this->recordSyncFailure(
                            $noteData['id'] ?? 'unknown',
                            $noteData['title'] ?? 'unknown',
                            $e->getMessage()
                        );
                    }
                }

                // Allow garbage collection between chunks
                gc_collect_cycles();
            }

            // Delete notes that no longer exist (only if processing all notes)
            if ($limit === null) {
                // Filter out any null keys from $existing to prevent issues
                $existingKeys = array_filter(array_keys($existing), fn ($key) => ! empty($key));
                $toDelete = array_diff($existingKeys, $processedNoteIds);

                foreach ($toDelete as $noteId) {
                    $existingDoc = $existing[$noteId] ?? null;
                    if ($existingDoc) {
                        try {
                            $sql = 'DELETE FROM rag_documents WHERE designation = ? AND source_id = ?';
                            DB::connection('pgsql_rag')->delete($sql, ['joplin_note', $noteId]);

                            $sql = 'DELETE FROM rag_documents WHERE designation = ? AND parent_id = ?';
                            DB::connection('pgsql_rag')->delete($sql, ['joplin_attachment', $existingDoc->id]);

                            $stats['notes_deleted']++;

                            Log::info('Joplin syncAll: Deleted note no longer in Joplin', [
                                'source_id' => $noteId,
                                'rag_id' => $existingDoc->id,
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Joplin syncAll: Failed to delete removed note', [
                                'source_id' => $noteId,
                                'error' => $e->getMessage(),
                            ]);
                            $stats['errors']++;
                        }
                    }
                }

                // Cleanup any orphaned documents with null source_id
                $this->cleanupOrphanedDocuments();
            }

            $endTime = Carbon::now();
            $durationSeconds = $startTime->diffInSeconds($endTime);

            // Update health status
            $this->updateHealthStatus('healthy', [
                'last_sync' => $endTime->toIso8601String(),
                'notes_processed' => $stats['notes_indexed'] + $stats['notes_updated'],
                'errors' => $stats['errors'],
            ]);

            // Cache last sync time
            Cache::put(self::LAST_SYNC_CACHE_KEY, $endTime->toIso8601String(), now()->addDays(7));

            Log::info('Joplin syncAll completed', array_merge($stats, [
                'duration_seconds' => $durationSeconds,
                'total_files' => $totalFiles,
            ]));

            return [
                'total_files' => $totalFiles,
                'notes_indexed' => $stats['notes_indexed'],
                'notes_skipped' => $stats['notes_skipped'] + ($totalFiles - $notesToProcess),
                'errors' => $stats['errors'],
                'duration_seconds' => $durationSeconds,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'processed_formats' => $this->processedFormats,
                'unprocessable_formats' => array_unique($this->unprocessableFormats),
            ];

        } catch (\Exception $e) {
            $this->updateHealthStatus('error', [
                'error' => $e->getMessage(),
                'timestamp' => Carbon::now()->toIso8601String(),
            ]);

            Log::error('Joplin syncAll failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Perform full sync: add new, update changed, delete removed
     */
    public function sync(): array
    {
        $startTime = microtime(true);
        Log::info('Starting Joplin sync');

        try {
            // Get all notes from Nextcloud
            $notes = $this->fetchAllNotes();

            // Get existing notes from RAG (raw SQL — no Eloquent)
            $existingDocs = DB::connection('pgsql_rag')->select(
                'SELECT id, source_id, title, updated_at, content_hash FROM rag_documents WHERE designation = ?',
                ['joplin_note']
            );

            // Key by source_id
            $existing = [];
            foreach ($existingDocs as $doc) {
                $existing[$doc->source_id] = $doc;
            }

            $stats = [
                'added' => 0,
                'updated' => 0,
                'deleted' => 0,
                'attachments_processed' => 0,
                'errors' => 0,
            ];

            // Process each note
            $processedNoteIds = [];
            foreach ($notes as $noteData) {
                try {
                    $noteId = $noteData['id'];

                    // Skip notes without valid ID (prevents null key issues)
                    if (empty($noteId)) {
                        Log::warning('Joplin sync: Skipping note without valid ID', [
                            'title' => $noteData['title'] ?? 'unknown',
                        ]);

                        continue;
                    }

                    // Skip notes with empty content (can't generate embeddings)
                    if (empty(trim($noteData['content'] ?? ''))) {
                        Log::debug('Joplin sync: Skipping note with empty content', [
                            'note_id' => $noteId,
                            'title' => $noteData['title'] ?? 'unknown',
                        ]);

                        continue;
                    }

                    // Skip quarantined notes (repeated failures)
                    if ($this->isQuarantined($noteId)) {
                        Log::debug('Joplin sync: Skipping quarantined note', [
                            'note_id' => $noteId,
                            'title' => $noteData['title'] ?? 'unknown',
                        ]);

                        continue;
                    }

                    $processedNoteIds[] = $noteId;
                    $existingNote = $existing[$noteId] ?? null;

                    // Calculate content hash
                    $contentHash = md5($noteData['content']);

                    if (! $existingNote) {
                        // Add new note
                        $this->indexNote($noteData, $contentHash);
                        $stats['added']++;
                        $this->clearQuarantine($noteId);
                    } elseif ($existingNote->content_hash !== $contentHash) {
                        // Update changed note
                        $this->updateNote($existingNote, $noteData, $contentHash);
                        $stats['updated']++;
                        $this->clearQuarantine($noteId);
                    }

                    // Attachments are queued by indexNote() for new/changed notes.
                    // Do not run the legacy inline attachment processor for unchanged
                    // notes; it re-enters RAG indexing and can stall routine syncs.
                } catch (\Exception $e) {
                    Log::error('Error processing note', [
                        'note_id' => $noteData['id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                    $stats['errors']++;
                    // Track failure for quarantine
                    $this->recordSyncFailure(
                        $noteData['id'] ?? 'unknown',
                        $noteData['title'] ?? 'unknown',
                        $e->getMessage()
                    );
                }
            }

            // Delete notes that no longer exist in Joplin
            // Filter out any null keys from $existing to prevent issues
            $existingKeys = array_filter(array_keys($existing), fn ($key) => ! empty($key));
            $toDelete = array_diff($existingKeys, $processedNoteIds);

            foreach ($toDelete as $noteId) {
                $existingDoc = $existing[$noteId] ?? null;
                if ($existingDoc) {
                    try {
                        // Delete note using raw SQL
                        $sql = 'DELETE FROM rag_documents WHERE designation = ? AND source_id = ?';
                        DB::connection('pgsql_rag')->delete($sql, ['joplin_note', $noteId]);

                        // Also delete associated attachments using raw SQL
                        $sql = 'DELETE FROM rag_documents WHERE designation = ? AND parent_id = ?';
                        DB::connection('pgsql_rag')->delete($sql, ['joplin_attachment', $existingDoc->id]);

                        $stats['deleted']++;

                        Log::info('Joplin sync: Deleted note no longer in Joplin', [
                            'source_id' => $noteId,
                            'rag_id' => $existingDoc->id,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Joplin sync: Failed to delete removed note', [
                            'source_id' => $noteId,
                            'error' => $e->getMessage(),
                        ]);
                        $stats['errors']++;
                    }
                }
            }

            // Cleanup any orphaned documents with null source_id
            $this->cleanupOrphanedDocuments();

            $duration = round((microtime(true) - $startTime) * 1000);

            Log::info('Joplin sync completed', array_merge($stats, [
                'duration_ms' => $duration,
                'total_notes' => count($notes),
                'processed_formats' => $this->processedFormats,
                'unprocessable_formats' => array_unique($this->unprocessableFormats),
            ]));

            return array_merge($stats, [
                'duration_ms' => $duration,
                'total_notes' => count($notes),
                'processed_formats' => $this->processedFormats,
                'unprocessable_formats' => array_unique($this->unprocessableFormats),
            ]);
        } catch (\Exception $e) {
            Log::error('Joplin sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Fetch all notes from Nextcloud with retry logic
     *
     * @throws \Exception if all retries fail
     */
    private function fetchAllNotes(): array
    {
        $notes = [];

        // Filesystem-first: scan directory directly
        if ($this->localPath) {
            $files = scandir($this->localPath);
            foreach ($files as $file) {
                if (! preg_match('/^[a-f0-9]{32}\.md$/', $file)) {
                    continue;
                }

                $content = file_get_contents($this->localPath.'/'.$file);
                if (empty($content)) {
                    continue;
                }

                $parsed = $this->parseNoteContent($content);

                if ($parsed['type'] === 1) {
                    if (empty(trim($parsed['content'] ?? ''))) {
                        Log::debug('Joplin sync: Skipping note with empty body', [
                            'id' => $parsed['id'],
                            'title' => $parsed['title'],
                        ]);

                        continue;
                    }

                    $notes[] = [
                        'id' => $parsed['id'],
                        'title' => $parsed['title'],
                        'content' => $parsed['content'],
                        'metadata' => $parsed['metadata'],
                        'attachments' => $parsed['attachments'],
                    ];
                }
            }

            Log::info('Joplin sync: fetched notes via filesystem', ['count' => count($notes)]);

            return $notes;
        }

        // WebDAV fallback
        $url = $this->nextcloudUrl.'/remote.php/dav/files/'.$this->username.$this->joplinPath;

        // Retry logic for directory listing
        $response = $this->httpWithRetry(function () use ($url) {
            return $this->http()
                ->timeout(30)
                ->withHeaders(['Depth' => '1'])
                ->send('PROPFIND', $url);
        }, 'fetchAllNotes PROPFIND');

        if (! $response->successful()) {
            throw new \Exception('Failed to fetch Joplin data: HTTP '.$response->status());
        }

        // Parse WebDAV response to get .md files
        $xml = simplexml_load_string($response->body());
        if ($xml === false) {
            throw new \Exception('Failed to parse WebDAV response XML');
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $files = $xml->xpath('//d:response');

        foreach ($files as $file) {
            $href = (string) $file->xpath('d:href')[0];
            if (str_ends_with($href, '.md')) {
                $content = $this->fetchNoteContent($href);

                // Skip empty content (failed fetches already logged)
                if (empty($content)) {
                    continue;
                }

                $parsed = $this->parseNoteContent($content);

                // Only index notes (type_ = 1), skip folders
                if ($parsed['type'] === 1) {
                    // Skip notes with empty body content (can't generate embeddings)
                    if (empty(trim($parsed['content'] ?? ''))) {
                        Log::debug('Joplin sync: Skipping note with empty body', [
                            'id' => $parsed['id'],
                            'title' => $parsed['title'],
                        ]);

                        continue;
                    }

                    $notes[] = [
                        'id' => $parsed['id'],
                        'title' => $parsed['title'],
                        'content' => $parsed['content'],
                        'metadata' => $parsed['metadata'],
                        'attachments' => $parsed['attachments'],
                    ];
                }
            }
        }

        return $notes;
    }

    /**
     * Execute HTTP request with retry and exponential backoff
     *
     * @param  callable  $httpCall  Function that returns HTTP response
     * @param  string  $operation  Description for logging
     * @param  int  $maxRetries  Maximum retry attempts
     * @return \Illuminate\Http\Client\Response
     *
     * @throws \Exception if all retries fail
     */
    private function httpWithRetry(callable $httpCall, string $operation, int $maxRetries = self::MAX_RETRIES)
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $httpCall();

                // Check for server errors that warrant retry
                if ($response->serverError()) {
                    throw new \Exception('Server error: HTTP '.$response->status());
                }

                // Success or client error (don't retry client errors)
                return $response;

            } catch (\Exception $e) {
                $lastException = $e;

                // Check if it's a connection error worth retrying
                if ($this->isRetryableError($e->getMessage())) {
                    $delay = pow(2, $attempt - 1); // 1, 2, 4 seconds

                    Log::warning('Joplin HTTP retry', [
                        'operation' => $operation,
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'delay_seconds' => $delay,
                        'error' => $e->getMessage(),
                    ]);

                    if ($attempt < $maxRetries) {
                        sleep($delay);

                        continue;
                    }
                }

                // Non-retryable error or max retries reached
                break;
            }
        }

        Log::error("Joplin HTTP request failed after {$maxRetries} attempts", [
            'operation' => $operation,
            'error' => $lastException?->getMessage(),
        ]);

        throw $lastException ?? new \Exception("HTTP request failed: {$operation}");
    }

    /**
     * Check if an error message indicates a retryable condition
     */
    private function isRetryableError(string $message): bool
    {
        $retryablePatterns = [
            'cURL error 7',       // Connection refused
            'cURL error 28',      // Timeout
            'cURL error 35',      // SSL connect error
            'cURL error 52',      // Empty reply
            'cURL error 56',      // Recv failure
            'Connection refused',
            'Connection reset',
            'Connection timed out',
            'Could not resolve host',
            'Network is unreachable',
            'Server error',
            'HTTP 500',
            'HTTP 502',
            'HTTP 503',
            'HTTP 504',
        ];

        foreach ($retryablePatterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetch note content from Nextcloud with retry logic
     */
    private function fetchNoteContent(string $href): string
    {
        try {
            $response = $this->httpWithRetry(function () use ($href) {
                return $this->http()
                    ->timeout(15)
                    ->get($this->nextcloudUrl.$href);
            }, 'fetchNoteContent');

            return $response->successful() ? $response->body() : '';
        } catch (\Exception $e) {
            Log::warning('Failed to fetch note content', [
                'href' => $href,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Parse note content
     */
    private function parseNoteContent(string $content): array
    {
        $lines = explode("\n", $content);

        // Find metadata section (lines with "key: value" format at end)
        $metadataStart = null;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match('/^[a-z_]+:\s*.+$/i', $lines[$i])) {
                $metadataStart = $i;
            } elseif (trim($lines[$i]) !== '' && trim($lines[$i]) !== '---') {
                break;
            }
        }

        // Extract metadata
        $metadata = [];
        if ($metadataStart !== null) {
            for ($i = $metadataStart; $i < count($lines); $i++) {
                if (preg_match('/^([a-z_]+):\s*(.*)$/i', $lines[$i], $matches)) {
                    $metadata[$matches[1]] = $matches[2];
                }
            }
        }

        // Extract title and content
        $title = trim($lines[0]);
        $contentEnd = $metadataStart ?? count($lines);
        $contentLines = array_slice($lines, 1, $contentEnd - 1);
        $noteContent = trim(implode("\n", $contentLines));
        $noteContent = preg_replace('/\n---\n*$/s', '', $noteContent);

        $attachments = [];
        $seenResourceIds = [];

        // Deduplicate by resource ID so repeated references in a note cannot flood the queue.
        preg_match_all('/\[([^\]]+)\]\(:\/([a-f0-9]{32})\)/i', $noteContent, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $resourceId = $match[2];
            if (isset($seenResourceIds[$resourceId])) {
                continue;
            }

            $attachments[] = [
                'filename' => $match[1],
                'resource_id' => $resourceId,
            ];
            $seenResourceIds[$resourceId] = true;
        }

        preg_match_all('/<img[^>]+src=":\/([a-f0-9]{32})"[^>]*alt="([^"]+)"[^>]*>/i', $noteContent, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $resourceId = $match[1];
            if (isset($seenResourceIds[$resourceId])) {
                continue;
            }

            $attachments[] = [
                'filename' => $match[2],
                'resource_id' => $resourceId,
            ];
            $seenResourceIds[$resourceId] = true;
        }

        preg_match_all('/<img[^>]+alt="([^"]+)"[^>]+src=":\/([a-f0-9]{32})"[^>]*>/i', $noteContent, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $resourceId = $match[2];
            if (isset($seenResourceIds[$resourceId])) {
                continue;
            }

            $attachments[] = [
                'filename' => $match[1],
                'resource_id' => $resourceId,
            ];
            $seenResourceIds[$resourceId] = true;
        }

        return [
            'title' => $title,
            'content' => $noteContent,
            'id' => $metadata['id'] ?? null,
            'type' => isset($metadata['type_']) ? (int) $metadata['type_'] : 0,
            'metadata' => $metadata,
            'attachments' => $attachments,
        ];
    }

    /**
     * Index a new note to RAG
     *
     * Note: Attachments are now queued for async processing via Horizon
     * instead of being processed inline. This keeps syncs fast.
     * Use `php artisan joplin:attachments --action=reprocess` to process attachments.
     */
    private function indexNote(array $noteData, string $contentHash): void
    {
        $content = trim($noteData['content'] ?? '');

        // Skip notes with empty content - can't generate embeddings
        if (empty($content)) {
            Log::warning('Skipping empty Joplin note', [
                'note_id' => $noteData['id'],
                'title' => $noteData['title'],
            ]);

            return;
        }

        // Queue attachments for async processing (v2 pipeline via Horizon)
        // Attachment content will be appended to note after processing
        if (isset($noteData['attachments']) && count($noteData['attachments']) > 0) {
            $this->queueAttachmentsForProcessing($noteData['attachments'], $noteData['id']);
        }

        $doc = $this->ragService->indexDocument(
            documentType: 'joplin_note',
            content: $content,
            title: $noteData['title'],
            metadata: $noteData['metadata'],
            sourceId: $noteData['id'],
            sourceType: 'joplin',
            options: [
                // Joplin already gates writes by stable content_hash; semantic
                // dedup can turn routine syncs into long pgvector/embedding waits.
                'skip_dedup' => true,
                'trace_timing' => true,
                // Scheduled note sync should defer if fast embedding providers are
                // down instead of falling into slow CPU/Python embedding fallback.
                'allow_cpu_fallback' => false,
            ],
        );

        // Update additional fields using raw SQL (no Eloquent)
        $sql = 'UPDATE rag_documents SET designation = ?, content_hash = ?, last_synced_at = NOW() WHERE id = ?';
        DB::connection('pgsql_rag')->update($sql, ['joplin_note', $contentHash, $doc->id]);
    }

    private function hasFastEmbeddingProvider(): bool
    {
        $aiService = $this->aiService ?? app(AIService::class);

        return $aiService->hasNonCpuEmbeddingProvider();
    }

    /**
     * Update an existing note in RAG
     */
    private function updateNote(object $existingNote, array $noteData, string $contentHash): void
    {
        // Delete old version using raw SQL
        $sql = 'DELETE FROM rag_documents WHERE id = ?';
        DB::connection('pgsql_rag')->delete($sql, [$existingNote->id]);

        // Re-index with new content
        $this->indexNote($noteData, $contentHash);
    }

    /**
     * Queue attachments for async processing via Horizon
     *
     * This replaces the old inline extractAttachmentData() approach.
     * Attachments are now processed by JoplinAttachmentJob with v2 pipeline.
     */
    private function queueAttachmentsForProcessing(array $attachments, string $noteId): void
    {
        foreach ($attachments as $attachment) {
            $filename = $attachment['filename'];
            $resourceId = $attachment['resource_id'];

            // Skip malformed filenames (e.g., HTML img tags)
            if (str_contains($filename, '<') || str_contains($filename, '>') || strlen($filename) > 255) {
                Log::warning('Skipping malformed attachment filename', [
                    'note_id' => $noteId,
                    'resource_id' => $resourceId,
                    'filename_preview' => substr($filename, 0, 100),
                ]);

                continue;
            }

            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            // Truncate extension to fit column (max 20 chars)
            $extension = substr($extension, 0, 20);

            try {
                // Insert/update in joplin_attachment_index
                $sql = "INSERT INTO joplin_attachment_index
                        (note_id, resource_id, filename, extension, sync_status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, 'queued', NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                            filename = VALUES(filename),
                            sync_status = 'queued',
                            updated_at = NOW()";

                DB::statement($sql, [$noteId, $resourceId, $filename, $extension]);

                // Dispatch job to Horizon queue
                JoplinAttachmentJob::dispatch($resourceId, $filename, $noteId);

                Log::debug('Queued attachment for processing', [
                    'note_id' => $noteId,
                    'resource_id' => $resourceId,
                    'filename' => $filename,
                ]);

            } catch (\Exception $e) {
                Log::warning('Failed to queue attachment', [
                    'note_id' => $noteId,
                    'filename' => $filename,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Extract data from all attachments
     */
    private function extractAttachmentData(array $attachments): string
    {
        $extracted = [];

        foreach ($attachments as $attachment) {
            try {
                $data = $this->processAttachment($attachment);
                if (! empty($data)) {
                    $extracted[] = "## {$attachment['filename']}\n{$data}";
                }
            } catch (\Exception $e) {
                Log::warning('Failed to process attachment', [
                    'filename' => $attachment['filename'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return implode("\n\n", $extracted);
    }

    /**
     * Process a single attachment using ContentExtractionService (E17)
     */
    private function processAttachment(array $attachment): ?string
    {
        $filename = $attachment['filename'];
        $resourceId = $attachment['resource_id'];

        // Fetch attachment content
        $resourcePath = "/.resource/{$resourceId}";
        $content = $this->fetchAttachmentContent($resourcePath);

        if (empty($content)) {
            return null;
        }

        // Use ContentExtractionService if available
        if ($this->extractionService) {
            // Save to temp file for extraction
            $tempFile = storage_path('app/temp/joplin_'.uniqid().'_'.basename($filename));
            @mkdir(dirname($tempFile), 0755, true);
            file_put_contents($tempFile, $content);

            try {
                $result = $this->extractionService->extract($tempFile, [
                    'use_vision' => $this->enableVision,
                    'use_ocr' => $this->enableOCR,
                ]);

                if ($result['success'] && ! empty($result['text'])) {
                    $this->processedFormats[$result['method']] = ($this->processedFormats[$result['method']] ?? 0) + 1;

                    // Add media link if MediaUrlService available
                    $mediaUrl = $this->mediaUrlService
                        ? $this->mediaUrlService->getJoplinAttachmentUrl($resourceId, $filename)
                        : null;

                    $output = "## {$filename}\n";
                    if ($mediaUrl) {
                        $output .= "[View Original]({$mediaUrl})\n\n";
                    }
                    $output .= $result['text'];

                    return $output;
                }
            } finally {
                @unlink($tempFile);
            }
        }

        // Fallback: use legacy processing
        return $this->processAttachmentLegacy($attachment, $content);
    }

    /**
     * Legacy attachment processing (fallback)
     */
    private function processAttachmentLegacy(array $attachment, string $content): ?string
    {
        $filename = $attachment['filename'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Process based on file type
        switch ($extension) {
            case 'pdf':
                return $this->extractPdfText($content, $filename);

            case 'png':
            case 'jpg':
            case 'jpeg':
            case 'gif':
            case 'bmp':
            case 'tiff':
            case 'webp':
                $ocrResult = $this->extractImageText($content, $filename);
                if ($ocrResult) {
                    return $ocrResult;
                }
                $this->unprocessableFormats[] = 'image_no_ocr';

                return "Image file: $filename (size: ".strlen($content).' bytes) - No text extracted';

            case 'txt':
            case 'md':
                $this->processedFormats['text'] = ($this->processedFormats['text'] ?? 0) + 1;
                if ($this->enableAISummarization && $this->aiService && strlen($content) > 1000) {
                    $summarized = $this->summarizeContent($content, $filename, 'text file');
                    if ($summarized) {
                        return $summarized;
                    }
                }

                return "Text from {$filename}:\n".$this->cleanExtractedText($content);

            case 'html':
            case 'htm':
                $textContent = strip_tags($content);
                $this->processedFormats['html'] = ($this->processedFormats['html'] ?? 0) + 1;
                if ($this->enableAISummarization && $this->aiService && strlen($textContent) > 500) {
                    $summarized = $this->summarizeContent($textContent, $filename, 'HTML document');
                    if ($summarized) {
                        return $summarized;
                    }
                }

                return "HTML Content from {$filename}:\n".$this->cleanExtractedText($textContent);

            default:
                $this->unprocessableFormats[] = $extension;

                return null;
        }
    }

    /**
     * Fetch attachment content from Nextcloud with retry logic
     */
    private function fetchAttachmentContent(string $resourcePath): ?string
    {
        $url = $this->nextcloudUrl.'/remote.php/dav/files/'.$this->username.$this->joplinPath.$resourcePath;

        try {
            $response = $this->httpWithRetry(function () use ($url) {
                return $this->http()
                    ->timeout(30)
                    ->get($url);
            }, 'fetchAttachmentContent');

            return $response->successful() ? $response->body() : null;
        } catch (\Exception $e) {
            Log::warning('Failed to fetch attachment content', [
                'path' => $resourcePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract text from PDF and optionally summarize with AI
     */
    private function extractPdfText(string $pdfContent, string $filename): ?string
    {
        try {
            $parser = new PdfParser;
            $pdf = $parser->parseContent($pdfContent);
            $rawText = $pdf->getText();

            if (empty(trim($rawText))) {
                return null;
            }

            $this->processedFormats['pdf'] = ($this->processedFormats['pdf'] ?? 0) + 1;

            // If AI summarization is enabled and text is substantial, summarize it
            if ($this->enableAISummarization && $this->aiService && strlen($rawText) > 500) {
                $summarized = $this->summarizeContent($rawText, $filename, 'PDF document');
                if ($summarized) {
                    return $summarized;
                }
            }

            // Fallback to raw text (cleaned up)
            return "PDF Content from {$filename}:\n".$this->cleanExtractedText($rawText);
        } catch (\Exception $e) {
            Log::warning('PDF extraction failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            $this->unprocessableFormats[] = 'pdf_failed';

            return null;
        }
    }

    /**
     * Extract text/description from image using AI Vision model (preferred) or OCR (fallback)
     *
     * Priority:
     * 1. AI Vision model (llava/Claude) - understands image content semantically
     * 2. OCR (tesseract) - extracts visible text only
     */
    private function extractImageText(string $imageContent, string $filename): ?string
    {
        // Try AI Vision first (if enabled and available)
        if ($this->enableVision && $this->aiService) {
            try {
                $visionResult = $this->extractWithVision($imageContent, $filename);
                if ($visionResult) {
                    $this->processedFormats['vision'] = ($this->processedFormats['vision'] ?? 0) + 1;

                    return $visionResult;
                }
            } catch (\Exception $e) {
                Log::warning('Vision extraction failed, trying OCR fallback', [
                    'filename' => $filename,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fall back to OCR if vision unavailable or failed
        if (! $this->enableOCR) {
            return null;
        }

        return $this->extractWithOCR($imageContent, $filename);
    }

    /**
     * Extract image content using AI Vision model
     */
    private function extractWithVision(string $imageContent, string $filename): ?string
    {
        if (! $this->aiService) {
            return null;
        }

        // Check if vision is available
        if (! $this->aiService->isVisionAvailable()) {
            Log::debug('Vision model not available');

            return null;
        }

        $prompt = <<<PROMPT
Analyze this image and provide a comprehensive description that will be useful for search and retrieval.

Include:
1. **Type of content**: What is this image? (document, photo, diagram, screenshot, handwriting, etc.)
2. **Key information**: Extract any visible text, numbers, dates, names, or other data
3. **Visual elements**: Describe important visual elements, layouts, or diagrams
4. **Context**: What is the purpose or topic of this image?

Format your response as structured text that can be searched. Be accurate and factual.
If there is text in the image, include it verbatim where possible.
Keep the response concise but comprehensive (under 500 words).

Image filename: {$filename}
PROMPT;

        try {
            $result = $this->aiService->processImage($imageContent, $prompt, [
                'factual_mode' => true,
                'max_tokens' => 1500,
                'timeout' => 120, // Vision processing can be slow
            ]);

            if (! $result['success'] || empty(trim($result['response'] ?? ''))) {
                if (! $result['success']) {
                    Log::warning('Vision API call failed', [
                        'filename' => $filename,
                        'error' => $result['error'] ?? 'Unknown error',
                    ]);
                    throw new \Exception($result['error'] ?? 'Vision processing failed');
                }

                return null;
            }

            Log::info('Vision extraction successful', [
                'filename' => $filename,
                'response_length' => strlen($result['response'] ?? ''),
                'provider' => $result['provider'] ?? 'unknown',
            ]);

            return "Image Analysis ({$filename}):\n\n".trim($result['response']);

        } catch (\Exception $e) {
            Log::warning('Vision API call failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Re-throw to trigger OCR fallback
        }
    }

    /**
     * Extract text from image using OCR (tesseract) - fallback method
     */
    private function extractWithOCR(string $imageContent, string $filename): ?string
    {
        // Check if tesseract is available
        $tesseractPath = trim(Process::timeout(5)->run(['which', 'tesseract'])->output());
        if (empty($tesseractPath) || ! file_exists($tesseractPath) || ! is_executable($tesseractPath)) {
            $this->unprocessableFormats[] = 'image_no_tesseract';

            return null;
        }

        try {
            // Create temp file for image
            $tempImage = tempnam(sys_get_temp_dir(), 'joplin_ocr_');
            file_put_contents($tempImage, $imageContent);

            // Run tesseract OCR
            $tempOutput = tempnam(sys_get_temp_dir(), 'joplin_ocr_out_');
            $returnCode = Process::timeout(60)->run([
                $tesseractPath,
                $tempImage,
                $tempOutput,
            ])->exitCode();

            // Clean up temp image
            @unlink($tempImage);

            if ($returnCode !== 0) {
                @unlink($tempOutput.'.txt');
                $this->unprocessableFormats[] = 'ocr_failed';

                return null;
            }

            // Read OCR output
            $ocrText = @file_get_contents($tempOutput.'.txt');
            @unlink($tempOutput.'.txt');

            if (empty(trim($ocrText))) {
                $this->unprocessableFormats[] = 'ocr_empty';

                return null;
            }

            $this->processedFormats['ocr'] = ($this->processedFormats['ocr'] ?? 0) + 1;

            // Optionally summarize OCR text
            if ($this->enableAISummarization && $this->aiService && strlen($ocrText) > 200) {
                $summarized = $this->summarizeContent($ocrText, $filename, 'OCR extracted text from image');
                if ($summarized) {
                    return $summarized;
                }
            }

            return "OCR Text from {$filename}:\n".$this->cleanExtractedText($ocrText);
        } catch (\Exception $e) {
            Log::warning('OCR extraction failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            $this->unprocessableFormats[] = 'ocr_error';

            return null;
        }
    }

    /**
     * Summarize extracted content using AI for accuracy and conciseness
     */
    private function summarizeContent(string $content, string $filename, string $contentType): ?string
    {
        if (! $this->aiService) {
            return null;
        }

        try {
            // Truncate very long content to avoid token limits
            $maxChars = 8000;
            if (strlen($content) > $maxChars) {
                $content = substr($content, 0, $maxChars)."\n\n[Content truncated for summarization]";
            }

            $prompt = <<<PROMPT
You are processing extracted text from a {$contentType} file named "{$filename}".

The raw extracted text may contain OCR errors, formatting artifacts, or be disorganized.

Your task:
1. Clean up any obvious OCR or extraction errors
2. Organize the information logically
3. Summarize key facts, data points, and important information
4. Remove redundant or meaningless content
5. Preserve all factual information (names, dates, numbers, addresses, etc.)

Output a clean, accurate, concise summary that captures all important information.
If this appears to be a form, receipt, or document with structured data, preserve that structure.

Raw extracted text:
---
{$content}
---

Provide only the cleaned/summarized content, no explanations:
PROMPT;

            $result = $this->aiService->process($prompt, [
                'factual_mode' => true,
                'max_tokens' => 2000,
                'system_prompt' => 'You are a document processing assistant. Output clean, factual, organized content.',
            ]);

            if ($result['success'] && ! empty(trim($result['response'] ?? ''))) {
                $this->processedFormats['ai_summarized'] = ($this->processedFormats['ai_summarized'] ?? 0) + 1;

                return "Summarized {$contentType} ({$filename}):\n\n".trim($result['response']);
            }
        } catch (\Exception $e) {
            Log::warning('AI summarization failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Clean up raw extracted text
     */
    private function cleanExtractedText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        // Remove excessive newlines (more than 2)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        // Trim each line
        $lines = array_map('trim', explode("\n", $text));
        // Remove empty lines at start/end
        while (! empty($lines) && empty($lines[0])) {
            array_shift($lines);
        }
        while (! empty($lines) && empty($lines[count($lines) - 1])) {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    /**
     * Process attachments separately (for parent_id linking)
     */
    private function processAttachments(array $attachments, string $parentNoteId): array
    {
        $stats = ['processed' => 0, 'failed' => 0];

        foreach ($attachments as $attachment) {
            try {
                $data = $this->processAttachment($attachment);
                if (! empty($data)) {
                    $this->indexAttachment($attachment, $parentNoteId, $data);
                    $stats['processed']++;
                }
            } catch (\Exception $e) {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Index attachment as separate RAG document
     */
    private function indexAttachment(array $attachment, string $parentNoteId, string $content): void
    {
        // Find parent RAG document using raw SQL
        $sql = 'SELECT * FROM rag_documents WHERE source_id = ? AND designation = ? LIMIT 1';
        $parents = DB::connection('pgsql_rag')->select($sql, [$parentNoteId, 'joplin_note']);
        $parent = $parents[0] ?? null;

        if (! $parent) {
            return;
        }

        $doc = $this->ragService->indexDocument(
            documentType: 'joplin_attachment',
            content: $content,
            title: $attachment['filename'],
            metadata: ['resource_id' => $attachment['resource_id']],
            sourceId: $attachment['resource_id'],
            sourceType: 'joplin_resource'
        );

        // Update designation and parent_id using raw SQL
        $sql = 'UPDATE rag_documents SET designation = ?, parent_id = ?, last_synced_at = NOW() WHERE id = ?';
        DB::connection('pgsql_rag')->update($sql, ['joplin_attachment', $parent->id, $doc->id]);
    }

    /**
     * Get sync statistics
     */
    public function getStats(): array
    {
        $ragStats = $this->ragService->getStats();

        return [
            'total_joplin_notes' => $ragStats['by_type']['joplin_note'] ?? 0,
            'total_joplin_attachments' => $ragStats['by_type']['joplin_attachment'] ?? 0,
            'nextcloud_url' => $this->nextcloudUrl,
            'joplin_path' => $this->joplinPath,
        ];
    }

    /**
     * Reprocess existing RAG attachments with AI summarization
     *
     * This method finds attachments that have raw/placeholder content
     * and reprocesses them with AI summarization.
     *
     * @param  int  $limit  Maximum attachments to process
     * @param  bool  $pdfOnly  Only process PDFs (faster, no OCR needed)
     * @return array Processing statistics
     */
    public function reprocessAttachments(int $limit = 50, bool $pdfOnly = false): array
    {
        $stats = [
            'processed' => 0,
            'improved' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        Log::info('Starting attachment reprocessing', ['limit' => $limit, 'pdf_only' => $pdfOnly]);

        // Find attachments with placeholder or raw content
        $conditions = $pdfOnly
            ? "content LIKE 'PDF Content:%' AND LENGTH(content) > 500"
            : "(content LIKE 'PDF Content:%' OR content LIKE 'Image file:%') AND LENGTH(content) > 50";

        $sql = "SELECT id, title, content, source_id, metadata
                FROM rag_documents
                WHERE document_type = 'joplin_attachment'
                AND ({$conditions})
                ORDER BY id DESC
                LIMIT ?";

        $attachments = DB::connection('pgsql_rag')->select($sql, [$limit]);

        foreach ($attachments as $attachment) {
            try {
                $stats['processed']++;
                $filename = $attachment->title;
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                // Skip if already summarized
                if (str_starts_with($attachment->content, 'Summarized ')) {
                    $stats['skipped']++;

                    continue;
                }

                // Extract raw content from current entry
                $rawContent = $attachment->content;

                // For PDFs, content is already extracted, just needs summarization
                if ($extension === 'pdf' && str_starts_with($rawContent, 'PDF Content:')) {
                    $textContent = substr($rawContent, strlen('PDF Content:'));
                    $textContent = trim($textContent);

                    if (strlen($textContent) > 500 && $this->aiService) {
                        $summarized = $this->summarizeContent($textContent, $filename, 'PDF document');
                        if ($summarized) {
                            $this->updateAttachmentContent($attachment->id, $summarized);
                            $stats['improved']++;
                            Log::info('Reprocessed PDF attachment', ['id' => $attachment->id, 'filename' => $filename]);

                            continue;
                        }
                    }
                }

                // For images, we need to re-fetch and OCR
                if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'tiff', 'webp'])) {
                    $metadata = json_decode($attachment->metadata, true) ?? [];
                    $resourceId = $metadata['resource_id'] ?? $attachment->source_id;

                    if ($resourceId) {
                        $resourcePath = "/.resource/{$resourceId}";
                        $imageContent = $this->fetchAttachmentContent($resourcePath);

                        if ($imageContent) {
                            $ocrResult = $this->extractImageText($imageContent, $filename);
                            if ($ocrResult && $ocrResult !== $rawContent) {
                                $this->updateAttachmentContent($attachment->id, $ocrResult);
                                $stats['improved']++;
                                Log::info('Reprocessed image attachment', ['id' => $attachment->id, 'filename' => $filename]);

                                continue;
                            }
                        }
                    }
                }

                $stats['skipped']++;
            } catch (\Exception $e) {
                Log::error('Failed to reprocess attachment', [
                    'id' => $attachment->id,
                    'error' => $e->getMessage(),
                ]);
                $stats['failed']++;
            }
        }

        Log::info('Attachment reprocessing completed', $stats);

        return $stats;
    }

    /**
     * Update attachment content in RAG and regenerate embedding
     */
    private function updateAttachmentContent(int $id, string $newContent): void
    {
        // Generate new embedding using AIService
        $aiService = $this->aiService ?? app(AIService::class);
        $result = $aiService->generateEmbedding($newContent);

        if (! $result['success']) {
            throw new \Exception('Failed to generate embedding: '.($result['error'] ?? 'unknown error'));
        }

        // Format embedding for PostgreSQL
        $embeddingStr = PgVector::literal($result['embedding']);

        // Update document
        $sql = 'UPDATE rag_documents
                SET content = ?,
                    embedding = ?::vector,
                    content_hash = ?,
                    updated_at = NOW()
                WHERE id = ?';

        DB::connection('pgsql_rag')->update($sql, [
            $newContent,
            $embeddingStr,
            md5($newContent),
            $id,
        ]);
    }

    /**
     * Update health status in cache
     *
     * @param  string  $status  Status: 'healthy', 'running', 'error'
     * @param  array  $details  Additional details
     */
    private function updateHealthStatus(string $status, array $details = []): void
    {
        $health = [
            'status' => $status,
            'updated_at' => Carbon::now()->toIso8601String(),
            'details' => $details,
        ];

        Cache::put(self::HEALTH_CACHE_KEY, $health, now()->addHours(24));
    }

    /**
     * Get health status for monitoring
     *
     * @return array Health status with connection check
     */
    public function getHealth(): array
    {
        $cached = Cache::get(self::HEALTH_CACHE_KEY, [
            'status' => 'unknown',
            'updated_at' => null,
            'details' => [],
        ]);

        $lastSync = Cache::get(self::LAST_SYNC_CACHE_KEY);

        // Check Nextcloud connection
        $connectionOk = $this->checkNextcloudConnection();

        // Get queue stats
        $queueStats = $this->getQueueStats();

        return [
            'status' => $cached['status'],
            'last_sync' => $lastSync,
            'last_health_update' => $cached['updated_at'],
            'connection' => [
                'nextcloud' => $connectionOk ? 'ok' : 'error',
                'url' => $this->nextcloudUrl,
            ],
            'queue' => $queueStats,
            'details' => $cached['details'],
        ];
    }

    /**
     * Check Nextcloud connection
     *
     * @return bool True if connection successful
     */
    private function checkNextcloudConnection(): bool
    {
        try {
            $url = $this->nextcloudUrl.'/remote.php/dav/files/'.$this->username.$this->joplinPath;

            $response = $this->http()
                ->timeout(10)
                ->withHeaders(['Depth' => '0'])
                ->send('PROPFIND', $url);

            return $response->successful();
        } catch (\Exception $e) {
            Log::warning('Joplin health check: Nextcloud connection failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get Joplin queue statistics
     *
     * @return array Queue statistics
     */
    private function getQueueStats(): array
    {
        try {
            $pendingResult = DB::selectOne('SELECT COUNT(*) as cnt FROM joplin_queue_jobs WHERE status = ?', ['pending']);
            $pending = $pendingResult->cnt ?? 0;

            $failedResult = DB::selectOne('SELECT COUNT(*) as cnt FROM joplin_queue_jobs WHERE status = ?', ['failed']);
            $failed = $failedResult->cnt ?? 0;

            $oldestPending = DB::selectOne('SELECT created_at FROM joplin_queue_jobs WHERE status = ? ORDER BY created_at ASC LIMIT 1', ['pending']);

            return [
                'pending' => $pending,
                'failed' => $failed,
                'oldest_pending' => $oldestPending?->created_at,
            ];
        } catch (\Exception $e) {
            return [
                'pending' => 0,
                'failed' => 0,
                'oldest_pending' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cleanup orphaned RAG documents
     *
     * This method removes:
     * 1. Joplin notes with null source_id (malformed entries)
     * 2. Joplin attachments whose parent notes no longer exist
     *
     * @return array Cleanup statistics
     */
    private function cleanupOrphanedDocuments(): array
    {
        $stats = [
            'null_source_notes' => 0,
            'orphaned_attachments' => 0,
        ];

        try {
            // Delete joplin_note documents with null source_id
            $sql = 'DELETE FROM rag_documents WHERE designation = ? AND source_id IS NULL';
            $stats['null_source_notes'] = DB::connection('pgsql_rag')->delete($sql, ['joplin_note']);

            if ($stats['null_source_notes'] > 0) {
                Log::info('Joplin cleanup: Removed notes with null source_id', [
                    'count' => $stats['null_source_notes'],
                ]);
            }

            // Delete orphaned attachments (parent_id references non-existent document)
            $sql = 'DELETE FROM rag_documents
                    WHERE designation = ?
                    AND parent_id IS NOT NULL
                    AND parent_id NOT IN (
                        SELECT id FROM rag_documents WHERE designation = ?
                    )';
            $stats['orphaned_attachments'] = DB::connection('pgsql_rag')->delete($sql, ['joplin_attachment', 'joplin_note']);

            if ($stats['orphaned_attachments'] > 0) {
                Log::info('Joplin cleanup: Removed orphaned attachments', [
                    'count' => $stats['orphaned_attachments'],
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Joplin cleanup failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $stats;
    }

    /**
     * Check if a note is quarantined due to repeated failures
     */
    private function isQuarantined(string $noteId): bool
    {
        $key = self::FAILURE_CACHE_PREFIX.$noteId;
        $failures = Cache::get($key, ['count' => 0, 'quarantined_at' => null]);

        if ($failures['quarantined_at']) {
            $quarantinedAt = Carbon::parse($failures['quarantined_at']);
            if ($quarantinedAt->diffInHours(now()) < self::QUARANTINE_HOURS) {
                return true;
            }
            // Quarantine expired, reset
            Cache::forget($key);
        }

        return false;
    }

    /**
     * Record a sync failure for a note
     */
    private function recordSyncFailure(string $noteId, string $title, string $error): void
    {
        $key = self::FAILURE_CACHE_PREFIX.$noteId;
        $failures = Cache::get($key, ['count' => 0, 'quarantined_at' => null, 'errors' => []]);

        $failures['count']++;
        $failures['last_error'] = $error;
        $failures['last_attempt'] = now()->toIso8601String();
        $failures['title'] = $title;
        $failures['errors'][] = [
            'time' => now()->toIso8601String(),
            'error' => substr($error, 0, 200),
        ];

        // Keep only last 5 errors
        if (count($failures['errors']) > 5) {
            $failures['errors'] = array_slice($failures['errors'], -5);
        }

        // Quarantine after max failures
        if ($failures['count'] >= self::MAX_FAILURES_BEFORE_QUARANTINE) {
            $failures['quarantined_at'] = now()->toIso8601String();
            Log::warning('Joplin note quarantined after repeated failures', [
                'note_id' => $noteId,
                'title' => $title,
                'failure_count' => $failures['count'],
                'last_error' => $error,
                'quarantine_hours' => self::QUARANTINE_HOURS,
            ]);
        }

        // Cache for quarantine duration + buffer
        Cache::put($key, $failures, now()->addHours(self::QUARANTINE_HOURS + 24));
    }

    /**
     * Clear quarantine for a note (called on successful sync)
     */
    private function clearQuarantine(string $noteId): void
    {
        $key = self::FAILURE_CACHE_PREFIX.$noteId;
        Cache::forget($key);
    }

    /**
     * Get all quarantined notes for admin review
     */
    public function getQuarantinedNotes(): array
    {
        // This requires iterating cache keys which isn't ideal
        // In production, consider using a database table instead
        $quarantined = [];

        // Get all cache keys with our prefix (Redis-specific)
        try {
            $keys = Cache::getRedis()->keys(config('cache.prefix').':'.self::FAILURE_CACHE_PREFIX.'*');
            foreach ($keys as $key) {
                $cleanKey = str_replace(config('cache.prefix').':', '', $key);
                $data = Cache::get($cleanKey);
                if ($data && isset($data['quarantined_at'])) {
                    $quarantined[] = array_merge($data, [
                        'note_id' => str_replace(self::FAILURE_CACHE_PREFIX, '', $cleanKey),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::debug('Could not retrieve quarantined notes from cache', ['error' => $e->getMessage()]);
        }

        return $quarantined;
    }
}
