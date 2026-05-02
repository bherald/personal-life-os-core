<?php

namespace App\Services;

use App\Support\JoplinPaths;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Joplin Tags Service
 *
 * Handles tag management for Joplin notes.
 * Tags are stored as type_=5 items in Joplin with note references.
 * Independent PLOS interoperability adapter for an operator-managed sync target;
 * it does not use upstream Joplin application or server source code.
 */
class JoplinTagsService
{
    protected string $baseUrl;

    protected string $username;

    protected string $password;

    protected string $joplinPath = '/Joplin-data/';

    protected ?string $localPath = null;

    private const HTTP_CONNECT_TIMEOUT = 5;

    private const HTTP_TIMEOUT = 120;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.nextcloud.url') ?? '', '/');
        $this->username = config('services.nextcloud.username') ?? '';
        $this->password = config('services.nextcloud.password') ?? '';
        $this->joplinPath = JoplinPaths::syncPath(true);

        $this->localPath = JoplinPaths::localRoot();
    }

    private function getLocalFilePath(string $path): ?string
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
     * Get all tags
     *
     * @return array Array of tags with their IDs and titles
     */
    public function getAllTags(): array
    {
        $files = $this->listDirectory($this->joplinPath);
        $tags = [];

        foreach ($files as $file) {
            if (preg_match('/^[a-f0-9]{32}\.md$/', $file)) {
                $content = $this->getFileContent($this->joplinPath.$file);
                if ($content) {
                    $parsed = $this->parseFile($content);
                    if ($parsed && $parsed['type'] == 5) { // Type 5 = Tag
                        $tags[] = [
                            'id' => $parsed['id'],
                            'title' => $parsed['title'],
                        ];
                    }
                }
            }
        }

        return $tags;
    }

    /**
     * Create a new tag
     *
     * @param  string  $title  Tag title
     * @return array Tag info
     */
    public function createTag(string $title): array
    {
        $tagId = $this->generateJoplinId();
        $now = $this->getJoplinTimestamp();

        $tagContent = sprintf("%s\n\nid: %s\ncreated_time: %s\nupdated_time: %s\ntype_: 5\n---\n\n",
            $title,
            $tagId,
            $now,
            $now
        );

        $success = $this->writeFile($this->joplinPath.$tagId.'.md', $tagContent);

        if (! $success) {
            throw new \Exception('Failed to create tag');
        }

        Log::info('Created Joplin tag', ['tag_id' => $tagId, 'title' => $title]);

        return [
            'success' => true,
            'tag_id' => $tagId,
            'title' => $title,
        ];
    }

    /**
     * Add tag to note
     *
     * @param  string  $noteId  Note ID
     * @param  string  $tagId  Tag ID
     * @return array Result
     */
    public function addTagToNote(string $noteId, string $tagId): array
    {
        // Create note-tag relationship file (type_=6)
        $relationId = $this->generateJoplinId();
        $now = $this->getJoplinTimestamp();

        $relationContent = sprintf("\n\nid: %s\nnote_id: %s\ntag_id: %s\ncreated_time: %s\nupdated_time: %s\ntype_: 6\n---\n\n",
            $relationId,
            $noteId,
            $tagId,
            $now,
            $now
        );

        $success = $this->writeFile($this->joplinPath.$relationId.'.md', $relationContent);

        if (! $success) {
            throw new \Exception('Failed to add tag to note');
        }

        return [
            'success' => true,
            'relation_id' => $relationId,
        ];
    }

    /**
     * Get tags for a note
     *
     * @param  string  $noteId  Note ID
     * @return array Array of tag objects
     */
    public function getTagsForNote(string $noteId): array
    {
        $files = $this->listDirectory($this->joplinPath);
        $tagIds = [];

        // Find all note-tag relations for this note
        foreach ($files as $file) {
            if (preg_match('/^[a-f0-9]{32}\.md$/', $file)) {
                $content = $this->getFileContent($this->joplinPath.$file);
                if ($content) {
                    $parsed = $this->parseFile($content);
                    if ($parsed && $parsed['type'] == 6) { // Type 6 = Note-Tag relation
                        if (($parsed['note_id'] ?? null) === $noteId) {
                            $tagIds[] = $parsed['tag_id'] ?? null;
                        }
                    }
                }
            }
        }

        // Get tag details
        $tags = [];
        $allTags = $this->getAllTags();

        foreach ($allTags as $tag) {
            if (in_array($tag['id'], $tagIds)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    // Helper methods

    private function listDirectory(string $path): array
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

        $response = $this->http()
            ->withHeaders(['Depth' => '1'])
            ->withBody('<?xml version="1.0"?>
                <d:propfind xmlns:d="DAV:">
                    <d:prop><d:resourcetype/></d:prop>
                </d:propfind>', 'application/xml')
            ->send('PROPFIND', $url);

        if (! $response->successful()) {
            return [];
        }

        $xml = simplexml_load_string($response->body());
        if (! $xml) {
            return [];
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $files = [];

        foreach ($xml->xpath('//d:response') as $item) {
            $href = (string) $item->xpath('d:href')[0];
            if (preg_match('/\/([^\/]+)$/', $href, $matches)) {
                $files[] = $matches[1];
            }
        }

        return $files;
    }

    private function getFileContent(string $path): ?string
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

        $response = $this->http()->get($url);

        return $response->successful() ? $response->body() : null;
    }

    private function writeFile(string $path, string $content): bool
    {
        $localFile = $this->getLocalFilePath($path);
        if ($localFile) {
            $directory = dirname($localFile);
            if (is_dir($directory) && is_writable($directory) && (! file_exists($localFile) || is_writable($localFile))) {
                $written = @file_put_contents($localFile, $content, LOCK_EX);
                if ($written !== false) {
                    return true;
                }

                Log::warning('JoplinTagsService: filesystem write fallback to WebDAV', ['path' => $path]);
            }
        }

        $url = $this->baseUrl.'/remote.php/dav/files/'.$this->username.$path;

        $response = $this->http()
            ->withBody($content, 'text/plain')
            ->put($url);

        return $response->successful();
    }

    private function parseFile(string $content): ?array
    {
        $lines = explode("\n", $content);
        $metadata = [];
        $inMetadata = false;
        $titleLine = true;

        foreach ($lines as $line) {
            if ($titleLine && ! empty(trim($line))) {
                $metadata['title'] = trim($line);
                $titleLine = false;
                $inMetadata = true;

                continue;
            }

            if (trim($line) === '---') {
                break;
            }

            if ($inMetadata && preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);

                if ($key === 'type_') {
                    $metadata['type'] = (int) $value;
                } else {
                    $metadata[$key] = $value;
                }
            }
        }

        return $metadata;
    }

    private function generateJoplinId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function getJoplinTimestamp(): string
    {
        return now()->format('Y-m-d\TH:i:s.v\Z');
    }
}
