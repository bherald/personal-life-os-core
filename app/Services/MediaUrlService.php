<?php

namespace App\Services;

/**
 * MediaUrlService - Centralized URL Generation for Media Files (E17/EA1)
 *
 * Generates Nextcloud WebDAV URLs for all media types across the framework.
 * Supports: Joplin attachments, Windows files (future Nextcloud migration), general files.
 *
 * URL Patterns:
 * - Joplin: {NEXTCLOUD_URL}/remote.php/dav/files/{user}/Joplin-data/.resource/{resourceId}
 * - Files: {NEXTCLOUD_URL}/remote.php/dav/files/{user}/{path}
 * - Windows (future): {NEXTCLOUD_URL}/remote.php/dav/files/{user}/{libraryRoot}/{relativePath}
 */
class MediaUrlService
{
    protected string $nextcloudUrl;

    protected string $nextcloudUser;

    protected string $joplinPath;

    protected string $windowsBasePath;

    public function __construct()
    {
        $this->nextcloudUrl = rtrim((string) config('services.nextcloud.url', ''), '/');
        $this->nextcloudUser = (string) config('services.nextcloud.username', '');
        $this->joplinPath = (string) config('services.nextcloud.joplin_path', '/Joplin-data');
        $this->windowsBasePath = (string) config('services.nextcloud.windows_base', '/Library');
    }

    /**
     * Generate URL for a Joplin attachment resource
     *
     * Uses the media proxy endpoint which handles WebDAV authentication.
     * Files are served directly without requiring Nextcloud login.
     */
    public function getJoplinAttachmentUrl(string $resourceId, ?string $filename = null): string
    {
        // Use our media proxy endpoint - handles WebDAV auth on backend
        return '/api/media/joplin/'.$resourceId;
    }

    /**
     * Generate WebDAV URL for a Joplin attachment (for backend/API use)
     */
    public function getJoplinAttachmentWebDavUrl(string $resourceId, ?string $filename = null): string
    {
        $baseUrl = $this->getWebDavBaseUrl();
        $resourcePath = ltrim($this->joplinPath, '/').'/.resource/'.$resourceId;

        if ($filename) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            if ($ext) {
                $resourcePath .= '.'.strtolower($ext);
            }
        }

