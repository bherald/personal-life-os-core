<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Thumbnail/Preview Generation Service
 *
 * Generates and caches thumbnails for images, PDFs, videos, and Office documents.
 * Uses a multi-strategy approach:
 * 1. Check local cache
 * 2. Try Nextcloud preview API (images only)
 * 3. Generate locally:
 *    - Images: GD/Imagick
 *    - PDFs: Imagick or pdftoppm CLI
 *    - Videos: ffmpeg (frame at 5s)
 *    - Office docs: LibreOffice headless → PDF → thumbnail
 *
 * Storage: storage/app/thumbnails/{uuid_prefix_2}/{uuid}.{size}.jpg
 * Sizes: small (150x150), medium (300x300), large (600x600)
 *
 * Dependencies:
 * - Imagick or GD for images
 * - pdftoppm (poppler-utils) for PDF fallback
 * - ffmpeg for video
 * - LibreOffice (soffice) for Office documents (docx, xlsx, pptx, doc, xls, ppt, odt, ods, odp)
 */
class ThumbnailService
{
    private ?NextcloudFileApiService $nextcloudApi = null;

    private bool $batchMode = false;

    /** Available thumbnail sizes */
    private const SIZES = [
        'small' => ['width' => 150, 'height' => 150],
        'medium' => ['width' => 300, 'height' => 300],
        'large' => ['width' => 600, 'height' => 600],
    ];

    /** JPEG quality for thumbnails */
    private const JPEG_QUALITY = 85;

