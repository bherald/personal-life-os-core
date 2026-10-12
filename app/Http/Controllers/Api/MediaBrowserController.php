<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AIService;
use App\Services\ExifWritebackService;
use App\Services\FaceEmbeddingService;
use App\Services\FaceMatcherService;
use App\Services\FileRegistryLifecycleService;
use App\Services\FileRegistryService;
use App\Services\Files\PhotoReviewEvidenceService;
use App\Services\FileVersionService;
use App\Services\Genealogy\FaceCandidateDecisionService;
use App\Services\Genealogy\FaceCandidateService;
use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\Genealogy\FaceMatchReviewDecisionService;
use App\Services\Genealogy\Support\TemporalProximityChecker;
use App\Services\ImageEditService;
use App\Services\NextcloudFileApiService;
use App\Services\NextcloudService;
use App\Services\RagEmbeddingProvenanceService;
use App\Services\Review\RegistryFaceReviewService;
use App\Services\ReviewTypeRegistryService;
use App\Services\ThumbnailService;
use App\Support\PgVector;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    public function photoEvidence(Request $request): JsonResponse
    {
        try {
            $bundle = app(PhotoReviewEvidenceService::class)->evidence($this->photoEvidenceSelector($request));

            return response()->json($bundle)->header('Cache-Control', 'private, no-store');
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'error_code' => 'invalid_photo_selector'], 422)->header('Cache-Control', 'private, no-store');
        } catch (\RuntimeException $exception) {
            return response()->json(['success' => false, 'error_code' => $exception->getMessage()], 409)->header('Cache-Control', 'private, no-store');
        }
    }

    public function photoEvidencePreview(Request $request): Response
    {
        $request->validate(['source_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D']);
        try {
            $preview = app(PhotoReviewEvidenceService::class)->preview($this->photoEvidenceSelector($request, true), $request->query('source_sha256'));

            return response($preview['bytes'], 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store',
                'X-PLOS-Source-SHA256' => $preview['source_sha256'], 'X-Content-Type-Options' => 'nosniff']);
        } catch (\InvalidArgumentException|\RuntimeException) {
            abort(409, 'Current source photo requires review.');
        }
    }

    private function photoEvidenceSelector(Request $request, bool $preview = false): array
    {
        $fields = ['asset_uuid', 'file_registry_id', 'face_match_id', 'face_id', 'media_id', 'tree_id'];
        $allowed = $preview ? [...$fields, 'source_sha256'] : $fields;
        if (array_diff(array_keys($request->query()), $allowed) !== []) {
            throw new \InvalidArgumentException('Unsupported photo selector.');
        }

        return $request->only($fields);
    }

    private const FACE_CANDIDATE_DECISION_ACTIONS = [
        'keep_name_only',
        'outside_tree',
        'too_vague',
        'not_this_person',
        'defer',
    ];

    private const FACE_CANDIDATE_DECISION_STATUSES = [
        'pending',
        'ignored',
        'dismissed',
        'approved',
        'rejected',
    ];

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
            $ncApi = app(NextcloudFileApiService::class);
            $localFsPath = $ncApi->localPath($path);
            if ($localFsPath) {
                $sourceData = @file_get_contents($localFsPath);
            } else {
                $nextcloud = app(NextcloudService::class);
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
            $nextcloud = app(NextcloudService::class);
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
    public function stream(Request $request, string $uuid): StreamedResponse|Response
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
        $normalizeName = static function (?string $name): string {
            $name = strtolower(trim((string) $name));
            $name = preg_replace('/\([^)]*\)/', ' ', $name) ?? $name;
            $name = str_replace(',', ' ', $name);
            $name = preg_replace('/\s+/', ' ', $name) ?? $name;

            return trim($name);
        };

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

        $faceIdentityRows = [];
        if (! $includeUnlinkedOnly) {
            $faceWhere = [
                'frf.hidden = 0',
                "NULLIF(TRIM(frf.person_name), '') IS NOT NULL",
                "LOWER(TRIM(frf.person_name)) <> 'unknown'",
            ];
            $faceParams = [];

            if ($treeId > 0) {
                $faceWhere[] = '(frf.genealogy_person_id IS NULL OR gp.tree_id = ?)';
                $faceParams[] = $treeId;
            }

            if ($search !== '') {
                $like = '%'.$search.'%';
                $faceWhere[] = "(frf.person_name LIKE ? OR gp.given_name LIKE ? OR gp.surname LIKE ? OR CONCAT_WS(' ', gp.given_name, gp.surname) LIKE ?)";
                array_push($faceParams, $like, $like, $like, $like);
            }

            $faceOrderSql = 'face_count DESC, frf.person_name ASC';
            if ($search !== '') {
                $normalizedSearch = $normalizeName($search);
                $faceOrderSql = '
                    CASE
                        WHEN LOWER(frf.person_name) = ? THEN 0
                        WHEN LOWER(frf.person_name) LIKE ? THEN 1
                        ELSE 2
                    END,
                    face_count DESC,
                    frf.person_name ASC
                ';
                array_push($faceParams, $normalizedSearch, $normalizedSearch.'%');
            }

            $faceParams[] = $limit;
            $faceIdentityRows = DB::select('
                SELECT
                    frf.person_name,
                    frf.genealogy_person_id,
                    gp.tree_id,
                    gp.gedcom_id,
                    gp.given_name,
                    gp.surname,
                    gp.birth_date,
                    gp.death_date,
                    COUNT(*) AS face_count,
                    COUNT(DISTINCT frf.file_registry_id) AS media_count
                FROM file_registry_faces frf
                LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
                WHERE '.implode(' AND ', $faceWhere)."
                GROUP BY
                    frf.person_name,
                    frf.genealogy_person_id,
                    gp.tree_id,
                    gp.gedcom_id,
                    gp.given_name,
                    gp.surname,
                    gp.birth_date,
                    gp.death_date
                ORDER BY {$faceOrderSql}
                LIMIT ?
            ", $faceParams);
        }

        $results = [];
        foreach ($faceIdentityRows as $row) {
            $personId = $row->genealogy_person_id !== null ? (int) $row->genealogy_person_id : null;
            $faceLabel = trim((string) $row->person_name);
            $key = $personId !== null ? 'genealogy:'.$personId : 'face_label:'.$normalizeName($faceLabel);
            if ($key === 'face_label:') {
                continue;
            }

            $genealogyName = trim((string) (($row->given_name ?? '').' '.($row->surname ?? '')));
            $results[$key] = [
                'id' => $personId,
                'identity_key' => $key,
                'identity_type' => $personId !== null ? 'genealogy_person' : 'face_label',
                'genealogy_person_id' => $personId,
                'tree_id' => $row->tree_id !== null ? (int) $row->tree_id : null,
                'gedcom_id' => $row->gedcom_id,
                'name' => $personId !== null && $genealogyName !== '' ? $genealogyName : $faceLabel,
                'person_name' => $faceLabel,
                'face_label' => $faceLabel,
                'given_name' => $row->given_name,
                'surname' => $row->surname,
                'genealogy_name' => $genealogyName !== '' ? $genealogyName : null,
                'birth_date' => $row->birth_date,
                'death_date' => $row->death_date,
                'media_count' => (int) $row->media_count,
                'face_count' => (int) $row->face_count,
                'bridge_priority' => $personId !== null ? 1 : 0,
                'sort_score' => $search !== '' && $normalizeName($faceLabel) === $normalizeName($search) ? 2 : 1,
            ];
        }

        foreach ($persons as $person) {
            $key = 'genealogy:'.(int) $person->id;
            if (isset($results[$key])) {
                $results[$key]['face_count'] = max((int) $results[$key]['face_count'], (int) $person->face_count);
                $results[$key]['media_count'] = max((int) $results[$key]['media_count'], (int) $person->face_count);

                continue;
            }

            $name = trim((string) $person->name);
            $results[$key] = [
                'id' => (int) $person->id,
                'identity_key' => $key,
                'identity_type' => 'genealogy_person',
                'genealogy_person_id' => (int) $person->id,
                'tree_id' => (int) $person->tree_id,
                'gedcom_id' => $person->gedcom_id,
                'name' => $name,
                'person_name' => $name,
                'face_label' => null,
                'given_name' => $person->given_name,
                'surname' => $person->surname,
                'genealogy_name' => $name,
                'birth_date' => $person->birth_date,
                'death_date' => $person->death_date,
                'media_count' => (int) $person->face_count,
                'face_count' => (int) $person->face_count,
                'bridge_priority' => 1,
                'sort_score' => $search !== '' && $normalizeName($name) === $normalizeName($search) ? 2 : 1,
            ];
        }

        $data = array_values($results);
        usort($data, static function (array $a, array $b): int {
            return ($b['sort_score'] <=> $a['sort_score'])
                ?: ($b['bridge_priority'] <=> $a['bridge_priority'])
                ?: ($b['face_count'] <=> $a['face_count'])
                ?: strcmp((string) $a['name'], (string) $b['name']);
        });

        return response()->json([
            'success' => true,
            'data' => array_map(static function (array $row): array {
                unset($row['sort_score'], $row['bridge_priority']);

                return $row;
            }, array_slice($data, 0, $limit)),
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
        return DB::transaction(function () use ($request, $faceId): JsonResponse {
            $personName = trim($request->input('person_name', ''));
            $genealogyPersonId = (int) $request->input('genealogy_person_id', 0);
            if (! $personName) {
                return response()->json(['error' => 'person_name required'], 400);
            }

            if ($genealogyPersonId > 0 && ! $this->findGenealogyPerson($genealogyPersonId, (int) $request->input('tree_id', 0))) {
                return $this->invalidGenealogyPersonResponse($genealogyPersonId);
            }

            $face = DB::selectOne('SELECT id, file_registry_id, global_identity_uuid, global_identity_approval_receipt_id FROM file_registry_faces WHERE id = ? FOR UPDATE', [$faceId]);
            if (! $face) {
                return response()->json(['error' => 'Face not found'], 404);
            }

            if ($this->faceHasGlobalAssignment($face)) {
                return $this->globalFaceMutationConflict();
            }

            if ($genealogyPersonId > 0) {
                DB::update(
                    'UPDATE file_registry_faces SET person_name = ?, genealogy_person_id = ? WHERE id = ? AND global_identity_uuid IS NULL AND global_identity_approval_receipt_id IS NULL',
                    [$personName, $genealogyPersonId, $faceId]
                );
            } else {
                DB::update(
                    'UPDATE file_registry_faces SET person_name = ?, genealogy_person_id = NULL, verified = 0 WHERE id = ? AND global_identity_uuid IS NULL AND global_identity_approval_receipt_id IS NULL',
                    [$personName, $faceId]
                );
            }

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
        });
    }

    /**
     * Link a face to a genealogy person
     *
     * POST /api/media/faces/link
     */
    public function linkFace(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $faceId = (int) $request->input('face_id');
            $personId = (int) ($request->input('person_id') ?: $request->input('genealogy_person_id'));

            if (! $faceId || ! $personId) {
                return response()->json(['error' => 'face_id and person_id required'], 400);
            }

            $face = DB::selectOne('SELECT id, global_identity_uuid, global_identity_approval_receipt_id FROM file_registry_faces WHERE id = ? FOR UPDATE', [$faceId]);
            if (! $face) {
                return response()->json(['error' => 'Face not found'], 404);
            }
            if ($this->faceHasGlobalAssignment($face)) {
                return $this->globalFaceMutationConflict();
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
        });
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
        $limit = max(1, min((int) $request->get('limit', 50), 200));
        $offset = max(0, (int) $request->get('offset', 0));

        $statusFilter = $status === 'all' ? '' : 'AND q.status = ?';
        $params = $status === 'all' ? [$treeId, $limit, $offset] : [$treeId, $status, $limit, $offset];

        $queue = DB::select("
            SELECT
                q.*,
                gp.given_name,
                gp.surname,
                COALESCE(fr1.filename, fr2.filename, gm.local_filename) as filename,
                COALESCE(fr1.asset_uuid, fr2.asset_uuid) as asset_uuid,
                COALESCE(gm.nextcloud_path, gm.original_path, fr1.current_path, fr1.original_path, fr2.current_path, fr2.original_path) as media_path,
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
            LIMIT ? OFFSET ?
        ", $params);

        $countParams = $status === 'all' ? [$treeId] : [$treeId, $status];
        $total = DB::selectOne("
            SELECT COUNT(*) AS total
            FROM genealogy_face_match_queue q
            WHERE q.tree_id = ? {$statusFilter}
        ", $countParams);

        $stats = $this->faceMatcher->getQueueStats($treeId);
        $totalCount = (int) ($total->total ?? 0);

        return response()->json([
            'success' => true,
            'data' => $queue,
            'stats' => $stats,
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $totalCount,
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
        $validated = $request->validate([
            'action' => 'required|string|in:approve,reject',
            'decision_scope' => 'required|string|in:confirm_face_identity_link,reject_face_identity_suggestion',
            'notes' => 'nullable|string|max:2000',
            'face_region_convention' => 'nullable|string|in:normalized_top_left',
        ]);

        $expectedScope = $validated['action'] === 'approve'
            ? 'confirm_face_identity_link'
            : 'reject_face_identity_suggestion';
        if ($validated['decision_scope'] !== $expectedScope) {
            return response()->json(['error' => 'decision_scope does not match action'], 422);
        }

        return DB::transaction(function () use ($validated, $id): JsonResponse {
            $action = $validated['action'];
            $match = DB::selectOne('SELECT * FROM genealogy_face_match_queue WHERE id = ? FOR UPDATE', [$id]);
            if (! $match) {
                return response()->json(['error' => 'Face match not found'], 404);
            }

            if (! empty($match->file_registry_face_id)) {
                $face = DB::selectOne('SELECT id, global_identity_uuid, global_identity_approval_receipt_id FROM file_registry_faces WHERE id = ? FOR UPDATE', [(int) $match->file_registry_face_id]);
                if (! $face) {
                    return response()->json(['error' => 'Face not found'], 404);
                }
                if ($this->faceHasGlobalAssignment($face)) {
                    return $this->globalFaceMutationConflict();
                }
            }

            $decisionMeta = [
                'decision_scope' => $validated['decision_scope'],
                'face_region_convention' => $validated['face_region_convention'] ?? null,
            ];
            $decision = $action === 'approve'
                ? app(FaceMatchReviewDecisionService::class)->approve($id, $validated['notes'] ?? null, $decisionMeta)
                : app(FaceMatchReviewDecisionService::class)->reject($id, $validated['notes'] ?? null, $decisionMeta);
            if (! ($decision['success'] ?? false)) {
                $status = ($decision['error_code'] ?? null) === 'not_found' ? 404 : 409;

                return response()->json([
                    'success' => false,
                    'error' => [
                        'message' => $decision['error'] ?? 'Face match review failed.',
                        'code' => $decision['error_code'] ?? 'face_match_review_failed',
                    ],
                ], $status);
            }

            return response()->json([
                'success' => true,
                'status' => $action === 'approve' ? 'approved' : 'rejected',
                'genealogy_bridge' => $decision['genealogy_bridge'] ?? null,
            ]);
        });
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
        return DB::transaction(function () use ($request, $faceId): JsonResponse {
            $personId = (int) ($request->input('person_id') ?: $request->input('genealogy_person_id'));
            if (! $personId) {
                return response()->json(['error' => 'person_id required'], 400);
            }

            $face = DB::selectOne('SELECT id, file_registry_id, global_identity_uuid, global_identity_approval_receipt_id FROM file_registry_faces WHERE id = ? FOR UPDATE', [$faceId]);
            if (! $face) {
                return response()->json(['error' => 'Face not found'], 404);
            }
            if ($this->faceHasGlobalAssignment($face)) {
                return $this->globalFaceMutationConflict();
            }

            $person = $this->findGenealogyPerson($personId, (int) $request->input('tree_id', 0));
            if (! $person) {
                return $this->invalidGenealogyPersonResponse($personId);
            }

            // Update face assignment
            DB::update('UPDATE file_registry_faces SET genealogy_person_id = ?, person_name = ? WHERE id = ? AND global_identity_uuid IS NULL AND global_identity_approval_receipt_id IS NULL', [
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
        });
    }

    /**
     * Unlink a face from its genealogy person
     *
     * POST /api/media/faces/{faceId}/unlink
     */
    public function unlinkFace(int $faceId): JsonResponse
    {
        $result = $this->clearFacePersonAssignment($faceId, false);
        if (($result['error'] ?? null) === 'face_not_found') {
            return response()->json(['error' => 'Face not found'], 404);
        }

        return response()->json(['success' => true, 'face_id' => $faceId, 'assignment' => $result]);
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
        if ($request->exists('photo_field_decision')) {
            if (array_keys($request->all()) !== ['photo_field_decision'] || ! is_array($request->input('photo_field_decision'))) {
                return response()->json(['success' => false, 'error' => 'Review one photo field separately from other metadata edits',
                    'review_required' => true, 'decision_applied' => false], 422)
                    ->header('Cache-Control', 'private, no-store');
            }
            $actorId = $request->user()?->getAuthIdentifier();
            if ((! is_int($actorId) && ! is_string($actorId)) || ! ctype_digit((string) $actorId) || (int) $actorId <= 0) {
                return response()->json(['success' => false, 'error' => 'An authenticated reviewer is required'], 401)
                    ->header('Cache-Control', 'private, no-store');
            }
            try {
                $result = app(PhotoReviewEvidenceService::class)->decideField($uuid, $request->input('photo_field_decision'), (int) $actorId);

                return response()->json($result + ['file_written' => null, 'source_replaced' => false, 'repair_required' => false])
                    ->header('Cache-Control', 'private, no-store');
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['success' => false, 'error' => $exception->getMessage(), 'review_required' => true, 'decision_applied' => false], 422)
                    ->header('Cache-Control', 'private, no-store');
            } catch (\RuntimeException $exception) {
                if ($exception instanceof QueryException
                    || (! str_starts_with($exception->getMessage(), 'photo_field_review_') && $exception->getMessage() !== 'photo_asset_unavailable')) {
                    throw $exception;
                }

                return response()->json(['success' => false, 'error' => $exception->getMessage(), 'review_required' => true, 'decision_applied' => false], 409)
                    ->header('Cache-Control', 'private, no-store');
            }
        }
        $file = DB::selectOne("SELECT * FROM file_registry WHERE asset_uuid = ? AND status = 'active'", [$uuid]);

        if (! $file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $updates = [];
        $params = [];
        $exifWriteArgs = [];
        $dateChanged = false;
        $tagsChanged = false;
        $fileOnlyChanges = false;
        $captionSourceHash = null;
        $writeToFile = $request->boolean('write_to_file', false);
        $localPath = $this->resolveLocalPath($file->current_path);

        // Only an explicit original-date decision may promote an estimate or clear EXIF.
        if ($request->exists('date_taken')) {
            $dateTaken = $request->input('date_taken');
            $dateTaken = $dateTaken === '' ? null : $dateTaken;
            if ($dateTaken !== null) {
                if (! is_string($dateTaken)) {
                    return response()->json(['error' => 'Invalid original date'], 422);
                }
                $dateTaken = str_replace('T', ' ', $dateTaken);
                if (strlen($dateTaken) === 16) {
                    $dateTaken .= ':00';
                }
                $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateTaken);
                if (! $parsedDate || $parsedDate->format('Y-m-d H:i:s') !== $dateTaken) {
                    return response()->json(['error' => 'Original date must be a valid YYYY-MM-DD HH:MM:SS value'], 422);
                }
            }
            $intent = $request->input('date_taken_intent');
            $currentDate = $file->date_taken ? str_replace('T', ' ', $file->date_taken) : null;
            // Older clients truncate displayed dates to minutes. An unchanged save is not a date decision.
            $unchanged = $dateTaken === $currentDate || ($dateTaken && $currentDate
                && substr($dateTaken, 0, 16) === substr($currentDate, 0, 16)
                && substr($dateTaken, -3) === ':00');
            if (! $unchanged || $intent !== null) {
                $validIntent = $dateTaken === null
                    ? $intent === 'clear_original_date'
                    : in_array($intent, ['set_original_date', 'confirm_original_date'], true);
                if (! $validIntent) {
                    return response()->json(['error' => 'An explicit original-date decision is required', 'review_required' => true], 422);
                }
                $dateChanged = true;
                $updates[] = 'date_taken = ?';
                $params[] = $dateTaken;
                $updates[] = 'date_taken_precision = ?';
                $params[] = $dateTaken === null ? null : 'exact';
                $updates[] = "date_taken_source = 'user_manual'";
                $updates[] = 'date_taken_confidence = ?';
                $params[] = $dateTaken === null ? null : 1.0;
                $exifDate = $dateTaken === null ? '' : str_replace('-', ':', substr($dateTaken, 0, 10)).substr($dateTaken, 10);
                foreach (['DateTimeOriginal', 'CreateDate', 'ModifyDate'] as $tag) {
                    $exifWriteArgs[] = "-{$tag}={$exifDate}";
                }
            }
        }

        // Manual captions belong to description; generated AI evidence remains unchanged.
        if ($request->exists('description')) {
            $desc = (string) $request->input('description', '');
            $currentDescription = $file->description ?? $file->ai_description ?? '';
            if ($desc !== $currentDescription) {
                $captionSourceHash = $request->input('source_sha256');
                if (! is_string($captionSourceHash) || ! preg_match('/^[a-f0-9]{64}$/D', $captionSourceHash)) {
                    return response()->json(['success' => false, 'error' => 'A reviewed source SHA-256 is required for caption edits', 'review_required' => true], 422);
                }
                if (! $this->metadataSourceMatches($file, $captionSourceHash)) {
                    return response()->json(['success' => false, 'error' => 'Caption source changed or is unavailable; reload the photo', 'review_required' => true], 409);
                }
                $updates[] = 'description = ?';
                $params[] = $desc;
                $tagsChanged = true;
                $exifWriteArgs[] = '-ImageDescription='.$desc;
                $exifWriteArgs[] = '-XMP:Description='.$desc;
            }
        }

        if ($request->exists('tags')) {
            $tags = $request->input('tags');
            $tagArray = is_array($tags) ? $tags : explode(',', (string) $tags);
            $tagArray = array_values(array_filter(array_map('trim', $tagArray), fn ($tag) => $tag !== ''));
            $currentTags = is_array($file->tags) ? $file->tags : (json_decode($file->tags ?? '[]', true) ?? []);
            if ($tagArray !== $currentTags) {
                $updates[] = 'tags = ?';
                $params[] = json_encode($tagArray);
                $tagsChanged = true;
                // Replace only the explicitly edited lists, preserving other metadata.
                $exifWriteArgs[] = '-IPTC:Keywords=';
                $exifWriteArgs[] = '-XMP:Subject=';
                foreach ($tagArray as $tag) {
                    $exifWriteArgs[] = '-IPTC:Keywords+='.$tag;
                    $exifWriteArgs[] = '-XMP:Subject+='.$tag;
                }
            }
        }

        if ($request->exists('copyright')) {
            $fileOnlyChanges = true;
            $copyright = (string) $request->input('copyright', '');
            $exifWriteArgs[] = '-IPTC:CopyrightNotice='.$copyright;
            $exifWriteArgs[] = '-XMP:Rights='.$copyright;
            $exifWriteArgs[] = '-IFD0:Copyright='.$copyright;
        }

        if ($request->exists('title') && (string) $request->input('title', '') !== ($file->title ?? '')) {
            $title = (string) $request->input('title', '');
            $updates[] = 'title = ?';
            $params[] = $title;
            $tagsChanged = true;
            $exifWriteArgs[] = '-XMP:Title='.$title;
            $exifWriteArgs[] = '-IPTC:ObjectName='.$title;
        }

        if ($request->has('exif_fields') && is_array($request->input('exif_fields'))) {
            foreach ($request->input('exif_fields') as $field) {
                if (! empty($field['tag']) && isset($field['value'])) {
                    $tag = preg_replace('/[^a-zA-Z0-9:\-_]/', '', $field['tag']);
                    $baseTag = substr($tag, (int) strrpos(':'.$tag, ':'));
                    if (preg_match('/date|time|^all$/i', $baseTag)) {
                        return response()->json(['error' => 'Use the original-date field and explicit date intent to edit date metadata'], 422);
                    }
                    $exifWriteArgs[] = "-{$tag}=".$field['value'];
                    $fileOnlyChanges = true;
                }
            }
        }

        if ($fileOnlyChanges && ! $writeToFile) {
            return response()->json([
                'success' => false,
                'error' => 'Copyright and custom EXIF edits require saving to the physical file',
                'source_replaced' => false,
                'repair_required' => false,
            ], 422);
        }

        // Verified file publication precedes semantic DB edits. A held write cannot claim a saved date.
        $writeResult = null;
        if ($writeToFile && $exifWriteArgs !== []) {
            if (! config('metadata_writeback.enabled', false) || ! config('metadata_writeback.in_place_enabled', false)) {
                $writeResult = ['success' => false, 'error' => 'Metadata writeback disabled', 'code' => -3];
            } elseif (! $localPath || ! is_file($localPath)) {
                $writeResult = ['success' => false, 'error' => 'Metadata source is unavailable'];
            } else {
                try {
                    $args = array_merge(['-preserve'], $exifWriteArgs);
                    $writeResult = $captionSourceHash === null
                        ? $this->exifWriteback->writeVerifiedMetadata($localPath, $args, true)
                        : $this->exifWriteback->writeVerifiedMetadata($localPath, $args, true, $captionSourceHash);
                } catch (\Throwable $e) {
                    $writeResult = ['success' => false, 'error' => $e->getMessage()];
                }
            }
            if (! ($writeResult['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => $writeResult['error'] ?? 'Metadata write held',
                    'data' => (array) $file,
                    'file_written' => $writeResult,
                    'source_replaced' => $writeResult['source_replaced'] ?? false,
                    'repair_required' => $writeResult['repair_required'] ?? false,
                ]);
            }
        }
        if ($dateChanged) {
            $updates[] = 'exif_written = '.(int) ($writeResult['success'] ?? false);
        }
        if ($tagsChanged) {
            $updates[] = 'exif_tags_written = '.(int) ($writeResult['success'] ?? false);
        }

        if ($updates !== []) {
            $params[] = $file->id;
            try {
                DB::transaction(function () use ($file, $captionSourceHash, $writeResult, $updates, $params): void {
                    $currentHash = ($writeResult['success'] ?? false) ? ($writeResult['written_sha256'] ?? null) : $captionSourceHash;
                    if ($captionSourceHash !== null) {
                        $locked = DB::table('file_registry')->where('id', $file->id)->lockForUpdate()->first();
                        if (! $locked || ! is_string($currentHash) || ! $this->metadataSourceMatches($file, $currentHash)
                            || ($locked->description ?? null) !== ($file->description ?? null)) {
                            throw new \RuntimeException('caption_source_changed');
                        }
                    }
                    DB::update('UPDATE file_registry SET '.implode(', ', $updates).' WHERE id = ?', $params);
                    if ($captionSourceHash !== null && ! $this->metadataSourceMatches($file, $currentHash)) {
                        throw new \RuntimeException('caption_source_changed');
                    }
                });
            } catch (\Throwable $e) {
                // The staged writer has already published bytes. Do not disguise a DB failure as a held file write.
                $sourceReplaced = ($writeResult['success'] ?? false) && ! ($writeResult['unchanged'] ?? false);
                if ($e instanceof \RuntimeException && $e->getMessage() === 'caption_source_changed') {
                    return response()->json(['success' => false, 'error' => 'Caption source or catalog changed before saving; reload the photo',
                        'review_required' => true, 'file_written' => $writeResult, 'source_replaced' => $sourceReplaced, 'repair_required' => $sourceReplaced], 409);
                }
                if (! $sourceReplaced) {
                    throw $e;
                }

                Log::error('Metadata file published but semantic DB update failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

                return response()->json([
                    'success' => false,
                    'error' => 'File metadata changed but database metadata could not be saved; repair is required',
                    'data' => (array) $file,
                    'file_written' => $writeResult,
                    'source_replaced' => true,
                    'repair_required' => true,
                ]);
            }
        }
        // Keep scan evidence and date provenance; generic EXIF rereads must not promote scan/estimate dates.

        // Update RAG content if provided
        if ($request->has('rag_content')) {
            try {
                $ragContent = trim((string) $request->input('rag_content'));
                if ($ragContent === '') {
                    throw new \RuntimeException('RAG content cannot be empty.');
                }

                $embeddingResult = app(AIService::class)->generateEmbedding($ragContent);
                if (! ($embeddingResult['success'] ?? false) || empty($embeddingResult['embedding'])) {
                    throw new \RuntimeException('RAG content embedding failed; searchable content was not changed.');
                }

                $contentHash = hash('sha256', $ragContent);
                $provenance = app(RagEmbeddingProvenanceService::class)->attach(
                    null,
                    $embeddingResult,
                    $contentHash
                );
                $updated = DB::connection('pgsql_rag')->update("
                    UPDATE rag_documents
                    SET content = ?,
                        embedding = ?::vector,
                        content_hash = ?,
                        metadata = COALESCE(metadata, '{}'::jsonb) || ?::jsonb,
                        updated_at = NOW()
                    WHERE metadata->>'asset_uuid' = ? OR metadata->>'file_path' = ?
                ", [
                    $ragContent,
                    PgVector::literal($embeddingResult['embedding']),
                    $contentHash,
                    json_encode($provenance, JSON_THROW_ON_ERROR),
                    $uuid,
                    $file->current_path,
                ]);
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
            'source_replaced' => ($writeResult['success'] ?? false) && ! ($writeResult['unchanged'] ?? false),
            'repair_required' => false,
        ]);
    }

    private function metadataSourceMatches(object $file, string $expectedHash): bool
    {
        $current = DB::table('file_registry')->where('id', $file->id)->where('asset_uuid', $file->asset_uuid)->where('status', 'active')->first(['current_path']);
        if (! $current || $current->current_path !== $file->current_path) {
            return false;
        }
        $deadline = hrtime(true) / 1e9 + max(.001, min(30, (float) config('file_registry.photo_review.hash_timeout_seconds', 10)));
        $proof = app(NextcloudFileApiService::class)->computeFileHash($file->current_path, $deadline, true);

        return ($proof['success'] ?? false) && hash_equals($expectedHash, (string) ($proof['hash'] ?? ''));
    }

    /**
     * Re-read EXIF from physical file and sync key fields to DB
     */
    private function syncExifToDb(int $fileId, string $localPath): void
    {
        try {
            $result = Process::timeout(10)
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
            Log::debug('syncExifToDb failed', ['exception' => $e]);
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
            if (! empty($face->person_name) || $this->faceHasGlobalAssignment($face)) {
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
                $updated = DB::update("UPDATE file_registry_faces SET person_name = ? WHERE id = ? AND (person_name IS NULL OR person_name = '') AND global_identity_uuid IS NULL AND global_identity_approval_receipt_id IS NULL", [
                    $name, $face->id,
                ]);
                if ($updated > 0) {
                    $face->person_name = $name;
                }
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
            SELECT frf.id, frf.person_name, frf.genealogy_person_id, frf.global_identity_uuid, frf.global_identity_approval_receipt_id,
                   frf.region_x, frf.region_y, frf.region_w, frf.region_h,
                   frf.confidence, frf.verified,
                   gp.given_name, gp.surname
            FROM file_registry_faces frf
            LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
            WHERE frf.file_registry_id = ?
              AND frf.source_current = 1 AND frf.superseded_at IS NULL
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
                $result = Process::timeout(10)
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
                Log::debug('EXIF read failed', ['uuid' => $uuid, 'exception' => $e]);
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
        $matchedLibraryRoot = str_starts_with(strtolower($ncPath), strtolower($libraryRoot));
        $diskLibraryRoot = $matchedLibraryRoot ? substr($ncPath, 0, strlen($libraryRoot)) : $libraryRoot;
        $basePath = rtrim($dataPath, '/').$diskLibraryRoot;
        $relativePath = $matchedLibraryRoot
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
            'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif', 'image/tiff', 'image/bmp', 'image/jp2', 'image/jpx', 'image/j2k', 'image/x-jp2'],
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
        $faceEmbedding = app(FaceEmbeddingService::class);

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
        $faceEmbedding = app(FaceEmbeddingService::class);
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
        $faceEmbedding = app(FaceEmbeddingService::class);

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

        $faceEmbedding = app(FaceEmbeddingService::class);
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
        $faceEmbedding = app(FaceEmbeddingService::class);

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
        $faceEmbedding = app(FaceEmbeddingService::class);

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
        $faceEmbedding = app(FaceEmbeddingService::class);

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
        $faceEmbedding = app(FaceEmbeddingService::class);

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
        $faceEmbedding = app(FaceEmbeddingService::class);

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
        $faceEmbedding = app(FaceEmbeddingService::class);

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
        $faceEmbedding = app(FaceEmbeddingService::class);

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
        $faceEmbedding = app(FaceEmbeddingService::class);

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
    public function serveFaceCrop(int $id): Response|BinaryFileResponse
    {
        try {
            $face = DB::connection('pgsql_rag')->selectOne('SELECT * FROM face_embeddings WHERE id = ?', [$id]);
            if (! $face) {
                return response('Face not found', 404, ['Cache-Control' => 'private, no-store']);
            }
            if (! app(FaceEmbeddingService::class)->embeddingHasCurrentSourceEvidence($face)) {
                return response('Face source provenance requires review; source photo remains available', 409, ['Cache-Control' => 'private, no-store']);
            }
            if (! empty($face->file_registry_face_id)) {
                return $this->serveFaceRegistryCrop((int) $face->file_registry_face_id);
            }

            return response('Face source provenance requires review; source photo remains available', 409, ['Cache-Control' => 'private, no-store']);
        } catch (\Throwable $e) {
            return response('Face source unavailable', 500, ['Cache-Control' => 'private, no-store']);
        }
    }

    /**
     * Serve face crop from genealogy_face_match_queue (for Research Hub)
     *
     * GET /api/media/face-match-crop/{id}
     */
    public function serveFaceMatchCrop(int $id): Response|BinaryFileResponse
    {
        $headers = ['Cache-Control' => 'private, no-cache, must-revalidate'];
        try {
            $match = DB::selectOne('SELECT q.media_id, q.file_registry_face_id, q.face_region, q.match_details,
                COALESCE(m.nextcloud_path, m.original_path, fr.current_path) AS media_path
                FROM genealogy_face_match_queue q LEFT JOIN genealogy_media m ON m.id = q.media_id
                LEFT JOIN file_registry_faces frf ON frf.id = q.file_registry_face_id
                LEFT JOIN file_registry fr ON fr.id = frf.file_registry_id WHERE q.id = ?', [$id]);
            if (! $match) {
                return response('Face not found', 404, $headers);
            }
            if (! empty($match->file_registry_face_id)) {
                $face = DB::selectOne('SELECT frf.*, fr.current_path, fr.status AS file_status FROM file_registry_faces frf
                    JOIN file_registry fr ON fr.id = frf.file_registry_id WHERE frf.id = ?', [(int) $match->file_registry_face_id]);
                $details = json_decode((string) ($match->match_details ?? ''), true);
                $region = json_decode((string) ($match->face_region ?? ''), true);
                $proof = $face ? app(FaceLinkBridgeService::class)->validateFaceSourceEvidence($face) : ['success' => false];
                if (! ($proof['success'] ?? false) || ($details['source_sha256'] ?? null) !== $proof['source_sha256']
                    || ($details['face_region_convention'] ?? null) !== 'normalized_top_left'
                    || ($details['source_path'] ?? null) !== $proof['source_path']) {
                    return response('Face review source requires review; source photo remains available', 409, $headers);
                }
                foreach (['x', 'y', 'w', 'h'] as $axis) {
                    if (! is_numeric($region[$axis] ?? null) || abs((float) $region[$axis] - $proof['region'][$axis]) > (float) config('genealogy.face_region_match_tolerance')) {
                        return response('Face review region changed; source photo remains available', 409, $headers);
                    }
                }

                return $this->serveFaceRegistryCrop((int) $match->file_registry_face_id);
            }
            $details = json_decode((string) ($match->match_details ?? ''), true);
            $region = json_decode((string) ($match->face_region ?? ''), true);
            $hash = $details['source_sha256'] ?? null;
            if (($details['face_region_convention'] ?? null) !== 'normalized_top_left' || ! is_string($hash)
                || ! preg_match('/^[a-f0-9]{64}$/D', $hash) || ! is_array($region)) {
                return response('Face source provenance requires review; source photo remains available', 409, $headers);
            }
            foreach (['x', 'y', 'w', 'h'] as $axis) {
                if (! is_numeric($region[$axis] ?? null) || ! is_finite((float) $region[$axis])) {
                    return response('Face region requires review', 409, $headers);
                }
            }
            if ($region['x'] < 0 || $region['y'] < 0 || $region['w'] <= 0 || $region['h'] <= 0
                || $region['x'] + $region['w'] > 1 || $region['y'] + $region['h'] > 1) {
                return response('Face region requires review', 409, $headers);
            }
            $path = $this->resolveFaceMatchSourcePath($match->media_path ?? null);
            $current = $path ? @hash_file('sha256', $path) : null;
            if (! is_string($current) || ! hash_equals($hash, $current)) {
                return response('Face source changed or unavailable', 409, $headers);
            }
            $response = $this->cropAndServeFace($path, $region);
            $current = @hash_file('sha256', $path);
            if (! is_string($current) || ! hash_equals($hash, $current)) {
                return response('Face source changed during rendering', 409, $headers);
            }
            $response->headers->set('Cache-Control', $headers['Cache-Control']);

            return $response;
        } catch (\Throwable $e) {
            return response('Face source unavailable', 500, $headers);
        }
    }

    /**
     * Serve a read-only source-photo preview for a face-match review row.
     *
     * GET /api/media/face-match-source/{id}
     */
    public function serveFaceMatchSource(int $id): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $hash = request()->query('source_sha256');
            if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                return response('Load current source proof before opening this photo', 409, $headers);
            }
            $match = DB::table('genealogy_face_match_queue')->where('id', $id)->first();
            $proof = $match ? app(FaceLinkBridgeService::class)->reviewSourcePhoto($match, $hash) : ['success' => false];
            if (! ($proof['success'] ?? false)) {
                return response('Source photo changed or unavailable', 409, $headers);
            }
            $image = imagecreatefromstring($proof['bytes']);
            // Encoded pixel geometry matches normalized_top_left. No orientation metadata survives this preview.
            $maxSize = max(1, (int) config('metadata_writeback.preview_max_dimension'));
            $scale = min(1, $maxSize / imagesx($image), $maxSize / imagesy($image));
            $preview = imagescale($image, max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
            if (! $preview) {
                imagedestroy($image);

                return response('Source preview could not be rendered', 409, $headers);
            }
            ob_start();
            imagejpeg($preview, null, 90);
            $bytes = ob_get_clean();
            imagedestroy($preview);
            imagedestroy($image);
            $current = app(NextcloudFileApiService::class)->computeFileHash($proof['source_path']);
            if (! ($current['success'] ?? false) || ! hash_equals($hash, (string) ($current['hash'] ?? ''))) {
                return response('Source photo changed during rendering', 409, $headers);
            }

            return response($bytes, 200, $headers + ['Content-Type' => 'image/jpeg', 'X-PLOS-Source-SHA256' => $hash]);
        } catch (\Throwable $e) {
            return response('Source photo unavailable', 409, $headers);
        }
    }

    public function faceMatchSourceProof(int $id): JsonResponse
    {
        $match = DB::table('genealogy_face_match_queue')->where('id', $id)->first();
        $proof = $match ? app(FaceLinkBridgeService::class)->reviewSourcePhoto($match) : ['success' => false];
        if (! ($proof['success'] ?? false)) {
            return response()->json(['success' => false, 'error' => ($proof['error_code'] ?? '') === 'source_resource_budget'
                ? 'The source photo exceeds the current decoding budget; no corruption is implied.' : 'Current source photo is unavailable',
                'error_code' => $proof['error_code'] ?? 'source_photo_unavailable'], 409, ['Cache-Control' => 'private, no-store']);
        }
        $suggestionProof = array_diff_key($proof, ['bytes' => true]);
        unset($proof['bytes'], $proof['source_path']);
        $proof['preview_url'] = '/api/media/face-match-source/'.$id.'?source_sha256='.$proof['source_sha256'];
        if (($match->status ?? null) === 'pending' && auth('web')->check()) {
            // Detector regions are suggestions only. An unavailable private packet
            // must not prevent the operator from drawing a current region manually.
            try {
                $proof['anonymous_suggestions'] = app(FaceEmbeddingService::class)->anonymousPreviewSuggestions(
                    ['face_review_id' => $id, 'tree_id' => (int) $match->tree_id], $suggestionProof
                );
            } catch (\Throwable) {
                $proof['anonymous_suggestions'] = ['state' => 'unavailable'];
            }
        }

        return response()->json($proof, 200, ['Cache-Control' => 'private, no-store']);
    }

    private function resolveFaceMatchSourcePath(mixed $mediaPath): ?string
    {
        $path = is_scalar($mediaPath) ? trim((string) $mediaPath) : '';
        $configuredRoot = realpath(trim((string) config('services.nextcloud.data_path', '')));
        if ($path === '' || $configuredRoot === false || ! is_dir($configuredRoot)) {
            return null;
        }

        $candidates = [$this->resolveLocalPath($path)];
        if (str_starts_with($path, '/')) {
            $candidates[] = $path;
        }
        $candidates[] = rtrim($configuredRoot, '/').'/'.ltrim($path, '/');

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $resolved = realpath($candidate);
            if ($resolved === false || ! is_file($resolved)) {
                continue;
            }

            if ($resolved === $configuredRoot || str_starts_with($resolved, rtrim($configuredRoot, '/').'/')) {
                return $resolved;
            }
        }

        return null;
    }

    private function faceRegistryCropOrPlaceholder(mixed $fileRegistryFaceId): Response|BinaryFileResponse
    {
        $registryCrop = is_numeric($fileRegistryFaceId)
            ? $this->faceRegistryCropOrNull((int) $fileRegistryFaceId)
            : null;

        return $registryCrop ?? $this->returnFacePlaceholder();
    }

    private function faceRegistryCropOrNull(int $fileRegistryFaceId): ?Response
    {
        $response = $this->serveFaceRegistryCrop($fileRegistryFaceId);

        return $response->getStatusCode() < 400 ? $response : null;
    }

    /**
     * Crop face from image and return as response
     */
    private function cropAndServeFace(string $imagePath, array $region): Response
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
    private function serveImageThumbnail(
        string $imagePath,
        int $maxSize,
        string $cacheControl = 'public, max-age=86400'
    ): Response {
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
                'Cache-Control' => $cacheControl,
            ]);
        } catch (\Exception $e) {
            return $this->returnFacePlaceholder();
        }
    }

    /**
     * Return placeholder image for faces
     */
    private function returnFacePlaceholder(): Response|BinaryFileResponse
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
     * Permanently delete a file from disk and retain its registry tombstone.
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
        $physical = $this->deletePhysicalFile($localPath);
        if (! $physical['success']) {
            Log::warning('Failed to delete physical file; registry tombstone withheld', [
                'path' => $localPath,
                'uuid' => $uuid,
                'reason' => $physical['reason'],
            ]);

            return response()->json([
                'success' => false,
                'error' => $physical['error'],
            ], $physical['http_status']);
        }

        $physicalDeleted = $physical['physical_deleted'];
        $physicalMissing = $physical['physical_missing'];

        $deleted = $this->fileLifecycle->deleteFileFromRegistry($uuid, $ncPath, 'Deleted by user');
        if (! $deleted) {
            Log::error('Physical file removed but registry tombstone failed', [
                'uuid' => $uuid,
                'path' => $ncPath,
                'physical_deleted' => $physicalDeleted,
                'physical_missing' => $physicalMissing,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'File removal completed but registry cleanup failed; operator review is required',
            ], 500);
        }

        Log::info('File deleted', [
            'uuid' => $uuid,
            'filename' => $file->filename,
            'path' => $ncPath,
            'physical' => $physicalDeleted,
            'physical_missing' => $physicalMissing,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'filename' => $file->filename,
                'physical_deleted' => $physicalDeleted,
                'physical_missing' => $physicalMissing,
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
        $physical = $this->deletePhysicalFile($localPath);
        if (! $physical['success']) {
            Log::warning('Failed to delete physical file during hard purge; registry purge withheld', [
                'path' => $localPath,
                'uuid' => $uuid,
                'reason' => $physical['reason'],
            ]);

            return response()->json([
                'success' => false,
                'error' => $physical['error'],
            ], $physical['http_status']);
        }

        $physicalDeleted = $physical['physical_deleted'];
        $physicalMissing = $physical['physical_missing'];

        $purged = $this->fileLifecycle->hardPurgeFileFromRegistry($uuid, $ncPath, $force);
        if (! $purged) {
            return response()->json(['success' => false, 'error' => 'Failed to hard purge file'], 500);
        }

        Log::info('File hard purged', [
            'uuid' => $uuid,
            'filename' => $file->filename,
            'path' => $ncPath,
            'physical' => $physicalDeleted,
            'physical_missing' => $physicalMissing,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'filename' => $file->filename,
                'physical_deleted' => $physicalDeleted,
                'physical_missing' => $physicalMissing,
                'hard_purged' => true,
            ],
        ]);
    }

    /**
     * @return array{success:bool,physical_deleted:bool,physical_missing:bool,reason:?string,error:?string,http_status:int}
     */
    private function deletePhysicalFile(?string $localPath): array
    {
        if (! $localPath) {
            return [
                'success' => false,
                'physical_deleted' => false,
                'physical_missing' => false,
                'reason' => 'local_storage_unavailable',
                'error' => 'Local media storage is unavailable; no file or registry data was changed',
                'http_status' => 503,
            ];
        }

        if (! file_exists($localPath) && ! is_link($localPath)) {
            return [
                'success' => true,
                'physical_deleted' => false,
                'physical_missing' => true,
                'reason' => null,
                'error' => null,
                'http_status' => 200,
            ];
        }

        if (@unlink($localPath)) {
            return [
                'success' => true,
                'physical_deleted' => true,
                'physical_missing' => false,
                'reason' => null,
                'error' => null,
                'http_status' => 200,
            ];
        }

        return [
            'success' => false,
            'physical_deleted' => false,
            'physical_missing' => false,
            'reason' => 'unlink_failed',
            'error' => 'Physical file deletion failed; no registry data was changed',
            'http_status' => 500,
        ];
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
                AND COALESCE(date_taken_precision, 'exact') = 'exact'
                AND is_scan = 0
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
            $faceWritebackExists = $this->exifWriteback->faceWritebackExistsSql('fr', 'ff', 'fip');
            $files = DB::select("
                SELECT DISTINCT fr.id, fr.current_path, fr.filename
                FROM file_registry fr
                INNER JOIN file_registry_faces ff ON ff.file_registry_id = fr.id
                INNER JOIN face_identity_provenance fip ON fip.file_registry_face_id = ff.id
                WHERE (fr.exif_faces_written IS NULL OR fr.exif_faces_written = 0)
                AND fr.status = 'active'
                AND fr.extension IN ({$imageExts})
                AND {$faceWritebackExists}
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

                $faces = $this->exifWriteback->facesForWriteback((int) $file->id);
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
     * Named people grid — grouped by genealogy person when linked, otherwise by face label.
     *
     * GET /api/media/faces/recognized
     */
    public function facesRecognized(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 60), 200);
        $offset = (int) $request->get('offset', 0);
        $windowLimit = min($offset + $limit + 1, 1000);
        $search = $request->get('search', '');
        $showHidden = (bool) $request->get('hidden', false);

        $where = $showHidden ? 'frf.hidden = 1' : 'frf.hidden = 0';
        $where .= " AND frf.source_current = 1 AND frf.superseded_at IS NULL AND fr.status = 'active' AND frf.global_identity_uuid IS NULL";
        $where .= " AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL AND LOWER(TRIM(frf.person_name)) != 'unknown'";

        $baseSql = "
            SELECT
                CASE
                    WHEN frf.genealogy_person_id IS NOT NULL THEN CONCAT('ft:', frf.genealogy_person_id)
                    ELSE CONCAT('name:', frf.person_name)
                END as identity_key,
                CASE
                    WHEN frf.genealogy_person_id IS NOT NULL THEN 'genealogy_person'
                    ELSE 'name'
                END as scope_type,
                frf.genealogy_person_id,
                CASE WHEN frf.genealogy_person_id IS NULL THEN frf.person_name ELSE NULL END as scope_name,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ', gp.given_name, gp.surname)), ''),
                    MIN(frf.person_name)
                ) as person_name,
                NULLIF(TRIM(CONCAT_WS(' ', gp.given_name, gp.surname)), '') as genealogy_name,
                MIN(frf.person_name) as face_label_name,
                GROUP_CONCAT(DISTINCT frf.person_name ORDER BY frf.person_name SEPARATOR ', ') as face_name_variants,
                COUNT(DISTINCT frf.person_name) as face_name_variant_count,
                COUNT(*) as face_count,
                0 as linked_media_count,
                COUNT(DISTINCT frf.file_registry_id) as media_count,
                MIN(frf.id) as representative_face_id,
                NULL as representative_asset_uuid,
                NULL as representative_filename,
                MAX(frf.favorite) as favorite
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
            WHERE {$where}
            GROUP BY
                CASE
                    WHEN frf.genealogy_person_id IS NOT NULL THEN CONCAT('ft:', frf.genealogy_person_id)
                    ELSE CONCAT('name:', frf.person_name)
                END,
                CASE
                    WHEN frf.genealogy_person_id IS NOT NULL THEN 'genealogy_person'
                    ELSE 'name'
                END,
                frf.genealogy_person_id,
                CASE WHEN frf.genealogy_person_id IS NULL THEN frf.person_name ELSE NULL END,
                gp.given_name,
                gp.surname
        ";

        $having = '';
        $searchParams = [];
        if ($search) {
            $having = 'WHERE person_name LIKE ? OR genealogy_name LIKE ? OR face_name_variants LIKE ?';
            $searchParams = ['%'.$search.'%', '%'.$search.'%', '%'.$search.'%'];
        }

        $facePeople = DB::select("
            SELECT *
            FROM ({$baseSql}) grouped_people
            {$having}
            ORDER BY face_count DESC
            LIMIT ?
        ", [...$searchParams, $windowLimit]);

        $mediaPeople = [];
        if (! $showHidden) {
            $mediaWhere = [];
            $mediaParams = [];
            if ($search) {
                $mediaWhere[] = "(gp.given_name LIKE ? OR gp.surname LIKE ? OR CONCAT_WS(' ', gp.given_name, gp.surname) LIKE ?)";
                $mediaParams = ['%'.$search.'%', '%'.$search.'%', '%'.$search.'%'];
            }
            $mediaWhereSql = $mediaWhere === [] ? '' : 'WHERE '.implode(' AND ', $mediaWhere);

            $mediaPeople = DB::select("
                SELECT
                    CONCAT('ft:', gp.id) as identity_key,
                    'genealogy_person' as scope_type,
                    gp.id as genealogy_person_id,
                    NULL as scope_name,
                    NULLIF(TRIM(CONCAT_WS(' ', gp.given_name, gp.surname)), '') as person_name,
                    NULLIF(TRIM(CONCAT_WS(' ', gp.given_name, gp.surname)), '') as genealogy_name,
                    NULL as face_label_name,
                    NULL as face_name_variants,
                    0 as face_name_variant_count,
                    0 as face_count,
                    COUNT(DISTINCT gpm.media_id) as linked_media_count,
                    COUNT(DISTINCT gpm.media_id) as media_count,
                    NULL as representative_face_id,
                    NULL as representative_asset_uuid,
                    MIN(COALESCE(gm.local_filename, gm.title)) as representative_filename,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            COALESCE(NULLIF(gm.nextcloud_path, ''), NULLIF(gm.original_path, ''))
                            ORDER BY gpm.is_primary DESC, gpm.id DESC
                            SEPARATOR '\n'
                        ),
                        '\n',
                        1
                    ) as representative_path,
                    0 as favorite
                FROM genealogy_person_media gpm
                JOIN genealogy_persons gp ON gp.id = gpm.person_id
                JOIN genealogy_media gm ON gm.id = gpm.media_id
                {$mediaWhereSql}
                GROUP BY gp.id, gp.given_name, gp.surname
                ORDER BY linked_media_count DESC, person_name ASC
                LIMIT ?
            ", [...$mediaParams, $windowLimit]);
        }

        $globalPeople = ! $showHidden && auth('web')->check() ? $this->globalFacePeople($search, $windowLimit) : [];
        $peopleByIdentity = [];
        foreach (array_merge($facePeople, $mediaPeople, $globalPeople) as $person) {
            $key = (string) $person->identity_key;
            if ($key === '') {
                continue;
            }

            if (! isset($peopleByIdentity[$key])) {
                $peopleByIdentity[$key] = (array) $person;

                continue;
            }

            $existing = &$peopleByIdentity[$key];
            $existing['face_count'] = max((int) ($existing['face_count'] ?? 0), (int) ($person->face_count ?? 0));
            $existing['linked_media_count'] = max((int) ($existing['linked_media_count'] ?? 0), (int) ($person->linked_media_count ?? 0));
            $existing['media_count'] = max((int) ($existing['media_count'] ?? 0), (int) ($person->media_count ?? 0));
            $existing['favorite'] = max((int) ($existing['favorite'] ?? 0), (int) ($person->favorite ?? 0));

            if (empty($existing['representative_face_id']) && ! empty($person->representative_face_id)) {
                $existing['representative_face_id'] = $person->representative_face_id;
            }
            if (empty($existing['representative_asset_uuid']) && ! empty($person->representative_asset_uuid)) {
                $existing['representative_asset_uuid'] = $person->representative_asset_uuid;
                $existing['representative_filename'] = $person->representative_filename;
            }
            if (empty($existing['representative_path']) && ! empty($person->representative_path)) {
                $existing['representative_path'] = $person->representative_path;
            }
            if (empty($existing['face_name_variants']) && ! empty($person->face_name_variants)) {
                $existing['face_name_variants'] = $person->face_name_variants;
                $existing['face_name_variant_count'] = $person->face_name_variant_count;
            }
            unset($existing);
        }

        $people = array_map(static function (array $person): array {
            $faceCount = (int) ($person['face_count'] ?? 0);
            $linkedMediaCount = (int) ($person['linked_media_count'] ?? 0);
            $person['face_count'] = $faceCount;
            $person['linked_media_count'] = $linkedMediaCount;
            $global = ($person['scope_type'] ?? null) === 'global_identity';
            $person['media_count'] = $global ? (int) ($person['media_count'] ?? 0) : max((int) ($person['media_count'] ?? 0), $faceCount, $linkedMediaCount);
            $person['has_face_rows'] = $faceCount > 0;
            $person['has_ft_media'] = $linkedMediaCount > 0;
            $person['known_source'] = $global ? 'global_identity' : ($faceCount > 0
                ? ($linkedMediaCount > 0 ? 'face_rows_and_ft_media' : 'face_rows')
                : 'ft_media');
            $person['representative_thumbnail_url'] = ! empty($person['representative_asset_uuid'])
                ? "/api/media/{$person['representative_asset_uuid']}/thumbnail/medium"
                : null;

            return $person;
        }, array_values($peopleByIdentity));

        usort($people, static function (array $a, array $b): int {
            return ((int) $b['face_count'] <=> (int) $a['face_count'])
                ?: ((int) $b['linked_media_count'] <=> (int) $a['linked_media_count'])
                ?: strcmp((string) $a['person_name'], (string) $b['person_name']);
        });

        $pagePeople = array_slice($people, $offset, $limit);
        $mediaPaths = array_values(array_unique(array_filter(array_map(
            static fn (array $person): ?string => empty($person['representative_face_id']) ? ($person['representative_path'] ?? null) : null,
            $pagePeople
        ))));
        if ($mediaPaths !== []) {
            $pathPlaceholders = implode(',', array_fill(0, count($mediaPaths), '?'));
            $registryRows = DB::select("
                SELECT asset_uuid, filename, current_path, original_path
                FROM file_registry
                WHERE status = 'active' AND (current_path IN ({$pathPlaceholders})
                   OR original_path IN ({$pathPlaceholders}))
            ", [...$mediaPaths, ...$mediaPaths]);

            $registryByPath = [];
            foreach ($registryRows as $registry) {
                foreach ([$registry->current_path ?? null, $registry->original_path ?? null] as $path) {
                    $path = trim((string) $path);
                    if ($path !== '') {
                        $registryByPath[$path] = $registry;
                    }
                }
            }

            foreach ($pagePeople as &$person) {
                $path = trim((string) ($person['representative_path'] ?? ''));
                if ($path === '' || ! isset($registryByPath[$path])) {
                    continue;
                }

                $registry = $registryByPath[$path];
                $person['representative_asset_uuid'] = $registry->asset_uuid;
                $person['representative_filename'] = $registry->filename;
                $person['representative_thumbnail_url'] = $registry->asset_uuid
                    ? "/api/media/{$registry->asset_uuid}/thumbnail/medium"
                    : null;
            }
            unset($person);
        }

        $hasMore = count($people) > ($offset + $limit);

        return response()->json([
            'success' => true,
            'data' => $pagePeople,
            'total' => $hasMore ? $offset + $limit + 1 : $offset + count($pagePeople),
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
              AND frf.source_current = 1 AND frf.superseded_at IS NULL AND fr.status = 'active'
              AND frf.global_identity_uuid IS NULL
            ORDER BY frf.confidence DESC, frf.id DESC
            LIMIT ? OFFSET ?
        ", [$limit, $offset]);

        // Legacy labels and independent global identities keep distinct count keys.
        $stats = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN frf.person_name != '' AND frf.hidden = 0 THEN 1 ELSE 0 END) as named_count,
                SUM(CASE WHEN frf.person_name = '' AND frf.hidden = 0 THEN 1 ELSE 0 END) as unnamed_count,
                SUM(CASE WHEN frf.hidden = 1 THEN 1 ELSE 0 END) as hidden_count,
                COUNT(DISTINCT CASE WHEN frf.person_name != '' AND LOWER(frf.person_name) != 'unknown'
                    THEN CASE WHEN frf.genealogy_person_id IS NOT NULL THEN CONCAT('ft:', frf.genealogy_person_id)
                    ELSE CONCAT('name:', frf.person_name) END END) as unique_people
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            WHERE frf.source_current = 1 AND frf.superseded_at IS NULL AND fr.status = 'active' AND frf.global_identity_uuid IS NULL
        ");
        if (auth('web')->check()) {
            $globalStats = $this->globalFaceRows()->selectRaw('COUNT(*) AS faces, COUNT(DISTINCT frf.global_identity_uuid) AS people')->first();
            $globalHidden = $this->globalFaceRows(true)->count();
            $stats->total = (int) ($stats->total ?? 0) + (int) $globalStats->faces + $globalHidden;
            $stats->named_count = (int) ($stats->named_count ?? 0) + (int) $globalStats->faces;
            $stats->hidden_count = (int) ($stats->hidden_count ?? 0) + $globalHidden;
            $stats->unique_people = (int) ($stats->unique_people ?? 0) + (int) $globalStats->people;
        }

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
        $clusterScope = (string) $request->get('cluster_scope', 'all');
        $search = trim((string) $request->get('search', ''));
        $activeOnly = $request->boolean('active_only', true);
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
        if (! in_array($clusterScope, ['all', 'mixed'], true)) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_cluster_scope',
            ], 422);
        }
        if (mb_strlen($search) > 100) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_search',
            ], 422);
        }

        $allowedDecisionActionsSql = implode(', ', array_map(
            fn (string $action): string => "'{$action}'",
            self::FACE_CANDIDATE_DECISION_ACTIONS
        ));
        $decisionJoin = "
            LEFT JOIN genealogy_face_match_queue q ON q.file_registry_face_id = frf.id
              AND JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.action')) IN ({$allowedDecisionActionsSql})
              AND NOT EXISTS (
                SELECT 1
                FROM genealogy_face_match_queue q2
                WHERE q2.file_registry_face_id = frf.id
                  AND JSON_UNQUOTE(JSON_EXTRACT(q2.match_details, '$.latest_candidate_decision.action')) IN ({$allowedDecisionActionsSql})
                  AND (
                    q2.updated_at > q.updated_at
                    OR (q2.updated_at = q.updated_at AND q2.id > q.id)
                  )
              )
        ";
        $namedOnlyFilter = "frf.hidden = 0
              AND frf.source_current = 1 AND frf.superseded_at IS NULL AND fr.status = 'active'
              AND frf.global_identity_uuid IS NULL
              AND frf.genealogy_person_id IS NULL
              AND NULLIF(TRIM(frf.person_name), '') IS NOT NULL
              AND LOWER(TRIM(frf.person_name)) != 'unknown'";

        $mixedClusterActiveSql = $activeOnly ? "AND fr_mix.status = 'active'" : '';
        $mixedClusterJoin = "
            LEFT JOIN (
                SELECT frf_mix.cluster_id
                FROM file_registry_faces frf_mix
                JOIN file_registry fr_mix ON fr_mix.id = frf_mix.file_registry_id
                WHERE frf_mix.hidden = 0
                  AND frf_mix.source_current = 1 AND frf_mix.superseded_at IS NULL AND fr_mix.status = 'active'
                  AND frf_mix.global_identity_uuid IS NULL
                  AND frf_mix.cluster_id IS NOT NULL
                  AND NULLIF(TRIM(frf_mix.person_name), '') IS NOT NULL
                  AND LOWER(TRIM(frf_mix.person_name)) != 'unknown'
                  {$mixedClusterActiveSql}
                GROUP BY frf_mix.cluster_id
                HAVING COUNT(DISTINCT LOWER(TRIM(frf_mix.person_name))) > 1
            ) mixed_names ON mixed_names.cluster_id = frf.cluster_id
        ";

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
        $activeFilter = $activeOnly ? "AND fr.status = 'active'" : '';
        $clusterScopeFilter = $clusterScope === 'mixed'
            ? 'AND mixed_names.cluster_id IS NOT NULL'
            : '';
        $searchFilter = $search !== ''
            ? 'AND (frf.person_name LIKE ? OR fr.filename LIKE ? OR fr.current_path LIKE ?)'
            : '';
        $searchParams = $search !== '' ? ["%{$search}%", "%{$search}%", "%{$search}%"] : [];
        $whereParams = array_merge($searchParams, $staleOnly ? [$staleNamedOnlyHours] : []);
        $sortSql = match ($sort) {
            'oldest' => 'frf.updated_at ASC, frf.id ASC',
            default => 'frf.updated_at DESC, frf.id DESC',
        };

        $faces = DB::select("
            SELECT frf.id as face_id, frf.file_registry_id, frf.person_name,
                   frf.genealogy_person_id, frf.region_x, frf.region_y,
                   frf.region_w, frf.region_h, frf.confidence, frf.source,
                   frf.verified, frf.favorite, frf.cluster_id,
                   frf.source_sha256, frf.source_current, frf.superseded_at, frf.hidden, frf.face_region_convention,
                   fr.asset_uuid, fr.filename, fr.current_path, fr.date_taken,
                   fr.status AS file_status,
                   IFNULL(GREATEST(TIMESTAMPDIFF(HOUR, frf.updated_at, NOW()), 0), 0) AS backlog_age_hours,
                   CASE WHEN frf.updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR) THEN 1 ELSE 0 END AS is_stale_named_only,
                   CASE WHEN mixed_names.cluster_id IS NOT NULL THEN 1 ELSE 0 END AS is_mixed_name_cluster,
                   q.status AS candidate_decision_status,
                   JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.action')) AS candidate_decision_action,
                   JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.terminal')) AS candidate_decision_terminal,
                   JSON_UNQUOTE(JSON_EXTRACT(q.match_details, '$.latest_candidate_decision.decided_at')) AS candidate_decision_at
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            {$mixedClusterJoin}
            {$decisionJoin}
            WHERE {$namedOnlyFilter}
              {$activeFilter}
              {$decisionFilter}
              {$clusterScopeFilter}
              {$searchFilter}
              {$staleFilter}
            ORDER BY {$sortSql}
            LIMIT ? OFFSET ?
        ", array_merge([$staleNamedOnlyHours], $whereParams, [$limit, $offset]));

        $faces = array_map(function (object $face): object {
            $face->backlog_age_hours = (int) ($face->backlog_age_hours ?? 0);
            $face->is_stale_named_only = (bool) ($face->is_stale_named_only ?? false);
            $face->is_mixed_name_cluster = (bool) ($face->is_mixed_name_cluster ?? false);
            $face->face_genealogy_posture = $this->namedOnlyFaceGenealogyPosture();
            $face->candidate_decision_status = $this->safeFaceCandidateDecisionStatus($face->candidate_decision_status ?? null);
            $face->candidate_decision_action = $this->safeFaceCandidateDecisionAction($face->candidate_decision_action ?? null);
            $face->candidate_decision_terminal = $this->safeFaceCandidateDecisionTerminal($face->candidate_decision_terminal ?? null);
            $face->candidate_decision_at = $this->safeFaceCandidateDecisionTimestamp($face->candidate_decision_at ?? null);
            $face->photo_date_context = $this->facePhotoDateContext($face->date_taken ?? null);
            $face->source_current = (int) ($face->source_current ?? 0) === 1;
            $face->hidden = (bool) ($face->hidden ?? true);
            $face->global_face_evidence = $this->globalFaceStoredEvidence($face);
            unset($face->date_taken);

            return $face;
        }, $faces);

        $total = DB::selectOne("
            SELECT COUNT(*) as cnt
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            {$mixedClusterJoin}
            {$decisionJoin}
            WHERE {$namedOnlyFilter}
              {$activeFilter}
              {$decisionFilter}
              {$clusterScopeFilter}
              {$searchFilter}
              {$staleFilter}
        ", $whereParams);

        return response()->json([
            'success' => true,
            'data' => $faces,
            'total' => (int) ($total->cnt ?? 0),
            'decision_state' => $decisionState,
            'active_only' => $activeOnly,
            'cluster_scope' => $clusterScope,
            'search' => $search,
            'stale_only' => $staleOnly,
            'sort' => $sort,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** Stored snapshot only: the pinned crop and proposal admission verify actual bytes. */
    private function globalFaceStoredEvidence(object $face): ?array
    {
        $hash = $face->source_sha256 ?? null;
        if (($face->file_status ?? null) !== 'active' || ! empty($face->hidden)
            || (int) ($face->source_current ?? 0) !== 1 || ! empty($face->superseded_at)
            || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)
            || ($face->face_region_convention ?? null) !== 'normalized_top_left'
            || (int) ($face->face_id ?? 0) < 1) {
            return null;
        }
        $region = [];
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            $value = $face->{'region_'.$axis} ?? null;
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                return null;
            }
            $region[$axis] = (float) $value;
        }
        if ($region['x'] < 0 || $region['y'] < 0 || $region['w'] <= 0 || $region['h'] <= 0
            || $region['x'] + $region['w'] > 1 || $region['y'] + $region['h'] > 1) {
            return null;
        }
        $fingerprint = hash('sha256', $hash.json_encode($region, JSON_THROW_ON_ERROR));

        return ['face_id' => (int) $face->face_id, 'source_sha256' => $hash, 'region' => $region,
            'face_region_convention' => 'normalized_top_left', 'coordinate_basis' => 'encoded_pixels',
            'region_fingerprint' => $fingerprint,
            'crop_url' => '/api/media/faces/registry-crop/'.$face->face_id.'?source_sha256='.$hash.'&region_fingerprint='.$fingerprint];
    }

    /**
     * @return array<string, mixed>
     */
    private function namedOnlyFaceGenealogyPosture(): array
    {
        return [
            'schema' => 'face_genealogy_named_only_posture.v1',
            'projection_only' => true,
            'operator_review_required' => true,
            'operator_link_available' => true,
            'operator_decision_available' => true,
            'automation_allowed' => false,
            'automatic_link_allowed' => false,
            'create_person_allowed' => false,
            'metadata_writeback_allowed' => false,
            'posture_reason' => 'named_only_face_review',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function facePhotoDateContext(mixed $dateTaken): array
    {
        $safeDateTaken = $this->safePhotoDateTaken($dateTaken);
        $photoYear = TemporalProximityChecker::extractYear($safeDateTaken ?? $dateTaken);

        return [
            'projection_only' => true,
            'available' => $photoYear !== null,
            'source' => $photoYear !== null ? 'file_registry.date_taken' : null,
            'photo_year' => $photoYear,
            'date_taken' => $safeDateTaken,
        ];
    }

    private function safePhotoDateTaken(mixed $dateTaken): ?string
    {
        if ($dateTaken instanceof \DateTimeInterface) {
            return $dateTaken->format('Y-m-d');
        }

        if (! is_scalar($dateTaken)) {
            return null;
        }

        $value = trim((string) $dateTaken);
        if (TemporalProximityChecker::extractYear($value) === null) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1
            ? substr($value, 0, 10)
            : null;
    }

    private function safeFaceCandidateDecisionAction(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $action = trim((string) $value);

        return in_array($action, self::FACE_CANDIDATE_DECISION_ACTIONS, true) ? $action : null;
    }

    private function safeFaceCandidateDecisionStatus(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $status = trim((string) $value);

        return in_array($status, self::FACE_CANDIDATE_DECISION_STATUSES, true) ? $status : null;
    }

    private function safeFaceCandidateDecisionTerminal(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1 ? 'true' : 'false';
        }

        if (! is_scalar($value)) {
            return null;
        }

        $terminal = strtolower(trim((string) $value));

        return in_array($terminal, ['true', 'false'], true) ? $terminal : null;
    }

    private function safeFaceCandidateDecisionTimestamp(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $timestamp = trim((string) $value);
        if ($timestamp === '' || strlen($timestamp) > 64 || str_contains($timestamp, '<') || str_contains($timestamp, '>')) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $timestamp) !== 1) {
            return null;
        }

        try {
            new \DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            return null;
        }

        return $timestamp;
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
    public function registryFaceSourceProof(int $id): JsonResponse
    {
        $registry = app(ReviewTypeRegistryService::class);
        $type = $registry->getType(RegistryFaceReviewService::TYPE);
        if (($type['source_table'] ?? null) !== 'file_registry_faces'
            || in_array(RegistryFaceReviewService::TYPE, $registry->getDisabledTypeNames(), true)) {
            return response()->json(['success' => false, 'error' => 'Photo review is unavailable.'], 503);
        }
        try {
            return response()->json(app(RegistryFaceReviewService::class)->proof($id));
        } catch (\Throwable) {
            return response()->json(['success' => false, 'error' => 'The current face and source photo need preparation before review.'], 409);
        }
    }

    public function unidentifiedFaces(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->get('per_page', 50), 200));
        $page = max((int) $request->get('page', 1), 1);
        $offset = ($page - 1) * $perPage;
        $query = DB::table('genealogy_face_match_queue as f')
            ->where('f.status', 'pending')->where('f.match_type', 'no_match');
        $pendingTotal = (clone $query)->count();
        $forReview = $request->boolean('for_review');
        if ($forReview) {
            $type = app(ReviewTypeRegistryService::class)->getType('face');
            if (($type['source_table'] ?? null) !== 'genealogy_face_match_queue'
                || ($type['source_connection'] ?? null) === 'pgsql_rag') {
                return response()->json(['success' => false, 'error' => 'Photo review is unavailable.'], 503);
            }
            $source = 'default:'.$type['source_table'];
            // Deferral belongs to the Desk; it does not change the underlying face queue.
            // Only the latest generation may hold a photo, so a reopened item returns normally.
            $query->whereNotExists(function (Builder $held) use ($source): void {
                $held->selectRaw('1')->from('review_desk_references as desk')
                    ->where('desk.source', $source)->whereRaw('desk.source_id = CAST(f.id AS CHAR)')
                    ->whereIn('desk.status', ['later', 'followup'])
                    ->whereNotExists(function (Builder $newer): void {
                        $newer->selectRaw('1')->from('review_desk_references as newer')
                            ->whereColumn('newer.source', 'desk.source')
                            ->whereColumn('newer.source_id', 'desk.source_id')
                            ->whereColumn('newer.generation', '>', 'desk.generation');
                    });
            });
        }
        $registry = app(ReviewTypeRegistryService::class);
        $registryType = $forReview ? $registry->getType(RegistryFaceReviewService::TYPE) : null;
        if (($registryType['source_table'] ?? null) === 'file_registry_faces'
            && ($registryType['source_connection'] ?? null) !== 'pgsql_rag'
            && ! in_array(RegistryFaceReviewService::TYPE, $registry->getDisabledTypeNames(), true)) {
            $adapter = app(RegistryFaceReviewService::class);
            $global = $adapter->pending();
            $pendingTotal += (clone $global)->count();
            $adapter->excludeDeferred($global, $adapter::SOURCE, 'face.id');
            $legacy = $query->leftJoin('genealogy_media as m', 'm.id', '=', 'f.media_id')
                ->select(['f.id', 'f.media_id', 'f.face_region', 'f.created_at', 'm.local_filename as filename'])
                ->selectRaw("COALESCE(m.nextcloud_path, m.original_path) as media_path, 'face' as review_type");
            // Union columns have an explicit common order; queue IDs and registry IDs may overlap.
            $global->select(['face.id'])
                ->selectRaw('NULL as media_id, NULL as face_region')->addSelect(['face.created_at', 'file.filename'])
                ->selectRaw("file.current_path as media_path, 'registry_face' as review_type");
            $merged = DB::query()->fromSub($legacy->unionAll($global), 'photos');
            $total = (clone $merged)->count();
            $faces = $merged->orderByDesc('created_at')->orderBy('review_type')->orderByDesc('id')->limit($perPage)->offset($offset)->get();
            foreach ($faces as $face) {
                $face->reference = $face->review_type.':'.$face->id;
            }

            return response()->json(['faces' => $faces, 'total' => $total, 'pending_total' => $pendingTotal,
                'page' => $page, 'per_page' => $perPage, 'pages' => $total > 0 ? (int) ceil($total / $perPage) : 0]);
        }
        $total = $forReview ? (clone $query)->count() : $pendingTotal;
        $faces = $query->leftJoin('genealogy_media as m', 'm.id', '=', 'f.media_id')
            ->select(['f.id', 'f.media_id', 'f.face_region', 'f.created_at', 'm.local_filename as filename'])
            ->selectRaw('COALESCE(m.nextcloud_path, m.original_path) as media_path')
            ->orderByDesc('f.created_at')->orderByDesc('f.id')->limit($perPage)->offset($offset)->get();

        return response()->json([
            'faces' => $faces,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            ...($forReview ? ['pending_total' => $pendingTotal] : []),
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
        if (in_array($status, ['approved', 'rejected'], true)) {
            return response()->json([
                'success' => false,
                'error' => 'Identity approvals and rejections require the picture match review.',
                'error_code' => 'face_identity_review_required',
            ], 422);
        }
        $allowed = ['pending', 'ignored', 'dismissed'];
        if (! in_array($status, $allowed, true)) {
            return response()->json(['success' => false, 'error' => 'Invalid status'], 422);
        }

        if ($status === 'dismissed') {
            $status = 'ignored';
        }

        $affected = DB::update(
            "UPDATE genealogy_face_match_queue
             SET status = ?, reviewed_at = CASE WHEN ? = 'pending' THEN NULL ELSE NOW() END, updated_at = NOW()
             WHERE id = ? AND match_type = 'no_match'
               AND status IN ('pending', 'ignored', 'dismissed')
               AND (suggested_person_id IS NULL OR suggested_person_id = 0)
               AND JSON_EXTRACT(match_details, '$.latest_candidate_decision') IS NULL
               AND JSON_EXTRACT(match_details, '$.candidate_decisions') IS NULL
               AND JSON_EXTRACT(match_details, '$.rejected_candidate_ids') IS NULL
               AND JSON_EXTRACT(match_details, '$.candidate_decision_version') IS NULL
               AND JSON_EXTRACT(match_details, '$.operator_confirmations') IS NULL
               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(match_details, '$.source')), '') <> 'operator confirmation'",
            [$status, $status, $id]
        );

        if ($affected !== 1) {
            $exists = DB::selectOne('SELECT id FROM genealogy_face_match_queue WHERE id = ?', [$id]);

            return response()->json([
                'success' => false,
                'error' => $exists ? 'Only unidentified matches without identity decision history can be dismissed or reopened; the match may have changed.' : 'Face match record not found',
                'error_code' => $exists ? 'face_match_status_conflict' : 'not_found',
            ], $exists ? 409 : 404);
        }

        return response()->json(['success' => true, 'status' => $status]);
    }

    /**
     * Face crop by file_registry_faces ID — cached thumbnail
     *
     * GET /api/media/faces/registry-crop/{faceId}
     */
    public function serveFaceRegistryCrop(int $faceId, ?Request $request = null): Response
    {
        $request ??= request();
        $pinned = $request->query->has('source_sha256') || $request->query->has('region_fingerprint');
        $headers = ['Cache-Control' => $pinned ? 'private, no-store' : 'private, no-cache, must-revalidate'];
        $expectedHash = $request->query('source_sha256');
        $expectedRegion = $request->query('region_fingerprint');
        if ($pinned && (! is_string($expectedHash) || ! preg_match('/^[a-f0-9]{64}$/D', $expectedHash)
            || ! is_string($expectedRegion) || ! preg_match('/^[a-f0-9]{64}$/D', $expectedRegion))) {
            return response('Exact source and region pins are required together', 409, $headers);
        }
        $query = "SELECT frf.source, frf.source_sha256, frf.face_region_convention, frf.source_current, frf.superseded_at, frf.region_x, frf.region_y, frf.region_w, frf.region_h, fr.current_path
                  FROM file_registry_faces frf
                  JOIN file_registry fr ON fr.id = frf.file_registry_id
                  WHERE frf.id = ? AND fr.status = 'active' AND COALESCE(frf.hidden, 0) = 0";
        $face = DB::selectOne($query, [$faceId]);
        if ($face && (empty($face->source_current) || ! empty($face->superseded_at))) {
            return response('Face source superseded; source photo remains available', 409, $headers);
        }
        if (! $face || ! is_string($face->current_path) || $face->current_path === '') {
            return response('Face not found', 404, $headers);
        }
        $region = [];
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            $value = $face->{'region_'.$axis};
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                return response('Face region unavailable', 409, $headers);
            }
            $region[$axis] = (float) $value;
        }
        if ($region['x'] < 0 || $region['y'] < 0 || $region['w'] <= 0 || $region['h'] <= 0
            || $region['x'] + $region['w'] > 1 || $region['y'] + $region['h'] > 1) {
            return response('Face region unavailable', 409, $headers);
        }
        if ($pinned && (($face->source_sha256 ?? null) !== $expectedHash
            || ($face->face_region_convention ?? null) !== 'normalized_top_left'
            || ! hash_equals($expectedRegion, hash('sha256', $expectedHash.json_encode($region, JSON_THROW_ON_ERROR))))) {
            return response('Selected face source or region changed', 409, $headers);
        }

        $sourceImage = $cropped = null;
        $temporary = null;
        try {
            $filePath = $this->resolveFaceMatchSourcePath($face->current_path);
            $dataRoot = rtrim((string) config('services.nextcloud.data_path'), '/');
            $ncPath = $dataRoot !== '' && str_starts_with($face->current_path, $dataRoot.'/')
                ? substr($face->current_path, strlen($dataRoot)) : $face->current_path;
            if (str_contains($ncPath, '\\') || str_contains($ncPath, "\0") || in_array('..', explode('/', $ncPath), true)) {
                return response('Source photo unavailable', 404, $headers);
            }
            if ($filePath) {
                $imageData = @file_get_contents($filePath);
            } else {
                $result = app(NextcloudFileApiService::class)->downloadFile($ncPath);
                $imageData = ($result['success'] ?? false) ? ($result['content'] ?? null) : null;
            }
            if (! is_string($imageData) || $imageData === '') {
                return response('Source photo unavailable', 404, $headers);
            }
            $sourceHash = hash('sha256', $imageData);
            if ($pinned && ! hash_equals($expectedHash, $sourceHash)) {
                return response('Selected face source changed', 409, $headers);
            }
            $geometryProven = function (object $row) use ($faceId, $region, $sourceHash): bool {
                if (empty($row->source_current) || ! empty($row->superseded_at)) {
                    return false;
                }
                if (($row->source_sha256 ?? null) !== null) {
                    return ($row->face_region_convention ?? null) === 'normalized_top_left'
                        && is_string($row->source_sha256) && hash_equals($sourceHash, $row->source_sha256);
                }
                // Legacy XMP boxes mix center and top-left geometry. Only an exact,
                // current normalized receipt can establish how these stored pixels map.
                $reviews = DB::select('SELECT match_details FROM genealogy_face_match_queue WHERE file_registry_face_id = ?', [$faceId]);
                foreach ($reviews as $review) {
                    $details = json_decode((string) ($review->match_details ?? ''), true);
                    foreach (is_array($details['operator_confirmations'] ?? null) ? $details['operator_confirmations'] : [] as $receipt) {
                        if (! is_array($receipt) || ($receipt['face_region_convention'] ?? null) !== 'normalized_top_left'
                            || (int) ($receipt['file_registry_face_id'] ?? 0) !== $faceId
                            || ($receipt['source_path'] ?? null) !== $row->current_path
                            || ($receipt['source_sha256'] ?? null) !== $sourceHash) {
                            continue;
                        }
                        foreach ($region as $axis => $coordinate) {
                            if (! is_numeric($receipt['region'][$axis] ?? null)
                                || abs((float) $receipt['region'][$axis] - $coordinate) > (float) config('genealogy.face_region_match_tolerance', 0.00015)) {
                                continue 2;
                            }
                        }

                        return true;
                    }
                }

                return false;
            };
            if (! $geometryProven($face)) {
                return response('Face source or geometry requires review; source photo remains available', 409, $headers);
            }
            $fingerprint = hash('sha256', $sourceHash.json_encode($region, JSON_THROW_ON_ERROR));
            if ($pinned) {
                $headers['X-PLOS-Source-SHA256'] = $sourceHash;
                $headers['X-PLOS-Region-Fingerprint'] = $fingerprint;
            }
            $cachePath = storage_path("app/thumbnails/faces/frf_{$faceId}_{$fingerprint}.jpg");
            $currentEvidenceMatches = function () use ($query, $faceId, $face, $region, $sourceHash, $filePath, $ncPath, $geometryProven): bool {
                $current = DB::selectOne($query, [$faceId]);
                if (! $current || $current->current_path !== $face->current_path
                    || ($current->source ?? null) !== ($face->source ?? null) || ! $geometryProven($current)) {
                    return false;
                }
                foreach ($region as $axis => $coordinate) {
                    if (! is_numeric($current->{'region_'.$axis}) || (float) $current->{'region_'.$axis} !== $coordinate) {
                        return false;
                    }
                }
                $hash = $filePath ? @hash_file('sha256', $filePath) : null;
                if (! $filePath) {
                    $result = app(NextcloudFileApiService::class)->computeFileHash($ncPath);
                    $hash = ($result['success'] ?? false) ? ($result['hash'] ?? null) : null;
                }

                return is_string($hash) && hash_equals($sourceHash, $hash);
            };
            if (is_file($cachePath)) {
                $content = @file_get_contents($cachePath);
                $dimensions = is_string($content) ? @getimagesizefromstring($content) : false;
                $cachedImage = $dimensions && ($dimensions['mime'] ?? null) === 'image/jpeg' ? @imagecreatefromstring($content) : false;
                $validCache = $cachedImage instanceof \GdImage;
                if ($validCache) {
                    imagedestroy($cachedImage);
                }
                if ($validCache) {
                    if (! $currentEvidenceMatches()) {
                        return response('Face source or region changed', 409, $headers);
                    }

                    return response($content, 200, ['Content-Type' => 'image/jpeg'] + $headers)->setEtag($fingerprint);
                }
            }
            $sourceImage = @imagecreatefromstring($imageData);
            if (! $sourceImage) {
                return response('Cannot process image', 500, $headers);
            }
            // Detector boxes belong to raw pixels. Crop first, then orient the selected crop.
            $imgWidth = imagesx($sourceImage);
            $imgHeight = imagesy($sourceImage);
            if (floor($region['w'] * $imgWidth) < 1 || floor($region['h'] * $imgHeight) < 1) {
                return response('Face region unavailable', 409, $headers);
            }
            $padding = 0.3;
            $x = max(0, ($region['x'] - $region['w'] * $padding) * $imgWidth);
            $y = max(0, ($region['y'] - $region['h'] * $padding) * $imgHeight);
            $w = min($imgWidth - $x, $region['w'] * (1 + $padding * 2) * $imgWidth);
            $h = min($imgHeight - $y, $region['h'] * (1 + $padding * 2) * $imgHeight);
            $cropSize = min(max($w, $h), $imgWidth, $imgHeight);
            $x = max(0, min($x - ($cropSize - $w) / 2, $imgWidth - $cropSize));
            $y = max(0, min($y - ($cropSize - $h) / 2, $imgHeight - $cropSize));
            $cropped = imagecreatetruecolor(200, 200);
            imagecopyresampled($cropped, $sourceImage, 0, 0, (int) $x, (int) $y, 200, 200, (int) $cropSize, (int) $cropSize);
            if (function_exists('exif_read_data')) {
                $stream = fopen('php://temp', 'w+b');
                try {
                    fwrite($stream, $imageData);
                    rewind($stream);
                    $exif = @exif_read_data($stream);
                } finally {
                    fclose($stream);
                }
                $orientation = (int) ($exif['Orientation'] ?? 1);
                if (in_array($orientation, [2, 5, 7], true)) {
                    imageflip($cropped, IMG_FLIP_HORIZONTAL);
                } elseif ($orientation === 4) {
                    imageflip($cropped, IMG_FLIP_VERTICAL);
                }
                $angle = match ($orientation) {
                    3 => 180, 5, 6 => -90, 7, 8 => 90, default => 0
                };
                if ($angle !== 0) {
                    $oriented = imagerotate($cropped, $angle, 0);
                    imagedestroy($cropped);
                    $cropped = $oriented;
                }
            }
            $dir = dirname($cachePath);
            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return response('Face crop cache unavailable', 500, $headers);
            }
            $temporary = tempnam($dir, '.face-');
            if ($temporary === false || ! imagejpeg($cropped, $temporary, 90)) {
                return response('Face crop cache unavailable', 500, $headers);
            }
            if (! $currentEvidenceMatches()) {
                return response('Face source or region changed', 409, $headers);
            }
            chmod($temporary, 0666 & ~umask());
            if (! rename($temporary, $cachePath)) {
                return response('Face crop cache unavailable', 500, $headers);
            }
            $temporary = null;
            $content = file_get_contents($cachePath);

            return response($content, 200, ['Content-Type' => 'image/jpeg'] + $headers)->setEtag($fingerprint);
        } catch (\Throwable $e) {
            Log::warning('Face registry crop unavailable', ['face_id' => $faceId, 'exception' => $e::class]);

            return response('Face crop failed', 500, $headers);
        } finally {
            if ($sourceImage instanceof \GdImage) {
                imagedestroy($sourceImage);
            }
            if ($cropped instanceof \GdImage) {
                imagedestroy($cropped);
            }
            if (is_string($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Bulk name multiple faces at once
     *
     * POST /api/media/faces/bulk-name
     */
    public function bulkNameFaces(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $faceIds = array_values(array_unique(array_filter(
                array_map('intval', (array) $request->input('face_ids', [])),
                fn (int $faceId): bool => $faceId > 0
            )));
            $personName = trim($request->input('person_name', ''));
            $genealogyPersonId = (int) $request->input('genealogy_person_id', 0);

            if (empty($faceIds)) {
                return response()->json(['error' => 'face_ids required'], 400);
            }

            $genealogyPerson = null;
            if ($genealogyPersonId > 0) {
                $genealogyPerson = $this->findGenealogyPerson($genealogyPersonId, (int) $request->input('tree_id', 0));
                if (! $genealogyPerson) {
                    return $this->invalidGenealogyPersonResponse($genealogyPersonId);
                }

                $canonicalName = trim(($genealogyPerson->given_name ?? '').' '.($genealogyPerson->surname ?? ''));
                if ($canonicalName !== '') {
                    $personName = $canonicalName;
                }
            }

            $placeholders = implode(',', array_fill(0, count($faceIds), '?'));

            $selected = DB::select("SELECT id, global_identity_uuid, global_identity_approval_receipt_id FROM file_registry_faces WHERE id IN ({$placeholders}) FOR UPDATE", $faceIds);
            foreach ($selected as $face) {
                if ($this->faceHasGlobalAssignment($face)) {
                    return $this->globalFaceMutationConflict();
                }
            }

            // Update face names and the explicit FT bridge field. The shared bridge
            // still handles genealogy_media alignment when a person id is present.
            if ($genealogyPersonId > 0) {
                $updated = DB::update("
                UPDATE file_registry_faces SET person_name = ?, genealogy_person_id = ?
                WHERE id IN ({$placeholders}) AND global_identity_uuid IS NULL AND global_identity_approval_receipt_id IS NULL
            ", array_merge([$personName, $genealogyPersonId], $faceIds));
            } else {
                $updated = DB::update("
                UPDATE file_registry_faces SET person_name = ?, genealogy_person_id = NULL, verified = 0
                WHERE id IN ({$placeholders}) AND global_identity_uuid IS NULL AND global_identity_approval_receipt_id IS NULL
            ", array_merge([$personName], $faceIds));
            }

            // Reset writeback flags on affected files
            DB::update("
            UPDATE file_registry SET exif_faces_written = 0
            WHERE id IN (
                SELECT DISTINCT file_registry_id FROM file_registry_faces WHERE id IN ({$placeholders})
            )
        ", $faceIds);

            $scanLogsReset = DB::delete("
            DELETE FROM genealogy_media_scan_log
            WHERE nextcloud_path IN (
                SELECT current_path FROM file_registry
                WHERE id IN (
                    SELECT DISTINCT file_registry_id FROM file_registry_faces WHERE id IN ({$placeholders})
                )
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
                'person_name' => $personName,
                'genealogy_person_id' => $genealogyPersonId ?: null,
                'genealogy_bridge' => $bridgeResults,
                'scan_logs_reset' => $scanLogsReset,
            ]);
        });
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

        $fileRows = DB::select("
            SELECT DISTINCT file_registry_id
            FROM file_registry_faces
            WHERE id IN ({$placeholders})
        ", $faceIds);
        $fileIds = array_values(array_unique(array_map(
            static fn (object $row): int => (int) $row->file_registry_id,
            $fileRows
        )));

        $updated = DB::update("
            UPDATE file_registry_faces SET hidden = ? WHERE id IN ({$placeholders})
        ", array_merge([$hidden ? 1 : 0], $faceIds));

        if ($fileIds !== []) {
            $filePlaceholders = implode(',', array_fill(0, count($fileIds), '?'));
            DB::update("
                UPDATE file_registry
                SET exif_faces_written = 0, metadata_synced_at = NULL, rag_indexed_at = NULL
                WHERE id IN ({$filePlaceholders})
            ", $fileIds);
        }

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'metadata_files_marked_pending' => count($fileIds),
        ]);
    }

    /**
     * Preview a person-card face label rename before applying the cascade.
     *
     * POST /api/media/faces/rename-person/preview
     */
    public function previewRenamePerson(Request $request): JsonResponse
    {
        $scope = $this->faceRenameScopeFromRequest($request);
        $newName = trim((string) $request->input('new_name', ''));

        if ($scope['error'] !== null) {
            return response()->json(['error' => $scope['error']], 400);
        }

        if ($newName === '') {
            return response()->json(['error' => 'new_name required'], 400);
        }

        if (
            $scope['scope_type'] === 'name'
            && count($scope['normalized_source_names']) === 1
            && $scope['normalized_source_names'][0] === $this->normalizeFaceRenameName($newName)
        ) {
            return response()->json(['error' => 'Names are identical'], 400);
        }

        return response()->json($this->buildFaceRenamePreview($scope, $newName));
    }

    /**
     * Rename all face labels in a People-card scope without changing FT person records.
     *
     * POST /api/media/faces/rename-person
     */
    public function renamePerson(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            $scope = $this->faceRenameScopeFromRequest($request);
            $newName = trim((string) $request->input('new_name', ''));

            if ($scope['error'] !== null) {
                return response()->json(['error' => $scope['error']], 400);
            }

            if ($newName === '') {
                return response()->json(['error' => 'new_name required'], 400);
            }

            if (
                $scope['scope_type'] === 'name'
                && count($scope['normalized_source_names']) === 1
                && $scope['normalized_source_names'][0] === $this->normalizeFaceRenameName($newName)
            ) {
                return response()->json(['error' => 'Names are identical'], 400);
            }

            [$lockedWhere, $lockedParams] = $this->faceRenameWhere($scope, 'frf');
            $selected = DB::select("SELECT frf.id, frf.global_identity_uuid, frf.global_identity_approval_receipt_id FROM file_registry_faces frf WHERE {$lockedWhere} FOR UPDATE", $lockedParams);
            foreach ($selected as $face) {
                if ($this->faceHasGlobalAssignment($face)) {
                    return $this->globalFaceMutationConflict();
                }
            }
            $preview = $this->buildFaceRenamePreview($scope, $newName);
            if (($preview['face_count'] ?? 0) < 1) {
                return response()->json(['error' => 'No matching face labels found', 'preview' => $preview], 404);
            }

            [$faceWhere, $faceParams] = $this->faceRenameWhere($scope, 'frf');
            $fileRows = DB::select("
            SELECT DISTINCT frf.file_registry_id
            FROM file_registry_faces frf
            WHERE {$faceWhere}
        ", $faceParams);
            $fileIds = array_values(array_filter(array_map(fn ($row) => (int) $row->file_registry_id, $fileRows)));

            [$updateWhere, $updateParams] = $this->faceRenameWhere($scope, '');
            $renamed = DB::transaction(function () use ($newName, $updateWhere, $updateParams, $fileIds): int {
                $count = DB::update("
                UPDATE file_registry_faces
                SET person_name = ?
                WHERE {$updateWhere} AND global_identity_uuid IS NULL AND global_identity_approval_receipt_id IS NULL
            ", [$newName, ...$updateParams]);

                if ($fileIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
                    DB::update("
                    UPDATE file_registry
                    SET exif_faces_written = 0, rag_indexed_at = NULL
                    WHERE id IN ({$placeholders})
                ", $fileIds);
                }

                return $count;
            });

            $clustersRenamed = 0;
            try {
                [$clusterWhere, $clusterParams] = $this->faceRenameClusterWhere($scope);
                $clustersRenamed = DB::connection('pgsql_rag')->update("
                UPDATE person_clusters
                SET name = ?
                WHERE {$clusterWhere}
            ", [$newName, ...$clusterParams]);
            } catch (\Throwable $e) {
                Log::warning('Face rename cluster cascade failed', [
                    'scope_type' => $scope['scope_type'],
                    'genealogy_person_id' => $scope['genealogy_person_id'],
                    'old_name' => $scope['old_name'],
                    'new_name' => $newName,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'renamed' => $renamed,
                'merged' => (bool) ($preview['merged'] ?? false),
                'files_queued_for_writeback' => count($fileIds),
                'files_queued_for_rag' => count($fileIds),
                'clusters_renamed' => $clustersRenamed,
                'ft_record_changes' => 0,
                'preview' => $preview,
            ]);
        });
    }

    private function faceRenameScopeFromRequest(Request $request): array
    {
        $oldName = trim((string) $request->input('old_name', ''));
        $sourceNamesInput = $request->input('source_names', []);
        $genealogyPersonId = (int) $request->input('genealogy_person_id', 0);
        $scopeType = trim((string) $request->input('scope_type', ''));

        if ($scopeType === '') {
            $scopeType = $genealogyPersonId > 0 ? 'genealogy_person' : 'name';
        }

        if (! in_array($scopeType, ['name', 'genealogy_person'], true)) {
            return ['error' => 'scope_type must be name or genealogy_person'];
        }

        if ($scopeType === 'genealogy_person' && $genealogyPersonId <= 0) {
            return ['error' => 'genealogy_person_id required for genealogy_person scope'];
        }

        $sourceNames = [];
        if (is_array($sourceNamesInput)) {
            foreach ($sourceNamesInput as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    $sourceNames[] = $name;
                }
            }
        }
        if ($oldName !== '') {
            array_unshift($sourceNames, $oldName);
        }
        $sourceNamesByNormalized = [];
        foreach ($sourceNames as $name) {
            $normalized = $this->normalizeFaceRenameName($name);
            if ($normalized !== '' && ! array_key_exists($normalized, $sourceNamesByNormalized)) {
                $sourceNamesByNormalized[$normalized] = $name;
            }
        }
        $sourceNames = array_values($sourceNamesByNormalized);
        $normalizedSourceNames = array_keys($sourceNamesByNormalized);

        if ($scopeType === 'name' && $sourceNames === []) {
            return ['error' => 'old_name required for name scope'];
        }

        return [
            'error' => null,
            'scope_type' => $scopeType,
            'old_name' => $oldName,
            'source_names' => $sourceNames,
            'normalized_source_names' => $normalizedSourceNames,
            'genealogy_person_id' => $genealogyPersonId > 0 ? $genealogyPersonId : null,
        ];
    }

    private function faceRenameWhere(array $scope, string $alias = 'frf'): array
    {
        $prefix = $alias !== '' ? $alias.'.' : '';

        if ($scope['scope_type'] === 'genealogy_person') {
            return [$prefix.'genealogy_person_id = ?', [(int) $scope['genealogy_person_id']]];
        }

        $names = array_values(array_filter($scope['normalized_source_names'] ?? [], fn (string $name): bool => $name !== ''));
        if ($names === []) {
            return ['0 = 1', []];
        }
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        return ["LOWER(TRIM({$prefix}person_name)) IN ({$placeholders})", $names];
    }

    private function faceRenameClusterWhere(array $scope): array
    {
        if ($scope['scope_type'] === 'genealogy_person') {
            return ['genealogy_person_id = ?', [(int) $scope['genealogy_person_id']]];
        }

        $names = array_values(array_filter($scope['normalized_source_names'] ?? [], fn (string $name): bool => $name !== ''));
        if ($names === []) {
            return ['0 = 1', []];
        }
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        return ["LOWER(TRIM(name)) IN ({$placeholders})", $names];
    }

    private function normalizeFaceRenameName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }

    private function buildFaceRenamePreview(array $scope, string $newName): array
    {
        [$where, $params] = $this->faceRenameWhere($scope, 'frf');

        $summary = DB::selectOne("
            SELECT
                COUNT(*) as face_count,
                COUNT(DISTINCT frf.file_registry_id) as photo_count,
                SUM(CASE WHEN frf.hidden = 1 THEN 1 ELSE 0 END) as hidden_face_count,
                COUNT(DISTINCT NULLIF(TRIM(frf.person_name), '')) as current_name_count,
                GROUP_CONCAT(DISTINCT frf.person_name ORDER BY frf.person_name SEPARATOR '|||') as current_names
            FROM file_registry_faces frf
            WHERE {$where}
        ", $params);

        $target = DB::selectOne('
            SELECT COUNT(*) as face_count, COUNT(DISTINCT file_registry_id) as photo_count
            FROM file_registry_faces
            WHERE LOWER(TRIM(person_name)) = ?
        ', [$this->normalizeFaceRenameName($newName)]);

        $ftRows = DB::select("
            SELECT frf.genealogy_person_id, COUNT(*) as face_count, gp.gedcom_id,
                   gp.given_name, gp.surname,
                   NULLIF(TRIM(CONCAT_WS(' ', gp.given_name, gp.surname)), '') as canonical_name
            FROM file_registry_faces frf
            LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
            WHERE {$where} AND frf.genealogy_person_id IS NOT NULL
            GROUP BY frf.genealogy_person_id, gp.gedcom_id, gp.given_name, gp.surname
            ORDER BY face_count DESC
        ", $params);

        $sampleFiles = DB::select("
            SELECT fr.id, fr.filename, fr.current_path, COUNT(*) as face_count
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            WHERE {$where}
            GROUP BY fr.id, fr.filename, fr.current_path
            ORDER BY face_count DESC, fr.id DESC
            LIMIT 5
        ", $params);

        $clusterSummary = ['available' => false, 'cluster_count' => 0];
        try {
            [$clusterWhere, $clusterParams] = $this->faceRenameClusterWhere($scope);
            $cluster = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as cluster_count
                FROM person_clusters
                WHERE {$clusterWhere}
            ", $clusterParams);
            $clusterSummary = [
                'available' => true,
                'cluster_count' => (int) ($cluster->cluster_count ?? 0),
            ];
        } catch (\Throwable $e) {
            $clusterSummary['error'] = 'Cluster preview unavailable';
        }

        $currentNames = [];
        if (! empty($summary->current_names)) {
            $currentNames = array_values(array_filter(explode('|||', (string) $summary->current_names)));
        }

        $ftPeople = array_map(function ($row): array {
            return [
                'id' => (int) $row->genealogy_person_id,
                'gedcom_id' => $row->gedcom_id,
                'name' => $row->canonical_name ?: trim(($row->given_name ?? '').' '.($row->surname ?? '')),
                'face_count' => (int) $row->face_count,
            ];
        }, $ftRows);

        $warnings = [];
        if ((int) ($summary->face_count ?? 0) < 1) {
            $warnings[] = 'No faces match this rename scope.';
        }
        if ($ftPeople !== []) {
            $warnings[] = 'FT person records will not be renamed; only face labels, photo writeback flags, and search/RAG freshness will change.';
        }
        if (count($ftPeople) > 1) {
            $warnings[] = 'This face label is linked to more than one FT person. Review the listed FT names before applying.';
        }
        if ((int) ($target->face_count ?? 0) > 0) {
            $warnings[] = 'The target label already exists. Applying this will merge these face labels under the target display label.';
        }
        if ($scope['scope_type'] === 'name' && count($scope['source_names']) > 1) {
            $warnings[] = 'Multiple source labels will be normalized to one display label. Confirm these are the same person before applying.';
        }
        if ($scope['scope_type'] === 'name' && $scope['source_names'] !== [] && collect($scope['source_names'])->contains(fn (string $name): bool => ! str_contains($name, ' '))) {
            $warnings[] = 'This is a short nickname-style label. Confirm the sample photos so unrelated people are not merged.';
        }
        if (count($currentNames) > 1) {
            $warnings[] = 'Multiple current face labels in this scope will be normalized to the requested label.';
        }

        $faceCount = (int) ($summary->face_count ?? 0);
        $photoCount = (int) ($summary->photo_count ?? 0);
        $clusterCount = (int) ($clusterSummary['cluster_count'] ?? 0);

        return [
            'success' => true,
            'scope_type' => $scope['scope_type'],
            'source_name' => $scope['old_name'] ?: ($ftPeople[0]['name'] ?? ('FT person '.$scope['genealogy_person_id'])),
            'source_names' => $scope['source_names'],
            'new_name' => $newName,
            'genealogy_person_id' => $scope['genealogy_person_id'],
            'current_names' => $currentNames,
            'face_count' => $faceCount,
            'photo_count' => $photoCount,
            'hidden_face_count' => (int) ($summary->hidden_face_count ?? 0),
            'target_face_count' => (int) ($target->face_count ?? 0),
            'target_photo_count' => (int) ($target->photo_count ?? 0),
            'merged' => (int) ($target->face_count ?? 0) > 0,
            'ft_people' => $ftPeople,
            'ft_record_changes' => 0,
            'cluster_summary' => $clusterSummary,
            'sample_files' => array_map(fn ($row): array => [
                'id' => (int) $row->id,
                'filename' => $row->filename,
                'current_path' => $row->current_path,
                'face_count' => (int) $row->face_count,
            ], $sampleFiles),
            'warnings' => $warnings,
            'cascade' => [
                ['label' => 'Face labels updated', 'count' => $faceCount],
                ['label' => 'Photos queued for EXIF face writeback', 'count' => $photoCount],
                ['label' => 'Photos queued for file/RAG search reindex', 'count' => $photoCount],
                ['label' => 'Matching face cluster labels updated', 'count' => $clusterCount],
                ['label' => 'FT person records changed', 'count' => 0],
            ],
        ];
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
        if ($request->query->has('identity_uuid')) {
            return $this->globalPersonFaces($request);
        }
        $personName = trim($request->get('name', ''));
        $genealogyPersonId = (int) $request->get('genealogy_person_id', 0);
        $limit = min((int) $request->get('limit', 60), 200);
        $offset = (int) $request->get('offset', 0);
        $trustMode = $this->facePersonPageTrustMode();
        $normalizeName = static function (?string $name): string {
            $name = strtolower(trim((string) $name));
            $name = preg_replace('/\s+/', ' ', $name) ?? $name;

            return trim($name);
        };

        if (! $personName && $genealogyPersonId <= 0) {
            return response()->json(['error' => 'name or genealogy_person_id parameter required'], 400);
        }

        $normalizedNames = array_values(array_filter([$normalizeName($personName)], fn (string $name): bool => $name !== ''));
        $trustedStatuses = [
            'export_exact',
            'copy_export_exact',
            'export_variant_supported',
            'copy_export_variant_supported',
        ];
        $trustedStatusSql = "'".implode("','", $trustedStatuses)."'";
        $provenanceSelect = ',
                   fip.status AS provenance_status,
                   fip.evidence_source AS provenance_evidence_source,
                   fip.matched_export_name,
                   fip.matched_variant_name';
        $provenanceJoin = 'LEFT JOIN face_identity_provenance fip ON fip.file_registry_face_id = frf.id';
        $baseVisibilityWhere = "COALESCE(frf.hidden, 0) = 0 AND fr.status = 'active'
            AND frf.source_current = 1 AND frf.superseded_at IS NULL AND frf.global_identity_uuid IS NULL";
        $provenanceWhere = '';
        if ($trustMode === 'provenance_trusted') {
            $provenanceWhere = " AND fip.status IN ({$trustedStatusSql})";
        }

        $where = 'LOWER(TRIM(frf.person_name)) = ?';
        $params = [$normalizedNames[0] ?? $normalizeName($personName)];

        if ($genealogyPersonId > 0) {
            $person = DB::selectOne('SELECT given_name, surname FROM genealogy_persons WHERE id = ?', [$genealogyPersonId]);
            $canonicalName = $person ? trim(($person->given_name ?? '').' '.($person->surname ?? '')) : '';
            if ($canonicalName !== '') {
                $personName = $canonicalName;
                $normalizedNames[] = $normalizeName($canonicalName);
            }

            $normalizedNames = array_values(array_unique(array_filter($normalizedNames, fn (string $name): bool => $name !== '')));
            $where = 'frf.genealogy_person_id = ?';
            $params = [$genealogyPersonId];
        }

        $faces = DB::select("
            SELECT frf.id as face_id, frf.file_registry_id, frf.person_name,
                   frf.genealogy_person_id, frf.region_x, frf.region_y, frf.region_w, frf.region_h,
                   frf.confidence, frf.source, frf.hidden, frf.favorite,
                   fr.asset_uuid, fr.filename, fr.current_path
                   {$provenanceSelect}
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            {$provenanceJoin}
            WHERE {$baseVisibilityWhere}
              AND {$where}
              {$provenanceWhere}
            ORDER BY frf.confidence DESC, frf.id DESC
            LIMIT ? OFFSET ?
        ", [...$params, $limit, $offset]);

        $faceData = array_map(static function (object $face) use ($genealogyPersonId): array {
            $row = (array) $face;
            $facePersonId = $face->genealogy_person_id !== null ? (int) $face->genealogy_person_id : null;

            if ($genealogyPersonId > 0 && $facePersonId === $genealogyPersonId) {
                $row['assignment_source'] = 'ft_face';
                $row['assignment_label'] = 'FT';
            } elseif ($facePersonId !== null) {
                $row['assignment_source'] = 'other_ft_same_name';
                $row['assignment_label'] = 'Other FT';
            } else {
                $row['assignment_source'] = 'name_only';
                $row['assignment_label'] = 'Name only';
            }
            if (! empty($face->provenance_status)) {
                $row['assignment_source'] = 'provenance_'.$row['assignment_source'];
                $row['assignment_label'] = match ((string) $face->provenance_status) {
                    'export_exact' => 'Export',
                    'copy_export_exact' => 'Copy',
                    'export_variant_supported' => 'Variant',
                    'copy_export_variant_supported' => 'Copy variant',
                    default => $row['assignment_label'],
                };
            }

            return $row;
        }, $faces);

        $total = DB::selectOne("
            SELECT COUNT(*) as cnt
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            {$provenanceJoin}
            WHERE {$baseVisibilityWhere}
              AND {$where}
              {$provenanceWhere}
        ", $params);

        $linkedMedia = [];
        $linkedMediaTotal = 0;
        $includeLinkedMedia = $request->boolean('include_linked_media', false)
            && ($trustMode !== 'provenance_trusted' || $request->boolean('include_untrusted_linked_media', false));

        if ($genealogyPersonId > 0 && $offset === 0 && $includeLinkedMedia) {
            $linkedMediaRows = DB::select('
                SELECT
                    gpm.id AS person_media_id,
                    gm.id AS media_id,
                    gm.title,
                    gm.local_filename,
                    gm.nextcloud_path,
                    gm.original_path,
                    gm.media_type,
                    gm.mime_type,
                    gm.date_taken,
                    gm.media_date,
                    gm.face_count AS genealogy_face_count,
                    gpm.is_primary,
                    gpm.face_confirmed
                FROM genealogy_person_media gpm
                JOIN genealogy_media gm ON gm.id = gpm.media_id
                WHERE gpm.person_id = ?
                ORDER BY gpm.is_primary DESC, gpm.id DESC
                LIMIT 1000
            ', [$genealogyPersonId]);

            $paths = [];
            foreach ($linkedMediaRows as $media) {
                foreach ([$media->nextcloud_path ?? null, $media->original_path ?? null] as $path) {
                    $path = trim((string) $path);
                    if ($path !== '') {
                        $paths[] = $path;
                    }
                }
            }
            $paths = array_values(array_unique($paths));

            $registryByPath = [];
            $registryIds = [];
            if ($paths !== []) {
                $pathPlaceholders = implode(',', array_fill(0, count($paths), '?'));
                $registryRows = DB::select("
                    SELECT id, asset_uuid, filename, current_path, original_path, face_count
                    FROM file_registry
                    WHERE status = 'active' AND (current_path IN ({$pathPlaceholders})
                       OR original_path IN ({$pathPlaceholders}))
                ", [...$paths, ...$paths]);

                foreach ($registryRows as $registry) {
                    $registryIds[] = (int) $registry->id;
                    foreach ([$registry->current_path ?? null, $registry->original_path ?? null] as $path) {
                        $path = trim((string) $path);
                        if ($path !== '') {
                            $registryByPath[$path] = $registry;
                        }
                    }
                }
                $registryIds = array_values(array_unique($registryIds));
            }

            $matchedRegistryIds = [];
            if ($registryIds !== []) {
                $idPlaceholders = implode(',', array_fill(0, count($registryIds), '?'));
                $faceIdentityWhere = 'frf.genealogy_person_id = ?';
                $faceIdentityParams = [$genealogyPersonId];
                $linkedMediaProvenanceWhere = '';
                if ($trustMode === 'provenance_trusted') {
                    $linkedMediaProvenanceWhere = "AND fip.status IN ({$trustedStatusSql})";
                }

                $matchedRows = DB::select("
                    SELECT DISTINCT frf.file_registry_id
                    FROM file_registry_faces frf
                    LEFT JOIN face_identity_provenance fip ON fip.file_registry_face_id = frf.id
                    WHERE frf.file_registry_id IN ({$idPlaceholders})
                      AND {$faceIdentityWhere}
                      AND COALESCE(frf.hidden, 0) = 0
                      AND frf.source_current = 1 AND frf.superseded_at IS NULL AND frf.global_identity_uuid IS NULL
                      {$linkedMediaProvenanceWhere}
                ", [...$registryIds, ...$faceIdentityParams]);
                foreach ($matchedRows as $row) {
                    $matchedRegistryIds[(int) $row->file_registry_id] = true;
                }
            }

            foreach ($linkedMediaRows as $media) {
                $registry = null;
                foreach ([$media->nextcloud_path ?? null, $media->original_path ?? null] as $path) {
                    $path = trim((string) $path);
                    if ($path !== '' && isset($registryByPath[$path])) {
                        $registry = $registryByPath[$path];
                        break;
                    }
                }

                if ($registry !== null && isset($matchedRegistryIds[(int) $registry->id])) {
                    continue;
                }

                $media->file_registry_id = $registry !== null ? (int) $registry->id : null;
                $media->asset_uuid = $registry->asset_uuid ?? null;
                $media->filename = $registry->filename ?? null;
                $media->current_path = $registry->current_path ?? null;
                $media->registry_face_count = $registry->face_count ?? null;
                $linkedMedia[] = $media;

                if (count($linkedMedia) >= 60) {
                    break;
                }
            }
            $linkedMediaTotal = count($linkedMedia);
        }

        return response()->json([
            'success' => true,
            'data' => $faceData,
            'linked_media' => array_map(fn ($media) => [
                'person_media_id' => (int) $media->person_media_id,
                'media_id' => (int) $media->media_id,
                'title' => $media->title,
                'filename' => $media->filename ?: $media->local_filename ?: basename((string) ($media->nextcloud_path ?: $media->original_path)),
                'asset_uuid' => $media->asset_uuid,
                'thumbnail_url' => $media->asset_uuid ? "/api/media/{$media->asset_uuid}/thumbnail/medium" : null,
                'file_registry_id' => $media->file_registry_id !== null ? (int) $media->file_registry_id : null,
                'current_path' => $media->current_path,
                'nextcloud_path' => $media->nextcloud_path,
                'original_path' => $media->original_path,
                'media_type' => $media->media_type,
                'mime_type' => $media->mime_type,
                'date_taken' => $media->date_taken,
                'media_date' => $media->media_date,
                'is_primary' => (bool) $media->is_primary,
                'face_confirmed' => (bool) $media->face_confirmed,
                'registry_face_count' => $media->registry_face_count !== null ? (int) $media->registry_face_count : null,
                'genealogy_face_count' => $media->genealogy_face_count !== null ? (int) $media->genealogy_face_count : null,
            ], $linkedMedia),
            'linked_media_total' => $linkedMediaTotal,
            'total' => (int) ($total->cnt ?? 0),
            'person_name' => $personName,
            'genealogy_person_id' => $genealogyPersonId ?: null,
            'trust_mode' => $trustMode,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** Shared stored-proof eligibility for global lists and their counts; crops verify bytes. */
    private function globalFaceRows(bool $hidden = false): Builder
    {
        return DB::table('file_registry_faces as frf')->join('file_registry as fr', 'fr.id', '=', 'frf.file_registry_id')
            ->join('global_face_identities as identity', 'identity.identity_uuid', '=', 'frf.global_identity_uuid')
            ->where('identity.status', 'operator_confirmed')->whereRaw("TRIM(identity.canonical_name) <> ''")
            ->where('fr.status', 'active')->where('frf.source_current', 1)->whereNull('frf.superseded_at')->where('frf.hidden', $hidden ? 1 : 0)
            ->where('frf.id', '>', 0)
            ->where('frf.face_region_convention', 'normalized_top_left')
            ->whereRaw('identity.identity_uuid REGEXP ?', ['^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'])
            ->whereRaw('frf.source_sha256 REGEXP ?', ['^[a-f0-9]{64}$'])
            ->whereRaw('frf.global_identity_approval_receipt_id REGEXP ?', ['^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'])
            ->where(function ($query): void {
                $query->whereNull('fr.content_hash')->orWhere('fr.content_hash', '')->orWhereColumn('fr.content_hash', 'frf.source_sha256');
            })
            ->where('frf.region_x', '>=', 0)->where('frf.region_y', '>=', 0)->where('frf.region_w', '>', 0)->where('frf.region_h', '>', 0)
            ->whereRaw('frf.region_x + frf.region_w <= 1 AND frf.region_y + frf.region_h <= 1');
    }

    private function globalIdentityDetails(array $rows): array
    {
        $uuids = array_map(fn ($row) => $row->identity_uuid, $rows);
        if ($uuids === []) {
            return [];
        }
        $aliases = DB::table('global_face_identity_aliases')->whereIn('identity_uuid', $uuids)->orderBy('alias')->get()->groupBy('identity_uuid');
        $mappings = DB::table('global_face_identity_genealogy_links as mapping')
            ->leftJoin('genealogy_trees as tree', 'tree.id', '=', 'mapping.tree_id')
            ->leftJoin('genealogy_persons as person', function ($join): void {
                $join->on('person.id', '=', 'mapping.genealogy_person_id')->on('person.tree_id', '=', 'mapping.tree_id');
            })->whereIn('mapping.identity_uuid', $uuids)->orderBy('mapping.tree_id')->orderBy('mapping.genealogy_person_id')
            ->get(['mapping.identity_uuid', 'mapping.tree_id', 'mapping.genealogy_person_id', 'tree.name as tree_name', 'person.given_name', 'person.surname'])
            ->groupBy('identity_uuid');

        return array_map(fn ($row) => ['identity_uuid' => $row->identity_uuid, 'canonical_name' => $row->canonical_name,
            'aliases' => ($aliases[$row->identity_uuid] ?? collect())->pluck('alias')->all(),
            'mappings' => ($mappings[$row->identity_uuid] ?? collect())->map(fn ($mapping) => [
                'tree_id' => (int) $mapping->tree_id, 'tree_name' => $mapping->tree_name,
                'genealogy_person_id' => (int) $mapping->genealogy_person_id,
                'person_name' => trim(($mapping->given_name ?? '').' '.($mapping->surname ?? '')),
            ])->all()], $rows);
    }

    private function globalFaceSelect(Builder $query): Builder
    {
        return $query->select(['frf.*', 'frf.id as face_id', 'fr.status as file_status', 'fr.asset_uuid', 'fr.filename']);
    }

    private function globalFacePeople(string $search, int $limit): array
    {
        return DB::transaction(function () use ($search, $limit): array {
            $query = $this->globalFaceRows();
            if ($search !== '') {
                $query->where(function ($query) use ($search): void {
                    $query->where('identity.canonical_name', 'like', '%'.$search.'%')->orWhereExists(function ($aliases) use ($search): void {
                        $aliases->selectRaw('1')->from('global_face_identity_aliases as alias')
                            ->whereColumn('alias.identity_uuid', 'identity.identity_uuid')->where('alias.alias', 'like', '%'.$search.'%');
                    });
                });
            }
            $rows = $query->groupBy('identity.identity_uuid', 'identity.canonical_name')
                ->select(['identity.identity_uuid', 'identity.canonical_name'])
                ->selectRaw('COUNT(*) AS face_count, COUNT(DISTINCT frf.file_registry_id) AS media_count, MIN(frf.id) AS representative_face_id, MAX(frf.favorite) AS favorite')
                ->orderByDesc('face_count')->orderBy('identity.identity_uuid')->limit($limit)->get()->all();
            $details = collect($this->globalIdentityDetails($rows))->keyBy('identity_uuid');
            $representatives = $this->globalFaceSelect($this->globalFaceRows()->whereIn('frf.id', array_column($rows, 'representative_face_id')))
                ->get()->keyBy('face_id');

            return array_values(array_filter(array_map(function ($row) use ($details, $representatives): ?object {
                $representative = $representatives[$row->representative_face_id] ?? null;
                $evidence = $representative ? $this->globalFaceStoredEvidence($representative) : null;
                if ($evidence === null) {
                    return null;
                }

                return (object) ['identity_key' => 'global:'.$row->identity_uuid, 'scope_type' => 'global_identity',
                    'identity_uuid' => $row->identity_uuid, 'person_name' => $row->canonical_name,
                    'aliases' => $details[$row->identity_uuid]['aliases'], 'genealogy_person_id' => null, 'scope_name' => null,
                    'genealogy_name' => null, 'face_label_name' => null, 'face_name_variants' => null, 'face_name_variant_count' => 0,
                    'face_count' => (int) $row->face_count, 'media_count' => (int) $row->media_count, 'linked_media_count' => 0,
                    'representative_face_id' => (int) $row->representative_face_id, 'representative_crop_url' => $evidence['crop_url'],
                    'representative_asset_uuid' => null, 'representative_filename' => null, 'favorite' => (int) $row->favorite];
            }, $rows)));
        });
    }

    private function globalPersonFaces(Request $request): JsonResponse
    {
        if (! auth('web')->check()) {
            return response()->json(['error' => 'Authenticated operator required'], 401);
        }
        $uuid = $request->query('identity_uuid');
        if (! is_string($uuid) || ! Str::isUuid($uuid) || $request->query->has('genealogy_person_id') || trim((string) $request->query('name', '')) !== '') {
            return response()->json(['error' => 'Use one explicit global identity UUID'], 422);
        }

        return DB::transaction(function () use ($request, $uuid): JsonResponse {
            $identity = DB::table('global_face_identities')->where('identity_uuid', $uuid)->where('status', 'operator_confirmed')->first();
            if (! $identity) {
                return response()->json(['error' => 'Confirmed individual not found'], 404);
            }
            $limit = max(1, min((int) $request->query('limit', 60), 200));
            $offset = max(0, (int) $request->query('offset', 0));
            $query = $this->globalFaceRows()->where('frf.global_identity_uuid', $uuid);
            $total = (clone $query)->count();
            $faces = $this->globalFaceSelect($query)->orderByDesc('frf.id')->limit($limit)->offset($offset)->get()->map(function ($face): array {
                $evidence = $this->globalFaceStoredEvidence($face);

                return ['face_id' => (int) $face->face_id, 'file_registry_id' => (int) $face->file_registry_id,
                    'asset_uuid' => $face->asset_uuid, 'filename' => $face->filename, 'identity_uuid' => $face->global_identity_uuid,
                    'source_sha256' => $face->source_sha256, 'source_current' => 1, 'superseded_at' => null, 'hidden' => 0,
                    'face_region_convention' => 'normalized_top_left', 'global_face_evidence' => $evidence,
                    'crop_url' => $evidence['crop_url'], 'assignment_source' => 'global_identity', 'assignment_label' => 'Confirmed',
                    'confidence' => $face->confidence, 'favorite' => (bool) $face->favorite];
            })->all();

            return response()->json(['success' => true, 'data' => $faces, 'total' => $total, 'identity_uuid' => $uuid,
                'identity' => $this->globalIdentityDetails([$identity])[0], 'person_name' => $identity->canonical_name,
                'trust_mode' => 'operator_confirmed', 'linked_media' => [], 'linked_media_total' => 0, 'limit' => $limit, 'offset' => $offset]);
        });
    }

    private function facePersonPageTrustMode(): string
    {
        try {
            $row = DB::selectOne(
                'SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
                ['faces', 'person_page_trust_mode']
            );
            $mode = trim((string) ($row->config_value ?? ''));
            if (in_array($mode, ['provenance_trusted', 'review_all', 'legacy_all'], true)) {
                return $mode;
            }
        } catch (\Throwable $e) {
            Log::warning('MediaBrowserController: face person trust mode lookup failed', ['error' => $e->getMessage()]);
        }

        return 'provenance_trusted';
    }

    /**
     * Remove a face from a person (set person_name back to empty)
     *
     * POST /api/media/faces/{faceId}/exclude
     */
    public function excludeFace(int $faceId): JsonResponse
    {
        $result = $this->clearFacePersonAssignment($faceId, true);
        if (($result['error'] ?? null) === 'face_not_found') {
            return response()->json(['error' => 'Face not found'], 404);
        }

        return response()->json([
            'success' => true,
            'excluded_from' => $result['previous_person_name'] ?? null,
            'previous_genealogy_person_id' => $result['previous_genealogy_person_id'] ?? null,
            'assignment' => $result,
        ]);
    }

    /**
     * Clear a face/person assignment and mark the file for a metadata rewrite.
     *
     * If $expectedPersonId is supplied, the row is only cleared when it is still
     * linked to that same rejected person. This keeps stale review actions from
     * clobbering a later operator reassignment.
     */
    private function clearFacePersonAssignment(int $faceId, bool $clearName, ?int $expectedPersonId = null): array
    {
        return DB::transaction(function () use ($faceId, $clearName, $expectedPersonId): array {
            $face = DB::selectOne('
            SELECT frf.id, frf.file_registry_id, frf.person_name, frf.genealogy_person_id,
                   frf.global_identity_uuid, frf.global_identity_approval_receipt_id, fr.current_path
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id
            WHERE frf.id = ? FOR UPDATE
        ', [$faceId]);

            if (! $face) {
                return ['success' => false, 'error' => 'face_not_found'];
            }
            if ($this->faceHasGlobalAssignment($face)) {
                throw new HttpException(409, 'Global identity requires its individual operator review.');
            }

            if ($expectedPersonId !== null && (int) ($face->genealogy_person_id ?? 0) !== $expectedPersonId) {
                return [
                    'success' => true,
                    'cleared' => false,
                    'reason' => 'face_reassigned_to_different_person',
                    'previous_genealogy_person_id' => $face->genealogy_person_id !== null ? (int) $face->genealogy_person_id : null,
                    'previous_person_name' => $face->person_name,
                ];
            }

            $setNameSql = $clearName ? "person_name = '', " : '';
            $updated = DB::update("
            UPDATE file_registry_faces
            SET {$setNameSql}genealogy_person_id = NULL, verified = 0, updated_at = NOW()
            WHERE id = ? AND global_identity_uuid IS NULL AND global_identity_approval_receipt_id IS NULL
        ", [$faceId]);

            if ($updated > 0) {
                DB::update('
                UPDATE file_registry
                SET exif_faces_written = 0, metadata_synced_at = NULL, rag_indexed_at = NULL
                WHERE id = ?
            ', [$face->file_registry_id]);

                if (! empty($face->current_path)) {
                    DB::delete('DELETE FROM genealogy_media_scan_log WHERE nextcloud_path = ?', [$face->current_path]);
                }
            }

            return [
                'success' => true,
                'cleared' => $updated > 0,
                'face_id' => $faceId,
                'file_registry_id' => (int) $face->file_registry_id,
                'previous_genealogy_person_id' => $face->genealogy_person_id !== null ? (int) $face->genealogy_person_id : null,
                'previous_person_name' => $face->person_name,
            ];
        });
    }

    private function faceHasGlobalAssignment(object $face): bool
    {
        return ($face->global_identity_uuid ?? null) !== null || ($face->global_identity_approval_receipt_id ?? null) !== null;
    }

    private function globalFaceMutationConflict(): JsonResponse
    {
        return response()->json(['error' => 'Global identity requires its individual operator review.'], 409);
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

        $faceEmbedding = app(FaceEmbeddingService::class);

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

        $faceEmbedding = app(FaceEmbeddingService::class);

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
        $faceEmbedding = app(FaceEmbeddingService::class);

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
        $faceEmbedding = app(FaceEmbeddingService::class);

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
