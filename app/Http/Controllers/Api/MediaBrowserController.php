<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExifWritebackService;
use App\Services\FaceMatcherService;
use App\Services\FileRegistryLifecycleService;
use App\Services\FileRegistryService;
use App\Services\FileVersionService;
use App\Services\Genealogy\FaceCandidateDecisionService;
use App\Services\Genealogy\FaceCandidateService;
use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\ImageEditService;
use App\Services\ThumbnailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MediaBrowserController - API for media gallery and viewer
 *
 * Provides endpoints for:
 * - Media listing with filters (type, date, person, folder)
 * - Thumbnail delivery
 * - Media streaming (video/audio)
 * - Face management
 * - Folder navigation
 * - Image editing (rotate, flip, crop, resize, adjust)
 */
class MediaBrowserController extends Controller
{
    private ?ImageEditService $imageEditor = null;

    private ?FileVersionService $fileVersion = null;

    public function __construct(
        private FileRegistryService $fileRegistry,
        private FileRegistryLifecycleService $fileLifecycle,
        private ThumbnailService $thumbnailService,
        private FaceMatcherService $faceMatcher,
        private ExifWritebackService $exifWriteback
    ) {}

    private function getImageEditor(): ImageEditService
    {
        if ($this->imageEditor === null) {
            $this->imageEditor = app(ImageEditService::class);
        }

        return $this->imageEditor;
    }

    private function getFileVersion(): FileVersionService
    {
        if ($this->fileVersion === null) {
            $this->fileVersion = app(FileVersionService::class);
        }

        return $this->fileVersion;
    }