    /** Supported MIME types for thumbnail generation */
    private const SUPPORTED_IMAGE_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/tiff',
        'image/jp2',
        'image/jpx',
        'image/jpm',
        'image/j2k',
        'image/x-jp2',
        'image/heic',
        'image/heif',
        'image/heic-sequence',
        'image/heif-sequence',
    ];

    private const SUPPORTED_PDF_TYPES = ['application/pdf'];

    private const SUPPORTED_VIDEO_TYPES = ['video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm', 'video/x-matroska'];

    private const SUPPORTED_OFFICE_TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',  // docx
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',        // xlsx
        'application/vnd.openxmlformats-officedocument.presentationml.presentation', // pptx
        'application/msword',                                                        // doc
        'application/vnd.ms-excel',                                                  // xls
        'application/vnd.ms-powerpoint',                                             // ppt
        'application/vnd.oasis.opendocument.text',                                   // odt
        'application/vnd.oasis.opendocument.spreadsheet',                            // ods
        'application/vnd.oasis.opendocument.presentation',                           // odp
    ];

    private const SUPPORTED_TEXT_TYPES = [
        'text/plain',                    // txt, log
        'text/csv',                      // csv
        'text/markdown',                 // md
        'text/html',                     // html
        'text/xml',                      // xml
        'application/json',              // json
        'application/xml',               // xml
        'text/x-php',                    // php
        'text/x-python',                 // py
        'text/x-script.python',          // py (alternate libmagic detection)
        'text/javascript',               // js
        'application/javascript',        // js
        'text/css',                      // css
        'text/x-java-source',            // java
        'text/x-java',                   // java/ts/js (alternate libmagic detection)
        'text/x-c',                      // c, h
        'text/x-c++src',                 // cpp
        'text/x-c++',                    // cpp (alternate)
        'text/x-asm',                    // asm, s
        'text/x-perl',                   // pl, pm
        'text/x-vbscript',              // vbs
        'text/x-msdos-batch',            // bat, cmd
        'text/x-Algol68',               // sometimes detected for js
        'text/x-shellscript',            // sh, bash
        'application/x-yaml',            // yaml, yml
        'text/yaml',                     // yaml
        'text/typescript',               // ts (when detected correctly)
        'application/typescript',        // ts
        'application/x-typescript',      // ts
        'image/svg+xml',                 // svg (XML text)
        'application/x-sh',              // shell scripts
        'text/x-sql',                    // sql
        'text/x-ruby',                   // rb
        'text/x-go',                     // go
        'text/x-rust',                   // rs
        'message/rfc822',                // eml (show headers as text)
    ];

    private const SUPPORTED_ARCHIVE_TYPES = [
        'application/zip', 'application/x-zip-compressed',
        'application/x-7z-compressed', 'application/x-iso9660-image',
        'application/java-archive',      // jar
        'application/epub+zip',          // epub
        'application/x-rar-compressed',  // rar (alternate)
    ];

    private const SUPPORTED_DBF_EXTENSIONS = ['dbf', 'cdx'];

    /** Extension → routing category (fallback when MIME detection is wrong/unreliable) */
    private const EXTENSION_TYPE_MAP = [
        // Code/text → generateTextThumbnail()
        'ts' => 'text', 'tsx' => 'text', 'jsx' => 'text', 'vue' => 'text',
        'svelte' => 'text', 'mjs' => 'text', 'cjs' => 'text',
        'rb' => 'text', 'go' => 'text', 'rs' => 'text', 'swift' => 'text',
        'kt' => 'text', 'scala' => 'text', 'lua' => 'text', 'r' => 'text',
        'sql' => 'text', 'sh' => 'text', 'bash' => 'text', 'zsh' => 'text',
        'toml' => 'text', 'ini' => 'text', 'env' => 'text', 'conf' => 'text',
        'map' => 'text', 'lock' => 'text', 'svg' => 'text',
        'cs' => 'text', 'cpp' => 'text', 'c' => 'text', 'h' => 'text',
        'java' => 'text', 'pyi' => 'text', 'resx' => 'text',
        'vcf' => 'text', 'ics' => 'text',
        // Common code extensions missing from MIME detection
        'js' => 'text', 'css' => 'text', 'php' => 'text', 'py' => 'text',
        'md' => 'text', 'yml' => 'text', 'yaml' => 'text', 'xml' => 'text',
        'htm' => 'text', 'html' => 'text', 'csv' => 'text', 'log' => 'text',
        'bat' => 'text', 'cmd' => 'text', 'ps1' => 'text', 'psm1' => 'text',
        'vb' => 'text', 'vbs' => 'text', 'sln' => 'text', 'csproj' => 'text',
        'vbproj' => 'text', 'fsproj' => 'text', 'xaml' => 'text', 'axaml' => 'text',
        'rst' => 'text', 'd' => 'text', 'pl' => 'text', 'pm' => 'text',
        'asm' => 'text', 's' => 'text', 'gradle' => 'text', 'groovy' => 'text',
        'properties' => 'text', 'gitignore' => 'text', 'npmignore' => 'text',
        'dockerignore' => 'text', 'editorconfig' => 'text', 'htaccess' => 'text',
        'prefab' => 'text', 'asset' => 'text', 'meta' => 'text', 'target' => 'text',
        'config' => 'text', 'settings' => 'text', 'cxx' => 'text', 'cc' => 'text',
        'hh' => 'text', 'hpp' => 'text', 'stub' => 'text', 'phpt' => 'text',
        'inc' => 'text', 'rdp' => 'text',
        // Archives → generateArchiveThumbnail()
        'zip' => 'archive', '7z' => 'archive', 'rar' => 'archive',
        'tar' => 'archive', 'gz' => 'archive', 'bz2' => 'archive',
        'tgz' => 'archive', 'iso' => 'archive', 'xz' => 'archive',
        'jar' => 'archive', 'epub' => 'archive', 'fzz' => 'archive',
        // FoxPro/VFP DBF-family → generateDbfThumbnail()
        'dbf' => 'dbf', 'cdx' => 'dbf',
        'scx' => 'dbf', 'frx' => 'dbf', 'vcx' => 'dbf', 'pjx' => 'dbf',
        'lbx' => 'dbf', 'lbt' => 'dbf', 'dbc' => 'dbf', 'mpr' => 'dbf',
        'frt' => 'dbf', 'fpt' => 'dbf', 'sct' => 'dbf', 'vct' => 'dbf',
        'pjt' => 'dbf',
        // Binary/other → generateGenericIconThumbnail()
        'dll' => 'binary', 'exe' => 'binary', 'bin' => 'binary',
        'pyc' => 'binary', 'so' => 'binary', 'dylib' => 'binary',
        'aif' => 'binary', 'aiff' => 'binary', 'wav' => 'binary',
        'lnk' => 'binary', 'msi' => 'binary', 'pfx' => 'binary',
        'pdb' => 'binary', 'qm' => 'binary', 'prc' => 'binary',
        'dtbo' => 'binary', 'uasset' => 'binary', 'cache' => 'binary',
        'hbaked' => 'binary', 'hbakedmaterial' => 'binary', 'dst' => 'binary',
        'o' => 'binary', 'flat' => 'binary', 'resources' => 'binary',
        'msf' => 'binary', 'diag' => 'binary', 'mid' => 'binary',
        'cur' => 'binary',
        // ICO → route to image handler (Imagick supports it)
        'ico' => 'image',
        // JPEG 2000 archival scans → route to image handler / Pillow fallback
        'jp2' => 'image', 'j2k' => 'image', 'jpf' => 'image', 'jpx' => 'image', 'jpm' => 'image',
        // Phone-origin image formats already classified as images by config/file_types.php
        'heic' => 'image', 'heif' => 'image',
    ];

    private function getNextcloudApi(): NextcloudFileApiService
    {
        if ($this->nextcloudApi === null) {
            $this->nextcloudApi = app(NextcloudFileApiService::class);
        }

        return $this->nextcloudApi;
    }

    /**
     * Get a thumbnail for a file, generating if needed
     *
     * @param  string  $assetUuid  File asset UUID
     * @param  string  $size  Thumbnail size (small, medium, large)
     * @return array {success, path, mime_type, from_cache} or {success: false, error}
     */
    public function getThumbnail(string $assetUuid, string $size = 'medium'): array
    {
        if (! isset(self::SIZES[$size])) {
            return ['success' => false, 'error' => "Invalid size: {$size}. Use: small, medium, large"];
        }

        // Check local cache
        $cachePath = $this->getThumbnailPath($assetUuid, $size);
        $fullPath = storage_path('app/'.$cachePath);

        if (file_exists($fullPath)) {
            // Ensure DB record has this size tracked (backfill for files generated before DB tracking)
            $file = DB::selectOne(
                "SELECT id, thumbnail_sizes FROM file_registry WHERE asset_uuid = ? AND status = 'active' LIMIT 1",
                [$assetUuid]
            );
            if ($file) {
                $sizes = json_decode($file->thumbnail_sizes ?? '[]', true) ?: [];
                if (! in_array($size, $sizes)) {
                    $this->updateThumbnailRecord($file->id, $assetUuid, $size);
                }
            }

            return [
                'success' => true,
                'path' => $fullPath,
                'mime_type' => 'image/jpeg',
                'from_cache' => true,
            ];
        }

        // Generate thumbnail
        return $this->generateThumbnail($assetUuid, $size);
    }

    /**
     * Generate a single thumbnail
     */
    public function generateThumbnail(string $assetUuid, string $size = 'medium'): array
    {
        $sizeConfig = self::SIZES[$size] ?? self::SIZES['medium'];
        $w = $sizeConfig['width'];
        $h = $sizeConfig['height'];

        // Get file info from registry
        $file = DB::selectOne(
            "SELECT id, asset_uuid, current_path, mime_type, nextcloud_fileid, file_size
             FROM file_registry WHERE asset_uuid = ? AND status = 'active' LIMIT 1",
            [$assetUuid]
        );

        if (! $file) {
            return ['success' => false, 'error' => 'File not found in registry'];
        }

        $mimeType = $file->mime_type ?? '';

        // Try Nextcloud preview API first (for images) — skipped in batch mode (local Imagick is faster)
        if (! $this->batchMode && $file->nextcloud_fileid && in_array($mimeType, self::SUPPORTED_IMAGE_TYPES)) {
            $ncResult = $this->tryNextcloudPreview($file, $w, $h, $assetUuid, $size);
            if ($ncResult && $ncResult['success']) {
                return $ncResult;
            }
        }

        // Filesystem-first: use local NVMe path when available (~1000x faster than WebDAV)
        $ncApi = $this->getNextcloudApi();
        $localFsPath = $ncApi->localPath($file->current_path);
        $tempFile = null;
        $deleteTempFile = false;

        if ($localFsPath) {
            $tempFile = $localFsPath; // Use filesystem path directly — no copy needed
        } else {
            // WebDAV fallback (non-prod or file not on local filesystem)
            $sourceResult = $ncApi->downloadFile($file->current_path);
            if (! $sourceResult['success']) {
                $this->markError($file->id, 'Download failed: '.($sourceResult['error'] ?? 'unknown'));

                return ['success' => false, 'error' => 'Failed to download source file'];
            }
            $tempFile = tempnam(sys_get_temp_dir(), 'thumb_');
            file_put_contents($tempFile, $sourceResult['content']);
            $deleteTempFile = true;
        }

        try {
            $outputPath = $this->ensureThumbnailDir($assetUuid, $size);

            if (in_array($mimeType, self::SUPPORTED_IMAGE_TYPES)) {
                $result = $this->generateImageThumbnail($tempFile, $outputPath, $w, $h);
            } elseif (in_array($mimeType, self::SUPPORTED_PDF_TYPES)) {
                $result = $this->generatePdfThumbnail($tempFile, $outputPath, $w, $h);
            } elseif (in_array($mimeType, self::SUPPORTED_VIDEO_TYPES)) {
                $result = $this->generateVideoThumbnail($tempFile, $outputPath, $w, $h);
            } elseif (in_array($mimeType, self::SUPPORTED_OFFICE_TYPES)) {
                $result = $this->generateOfficeThumbnail($tempFile, $outputPath, $w, $h);
            } elseif (in_array($mimeType, self::SUPPORTED_TEXT_TYPES) || $this->isTextFile($tempFile)) {
                $result = $this->generateTextThumbnail($tempFile, $outputPath, $w, $h, $file->current_path ?? '');
            } else {
                // Extension-based fallback when MIME detection is wrong/unreliable
                $ext = strtolower(pathinfo($file->current_path ?? '', PATHINFO_EXTENSION));
                $category = self::EXTENSION_TYPE_MAP[$ext] ?? null;

                if ($category === 'text') {
                    $result = $this->generateTextThumbnail($tempFile, $outputPath, $w, $h, $file->current_path ?? '');
                } elseif ($category === 'archive') {
                    $result = $this->generateArchiveThumbnail($tempFile, $outputPath, $w, $h, $file->current_path ?? '');
                } elseif ($category === 'dbf') {
                    $result = $this->generateDbfThumbnail($tempFile, $outputPath, $w, $h, $file->current_path ?? '');
                } elseif ($category === 'image') {
                    $result = $this->generateImageThumbnail($tempFile, $outputPath, $w, $h);
                } elseif ($category === 'binary') {
                    $result = $this->generateGenericIconThumbnail($outputPath, $w, $h, $ext);
                } else {
                    // True unknown — generic icon so it has something to show
                    $result = $this->generateGenericIconThumbnail($outputPath, $w, $h, $ext, $mimeType);
                    if (! $result) {
                        $this->markError($file->id, "Unsupported mime type: {$mimeType}");

                        return ['success' => false, 'error' => "Unsupported file type: {$mimeType}"];
                    }
                }
            }

            if ($result) {
                $this->updateThumbnailRecord($file->id, $assetUuid, $size);

                return [
                    'success' => true,
                    'path' => $outputPath,
                    'mime_type' => 'image/jpeg',
                    'from_cache' => false,
                ];
            }

            $this->markError($file->id, 'Thumbnail generation failed');

            return ['success' => false, 'error' => 'Thumbnail generation failed'];
        } finally {
            if ($deleteTempFile && $tempFile) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Generate all thumbnail sizes for a file
     */
    public function generateAllSizes(string $assetUuid): array
    {
        $results = [];
        foreach (array_keys(self::SIZES) as $size) {
            $results[$size] = $this->generateThumbnail($assetUuid, $size);
        }

        return $results;
    }

    /**
     * Batch generate thumbnails for files matching filters
     */
    public function batchGenerate(array $filters = [], int $limit = 50, int $deadlineSeconds = 0): array
    {
        $this->batchMode = true;
        $stats = ['processed' => 0, 'generated' => 0, 'errors' => 0, 'skipped' => 0, 'stopped_early' => false];
        $startedAt = microtime(true);

        $where = "WHERE status = 'active' AND thumbnail_generated_at IS NULL AND thumbnail_error IS NULL AND mime_type IS NOT NULL AND mime_type != ''";
        $params = [];

        if (! empty($filters['type'])) {
            $mimeTypes = $this->getMimeTypesForFilter($filters['type']);
            if (! empty($mimeTypes)) {
                $placeholders = implode(',', array_fill(0, count($mimeTypes), '?'));
                $where .= " AND mime_type IN ({$placeholders})";
                $params = array_merge($params, $mimeTypes);
            }
        }

        if (! empty($filters['path'])) {
            $where .= ' AND current_path LIKE ?';
            $params[] = $filters['path'].'%';
        }

        $params[] = $limit;

        $files = DB::select(
            "SELECT id, asset_uuid, current_path, mime_type, nextcloud_fileid, file_size
             FROM file_registry
             {$where}
             ORDER BY created_at DESC
             LIMIT ?",
            $params
        );

        foreach ($files as $file) {
            if ($this->shouldStopBeforeStartingFile($startedAt, $deadlineSeconds, $stats['processed'])) {
                $stats['stopped_early'] = true;
                break;
            }

            $stats['processed']++;
            $mimeType = $file->mime_type ?? '';
            $ext = strtolower(pathinfo($file->current_path ?? '', PATHINFO_EXTENSION));

            if (! $this->isSupportedMimeType($mimeType) && ! isset(self::EXTENSION_TYPE_MAP[$ext])) {
                $stats['skipped']++;
                // Mark as unsupported so it's excluded from future batch queries
                DB::update(
                    "UPDATE file_registry SET thumbnail_error = 'unsupported_type', thumbnail_generated_at = NOW() WHERE id = ?",
                    [$file->id]
                );

                continue;
            }

            try {
                $results = $this->generateAllSizes($file->asset_uuid);
                $anySuccess = false;
                foreach ($results as $result) {
                    if ($result['success']) {
                        $anySuccess = true;
                    }
                }

                if ($anySuccess) {
                    $stats['generated']++;
                } else {
                    $stats['errors']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('Thumbnail: Batch generation error', [
                    'uuid' => $file->asset_uuid,
                    'error' => $e->getMessage(),
                ]);
                // Mark error so file doesn't retry infinitely
                DB::update(
                    'UPDATE file_registry SET thumbnail_error = ? WHERE id = ?',
                    [substr($e->getMessage(), 0, 255), $file->id]
                );
            }
        }

        return $stats;
    }

    private function shouldStopBeforeStartingFile(float $startedAt, int $deadlineSeconds, int $processedCount): bool
    {
        if ($deadlineSeconds <= 0 || $processedCount <= 0) {
            return false;
        }

        $elapsedSeconds = microtime(true) - $startedAt;
        $avgSecondsPerFile = $elapsedSeconds / max(1, $processedCount);

        return ($elapsedSeconds + $avgSecondsPerFile) >= $deadlineSeconds;
    }

    /**
     * Generate image thumbnail using GD or Imagick
     */
    private function generateImageThumbnail(string $source, string $output, int $w, int $h): bool
    {
        try {
            // Use subprocess isolation for TIFF/GIF to prevent C-level crashes from killing the batch
            $mimeType = function_exists('mime_content_type') ? mime_content_type($source) : null;
            $riskyMimes = ['image/tiff', 'image/gif'];
            if (extension_loaded('imagick') && $mimeType && in_array($mimeType, $riskyMimes)) {
                return $this->generateThumbnailInSubprocess($source, $output, $w, $h);
            }

            if (extension_loaded('imagick')) {
                $result = $this->generateImageThumbnailImagick($source, $output, $w, $h);
                if ($result) {
                    return true;
                }
            }

            $result = $this->generateImageThumbnailGD($source, $output, $w, $h);
            if ($result) {
                return true;
            }

            return $this->generateImageThumbnailPillow($source, $output, $w, $h);
        } catch (\Throwable $e) {
            Log::warning('Thumbnail: Image generation failed', ['source' => $source, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function generateImageThumbnailGD(string $source, string $output, int $w, int $h): bool
    {
        $info = @getimagesize($source);
        if (! $info) {
            return false;
        }

        $srcImage = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_GIF => @imagecreatefromgif($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            IMAGETYPE_BMP => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($source) : false,
            default => false,
        };

        if (! $srcImage) {
            return false;
        }

        // Auto-orient based on EXIF data
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            if (! empty($exif['Orientation'])) {
                $srcImage = match ((int) $exif['Orientation']) {
                    3 => imagerotate($srcImage, 180, 0),
                    6 => imagerotate($srcImage, -90, 0),
                    8 => imagerotate($srcImage, 90, 0),
                    default => $srcImage,
                };
            }
        }

        $srcW = imagesx($srcImage);
        $srcH = imagesy($srcImage);

        // Aspect-preserving resize to fit within w x h
        $ratio = min($w / $srcW, $h / $srcH);
        $newW = (int) round($srcW * $ratio);
        $newH = (int) round($srcH * $ratio);

        $dstImage = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

        $result = imagejpeg($dstImage, $output, self::JPEG_QUALITY);

        imagedestroy($srcImage);
        imagedestroy($dstImage);

        return $result;
    }

    private function generateImageThumbnailImagick(string $source, string $output, int $w, int $h): bool
    {
        // Pre-validate: check dimensions without full decode to avoid C-level crashes
        $mimeType = function_exists('mime_content_type') ? mime_content_type($source) : null;
        if ($mimeType && in_array($mimeType, ['image/tiff', 'image/gif'])) {
            $probe = new \Imagick;
            $probe->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 64 * 1024 * 1024);
            $probe->pingImage($source);
            $pw = $probe->getImageWidth();
            $ph = $probe->getImageHeight();
            $probe->destroy();
            if ($pw * $ph > 100_000_000) {
                throw new \Exception("Image too large for safe thumbnailing: {$pw}x{$ph}");
            }
        }

        $imagick = new \Imagick;
        // Set resource limits to prevent C-level crashes on problematic files
        $imagick->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        $imagick->setResourceLimit(\Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
        $imagick->setResourceLimit(\Imagick::RESOURCETYPE_AREA, 128 * 1024 * 1024);
        $imagick->readImage($source);
        // Auto-orient based on EXIF data before thumbnailing
        $imagick->autoOrient();
        $imagick->thumbnailImage($w, $h, true);
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(self::JPEG_QUALITY);
        $result = $imagick->writeImage($output);
        $imagick->destroy();

        return $result;
    }

    private function generateImageThumbnailPillow(string $source, string $output, int $w, int $h): bool
    {
        $script = <<<'PYTHON'
import sys
from PIL import Image, ImageOps

source, output, width, height = sys.argv[1], sys.argv[2], int(sys.argv[3]), int(sys.argv[4])
img = Image.open(source)
img = ImageOps.exif_transpose(img)
resample = getattr(getattr(Image, "Resampling", Image), "LANCZOS")
img.thumbnail((width, height), resample)
if img.mode not in ("RGB", "L"):
    img = img.convert("RGB")
elif img.mode == "L":
    img = img.convert("RGB")
img.save(output, "JPEG", quality=85, optimize=True)
PYTHON;

        $result = Process::timeout(45)->run(['python3', '-c', $script, $source, $output, (string) $w, (string) $h]);
        if (! $result->successful()) {
            Log::debug('Thumbnail: Pillow image generation failed', [
                'source' => $source,
                'exit_code' => $result->exitCode(),
                'error' => trim($result->errorOutput()),
            ]);

            return false;
        }

        return file_exists($output) && filesize($output) > 0;
    }

    /**
     * Generate thumbnail in isolated subprocess to prevent C-level Imagick crashes
     * from killing the parent batch process (same pattern as phash subprocess isolation).
     */
    private function generateThumbnailInSubprocess(string $source, string $output, int $w, int $h): bool
    {
        $php = PHP_BINARY;
        $script = <<<'PHPSCRIPT'
$source = $argv[1];
$output = $argv[2];
$w = (int)$argv[3];
$h = (int)$argv[4];
try {
    $im = new Imagick();
    $im->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
    $im->setResourceLimit(Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
    $im->setResourceLimit(Imagick::RESOURCETYPE_AREA, 128 * 1024 * 1024);
    $im->readImage($source);
    $im->autoOrient();
    $im->thumbnailImage($w, $h, true);
    $im->setImageFormat('jpeg');
    $im->setImageCompressionQuality(85);
    $im->writeImage($output);
    $im->destroy();
    echo 'OK';
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
PHPSCRIPT;
        $cmd = sprintf(
            '%s -r %s %s %s %d %d 2>/dev/null',
            escapeshellarg($php),
            escapeshellarg($script),
            escapeshellarg($source),
            escapeshellarg($output),
            $w,
            $h
        );

        $result = Process::timeout(30)->run(['php', '-r', $script, $source, $output, (string) $w, (string) $h]);
        $exitCode = $result->exitCode();

        if ($exitCode !== 0) {
            Log::warning('Thumbnail: Subprocess failed for risky image', [
                'source' => $source,
                'exit_code' => $exitCode,
            ]);

            return false;
        }

        return file_exists($output) && filesize($output) > 0;
    }

    /**
     * Generate PDF thumbnail (first page)
     */
    private function generatePdfThumbnail(string $source, string $output, int $w, int $h): bool
    {
        try {
            // Try Imagick first
            if (extension_loaded('imagick')) {
                $imagick = new \Imagick;
                $imagick->setResolution(150, 150);
                $imagick->readImage($source.'[0]');
                $imagick->setImageFormat('jpeg');
                $imagick->thumbnailImage($w, $h, true);
                $imagick->setImageCompressionQuality(self::JPEG_QUALITY);
                $result = $imagick->writeImage($output);
                $imagick->destroy();

                return $result;
            }
        } catch (Exception $e) {
            Log::debug('Thumbnail: Imagick PDF failed, trying pdftoppm', ['error' => $e->getMessage()]);
        }

        // Fallback to pdftoppm CLI
        $tempOut = tempnam(sys_get_temp_dir(), 'pdfthumb_');
        $returnCode = Process::timeout(60)->run([
            'pdftoppm',
            '-jpeg',
            '-f',
            '1',
            '-l',
            '1',
            '-scale-to',
            (string) max($w, $h),
            $source,
            $tempOut,
        ])->exitCode();

        $ppmFile = $tempOut.'-1.jpg';
        if ($returnCode === 0 && file_exists($ppmFile)) {
            rename($ppmFile, $output);
            @unlink($tempOut);

            return true;
        }

        @unlink($tempOut);
        @unlink($ppmFile);

        return false;
    }

    /**
     * Generate video thumbnail (frame at 5 seconds)
     */
    private function generateVideoThumbnail(string $source, string $output, int $w, int $h): bool
    {
        $returnCode = Process::timeout(120)->run([
            'ffmpeg',
            '-y',
            '-ss',
            '5',
            '-i',
            $source,
            '-vframes',
            '1',
            '-vf',
            "scale={$w}:{$h}:force_original_aspect_ratio=decrease",
            '-q:v',
            '2',
            $output,
        ])->exitCode();

        if ($returnCode !== 0 || ! file_exists($output)) {
            // Try at 0 seconds if video is too short
            $returnCode = Process::timeout(120)->run([
                'ffmpeg',
                '-y',
                '-ss',
                '0',
                '-i',
                $source,
                '-vframes',
                '1',
                '-vf',
                "scale={$w}:{$h}:force_original_aspect_ratio=decrease",
                '-q:v',
                '2',
                $output,
            ])->exitCode();
        }

        return $returnCode === 0 && file_exists($output);
    }

    /**
     * Generate Office document thumbnail (first page via LibreOffice)
     * Converts to PDF first, then generates thumbnail from PDF
     */
    private function generateOfficeThumbnail(string $source, string $output, int $w, int $h): bool
    {
        $tempDir = sys_get_temp_dir().'/office_thumb_'.uniqid();
        mkdir($tempDir, 0755, true);

        try {
            // Use LibreOffice to convert to PDF
            $returnCode = Process::timeout(120)->run([
                'soffice',
                '--headless',
                '--convert-to',
                'pdf',
                '--outdir',
                $tempDir,
                $source,
            ])->exitCode();

            if ($returnCode !== 0) {
                Log::warning('Thumbnail: LibreOffice conversion failed', ['code' => $returnCode]);
                $this->cleanupTempDir($tempDir);

                return false;
            }

            // Find the generated PDF
            $pdfFiles = glob($tempDir.'/*.pdf');
            if (empty($pdfFiles)) {
                Log::warning('Thumbnail: No PDF generated by LibreOffice');
                $this->cleanupTempDir($tempDir);

                return false;
            }

            $pdfFile = $pdfFiles[0];

            // Generate thumbnail from the PDF
            $result = $this->generatePdfThumbnail($pdfFile, $output, $w, $h);

            $this->cleanupTempDir($tempDir);

            return $result;

        } catch (Exception $e) {
            Log::warning('Thumbnail: Office thumbnail generation failed', ['error' => $e->getMessage()]);
            $this->cleanupTempDir($tempDir);

            return false;
        }
    }

    /**
     * Generate text file thumbnail (renders first ~20 lines as image)
     */
    private function generateTextThumbnail(string $source, string $output, int $w, int $h, string $filename = ''): bool
    {
        try {
            $lines = [];
            $handle = fopen($source, 'r');
            if (! $handle) {
                return false;
            }

            $lineCount = 0;
            while (($line = fgets($handle)) !== false && $lineCount < 20) {
                $line = str_replace("\t", '    ', $line);
                $line = rtrim($line);
                if (strlen($line) > 80) {
                    $line = substr($line, 0, 77).'...';
                }
                $lines[] = $line;
                $lineCount++;
            }
            fclose($handle);

            if (empty($lines)) {
                $lines = ['(empty file)'];
            }

            return $this->renderTextPreview($output, $w, $h, $filename, $lines);

        } catch (Exception $e) {
            Log::warning('Thumbnail: Text thumbnail generation failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Shared text/list preview renderer used by text, archive, and DBF thumbnails.
     * Colors array keys: header_bg, header_color, line_color, bg_color, label.
     */
    private function renderTextPreview(string $output, int $w, int $h, string $filename, array $lines, array $colors = []): bool
    {
        $lineHeight = 14;
        $padding = 10;
        $headerHeight = 24;

        $imgWidth = max($w, 400);
        $imgHeight = max($h, $headerHeight + $padding * 2 + count($lines) * $lineHeight);

        $img = imagecreatetruecolor($imgWidth, $imgHeight);

        $bg = $colors['bg_color'] ?? [40, 44, 52];
        $hdrBg = $colors['header_bg'] ?? [30, 34, 42];
        $hdrClr = $colors['header_color'] ?? [97, 175, 239];
        $lineClr = $colors['line_color'] ?? [171, 178, 191];

        $bgColor = imagecolorallocate($img, ...$bg);
        $headerBg = imagecolorallocate($img, ...$hdrBg);
        $headerText = imagecolorallocate($img, ...$hdrClr);
        $textColor = imagecolorallocate($img, ...$lineClr);

        imagefilledrectangle($img, 0, 0, $imgWidth, $imgHeight, $bgColor);
        imagefilledrectangle($img, 0, 0, $imgWidth, $headerHeight, $headerBg);

        $displayName = $filename ? basename($filename) : 'text file';
        if (strlen($displayName) > 50) {
            $displayName = '...'.substr($displayName, -47);
        }
        imagestring($img, 3, $padding, 6, $displayName, $headerText);

        // Optional right-side label in header (e.g. "ZIP", "DBF")
        if (! empty($colors['label'])) {
            $lbl = strtoupper((string) $colors['label']);
            $lblX = $imgWidth - (strlen($lbl) * 8) - $padding;
            imagestring($img, 3, $lblX, 6, $lbl, $headerText);
        }

        $y = $headerHeight + $padding;
        foreach ($lines as $line) {
            imagestring($img, 2, $padding, $y, $line, $textColor);
            $y += $lineHeight;
        }

        $thumb = imagecreatetruecolor($w, $h);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $w, $h, $imgWidth, $imgHeight);
        $result = imagejpeg($thumb, $output, self::JPEG_QUALITY);
        imagedestroy($img);
        imagedestroy($thumb);

        return $result;
    }

    /**
     * Generate archive thumbnail showing file listing
     */
    private function generateArchiveThumbnail(string $source, string $output, int $w, int $h, string $filename = ''): bool
    {
        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $files = [];
        $totalCount = 0;
        $archiveLabel = strtoupper($ext);

        try {
            if ($ext === 'iso') {
                if (filesize($source) > 2 * 1024 * 1024 * 1024) {
                    return $this->generateGenericIconThumbnail($output, $w, $h, 'iso', 'ISO image (>2GB)');
                }
                $isoResult = Process::timeout(30)->run(['isoinfo', '-f', '-i', $source]);
                $isoFiles = preg_split('/\r?\n/', trim($isoResult->output())) ?: [];
                $totalCount = count($isoFiles);
                $files = array_slice(array_map('basename', $isoFiles), 0, 8);
            } elseif (in_array($ext, ['7z', 'rar', 'tar', 'tgz', 'gz', 'bz2', 'xz'])) {
                $sevenZResult = Process::timeout(30)->run(['7z', 'l', $source]);
                $sevenZLines = preg_split('/\r?\n/', trim($sevenZResult->output())) ?: [];
                foreach ($sevenZLines as $line) {
                    // 7z list output: date time attr size comp name
                    if (preg_match('/^\d{4}-\d{2}-\d{2}.*\s{2}(.+)$/', $line, $m)) {
                        $entry = trim($m[1]);
                        if ($entry && ! str_ends_with($entry, '/') && ! str_ends_with($entry, '\\')) {
                            $files[] = basename($entry);
                            $totalCount++;
                        }
                    }
                }
                $files = array_slice($files, 0, 8);
            } else {
                // ZIP (and fallback)
                $zip = new \ZipArchive;
                if ($zip->open($source) === true) {
                    $totalCount = $zip->numFiles;
                    for ($i = 0; $i < min($zip->numFiles, 8); $i++) {
                        $stat = $zip->statIndex($i);
                        if ($stat && ! str_ends_with($stat['name'], '/')) {
                            $files[] = basename($stat['name']);
                        }
                    }
                    $zip->close();
                }
            }
        } catch (\Throwable $e) {
            Log::debug('Thumbnail: Archive listing failed', ['source' => $source, 'error' => $e->getMessage()]);
        }

        if (empty($files)) {
            return $this->generateGenericIconThumbnail($output, $w, $h, $ext, "{$archiveLabel} archive");
        }

        $lines = array_map(fn ($f) => '  '.$f, $files);
        if ($totalCount > count($files)) {
            $lines[] = '  +'.($totalCount - count($files)).' more...';
        }

        return $this->renderTextPreview($output, $w, $h, $filename, $lines, [
            'header_bg' => [40, 44, 52],
            'header_color' => [229, 192, 123],
            'line_color' => [171, 178, 191],
            'bg_color' => [30, 33, 39],
            'label' => $archiveLabel,
        ]);
    }

    /**
     * Generate DBF (FoxPro/dBASE) thumbnail showing schema
     */
    private function generateDbfThumbnail(string $source, string $output, int $w, int $h, string $filename = ''): bool
    {
        $fp = @fopen($source, 'rb');
        if (! $fp) {
            return $this->generateGenericIconThumbnail($output, $w, $h, 'dbf', 'DBF database');
        }

        $header = fread($fp, 32);
        if (strlen($header) < 32) {
            fclose($fp);

            return $this->generateGenericIconThumbnail($output, $w, $h, 'dbf', 'DBF database');
        }

        $data = unpack('Cversion/x3/Vrecords/vheader_size/vrecord_size', $header);
        $numRecords = $data['records'];
        $headerSize = $data['header_size'];

        $fields = [];
        while (ftell($fp) < $headerSize - 1 && count($fields) < 8) {
            $fieldDesc = fread($fp, 32);
            if (strlen($fieldDesc) < 32 || $fieldDesc[0] === "\x0D") {
                break;
            }
            $f = unpack('A11name/Atype/x4/Clength', $fieldDesc);
            $name = rtrim($f['name'], "\x00");
            if ($name) {
                $fields[] = $name.':'.$f['type'];
            }
        }
        fclose($fp);

        $lines = array_map(fn ($f) => '  '.$f, $fields);
        $lines[] = '';
        $lines[] = '  '.number_format($numRecords).' records';

        return $this->renderTextPreview($output, $w, $h, $filename, $lines, [
            'header_bg' => [40, 44, 52],
            'header_color' => [224, 108, 117],
            'line_color' => [171, 178, 191],
            'bg_color' => [30, 33, 39],
            'label' => 'DBF',
        ]);
    }

    /**
     * Generate a color-coded generic icon thumbnail for unsupported/binary types
     */
    private function generateGenericIconThumbnail(string $output, int $w, int $h, string $ext, string $label = ''): bool
    {
        $colorMap = [
            'dll' => [224, 108, 117], 'exe' => [224, 108, 117], 'bin' => [224, 108, 117],
            'so' => [224, 108, 117], 'dylib' => [224, 108, 117], 'pyc' => [224, 108, 117],
            'wav' => [198, 120, 221], 'aif' => [198, 120, 221], 'aiff' => [198, 120, 221],
            'gz' => [86, 182, 194],  'tar' => [86, 182, 194],  'xz' => [86, 182, 194],
            'iso' => [86, 182, 194],
        ];
        $color = $colorMap[$ext] ?? [128, 128, 128];

        $img = imagecreatetruecolor($w, $h);
        $bgClr = imagecolorallocate($img, 30, 33, 39);
        $accent = imagecolorallocate($img, ...$color);
        $white = imagecolorallocate($img, 220, 220, 220);

        imagefill($img, 0, 0, $bgClr);

        $bx1 = (int) ($w * 0.15);
        $by1 = (int) ($h * 0.25);
        $bx2 = (int) ($w * 0.85);
        $by2 = (int) ($h * 0.65);
        imagefilledrectangle($img, $bx1, $by1, $bx2, $by2, $accent);

        $extDisplay = strtoupper(substr($ext, 0, 6));
        $cx = (int) (($bx1 + $bx2) / 2) - (int) (strlen($extDisplay) * 4);
        $cy = (int) (($by1 + $by2) / 2) - 6;
        imagestring($img, 4, $cx, $cy, $extDisplay, $white);

        if ($label) {
            $shortLabel = substr($label, 0, 22);
            $lx = max(0, (int) ($w / 2) - (int) (strlen($shortLabel) * 3));
            imagestring($img, 1, $lx, $by2 + 8, $shortLabel, $accent);
        }

        $result = imagejpeg($img, $output, self::JPEG_QUALITY);
        imagedestroy($img);

        return $result;
    }

    /**
     * Check if file appears to be text-based (fallback detection)
     */
    private function isTextFile(string $path): bool
    {
        // Check first 8KB for binary content
        $handle = fopen($path, 'r');
        if (! $handle) {
            return false;
        }

        $sample = fread($handle, 8192);
        fclose($handle);

        if ($sample === false || $sample === '') {
            return false;
        }

        // If contains null bytes, it's binary
        if (strpos($sample, "\0") !== false) {
            return false;
        }

        // Check if mostly printable ASCII
        $printable = 0;
        $total = strlen($sample);
        for ($i = 0; $i < $total; $i++) {
            $ord = ord($sample[$i]);
            if (($ord >= 32 && $ord <= 126) || $ord === 9 || $ord === 10 || $ord === 13) {
                $printable++;
            }
        }

        return ($printable / $total) > 0.85;
    }

    /**
     * Clean up temporary directory
     */
    private function cleanupTempDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = glob($dir.'/*');
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /**
     * Try Nextcloud preview API
     */
    private function tryNextcloudPreview(object $file, int $w, int $h, string $assetUuid, string $size): ?array
    {
        try {
            $previewResult = $this->getNextcloudApi()->getPreviewUrl((int) $file->nextcloud_fileid, $w, $h);
            if (! $previewResult['success'] || empty($previewResult['content'])) {
                return null;
            }

            $outputPath = $this->ensureThumbnailDir($assetUuid, $size);
            file_put_contents($outputPath, $previewResult['content']);

            // Auto-orient Nextcloud preview in case it didn't handle EXIF rotation
            if (class_exists(\Imagick::class)) {
                try {
                    $img = new \Imagick($outputPath);
                    $img->autoOrient();
                    $img->writeImage($outputPath);
                    $img->destroy();
                } catch (\Exception $e) {
                    Log::debug('ThumbnailService: Imagick auto-orient failed, keeping original', ['error' => $e->getMessage()]);
                }
            }

            $this->updateThumbnailRecord($file->id, $assetUuid, $size);

            return [
                'success' => true,
                'path' => $outputPath,
                'mime_type' => 'image/jpeg',
                'from_cache' => false,
                'source' => 'nextcloud',
            ];
        } catch (Exception $e) {
            Log::debug('Thumbnail: Nextcloud preview failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get deterministic thumbnail storage path
     */
    public function getThumbnailPath(string $assetUuid, string $size): string
    {
        $prefix = substr($assetUuid, 0, 2);

        return "thumbnails/{$prefix}/{$assetUuid}.{$size}.jpg";
    }

    /**
     * Ensure thumbnail directory exists and return full output path
     */
    private function ensureThumbnailDir(string $assetUuid, string $size): string
    {
        $relativePath = $this->getThumbnailPath($assetUuid, $size);
        $fullPath = storage_path('app/'.$relativePath);
        $dir = dirname($fullPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $fullPath;
    }

    /**
     * Update file_registry thumbnail tracking columns
     */
    private function updateThumbnailRecord(int $fileId, string $assetUuid, string $size): void
    {
        // Atomic JSON merge — avoids race condition with concurrent thumbnail generation
        DB::update(
            "UPDATE file_registry
             SET thumbnail_generated_at = NOW(),
                 thumbnail_sizes = CASE
                     WHEN thumbnail_sizes IS NULL OR thumbnail_sizes = '[]' OR thumbnail_sizes = ''
                         THEN JSON_ARRAY(?)
                     WHEN JSON_CONTAINS(thumbnail_sizes, JSON_QUOTE(?))
                         THEN thumbnail_sizes
                     ELSE JSON_ARRAY_APPEND(thumbnail_sizes, '$', ?)
                 END,
                 thumbnail_error = NULL
             WHERE id = ?",
            [$size, $size, $size, $fileId]
        );
    }

    /**
     * Mark a thumbnail generation error
     */
    private function markError(int $fileId, string $error): void
    {
        DB::update(
            'UPDATE file_registry SET thumbnail_error = ? WHERE id = ?',
            [substr($error, 0, 255), $fileId]
        );
    }

    /**
     * Delete all thumbnails for a file
     */
    public function deleteThumbnails(string $assetUuid): int
    {
        $deleted = 0;
        foreach (array_keys(self::SIZES) as $size) {
            $path = storage_path('app/'.$this->getThumbnailPath($assetUuid, $size));
            if (file_exists($path)) {
                @unlink($path);
                $deleted++;
            }
        }

        // Clear DB record
        DB::update(
            'UPDATE file_registry SET thumbnail_generated_at = NULL, thumbnail_sizes = NULL, thumbnail_error = NULL WHERE asset_uuid = ?',
            [$assetUuid]
        );

        return $deleted;
    }

    /**
     * Cleanup orphaned thumbnails (files removed from registry)
     */
    public function cleanupOrphaned(): array
    {
        $stats = ['checked' => 0, 'removed' => 0, 'bytes_freed' => 0];

        $thumbDir = storage_path('app/thumbnails');
        if (! is_dir($thumbDir)) {
            return $stats;
        }

        $prefixDirs = glob($thumbDir.'/*', GLOB_ONLYDIR);
        foreach ($prefixDirs as $prefixDir) {
            $files = glob($prefixDir.'/*.jpg');
            foreach ($files as $thumbFile) {
                $stats['checked']++;
                $basename = basename($thumbFile);
                // Extract UUID: {uuid}.{size}.jpg
                $parts = explode('.', $basename);
                if (count($parts) < 3) {
                    continue;
                }
                $uuid = $parts[0];

                $exists = DB::selectOne(
                    "SELECT 1 FROM file_registry WHERE asset_uuid = ? AND status = 'active' LIMIT 1",
                    [$uuid]
                );

                if (! $exists) {
                    $size = filesize($thumbFile);
                    @unlink($thumbFile);
                    $stats['removed']++;
                    $stats['bytes_freed'] += $size;
                }
            }
        }

        return $stats;
    }

    /**
     * Get thumbnail statistics
     */
    public function getStats(): array
    {
        $generated = DB::selectOne(
            'SELECT COUNT(*) as cnt FROM file_registry WHERE thumbnail_generated_at IS NOT NULL'
        );

        $errors = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM file_registry
             WHERE thumbnail_error IS NOT NULL
               AND thumbnail_error != 'unsupported_type'
               AND thumbnail_error != 'generation_failed'
               AND thumbnail_error NOT LIKE 'Unsupported mime type%'"
        );

        $skipped = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM file_registry
             WHERE thumbnail_error IN ('unsupported_type', 'generation_failed')
                OR thumbnail_error LIKE 'Unsupported mime type%'"
        );

        // Build list of all supported MIME types for pending count
        $allSupported = array_merge(
            self::SUPPORTED_IMAGE_TYPES,
            self::SUPPORTED_PDF_TYPES,
            self::SUPPORTED_VIDEO_TYPES,
            self::SUPPORTED_OFFICE_TYPES,
            self::SUPPORTED_TEXT_TYPES
        );
        $placeholders = implode(',', array_fill(0, count($allSupported), '?'));

        $pending = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM file_registry
             WHERE thumbnail_generated_at IS NULL
               AND thumbnail_error IS NULL
               AND status = 'active'
               AND mime_type IN ({$placeholders})",
            $allSupported
        );

        // Calculate disk usage
        $diskUsage = 0;
        $thumbDir = storage_path('app/thumbnails');
        if (is_dir($thumbDir)) {
            $duResult = Process::timeout(10)->run(['du', '-sb', $thumbDir]);
            $duOutput = trim($duResult->output());
            if ($duResult->successful() && $duOutput !== '') {
                $diskUsage = (int) explode("\t", $duOutput)[0];
            }
        }

        return [
            'generated' => (int) ($generated->cnt ?? 0),
            'errors' => (int) ($errors->cnt ?? 0),
            'skipped_unsupported' => (int) ($skipped->cnt ?? 0),
            'pending' => (int) ($pending->cnt ?? 0),
            'disk_usage_bytes' => $diskUsage,
            'disk_usage_human' => $this->formatBytes($diskUsage),
        ];
    }

    /**
     * Check if a MIME type is supported for thumbnail generation.
     * Extension-based routing (EXTENSION_TYPE_MAP) is a separate parallel path.
     */
    public function isSupportedMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_IMAGE_TYPES)
            || in_array($mimeType, self::SUPPORTED_PDF_TYPES)
            || in_array($mimeType, self::SUPPORTED_VIDEO_TYPES)
            || in_array($mimeType, self::SUPPORTED_OFFICE_TYPES)
            || in_array($mimeType, self::SUPPORTED_TEXT_TYPES)
            || in_array($mimeType, self::SUPPORTED_ARCHIVE_TYPES);
    }

    /**
     * Check if a file extension has extension-based thumbnail routing
     */
    public function isSupportedExtension(string $ext): bool
    {
        return isset(self::EXTENSION_TYPE_MAP[strtolower($ext)]);
    }

    /**
     * All extensions covered by EXTENSION_TYPE_MAP (for reprocess/clear queries)
     */
    public static function getSupportedExtensions(): array
    {
        return array_keys(self::EXTENSION_TYPE_MAP);
    }

    /**
     * All MIME types covered by any SUPPORTED_*_TYPES constant
     */
    public static function getAllSupportedMimeTypes(): array
    {
        return array_merge(
            self::SUPPORTED_IMAGE_TYPES,
            self::SUPPORTED_PDF_TYPES,
            self::SUPPORTED_VIDEO_TYPES,
            self::SUPPORTED_OFFICE_TYPES,
            self::SUPPORTED_TEXT_TYPES,
            self::SUPPORTED_ARCHIVE_TYPES,
        );
    }

    /**
     * Get MIME types for a filter string
     */
    private function getMimeTypesForFilter(string $type): array
    {
        return match ($type) {
            'image' => self::SUPPORTED_IMAGE_TYPES,
            'pdf' => self::SUPPORTED_PDF_TYPES,
            'video' => self::SUPPORTED_VIDEO_TYPES,
            'office', 'document' => array_merge(self::SUPPORTED_PDF_TYPES, self::SUPPORTED_OFFICE_TYPES),
            'text', 'code' => self::SUPPORTED_TEXT_TYPES,
            default => array_merge(self::SUPPORTED_IMAGE_TYPES, self::SUPPORTED_PDF_TYPES, self::SUPPORTED_VIDEO_TYPES, self::SUPPORTED_OFFICE_TYPES, self::SUPPORTED_TEXT_TYPES),
        };
    }

    /**
     * Check if LibreOffice is available for Office document thumbnails
     */
    public function isLibreOfficeAvailable(): bool
    {
        $result = Process::timeout(5)->run(['which', 'soffice']);
        $path = trim($result->output());

        return $result->successful() && $path !== '' && file_exists($path) && is_executable($path);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2).' '.$units[$i];
    }
}
