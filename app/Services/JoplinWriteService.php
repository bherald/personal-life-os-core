<?php

namespace App\Services;

use App\Support\JoplinPaths;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Joplin Write Service
 *
 * Provides write capabilities for Joplin notes via WebDAV.
 * Supports creating, updating, appending, and managing notes.
 * Implements bidirectional sync detection and conflict resolution.
 * Independent PLOS interoperability adapter for an operator-managed sync target;
 * it does not use upstream Joplin application or server source code.
 *
 * Uses direct HTTP/WebDAV operations for maximum control.
 */
class JoplinWriteService
{
    protected string $baseUrl;

    protected string $username;

    protected string $password;

    protected string $joplinPath = '/Joplin-data/';

    protected ?string $localPath = null;

    protected JoplinFilesService $readService;

    protected JoplinLockHandler $lockHandler;

    protected JoplinQueueService $queueService;

    private const HTTP_CONNECT_TIMEOUT = 5;

    private const HTTP_TIMEOUT = 120;

    public function __construct(
        JoplinFilesService $readService,
        JoplinLockHandler $lockHandler,
        JoplinQueueService $queueService
    ) {
        $this->baseUrl = rtrim(config('services.nextcloud.url') ?? '', '/');
        $this->username = config('services.nextcloud.username') ?? '';
        $this->password = config('services.nextcloud.password') ?? '';
        $this->readService = $readService;
        $this->lockHandler = $lockHandler;
        $this->queueService = $queueService;
        $this->joplinPath = JoplinPaths::syncPath(true);

        $this->localPath = JoplinPaths::localRoot();
    }

