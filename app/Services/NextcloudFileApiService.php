<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nextcloud File API Service
 *
 * Handles Nextcloud file operations with fileid support:
 * - Get file info including fileid
 * - Direct download URL generation via OCS API
 * - File search by fileid
 * - WebDAV operations (move, copy, delete)
 *
 * @see https://docs.nextcloud.com/server/stable/developer_manual/client_apis/OCS/ocs-api-overview.html
 * @see https://docs.nextcloud.com/server/latest/developer_manual/client_apis/WebDAV/basic.html
 */
class NextcloudFileApiService
{
    private string $baseUrl;

    private string $username;

    private string $password;

    private ?string $dataPath;

    private const HTTP_CONNECT_TIMEOUT = 5;

    private const HTTP_TIMEOUT = 120;

    /** Cache TTLs */
    private const CACHE_TTL_DIRECT_URL = 3600; // 1 hour (URLs valid for 8 hours)

    /** MIME types by extension (common subset for filesystem mode) */
    private const MIME_MAP = [
        'pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odp' => 'application/vnd.oasis.opendocument.presentation',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'md' => 'text/markdown', 'html' => 'text/html', 'htm' => 'text/html', 'rtf' => 'application/rtf',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jfif' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'bmp' => 'image/bmp', 'webp' => 'image/webp',
        'tiff' => 'image/tiff', 'tif' => 'image/tiff', 'jp2' => 'image/jp2', 'j2k' => 'image/jp2', 'jpf' => 'image/jp2', 'jpx' => 'image/jp2', 'heic' => 'image/heic', 'heif' => 'image/heif', 'svg' => 'image/svg+xml',
        'mp4' => 'video/mp4', 'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'flac' => 'audio/flac', 'm4a' => 'audio/mp4',
        'json' => 'application/json', 'xml' => 'application/xml', 'zip' => 'application/zip', 'gz' => 'application/gzip',
    ];

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.nextcloud.url', ''), '/');
        $this->username = (string) config('services.nextcloud.username', '');
        $this->password = config('services.nextcloud.password') ?? '';
        $this->dataPath = config('services.nextcloud.data_path') ?: null;
    }

    /**
     * Resolve Nextcloud path to local filesystem path.
     * Returns null if direct access not available or path doesn't exist.
     */
    public function localPath(string $ncPath): ?string
    {
        if (! $this->dataPath) {
            return null;
        }

        $ncPath = '/'.ltrim($ncPath, '/');
        $fullPath = rtrim($this->dataPath, '/').$ncPath;

        return file_exists($fullPath) ? $fullPath : null;
    }

    /**
     * Get the base filesystem path (for scanning dirs that may not exist yet in registry).
     * Returns null if direct access not configured.
     */
    private function fsBasePath(string $ncPath): ?string
    {
        if (! $this->dataPath || ! is_dir($this->dataPath)) {
            return null;
        }

        $ncPath = '/'.ltrim($ncPath, '/');

        return rtrim($this->dataPath, '/').$ncPath;
    }

    /**
     * Guess MIME type from extension (no network call needed).
     */
    private function guessMimeType(string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::MIME_MAP[$ext] ?? null;
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT)
            ->timeout(self::HTTP_TIMEOUT)
            ->withBasicAuth($this->username, $this->password);
    }

    /**
     * Get file info including Nextcloud's internal fileid
     *
     * @param  string  $path  Path relative to user root
     * @return array File info with fileid, size, mime_type, etc.
     */
    public function getFileInfo(string $path): array
    {
        $path = '/'.ltrim($path, '/');

        // Filesystem-first: stat() is ~1000x faster than WebDAV PROPFIND
        $localFile = $this->localPath($path);
        if ($localFile) {
            try {
                $stat = stat($localFile);
                if ($stat !== false) {
                    return [
                        'success' => true,
                        'path' => $path,
                        'fileid' => null, // Not available from filesystem — populated later via WebDAV if needed
                        'size' => $stat['size'],
                        'mime_type' => $this->guessMimeType(basename($localFile)) ?? mime_content_type($localFile) ?: null,
                        'etag' => null,
                        'last_modified' => gmdate('D, d M Y H:i:s \G\M\T', $stat['mtime']),
                        'source' => 'filesystem',
                    ];
                }
            } catch (Exception $e) {
                // Fall through to WebDAV
                Log::debug('NextcloudFileApi: filesystem stat failed, falling back to WebDAV', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }

        // WebDAV fallback
        $url = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$path}";

        try {
            $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:prop>
    <oc:fileid/>
    <d:getcontentlength/>
    <d:getcontenttype/>
    <d:getetag/>
    <d:getlastmodified/>
    <oc:size/>
    <nc:has-preview/>
  </d:prop>
</d:propfind>
XML;

            $response = $this->http()
                ->withHeaders([
                    'Content-Type' => 'application/xml; charset=utf-8',
                    'Depth' => '0',
                ])
                ->send('PROPFIND', $url, ['body' => $xml]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => 'PROPFIND failed: HTTP '.$response->status(),
                    'path' => $path,
                ];
            }

            return $this->parsePropfindResponse($response->body(), $path);

        } catch (Exception $e) {
            Log::error('NextcloudFileApi: getFileInfo failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'path' => $path,
            ];
        }
    }

    /**
     * Parse PROPFIND response for file properties
     */
    private function parsePropfindResponse(string $xml, string $path): array
    {
        try {
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument;
            $loaded = $dom->loadXML($xml);

            if (! $loaded) {
                return ['success' => false, 'error' => 'Failed to parse XML', 'path' => $path];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('oc', 'http://owncloud.org/ns');
            $xpath->registerNamespace('nc', 'http://nextcloud.org/ns');

            $response = $xpath->query('//d:response')->item(0);
            if (! $response) {
                return ['success' => false, 'error' => 'No response element', 'path' => $path];
            }

            $fileidNode = $xpath->query('.//oc:fileid', $response)->item(0);
            $sizeNode = $xpath->query('.//d:getcontentlength', $response)->item(0);
            $ocSizeNode = $xpath->query('.//oc:size', $response)->item(0);
            $mimeNode = $xpath->query('.//d:getcontenttype', $response)->item(0);
            $etagNode = $xpath->query('.//d:getetag', $response)->item(0);
            $lastModNode = $xpath->query('.//d:getlastmodified', $response)->item(0);

            return [
                'success' => true,
                'path' => $path,
                'fileid' => $fileidNode ? (int) $fileidNode->nodeValue : null,
                'size' => $sizeNode ? (int) $sizeNode->nodeValue : ($ocSizeNode ? (int) $ocSizeNode->nodeValue : 0),
                'mime_type' => $mimeNode ? $mimeNode->nodeValue : null,
                'etag' => $etagNode ? trim($etagNode->nodeValue, '"') : null,
                'last_modified' => $lastModNode ? $lastModNode->nodeValue : null,
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'path' => $path];
        }
    }

    /**
     * Get direct download URL using Nextcloud's OCS Direct Download API
     *
     * This creates a temporary URL (valid for 8 hours) that doesn't require authentication.
     *
     * @param  int  $fileid  Nextcloud internal file ID
     * @return array Result with URL
     */
    public function getDirectDownloadUrl(int $fileid): array
    {
        // Check cache first
        $cacheKey = "nc:direct:{$fileid}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return [
                'success' => true,
                'url' => $cached['url'],
                'expires_at' => $cached['expires_at'],
                'from_cache' => true,
            ];
        }

        try {
            $url = "{$this->baseUrl}/ocs/v2.php/apps/dav/api/v1/direct";

            $response = $this->http()
                ->withHeaders([
                    'OCS-APIRequest' => 'true',
                    'Accept' => 'application/json',
                ])
                ->post($url, [
                    'fileId' => $fileid,
                ]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => 'OCS API failed: HTTP '.$response->status(),
                    'fileid' => $fileid,
                ];
            }

            $data = $response->json();
            $directUrl = $data['ocs']['data']['url'] ?? null;

            if (! $directUrl) {
                return [
                    'success' => false,
                    'error' => 'No direct URL in response',
                    'fileid' => $fileid,
                    'response' => $data,
                ];
            }

            // Cache for 1 hour (URLs valid for 8 hours, but refresh early)
            $expiresAt = now()->addHours(7)->toIso8601String();
            Cache::put($cacheKey, [
                'url' => $directUrl,
                'expires_at' => $expiresAt,
            ], self::CACHE_TTL_DIRECT_URL);

            Log::debug('NextcloudFileApi: Direct download URL generated', [
                'fileid' => $fileid,
                'expires_at' => $expiresAt,
            ]);

            return [
                'success' => true,
                'url' => $directUrl,
                'expires_at' => $expiresAt,
                'from_cache' => false,
            ];

        } catch (Exception $e) {
            Log::error('NextcloudFileApi: getDirectDownloadUrl failed', [
                'fileid' => $fileid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'fileid' => $fileid,
            ];
        }
    }

    /**
     * Get WebDAV URL for a file (requires authentication)
     *
     * @param  string  $path  Path relative to user root
     * @return array Result with URL
     */
    public function getWebDavUrl(string $path): array
    {
        $path = '/'.ltrim($path, '/');
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));

        return [
            'success' => true,
            'url' => "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$encodedPath}",
            'requires_auth' => true,
        ];
    }

    /**
     * Check if a file exists at the given path
     *
     * @param  string  $path  Path relative to user root
     * @return bool File exists
     */
    public function fileExists(string $path): bool
    {
        $path = '/'.ltrim($path, '/');

        // Filesystem-first
        if ($this->localPath($path) !== null) {
            return true;
        }

        // WebDAV fallback (handles case where filesystem not configured)
        if (! $this->dataPath) {
            $url = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$path}";
            try {
                $response = $this->http()
                    ->withHeaders(['Depth' => '0'])
                    ->send('PROPFIND', $url);

                return $response->successful();
            } catch (Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Find a file by its fileid using WebDAV SEARCH
     *
     * @param  int  $fileid  Nextcloud internal file ID
     * @return array Result with path if found
     */
    public function findFileByFileid(int $fileid): array
    {
        try {
            // Use WebDAV SEARCH to find file by fileid
            $url = "{$this->baseUrl}/remote.php/dav/files/{$this->username}";

            $xml = <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<d:searchrequest xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:basicsearch>
    <d:select>
      <d:prop>
        <d:displayname/>
        <oc:fileid/>
      </d:prop>
    </d:select>
    <d:from>
      <d:scope>
        <d:href>/files/{$this->username}</d:href>
        <d:depth>infinity</d:depth>
      </d:scope>
    </d:from>
    <d:where>
      <d:eq>
        <d:prop><oc:fileid/></d:prop>
        <d:literal>{$fileid}</d:literal>
      </d:eq>
    </d:where>
  </d:basicsearch>
</d:searchrequest>
XML;

            $response = $this->http()
                ->withHeaders([
                    'Content-Type' => 'application/xml; charset=utf-8',
                ])
                ->send('SEARCH', $url, ['body' => $xml]);

            if ($response->status() === 207) {
                // Parse response to get path
                $path = $this->parseSearchResponse($response->body());
                if ($path) {
                    return [
                        'success' => true,
                        'fileid' => $fileid,
                        'path' => $path,
                    ];
                }
            }

            // SEARCH not supported or file not found, try alternative approach
            // List all files and search (slower but more compatible)
            return $this->findFileByFileidFallback($fileid);

        } catch (Exception $e) {
            Log::error('NextcloudFileApi: findFileByFileid failed', [
                'fileid' => $fileid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'fileid' => $fileid,
            ];
        }
    }

    /**
     * Fallback method to find file by fileid using PROPFIND traversal
     * This is slower but works on all Nextcloud versions
     */
    private function findFileByFileidFallback(int $fileid): array
    {
        // This is a simplified version - for production, you might want to
        // implement a more efficient search or use Nextcloud's search API
        Log::info('NextcloudFileApi: Using fallback fileid search', ['fileid' => $fileid]);

        return [
            'success' => false,
            'error' => 'File not found (fallback search not implemented)',
            'fileid' => $fileid,
        ];
    }

    /**
     * Parse SEARCH response to extract file path
     */
    private function parseSearchResponse(string $xml): ?string
    {
        try {
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument;
            $loaded = $dom->loadXML($xml);

            if (! $loaded) {
                return null;
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');

            $hrefNode = $xpath->query('//d:response/d:href')->item(0);
            if (! $hrefNode) {
                return null;
            }

            $href = $hrefNode->nodeValue;
            // Extract path from href (remove /remote.php/dav/files/username prefix)
            $prefix = "/remote.php/dav/files/{$this->username}";
            if (strpos($href, $prefix) === 0) {
                return urldecode(substr($href, strlen($prefix)));
            }

            return urldecode($href);

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Compute SHA-256 hash of file content
     *
     * @param  string  $path  Path relative to user root
     * @return array Result with hash
     */
    public function computeFileHash(string $path): array
    {
        $path = '/'.ltrim($path, '/');

        // Filesystem-first: hash_file() is dramatically faster than streaming over WebDAV
        $localFile = $this->localPath($path);
        if ($localFile) {
            try {
                $hash = hash_file('sha256', $localFile);
                if ($hash !== false) {
                    return [
                        'success' => true,
                        'hash' => $hash,
                        'path' => $path,
                        'size' => filesize($localFile),
                        'source' => 'filesystem',
                    ];
                }
            } catch (Exception $e) {
                Log::debug('NextcloudFileApi: filesystem hash failed, falling back to WebDAV', ['path' => $path]);
            }
        }

        // WebDAV fallback
        $url = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$path}";

        try {
            $response = $this->http()
                ->withOptions(['stream' => true])
                ->get($url);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => 'GET failed: HTTP '.$response->status(),
                    'path' => $path,
                ];
            }

            $hashContext = hash_init('sha256');
            $body = $response->body();
            hash_update($hashContext, $body);
            $hash = hash_final($hashContext);

            return [
                'success' => true,
                'hash' => $hash,
                'path' => $path,
                'size' => strlen($body),
            ];

        } catch (Exception $e) {
            Log::error('NextcloudFileApi: computeFileHash failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'path' => $path,
            ];
        }
    }

    /**
     * List files in a directory with fileid
     *
     * @param  string  $path  Directory path relative to user root
     * @param  bool  $recursive  Include subdirectories (use iterative mode for large directories)
     * @param  int  $timeout  Timeout in seconds (default 120 for recursive, 30 for non-recursive)
     * @param  int  $limit  Maximum files to return (0 = unlimited, only works for recursive)
     * @return array List of files with fileid
     */
    public function listFiles(string $path = '/', bool $recursive = false, int $timeout = 0, int $limit = 0): array
    {
        // Filesystem-first for recursive scans (orders of magnitude faster)
        if ($recursive) {
            $fsPath = $this->fsBasePath($path);
            if ($fsPath && is_dir($fsPath)) {
                $result = $this->listFilesFilesystem($path, $fsPath, $timeout ?: 300, $limit);
                if ($result['success'] && ! empty($result['files'])) {
                    return $result;
                }
                // Fall through to WebDAV if filesystem scan returned empty or failed
                Log::warning('NextcloudFileApi: Filesystem scan failed or empty, falling back to WebDAV', [
                    'path' => $path,
                    'error' => $result['error'] ?? 'empty',
                ]);
            }

            return $this->listFilesIterative($path, $timeout ?: 300, $limit);
        }

        $path = '/'.ltrim($path, '/');
        $url = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$path}";

        // Default timeout: 30s for single folder, higher for recursive (handled above)
        $requestTimeout = $timeout ?: 30;

        try {
            $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <d:displayname/>
    <d:resourcetype/>
    <oc:fileid/>
    <d:getcontentlength/>
    <d:getcontenttype/>
    <d:getlastmodified/>
  </d:prop>
</d:propfind>
XML;

            $response = Http::connectTimeout(5)->timeout($requestTimeout)
                ->withBasicAuth($this->username, $this->password)
                ->withHeaders([
                    'Content-Type' => 'application/xml; charset=utf-8',
                    'Depth' => '1',  // Always depth 1 for single listing
                ])
                ->send('PROPFIND', $url, ['body' => $xml]);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => 'PROPFIND failed: HTTP '.$response->status(),
                    'path' => $path,
                ];
            }

            return $this->parseListFilesResponse($response->body(), $path);

        } catch (Exception $e) {
            Log::error('NextcloudFileApi: listFiles failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'path' => $path,
            ];
        }
    }

    /**
     * List files via direct filesystem access — manual recursion with symlink-loop
     * guard, per-entry error tracking, timeout/limit flags, and completeness signal.
     *
     * Orders of magnitude faster than WebDAV PROPFIND. Returns same structure as
     * listFilesIterative() for drop-in compatibility (success/files/count/path),
     * plus extended status fields consumed by listFilesWithStatus():
     *   - complete (bool): true only if every directory was fully walked
     *   - scan_errors (array<string>): messages for unreadable/unstat-able entries
     *   - scan_errors_count (int): total per-entry errors encountered
     *   - scan_truncated (bool): true if stopped early by timeout or limit
     *   - scan_truncated_reason (string|null): 'timeout', 'limit', or null
     *   - symlink_loops_skipped (int): cycles detected via visited-inode set
     *
     * @param  string  $ncRootPath  Nextcloud-relative root (e.g. '/Library')
     * @param  string  $fsRootPath  Absolute filesystem path
     * @param  int  $timeout  Total timeout in seconds
     * @param  int  $limit  Maximum files to return (0 = unlimited)
     */
    private function listFilesFilesystem(string $ncRootPath, string $fsRootPath, int $timeout = 300, int $limit = 0): array
    {
        $ncRootPath = '/'.ltrim($ncRootPath, '/');
        $allFiles = [];
        $scanErrorMessages = [];
        $scanErrorsCount = 0;
        $symlinkLoopsSkipped = 0;
        $truncated = false;
        $truncatedReason = null;
        $startTime = time();
        $dataPathLen = strlen(rtrim($this->dataPath, '/'));
        $maxDepth = 32; // hard safety net in addition to inode set
        $maxErrorMessages = 25; // cap how many error strings we retain for reporting

        // Visited real directory inodes — primary symlink-loop guard.
        // Key is "device:inode" from stat() on the realpath; covers both hard and soft cycles.
        $visitedInodes = [];

        Log::info('NextcloudFileApi: Starting filesystem scan', [
            'path' => $ncRootPath,
            'fs_path' => $fsRootPath,
            'limit' => $limit,
        ]);

        // Manual depth-first walk (replaces RecursiveDirectoryIterator with FOLLOW_SYMLINKS,
        // which had no cycle detection). Stack entries: [absolutePath, depth].
        $stack = [[rtrim($fsRootPath, '/'), 0]];
        $rootReal = @realpath($fsRootPath);
        if ($rootReal !== false) {
            $rootStat = @stat($rootReal);
            if ($rootStat !== false) {
                $visitedInodes[$rootStat['dev'].':'.$rootStat['ino']] = true;
            }
        }

        $recordError = function (string $message) use (&$scanErrorMessages, &$scanErrorsCount, $maxErrorMessages): void {
            $scanErrorsCount++;
            if (count($scanErrorMessages) < $maxErrorMessages) {
                $scanErrorMessages[] = $message;
            }
        };

        while (! empty($stack)) {
            // Timeout check — mark as incomplete rather than silently returning.
            if ((time() - $startTime) > $timeout) {
                $truncated = true;
                $truncatedReason = 'timeout';
                Log::warning('NextcloudFileApi: Filesystem scan timeout', [
                    'path' => $ncRootPath,
                    'files_found' => count($allFiles),
                    'elapsed' => time() - $startTime,
                    'remaining_dirs' => count($stack),
                ]);
                break;
            }

            // Limit check — explicit truncation signal.
            if ($limit > 0 && count($allFiles) >= $limit) {
                $truncated = true;
                $truncatedReason = 'limit';
                break;
            }

            [$dir, $depth] = array_pop($stack);

            if ($depth > $maxDepth) {
                $recordError(sprintf('max_depth_exceeded: %s (depth=%d)', $dir, $depth));

                continue;
            }

            $handle = @opendir($dir);
            if ($handle === false) {
                $recordError(sprintf('opendir_failed: %s', $dir));

                continue;
            }

            try {
                while (($entry = readdir($handle)) !== false) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }

                    if ((time() - $startTime) > $timeout) {
                        $truncated = true;
                        $truncatedReason = 'timeout';
                        break;
                    }
                    if ($limit > 0 && count($allFiles) >= $limit) {
                        $truncated = true;
                        $truncatedReason = 'limit';
                        break;
                    }

                    $full = $dir.DIRECTORY_SEPARATOR.$entry;

                    // lstat (don't follow) so we can decide whether to traverse
                    $lstat = @lstat($full);
                    if ($lstat === false) {
                        $recordError(sprintf('lstat_failed: %s', $full));

                        continue;
                    }

                    $isLink = is_link($full);
                    $isDir = is_dir($full); // follows symlinks — intentional, we want to classify target

                    if ($isDir) {
                        // Symlink-loop guard: resolve real path + stat; skip if we've already visited this inode.
                        $realDir = @realpath($full);
                        if ($realDir === false) {
                            $recordError(sprintf('realpath_failed: %s', $full));

                            continue;
                        }
                        $dirStat = @stat($realDir);
                        if ($dirStat === false) {
                            $recordError(sprintf('stat_failed: %s', $realDir));

                            continue;
                        }
                        $key = $dirStat['dev'].':'.$dirStat['ino'];
                        if (isset($visitedInodes[$key])) {
                            if ($isLink) {
                                $symlinkLoopsSkipped++;
                            }

                            continue;
                        }
                        $visitedInodes[$key] = true;
                        $stack[] = [$realDir, $depth + 1];

                        continue;
                    }

                    // Regular file branch — match prior WebDAV contract (dirs excluded).
                    if (! is_file($full)) {
                        // e.g. broken symlink, socket, fifo
                        $recordError(sprintf('skipped_non_regular: %s', $full));

                        continue;
                    }
                    if (! is_readable($full)) {
                        $recordError(sprintf('unreadable_file: %s', $full));

                        continue;
                    }

                    $size = @filesize($full);
                    $mtime = @filemtime($full);
                    if ($size === false || $mtime === false) {
                        $recordError(sprintf('stat_failed: %s', $full));

                        continue;
                    }

                    // Convert absolute filesystem path back to Nextcloud-relative path
                    $ncPath = substr($full, $dataPathLen);

                    $allFiles[] = [
                        'path' => $ncPath,
                        'fileid' => null, // Not available from filesystem
                        'is_directory' => false,
                        'size' => $size,
                        'mime_type' => $this->guessMimeType($entry),
                        'last_modified' => gmdate('D, d M Y H:i:s \G\M\T', $mtime),
                    ];

                    if (count($allFiles) % 5000 === 0) {
                        Log::info('NextcloudFileApi: Filesystem scan progress', [
                            'files_found' => count($allFiles),
                            'elapsed' => time() - $startTime,
                            'errors' => $scanErrorsCount,
                        ]);
                    }
                }
            } catch (Exception $e) {
                // Per-directory failure — track and keep scanning, but flag as incomplete.
                $recordError(sprintf('dir_scan_exception: %s: %s', $dir, $e->getMessage()));
            } finally {
                closedir($handle);
            }
        }

        $elapsed = time() - $startTime;
        $complete = ! $truncated && $scanErrorsCount === 0;

        Log::info('NextcloudFileApi: Filesystem scan complete', [
            'path' => $ncRootPath,
            'files_found' => count($allFiles),
            'errors' => $scanErrorsCount,
            'truncated' => $truncated,
            'truncated_reason' => $truncatedReason,
            'symlink_loops_skipped' => $symlinkLoopsSkipped,
            'complete' => $complete,
            'elapsed' => $elapsed,
        ]);

        // Hard-failure short-circuit: if we have literally zero files AND errors, allow WebDAV fallback
        // (matches old behavior). Any successful partial result returns success=true with complete=false.
        if (empty($allFiles) && $scanErrorsCount > 0 && ! $truncated) {
            return [
                'success' => false,
                'error' => 'filesystem_scan_all_entries_failed',
                'files' => [],
                'count' => 0,
                'path' => $ncRootPath,
                'source' => 'filesystem',
                'complete' => false,
                'scan_errors' => $scanErrorMessages,
                'scan_errors_count' => $scanErrorsCount,
                'scan_truncated' => false,
                'scan_truncated_reason' => null,
                'symlink_loops_skipped' => $symlinkLoopsSkipped,
                'elapsed_seconds' => $elapsed,
            ];
        }

        return [
            'success' => true,
            'files' => $allFiles,
            'count' => count($allFiles),
            'path' => $ncRootPath,
            'source' => 'filesystem',
            'elapsed_seconds' => $elapsed,
            'complete' => $complete,
            'scan_errors' => $scanErrorMessages,
            'scan_errors_count' => $scanErrorsCount,
            'scan_truncated' => $truncated,
            'scan_truncated_reason' => $truncatedReason,
            'symlink_loops_skipped' => $symlinkLoopsSkipped,
        ];
    }

    /**
     * List files iteratively via WebDAV (folder by folder) — FALLBACK when filesystem not available.
     *
     * Instead of requesting Depth: infinity, this method walks the directory tree
     * one level at a time, collecting files and queuing subdirectories.
     *
     * @param  string  $rootPath  Starting directory path
     * @param  int  $timeout  Total timeout in seconds
     * @param  int  $limit  Maximum files to return (0 = unlimited)
     * @return array List of files with fileid
     */
    private function listFilesIterative(string $rootPath, int $timeout = 300, int $limit = 0): array
    {
        $rootPath = '/'.ltrim($rootPath, '/');
        $allFiles = [];
        $queue = [$rootPath];
        $visitedFolders = [$rootPath => true]; // WebDAV loop guard (dedup folder paths)
        $startTime = time();
        $scanErrorMessages = [];
        $scanErrorsCount = 0;
        $truncated = false;
        $truncatedReason = null;
        $maxErrorMessages = 25;

        Log::info('NextcloudFileApi: Starting iterative scan', ['path' => $rootPath, 'limit' => $limit]);

        while (! empty($queue)) {
            // Check timeout
            if ((time() - $startTime) > $timeout) {
                Log::warning('NextcloudFileApi: Iterative scan timeout', [
                    'path' => $rootPath,
                    'files_found' => count($allFiles),
                    'elapsed' => time() - $startTime,
                ]);
                $truncated = true;
                $truncatedReason = 'timeout';
                break;
            }

            // Check file limit
            if ($limit > 0 && count($allFiles) >= $limit) {
                Log::info('NextcloudFileApi: File limit reached', [
                    'path' => $rootPath,
                    'files_found' => count($allFiles),
                    'limit' => $limit,
                ]);
                $truncated = true;
                $truncatedReason = 'limit';
                break;
            }

            $currentPath = array_shift($queue);
            $url = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$currentPath}";

            try {
                $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8" ?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <d:displayname/>
    <d:resourcetype/>
    <oc:fileid/>
    <d:getcontentlength/>
    <d:getcontenttype/>
    <d:getlastmodified/>
  </d:prop>
</d:propfind>
XML;

                $response = Http::connectTimeout(5)->timeout(60)
                    ->withBasicAuth($this->username, $this->password)
                    ->withHeaders([
                        'Content-Type' => 'application/xml; charset=utf-8',
                        'Depth' => '1',
                    ])
                    ->send('PROPFIND', $url, ['body' => $xml]);

                if (! $response->successful()) {
                    Log::warning('NextcloudFileApi: Failed to list folder', [
                        'path' => $currentPath,
                        'status' => $response->status(),
                    ]);
                    $scanErrorsCount++;
                    if (count($scanErrorMessages) < $maxErrorMessages) {
                        $scanErrorMessages[] = sprintf('propfind_http_%d: %s', $response->status(), $currentPath);
                    }

                    continue;
                }

                $result = $this->parseListFilesResponse($response->body(), $currentPath);

                if (! ($result['success'] ?? false)) {
                    $scanErrorsCount++;
                    if (count($scanErrorMessages) < $maxErrorMessages) {
                        $scanErrorMessages[] = sprintf('parse_failed: %s: %s', $currentPath, $result['error'] ?? 'unknown');
                    }

                    continue;
                }

                if (! empty($result['files'])) {
                    foreach ($result['files'] as $file) {
                        // Skip the parent folder itself
                        if ($file['path'] === $currentPath) {
                            continue;
                        }

                        if ($file['is_directory']) {
                            // Loop guard — don't re-queue a folder path we've already walked/queued
                            if (isset($visitedFolders[$file['path']])) {
                                continue;
                            }
                            $visitedFolders[$file['path']] = true;
                            $queue[] = $file['path'];
                        } else {
                            // Add file to results
                            $allFiles[] = $file;
                        }
                    }
                }

            } catch (Exception $e) {
                Log::warning('NextcloudFileApi: Error listing folder', [
                    'path' => $currentPath,
                    'error' => $e->getMessage(),
                ]);
                $scanErrorsCount++;
                if (count($scanErrorMessages) < $maxErrorMessages) {
                    $scanErrorMessages[] = sprintf('folder_exception: %s: %s', $currentPath, $e->getMessage());
                }
                // Continue with other folders
            }
        }

        $complete = ! $truncated && $scanErrorsCount === 0;

        Log::info('NextcloudFileApi: Iterative scan complete', [
            'path' => $rootPath,
            'files_found' => count($allFiles),
            'errors' => $scanErrorsCount,
            'truncated' => $truncated,
            'truncated_reason' => $truncatedReason,
            'complete' => $complete,
            'elapsed' => time() - $startTime,
        ]);

        return [
            'success' => true,
            'files' => $allFiles,
            'count' => count($allFiles),
            'path' => $rootPath,
            'source' => 'webdav',
            'complete' => $complete,
            'scan_errors' => $scanErrorMessages,
            'scan_errors_count' => $scanErrorsCount,
            'scan_truncated' => $truncated,
            'scan_truncated_reason' => $truncatedReason,
        ];
    }

    /**
     * Recursive listing with guaranteed status metadata.
     *
     * Unlike listFiles(), this method always returns the status keys
     * (`complete`, `scan_errors`, `scan_errors_count`, `scan_truncated`,
     * `scan_truncated_reason`, `symlink_loops_skipped`) so callers can
     * detect silent partial-scan conditions. Existing callers of listFiles()
     * continue to work unchanged.
     *
     * @param  string  $path  Directory path relative to user root
     * @param  int  $timeout  Total timeout in seconds (default 300)
     * @param  int  $limit  Max files to return (0 = unlimited)
     * @return array {
     *               success: bool,
     *               files: array,
     *               count: int,
     *               path: string,
     *               source: string,           // 'filesystem' | 'webdav'
     *               complete: bool,           // false if truncated or any entry errored
     *               scan_errors: string[],    // bounded list of per-entry error messages
     *               scan_errors_count: int,   // total errors (may exceed scan_errors size)
     *               scan_truncated: bool,
     *               scan_truncated_reason: ?string,  // 'timeout' | 'limit' | null
     *               symlink_loops_skipped: int,
     *               }
     */
    public function listFilesWithStatus(string $path = '/', int $timeout = 300, int $limit = 0): array
    {
        $result = $this->listFiles($path, true, $timeout, $limit);

        // listFiles() may dispatch to filesystem or webdav. Normalize so the contract is stable.
        $normalized = [
            'success' => (bool) ($result['success'] ?? false),
            'files' => (array) ($result['files'] ?? []),
            'count' => (int) ($result['count'] ?? 0),
            'path' => (string) ($result['path'] ?? $path),
            'source' => (string) ($result['source'] ?? 'unknown'),
            'complete' => array_key_exists('complete', $result)
                ? (bool) $result['complete']
                : (bool) ($result['success'] ?? false),
            'scan_errors' => array_values(array_map('strval', (array) ($result['scan_errors'] ?? []))),
            'scan_errors_count' => (int) ($result['scan_errors_count'] ?? (is_array($result['scan_errors'] ?? null) ? count($result['scan_errors']) : 0)),
            'scan_truncated' => (bool) ($result['scan_truncated'] ?? false),
            'scan_truncated_reason' => $result['scan_truncated_reason'] ?? null,
            'symlink_loops_skipped' => (int) ($result['symlink_loops_skipped'] ?? 0),
        ];

        if (! $normalized['success']) {
            $normalized['error'] = (string) ($result['error'] ?? 'unknown');
            $normalized['complete'] = false;
        }

        return $normalized;
    }

    /**
     * Parse PROPFIND response for file listing
     */
    private function parseListFilesResponse(string $xml, string $basePath): array
    {
        $files = [];

        try {
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument;
            $loaded = $dom->loadXML($xml);

            if (! $loaded) {
                return ['success' => false, 'error' => 'Failed to parse XML', 'path' => $basePath];
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('d', 'DAV:');
            $xpath->registerNamespace('oc', 'http://owncloud.org/ns');

            $responses = $xpath->query('//d:response');
            $prefix = "/remote.php/dav/files/{$this->username}";

            foreach ($responses as $response) {
                $hrefNode = $xpath->query('d:href', $response)->item(0);
                if (! $hrefNode) {
                    continue;
                }

                $href = urldecode($hrefNode->nodeValue);
                $filePath = strpos($href, $prefix) === 0 ? substr($href, strlen($prefix)) : $href;

                // Skip the directory itself
                if (rtrim($filePath, '/') === rtrim($basePath, '/')) {
                    continue;
                }

                $isDirectory = $xpath->query('.//d:resourcetype/d:collection', $response)->length > 0;
                $fileidNode = $xpath->query('.//oc:fileid', $response)->item(0);
                $sizeNode = $xpath->query('.//d:getcontentlength', $response)->item(0);
                $mimeNode = $xpath->query('.//d:getcontenttype', $response)->item(0);
                $displayNode = $xpath->query('.//d:displayname', $response)->item(0);
                $lastModNode = $xpath->query('.//d:getlastmodified', $response)->item(0);

                $files[] = [
                    'path' => $filePath,
                    'name' => $displayNode ? $displayNode->nodeValue : basename($filePath),
                    'is_directory' => $isDirectory,
                    'fileid' => $fileidNode ? (int) $fileidNode->nodeValue : null,
                    'size' => $sizeNode ? (int) $sizeNode->nodeValue : 0,
                    'mime_type' => $mimeNode ? $mimeNode->nodeValue : null,
                    'last_modified' => $lastModNode ? $lastModNode->nodeValue : null,
                ];
            }

            return [
                'success' => true,
                'path' => $basePath,
                'files' => $files,
                'count' => count($files),
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'path' => $basePath];
        }
    }

    /**
     * Copy a file via WebDAV COPY (server-side, no download needed)
     *
     * @param  string  $sourcePath  Source path relative to user root
     * @param  string  $destPath  Destination path relative to user root
     * @return array Result with success status
     */
    public function copyFile(string $sourcePath, string $destPath): array
    {
        $sourcePath = '/'.ltrim($sourcePath, '/');
        $destPath = '/'.ltrim($destPath, '/');

        $sourceEncoded = implode('/', array_map('rawurlencode', explode('/', $sourcePath)));
        $destEncoded = implode('/', array_map('rawurlencode', explode('/', $destPath)));

        $sourceUrl = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$sourceEncoded}";
        $destUrl = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$destEncoded}";

        try {
            // Ensure destination directory exists
            $destDir = dirname($destPath);
            $this->ensureDirectoryExists($destDir);

            $response = $this->http()
                ->withHeaders([
                    'Destination' => $destUrl,
                    'Overwrite' => 'F',
                ])
                ->send('COPY', $sourceUrl);

            if ($response->status() === 201 || $response->status() === 204) {
                Log::info('NextcloudFileApi: File copied', ['from' => $sourcePath, 'to' => $destPath]);

                return ['success' => true, 'from' => $sourcePath, 'to' => $destPath];
            }

            return [
                'success' => false,
                'error' => 'COPY failed: HTTP '.$response->status(),
                'from' => $sourcePath,
                'to' => $destPath,
            ];
        } catch (Exception $e) {
            Log::error('NextcloudFileApi: copyFile failed', [
                'from' => $sourcePath,
                'to' => $destPath,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'from' => $sourcePath, 'to' => $destPath];
        }
    }

    /**
     * Move a file via WebDAV MOVE
     *
     * @param  string  $sourcePath  Source path relative to user root
     * @param  string  $destPath  Destination path relative to user root
     * @return array Result with success status
     */
    public function moveFile(string $sourcePath, string $destPath): array
    {
        $sourcePath = '/'.ltrim($sourcePath, '/');
        $destPath = '/'.ltrim($destPath, '/');

        $sourceEncoded = implode('/', array_map('rawurlencode', explode('/', $sourcePath)));
        $destEncoded = implode('/', array_map('rawurlencode', explode('/', $destPath)));

        $sourceUrl = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$sourceEncoded}";
        $destUrl = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$destEncoded}";

        try {
            // Ensure destination directory exists
            $destDir = dirname($destPath);
            $this->ensureDirectoryExists($destDir);

            $response = $this->http()
                ->withHeaders([
                    'Destination' => $destUrl,
                    'Overwrite' => 'F',
                ])
                ->send('MOVE', $sourceUrl);

            if ($response->status() === 201 || $response->status() === 204) {
                Log::info('NextcloudFileApi: File moved', ['from' => $sourcePath, 'to' => $destPath]);

                return ['success' => true, 'from' => $sourcePath, 'to' => $destPath];
            }

            return [
                'success' => false,
                'error' => 'MOVE failed: HTTP '.$response->status(),
                'from' => $sourcePath,
                'to' => $destPath,
            ];
        } catch (Exception $e) {
            Log::error('NextcloudFileApi: moveFile failed', [
                'from' => $sourcePath,
                'to' => $destPath,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'from' => $sourcePath, 'to' => $destPath];
        }
    }

    /**
     * Ensure a directory exists in Nextcloud (MKCOL)
     */
    private function ensureDirectoryExists(string $path): void
    {
        $path = '/'.ltrim($path, '/');
        $segments = array_filter(explode('/', $path));
        $current = '';

        foreach ($segments as $segment) {
            $current .= '/'.$segment;
            $encodedPath = implode('/', array_map('rawurlencode', explode('/', $current)));
            $url = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$encodedPath}";

            try {
                $this->http()
                    ->send('MKCOL', $url);
                // 201 = created, 405 = already exists - both are fine
            } catch (Exception $e) {
                // Ignore errors - directory may already exist
            }
        }
    }

    /**
     * Get a preview/thumbnail from Nextcloud's preview API
     *
     * @param  int  $fileId  Nextcloud internal file ID
     * @param  int  $width  Desired width
     * @param  int  $height  Desired height
     * @return array Result with content (binary image data)
     */
    public function getPreviewUrl(int $fileId, int $width = 300, int $height = 300): array
    {
        try {
            $url = "{$this->baseUrl}/index.php/core/preview?fileId={$fileId}&x={$width}&y={$height}&a=true";

            $response = $this->http()
                ->timeout(30)
                ->get($url);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => 'Preview API failed: HTTP '.$response->status(),
                    'fileid' => $fileId,
                ];
            }

            return [
                'success' => true,
                'content' => $response->body(),
                'mime_type' => $response->header('Content-Type'),
                'fileid' => $fileId,
            ];
        } catch (Exception $e) {
            Log::warning('NextcloudFileApi: getPreviewUrl failed', [
                'fileid' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage(), 'fileid' => $fileId];
        }
    }

    /**
     * Get file content directly from filesystem (bypasses WebDAV)
     *
     * This method is optimized for production where Laravel runs on the same
     * server as Nextcloud and can access files directly via the filesystem.
     * Falls back to WebDAV if direct path not configured or file not found.
     *
     * @param  string  $path  Path relative to user files (e.g., '/Documents/file.txt')
     * @return string|null File content or null if not found
     */
    public function getFileContentDirect(string $path): ?string
    {
        $localFile = $this->localPath($path);
        if ($localFile && is_file($localFile)) {
            return file_get_contents($localFile);
        }

        // Fallback to WebDAV
        $result = $this->downloadFile($path);

        return $result['success'] ? $result['content'] : null;
    }

    /**
     * Check if direct filesystem access is available
     *
     * @return bool True if direct access is configured and directory exists
     */
    public function hasDirectAccess(): bool
    {
        return $this->dataPath && is_dir($this->dataPath);
    }

    /**
     * Download file content
     *
     * @param  string  $path  Path relative to user root
     * @return array Result with content
     */
    public function downloadFile(string $path): array
    {
        $path = '/'.ltrim($path, '/');
        // URL-encode path segments while preserving directory separators
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url = "{$this->baseUrl}/remote.php/dav/files/{$this->username}{$encodedPath}";

        try {
            $response = $this->http()
                ->get($url);

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'error' => 'GET failed: HTTP '.$response->status(),
                    'path' => $path,
                ];
            }

            return [
                'success' => true,
                'path' => $path,
                'content' => $response->body(),
                'size' => strlen($response->body()),
                'mime_type' => $response->header('Content-Type'),
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'path' => $path,
            ];
        }
    }
}
