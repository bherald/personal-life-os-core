<?php

namespace App\Services;

use App\Support\JoplinPaths;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * JoplinFilesService
 *
 * Provides read-only (and eventually read-write) access to Joplin notes
 * synced to Nextcloud via WebDAV.
 * This is an independent PLOS interoperability adapter for an operator-managed
 * sync target; it does not use upstream Joplin application or server source
 * code.
 *
 * Joplin stores notes as .md files with metadata headers:
 * - type_ 1: Notes (with content after ---)
 * - type_ 2: Notebooks/folders
 * - type_ 4: Resources/attachments (metadata, binary in .resource/)
 *
 * Example note structure:
 * Title of Note
 *
 * id: abc123...
 * parent_id: def456...
 * created_time: 2025-01-01T00:00:00.000Z
 * updated_time: 2025-01-01T00:00:00.000Z
 * ---
 *
 * Actual markdown content here...
 */
class JoplinFilesService
{
    protected string $baseUrl;

    protected string $username;

    protected string $password;

    protected string $joplinPath = '/Joplin-data/';

    protected ?string $localPath = null;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.nextcloud.url') ?? '', '/');
        $this->username = config('services.nextcloud.username') ?? '';
        $this->password = config('services.nextcloud.password') ?? '';
        $this->joplinPath = JoplinPaths::syncPath(true);