    /**
     * List media with filters and pagination
     *
     * GET /api/media
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 50), 200);
        $page = max((int) $request->get('page', 1), 1);
        $offset = ($page - 1) * $perPage;

        // Filters
        $type = $request->get('type'); // image, video, audio, document
        $folder = $request->get('folder');
        $person = $request->get('person');
        $search = $request->get('search');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');
        $hasFaces = $request->boolean('has_faces');
        $sort = $request->get('sort', 'date_desc');

        // Build query
        $where = "WHERE fr.status = 'active'";
        $params = [];
        $joins = '';

        // Type filter
        if ($type) {
            $mimeTypes = $this->getMimeTypesForType($type);
            if (! empty($mimeTypes)) {
                $placeholders = implode(',', array_fill(0, count($mimeTypes), '?'));
                $where .= " AND fr.mime_type IN ({$placeholders})";
                $params = array_merge($params, $mimeTypes);
            }
        }

        // Folder filter
        if ($folder) {
            $where .= ' AND fr.current_path LIKE ?';
            $params[] = $folder.'%';
        }

        // Person filter (via faces)
        if ($person) {
            $joins .= ' JOIN file_registry_faces frf ON frf.file_registry_id = fr.id';
            $where .= ' AND (frf.person_name LIKE ? OR frf.genealogy_person_id = ?)';
            $params[] = '%'.$person.'%';
            $params[] = is_numeric($person) ? (int) $person : 0;
        }

        // Search — FULLTEXT on AI data + LIKE fallback on filename/title/tags
        if ($search) {
            $where .= ' AND (fr.filename LIKE ? OR fr.title LIKE ? OR fr.tags LIKE ? OR MATCH(fr.ai_description, fr.ai_detected_text) AGAINST(? IN BOOLEAN MODE))';
            $searchTerm = '%'.$search.'%';
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $search]);
        }

        // Date range
        if ($dateFrom) {
            $where .= ' AND fr.nextcloud_modified_at >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $where .= ' AND fr.nextcloud_modified_at <= ?';
            $params[] = $dateTo.' 23:59:59';
        }

        // Has faces filter
        if ($hasFaces) {
            $where .= ' AND fr.face_count > 0';
        }

        // Sort
        $orderBy = match ($sort) {
            'date_asc' => 'fr.nextcloud_modified_at ASC',
            'name_asc' => 'fr.filename ASC',
            'name_desc' => 'fr.filename DESC',
            'size_asc' => 'fr.file_size ASC',
            'size_desc' => 'fr.file_size DESC',
            default => 'fr.nextcloud_modified_at DESC',
        };

        // Count total
        $countSql = "SELECT COUNT(DISTINCT fr.id) as total FROM file_registry fr {$joins} {$where}";
        $total = DB::selectOne($countSql, $params)->total ?? 0;

        // Fetch page
        $params[] = $perPage;
        $params[] = $offset;

        $sql = "
            SELECT DISTINCT
                fr.id,
                fr.asset_uuid,
                fr.filename,
                fr.current_path,
                fr.mime_type,
                fr.file_size,
                fr.nextcloud_modified_at,
                fr.title,
                fr.ai_description,
                fr.face_count,
                fr.thumbnail_sizes
            FROM file_registry fr
            {$joins}
            {$where}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?
        ";

        $items = DB::select($sql, $params);

        // Transform for frontend
        $mediaItems = array_map(function ($item) {
            $extension = pathinfo($item->filename, PATHINFO_EXTENSION);

            return [
                'id' => $item->id,
                'asset_uuid' => $item->asset_uuid,
                'uuid' => $item->asset_uuid,
                'filename' => $item->filename,
                'extension' => strtolower($extension),
                'current_path' => $item->current_path,
                'path' => $item->current_path,
                'type' => $this->getMediaType($item->mime_type),
                'mime_type' => $item->mime_type,
                'file_size' => $item->file_size,
                'size' => $item->file_size,
                'size_human' => $this->formatBytes($item->file_size),
                'media_date' => $item->nextcloud_modified_at,
                'date' => $item->nextcloud_modified_at,
                'title' => $item->title,
                'description' => $item->ai_description,
                'face_count' => (int) ($item->face_count ?? 0),
                'has_thumbnail' => ! empty($item->thumbnail_sizes),
                'thumbnail_url' => "/api/media/{$item->asset_uuid}/thumbnail/medium",
            ];
        }, $items);

        return response()->json([
            'success' => true,
            'data' => $mediaItems,
            'total' => $total,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * Get single media item details
     *
     * GET /api/media/{uuid}
     */
    public function show(string $uuid): JsonResponse
    {
        $item = DB::selectOne("
            SELECT fr.*, GROUP_CONCAT(frf.person_name) as face_names
            FROM file_registry fr
            LEFT JOIN file_registry_faces frf ON frf.file_registry_id = fr.id
            WHERE fr.asset_uuid = ? AND fr.status = 'active'
            GROUP BY fr.id
        ", [$uuid]);

        if (! $item) {
            return response()->json(['error' => 'Media not found'], 404);
        }

        // Get faces with details
        $faces = DB::select('
            SELECT frf.*, gp.given_name, gp.surname
            FROM file_registry_faces frf
            LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
            WHERE frf.file_registry_id = ?
        ', [$item->id]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $item->id,
                'uuid' => $item->asset_uuid,
                'filename' => $item->filename,
                'path' => $item->current_path,
                'type' => $this->getMediaType($item->mime_type),
                'mime_type' => $item->mime_type,
                'size' => $item->file_size,
                'size_human' => $this->formatBytes($item->file_size),
                'date' => $item->nextcloud_modified_at,
                'title' => $item->title,
                'description' => $item->description,
                'ai_description' => $item->ai_description,
                'ai_tags' => $item->ai_tags,
                'category' => $item->category,
                'tags' => $item->tags,
                'faces' => array_map(fn ($f) => [
                    'id' => $f->id,
                    'person_name' => $f->person_name,
                    'name' => $f->person_name,
                    'genealogy_person_id' => $f->genealogy_person_id,
                    'genealogy_name' => $f->given_name ? "{$f->given_name} {$f->surname}" : null,
                    'region_x' => (float) $f->region_x,
                    'region_y' => (float) $f->region_y,
                    'region_w' => (float) $f->region_w,
                    'region_h' => (float) $f->region_h,
                    'region' => [
                        'x' => (float) $f->region_x,
                        'y' => (float) $f->region_y,
                        'w' => (float) $f->region_w,
                        'h' => (float) $f->region_h,
                    ],
                    'verified' => (bool) $f->verified,
                ], $faces),
                'stream_url' => "/api/media/{$uuid}/stream",
                'thumbnail_url' => "/api/media/{$uuid}/thumbnail/large",
            ],
        ]);
    }

    /**
     * Get thumbnail for media
     *
     * GET /api/media/{uuid}/thumbnail/{size}
     */
    public function thumbnail(string $uuid, string $size = 'medium'): Response
    {
        $result = $this->thumbnailService->getThumbnail($uuid, $size);

        if (! $result['success']) {
            // Return placeholder SVG
            $placeholder = public_path('images/placeholder-thumbnail.svg');
            if (file_exists($placeholder)) {
                return response(file_get_contents($placeholder), 200, [
                    'Content-Type' => 'image/svg+xml',
                    'Cache-Control' => 'public, max-age=3600',
                ]);
            }

            return response('', 404);
        }

        $content = file_get_contents($result['path']);
        $etag = '"'.md5_file($result['path']).'"';
        $lastModified = gmdate('D, d M Y H:i:s', filemtime($result['path'])).' GMT';

        // Support conditional requests (304 Not Modified)
        if (request()->header('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=3600, must-revalidate',
            ]);
        }

        return response($content, 200, [
            'Content-Type' => $result['mime_type'],
            'Cache-Control' => 'public, max-age=3600, must-revalidate',
            'ETag' => $etag,
            'Last-Modified' => $lastModified,
        ]);
    }

    /**
     * Get thumbnail by Nextcloud path (for genealogy media not in file_registry)
     *
     * GET /api/media/path-thumbnail
     */
    public function thumbnailByPath(Request $request): Response
    {
        $path = $request->get('path');
        $size = $request->get('size', 'small');

        if (! $path) {
            return response('Path required', 400);
        }

        // Sanitize size
        $sizes = ['small' => 150, 'medium' => 300, 'large' => 600];
        $targetSize = $sizes[$size] ?? 150;

        // Generate cache key
        $cacheKey = 'path_thumb_'.md5($path.'_'.$size);
        $cachePath = storage_path("app/thumbnails/path/{$cacheKey}.jpg");

        // Check cache
        if (file_exists($cachePath)) {
            return response(file_get_contents($cachePath), 200, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        // Ensure cache directory exists
        $dir = dirname($cachePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Get source image data — filesystem-first, WebDAV fallback
        try {
            $ncApi = app(\App\Services\NextcloudFileApiService::class);
            $localFsPath = $ncApi->localPath($path);
            if ($localFsPath) {
                $sourceData = @file_get_contents($localFsPath);
            } else {
                $nextcloud = app(\App\Services\NextcloudService::class);
                $result = $nextcloud->downloadFile($path);
                if (! $result['success']) {
                    return response('File not found', 404);
                }
                $sourceData = $result['content'];
            }

            if (! $sourceData) {
                return response('File not found', 404);
            }

            // Create thumbnail using GD
            $sourceImage = @imagecreatefromstring($sourceData);

            if (! $sourceImage) {
                return response('Cannot process image', 500);
            }

            $width = imagesx($sourceImage);
            $height = imagesy($sourceImage);

            // Calculate thumbnail dimensions (maintain aspect ratio)
            if ($width > $height) {
                $newWidth = $targetSize;
                $newHeight = (int) ($height * ($targetSize / $width));
            } else {
                $newHeight = $targetSize;
                $newWidth = (int) ($width * ($targetSize / $height));
            }

            $thumb = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($thumb, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            // Save to cache
            imagejpeg($thumb, $cachePath, 85);
            imagedestroy($sourceImage);

            $content = file_get_contents($cachePath);
            imagedestroy($thumb);

            return response($content, 200, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=86400',
            ]);

        } catch (\Exception $e) {
            Log::error('Path thumbnail error', ['path' => $path, 'error' => $e->getMessage()]);

            return response('Thumbnail generation failed', 500);
        }
    }

    /**
     * Get cropped face thumbnail from image
     *
     * GET /api/media/face-crop
     *
     * @param path - Nextcloud path to image
     * @param region - JSON face region {x, y, w, h} as percentages
     * @param size - Output size (default 200)
     */
    public function faceCrop(Request $request): Response
    {
        $path = $request->get('path');
        $regionJson = $request->get('region');
        $size = (int) $request->get('size', 200);

        if (! $path || ! $regionJson) {
            return response('Path and region required', 400);
        }

        // Parse face region
        $region = json_decode($regionJson, true);
        if (! $region || ! isset($region['x'], $region['y'], $region['w'], $region['h'])) {
            return response('Invalid region format', 400);
        }

        // Generate cache key
        $cacheKey = 'face_'.md5($path.'_'.$regionJson.'_'.$size);
        $cachePath = storage_path("app/thumbnails/faces/{$cacheKey}.jpg");

        // Check cache
        if (file_exists($cachePath)) {
            return response(file_get_contents($cachePath), 200, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=604800', // 1 week
            ]);
        }

        try {
            $nextcloud = app(\App\Services\NextcloudService::class);
            $result = $nextcloud->downloadFile($path);

            if (! $result['success']) {
                return response('File not found', 404);
            }

            $sourceImage = @imagecreatefromstring($result['content']);
            if (! $sourceImage) {
                return response('Cannot process image', 500);
            }

            $imgWidth = imagesx($sourceImage);
            $imgHeight = imagesy($sourceImage);

            // Calculate crop coordinates from percentages
            // Add padding (30%) around the face for context
            $padding = 0.3;
            $x = max(0, ($region['x'] - $region['w'] * $padding) * $imgWidth);
            $y = max(0, ($region['y'] - $region['h'] * $padding) * $imgHeight);
            $w = min($imgWidth - $x, $region['w'] * (1 + $padding * 2) * $imgWidth);
            $h = min($imgHeight - $y, $region['h'] * (1 + $padding * 2) * $imgHeight);

            // Make it square (take the larger dimension)
            $cropSize = max($w, $h);
            // Re-center
            $x = max(0, $x - ($cropSize - $w) / 2);
            $y = max(0, $y - ($cropSize - $h) / 2);
            // Ensure we don't go out of bounds
            if ($x + $cropSize > $imgWidth) {
                $x = $imgWidth - $cropSize;
            }
            if ($y + $cropSize > $imgHeight) {
                $y = $imgHeight - $cropSize;
            }
            if ($x < 0) {
                $x = 0;
                $cropSize = min($cropSize, $imgWidth);
            }
            if ($y < 0) {
                $y = 0;
                $cropSize = min($cropSize, $imgHeight);
            }

            // Create cropped and scaled image
            $cropped = imagecreatetruecolor($size, $size);
            imagecopyresampled(
                $cropped, $sourceImage,
                0, 0, (int) $x, (int) $y,
                $size, $size, (int) $cropSize, (int) $cropSize
            );

            // Ensure directory exists
            $dir = dirname($cachePath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Save and return
            imagejpeg($cropped, $cachePath, 90);
            imagedestroy($sourceImage);

            $content = file_get_contents($cachePath);
            imagedestroy($cropped);

            return response($content, 200, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=604800',
            ]);

        } catch (\Exception $e) {
            Log::error('Face crop error', ['path' => $path, 'error' => $e->getMessage()]);

            return response('Face crop failed', 500);
        }
    }

    /**
     * Stream media file (video/audio)
     *
     * GET /api/media/{uuid}/stream
     */
    public function stream(Request $request, string $uuid): \Symfony\Component\HttpFoundation\StreamedResponse|Response
    {
        $item = DB::selectOne("
            SELECT current_path, mime_type, file_size
            FROM file_registry
            WHERE asset_uuid = ? AND status = 'active'
        ", [$uuid]);

        if (! $item) {
            return response('Not found', 404);
        }

        $path = $this->resolveLocalPath($item->current_path);
        if (! $path || ! file_exists($path)) {
            return response('File not found on disk', 404);
        }
        $size = filesize($path); // Use actual file size, not DB value (may be stale)
        $mimeType = $item->mime_type;

        // Fix incorrect MIME types for known media extensions
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeOverrides = [
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska', 'webm' => 'video/webm', 'wmv' => 'video/x-ms-wmv',
            'm4v' => 'video/x-m4v', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
            'ogg' => 'audio/ogg', 'flac' => 'audio/flac', 'm4a' => 'audio/mp4',
        ];
        if (isset($mimeOverrides[$ext]) && ($mimeType === 'application/octet-stream' || ! str_starts_with($mimeType, 'video/') && str_starts_with($mimeOverrides[$ext], 'video/'))) {
            $mimeType = $mimeOverrides[$ext];
        }

        // Handle range requests for video seeking
        $start = 0;
        $end = $size - 1;
        $length = $size;
        $statusCode = 200;

        if ($request->hasHeader('Range')) {
            $range = $request->header('Range');
            if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
                $start = (int) $matches[1];
                $end = ! empty($matches[2]) ? (int) $matches[2] : $size - 1;
                $length = $end - $start + 1;
                $statusCode = 206;
            }
        }

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Length' => $length,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=3600',
        ];

        if ($statusCode === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return response()->stream(function () use ($path, $start, $length) {
            $handle = fopen($path, 'rb');
            fseek($handle, $start);
            $remaining = $length;
            $chunkSize = 8192;

            while ($remaining > 0 && ! feof($handle)) {
                $read = min($chunkSize, $remaining);
                echo fread($handle, $read);
                $remaining -= $read;
                flush();
            }

            fclose($handle);
        }, $statusCode, $headers);
    }

    /**
     * Get folder tree structure
     *
     * GET /api/media/folders
     */
    public function folders(Request $request): JsonResponse
    {
        $nextcloudPath = config('services.nextcloud.data_path');
        $libraryRoot = $this->nextcloudLibraryRoot();

        // Filesystem-first: scan actual directory tree when path is available
        if ($nextcloudPath && is_dir(rtrim($nextcloudPath, '/').$libraryRoot)) {
            $result = $this->scanFilesystemFolders($nextcloudPath, $libraryRoot);
        } else {
            // Fallback: derive folders from file_registry paths
            $result = $this->getFoldersFromDatabase($request->get('base', $libraryRoot));
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Scan physical filesystem for folder tree (preferred method)
     */
    private function scanFilesystemFolders(string $basePath, string $libraryRoot): array
    {
        $libraryPath = rtrim($basePath, '/').$libraryRoot;
        $browserRoot = ltrim($libraryRoot, '/');
        $result = [];

        // Get file counts per folder from DB for overlay
        // Paths in file_registry have a leading slash, e.g. /Library/...
        $fileCounts = [];
        $rows = DB::select("
            SELECT
                SUBSTRING(current_path, 1, LENGTH(current_path) - LENGTH(SUBSTRING_INDEX(current_path, '/', -1)) - 1) as folder_path,
                COUNT(*) as file_count
            FROM file_registry
            WHERE status = 'active' AND current_path LIKE ?
            GROUP BY folder_path
        ", [$libraryRoot.'/%']);
        foreach ($rows as $row) {
            // Strip leading slash to match filesystem-relative paths
            $fileCounts[ltrim($row->folder_path, '/')] = $row->file_count;
        }

        // Recursive directory scan (max depth 6 to prevent runaway)
        $this->scanDirectory($libraryPath, $browserRoot, $result, $fileCounts, 0, 6);

        // Sort by path for consistent tree building
        usort($result, fn ($a, $b) => strcmp($a['path'], $b['path']));

        return $result;
    }

    private function scanDirectory(string $fsPath, string $relativePath, array &$result, array &$fileCounts, int $depth, int $maxDepth): void
    {
        if ($depth > $maxDepth || ! is_dir($fsPath)) {
            return;
        }

        $entries = @scandir($fsPath);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $fsPath.'/'.$entry;
            if (! is_dir($fullPath)) {
                continue;
            }

            $relPath = $relativePath.'/'.$entry;
            $result[] = [
                'path' => $relPath,
                'name' => $entry,
                'file_count' => $fileCounts[$relPath] ?? 0,
            ];

            $this->scanDirectory($fullPath, $relPath, $result, $fileCounts, $depth + 1, $maxDepth);
        }
    }

    /**
     * Fallback: derive folders from file_registry paths (dev/non-filesystem environments)
     */
    private function getFoldersFromDatabase(string $basePath): array
    {
        $basePath = trim($basePath);
        $basePath = $basePath === '' ? $this->nextcloudLibraryRoot() : '/'.ltrim($basePath, '/');

        $folders = DB::select("
            SELECT
                folder_path,
                COUNT(*) as file_count
            FROM (
                SELECT SUBSTRING(current_path, 1, LENGTH(current_path) - LENGTH(SUBSTRING_INDEX(current_path, '/', -1)) - 1) as folder_path
                FROM file_registry
                WHERE status = 'active'
                AND current_path LIKE ?
            ) as subq
            WHERE folder_path IS NOT NULL AND folder_path != ''
            GROUP BY folder_path
            ORDER BY folder_path
        ", [$basePath.'%']);

        $seen = [];
        $result = [];
        foreach ($folders as $folder) {
            $path = trim($folder->folder_path, '/');
            $parts = explode('/', $path);

            for ($i = 1; $i < count($parts); $i++) {
                $ancestorPath = implode('/', array_slice($parts, 0, $i));
                if (! isset($seen[$ancestorPath])) {
                    $seen[$ancestorPath] = true;
                    $result[] = [
                        'path' => $ancestorPath,
                        'name' => $parts[$i - 1],
                        'file_count' => 0,
                    ];
                }
            }

            if (! isset($seen[$path])) {
                $seen[$path] = true;
                $result[] = [
                    'path' => $path,
                    'name' => end($parts),
                    'file_count' => $folder->file_count,
                ];
            } else {
                foreach ($result as &$r) {
                    if ($r['path'] === $path) {
                        $r['file_count'] = $folder->file_count;
                        break;
                    }
                }
                unset($r);
            }
        }

        return $result;
    }

    /**
     * Browse a folder — returns immediate subfolders + files (file-manager mode)
     *
     * GET /api/media/browse?path=Library/Photos&sort=name_asc&limit=50&offset=0
     */
    public function browse(Request $request): JsonResponse
    {
        $path = trim((string) $request->get('path', ltrim($this->nextcloudLibraryRoot(), '/')), '/');
        $sort = $request->get('sort', 'name_asc');
        $limit = min((int) $request->get('limit', 50), 200);
        $offset = (int) $request->get('offset', 0);

        $nextcloudPath = config('services.nextcloud.data_path');

        // Build breadcrumb from path segments
        $parts = explode('/', $path);
        $breadcrumb = [];
        for ($i = 0; $i < count($parts); $i++) {
            $breadcrumb[] = [
                'name' => $parts[$i],
                'path' => implode('/', array_slice($parts, 0, $i + 1)),
            ];
        }

        // Get subfolders
        $subfolders = [];
        $fsDir = $nextcloudPath ? ($nextcloudPath.'/'.$path) : null;

        if ($fsDir && is_dir($fsDir)) {
            // Filesystem scan for subfolders
            $entries = @scandir($fsDir);
            if ($entries) {
                $subfolderPaths = [];
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    if (! is_dir($fsDir.'/'.$entry)) {
                        continue;
                    }
                    $subfolderPaths[] = $path.'/'.$entry;
                }

                // Batch file count query for all subfolders
                if (! empty($subfolderPaths)) {
                    $countMap = [];
                    $rows = DB::select("
                        SELECT
                            SUBSTRING(current_path, 1, LENGTH(current_path) - LENGTH(SUBSTRING_INDEX(current_path, '/', -1)) - 1) as folder_path,
                            COUNT(*) as file_count
                        FROM file_registry
                        WHERE status = 'active' AND current_path LIKE ?
                        GROUP BY folder_path
                    ", ['/'.$path.'/%']);

                    // Build recursive counts per subfolder
                    foreach ($rows as $row) {
                        $fp = ltrim($row->folder_path, '/');
                        foreach ($subfolderPaths as $sp) {
                            if (str_starts_with($fp, $sp.'/') || $fp === $sp) {
                                $countMap[$sp] = ($countMap[$sp] ?? 0) + $row->file_count;
                            }
                        }
                    }

                    foreach ($subfolderPaths as $sp) {
                        $name = basename($sp);
                        $hasChildren = false;
                        $childDir = $fsDir.'/'.$name;
                        if (is_dir($childDir)) {
                            $childEntries = @scandir($childDir);
                            if ($childEntries) {
                                foreach ($childEntries as $ce) {
                                    if ($ce !== '.' && $ce !== '..' && is_dir($childDir.'/'.$ce)) {
                                        $hasChildren = true;
                                        break;
                                    }
                                }
                            }
                        }
                        $subfolders[] = [
                            'name' => $name,
                            'path' => $sp,
                            'file_count' => $countMap[$sp] ?? 0,
                            'has_subfolders' => $hasChildren,
                        ];
                    }

                    // Sort subfolders alphabetically
                    usort($subfolders, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
                }
            }
        }

        // Get immediate files in this folder (NOT recursive)
        // Path pattern: /Library/Photos/file.jpg matches, /Library/Photos/sub/file.jpg does not
        $dbPath = '/'.$path;
        $params = [$dbPath.'/%', $dbPath.'/%/%'];

        $orderBy = match ($sort) {
            'name_desc' => 'fr.filename DESC',
            'date_desc' => 'COALESCE(fr.date_taken, fr.nextcloud_modified_at) DESC',
            'date_asc' => 'COALESCE(fr.date_taken, fr.nextcloud_modified_at) ASC',
            'size_desc' => 'fr.file_size DESC',
            default => 'fr.filename ASC',
        };

        $totalFiles = DB::selectOne("
            SELECT COUNT(*) as cnt FROM file_registry fr
            WHERE fr.status = 'active'
            AND fr.current_path LIKE ?
            AND fr.current_path NOT LIKE ?
        ", $params)->cnt ?? 0;

        $params[] = $limit;
        $params[] = $offset;

        $files = DB::select("
            SELECT
                fr.id, fr.asset_uuid, fr.filename, fr.current_path,
                fr.mime_type, fr.file_size, fr.extension,
                COALESCE(fr.date_taken, fr.nextcloud_modified_at) as file_date,
                fr.title, fr.ai_description, fr.face_count, fr.thumbnail_sizes
            FROM file_registry fr
            WHERE fr.status = 'active'
            AND fr.current_path LIKE ?
            AND fr.current_path NOT LIKE ?
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?
        ", $params);

        $fileItems = array_map(function ($item) {
            $ext = strtolower($item->extension ?: pathinfo($item->filename, PATHINFO_EXTENSION));

            return [
                'id' => $item->id,
                'asset_uuid' => $item->asset_uuid,
                'filename' => $item->filename,
                'extension' => $ext,
                'current_path' => $item->current_path,
                'path' => $item->current_path,
                'type' => $this->getMediaType($item->mime_type),
                'mime_type' => $item->mime_type,
                'file_size' => $item->file_size,
                'size_human' => $this->formatBytes($item->file_size),
                'date' => $item->file_date,
                'title' => $item->title,
                'description' => $item->ai_description,
                'face_count' => (int) ($item->face_count ?? 0),
                'has_thumbnail' => ! empty($item->thumbnail_sizes),
                'thumbnail_url' => $item->asset_uuid ? "/api/media/{$item->asset_uuid}/thumbnail/medium" : null,
            ];
        }, $files);

        return response()->json([
            'success' => true,
            'breadcrumb' => $breadcrumb,
            'subfolders' => $subfolders,
            'files' => $fileItems,
            'total_files' => $totalFiles,
            'meta' => ['limit' => $limit, 'offset' => $offset],
        ]);
    }

    /**
     * Get unique persons found in media
     *
     * GET /api/media/persons
     */
    public function persons(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 100), 500);
        $search = $request->get('search');

        if ($search && strlen($search) >= 2) {
            $likeTerm = '%'.$search.'%';

            // Face-based results (persons who appear in face tags, with media counts)
            $faceResults = DB::select('
                SELECT frf.genealogy_person_id as id, frf.person_name as name,
                    gp.given_name, gp.surname, COUNT(*) as media_count
                FROM file_registry_faces frf
                LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
                WHERE frf.person_name LIKE ? OR gp.given_name LIKE ? OR gp.surname LIKE ?
                GROUP BY frf.person_name, frf.genealogy_person_id, gp.given_name, gp.surname
                ORDER BY media_count DESC
                LIMIT ?
            ', [$likeTerm, $likeTerm, $likeTerm, $limit]);

            // Genealogy persons NOT already in face results (search across all trees)
            $facePersonIds = array_filter(array_map(fn ($r) => $r->id, $faceResults));
            if (count($facePersonIds) > 0) {
                $excludePlaceholders = implode(',', array_fill(0, count($facePersonIds), '?'));
                $genealogyParams = array_merge([$likeTerm, $likeTerm, $likeTerm], $facePersonIds, [$limit]);
                $genealogyResults = DB::select("
                    SELECT id, given_name, surname,
                        CONCAT(COALESCE(given_name,''), ' ', COALESCE(surname,'')) as name,
                        0 as media_count
                    FROM genealogy_persons
                    WHERE (given_name LIKE ? OR surname LIKE ? OR CONCAT(given_name, ' ', surname) LIKE ?)
                    AND id NOT IN ({$excludePlaceholders})
                    LIMIT ?
                ", $genealogyParams);
            } else {
                $genealogyResults = DB::select("
                    SELECT id, given_name, surname,
                        CONCAT(COALESCE(given_name,''), ' ', COALESCE(surname,'')) as name,
                        0 as media_count
                    FROM genealogy_persons
                    WHERE given_name LIKE ? OR surname LIKE ? OR CONCAT(given_name, ' ', surname) LIKE ?
                    LIMIT ?
                ", [$likeTerm, $likeTerm, $likeTerm, $limit]);
            }

            $persons = array_map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'genealogy_person_id' => $p->id,
                'given_name' => $p->given_name,
                'surname' => $p->surname,
                'genealogy_name' => $p->given_name ? trim("{$p->given_name} {$p->surname}") : null,
                'media_count' => (int) $p->media_count,
            ], array_merge($faceResults, $genealogyResults));

            return response()->json(['success' => true, 'data' => $persons]);
        }

        // No search term: return top persons by media count (original behavior)
        $where = '';
        $params = [];

        if ($search) {
            $where = 'WHERE frf.person_name LIKE ?';
            $params[] = '%'.$search.'%';
        }

        $params[] = $limit;

        $persons = DB::select("
            SELECT
                frf.person_name,
                frf.genealogy_person_id,
                gp.given_name,
                gp.surname,
                COUNT(*) as media_count
            FROM file_registry_faces frf
            LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
            {$where}
            GROUP BY frf.person_name, frf.genealogy_person_id, gp.given_name, gp.surname
            ORDER BY media_count DESC
            LIMIT ?
        ", $params);

        return response()->json([
            'success' => true,
            'data' => array_map(fn ($p) => [
                'id' => $p->genealogy_person_id,
                'name' => $p->person_name,
                'genealogy_person_id' => $p->genealogy_person_id,
                'given_name' => $p->given_name,
                'surname' => $p->surname,
                'genealogy_name' => $p->given_name ? "{$p->given_name} {$p->surname}" : null,
                'media_count' => $p->media_count,
            ], $persons),
        ]);
    }

    /**
     * Search genealogy persons directly, including people with no face rows.
     *
     * GET /api/media/genealogy-persons
     */
    public function genealogyPersons(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->get('limit', 20), 1), 100);
        $search = trim((string) $request->get('search', ''));
        $treeId = (int) $request->get('tree_id', 0);
        $includeUnlinkedOnly = $request->boolean('include_unlinked_only', false);

        $where = [];
        $params = [];

        if ($treeId > 0) {
            $where[] = 'gp.tree_id = ?';
            $params[] = $treeId;
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $where[] = "(gp.given_name LIKE ? OR gp.surname LIKE ? OR CONCAT_WS(' ', gp.given_name, gp.surname) LIKE ? OR gp.gedcom_id LIKE ?)";
            array_push($params, $like, $like, $like, $like);
        }

        if ($includeUnlinkedOnly) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM file_registry_faces frf WHERE frf.genealogy_person_id = gp.id)';
        }

        $whereSql = $where === [] ? '' : 'WHERE '.implode(' AND ', $where);

        $orderSql = 'gp.surname ASC, gp.given_name ASC, gp.id ASC';
        if ($search !== '') {
            $prefix = $search.'%';
            $orderSql = "
                CASE
                    WHEN CONCAT_WS(' ', gp.given_name, gp.surname) LIKE ? THEN 0
                    WHEN gp.surname LIKE ? THEN 1
                    WHEN gp.given_name LIKE ? THEN 2
                    ELSE 3
                END,
                gp.surname ASC,
                gp.given_name ASC,
                gp.id ASC
            ";
            array_push($params, $prefix, $prefix, $prefix);
        }

        $params[] = $limit;

        $persons = DB::select("
            SELECT
                gp.id,
                gp.tree_id,
                gp.gedcom_id,
                gp.given_name,
                gp.surname,
                gp.birth_date,
                gp.death_date,
                CONCAT_WS(' ', gp.given_name, gp.surname) AS name,
                COALESCE(face_counts.face_count, 0) AS face_count
            FROM genealogy_persons gp
            LEFT JOIN (
                SELECT genealogy_person_id, COUNT(*) AS face_count
                FROM file_registry_faces
                WHERE genealogy_person_id IS NOT NULL
                GROUP BY genealogy_person_id
            ) face_counts ON face_counts.genealogy_person_id = gp.id
            {$whereSql}
            ORDER BY {$orderSql}
            LIMIT ?
        ", $params);

        return response()->json([
            'success' => true,
            'data' => array_map(fn ($person) => [
                'id' => (int) $person->id,
                'genealogy_person_id' => (int) $person->id,
                'tree_id' => (int) $person->tree_id,
                'gedcom_id' => $person->gedcom_id,
                'name' => trim((string) $person->name),
                'given_name' => $person->given_name,
                'surname' => $person->surname,
                'genealogy_name' => trim((string) $person->name),
                'birth_date' => $person->birth_date,
                'death_date' => $person->death_date,
                'media_count' => (int) $person->face_count,
                'face_count' => (int) $person->face_count,
            ], $persons),
        ]);
    }

    private function findGenealogyPerson(int $personId, ?int $treeId = null): ?object
    {
        if ($personId <= 0) {
            return null;
        }

        if ($treeId && $treeId > 0) {
            return DB::selectOne(
                'SELECT id, given_name, surname FROM genealogy_persons WHERE id = ? AND tree_id = ? LIMIT 1',
                [$personId, $treeId]
            );
        }

        return DB::selectOne(
            'SELECT id, given_name, surname FROM genealogy_persons WHERE id = ? LIMIT 1',
            [$personId]
        );
    }

    private function invalidGenealogyPersonResponse(int $personId): JsonResponse
    {
        return response()->json([
            'error' => 'genealogy_person_id not found',
            'genealogy_person_id' => $personId,
        ], 422);
    }

    /**
     * Set person_name on a face record without genealogy link
     *
     * POST /api/media/faces/{faceId}/name
     */
    public function setFaceName(Request $request, int $faceId): JsonResponse
    {
        $personName = trim($request->input('person_name', ''));
        $genealogyPersonId = (int) $request->input('genealogy_person_id', 0);
        if (! $personName) {
            return response()->json(['error' => 'person_name required'], 400);
        }

        if ($genealogyPersonId > 0 && ! $this->findGenealogyPerson($genealogyPersonId, (int) $request->input('tree_id', 0))) {
            return $this->invalidGenealogyPersonResponse($genealogyPersonId);
        }

        $face = DB::selectOne('SELECT id, file_registry_id FROM file_registry_faces WHERE id = ?', [$faceId]);
        if (! $face) {
            return response()->json(['error' => 'Face not found'], 404);
        }

        DB::update('UPDATE file_registry_faces SET person_name = ? WHERE id = ?', [$personName, $faceId]);

        // Reset writeback flags so scheduled job re-writes EXIF
        DB::update('UPDATE file_registry SET exif_faces_written = 0 WHERE id = ?', [$face->file_registry_id]);

        $bridgeResult = null;
        if ($genealogyPersonId > 0) {
            $bridgeResult = app(FaceLinkBridgeService::class)->syncFaceLink($faceId, $genealogyPersonId);
        }

        return response()->json([
            'success' => true,
            'face_id' => $faceId,
            'person_name' => $personName,
            'genealogy_person_id' => $genealogyPersonId ?: null,
            'genealogy_bridge' => $bridgeResult,
        ]);
    }

    /**
     * Link a face to a genealogy person
     *
     * POST /api/media/faces/link
     */
    public function linkFace(Request $request): JsonResponse
    {
        $faceId = (int) $request->input('face_id');
        $personId = (int) ($request->input('person_id') ?: $request->input('genealogy_person_id'));

        if (! $faceId || ! $personId) {
            return response()->json(['error' => 'face_id and person_id required'], 400);
        }

        if (! $this->findGenealogyPerson($personId, (int) $request->input('tree_id', 0))) {
            return $this->invalidGenealogyPersonResponse($personId);
        }

        $bridgeResult = app(FaceLinkBridgeService::class)->syncFaceLink($faceId, $personId);
        $success = $bridgeResult['success'] ?? false;

        return response()->json([
            'success' => $success,
            'face_id' => $faceId,
            'person_id' => $personId,
            'genealogy_bridge' => $bridgeResult,
        ]);
    }

    /**
     * Get face match queue
     *
     * GET /api/media/faces/queue
     */
    public function faceQueue(Request $request): JsonResponse
    {
        $treeId = (int) $request->get('tree_id', 4);
        $status = $request->get('status', 'pending');
        $limit = min((int) $request->get('limit', 50), 200);

        $statusFilter = $status === 'all' ? '' : 'AND q.status = ?';
        $params = $status === 'all' ? [$treeId, $limit] : [$treeId, $status, $limit];

        $queue = DB::select("
            SELECT
                q.*,
                gp.given_name,
                gp.surname,
                COALESCE(fr1.filename, fr2.filename, gm.local_filename) as filename,
                COALESCE(fr1.asset_uuid, fr2.asset_uuid) as asset_uuid,
                gm.nextcloud_path as media_path,
                gm.id as genealogy_media_id
            FROM genealogy_face_match_queue q
            LEFT JOIN genealogy_persons gp ON gp.id = q.suggested_person_id
            -- Path 1: via file_registry_faces (when file_registry_face_id exists)
            LEFT JOIN file_registry_faces frf ON frf.id = q.file_registry_face_id
            LEFT JOIN file_registry fr1 ON fr1.id = frf.file_registry_id
            -- Path 2: via genealogy_media (when media_id exists)
            LEFT JOIN genealogy_media gm ON gm.id = q.media_id
            LEFT JOIN file_registry fr2 ON fr2.current_path = gm.nextcloud_path
            WHERE q.tree_id = ? {$statusFilter}
            ORDER BY q.confidence_score DESC
            LIMIT ?
        ", $params);

        $stats = $this->faceMatcher->getQueueStats($treeId);

        return response()->json([
            'success' => true,
            'data' => $queue,
            'stats' => $stats,
        ]);
    }

    /**
     * Get media statistics
     *
     * GET /api/media/stats
     */
    public function stats(): JsonResponse
    {
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total_files,
                SUM(CASE WHEN mime_type LIKE 'image/%' THEN 1 ELSE 0 END) as images,
                SUM(CASE WHEN mime_type LIKE 'video/%' THEN 1 ELSE 0 END) as videos,
                SUM(CASE WHEN mime_type LIKE 'audio/%' THEN 1 ELSE 0 END) as audio,
                SUM(CASE WHEN face_count > 0 THEN 1 ELSE 0 END) as with_faces,
                SUM(COALESCE(face_count, 0)) as total_faces,
                SUM(file_size) as total_size
            FROM file_registry
            WHERE status = 'active'
        ");

        $faceStats = DB::selectOne('
            SELECT
                COUNT(*) as total_face_records,
                SUM(CASE WHEN genealogy_person_id IS NOT NULL THEN 1 ELSE 0 END) as linked_faces,
                SUM(CASE WHEN genealogy_person_id IS NULL THEN 1 ELSE 0 END) as unlinked_faces
            FROM file_registry_faces
        ');

        $pendingReview = DB::selectOne("
            SELECT COUNT(*) as cnt
            FROM genealogy_face_match_queue
            WHERE status = 'pending'
        ");

        return response()->json([
            'success' => true,
            'data' => [
                'total_files' => (int) ($stats->total_files ?? 0),
                'images' => (int) ($stats->images ?? 0),
                'videos' => (int) ($stats->videos ?? 0),
                'audio' => (int) ($stats->audio ?? 0),
                'with_faces' => (int) ($stats->with_faces ?? 0),
                'total_faces' => (int) ($stats->total_faces ?? 0),
                'linked_faces' => (int) ($faceStats->linked_faces ?? 0),
                'unlinked_faces' => (int) ($faceStats->unlinked_faces ?? 0),
                'pending_review' => (int) ($pendingReview->cnt ?? 0),
                'total_size' => (int) ($stats->total_size ?? 0),
                'total_size_human' => $this->formatBytes((int) ($stats->total_size ?? 0)),
            ],
        ]);
    }

    /**
     * Approve/reject face match from queue
     *
     * POST /api/media/faces/queue/{id}/review
     */
    public function reviewFaceMatch(Request $request, int $id): JsonResponse
    {
        $action = $request->input('action'); // approve or reject

        if (! in_array($action, ['approve', 'reject'])) {
            return response()->json(['error' => 'action must be approve or reject'], 400);
        }

        $status = $action === 'approve' ? 'approved' : 'rejected';
        $bridgeResult = null;

        // If approving, also create the link
        if ($action === 'approve') {
            $match = DB::selectOne('SELECT * FROM genealogy_face_match_queue WHERE id = ?', [$id]);
            if ($match && $match->suggested_person_id && $match->file_registry_face_id) {
                $bridgeResult = app(FaceLinkBridgeService::class)->syncFaceLink(
                    (int) $match->file_registry_face_id,
                    (int) $match->suggested_person_id,
                    $match->media_id ? (int) $match->media_id : null
                );
            }
        }

        DB::update('
            UPDATE genealogy_face_match_queue
            SET status = ?, reviewed_at = NOW()
            WHERE id = ?
        ', [$status, $id]);

        return response()->json([
            'success' => true,
            'status' => $status,
            'genealogy_bridge' => $bridgeResult,
        ]);
    }

    // =========================================================================
    // Face Reassignment & Person Links
    // =========================================================================

    /**
     * Reassign a face to a different genealogy person
     *
     * POST /api/media/faces/{faceId}/reassign
     */
    public function reassignFace(Request $request, int $faceId): JsonResponse
    {
        $personId = (int) ($request->input('person_id') ?: $request->input('genealogy_person_id'));
        if (! $personId) {
            return response()->json(['error' => 'person_id required'], 400);
        }

        $face = DB::selectOne('SELECT id, file_registry_id FROM file_registry_faces WHERE id = ?', [$faceId]);
        if (! $face) {
            return response()->json(['error' => 'Face not found'], 404);
        }

        $person = $this->findGenealogyPerson($personId, (int) $request->input('tree_id', 0));
        if (! $person) {
            return $this->invalidGenealogyPersonResponse($personId);
        }

        // Update face assignment
        DB::update('UPDATE file_registry_faces SET genealogy_person_id = ?, person_name = ? WHERE id = ?', [
            $personId,
            trim($person->given_name.' '.$person->surname),
            $faceId,
        ]);

        // Reset writeback flags so scheduled job re-writes EXIF
        DB::update('UPDATE file_registry SET exif_faces_written = 0 WHERE id = ?', [$face->file_registry_id]);

        // Delete scan log entry so face-sync re-processes
        $file = DB::selectOne('SELECT current_path FROM file_registry WHERE id = ?', [$face->file_registry_id]);
        if ($file) {
            DB::delete('DELETE FROM genealogy_media_scan_log WHERE nextcloud_path = ?', [$file->current_path]);
        }

        $bridgeResult = app(FaceLinkBridgeService::class)->syncFaceLink($faceId, $personId);

        return response()->json([
            'success' => true,
            'face_id' => $faceId,
            'person_id' => $personId,
            'person_name' => trim($person->given_name.' '.$person->surname),
            'genealogy_bridge' => $bridgeResult,
        ]);
    }

    /**
     * Unlink a face from its genealogy person
     *
     * POST /api/media/faces/{faceId}/unlink
     */
    public function unlinkFace(int $faceId): JsonResponse
    {
        $face = DB::selectOne('SELECT id, file_registry_id FROM file_registry_faces WHERE id = ?', [$faceId]);
        if (! $face) {
            return response()->json(['error' => 'Face not found'], 404);
        }

        DB::update('UPDATE file_registry_faces SET genealogy_person_id = NULL WHERE id = ?', [$faceId]);

        // Reset writeback flags
        DB::update('UPDATE file_registry SET exif_faces_written = 0 WHERE id = ?', [$face->file_registry_id]);

        // Delete scan log entry
        $file = DB::selectOne('SELECT current_path FROM file_registry WHERE id = ?', [$face->file_registry_id]);
        if ($file) {
            DB::delete('DELETE FROM genealogy_media_scan_log WHERE nextcloud_path = ?', [$file->current_path]);
        }

        return response()->json(['success' => true, 'face_id' => $faceId]);
    }

    /**
     * Add a person-media link (for photos without face tags)
     *
     * POST /api/media/{uuid}/person-link
     */
    public function addPersonLink(Request $request, string $uuid): JsonResponse
    {
        $personId = (int) $request->input('person_id');
        $personName = trim($request->input('person_name', ''));
        $treeId = (int) $request->input('tree_id', 4);

        if (! $personId && ! $personName) {
            return response()->json(['error' => 'person_id or person_name required'], 400);
        }

        // Name-only link: create a face record with name but no genealogy association
        if (! $personId && $personName) {
            $file = DB::selectOne("SELECT id FROM file_registry WHERE asset_uuid = ? AND status = 'active'", [$uuid]);
            if (! $file) {
                return response()->json(['error' => 'File not found'], 404);
            }

            DB::insert("INSERT INTO file_registry_faces (file_registry_id, person_name, region_x, region_y, region_w, region_h, source, created_at) VALUES (?, ?, 0, 0, 0, 0, 'manual', NOW())", [
                $file->id, $personName,
            ]);

            // Reset writeback flags
            DB::update('UPDATE file_registry SET exif_faces_written = 0 WHERE id = ?', [$file->id]);

            return response()->json(['success' => true, 'person_name' => $personName]);
        }

        $file = DB::selectOne("SELECT id, current_path FROM file_registry WHERE asset_uuid = ? AND status = 'active'", [$uuid]);
        if (! $file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        // Find the genealogy_media row for this path
        $media = DB::selectOne('SELECT id FROM genealogy_media WHERE nextcloud_path = ? AND tree_id = ?', [$file->current_path, $treeId]);

        if (! $media) {
            // Create genealogy_media entry
            DB::insert("INSERT INTO genealogy_media (tree_id, media_type, nextcloud_path, local_filename, created_at, updated_at) VALUES (?, 'photo', ?, ?, NOW(), NOW())", [
                $treeId,
                $file->current_path,
                basename($file->current_path),
            ]);
            $media = DB::selectOne('SELECT id FROM genealogy_media WHERE nextcloud_path = ? AND tree_id = ?', [$file->current_path, $treeId]);
        }

        if (! $media) {
            return response()->json(['error' => 'Could not create media link'], 500);
        }

        // Check if link already exists
        $existing = DB::selectOne('SELECT id FROM genealogy_person_media WHERE person_id = ? AND media_id = ?', [$personId, $media->id]);
        if ($existing) {
            return response()->json(['success' => true, 'message' => 'Link already exists']);
        }

        DB::insert('INSERT INTO genealogy_person_media (person_id, media_id, created_at) VALUES (?, ?, NOW())', [$personId, $media->id]);

        return response()->json(['success' => true, 'person_id' => $personId, 'media_id' => $media->id]);
    }

    /**
     * Remove a person-media link
     *
     * DELETE /api/media/{uuid}/person-link/{personId}
     */
    public function removePersonLink(string $uuid, int $personId): JsonResponse
    {
        $file = DB::selectOne("SELECT id, current_path FROM file_registry WHERE asset_uuid = ? AND status = 'active'", [$uuid]);
        if (! $file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        // Find genealogy_media by path
        $media = DB::selectOne('SELECT id FROM genealogy_media WHERE nextcloud_path = ?', [$file->current_path]);
        if ($media) {
            DB::delete('DELETE FROM genealogy_person_media WHERE person_id = ? AND media_id = ?', [$personId, $media->id]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Update metadata for a media file — writes to DB and optionally to physical file
     *
     * POST /api/media/{uuid}/metadata
     */
    public function updateMetadata(Request $request, string $uuid): JsonResponse
    {
        $file = DB::selectOne("
            SELECT id, current_path, date_taken, ai_description, tags
            FROM file_registry
            WHERE asset_uuid = ? AND status = 'active'
        ", [$uuid]);

        if (! $file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $updates = [];
        $params = [];
        $exifWriteArgs = [];
        $writeToFile = $request->boolean('write_to_file', false);
        $localPath = $this->resolveLocalPath($file->current_path);

        // Date taken
        if ($request->has('date_taken')) {
            $dateTaken = $request->input('date_taken') ?: null;
            $updates[] = 'date_taken = ?';
            $params[] = $dateTaken;
            $updates[] = "date_taken_source = 'user_manual'";
            $updates[] = 'date_taken_confidence = 1.0';
            $updates[] = 'exif_written = 0';
            if ($dateTaken) {
                $exifDate = str_replace(['-', 'T'], [':', ' '], substr($dateTaken, 0, 19));
                $exifWriteArgs[] = "-DateTimeOriginal={$exifDate}";
                $exifWriteArgs[] = "-CreateDate={$exifDate}";
                $exifWriteArgs[] = "-ModifyDate={$exifDate}";
            }
        }

        // Description
        if ($request->has('description')) {
            $desc = $request->input('description');
            $updates[] = 'ai_description = ?';
            $params[] = $desc;
            $updates[] = 'exif_tags_written = 0';
            if ($desc) {
                $exifWriteArgs[] = '-ImageDescription='.$desc;
                $exifWriteArgs[] = '-XMP:Description='.$desc;
            }
        }

        // Tags (comma-separated string or JSON array)
        if ($request->has('tags')) {
            $tags = $request->input('tags');
            $tagArray = is_array($tags) ? $tags : array_map('trim', explode(',', $tags));
            $tagArray = array_filter($tagArray);
            $updates[] = 'tags = ?';
            $params[] = json_encode(array_values($tagArray));
            $updates[] = 'exif_tags_written = 0';
            foreach ($tagArray as $tag) {
                $exifWriteArgs[] = '-IPTC:Keywords='.$tag;
                $exifWriteArgs[] = '-XMP:Subject='.$tag;
            }
        }

        // Copyright
        if ($request->has('copyright')) {
            $copyright = $request->input('copyright');
            $exifWriteArgs[] = '-IPTC:CopyrightNotice='.$copyright;
            $exifWriteArgs[] = '-XMP:Rights='.$copyright;
            $exifWriteArgs[] = '-IFD0:Copyright='.$copyright;
        }

        // Title
        if ($request->has('title')) {
            $title = $request->input('title');
            $updates[] = 'title = ?';
            $params[] = $title;
            $exifWriteArgs[] = '-XMP:Title='.$title;
            $exifWriteArgs[] = '-IPTC:ObjectName='.$title;
        }

        // Custom EXIF fields (raw exiftool tag=value pairs)
        if ($request->has('exif_fields') && is_array($request->input('exif_fields'))) {
            foreach ($request->input('exif_fields') as $field) {
                if (! empty($field['tag']) && isset($field['value'])) {
                    $tag = preg_replace('/[^a-zA-Z0-9:\-_]/', '', $field['tag']);
                    $exifWriteArgs[] = "-{$tag}=".$field['value'];
                }
            }
        }

        // Update DB
        if (! empty($updates)) {
            $params[] = $file->id;
            $sql = 'UPDATE file_registry SET '.implode(', ', $updates).' WHERE id = ?';
            DB::update($sql, $params);
        }

        // Delete scan log so face-sync re-processes
        DB::delete('DELETE FROM genealogy_media_scan_log WHERE nextcloud_path = ?', [$file->current_path]);

        // Write to physical file if requested and we have changes
        $writeResult = null;
        if ($writeToFile && ! empty($exifWriteArgs) && $localPath && file_exists($localPath)) {
            if (! config('metadata_writeback.enabled', false) || ! config('metadata_writeback.in_place_enabled', false)) {
                $writeResult = [
                    'success' => false,
                    'error' => 'Metadata writeback disabled',
                    'code' => -3,
                ];
            } else {
                try {
                    $args = array_merge(['-overwrite_original', '-preserve'], $exifWriteArgs);
                    $cmd = 'exiftool '.implode(' ', array_map('escapeshellarg', $args)).' '.escapeshellarg($localPath);
                    $result = \Illuminate\Support\Facades\Process::timeout(30)->run($cmd);
                    $writeResult = [
                        'success' => $result->successful(),
                        'output' => trim($result->output().' '.$result->errorOutput()),
                    ];
                    if ($result->successful()) {
                        // Mark as written since we just did it
                        DB::update('UPDATE file_registry SET exif_written = 1, exif_tags_written = 1 WHERE id = ?', [$file->id]);
                        // Re-read EXIF to sync DB with what was actually written
                        $this->syncExifToDb($file->id, $localPath);
                    }
                } catch (\Exception $e) {
                    $writeResult = ['success' => false, 'error' => $e->getMessage()];
                }
            }
        }

        // Update RAG content if provided
        if ($request->has('rag_content')) {
            try {
                $ragContent = $request->input('rag_content');
                $updated = DB::connection('pgsql_rag')->update("
                    UPDATE rag_documents SET content = ?, updated_at = NOW()
                    WHERE metadata->>'asset_uuid' = ? OR metadata->>'file_path' = ?
                ", [$ragContent, $uuid, $file->current_path]);
                if ($updated === 0) {
                    // No existing doc — could create one, but for now just note it
                    Log::info('RAG content update: no matching document found', ['uuid' => $uuid]);
                }
            } catch (\Exception $e) {
                Log::warning('RAG content update failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            }
        }

        // Return updated record
        $updatedFile = DB::selectOne('SELECT * FROM file_registry WHERE id = ?', [$file->id]);

        return response()->json([
            'success' => true,
            'data' => (array) $updatedFile,
            'file_written' => $writeResult,
        ]);
    }

    /**
     * Re-read EXIF from physical file and sync key fields to DB
     */
    private function syncExifToDb(int $fileId, string $localPath): void
    {
        try {
            $result = \Illuminate\Support\Facades\Process::timeout(10)
                ->run([
                    'exiftool',
                    '-json',
                    '-n',
                    '-DateTimeOriginal',
                    '-CreateDate',
                    '-GPSLatitude',
                    '-GPSLongitude',
                    '-Copyright',
                    '-ImageDescription',
                    $localPath,
                ]);
            if (! $result->successful()) {
                return;
            }
            $parsed = json_decode($result->output(), true);
            if (empty($parsed[0])) {
                return;
            }
            $exif = $parsed[0];

            $syncUpdates = [];
            $syncParams = [];

            if (! empty($exif['DateTimeOriginal'])) {
                $dt = str_replace(':', '-', substr($exif['DateTimeOriginal'], 0, 10)).substr($exif['DateTimeOriginal'], 10);
                $syncUpdates[] = 'date_taken = ?';
                $syncParams[] = $dt;
                $syncUpdates[] = "date_taken_source = 'exif_original'";
                $syncUpdates[] = 'date_taken_confidence = 0.98';
            }

            if (! empty($syncUpdates)) {
                $syncParams[] = $fileId;
                DB::update('UPDATE file_registry SET '.implode(', ', $syncUpdates).' WHERE id = ?', $syncParams);
            }
        } catch (\Exception $e) {
            Log::debug('syncExifToDb failed: '.$e->getMessage());
        }
    }

    /**
     * Enrich face records with person names from XMP region metadata.
     * Matches AI-detected face regions to XMP regions by center-point proximity,
     * and fills in person_name from XMP PersonDisplayName when the face record
     * has no name yet.
     */
    private function enrichFaceNamesFromXmp(array &$faces, array $exifData): void
    {
        $xmpRegions = [];

        foreach ($exifData as $key => $val) {
            if (! is_array($val)) {
                continue;
            }

            // Microsoft Photo format: { Regions: [{ PersonDisplayName, Rectangle: "x,y,w,h" }] }
            if (isset($val['Regions']) && is_array($val['Regions'])) {
                foreach ($val['Regions'] as $region) {
                    $region = (array) $region;
                    if (empty($region['PersonDisplayName']) || empty($region['Rectangle'])) {
                        continue;
                    }
                    $parts = array_map('floatval', preg_split('/[\s,]+/', trim($region['Rectangle'])));
                    if (count($parts) === 4) {
                        $xmpRegions[] = [
                            'name' => $region['PersonDisplayName'],
                            'x' => $parts[0], 'y' => $parts[1], 'w' => $parts[2], 'h' => $parts[3],
                        ];
                    }
                }
            }

            // MWG-RS format: { RegionList: [{ Name, Area: { X, Y, W, H } }] }
            if (isset($val['RegionList']) && is_array($val['RegionList'])) {
                foreach ($val['RegionList'] as $region) {
                    $region = (array) $region;
                    if (empty($region['Name']) || empty($region['Area'])) {
                        continue;
                    }
                    $area = (array) $region['Area'];
                    $xmpRegions[] = [
                        'name' => $region['Name'],
                        'x' => floatval($area['X'] ?? $area['x'] ?? 0),
                        'y' => floatval($area['Y'] ?? $area['y'] ?? 0),
                        'w' => floatval($area['W'] ?? $area['w'] ?? 0),
                        'h' => floatval($area['H'] ?? $area['h'] ?? 0),
                    ];
                }
            }
        }

        if (empty($xmpRegions)) {
            return;
        }

        $usedXmp = [];
        foreach ($faces as $face) {
            if (! empty($face->person_name)) {
                continue;
            }

            $bestIdx = null;
            $bestDist = 0.15; // Max 15% center-point distance

            foreach ($xmpRegions as $idx => $xmp) {
                if (isset($usedXmp[$idx])) {
                    continue;
                }
                $faceCx = $face->region_x + $face->region_w / 2;
                $faceCy = $face->region_y + $face->region_h / 2;
                $xmpCx = $xmp['x'] + $xmp['w'] / 2;
                $xmpCy = $xmp['y'] + $xmp['h'] / 2;
                $dist = sqrt(pow($faceCx - $xmpCx, 2) + pow($faceCy - $xmpCy, 2));

                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestIdx = $idx;
                }
            }

            if ($bestIdx !== null) {
                $usedXmp[$bestIdx] = true;
                $name = $xmpRegions[$bestIdx]['name'];
                DB::update("UPDATE file_registry_faces SET person_name = ? WHERE id = ? AND (person_name IS NULL OR person_name = '')", [
                    $name, $face->id,
                ]);
                $face->person_name = $name;
            }
        }
    }

    /**
     * Get full metadata for a media item (for editing)
     *
     * GET /api/media/{uuid}/metadata
     */
    public function getMetadata(string $uuid): JsonResponse
    {
        $file = DB::selectOne("
            SELECT fr.*
            FROM file_registry fr
            WHERE fr.asset_uuid = ? AND fr.status = 'active'
        ", [$uuid]);

        if (! $file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        // Get faces
        $faces = DB::select('
            SELECT frf.id, frf.person_name, frf.genealogy_person_id,
                   frf.region_x, frf.region_y, frf.region_w, frf.region_h,
                   frf.confidence, frf.verified,
                   gp.given_name, gp.surname
            FROM file_registry_faces frf
            LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
            WHERE frf.file_registry_id = ?
        ', [$file->id]);

        // Get linked persons (via genealogy_person_media)
        $linkedPersons = DB::select('
            SELECT gp.id, gp.given_name, gp.surname
            FROM genealogy_person_media gpm
            JOIN genealogy_media gm ON gpm.media_id = gm.id
            JOIN genealogy_persons gp ON gpm.person_id = gp.id
            WHERE gm.nextcloud_path = ?
        ', [$file->current_path]);

        // Get RAG document with full content (PostgreSQL)
        $ragDoc = DB::connection('pgsql_rag')->selectOne("
            SELECT id, source_type, document_type, title, content, designation,
                   context_prefix,
                   metadata::text as metadata_json,
                   octet_length(content) as content_bytes,
                   created_at, updated_at, contextualized_at, raptor_indexed_at
            FROM rag_documents
            WHERE metadata->>'asset_uuid' = ? OR metadata->>'file_path' = ?
            ORDER BY updated_at DESC LIMIT 1
        ", [$uuid, $file->current_path]);

        $ragChunks = [];
        if ($ragDoc) {
            $ragChunks = DB::connection('pgsql_rag')->select('
                SELECT id, LEFT(sentence_text, 200) as content_preview,
                       octet_length(sentence_text) as chunk_bytes,
                       sentence_index
                FROM rag_sentence_embeddings
                WHERE document_id = ?
                ORDER BY sentence_index ASC
                LIMIT 50
            ', [$ragDoc->id]);
        }

        // Get perceptual hash data
        $phash = DB::selectOne('
            SELECT phash_hex, dhash_hex, computed_at
            FROM file_registry_perceptual_hashes
            WHERE file_registry_id = ?
        ', [$file->id]);

        // Read raw EXIF/XMP from physical file via exiftool
        $exifData = [];
        $localPath = $this->resolveLocalPath($file->current_path);
        if ($localPath && file_exists($localPath)) {
            try {
                $result = \Illuminate\Support\Facades\Process::timeout(10)
                    ->run([
                        'exiftool',
                        '-json',
                        '-G1',
                        '-struct',
                        '-n',
                        $localPath,
                    ]);
                if ($result->successful()) {
                    $parsed = json_decode($result->output(), true);
                    if (is_array($parsed) && ! empty($parsed[0])) {
                        $exifData = $parsed[0];
                        // Remove SourceFile (full path) for privacy
                        unset($exifData['SourceFile']);
                    }
                }
            } catch (\Exception $e) {
                Log::debug('EXIF read failed for '.$uuid.': '.$e->getMessage());
            }
        }

        // Enrich face records from XMP region names when person_name is missing
        $this->enrichFaceNamesFromXmp($faces, $exifData);

        // Build organized response
        $fileData = (array) $file;
        // Decode JSON fields for frontend
        foreach (['tags', 'ai_tags', 'chunk_hashes', 'thumbnail_sizes'] as $jsonField) {
            if (isset($fileData[$jsonField]) && is_string($fileData[$jsonField])) {
                $fileData[$jsonField] = json_decode($fileData[$jsonField], true);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'file' => $fileData,
                'exif' => $exifData,
                'faces' => array_map(fn ($f) => [
                    'id' => $f->id,
                    'person_name' => $f->person_name,
                    'genealogy_person_id' => $f->genealogy_person_id,
                    'genealogy_name' => $f->given_name ? trim($f->given_name.' '.$f->surname) : null,
                    'region_x' => (float) $f->region_x,
                    'region_y' => (float) $f->region_y,
                    'region_w' => (float) $f->region_w,
                    'region_h' => (float) $f->region_h,
                    'confidence' => (float) ($f->confidence ?? 0),
                    'verified' => (bool) $f->verified,
                ], $faces),
                'linked_persons' => array_map(fn ($p) => [
                    'id' => $p->id,
                    'name' => trim($p->given_name.' '.$p->surname),
                ], $linkedPersons),
                'perceptual_hash' => $phash ? [
                    'phash' => $phash->phash_hex,
                    'dhash' => $phash->dhash_hex,
                    'computed_at' => $phash->computed_at,
                ] : null,
                'rag' => $ragDoc ? [
                    'indexed' => true,
                    'source_type' => $ragDoc->source_type,
                    'document_type' => $ragDoc->document_type,
                    'title' => $ragDoc->title,
                    'designation' => $ragDoc->designation,
                    'content' => $ragDoc->content,
                    'content_bytes' => (int) $ragDoc->content_bytes,
                    'context_prefix' => $ragDoc->context_prefix,
                    'metadata' => json_decode($ragDoc->metadata_json, true),
                    'chunk_count' => count($ragChunks),
                    'chunks' => array_map(fn ($c) => [
                        'id' => $c->id,
                        'preview' => $c->content_preview,
                        'bytes' => (int) $c->chunk_bytes,
                    ], $ragChunks),
                    'indexed_at' => $ragDoc->updated_at,
                    'created_at' => $ragDoc->created_at,
                    'contextualized_at' => $ragDoc->contextualized_at,
                    'raptor_indexed_at' => $ragDoc->raptor_indexed_at,
                ] : ['indexed' => false],
            ],
        ]);
    }

    // =========================================================================
    // Image Editing
    // =========================================================================

    /**
     * Apply edits to an image and save
     *
     * POST /api/media/{uuid}/edit
     */
    public function editImage(Request $request, string $uuid): JsonResponse
    {
        $operations = $request->input('operations', []);
        if (empty($operations)) {
            return response()->json(['error' => 'No operations provided'], 400);
        }

        $file = $this->getFileRecord($uuid);
        if (! $file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        if (! str_starts_with($file->mime_type, 'image/')) {
            return response()->json(['error' => 'Not an image file'], 400);
        }

        $localPath = $this->resolveLocalPath($file->current_path);
        if (! $localPath || ! file_exists($localPath)) {
            return response()->json(['error' => 'Physical file not found'], 404);
        }

        try {
            // Record current version before editing
            $fileSize = filesize($localPath);
            $hash = md5_file($localPath);
            $description = 'Pre-edit: '.implode(', ', array_column($operations, 'type'));
            $this->getFileVersion()->recordVersion($file->id, $file->current_path, $fileSize, $hash, $description);

            // Read source, apply pipeline, write back
            $sourceData = file_get_contents($localPath);
            $editedData = $this->getImageEditor()->pipeline($sourceData, $operations);
            file_put_contents($localPath, $editedData);

            $this->invalidateContentDerivedData($file, $uuid, 'image_edit');

            // Invalidate and regenerate thumbnail files after derived DB state is cleared.
            $this->thumbnailService->deleteThumbnails($uuid);
            $this->thumbnailService->generateThumbnail($uuid, 'medium');

            // Update file_registry size
            $newSize = strlen($editedData);
            DB::update('UPDATE file_registry SET file_size = ? WHERE id = ?', [$newSize, $file->id]);

            return response()->json([
                'success' => true,
                'operations_applied' => count($operations),
                'new_size' => $newSize,
            ]);
        } catch (\Exception $e) {
            Log::error('Image edit failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Edit failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Preview edits without saving (returns image binary)
     *
     * POST /api/media/{uuid}/edit-preview
     */
    public function editPreview(Request $request, string $uuid): Response
    {
        $operations = $request->input('operations', []);

        $file = $this->getFileRecord($uuid);
        if (! $file) {
            return response('File not found', 404);
        }

        if (! str_starts_with($file->mime_type, 'image/')) {
            return response('Not an image file', 400);
        }

        $localPath = $this->resolveLocalPath($file->current_path);
        if (! $localPath || ! file_exists($localPath)) {
            return response('Physical file not found', 404);
        }

        try {
            $sourceData = file_get_contents($localPath);
            $previewData = empty($operations)
                ? $sourceData
                : $this->getImageEditor()->pipeline($sourceData, $operations);

            return response($previewData, 200, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'no-cache',
            ]);
        } catch (\Exception $e) {
            Log::error('Image preview failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return response('Preview failed: '.$e->getMessage(), 500);
        }
    }

    /**
     * Get version history for a file
     *
     * GET /api/media/{uuid}/versions
     */
    public function versions(string $uuid): JsonResponse
    {
        $file = $this->getFileRecord($uuid);
        if (! $file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $versions = $this->getFileVersion()->getVersionHistory($file->id);

        return response()->json([
            'success' => true,
            'data' => array_map(fn ($v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'file_size' => $v->file_size,
                'content_hash' => $v->content_hash,
                'change_description' => $v->change_description,
                'created_at' => $v->created_at,
            ], $versions),
        ]);
    }

    /**
     * Restore a previous version
     *
     * POST /api/media/{uuid}/versions/{versionId}/restore
     */
    public function restoreVersion(Request $request, string $uuid, int $versionId): JsonResponse
    {
        $file = $this->getFileRecord($uuid);
        if (! $file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $version = DB::selectOne(
            'SELECT * FROM file_versions WHERE id = ? AND file_registry_id = ?',
            [$versionId, $file->id]
        );

        if (! $version) {
            return response()->json(['error' => 'Version not found'], 404);
        }

        // The version stores the Nextcloud path at time of snapshot.
        // For image edits, the path hasn't changed - we need to check if a backup exists.
        // FileVersionService records the path but doesn't copy the file data.
        // For rollback to work with edited images, we'd need the actual file content.
        // Currently we can only rollback metadata - flag this limitation.
        $result = $this->getFileVersion()->rollbackToVersion($file->id, $versionId);

        if ($result['success']) {
            $this->invalidateContentDerivedData($file, $uuid, 'version_restore');

            // Invalidate thumbnails so they regenerate from current file state.
            $this->thumbnailService->deleteThumbnails($uuid);
        }

        return response()->json($result);
    }

    private function invalidateContentDerivedData(object $file, string $uuid, string $operation): void
    {
        try {
            $this->fileLifecycle->invalidateDerivedData((int) $file->id, $uuid);
        } catch (\Exception $e) {
            Log::warning('MediaBrowser content mutation did not fully invalidate derived data', [
                'asset_uuid' => $uuid,
                'file_registry_id' => $file->id ?? null,
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get file record from registry by UUID
     */
    private function getFileRecord(string $uuid): ?object
    {
        return DB::selectOne(
            "SELECT id, asset_uuid, current_path, mime_type, file_size FROM file_registry WHERE asset_uuid = ? AND status = 'active'",
            [$uuid]
        );
    }

    /**
     * Resolve Nextcloud path to local filesystem path
     */
    private function resolveLocalPath(string $ncPath): ?string
    {
        $dataPath = trim((string) config('services.nextcloud.data_path', ''));
        if ($dataPath === '') {
            return null;
        }

        $libraryRoot = '/'.trim((string) config('services.nextcloud.library_root', '/Library'), '/');
        $ncPath = '/'.ltrim($ncPath, '/');
        $basePath = rtrim($dataPath, '/').$libraryRoot;
        $relativePath = str_starts_with($ncPath, $libraryRoot)
            ? substr($ncPath, strlen($libraryRoot))
            : ltrim($ncPath, '/');

        $segments = [];
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' || str_contains($segment, "\0")) {
                return null;
            }
            $segments[] = $segment;
        }

        if (str_starts_with($ncPath, $libraryRoot)) {
            return $basePath.(empty($segments) ? '' : '/'.implode('/', $segments));
        }

        return $basePath.(empty($segments) ? '' : '/'.implode('/', $segments));
    }

    private function nextcloudLibraryRoot(): string
    {
        return '/'.trim((string) config('services.nextcloud.library_root', '/Library'), '/');
    }

    /**
     * Get media type from MIME type
     */
    private function getMediaType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            $mimeType === 'application/pdf' => 'document',
            str_contains($mimeType, 'epub') => 'ebook',
            default => 'other',
        };
    }

    /**
     * Get MIME types for media type filter
     */
    private function getMimeTypesForType(string $type): array
    {
        return match ($type) {
            'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/tiff', 'image/bmp'],
            'video' => ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/mpeg'],
            'audio' => ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/flac', 'audio/aac'],
            'document' => ['application/pdf'],
            'ebook' => ['application/epub+zip', 'application/x-mobipocket-ebook'],
            default => [],
        };
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1).' '.$units[$i];
    }

    /**
     * Add folder to tree structure
     */
    private function addToTree(array &$tree, array $parts, int $count): void
    {
        if (empty($parts)) {
            return;
        }

        $current = array_shift($parts);
        if (! isset($tree[$current])) {
            $tree[$current] = ['children' => [], 'count' => 0];
        }
        $tree[$current]['count'] += $count;

        if (! empty($parts)) {
            $this->addToTree($tree[$current]['children'], $parts, $count);
        }
    }

    // =========================================================================
    // AI Face Clusters (pgvector)
    // =========================================================================

    /**
     * Get face clusters for review
     *
     * GET /api/media/face-clusters
     */
    public function faceClusters(Request $request): JsonResponse
    {
        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        // Support both legacy status param and new filter param
        $filter = $request->input('filter', $request->input('status', 'all'));
        // Map legacy status values to unified filter
        if ($filter === 'unreviewed') {
            $filter = 'unidentified';
        }
        if ($filter === 'confirmed') {
            $filter = 'identified';
        }
        if (in_array($filter, ['ignored', 'hidden'])) {
            $filter = 'hidden';
        }

        $sort = $request->input('sort', 'size_desc');
        $minFaces = (int) $request->input('min_faces', 1);
        $limit = min((int) $request->input('limit', 50), 200);
        $offset = max((int) $request->input('offset', 0), 0);

        $result = $faceEmbedding->getUnifiedClusters($filter, $sort, $limit, $offset, $minFaces);
        $stats = $faceEmbedding->getStats();

        return response()->json([
            'success' => true,
            'data' => $result['clusters'],
            'total' => $result['total'],
            'stats' => $stats,
            'has_more' => count($result['clusters']) >= $limit,
        ]);
    }

    /**
     * Get face counts per genealogy person (for person picker display)
     *
     * GET /api/media/face-counts-by-person
     */
    public function faceCountsByPerson(): JsonResponse
    {
        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);
        $counts = $faceEmbedding->getFaceCountsByGenealogyPerson();

        return response()->json([
            'success' => true,
            'data' => $counts,
        ]);
    }

    /**
     * Get single face cluster
     *
     * GET /api/media/face-clusters/{id}
     */
    public function faceCluster(int $id): JsonResponse
    {
        try {
            $cluster = DB::connection('pgsql_rag')->selectOne('
                SELECT pc.*,
                    (SELECT COUNT(*) FROM face_embeddings WHERE person_cluster_id = pc.id) as total_faces
                FROM person_clusters pc
                WHERE pc.id = ?
            ', [$id]);

            if (! $cluster) {
                return response()->json(['error' => 'Cluster not found'], 404);
            }

            // Get all faces in this cluster
            $faces = DB::connection('pgsql_rag')->select('
                SELECT fe.id, fe.file_registry_id, fe.crop_path, fe.match_confidence,
                    fe.region_x, fe.region_y, fe.region_w, fe.region_h
                FROM face_embeddings fe
                WHERE fe.person_cluster_id = ?
                ORDER BY fe.match_confidence DESC NULLS LAST
            ', [$id]);

            return response()->json([
                'success' => true,
                'data' => [
                    'cluster' => (array) $cluster,
                    'faces' => array_map(fn ($f) => (array) $f, $faces),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Confirm a face cluster
     *
     * POST /api/media/face-clusters/{id}/confirm
     */
    public function confirmCluster(Request $request, int $id): JsonResponse
    {
        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        $name = $request->input('name');
        $genealogyPersonId = (int) $request->input('genealogy_person_id', 0);
        $writeToMedia = $request->boolean('write_to_media', true);

        if ($genealogyPersonId > 0 && ! $this->findGenealogyPerson($genealogyPersonId, (int) $request->input('tree_id', 0))) {
            return $this->invalidGenealogyPersonResponse($genealogyPersonId);
        }

        if (! $name) {
            return response()->json(['error' => 'Name is required'], 400);
        }

        $result = $faceEmbedding->confirmCluster($id, $name, $genealogyPersonId, $writeToMedia);
        $bridgeResult = null;
        if ($result && $genealogyPersonId) {
            $bridgeResult = app(FaceLinkBridgeService::class)->syncClusterLinks($id, (int) $genealogyPersonId);
        }

        // Auto-propagate: find and merge similar clusters
        $propagationResult = null;
        if ($result && $request->boolean('auto_propagate', true)) {
            $propagationResult = $faceEmbedding->propagateClusterMatches($id);
        }

        return response()->json([
            'success' => $result,
            'message' => $result ? 'Cluster confirmed' : 'Failed to confirm cluster',
            'propagation' => $propagationResult,
            'genealogy_bridge' => $bridgeResult,
        ]);
    }

    /**
     * Link cluster to genealogy person
     *
     * POST /api/media/face-clusters/{id}/link
     */
    public function linkClusterToGenealogy(Request $request, int $id): JsonResponse
    {
        $genealogyPersonId = (int) $request->input('genealogy_person_id', 0);

        if (! $genealogyPersonId) {
            return response()->json(['error' => 'genealogy_person_id is required'], 400);
        }

        $person = $this->findGenealogyPerson($genealogyPersonId, (int) $request->input('tree_id', 0));
        if (! $person) {
            return $this->invalidGenealogyPersonResponse($genealogyPersonId);
        }

        $name = trim($person->given_name.' '.$person->surname);

        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);
        $result = $faceEmbedding->confirmCluster($id, $name, $genealogyPersonId, true);
        $bridgeResult = null;
        if ($result) {
            $bridgeResult = app(FaceLinkBridgeService::class)->syncClusterLinks($id, (int) $genealogyPersonId);
        }

        return response()->json([
            'success' => $result,
            'message' => $result ? 'Cluster linked to genealogy' : 'Failed to link cluster',
            'genealogy_bridge' => $bridgeResult,
        ]);
    }

    /**
     * Batch confirm multiple clusters as the same person
     *
     * POST /api/media/face-clusters/batch-confirm
     */
    public function batchConfirmClusters(Request $request): JsonResponse
    {
        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        $clusterIds = $request->input('cluster_ids', []);
        $name = $request->input('name');
        $genealogyPersonId = (int) $request->input('genealogy_person_id', 0);
        $writeToMedia = $request->boolean('write_to_media', true);
        $autoPropagateEnabled = $request->boolean('auto_propagate', true);

        if (empty($clusterIds) || ! $name) {
            return response()->json(['error' => 'cluster_ids and name are required'], 400);
        }

        if ($genealogyPersonId > 0 && ! $this->findGenealogyPerson($genealogyPersonId, (int) $request->input('tree_id', 0))) {
            return $this->invalidGenealogyPersonResponse($genealogyPersonId);
        }

        $results = [];
        $succeeded = 0;
        $failed = 0;

        foreach ($clusterIds as $clusterId) {
            try {
                $result = $faceEmbedding->confirmCluster((int) $clusterId, $name, $genealogyPersonId, $writeToMedia);

                $propagation = null;
                if ($result && $autoPropagateEnabled) {
                    $propagation = $faceEmbedding->propagateClusterMatches((int) $clusterId);
                }

                $bridgeResult = null;
                if ($result && $genealogyPersonId > 0) {
                    $bridgeResult = app(FaceLinkBridgeService::class)->syncClusterLinks((int) $clusterId, $genealogyPersonId);
                }

                $results[] = [
                    'cluster_id' => $clusterId,
                    'success' => $result,
                    'propagation' => $propagation,
                    'genealogy_bridge' => $bridgeResult,
                ];
                $result ? $succeeded++ : $failed++;
            } catch (\Throwable $e) {
                $results[] = [
                    'cluster_id' => $clusterId,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                $failed++;
            }
        }

        return response()->json([
            'success' => $failed === 0,
            'message' => "{$succeeded} clusters confirmed as \"{$name}\"".($failed ? ", {$failed} failed" : ''),
            'results' => $results,
            'confirmed' => $succeeded,
            'failed' => $failed,
        ]);
    }

    /**
     * Merge multiple clusters
     *
     * POST /api/media/face-clusters/merge
     */
    public function mergeClusters(Request $request): JsonResponse
    {
        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        $targetId = $request->input('target_id');
        $sourceIds = $request->input('source_ids', []);

        if (! $targetId || empty($sourceIds)) {
            return response()->json(['error' => 'target_id and source_ids are required'], 400);
        }

        $result = $faceEmbedding->mergeClusters($targetId, $sourceIds);

        return response()->json([
            'success' => $result,
            'message' => $result ? 'Clusters merged' : 'Failed to merge clusters',
        ]);
    }

    /**
     * Find similar clusters to a confirmed cluster
     *
     * GET /api/media/face-clusters/{id}/similar
     */
    public function similarClusters(Request $request, int $id): JsonResponse
    {
        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        $tolerance = (float) $request->get('tolerance', 0.5);
        $limit = min((int) $request->get('limit', 20), 50);

        try {
            $suggestions = $faceEmbedding->suggestSimilarClusters($id, $tolerance, $limit);

            // Enrich with sample face crops for UI (single query instead of N+1)
            $clusterIds = array_column($suggestions, 'cluster_id');
            $facesMap = [];
            if (! empty($clusterIds)) {
                $placeholders = implode(',', array_fill(0, count($clusterIds), '?'));
                $allFaces = DB::connection('pgsql_rag')->select("
                    SELECT id, crop_path, person_cluster_id
                    FROM face_embeddings
                    WHERE person_cluster_id IN ({$placeholders})
                    AND id IN (
                        SELECT id FROM (
                            SELECT id, person_cluster_id,
                                ROW_NUMBER() OVER (PARTITION BY person_cluster_id ORDER BY created_at DESC) as rn
                            FROM face_embeddings
                            WHERE person_cluster_id IN ({$placeholders})
                        ) sub WHERE rn <= 4
                    )
                ", array_merge($clusterIds, $clusterIds));

                foreach ($allFaces as $f) {
                    $facesMap[$f->person_cluster_id][] = [
                        'id' => $f->id,
                        'crop_url' => "/api/media/face-crop/{$f->id}",
                    ];
                }
            }
            foreach ($suggestions as &$suggestion) {
                $suggestion['sample_faces'] = $facesMap[$suggestion['cluster_id']] ?? [];
            }

            return response()->json([
                'success' => true,
                'cluster_id' => $id,
                'suggestions' => $suggestions,
                'count' => count($suggestions),
            ]);
        } catch (\Exception $e) {
            Log::warning('similarClusters failed', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to find similar clusters'], 500);
        }
    }

    /**
     * Propagate matches from a confirmed cluster
     *
     * POST /api/media/face-clusters/{id}/propagate
     */
    public function propagateMatches(Request $request, int $id): JsonResponse
    {
        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        try {
            $result = $faceEmbedding->propagateClusterMatches($id);

            return response()->json([
                'success' => true,
                'auto_merged' => $result['auto_merged'],
                'suggested' => $result['suggested'],
                'total_found' => $result['total_found'],
            ]);
        } catch (\Exception $e) {
            Log::warning('propagateMatches failed', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to propagate matches'], 500);
        }
    }

    /**
     * Ignore a face cluster (mark as not needing identification)
     *
     * POST /api/media/face-clusters/{id}/ignore
     */
    public function ignoreCluster(Request $request, int $id): JsonResponse
    {
        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        $reason = $request->get('reason', null);

        try {
            $result = $faceEmbedding->ignoreCluster($id, $reason);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Cluster ignored' : 'Failed to ignore cluster',
            ]);
        } catch (\Exception $e) {
            Log::warning('ignoreCluster failed', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to ignore cluster'], 500);
        }
    }

    /**
     * Revert a cluster action (undo confirm/ignore)
     *
     * POST /api/media/face-clusters/{id}/revert
     */
    public function revertCluster(Request $request, int $id): JsonResponse
    {
        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        $previousStatus = $request->input('previous_status', 'unreviewed');
        $previousName = $request->input('previous_name');
        $previousGenealogyPersonId = $request->input('previous_genealogy_person_id');

        try {
            $result = $faceEmbedding->revertCluster($id, $previousStatus, $previousName, $previousGenealogyPersonId);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Cluster reverted' : 'Failed to revert cluster',
            ]);
        } catch (\Exception $e) {
            Log::warning('revertCluster failed', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to revert cluster'], 500);
        }
    }

    /**
     * Split faces out of a cluster into a new one
     *
     * POST /api/media/face-clusters/{id}/split
     */
    public function splitCluster(Request $request, int $id): JsonResponse
    {
        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        $faceIds = $request->input('face_ids', []);

        if (empty($faceIds)) {
            return response()->json(['error' => 'face_ids is required'], 400);
        }

        try {
            $result = $faceEmbedding->splitCluster($id, $faceIds);

            return response()->json($result);
        } catch (\Exception $e) {
            Log::warning('splitCluster failed', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to split cluster'], 500);
        }
    }

    /**
     * Get all faces in a cluster (for split UI)
     *
     * GET /api/media/face-clusters/{id}/faces
     */
    public function clusterFaces(int $id): JsonResponse
    {
        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        try {
            $faces = $faceEmbedding->getClusterFaces($id);

            return response()->json([
                'success' => true,
                'faces' => array_map(fn ($f) => array_merge($f, [
                    'crop_url' => "/api/media/face-crop/{$f['id']}",
                ]), $faces),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to get cluster faces'], 500);
        }
    }

    /**
     * Get photo context for a face — parent image URL + all faces on that image
     *
     * GET /api/media/face/{id}/photo-context
     */
    public function facePhotoContext(int $id): JsonResponse
    {
        try {
            // Get the face and its parent file
            $face = DB::connection('pgsql_rag')->selectOne('
                SELECT id, file_registry_id, person_cluster_id,
                    region_x, region_y, region_w, region_h, match_confidence
                FROM face_embeddings WHERE id = ?
            ', [$id]);

            if (! $face) {
                return response()->json(['error' => 'Face not found'], 404);
            }

            // Get parent file info from MySQL file_registry
            $file = DB::selectOne('
                SELECT id, asset_uuid, filename, current_path
                FROM file_registry WHERE id = ?
            ', [$face->file_registry_id]);

            if (! $file) {
                return response()->json(['error' => 'Parent file not found'], 404);
            }

            // Get ALL faces on this same photo (from face_embeddings)
            $allFaces = DB::connection('pgsql_rag')->select('
                SELECT fe.id, fe.person_cluster_id,
                    fe.region_x, fe.region_y, fe.region_w, fe.region_h,
                    fe.match_confidence,
                    pc.name as cluster_name, pc.status as cluster_status
                FROM face_embeddings fe
                LEFT JOIN person_clusters pc ON pc.id = fe.person_cluster_id
                WHERE fe.file_registry_id = ?
                ORDER BY fe.region_x ASC
            ', [$face->file_registry_id]);

            return response()->json([
                'success' => true,
                'data' => [
                    'face_id' => $face->id,
                    'file' => [
                        'uuid' => $file->asset_uuid,
                        'filename' => $file->filename,
                        'thumbnail_url' => "/api/media/{$file->asset_uuid}/thumbnail/large",
                    ],
                    'faces' => array_map(fn ($f) => [
                        'id' => $f->id,
                        'cluster_id' => $f->person_cluster_id,
                        'cluster_name' => $f->cluster_name,
                        'cluster_status' => $f->cluster_status,
                        'region' => [
                            'x' => (float) $f->region_x,
                            'y' => (float) $f->region_y,
                            'w' => (float) $f->region_w,
                            'h' => (float) $f->region_h,
                        ],
                        'confidence' => $f->match_confidence ? (float) $f->match_confidence : null,
                        'is_current' => $f->id === $face->id,
                    ], $allFaces),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Serve face crop image from face_embeddings table
     *
     * GET /api/media/face-crop/{id}
     */
    public function serveFaceCrop(int $id): \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $face = DB::connection('pgsql_rag')->selectOne('
                SELECT crop_path, file_registry_face_id FROM face_embeddings WHERE id = ?
            ', [$id]);

            if (! $face) {
                $placeholder = public_path('images/face-placeholder.png');
                if (file_exists($placeholder)) {
                    return response()->file($placeholder);
                }

                return response('Face not found', 404);
            }

            // Serve pre-computed crop if available
            if ($face->crop_path && file_exists($face->crop_path)) {
                return response()->file($face->crop_path, [
                    'Content-Type' => 'image/jpeg',
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }

            // Fall back to generating crop from file_registry_faces
            if ($face->file_registry_face_id) {
                return $this->serveFaceRegistryCrop($face->file_registry_face_id);
            }

            $placeholder = public_path('images/face-placeholder.png');
            if (file_exists($placeholder)) {
                return response()->file($placeholder);
            }

            return response('Face not found', 404);
        } catch (\Exception $e) {
            return response('Error: '.$e->getMessage(), 500);
        }
    }

    /**
     * Serve face crop from genealogy_face_match_queue (for Research Hub)
     *
     * GET /api/media/face-match-crop/{id}
     */
    public function serveFaceMatchCrop(int $id): \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $match = DB::selectOne('
                SELECT q.media_id, q.face_region,
                       COALESCE(m.nextcloud_path, m.original_path) as media_path
                FROM genealogy_face_match_queue q
                JOIN genealogy_media m ON m.id = q.media_id
                WHERE q.id = ?
            ', [$id]);

            if (! $match || ! $match->media_path) {
                return $this->returnFacePlaceholder();
            }

            // Resolve the full path
            $basePath = trim((string) config('services.nextcloud.data_path', ''));
            $fullPath = $basePath === '' ? '' : rtrim($basePath, '/').'/'.ltrim($match->media_path, '/');

            // Alternative: resolve through the configured library root.
            if ($fullPath === '' || ! file_exists($fullPath)) {
                $fullPath = $this->resolveLocalPath($match->media_path);
            }

            if (! $fullPath || ! file_exists($fullPath)) {
                return $this->returnFacePlaceholder();
            }

            // If we have face_region, crop the image
            if ($match->face_region) {
                $region = is_string($match->face_region) ? json_decode($match->face_region, true) : (array) $match->face_region;

                if ($region && isset($region['x'], $region['y'], $region['w'], $region['h'])) {
                    return $this->cropAndServeFace($fullPath, $region);
                }
            }

            // No region, serve full image as thumbnail
            return $this->serveImageThumbnail($fullPath, 128);

        } catch (\Exception $e) {
            Log::warning('serveFaceMatchCrop failed', ['id' => $id, 'error' => $e->getMessage()]);

            return $this->returnFacePlaceholder();
        }
    }

    /**
     * Crop face from image and return as response
     */
    private function cropAndServeFace(string $imagePath, array $region): \Illuminate\Http\Response
    {
        try {
            $image = imagecreatefromstring(file_get_contents($imagePath));
            if (! $image) {
                return $this->returnFacePlaceholder();
            }

            $width = imagesx($image);
            $height = imagesy($image);

            // Region is in percentage (0-1), convert to pixels
            $x = (int) ($region['x'] * $width);
            $y = (int) ($region['y'] * $height);
            $w = (int) ($region['w'] * $width);
            $h = (int) ($region['h'] * $height);

            // Add padding (20%)
            $padding = 0.2;
            $padX = (int) ($w * $padding);
            $padY = (int) ($h * $padding);
            $x = max(0, $x - $padX);
            $y = max(0, $y - $padY);
            $w = min($width - $x, $w + 2 * $padX);
            $h = min($height - $y, $h + 2 * $padY);

            // Create cropped image
            $cropped = imagecreatetruecolor($w, $h);
            imagecopy($cropped, $image, 0, 0, $x, $y, $w, $h);

            // Resize to max 128x128
            $maxSize = 128;
            $scale = min($maxSize / $w, $maxSize / $h, 1);
            $newW = (int) ($w * $scale);
            $newH = (int) ($h * $scale);

            $resized = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($resized, $cropped, 0, 0, 0, 0, $newW, $newH, $w, $h);

            // Output as JPEG
            ob_start();
            imagejpeg($resized, null, 85);
            $imageData = ob_get_clean();

            imagedestroy($image);
            imagedestroy($cropped);
            imagedestroy($resized);

            return response($imageData, 200, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        } catch (\Exception $e) {
            Log::warning('cropAndServeFace failed', ['path' => $imagePath, 'error' => $e->getMessage()]);

            return $this->returnFacePlaceholder();
        }
    }

    /**
     * Serve image as thumbnail
     */
    private function serveImageThumbnail(string $imagePath, int $maxSize): \Illuminate\Http\Response
    {
        try {
            $image = imagecreatefromstring(file_get_contents($imagePath));
            if (! $image) {
                return $this->returnFacePlaceholder();
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min($maxSize / $width, $maxSize / $height, 1);
            $newW = (int) ($width * $scale);
            $newH = (int) ($height * $scale);

            $resized = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);

            ob_start();
            imagejpeg($resized, null, 85);
            $imageData = ob_get_clean();

            imagedestroy($image);
            imagedestroy($resized);

            return response($imageData, 200, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        } catch (\Exception $e) {
            return $this->returnFacePlaceholder();
        }
    }

    /**
     * Return placeholder image for faces
     */
    private function returnFacePlaceholder(): \Illuminate\Http\Response|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $placeholder = public_path('images/face-placeholder.png');
        if (file_exists($placeholder)) {
            return response()->file($placeholder, ['Cache-Control' => 'no-cache, no-store']);
        }

        // Generate a simple placeholder
        $img = imagecreatetruecolor(64, 64);
        $bg = imagecolorallocate($img, 60, 50, 80);
        $fg = imagecolorallocate($img, 150, 130, 170);
        imagefill($img, 0, 0, $bg);
        imageellipse($img, 32, 24, 24, 24, $fg);
        imageellipse($img, 32, 48, 36, 24, $fg);

        ob_start();
        imagepng($img);
        $imageData = ob_get_clean();
        imagedestroy($img);

        return response($imageData, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-cache, no-store',
        ]);
    }

    /**
     * Get EXIF writeback stats
     *
     * GET /api/media/writeback/stats
     */
    public function writebackStats(): JsonResponse
    {
        $stats = $this->exifWriteback->getStats();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Write metadata back to a single file
     *
     * POST /api/media/writeback/{uuid}
     */
    public function writebackFile(Request $request, string $uuid): JsonResponse
    {
        // Get file from registry
        $file = DB::selectOne("
            SELECT id, current_path, filename, extension
            FROM file_registry
            WHERE asset_uuid = ?
            AND status = 'active'
        ", [$uuid]);

        if (! $file) {
            return response()->json(['success' => false, 'error' => 'File not found'], 404);
        }

        // Check extension
        $allowedExts = ['jpg', 'jpeg', 'png', 'tiff', 'webp', 'heic'];
        if (! in_array(strtolower($file->extension), $allowedExts)) {
            return response()->json(['success' => false, 'error' => 'Unsupported file type'], 400);
        }

        // Build local path
        $ncPath = $file->current_path;
        $localPath = $this->resolveLocalPath($ncPath);

        if (! $localPath || ! file_exists($localPath)) {
            return response()->json(['success' => false, 'error' => 'Physical file not found'], 404);
        }

        try {
            $result = $this->exifWriteback->writeAll($file->id, $localPath);

            // Update tracking columns
            if ($result['date']['success'] ?? false) {
                DB::update('UPDATE file_registry SET exif_written = 1, exif_date_written_at = NOW() WHERE id = ?', [$file->id]);
            }
            if ($result['faces']['success'] ?? false) {
                DB::update('UPDATE file_registry SET exif_faces_written = 1, exif_faces_written_at = NOW() WHERE id = ?', [$file->id]);
            }
            if ($result['tags']['success'] ?? false) {
                DB::update('UPDATE file_registry SET exif_tags_written = 1, exif_tags_written_at = NOW() WHERE id = ?', [$file->id]);
            }

            return response()->json([
                'success' => $result['success'],
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Writeback error', ['uuid' => $uuid, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Rename a file - updates physical file and all DB references
     *
     * POST /api/media/{uuid}/rename
     */
    public function renameFile(Request $request, string $uuid): JsonResponse
    {
        $newFilename = trim($request->input('filename', ''));
        if (! $newFilename) {
            return response()->json(['success' => false, 'error' => 'Filename required'], 400);
        }

        // Sanitize filename - remove dangerous characters
        $newFilename = preg_replace('/[\/\\\\<>:"|?*]/', '', $newFilename);
        if (! $newFilename) {
            return response()->json(['success' => false, 'error' => 'Invalid filename'], 400);
        }

        $file = DB::selectOne("
            SELECT id, current_path, filename, extension
            FROM file_registry
            WHERE asset_uuid = ? AND status = 'active'
        ", [$uuid]);

        if (! $file) {
            return response()->json(['success' => false, 'error' => 'File not found'], 404);
        }

        $oldNcPath = $file->current_path;
        $oldLocalPath = $this->resolveLocalPath($oldNcPath);

        // Build new path (same directory, new filename)
        $dir = dirname($oldNcPath);
        $newNcPath = $dir.'/'.$newFilename;
        $newLocalPath = $this->resolveLocalPath($newNcPath);

        // Check if source exists
        if (! $oldLocalPath || ! file_exists($oldLocalPath)) {
            return response()->json(['success' => false, 'error' => 'Physical file not found'], 404);
        }

        // Check if target already exists
        if ($newLocalPath && $oldLocalPath !== $newLocalPath && file_exists($newLocalPath)) {
            return response()->json(['success' => false, 'error' => 'A file with that name already exists'], 409);
        }

        if (! $newLocalPath) {
            return response()->json(['success' => false, 'error' => 'Resolved target path is invalid'], 400);
        }

        // Rename physical file
        if ($oldLocalPath !== $newLocalPath) {
            if (! @rename($oldLocalPath, $newLocalPath)) {
                return response()->json(['success' => false, 'error' => 'Failed to rename physical file'], 500);
            }
        }

        // Derive new extension
        $dotIdx = strrpos($newFilename, '.');
        $newExtension = $dotIdx !== false ? strtolower(substr($newFilename, $dotIdx + 1)) : $file->extension;

        // Update file_registry
        DB::update('
            UPDATE file_registry
            SET filename = ?, current_path = ?, extension = ?, updated_at = NOW()
            WHERE id = ?
        ', [$newFilename, $newNcPath, $newExtension, $file->id]);

        // Update genealogy_media_scan_log (path reference)
        DB::update('
            UPDATE genealogy_media_scan_log SET nextcloud_path = ? WHERE nextcloud_path = ?
        ', [$newNcPath, $oldNcPath]);

        // Update genealogy_media references
        DB::update('
            UPDATE genealogy_media SET nextcloud_path = ? WHERE nextcloud_path = ?
        ', [$newNcPath, $oldNcPath]);

        Log::info('File renamed', [
            'uuid' => $uuid,
            'old' => $file->filename,
            'new' => $newFilename,
            'old_path' => $oldNcPath,
            'new_path' => $newNcPath,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'filename' => $newFilename,
                'path' => $newNcPath,
                'extension' => $newExtension,
            ],
        ]);
    }

    /**
     * Permanently delete a file from registry and disk
     *
     * DELETE /api/media/{uuid}
     */
    public function deleteFile(string $uuid): JsonResponse
    {
        // Find file regardless of status (handles partial deletes)
        $file = DB::selectOne('
            SELECT id, current_path, filename, asset_uuid
            FROM file_registry
            WHERE asset_uuid = ?
        ', [$uuid]);

        if (! $file) {
            return response()->json(['success' => false, 'error' => 'File not found in registry'], 404);
        }

        $ncPath = $file->current_path;
        $localPath = $this->resolveLocalPath($ncPath);

        // Delete physical file if it exists
        $physicalDeleted = false;
        if ($localPath && file_exists($localPath)) {
            $physicalDeleted = @unlink($localPath);
            if (! $physicalDeleted) {
                Log::warning('Failed to delete physical file, proceeding with DB cleanup', ['path' => $localPath, 'uuid' => $uuid]);
            }
        }

        $this->fileLifecycle->deleteFileFromRegistry($uuid, $ncPath, 'Deleted by user');

        Log::info('File deleted', ['uuid' => $uuid, 'filename' => $file->filename, 'path' => $ncPath, 'physical' => $physicalDeleted]);

        return response()->json([
            'success' => true,
            'data' => [
                'filename' => $file->filename,
                'physical_deleted' => $physicalDeleted,
            ],
        ]);
    }

    /**
     * Hard purge a file tombstone and preserved references.
     *
     * DELETE /api/media/{uuid}/purge
     */
    public function hardPurgeFile(Request $request, string $uuid): JsonResponse
    {
        $file = DB::selectOne('
            SELECT id, current_path, filename, asset_uuid, status
            FROM file_registry
            WHERE asset_uuid = ?
        ', [$uuid]);

        if (! $file) {
            return response()->json(['success' => false, 'error' => 'File not found in registry'], 404);
        }

        $ncPath = $file->current_path;
        $force = $request->boolean('force');
        $eligibility = $this->fileLifecycle->canHardPurgeFile($uuid, $force);
        if (! $eligibility['allowed']) {
            return response()->json([
                'success' => false,
                'error' => 'Hard purge requires an expired deleted tombstone or force=true',
                'reason' => $eligibility['reason'],
                'retention_days' => $eligibility['retention_days'] ?? null,
                'purge_after' => $eligibility['purge_after'] ?? null,
            ], 409);
        }

        $localPath = $this->resolveLocalPath($ncPath);

        $physicalDeleted = false;
        if ($localPath && file_exists($localPath)) {
            $physicalDeleted = @unlink($localPath);
            if (! $physicalDeleted) {
                Log::warning('Failed to delete physical file during hard purge, proceeding with registry purge', [
                    'path' => $localPath,
                    'uuid' => $uuid,
                ]);
            }
        }

        $purged = $this->fileLifecycle->hardPurgeFileFromRegistry($uuid, $ncPath, $force);
        if (! $purged) {
            return response()->json(['success' => false, 'error' => 'Failed to hard purge file'], 500);
        }

        Log::info('File hard purged', ['uuid' => $uuid, 'filename' => $file->filename, 'path' => $ncPath, 'physical' => $physicalDeleted]);

        return response()->json([
            'success' => true,
            'data' => [
                'filename' => $file->filename,
                'physical_deleted' => $physicalDeleted,
                'hard_purged' => true,
            ],
        ]);
    }

    /**
     * Batch writeback metadata to multiple files
     *
     * POST /api/media/writeback/batch
     */
    public function writebackBatch(Request $request): JsonResponse
    {
        $type = $request->input('type', 'all'); // dates, faces, tags, all
        $limit = min((int) $request->input('limit', 50), 100);
        $imageExts = "'jpg', 'jpeg', 'png', 'tiff', 'webp', 'heic'";

        $results = [
            'processed' => 0,
            'dates_written' => 0,
            'faces_written' => 0,
            'tags_written' => 0,
            'errors' => 0,
        ];
        $permissionHalted = false;

        // Process dates
        if ($type === 'dates' || $type === 'all') {
            $files = DB::select("
                SELECT id, current_path, filename, date_taken, date_taken_source, date_taken_confidence
                FROM file_registry
                WHERE date_taken IS NOT NULL
                AND date_taken_source NOT LIKE 'exif_%'
                AND (exif_written IS NULL OR exif_written = 0)
                AND extension IN ({$imageExts})
                AND date_taken_confidence >= 0.5
                AND status = 'active'
                LIMIT ?
            ", [$limit]);

            foreach ($files as $file) {
                $ncPath = $file->current_path;
                $localPath = $this->resolveLocalPath($ncPath);

                $results['processed']++;

                if (! $localPath || ! file_exists($localPath)) {
                    DB::update('UPDATE file_registry SET exif_written = -1 WHERE id = ?', [$file->id]);
                    $results['errors']++;

                    continue;
                }

                $result = $this->exifWriteback->writeDate(
                    $localPath,
                    $file->date_taken,
                    $file->date_taken_source,
                    $file->date_taken_confidence
                );

                if ($result['success']) {
                    DB::update('UPDATE file_registry SET exif_written = 1, exif_date_written_at = NOW() WHERE id = ?', [$file->id]);
                    $results['dates_written']++;
                } elseif (($result['code'] ?? null) === -2) {
                    Log::warning('MediaBrowser writeback batch halted - host lacks EXIF writeback permissions', [
                        'file_id' => $file->id,
                        'path' => $localPath,
                    ]);
                    $results['errors']++;
                    $permissionHalted = true;
                    break;
                } else {
                    DB::update('UPDATE file_registry SET exif_written = -1 WHERE id = ?', [$file->id]);
                    $results['errors']++;
                }
            }
        }

        // Process faces
        if (! $permissionHalted && ($type === 'faces' || $type === 'all')) {
            $files = DB::select("
                SELECT DISTINCT fr.id, fr.current_path, fr.filename
                FROM file_registry fr
                INNER JOIN file_registry_faces ff ON ff.file_registry_id = fr.id
                WHERE (fr.exif_faces_written IS NULL OR fr.exif_faces_written = 0)
                AND fr.status = 'active'
                AND fr.extension IN ({$imageExts})
                LIMIT ?
            ", [$limit]);

            foreach ($files as $file) {
                $ncPath = $file->current_path;
                $localPath = $this->resolveLocalPath($ncPath);

                $results['processed']++;

                if (! $localPath || ! file_exists($localPath)) {
                    DB::update('UPDATE file_registry SET exif_faces_written = -1 WHERE id = ?', [$file->id]);
                    $results['errors']++;

                    continue;
                }

                $faces = DB::select('
                    SELECT person_name, genealogy_person_id, region_x, region_y, region_w, region_h, confidence
                    FROM file_registry_faces
                    WHERE file_registry_id = ?
                ', [$file->id]);

                // Enrich with genealogy names
                foreach ($faces as &$face) {
                    if ($face->genealogy_person_id && ! $face->person_name) {
                        $person = DB::selectOne("
                            SELECT CONCAT(given_name, ' ', surname) as name
                            FROM genealogy_persons
                            WHERE id = ?
                        ", [$face->genealogy_person_id]);
                        if ($person) {
                            $face->person_name = $person->name;
                        }
                    }
                }

                $result = $this->exifWriteback->writeFaces($localPath, $faces);

                if ($result['success']) {
                    DB::update('UPDATE file_registry SET exif_faces_written = 1, exif_faces_written_at = NOW() WHERE id = ?', [$file->id]);
                    $results['faces_written']++;
                } elseif (($result['code'] ?? null) === -2) {
                    Log::warning('MediaBrowser writeback batch halted - host lacks EXIF writeback permissions', [
                        'file_id' => $file->id,
                        'path' => $localPath,
                    ]);
                    $results['errors']++;
                    $permissionHalted = true;
                    break;
                } else {
                    DB::update('UPDATE file_registry SET exif_faces_written = -1 WHERE id = ?', [$file->id]);
                    $results['errors']++;
                }
            }
        }

        // Process tags
        if (! $permissionHalted && ($type === 'tags' || $type === 'all')) {
            try {
                $files = DB::select("
                    SELECT DISTINCT fr.id, fr.current_path, fr.filename, fr.ai_description
                    FROM file_registry fr
                    INNER JOIN file_registry_tags ft ON ft.file_registry_id = fr.id
                    WHERE ft.source = 'ai'
                    AND (fr.exif_tags_written IS NULL OR fr.exif_tags_written = 0)
                    AND fr.status = 'active'
                    AND fr.extension IN ({$imageExts})
                    LIMIT ?
                ", [$limit]);

                foreach ($files as $file) {
                    $ncPath = $file->current_path;
                    $localPath = $this->resolveLocalPath($ncPath);

                    $results['processed']++;

                    if (! $localPath || ! file_exists($localPath)) {
                        DB::update('UPDATE file_registry SET exif_tags_written = -1 WHERE id = ?', [$file->id]);
                        $results['errors']++;

                        continue;
                    }

                    $tagRows = DB::select("
                        SELECT tag FROM file_registry_tags
                        WHERE file_registry_id = ? AND source = 'ai'
                    ", [$file->id]);

                    $tags = array_column($tagRows, 'tag');
                    $result = $this->exifWriteback->writeTags($localPath, $tags, $file->ai_description);

                    if ($result['success']) {
                        DB::update('UPDATE file_registry SET exif_tags_written = 1, exif_tags_written_at = NOW() WHERE id = ?', [$file->id]);
                        $results['tags_written']++;
                    } elseif (($result['code'] ?? null) === -2) {
                        Log::warning('MediaBrowser writeback batch halted - host lacks EXIF writeback permissions', [
                            'file_id' => $file->id,
                            'path' => $localPath,
                        ]);
                        $results['errors']++;
                        $permissionHalted = true;
                        break;
                    } else {
                        DB::update('UPDATE file_registry SET exif_tags_written = -1 WHERE id = ?', [$file->id]);
                        $results['errors']++;
                    }
                }
            } catch (\Exception $e) {
                // file_registry_tags table may not exist
                Log::debug('Tags writeback skipped', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }

    // ─── Faces Page ────────────────────────────────────────────────────

    /**
     * Named people grid — grouped by person_name with counts
     *
     * GET /api/media/faces/recognized
     */
    public function facesRecognized(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 60), 200);
        $offset = (int) $request->get('offset', 0);
        $search = $request->get('search', '');
        $showHidden = (bool) $request->get('hidden', false);

        $where = $showHidden ? 'WHERE hidden = 1' : 'WHERE hidden = 0';
        $where .= " AND person_name != '' AND LOWER(person_name) != 'unknown'";
        $params = [];

        if ($search) {
            $where .= ' AND person_name LIKE ?';
            $params[] = '%'.$search.'%';
        }

        $params[] = $limit;
        $params[] = $offset;

        $people = DB::select("
            SELECT person_name,
                   COUNT(*) as face_count,
                   MIN(id) as representative_face_id,
                   MAX(genealogy_person_id) as genealogy_person_id,
                   MAX(favorite) as favorite
            FROM file_registry_faces
            {$where}
            GROUP BY person_name
            ORDER BY face_count DESC
            LIMIT ? OFFSET ?
        ", $params);

        // Total unique people count for pagination
        $searchWhere = $showHidden ? 'WHERE hidden = 1' : 'WHERE hidden = 0';
        $searchWhere .= " AND person_name != '' AND LOWER(person_name) != 'unknown'";
        $countParams = [];
        if ($search) {
            $searchWhere .= ' AND person_name LIKE ?';
            $countParams[] = '%'.$search.'%';
        }

        $total = DB::selectOne("
            SELECT COUNT(DISTINCT person_name) as cnt
            FROM file_registry_faces
            {$searchWhere}
        ", $countParams);

        return response()->json([
            'success' => true,
            'data' => $people,
            'total' => $total->cnt ?? 0,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Unnamed faces grid — individual face records without person_name
     *
     * GET /api/media/faces/new
     */
    public function facesNew(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 50), 200);
        $offset = (int) $request->get('offset', 0);

        $faces = DB::select("
            SELECT frf.id as face_id, frf.file_registry_id, frf.person_name,
                   frf.region_x, frf.region_y, frf.region_w, frf.region_h,
                   frf.confidence, frf.source, fr.asset_uuid, fr.filename, fr.current_path
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            WHERE frf.person_name = '' AND frf.hidden = 0
            ORDER BY frf.confidence DESC, frf.id DESC
            LIMIT ? OFFSET ?
        ", [$limit, $offset]);

        // Stats
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN person_name != '' AND hidden = 0 THEN 1 ELSE 0 END) as named_count,
                SUM(CASE WHEN person_name = '' AND hidden = 0 THEN 1 ELSE 0 END) as unnamed_count,
                SUM(CASE WHEN hidden = 1 THEN 1 ELSE 0 END) as hidden_count,
                COUNT(DISTINCT CASE WHEN person_name != '' AND LOWER(person_name) != 'unknown' THEN person_name END) as unique_people
            FROM file_registry_faces
        ");

        return response()->json([
            'success' => true,
            'data' => $faces,
            'stats' => [
                'total' => (int) ($stats->total ?? 0),
                'named_count' => (int) ($stats->named_count ?? 0),
                'unnamed_count' => (int) ($stats->unnamed_count ?? 0),
                'hidden_count' => (int) ($stats->hidden_count ?? 0),
                'unique_people' => (int) ($stats->unique_people ?? 0),
                'unidentified_count' => (int) (DB::selectOne(
                    "SELECT COUNT(*) as cnt FROM genealogy_face_match_queue WHERE status = 'pending' AND match_type = 'no_match'"
                )->cnt ?? 0),
            ],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Named-only faces grid — named face records that are not linked to genealogy people.
     *
     * GET /api/media/faces/named-only
     */
    public function facesNamedOnly(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 50), 200);
        $offset = (int) $request->get('offset', 0);
        $staleNamedOnlyHours = 24;
        $decisionState = (string) $request->get('decision_state', 'open');
        $sort = (string) $request->get('sort', 'recent');
        $staleOnly = $request->boolean('stale');
        if (! in_array($decisionState, ['open', 'decided', 'all'], true)) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_decision_state',
            ], 422);
        }
        if (! in_array($sort, ['recent', 'oldest'], true)) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_sort',
            ], 422);
        }

        $decisionJoin = "
            LEFT JOIN genealogy_face_match_queue q ON q.file_registry_face_id = frf.id
              AND JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.action')) IS NOT NULL
              AND NOT EXISTS (
                SELECT 1
                FROM genealogy_face_match_queue q2
                WHERE q2.file_registry_face_id = frf.id
                  AND JSON_UNQUOTE(JSON_EXTRACT(q2.match_details, '$.latest_candidate_decision.action')) IS NOT NULL
                  AND (
                    q2.updated_at > q.updated_at
                    OR (q2.updated_at = q.updated_at AND q2.id > q.id)
                  )
              )
        ";
        $namedOnlyFilter = "frf.hidden = 0
              AND frf.genealogy_person_id IS NULL
              AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL
              AND LOWER(TRIM(frf.person_name)) != 'unknown'";

        $decisionFilter = '';
        if ($decisionState === 'open') {
            $decisionFilter = "AND (
                q.id IS NULL
                OR JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.terminal')) IS NULL
                OR JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.terminal')) != 'true'
            )";
        } elseif ($decisionState === 'decided') {
            $decisionFilter = "AND JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.terminal')) = 'true'";
        }

        $staleFilter = $staleOnly
            ? 'AND frf.updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR)'
            : '';
        $whereParams = $staleOnly ? [$staleNamedOnlyHours] : [];
        $sortSql = match ($sort) {
            'oldest' => 'frf.updated_at ASC, frf.id ASC',
            default => 'frf.updated_at DESC, frf.id DESC',
        };

        $faces = DB::select("
            SELECT frf.id as face_id, frf.file_registry_id, frf.person_name,
                   frf.genealogy_person_id, frf.region_x, frf.region_y,
                   frf.region_w, frf.region_h, frf.confidence, frf.source,
                   frf.verified, frf.favorite, fr.asset_uuid, fr.filename,
                   fr.current_path,
                   IFNULL(GREATEST(TIMESTAMPDIFF(HOUR, frf.updated_at, NOW()), 0), 0) AS backlog_age_hours,
                   CASE WHEN frf.updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR) THEN 1 ELSE 0 END AS is_stale_named_only,
                   q.status AS candidate_decision_status,
                   JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.action')) AS candidate_decision_action,
                   JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.terminal')) AS candidate_decision_terminal,
                   JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.decided_at')) AS candidate_decision_at
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            {$decisionJoin}
            WHERE {$namedOnlyFilter}
              {$decisionFilter}
              {$staleFilter}
            ORDER BY {$sortSql}
            LIMIT ? OFFSET ?
        ", array_merge([$staleNamedOnlyHours], $whereParams, [$limit, $offset]));

        $faces = array_map(function (object $face): object {
            $face->backlog_age_hours = (int) ($face->backlog_age_hours ?? 0);
            $face->is_stale_named_only = (bool) ($face->is_stale_named_only ?? false);

            return $face;
        }, $faces);

        $total = DB::selectOne("
            SELECT COUNT(*) as cnt
            FROM file_registry_faces frf
            {$decisionJoin}
            WHERE {$namedOnlyFilter}
              {$decisionFilter}
              {$staleFilter}
        ", $whereParams);

        return response()->json([
            'success' => true,
            'data' => $faces,
            'total' => (int) ($total->cnt ?? 0),
            'decision_state' => $decisionState,
            'stale_only' => $staleOnly,
            'sort' => $sort,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Ranked genealogy-person candidates for one named, unlinked face.
     *
     * GET /api/media/faces/{faceId}/candidates
     */
    public function faceCandidates(Request $request, int $faceId): JsonResponse
    {
        $treeId = (int) $request->get('tree_id', 0);
        $limit = min(max((int) $request->get('limit', 10), 1), 50);
        $includeRejected = $request->boolean('include_rejected') || $request->boolean('search_again');

        $payload = app(FaceCandidateService::class)->candidatesForFace($faceId, $treeId, $limit, $includeRejected);
        $status = (int) ($payload['status'] ?? (($payload['success'] ?? false) ? 200 : 400));

        return response()->json($payload, $status);
    }

    /**
     * Record an operator candidate/no-candidate decision for a named, unlinked face.
     *
     * POST /api/media/faces/{faceId}/candidate-decision
     */
    public function decideFaceCandidate(Request $request, int $faceId): JsonResponse
    {
        $payload = app(FaceCandidateDecisionService::class)->decide(
            $faceId,
            $request->only(['action', 'tree_id', 'genealogy_person_id', 'reason']),
            $request->user()?->id
        );
        $status = (int) ($payload['status'] ?? (($payload['success'] ?? false) ? 200 : 400));

        return response()->json($payload, $status);
    }

    /**
     * Unidentified faces — no_match records from genealogy_face_match_queue (N63)
     *
     * GET /api/media/faces/unidentified
     */
    public function unidentifiedFaces(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 50), 200);
        $page = max((int) $request->get('page', 1), 1);
        $offset = ($page - 1) * $perPage;

        $total = (int) (DB::selectOne(
            "SELECT COUNT(*) as total FROM genealogy_face_match_queue WHERE status = 'pending' AND match_type = 'no_match'"
        )->total ?? 0);

        $faces = DB::select(
            "SELECT f.id, f.media_id, f.face_region, f.created_at,
                    COALESCE(m.nextcloud_path, m.original_path) as media_path,
                    m.local_filename as filename
             FROM genealogy_face_match_queue f
             LEFT JOIN genealogy_media m ON m.id = f.media_id
             WHERE f.status = 'pending' AND f.match_type = 'no_match'
             ORDER BY f.created_at DESC
             LIMIT ? OFFSET ?",
            [$perPage, $offset]
        );

        return response()->json([
            'faces' => $faces,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ]);
    }

    /**
     * Update genealogy_face_match_queue status (dismiss/reopen for N63 unidentified tab)
     *
     * PATCH /api/media/face-match/{id}/status
     */
    public function updateFaceMatchStatus(Request $request, int $id): JsonResponse
    {
        $status = $request->input('status');
        $allowed = ['pending', 'ignored', 'dismissed', 'approved', 'rejected'];
        if (! in_array($status, $allowed)) {
            return response()->json(['success' => false, 'error' => 'Invalid status'], 422);
        }

        if ($status === 'dismissed') {
            $status = 'ignored';
        }

        $affected = DB::update(
            'UPDATE genealogy_face_match_queue SET status = ?, reviewed_at = NOW() WHERE id = ?',
            [$status, $id]
        );

        if (! $affected) {
            return response()->json(['success' => false, 'error' => 'Face match record not found'], 404);
        }

        return response()->json(['success' => true, 'status' => $status]);
    }

    /**
     * Face crop by file_registry_faces ID — cached thumbnail
     *
     * GET /api/media/faces/registry-crop/{faceId}
     */
    public function serveFaceRegistryCrop(int $faceId): Response
    {
        $face = DB::selectOne('
            SELECT frf.region_x, frf.region_y, frf.region_w, frf.region_h,
                   fr.current_path
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            WHERE frf.id = ?
        ', [$faceId]);

        if (! $face || ! $face->current_path) {
            return response('Face not found', 404);
        }

        $cachePath = storage_path("app/thumbnails/faces/frf_{$faceId}.jpg");

        if (file_exists($cachePath)) {
            return response(file_get_contents($cachePath), 200, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=604800',
            ]);
        }

        try {
            $filePath = $face->current_path;

            // Try local file first, then Nextcloud
            if (file_exists($filePath)) {
                $imageData = file_get_contents($filePath);
            } else {
                $nextcloud = app(\App\Services\NextcloudService::class);
                // Convert absolute path to Nextcloud relative path
                $dataPath = trim((string) config('services.nextcloud.data_path', ''));
                $localRoot = $dataPath === '' ? '' : rtrim($dataPath, '/').'/';
                $ncPath = $localRoot !== '' && str_starts_with($filePath, $localRoot)
                    ? substr($filePath, strlen(rtrim($localRoot, '/')))
                    : ltrim($filePath, '/');
                $result = $nextcloud->downloadFile($ncPath);
                if (! $result['success']) {
                    return response('File not accessible', 404);
                }
                $imageData = $result['content'];
            }

            $sourceImage = @imagecreatefromstring($imageData);
            if (! $sourceImage) {
                return response('Cannot process image', 500);
            }

            // Auto-orient from EXIF
            if (function_exists('exif_read_data') && file_exists($filePath)) {
                $exif = @exif_read_data($filePath);
                if ($exif && isset($exif['Orientation'])) {
                    $sourceImage = match ((int) $exif['Orientation']) {
                        3 => imagerotate($sourceImage, 180, 0),
                        6 => imagerotate($sourceImage, -90, 0),
                        8 => imagerotate($sourceImage, 90, 0),
                        default => $sourceImage,
                    };
                }
            }

            $imgWidth = imagesx($sourceImage);
            $imgHeight = imagesy($sourceImage);
            $size = 200;

            // Calculate crop from normalized coordinates with padding
            $padding = 0.3;
            $x = max(0, ($face->region_x - $face->region_w * $padding) * $imgWidth);
            $y = max(0, ($face->region_y - $face->region_h * $padding) * $imgHeight);
            $w = min($imgWidth - $x, $face->region_w * (1 + $padding * 2) * $imgWidth);
            $h = min($imgHeight - $y, $face->region_h * (1 + $padding * 2) * $imgHeight);

            // Make square
            $cropSize = max($w, $h);
            $x = max(0, $x - ($cropSize - $w) / 2);
            $y = max(0, $y - ($cropSize - $h) / 2);
            if ($x + $cropSize > $imgWidth) {
                $x = $imgWidth - $cropSize;
            }
            if ($y + $cropSize > $imgHeight) {
                $y = $imgHeight - $cropSize;
            }
            if ($x < 0) {
                $x = 0;
                $cropSize = min($cropSize, $imgWidth);
            }
            if ($y < 0) {
                $y = 0;
                $cropSize = min($cropSize, $imgHeight);
            }

            $cropped = imagecreatetruecolor($size, $size);
            imagecopyresampled(
                $cropped, $sourceImage,
                0, 0, (int) $x, (int) $y,
                $size, $size, (int) $cropSize, (int) $cropSize
            );

            $dir = dirname($cachePath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            imagejpeg($cropped, $cachePath, 90);
            imagedestroy($sourceImage);
            $content = file_get_contents($cachePath);
            imagedestroy($cropped);

            return response($content, 200, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=604800',
            ]);
        } catch (\Exception $e) {
            Log::error('Face registry crop error', ['face_id' => $faceId, 'error' => $e->getMessage()]);

            return response('Face crop failed', 500);
        }
    }

    /**
     * Bulk name multiple faces at once
     *
     * POST /api/media/faces/bulk-name
     */
    public function bulkNameFaces(Request $request): JsonResponse
    {
        $faceIds = $request->input('face_ids', []);
        $personName = trim($request->input('person_name', ''));
        $genealogyPersonId = (int) $request->input('genealogy_person_id', 0);

        if (empty($faceIds)) {
            return response()->json(['error' => 'face_ids required'], 400);
        }

        if ($genealogyPersonId > 0 && ! $this->findGenealogyPerson($genealogyPersonId, (int) $request->input('tree_id', 0))) {
            return $this->invalidGenealogyPersonResponse($genealogyPersonId);
        }

        $faceIds = array_map('intval', $faceIds);
        $placeholders = implode(',', array_fill(0, count($faceIds), '?'));

        // Update face names
        $updated = DB::update("
            UPDATE file_registry_faces SET person_name = ? WHERE id IN ({$placeholders})
        ", array_merge([$personName], $faceIds));

        // Reset writeback flags on affected files
        DB::update("
            UPDATE file_registry SET exif_faces_written = 0
            WHERE id IN (
                SELECT DISTINCT file_registry_id FROM file_registry_faces WHERE id IN ({$placeholders})
            )
        ", $faceIds);

        $bridgeResults = [];
        if ($genealogyPersonId > 0) {
            foreach ($faceIds as $faceId) {
                $bridgeResults[$faceId] = app(FaceLinkBridgeService::class)->syncFaceLink($faceId, $genealogyPersonId);
            }
        }

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'genealogy_person_id' => $genealogyPersonId ?: null,
            'genealogy_bridge' => $bridgeResults,
        ]);
    }

    /**
     * Bulk hide/unhide faces
     *
     * POST /api/media/faces/bulk-hide
     */
    public function bulkHideFaces(Request $request): JsonResponse
    {
        $faceIds = $request->input('face_ids', []);
        $hidden = (bool) $request->input('hidden', true);

        if (empty($faceIds)) {
            return response()->json(['error' => 'face_ids required'], 400);
        }

        $faceIds = array_map('intval', $faceIds);
        $placeholders = implode(',', array_fill(0, count($faceIds), '?'));

        $updated = DB::update("
            UPDATE file_registry_faces SET hidden = ? WHERE id IN ({$placeholders})
        ", array_merge([$hidden ? 1 : 0], $faceIds));

        return response()->json([
            'success' => true,
            'updated' => $updated,
        ]);
    }

    /**
     * Rename all faces of a person (or merge if target already exists)
     *
     * POST /api/media/faces/rename-person
     */
    public function renamePerson(Request $request): JsonResponse
    {
        $oldName = trim($request->input('old_name', ''));
        $newName = trim($request->input('new_name', ''));

        if (! $oldName || ! $newName) {
            return response()->json(['error' => 'old_name and new_name required'], 400);
        }

        if ($oldName === $newName) {
            return response()->json(['error' => 'Names are identical'], 400);
        }

        // Check if target name already exists (merge case)
        $targetExists = DB::selectOne('
            SELECT COUNT(*) as cnt FROM file_registry_faces WHERE person_name = ? AND hidden = 0
        ', [$newName]);
        $merged = ($targetExists->cnt ?? 0) > 0;

        // Rename all faces
        $renamed = DB::update('
            UPDATE file_registry_faces SET person_name = ? WHERE person_name = ?
        ', [$newName, $oldName]);

        // Reset writeback flags
        DB::update('
            UPDATE file_registry SET exif_faces_written = 0
            WHERE id IN (
                SELECT DISTINCT file_registry_id FROM file_registry_faces WHERE person_name = ?
            )
        ', [$newName]);

        return response()->json([
            'success' => true,
            'renamed' => $renamed,
            'merged' => $merged,
        ]);
    }

    /**
     * Hidden faces — individual face records where hidden=1
     *
     * GET /api/media/faces/hidden
     */
    public function facesHidden(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 60), 200);
        $offset = (int) $request->get('offset', 0);

        $faces = DB::select('
            SELECT frf.id as face_id, frf.file_registry_id, frf.person_name,
                   frf.region_x, frf.region_y, frf.region_w, frf.region_h,
                   frf.confidence, frf.source,
                   fr.asset_uuid, fr.filename, fr.current_path
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            WHERE frf.hidden = 1
            ORDER BY frf.id DESC
            LIMIT ? OFFSET ?
        ', [$limit, $offset]);

        return response()->json([
            'success' => true,
            'data' => $faces,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * All individual faces for a specific person — for person detail/review page
     *
     * GET /api/media/faces/person-faces
     */
    public function personFaces(Request $request): JsonResponse
    {
        $personName = trim($request->get('name', ''));
        $limit = min((int) $request->get('limit', 60), 200);
        $offset = (int) $request->get('offset', 0);

        if (! $personName) {
            return response()->json(['error' => 'name parameter required'], 400);
        }

        $faces = DB::select('
            SELECT frf.id as face_id, frf.file_registry_id, frf.person_name,
                   frf.region_x, frf.region_y, frf.region_w, frf.region_h,
                   frf.confidence, frf.source, frf.hidden, frf.favorite,
                   fr.asset_uuid, fr.filename, fr.current_path
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            WHERE frf.person_name = ?
            ORDER BY frf.confidence DESC, frf.id DESC
            LIMIT ? OFFSET ?
        ', [$personName, $limit, $offset]);

        $total = DB::selectOne('
            SELECT COUNT(*) as cnt FROM file_registry_faces WHERE person_name = ?
        ', [$personName]);

        return response()->json([
            'success' => true,
            'data' => $faces,
            'total' => (int) ($total->cnt ?? 0),
            'person_name' => $personName,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Remove a face from a person (set person_name back to empty)
     *
     * POST /api/media/faces/{faceId}/exclude
     */
    public function excludeFace(int $faceId): JsonResponse
    {
        $face = DB::selectOne('SELECT id, person_name, file_registry_id FROM file_registry_faces WHERE id = ?', [$faceId]);
        if (! $face) {
            return response()->json(['error' => 'Face not found'], 404);
        }

        DB::update("UPDATE file_registry_faces SET person_name = '' WHERE id = ?", [$faceId]);

        // Reset writeback flag
        DB::update('UPDATE file_registry SET exif_faces_written = 0 WHERE id = ?', [$face->file_registry_id]);

        return response()->json([
            'success' => true,
            'excluded_from' => $face->person_name,
        ]);
    }

    /**
     * Identify (name) a cluster — with merge-on-rename.
     * If name matches existing confirmed cluster, auto-merges.
     *
     * POST /api/media/faces/clusters/{id}/identify
     */
    public function identifyClusterUnified(Request $request, int $id): JsonResponse
    {
        $name = $request->input('name');
        if (! $name) {
            return response()->json(['error' => 'name is required'], 400);
        }

        $genealogyPersonId = (int) $request->input('genealogy_person_id', 0);
        $writeToMedia = $request->boolean('write_to_media', true);

        if ($genealogyPersonId > 0 && ! $this->findGenealogyPerson($genealogyPersonId, (int) $request->input('tree_id', 0))) {
            return $this->invalidGenealogyPersonResponse($genealogyPersonId);
        }

        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        try {
            $result = $faceEmbedding->identifyCluster($id, $name, $genealogyPersonId, $writeToMedia);

            // Auto-propagate if identified (not merged — merge already propagates)
            if ($result['action'] === 'identified' && $request->boolean('auto_propagate', true)) {
                $propagation = $faceEmbedding->propagateClusterMatches($id);
                $result['propagation'] = $propagation;
            }

            if ($genealogyPersonId > 0) {
                $bridgeClusterId = (int) ($result['target_cluster_id'] ?? $result['cluster_id'] ?? $id);
                $result['genealogy_bridge'] = app(FaceLinkBridgeService::class)->syncClusterLinks(
                    $bridgeClusterId,
                    (int) $genealogyPersonId
                );
            }

            return response()->json(array_merge(['success' => true], $result));
        } catch (\Exception $e) {
            Log::warning('identifyClusterUnified failed', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Batch identify multiple clusters with the same name.
     *
     * POST /api/media/faces/clusters/batch-identify
     */
    public function batchIdentifyClusters(Request $request): JsonResponse
    {
        $clusterIds = $request->input('cluster_ids', []);
        $name = $request->input('name');

        if (empty($clusterIds) || ! $name) {
            return response()->json(['error' => 'cluster_ids and name are required'], 400);
        }

        $genealogyPersonId = (int) $request->input('genealogy_person_id', 0);
        $writeToMedia = $request->boolean('write_to_media', true);

        if ($genealogyPersonId > 0 && ! $this->findGenealogyPerson($genealogyPersonId, (int) $request->input('tree_id', 0))) {
            return $this->invalidGenealogyPersonResponse($genealogyPersonId);
        }

        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        $results = [];
        $succeeded = 0;

        foreach ($clusterIds as $clusterId) {
            try {
                $result = $faceEmbedding->identifyCluster((int) $clusterId, $name, $genealogyPersonId, $writeToMedia);
                if ($genealogyPersonId > 0) {
                    $bridgeClusterId = (int) ($result['target_cluster_id'] ?? $result['cluster_id'] ?? $clusterId);
                    $result['genealogy_bridge'] = app(FaceLinkBridgeService::class)->syncClusterLinks(
                        $bridgeClusterId,
                        (int) $genealogyPersonId
                    );
                }
                $results[] = array_merge(['cluster_id' => $clusterId, 'success' => true], $result);
                $succeeded++;
            } catch (\Exception $e) {
                $results[] = ['cluster_id' => $clusterId, 'success' => false, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'success' => true,
            'confirmed' => $succeeded,
            'failed' => count($clusterIds) - $succeeded,
            'results' => $results,
        ]);
    }

    /**
     * Hide a cluster (ignore + hide MySQL faces).
     *
     * POST /api/media/faces/clusters/{id}/hide
     */
    public function hideClusterUnified(Request $request, int $id): JsonResponse
    {
        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        try {
            $result = $faceEmbedding->hideCluster($id);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Cluster hidden' : 'Failed to hide cluster',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Restore a hidden cluster.
     *
     * POST /api/media/faces/clusters/{id}/restore
     */
    public function restoreClusterUnified(Request $request, int $id): JsonResponse
    {
        $faceEmbedding = app(\App\Services\FaceEmbeddingService::class);

        try {
            $result = $faceEmbedding->restoreCluster($id);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Cluster restored' : 'Failed to restore cluster',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
