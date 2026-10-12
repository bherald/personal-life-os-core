<?php

namespace App\Services;

use App\Support\JoplinPaths;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
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

            // Stable YouTube identity survives queue replay, lost acknowledgements and host races.
            $videoId = ($options['metadata']['source'] ?? null) === 'youtube_automation'
                ? ($options['metadata']['video_id'] ?? '') : null;
            if ($videoId !== null && (! is_string($videoId) || ! preg_match('/^[A-Za-z0-9_-]{11}$/D', $videoId))) {
                throw new \RuntimeException('Invalid YouTube video identity');
            }
            $noteId = $videoId === null ? $this->generateJoplinId()
                : substr(hash('sha256', 'plos.youtube.note:'.$videoId), 0, 32);
            if ($videoId !== null) {
                if ($this->reviewSnapshot($noteId.'.md') !== null) {
                    return $this->existingYouTubeResult($this->readService->getNote($noteId, true), $videoId);
                }
                $existing = $this->readService->findYouTubeVideoNote($videoId);
                if ($existing !== null) {
                    return $this->existingYouTubeResult($existing, $videoId);
                }
                $options['metadata']['source_url'] = 'https://www.youtube.com/watch?v='.$videoId;
            }

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
            if ($videoId !== null) {
                try {
                    $this->reviewPut($noteId.'.md', $noteContent, null);
                } catch (\Exception $e) {
                    // Another host may have won, or the successful PUT acknowledgement was lost.
                    if ($this->reviewSnapshot($noteId.'.md') !== null) {
                        return $this->existingYouTubeResult($this->readService->getNote($noteId, true), $videoId);
                    }
                    throw $e;
                }
                $success = true;
            } else {
                $success = $this->writeFile($this->joplinPath.$noteId.'.md', $noteContent);
            }

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

    private function existingYouTubeResult(?array $note, string $videoId): array
    {
        if ($note === null || $note['type'] !== 1 || $this->readService->youtubeVideoId($note) !== $videoId) {
            throw new \RuntimeException('Existing YouTube identity conflicts; preserved without overwrite');
        }

        return [
            'success' => true, 'queued' => false, 'reused' => true,
            'note_id' => $note['id'], 'title' => $note['title'],
            'parent_id' => $note['parent_id'], 'created_time' => $note['created_time'],
            'path' => $this->joplinPath.$note['id'].'.md',
        ];
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
        $lines[] = 'source_url: '.($data['metadata']['source_url'] ?? '');
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

    /** Refuse a target requiring client-side encryption; do not inspect or expose key material. */
    public function assertReviewTargetCompatible(): void
    {
        $response = $this->http()->get($this->baseUrl.'/remote.php/dav/files/'.rawurlencode($this->username).$this->joplinPath.'info.json');
        if (! $response->successful() || $response->json('version') !== 3 || $response->json('e2ee.value') !== false) {
            throw new \RuntimeException('Review target requires a supported unencrypted v3 sync format; client encryption is not bypassed');
        }
    }

    /** Fresh sync-target read for managed mirrors; never a cached/local-file assertion. */
    public function reviewSnapshot(string $relative): ?array
    {
        $url = $this->reviewUrl($relative);
        $response = $this->http()->get($url);
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new \RuntimeException('Review WebDAV read failed: HTTP '.$response->status());
        }

        return ['body' => $response->body(), 'etag' => $response->header('ETag'), 'hash' => hash('sha256', $response->body())];
    }

    /** Conditional WebDAV write prevents overwriting an edit between read and write. */
    public function reviewPut(string $relative, string $body, ?array $previous): array
    {
        if ($previous !== null && empty($previous['etag'])) {
            throw new \RuntimeException('Review target has no ETag; refusing unconditional overwrite');
        }
        $request = $this->http()->withHeaders($previous === null ? ['If-None-Match' => '*'] : ['If-Match' => $previous['etag']]);
        $response = $request->withBody($body, 'application/octet-stream')->put($this->reviewUrl($relative));
        if (! $response->successful()) {
            throw new \RuntimeException('Review conditional write failed: HTTP '.$response->status());
        }
        $verified = $this->reviewSnapshot($relative);
        if (! $verified || ! hash_equals(hash('sha256', $body), $verified['hash'])) {
            throw new \RuntimeException('Review read-back mismatch');
        }

        return $verified;
    }

    /** Conditional delete for an item owned by a managed mirror. */
    public function reviewDelete(string $relative, array $previous): void
    {
        if (empty($previous['etag']) || empty($previous['hash'])) {
            throw new \RuntimeException('Review delete requires a verified ETag and digest');
        }

        $response = $this->http()
            ->withHeaders(['If-Match' => $previous['etag']])
            ->delete($this->reviewUrl($relative));
        if (! $response->successful()) {
            throw new \RuntimeException('Review conditional delete failed: HTTP '.$response->status());
        }
        if ($this->reviewSnapshot($relative) !== null) {
            throw new \RuntimeException('Review delete read-back mismatch');
        }
    }

    public function reviewNoteWire(string $id, string $title, string $body, string $parent, string $created): string
    {
        return $this->buildNoteContent([
            'id' => $id, 'parent_id' => $parent, 'title' => $title, 'content' => $body,
            'created_time' => $created, 'updated_time' => $this->getJoplinTimestamp(), 'type' => 1,
        ]);
    }

    /** Compare operator-visible note fields while ignoring client-normalized wire metadata. */
    public function reviewNoteSemanticallyMatches(string $wire, string $title, string $body, ?string $parent): bool
    {
        $note = $this->reviewNoteFields($wire);
        if (! $note || $note['title'] !== $title) {
            return false;
        }

        return ($parent === null || $note['parent_id'] === $parent)
            && $note['type'] === '1'
            && trim($note['content'], "\n") === trim(str_replace(["\r\n", "\r"], "\n", $body), "\n");
    }

    public function reviewNoteParent(string $wire): ?string
    {
        return $this->reviewNoteFields($wire)['parent_id'] ?? null;
    }

    /** Read exact body bytes, removing only the sync format's structural separators. */
    public function reviewNoteExactFields(string $wire, string $noteId): array
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $noteId)
            || ! preg_match('/\A([^\r\n]+)(\r?\n)\2/', $wire, $header)) {
            throw new \RuntimeException('Invalid Joplin note framing');
        }
        $separator = $header[2].$header[2];
        $boundary = strrpos($wire, $separator.'id: '.$noteId.$header[2]);
        if ($boundary === false || ($boundary < strlen($header[0])
            && $boundary + strlen($separator) !== strlen($header[0]))) {
            throw new \RuntimeException('Missing Joplin note footer');
        }
        $offset = $boundary + strlen($separator);
        $fields = [];
        foreach (explode($header[2], substr($wire, $offset)) as $line) {
            if (preg_match('/^(id|parent_id|type_|created_time|updated_time|user_updated_time):[ \t]*(.*)$/D', $line, $match)) {
                if (array_key_exists($match[1], $fields)) {
                    throw new \RuntimeException('Ambiguous Joplin note footer');
                }
                $fields[$match[1]] = $match[2];
            }
        }
        if (($fields['id'] ?? null) !== $noteId || ($fields['type_'] ?? null) !== '1'
            || ! isset($fields['parent_id'], $fields['updated_time'], $fields['created_time'])) {
            throw new \RuntimeException('Incomplete Joplin note footer');
        }

        return $fields + [
            'title' => $header[1],
            'content' => substr($wire, strlen($header[0]), max(0, $boundary - strlen($header[0]))),
            'footer_offset' => $offset,
        ];
    }

    /** Caller holds the sync lock; retain source body and all unrelated wire properties. */
    public function reviewMoveNote(string $noteId, string $parentId, string $expectedUpdated): void
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $parentId)) {
            throw new \RuntimeException('Invalid Joplin move target');
        }
        $snapshot = $this->reviewSnapshot($noteId.'.md');
        if ($snapshot === null) {
            throw new \RuntimeException('Joplin move source is missing');
        }
        $fields = $this->reviewNoteExactFields($snapshot['body'], $noteId);
        if ($fields['updated_time'] !== $expectedUpdated) {
            throw new \RuntimeException('Joplin move source changed since inventory');
        }
        if ($fields['parent_id'] === $parentId) {
            return;
        }
        $now = $this->getJoplinTimestamp();
        $footer = preg_replace_callback('/^(parent_id|updated_time|user_updated_time):[^\r\n]*/m',
            fn (array $match): string => $match[1].': '.($match[1] === 'parent_id' ? $parentId : $now),
            substr($snapshot['body'], $fields['footer_offset']));
        $wire = substr($snapshot['body'], 0, $fields['footer_offset']).$footer;
        $this->reviewPut($noteId.'.md', $wire, $snapshot);
    }

    private function reviewNoteFields(string $wire): ?array
    {
        $wire = str_replace(["\r\n", "\r"], "\n", $wire);
        $lines = explode("\n", $wire);
        $titleIndex = null;
        foreach ($lines as $index => $line) {
            if (trim($line) !== '') {
                $titleIndex = $index;
                break;
            }
        }
        $metadataIndex = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^id:\s*[a-f0-9]{32}$/D', $line)) {
                $metadataIndex = $index;
                break;
            }
        }
        if ($titleIndex === null || $metadataIndex === null || $metadataIndex <= $titleIndex) {
            return null;
        }
        $metadata = [];
        foreach (array_slice($lines, $metadataIndex) as $line) {
            if (preg_match('/^(id|parent_id|type_):\s*(.*)$/D', $line, $match)) {
                $metadata[$match[1]] = $match[2];
            }
        }

        return [
            'title' => trim($lines[$titleIndex]),
            'content' => implode("\n", array_slice($lines, $titleIndex + 1, $metadataIndex - $titleIndex - 1)),
            'parent_id' => $metadata['parent_id'] ?? null,
            'type' => $metadata['type_'] ?? null,
        ];
    }

    public function reviewNotebookWire(string $id, string $title, string $parent): string
    {
        return $this->buildNoteContent([
            'id' => $id, 'parent_id' => $parent, 'title' => $title, 'content' => '',
            'created_time' => $this->getJoplinTimestamp(), 'updated_time' => $this->getJoplinTimestamp(), 'type' => 2,
        ]);
    }

    /** Immutable resources: changing a source creates a new resource, preserving older notes/history. */
    public function reviewResource(string $id, string $filename, string $mime, string $bytes): void
    {
        $binary = $this->reviewSnapshot('.resource/'.$id);
        if ($binary && ! hash_equals(hash('sha256', $bytes), $binary['hash'])) {
            throw new \RuntimeException('Review resource identity collision');
        }
        if (! $binary) {
            $this->reviewPut('.resource/'.$id, $bytes, null);
        }
        $meta = $this->reviewSnapshot($id.'.md');
        if ($meta) {
            if (! str_ends_with($meta['body'], 'type_: 4') || ! str_contains($meta['body'], "\nid: ".$id."\n")) {
                throw new \RuntimeException('Review resource metadata collision');
            }

            return;
        }
        $this->reviewPut($id.'.md', $this->reviewResourceWire($id, $filename, $mime, $bytes), null);
    }

    private function reviewResourceWire(string $id, string $filename, string $mime, string $bytes): string
    {
        $now = $this->getJoplinTimestamp();
        $wire = $filename."\n\nid: ".$id."\nmime: ".$mime."\nfilename: ".$filename
            ."\ncreated_time: ".$now."\nupdated_time: ".$now."\nuser_created_time: ".$now."\nuser_updated_time: ".$now
            ."\nfile_extension: ".pathinfo($filename, PATHINFO_EXTENSION)."\nencryption_cipher_text: \nencryption_applied: 0"
            ."\nencryption_blob_encrypted: 0\nsize: ".strlen($bytes)."\nshare_id: \nmaster_key_id: \nuser_data: \ntype_: 4";

        return $wire;
    }

    /** Bounded independent DAV requests through Laravel's existing HTTP transport. */
    private function reviewPool(array $tasks, int $concurrency, int $timeout): array
    {
        $results = Http::pool(function (Pool $pool) use ($tasks, $timeout) {
            foreach ($tasks as $path => $task) {
                $request = $pool->as($path)->connectTimeout(self::HTTP_CONNECT_TIMEOUT)->timeout($timeout)
                    ->withBasicAuth($this->username, $this->password);
                if ($task['method'] === 'GET') {
                    $request->get($this->reviewUrl($path));
                } elseif ($task['method'] === 'DELETE') {
                    $previous = $task['previous'];
                    if (empty($previous['etag']) || empty($previous['hash'])) {
                        throw new \RuntimeException('Review batch delete requires a verified receipt');
                    }
                    $request->withHeaders(['If-Match' => $previous['etag']])->delete($this->reviewUrl($path));
                } else {
                    $previous = $task['previous'];
                    if ($previous !== null && empty($previous['etag'])) {
                        throw new \RuntimeException('Review ETag unavailable');
                    }
                    $request->withHeaders($previous === null ? ['If-None-Match' => '*'] : ['If-Match' => $previous['etag']])
                        ->withBody($task['body'], 'application/octet-stream')->put($this->reviewUrl($path));
                }
            }
        }, $concurrency);
        foreach ($results as $response) {
            if (! $response instanceof Response) {
                throw new \RuntimeException('Review batch transport failed; resume from receipts');
            }
        }

        return $results;
    }

    public function reviewSnapshots(array $paths, int $concurrency, int $timeout): array
    {
        if (! $paths) {
            return [];
        }
        $tasks = array_fill_keys($paths, ['method' => 'GET']);
        $out = [];
        foreach ($this->reviewPool($tasks, $concurrency, $timeout) as $path => $response) {
            if ($response->status() === 404) {
                $out[$path] = null;

                continue;
            }
            if (! $response->successful()) {
                throw new \RuntimeException('Review batch read failed: HTTP '.$response->status());
            }
            $out[$path] = ['body' => $response->body(), 'etag' => $response->header('ETag'), 'hash' => hash('sha256', $response->body())];
        }

        return $out;
    }

    public function reviewPuts(array $updates, int $concurrency, int $timeout): array
    {
        if (! $updates) {
            return [];
        }
        $tasks = array_map(fn ($item) => ['method' => 'PUT'] + $item, $updates);
        foreach ($this->reviewPool($tasks, $concurrency, $timeout) as $response) {
            if (! $response->successful()) {
                throw new \RuntimeException('Review batch conditional write failed: HTTP '.$response->status());
            }
        }
        $verified = $this->reviewSnapshots(array_keys($updates), $concurrency, $timeout);
        foreach ($updates as $path => $item) {
            if (! $verified[$path] || hash('sha256', $item['body']) !== $verified[$path]['hash']) {
                throw new \RuntimeException('Review batch read-back mismatch');
            }
        }

        return $verified;
    }

    public function reviewDeletes(array $snapshots, int $concurrency, int $timeout): void
    {
        if (! $snapshots) {
            return;
        }
        $tasks = array_map(fn ($previous) => ['method' => 'DELETE', 'previous' => $previous], $snapshots);
        foreach ($this->reviewPool($tasks, $concurrency, $timeout) as $response) {
            if (! $response->successful()) {
                throw new \RuntimeException('Review batch conditional delete failed: HTTP '.$response->status());
            }
        }
        foreach ($this->reviewSnapshots(array_keys($snapshots), $concurrency, $timeout) as $remote) {
            if ($remote !== null) {
                throw new \RuntimeException('Review batch delete read-back mismatch');
            }
        }
    }

    public function reviewResources(array $items, int $concurrency, int $timeout): void
    {
        $paths = [];
        foreach ($items as $item) {
            $paths[] = '.resource/'.$item['id'];
            $paths[] = $item['id'].'.md';
        }
        $snapshots = $this->reviewSnapshots($paths, $concurrency, $timeout);
        $updates = [];
        foreach ($items as $item) {
            ['id' => $id, 'filename' => $filename, 'mime' => $mime, 'bytes' => $bytes] = $item;
            $binary = $snapshots['.resource/'.$id];
            $meta = $snapshots[$id.'.md'];
            if ($binary && hash('sha256', $bytes) !== $binary['hash']) {
                throw new \RuntimeException('Review resource identity collision');
            }
            if ($meta && (! str_ends_with($meta['body'], 'type_: 4') || ! str_contains($meta['body'], "\nid: ".$id."\n"))) {
                throw new \RuntimeException('Review resource metadata collision');
            }
            if (! $binary) {
                $updates['.resource/'.$id] = ['body' => $bytes, 'previous' => null];
            }
            if (! $meta) {
                $updates[$id.'.md'] = ['body' => $this->reviewResourceWire($id, $filename, $mime, $bytes), 'previous' => null];
            }
        }
        $this->reviewPuts($updates, $concurrency, $timeout);
    }

    private function reviewUrl(string $relative): string
    {
        if (! preg_match('~^(?:[a-f0-9]{32}\.md|\.resource/[a-f0-9]{32})$~D', $relative)) {
            throw new \InvalidArgumentException('Invalid managed Joplin item path');
        }

        return $this->baseUrl.'/remote.php/dav/files/'.rawurlencode($this->username).$this->joplinPath.$relative;
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