    protected function getLocalFilePath(string $path): ?string
    {
        return JoplinPaths::localFile($this->localPath, $this->joplinPath, $path);
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT)
            ->timeout(self::HTTP_TIMEOUT)
            ->withBasicAuth($this->username, $this->password);
    }

    /**
     * Create a new Joplin note
     *
     * @param  string  $title  Note title
     * @param  string  $content  Note content (markdown)
     * @param  string|null  $parentId  Parent notebook ID (null for root)
     * @param  array  $options  Additional options (tags, etc.)
     * @param  bool  $skipLock  Skip lock acquisition (used by queue processor)
     * @return array Created note info with ID
     */
    public function createNote(string $title, string $content, ?string $parentId = null, array $options = [], bool $skipLock = false): array
    {
        $lock = null;

        try {
            // Try to acquire lock (unless skipLock is true)
            if (! $skipLock) {
                try {
                    $lock = $this->lockHandler->acquireSyncLock();
                } catch (\Exception $e) {
                    // Lock acquisition failed - queue the operation
                    Log::info('Lock acquisition failed, queueing operation', [
                        'operation' => 'create_note',
                        'title' => $title,
                    ]);

                    $job = $this->queueService->queueOperation(
                        'create_note',
                        [
                            'title' => $title,
                            'content' => $content,
                            'parent_id' => $parentId,
                            'options' => $options,
                        ]
                    );

                    return [
                        'success' => true,
                        'queued' => true,
                        'job_id' => $job->id,
                        'message' => 'Operation queued - Joplin sync target is locked by another client. Will retry automatically.',
                    ];
                }
            }

            // Generate unique ID (32-char hex, Joplin format)
            $noteId = $this->generateJoplinId();

            // Get current timestamp
            $now = $this->getJoplinTimestamp();

            // Build note content in Joplin format
            $noteContent = $this->buildNoteContent([
                'id' => $noteId,
                'parent_id' => $parentId ?? '',
                'title' => $title,
                'content' => $content,
                'created_time' => $now,
                'updated_time' => $now,
                'type' => 1, // 1 = note
                'metadata' => $options['metadata'] ?? [],
            ]);

            // Write to WebDAV
            $success = $this->writeFile($this->joplinPath.$noteId.'.md', $noteContent);

            if (! $success) {
                throw new \Exception('Failed to write note file to WebDAV');
            }

            Log::info('Created Joplin note', [
                'note_id' => $noteId,
                'title' => $title,
                'parent_id' => $parentId,
            ]);

            return [
                'success' => true,
                'note_id' => $noteId,
                'title' => $title,
                'parent_id' => $parentId,
                'created_time' => $now,
                'path' => $this->joplinPath.$noteId.'.md',
            ];

        } catch (\Exception $e) {
            Log::error('Failed to create Joplin note', [
                'error' => $e->getMessage(),
                'title' => $title,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } finally {
            // Always release lock
            if ($lock) {
                $this->lockHandler->releaseLock($lock);
            }
        }
    }

    /**
     * Update an existing note
     *
     * @param  string  $noteId  Note ID to update
     * @param  array  $updates  Fields to update (title, content, parent_id, etc.)
     * @param  bool  $detectConflict  Check for conflicts before updating
     * @param  bool  $skipLock  Skip lock acquisition (used by queue processor)
     * @return array Update result
     */
    public function updateNote(string $noteId, array $updates, bool $detectConflict = true, bool $skipLock = false): array
    {
        $lock = null;

        try {
            // Try to acquire lock (unless skipLock is true)
            if (! $skipLock) {
                try {
                    $lock = $this->lockHandler->acquireSyncLock();
                } catch (\Exception $e) {
                    // Queue the operation
                    $job = $this->queueService->queueOperation(
                        'update_note',
                        ['updates' => $updates, 'detect_conflict' => $detectConflict],
                        $noteId
                    );

                    return [
                        'success' => true,
                        'queued' => true,
                        'job_id' => $job->id,
                        'message' => 'Update queued - will retry automatically.',
                    ];
                }
            }

            // Get existing note
            $existing = $this->readService->getNote($noteId);

            if (! $existing) {
                throw new \Exception("Note not found: $noteId");
            }

            // Conflict detection
            if ($detectConflict && isset($updates['expected_updated_time'])) {
                if ($existing['updated_time'] !== $updates['expected_updated_time']) {
                    return [
                        'success' => false,
                        'error' => 'Conflict detected: note was modified since last read',
                        'conflict' => true,
                        'current_updated_time' => $existing['updated_time'],
                        'expected_updated_time' => $updates['expected_updated_time'],
                    ];
                }
            }

            // Merge updates
            $newData = [
                'id' => $noteId,
                'parent_id' => $updates['parent_id'] ?? $existing['parent_id'],
                'title' => $updates['title'] ?? $existing['title'],
                'content' => $updates['content'] ?? $existing['content'],
                'created_time' => $existing['created_time'],
                'updated_time' => $this->getJoplinTimestamp(),
                'type' => $existing['type'],
                'metadata' => array_merge($existing['metadata'], $updates['metadata'] ?? []),
            ];

            // Build and write updated content
            $noteContent = $this->buildNoteContent($newData);
            $success = $this->writeFile($this->joplinPath.$noteId.'.md', $noteContent);

            if (! $success) {
                throw new \Exception('Failed to write updated note');
            }

            Log::info('Updated Joplin note', [
                'note_id' => $noteId,
                'updates' => array_keys($updates),
            ]);

            return [
                'success' => true,
                'note_id' => $noteId,
                'updated_time' => $newData['updated_time'],
                'title' => $newData['title'],
            ];

        } catch (\Exception $e) {
            Log::error('Failed to update Joplin note', [
                'error' => $e->getMessage(),
                'note_id' => $noteId,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } finally {
            if ($lock) {
                $this->lockHandler->releaseLock($lock);
            }
        }
    }

    /**
     * Append content to an existing note
     *
     * @param  string  $noteId  Note ID
     * @param  string  $appendContent  Content to append
     * @param  string  $separator  Separator between existing and new content
     * @return array Result
     */
    public function appendToNote(string $noteId, string $appendContent, string $separator = "\n\n"): array
    {
        try {
            $existing = $this->readService->getNote($noteId);

            if (! $existing) {
                throw new \Exception("Note not found: $noteId");
            }

            $newContent = $existing['content'].$separator.$appendContent;

            return $this->updateNote($noteId, [
                'content' => $newContent,
            ], false); // No conflict detection for appends

        } catch (\Exception $e) {
            Log::error('Failed to append to Joplin note', [
                'error' => $e->getMessage(),
                'note_id' => $noteId,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a new notebook/folder
     *
     * @param  string  $title  Notebook title
     * @param  string|null  $parentId  Parent notebook ID
     * @return array Created notebook info
     */
    public function createNotebook(string $title, ?string $parentId = null): array
    {
        try {
            $notebookId = $this->generateJoplinId();
            $now = $this->getJoplinTimestamp();

            $notebookContent = $this->buildNoteContent([
                'id' => $notebookId,
                'parent_id' => $parentId ?? '',
                'title' => $title,
                'content' => '',
                'created_time' => $now,
                'updated_time' => $now,
                'type' => 2, // 2 = notebook
                'metadata' => [],
            ]);

            $success = $this->writeFile($this->joplinPath.$notebookId.'.md', $notebookContent);

            if (! $success) {
                throw new \Exception('Failed to write notebook file');
            }

            Log::info('Created Joplin notebook', [
                'notebook_id' => $notebookId,
                'title' => $title,
            ]);

            return [
                'success' => true,
                'notebook_id' => $notebookId,
                'title' => $title,
                'parent_id' => $parentId,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to create Joplin notebook', [
                'error' => $e->getMessage(),
                'title' => $title,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Delete a note
     *
     * @param  string  $noteId  Note ID to delete
     * @return array Result
     */
    public function deleteNote(string $noteId): array
    {
        try {
            $path = $this->joplinPath.$noteId.'.md';

            $localFile = $this->getLocalFilePath($path);
            if ($localFile && file_exists($localFile) && is_writable($localFile) && @unlink($localFile)) {
                Log::info('Deleted Joplin note via filesystem', ['note_id' => $noteId]);

                return [
                    'success' => true,
                    'note_id' => $noteId,
                ];
            }

            if ($localFile && file_exists($localFile)) {
                Log::warning('JoplinWriteService: filesystem delete fallback to WebDAV', ['path' => $path]);
            }

            $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$path;

            $response = $this->http()
                ->delete($url);

            if (! $response->successful()) {
                throw new \Exception('Failed to delete note: HTTP '.$response->status());
            }

            Log::info('Deleted Joplin note', ['note_id' => $noteId]);

            return [
                'success' => true,
                'note_id' => $noteId,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to delete Joplin note', [
                'error' => $e->getMessage(),
                'note_id' => $noteId,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Detect sync conflicts by comparing timestamps
     *
     * @param  array  $localNotes  Local note states with updated_time
     * @return array Notes with conflicts
     */
    public function detectConflicts(array $localNotes): array
    {
        $conflicts = [];

        foreach ($localNotes as $noteId => $localState) {
            $remoteNote = $this->readService->getNote($noteId);

            if (! $remoteNote) {
                continue; // Note doesn't exist remotely
            }

            if ($remoteNote['updated_time'] !== $localState['updated_time']) {
                $conflicts[] = [
                    'note_id' => $noteId,
                    'local_updated_time' => $localState['updated_time'],
                    'remote_updated_time' => $remoteNote['updated_time'],
                    'title' => $remoteNote['title'],
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Build note content for the Joplin sync-target wire format.
     *
     * Format: title line, body, then key/value properties.
     *
     * @param  array  $data  Note data
     * @return string Formatted note content
     */
    protected function buildNoteContent(array $data): string
    {
        $lines = [];

        // 1. Title (first line, plain text)
        $lines[] = $data['title'];
        $lines[] = '';

        // 2. Body content (BEFORE properties, for type 1=note)
        if ($data['type'] == 1 && ! empty($data['content'])) {
            $lines[] = $data['content'];
            $lines[] = '';
        }

        // 3. Properties section (key: value format)
        $lines[] = 'id: '.$data['id'];
        $lines[] = 'created_time: '.$data['created_time'];
        $lines[] = 'updated_time: '.$data['updated_time'];
        $lines[] = 'user_created_time: '.$data['created_time'];
        $lines[] = 'user_updated_time: '.$data['updated_time'];
        $lines[] = 'encryption_cipher_text: ';
        $lines[] = 'encryption_applied: 0';
        $lines[] = 'parent_id: '.($data['parent_id'] ?? '');
        $lines[] = 'is_shared: 0';
        $lines[] = 'share_id: ';
        $lines[] = 'master_key_id: ';
        $lines[] = 'icon: '.($data['metadata']['icon'] ?? '');
        $lines[] = 'user_data: ';
        $lines[] = 'deleted_time: 0';
        $lines[] = 'type_: '.$data['type'];

        return implode("\n", $lines);
    }

    /**
     * Write file to WebDAV
     *
     * @param  string  $path  Relative path from user root
     * @param  string  $content  File content
     * @return bool Success
     */
    protected function writeFile(string $path, string $content): bool
    {
        $localFile = $this->getLocalFilePath($path);
        if ($localFile) {
            $directory = dirname($localFile);
            if (is_dir($directory) && is_writable($directory) && (! file_exists($localFile) || is_writable($localFile))) {
                $written = @file_put_contents($localFile, $content, LOCK_EX);
                if ($written !== false) {
                    return true;
                }

                Log::warning('JoplinWriteService: filesystem write fallback to WebDAV', ['path' => $path]);
            }
        }

        $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$path;

        $response = $this->http()
            ->withBody($content, 'text/plain')
            ->put($url);

        return $response->successful();
    }

    /**
     * Generate Joplin-compatible ID (32-char hex)
     *
     * @return string 32-character hexadecimal ID
     */
    protected function generateJoplinId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get Joplin-compatible timestamp
     *
     * @return string ISO 8601 timestamp with milliseconds
     */
    protected function getJoplinTimestamp(): string
    {
        return now()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Get service status
     *
     * @return array Status information
     */
    public function getStatus(): array
    {
        return [
            'available' => true,
            'capabilities' => [
                'create_notes' => true,
                'update_notes' => true,
                'append_notes' => true,
                'create_notebooks' => true,
                'delete_notes' => true,
                'conflict_detection' => true,
            ],
            'source' => 'Nextcloud WebDAV',
            'path' => $this->joplinPath,
        ];
    }
}