        // Filesystem-first: direct reads ~1000x faster than WebDAV
        $this->localPath = JoplinPaths::localRoot();
    }

    /**
     * List all Joplin note files
     */
    public function listNotes(): array
    {
        $files = $this->listDirectory($this->joplinPath);

        // Filter to only .md files (exclude .lock, .resource, .sync folders)
        $notes = [];
        foreach ($files as $file) {
            if (preg_match('/^[a-f0-9]{32}\.md$/', $file)) {
                $notes[] = $file;
            }
        }

        return $notes;
    }

    /**
     * Get a specific note by ID
     */
    public function getNote(string $noteId): ?array
    {
        $filename = $noteId.'.md';
        $content = $this->getFileContent($this->joplinPath.$filename);

        if (! $content) {
            return null;
        }

        return $this->parseNote($content);
    }

    /**
     * Search notes by content or title
     */
    public function searchNotes(string $query, int $limit = 10): array
    {
        $allNotes = $this->listNotes();
        $results = [];

        foreach ($allNotes as $filename) {
            $noteId = str_replace('.md', '', $filename);
            $note = $this->getNote($noteId);

            if (! $note || $note['type'] != 1) {
                continue; // Skip non-notes
            }

            // Search in title and content
            $searchText = strtolower($note['title'].' '.$note['content']);
            if (str_contains($searchText, strtolower($query))) {
                $results[] = [
                    'id' => $note['id'],
                    'title' => $note['title'],
                    'content' => substr($note['content'], 0, 200).'...',
                    'created_time' => $note['created_time'],
                    'updated_time' => $note['updated_time'],
                    'parent_id' => $note['parent_id'],
                ];

                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Get notebook/folder structure
     * Cached for 10 minutes to avoid slow WebDAV iteration
     */
    public function getNotebooks(): array
    {
        $cacheKey = 'joplin_notebooks_list';
        $cacheTTL = 600; // 10 minutes

        return Cache::remember($cacheKey, $cacheTTL, function () use ($cacheTTL) {
            $allNotes = $this->listNotes();
            $notebooks = [];

            foreach ($allNotes as $filename) {
                $noteId = str_replace('.md', '', $filename);
                $note = $this->getNote($noteId);

                if ($note && $note['type'] == 2) { // type_ 2 = notebook
                    $notebooks[] = [
                        'id' => $note['id'],
                        'title' => $note['title'],
                        'created_time' => $note['created_time'],
                    ];
                }
            }

            Log::info('Joplin notebooks cached', [
                'count' => count($notebooks),
                'cache_ttl' => $cacheTTL,
            ]);

            return $notebooks;
        });
    }

    /**
     * Get notes in a specific notebook
     */
    public function getNotesInNotebook(string $notebookId): array
    {
        $allNotes = $this->listNotes();
        $results = [];

        foreach ($allNotes as $filename) {
            $noteId = str_replace('.md', '', $filename);
            $note = $this->getNote($noteId);

            if ($note && $note['type'] == 1 && $note['parent_id'] == $notebookId) {
                $results[] = [
                    'id' => $note['id'],
                    'title' => $note['title'],
                    'created_time' => $note['created_time'],
                    'updated_time' => $note['updated_time'],
                ];
            }
        }

        return $results;
    }

    /**
     * Get attachment/resource info
     */
    public function getResource(string $resourceId): ?array
    {
        // Resources have metadata in .md files
        $note = $this->getNote($resourceId);

        if (! $note || $note['type'] != 4) {
            return null;
        }

        return [
            'id' => $note['id'],
            'filename' => $note['title'],
            'mime' => $note['mime'] ?? 'application/octet-stream',
            'size' => $note['size'] ?? 0,
            'created_time' => $note['created_time'],
            'file_extension' => $note['file_extension'] ?? '',
        ];
    }

    /**
     * Download attachment binary data
     */
    public function downloadResource(string $resourceId): ?string
    {
        $path = $this->joplinPath.'.resource/'.$resourceId;

        return $this->getFileContent($path);
    }

    /**
     * Parse a Joplin .md file into structured data
     *
     * Joplin format:
     * Title
     * (blank line)
     * Content...
     * (blank line)
     * id: xxx
     * parent_id: yyy
     * ...metadata fields...
     */
    protected function parseNote(string $content): array
    {
        $lines = explode("\n", $content);

        $data = [
            'title' => '',
            'id' => '',
            'parent_id' => '',
            'created_time' => '',
            'updated_time' => '',
            'type' => 0,
            'content' => '',
            'metadata' => [],
        ];

        // First, find where metadata starts (first line matching "key: value" pattern)
        $metadataStartIndex = -1;
        for ($i = 0; $i < count($lines); $i++) {
            if (preg_match('/^(id|parent_id|created_time|updated_time|type_|is_conflict|latitude|longitude):\s*/', $lines[$i])) {
                $metadataStartIndex = $i;
                break;
            }
        }

        // Parse title (first non-empty line)
        $titleFound = false;
        $contentStartIndex = 0;

        for ($i = 0; $i < count($lines); $i++) {
            if (! $titleFound && ! empty(trim($lines[$i]))) {
                $data['title'] = trim($lines[$i]);
                $titleFound = true;
                $contentStartIndex = $i + 1;
                break;
            }
        }

        // Extract content (between title and metadata)
        $contentEndIndex = $metadataStartIndex > 0 ? $metadataStartIndex : count($lines);
        $contentLines = array_slice($lines, $contentStartIndex, $contentEndIndex - $contentStartIndex);

        // Trim trailing blank lines from content
        while (count($contentLines) > 0 && trim($contentLines[count($contentLines) - 1]) === '') {
            array_pop($contentLines);
        }

        $data['content'] = implode("\n", $contentLines);

        // Parse metadata fields
        if ($metadataStartIndex >= 0) {
            for ($i = $metadataStartIndex; $i < count($lines); $i++) {
                if (preg_match('/^(\w+):\s*(.*)$/', $lines[$i], $matches)) {
                    $key = $matches[1];
                    $value = $matches[2];

                    if ($key === 'id') {
                        $data['id'] = $value;
                    } elseif ($key === 'parent_id') {
                        $data['parent_id'] = $value;
                    } elseif ($key === 'created_time') {
                        $data['created_time'] = $value;
                    } elseif ($key === 'updated_time') {
                        $data['updated_time'] = $value;
                    } elseif ($key === 'type_') {
                        $data['type'] = (int) $value;
                    } else {
                        $data['metadata'][$key] = $value;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * List files in directory (filesystem-first, WebDAV fallback)
     */
    protected function listDirectory(string $path): array
    {
        // Filesystem-first
        if ($this->localPath && $path === $this->joplinPath) {
            $files = [];
            foreach (scandir($this->localPath) as $file) {
                if ($file !== '.' && $file !== '..' && ! str_starts_with($file, '.')) {
                    $files[] = $file;
                }
            }

            return $files;
        }

        // WebDAV fallback
        $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$path;

        $response = Http::connectTimeout(5)->timeout(5)
            ->withBasicAuth($this->username, $this->password)
            ->withHeaders(['Depth' => '1'])
            ->send('PROPFIND', $url);

        if (! $response->successful()) {
            Log::error('Failed to list Joplin directory', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            return [];
        }

        // Parse WebDAV XML response
        preg_match_all('/<d:href>([^<]+)<\/d:href>/', $response->body(), $matches);

        $files = [];
        foreach ($matches[1] as $href) {
            $basename = basename($href);
            if ($basename && $basename !== basename($path)) {
                $files[] = $basename;
            }
        }

        return $files;
    }

    /**
     * Get file content (filesystem-first, WebDAV fallback)
     */
    protected function getFileContent(string $path): ?string
    {
        // Filesystem-first
        $localFile = JoplinPaths::localFile($this->localPath, $this->joplinPath, $path);
        if ($localFile) {
            if (file_exists($localFile) && is_readable($localFile)) {
                return file_get_contents($localFile);
            }
        }

        // WebDAV fallback
        $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$path;

        $response = Http::connectTimeout(5)->timeout(5)
            ->withBasicAuth($this->username, $this->password)
            ->get($url);

        if (! $response->successful()) {
            return null;
        }

        return $response->body();
    }

    /**
     * Get service status
     */
    public function getStatus(): array
    {
        $notes = $this->listNotes();
        $notebooks = $this->getNotebooks();

        return [
            'available' => true,
            'total_files' => count($notes),
            'notebooks' => count($notebooks),
            'source' => 'Nextcloud WebDAV ('.$this->baseUrl.')',
            'path' => $this->joplinPath,
        ];
    }
}