        return $baseUrl.'/'.$resourcePath;
    }

    /**
     * Generate URL for a file in Nextcloud
     * Uses the media proxy endpoint for authenticated access
     */
    public function getNextcloudFileUrl(string $relativePath): string
    {
        $cleanPath = ltrim($relativePath, '/');

        return '/api/media/file?path='.rawurlencode($cleanPath);
    }

    /**
     * Generate URL for a Windows file (future Nextcloud migration)
     * Maps a configured Windows base path to {NEXTCLOUD}/{libraryRoot}/path/to/file
     * Uses the media proxy endpoint for authenticated access
     */
    public function getWindowsFileUrl(string $windowsPath): string
    {
        // Convert Windows path to a Nextcloud library path.
        $relativePath = $this->convertWindowsToNextcloudPath($windowsPath);
        $cleanPath = ltrim($relativePath, '/');

        return '/api/media/file?path='.rawurlencode($cleanPath);
    }

    /**
     * Generate a clickable link for any source media
     * Auto-detects type based on path/identifier
     */
    public function getMediaUrl(string $sourceIdentifier, string $type = 'auto'): ?string
    {
        if ($type === 'auto') {
            $type = $this->detectMediaType($sourceIdentifier);
        }

        return match ($type) {
            'joplin' => $this->getJoplinAttachmentUrl($sourceIdentifier),
            'windows' => $this->getWindowsFileUrl($sourceIdentifier),
            'nextcloud' => $this->getNextcloudFileUrl($sourceIdentifier),
            default => null,
        };
    }

    /**
     * Generate HTML link for media
     */
    public function getMediaLink(string $sourceIdentifier, ?string $displayText = null, string $type = 'auto'): string
    {
        $url = $this->getMediaUrl($sourceIdentifier, $type);
        if (! $url) {
            return htmlspecialchars($displayText ?? $sourceIdentifier);
        }

        $text = htmlspecialchars($displayText ?? basename($sourceIdentifier));

        return sprintf('<a href="%s" target="_blank" class="media-link">%s</a>', htmlspecialchars($url), $text);
    }

    /**
     * Parse a {{WINFILES}} placeholder and return Nextcloud URL
     * Format: {{WINFILES}}/relative/path/to/file.pdf
     */
    public function parseWinfilesPlaceholder(string $text): string
    {
        return preg_replace_callback(
            '/\{\{WINFILES\}\}([^\s\)\"\']+)/',
            function ($matches) {
                $relativePath = $matches[1];

                return $this->getWindowsFileUrl(str_replace('/', '\\', $relativePath));
            },
            $text
        );
    }

    /**
     * Get media metadata for a source
     */
    public function getMediaMetadata(string $sourceIdentifier, string $type = 'auto'): array
    {
        $url = $this->getMediaUrl($sourceIdentifier, $type);
        $detectedType = $type === 'auto' ? $this->detectMediaType($sourceIdentifier) : $type;

        $extension = strtolower(pathinfo($sourceIdentifier, PATHINFO_EXTENSION));
        $mimeType = $this->getMimeType($extension);
        $isPreviewable = $this->isPreviewable($extension);

        return [
            'url' => $url,
            'type' => $detectedType,
            'filename' => basename($sourceIdentifier),
            'extension' => $extension,
            'mime_type' => $mimeType,
            'is_previewable' => $isPreviewable,
            'is_image' => in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif']),
            'is_pdf' => $extension === 'pdf',
            'is_video' => in_array($extension, ['mp4', 'webm', 'mov', 'avi', 'mkv']),
            'is_audio' => in_array($extension, ['mp3', 'wav', 'ogg', 'm4a', 'flac']),
        ];
    }

    // === PROTECTED HELPER METHODS ===

    protected function getWebDavBaseUrl(): string
    {
        return $this->nextcloudUrl.'/remote.php/dav/files/'.$this->nextcloudUser;
    }

    protected function convertWindowsToNextcloudPath(string $windowsPath): string
    {
        $path = str_replace('\\', '/', $windowsPath);
        $basePath = str_replace('\\', '/', (string) config('services.windows_file.base_path', ''));
        $basePath = rtrim($basePath, '/');

        if ($basePath !== '' && str_starts_with(strtolower($path), strtolower($basePath.'/'))) {
            $path = substr($path, strlen($basePath) + 1);
        } else {
            $path = preg_replace('/^[A-Za-z]:\/?/', '', $path);
        }

        return $this->windowsBasePath.'/'.ltrim($path, '/');
    }

    protected function detectMediaType(string $identifier): string
    {
        // Check for Joplin resource ID pattern (32 hex chars)
        if (preg_match('/^[a-f0-9]{32}$/i', $identifier)) {
            return 'joplin';
        }

        // Check for Windows path
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $identifier) || str_starts_with($identifier, '{{WINFILES}}')) {
            return 'windows';
        }

        // Default to Nextcloud file
        return 'nextcloud';
    }

    protected function getMimeType(string $extension): string
    {
        return match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'tiff', 'tif' => 'image/tiff',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'html', 'htm' => 'text/html',
            'json' => 'application/json',
            'xml' => 'application/xml',
            default => 'application/octet-stream',
        };
    }

    protected function isPreviewable(string $extension): bool
    {
        return in_array($extension, [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
            'pdf', 'txt', 'html', 'htm',
            'mp4', 'webm', 'mp3', 'wav', 'ogg',
        ]);
    }

    /**
     * Get service configuration for diagnostics
     */
    public function getConfig(): array
    {
        return [
            'nextcloud_url' => $this->nextcloudUrl,
            'nextcloud_user' => $this->nextcloudUser,
            'joplin_path' => $this->joplinPath,
            'windows_base_path' => $this->windowsBasePath,
            'webdav_base' => $this->getWebDavBaseUrl(),
        ];
    }
}

/**
 * URL-encode path segments while preserving slashes
 */
function rawurlencode_path(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}
