<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NextcloudFileApiService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MediaProxyController - Proxy files from Nextcloud via WebDAV
 *
 * E17/EA1: Provides authenticated access to Nextcloud files without
 * requiring the user to log into Nextcloud separately.
 */
class MediaProxyController extends Controller
{
    private const HTTP_CONNECT_TIMEOUT = 5;

    private const HTTP_TIMEOUT = 30;

    protected string $nextcloudUrl;

    protected string $nextcloudUser;

    protected string $nextcloudPassword;

    protected string $joplinPath;

    protected NextcloudFileApiService $nextcloudFileApi;

    public function __construct(NextcloudFileApiService $nextcloudFileApi)
    {
        $this->nextcloudUrl = rtrim((string) config('services.nextcloud.url', ''), '/');
        $this->nextcloudUser = (string) config('services.nextcloud.username', '');
        $this->nextcloudPassword = (string) config('services.nextcloud.password', '');
        $this->joplinPath = (string) config('services.nextcloud.joplin_path', '/Joplin-data');
        $this->nextcloudFileApi = $nextcloudFileApi;
    }

    /**
     * Serve a Joplin attachment by resource ID
     *
     * Looks up the attachment in the index to get the filename/extension,
     * then fetches it from Nextcloud via WebDAV and streams it to the browser.
     */
    public function getJoplinAttachment(string $resourceId)
    {
        // Validate resource ID format (32 hex chars)
        if (! preg_match('/^[a-f0-9]{32}$/i', $resourceId)) {
            return response()->json(['error' => 'Invalid resource ID format'], 400);
        }

        // Look up attachment in index to get filename
        $attachment = DB::selectOne(
            'SELECT * FROM joplin_attachment_index WHERE resource_id = ?',
            [$resourceId]
        );

        if (! $attachment) {
            return response()->json(['error' => 'Attachment not found in index'], 404);
        }

        // Joplin stores resources WITHOUT extension in Nextcloud
        // The extension is only in the filename metadata

        // Build WebDAV URL (no extension - Joplin stores files by resource ID only)
        $webdavUrl = $this->nextcloudUrl.'/remote.php/dav/files/'.$this->nextcloudUser
            .$this->joplinPath.'/.resource/'.$resourceId;

        return $this->proxyFile($webdavUrl, $attachment->filename);
    }

    /**
     * Serve a file by path (for future use with Windows files, etc.)
     */
    public function getFile(Request $request)
    {
        $path = $request->query('path');

        if (! $path) {
            return response()->json(['error' => 'Path parameter required'], 400);
        }

        // Security: prevent directory traversal
        if (str_contains($path, '..')) {
            return response()->json(['error' => 'Invalid path'], 400);
        }

        // Build WebDAV URL
        $webdavUrl = $this->nextcloudUrl.'/remote.php/dav/files/'.$this->nextcloudUser.'/'.ltrim($path, '/');

        return $this->proxyFile($webdavUrl, basename($path));
    }

    /**
     * Proxy a file from Nextcloud WebDAV to the browser
     */
    protected function proxyFile(string $webdavUrl, string $displayFilename)
    {
        try {
            $localPath = $this->resolveLocalPathFromWebdavUrl($webdavUrl);
            if ($localPath && is_file($localPath)) {
                return $this->streamLocalFile($localPath, $displayFilename);
            }

            // Fetch file from Nextcloud via WebDAV with Basic Auth
            $response = Http::withBasicAuth($this->nextcloudUser, $this->nextcloudPassword)
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT)
                ->timeout(self::HTTP_TIMEOUT)
                ->get($webdavUrl);

            if (! $response->successful()) {
                Log::warning('Failed to fetch file from Nextcloud', [
                    'url' => $webdavUrl,
                    'status' => $response->status(),
                ]);

                return response()->json([
                    'error' => 'File not found or access denied',
                    'status' => $response->status(),
                ], $response->status() >= 400 && $response->status() < 500 ? $response->status() : 502);
            }

            // Get content type - prefer our lookup since Nextcloud may return octet-stream
            $contentType = $this->getMimeType($displayFilename);
            if ($contentType === 'application/octet-stream') {
                // Fall back to response header if we don't recognize the extension
                $contentType = $response->header('Content-Type') ?? $contentType;
            }
            $contentLength = $response->header('Content-Length');

            // Determine if we should display inline or download
            $disposition = $this->shouldDisplayInline($contentType) ? 'inline' : 'attachment';

            // Build response headers
            $headers = [
                'Content-Type' => $contentType,
                'Content-Disposition' => $disposition.'; filename="'.$displayFilename.'"',
                'Cache-Control' => 'private, max-age=3600',
            ];

            if ($contentLength) {
                $headers['Content-Length'] = $contentLength;
            }

            return response($response->body(), 200, $headers);

        } catch (\Exception $e) {
            Log::error('Error proxying file from Nextcloud', [
                'url' => $webdavUrl,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to fetch file: '.$e->getMessage()], 500);
        }
    }

    protected function resolveLocalPathFromWebdavUrl(string $webdavUrl): ?string
    {
        $prefix = $this->nextcloudUrl.'/remote.php/dav/files/'.$this->nextcloudUser;
        if (! str_starts_with($webdavUrl, $prefix)) {
            return null;
        }

        $relativePath = substr($webdavUrl, strlen($prefix));
        if ($relativePath === false || $relativePath === '') {
            return null;
        }

        $decodedPath = implode('/', array_map('rawurldecode', explode('/', $relativePath)));

        return $this->nextcloudFileApi->localPath($decodedPath);
    }

    protected function streamLocalFile(string $localPath, string $displayFilename): Response
    {
        $contentType = $this->getMimeType($displayFilename);
        if ($contentType === 'application/octet-stream') {
            $contentType = mime_content_type($localPath) ?: $contentType;
        }

        $disposition = $this->shouldDisplayInline($contentType) ? 'inline' : 'attachment';

        return response(file_get_contents($localPath), 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => $disposition.'; filename="'.$displayFilename.'"',
            'Content-Length' => (string) filesize($localPath),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Determine if content type should be displayed inline
     */
    protected function shouldDisplayInline(string $contentType): bool
    {
        $inlineTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/jp2',
            'image/jpx',
            'image/svg+xml',
            'text/plain',
            'text/html',
            'video/mp4',
            'video/webm',
            'audio/mpeg',
            'audio/wav',
            'audio/ogg',
        ];

        foreach ($inlineTypes as $type) {
            if (str_starts_with($contentType, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get MIME type from filename
     */
    protected function getMimeType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg', 'jfif' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'jp2', 'j2k', 'jpf', 'jpx' => 'image/jp2',
            'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'txt' => 'text/plain',
            'html', 'htm' => 'text/html',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => 'application/octet-stream',
        };
    }
}
