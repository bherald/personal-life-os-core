<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class NaraCatalogMediaCaptureService
{
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff',
        'pdf', 'html', 'htm', 'txt',
        'mp3', 'wav', 'ogg', 'flac', 'm4a',
        'mp4', 'm4v', 'mov', 'webm',
    ];

    public function __construct(private readonly GenealogySourceService $sourceService) {}

    /**
     * @param  list<int>  $mediaIds
     * @return array<string, mixed>
     */
    public function collect(
        int $treeId,
        int $limit = 25,
        array $mediaIds = [],
        bool $executeCapture = false,
        bool $downloadConfirmed = false,
        bool $storageConfirmed = false,
        bool $metadataSnapshot = false,
        bool $compact = false,
        ?int $maxBytes = null
    ): array {
        $limit = max(1, min(250, $limit));
        $maxBytes = max(1, $maxBytes ?? (int) config('genealogy.evidence_asset_capture.max_bytes', 26214400));
        $targetRoot = $this->targetRoot();

        $payload = [
            'schema' => 'genealogy_nara_catalog_media_capture.v1',
            'status' => 'ok',
            'tree_id' => $treeId,
            'limit' => $limit,
            'execute_capture' => $executeCapture,
            'metadata_snapshot' => $metadataSnapshot,
            'target_root' => $targetRoot,
            'max_bytes' => $maxBytes,
            'summary' => [
                'placeholders_seen' => 0,
                'planned' => 0,
                'downloaded' => 0,
                'metadata_snapshots' => 0,
                'media_rows_updated' => 0,
                'file_registry_rows' => 0,
                'blocked' => 0,
                'failed' => 0,
                'no_downloadable_object' => 0,
            ],
            'items' => [],
        ];

        if (! Schema::hasTable('genealogy_media')) {
            $payload['status'] = 'blocked';
            $payload['blockers'] = ['genealogy_media_table_missing'];

            return $payload;
        }

        if ($executeCapture && (! $downloadConfirmed || ! $storageConfirmed)) {
            $payload['status'] = 'blocked';
            $payload['blockers'] = array_values(array_filter([
                ! $downloadConfirmed ? 'confirm_download_required' : null,
                ! $storageConfirmed ? 'confirm_storage_write_required' : null,
            ]));

            return $payload;
        }

        $rows = $this->placeholderRows($treeId, $limit, $mediaIds);
        $payload['summary']['placeholders_seen'] = count($rows);

        foreach ($rows as $row) {
            $item = $this->processPlaceholder(
                media: $row,
                executeCapture: $executeCapture,
                metadataSnapshot: $metadataSnapshot,
                targetRoot: $targetRoot,
                maxBytes: $maxBytes
            );

            $this->mergeSummary($payload['summary'], $item);
            $payload['items'][] = $compact ? $this->compactItem($item) : $item;
        }

        if ($payload['summary']['failed'] > 0 || $payload['summary']['blocked'] > 0) {
            $hasProgress = $payload['summary']['planned'] > 0
                || $payload['summary']['downloaded'] > 0
                || $payload['summary']['metadata_snapshots'] > 0;
            $payload['status'] = $hasProgress ? 'partial' : 'blocked';
        } elseif (! $executeCapture) {
            $payload['status'] = 'dry_run';
        }

        return $payload;
    }

    /**
     * @return list<object>
     */
    private function placeholderRows(int $treeId, int $limit, array $mediaIds): array
    {
        $query = DB::table('genealogy_media')
            ->select([
                'id',
                'tree_id',
                'original_path',
                'nextcloud_path',
                'local_filename',
                'title',
                'description',
                'media_type',
                'file_exists',
            ])
            ->where('tree_id', $treeId)
            ->whereNotNull('original_path')
            ->where('original_path', 'like', '%catalog.archives.gov/id/%');

        if ($mediaIds !== []) {
            $query->whereIn('id', array_values(array_unique(array_map('intval', $mediaIds))));
        } else {
            $query->where(function ($where): void {
                $where->whereNull('nextcloud_path')
                    ->orWhere('nextcloud_path', '')
                    ->orWhere('nextcloud_path', 'like', 'http%')
                    ->orWhere('file_exists', 0);
            });
        }

        return $query->orderBy('id')->limit($limit)->get()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function processPlaceholder(
        object $media,
        bool $executeCapture,
        bool $metadataSnapshot,
        string $targetRoot,
        int $maxBytes
    ): array {
        $catalogUrl = (string) ($media->original_path ?? '');
        $naId = $this->extractNaId($catalogUrl);
        $item = [
            'media_id' => (int) $media->id,
            'status' => 'pending',
            'na_id' => $naId,
            'catalog_url' => $catalogUrl,
            'action' => null,
            'title' => $media->title ?? null,
            'target_path' => null,
            'download_url' => null,
            'downloaded' => false,
            'metadata_snapshot' => false,
            'media_updated' => false,
            'file_registry_asset_uuid' => null,
            'additional_objects_available' => 0,
            'blockers' => [],
        ];

        if ($naId === null) {
            $item['status'] = 'blocked';
            $item['blockers'][] = 'nara_naid_not_found_in_url';

            return $item;
        }

        $recordResult = $this->sourceService->getNaraRecord($naId);
        if (! ($recordResult['success'] ?? false)) {
            $item['status'] = 'failed';
            $item['blockers'][] = 'nara_record_lookup_failed';
            $item['error'] = $recordResult['error'] ?? 'NARA record lookup failed';

            return $item;
        }

        $record = is_array($recordResult['record'] ?? null) ? $recordResult['record'] : [];
        $title = $this->recordTitle($record, $media);
        $item['title'] = $title;
        $objects = is_array($recordResult['objects'] ?? null) ? $recordResult['objects'] : [];
        $downloadable = array_values(array_filter(
            $objects,
            fn (array $object): bool => $this->allowedDownloadUrl((string) ($object['url'] ?? ''))
        ));
        $best = $this->selectBestObject($downloadable);
        $item['additional_objects_available'] = max(0, count($downloadable) - ($best ? 1 : 0));

        if ($best !== null) {
            $extension = $this->extensionFromObject($best);
            $targetPath = $this->targetPath($targetRoot, (int) $media->tree_id, (int) $media->id, $naId, $title, $extension);
            $item['status'] = $executeCapture ? 'ready' : 'planned';
            $item['action'] = 'download_object';
            $item['target_path'] = $targetPath;
            $item['download_url'] = $best['url'] ?? null;
            $item['object'] = [
                'object_id' => $best['object_id'] ?? null,
                'filename' => $best['filename'] ?? null,
                'format' => $best['format'] ?? null,
                'size' => $best['size'] ?? null,
            ];

            if (! $executeCapture) {
                return $item;
            }

            return $this->captureObject($media, $record, $best, $targetPath, $naId, $catalogUrl, $maxBytes, $item);
        }

        $item['action'] = $metadataSnapshot ? 'metadata_snapshot' : 'none';
        $item['status'] = $metadataSnapshot ? ($executeCapture ? 'ready' : 'planned') : 'no_downloadable_object';
        $item['target_path'] = $metadataSnapshot
            ? $this->targetPath($targetRoot, (int) $media->tree_id, (int) $media->id, $naId, $title, 'html')
            : null;
        $item['blockers'][] = 'no_downloadable_nara_digital_object';

        if (! $metadataSnapshot || ! $executeCapture) {
            return $item;
        }

        return $this->captureMetadataSnapshot($media, $record, $item['target_path'], $naId, $catalogUrl, $item);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function captureObject(
        object $media,
        array $record,
        array $object,
        string $targetPath,
        string $naId,
        string $catalogUrl,
        int $maxBytes,
        array $item
    ): array {
        $url = (string) ($object['url'] ?? '');
        if (! $this->allowedDownloadUrl($url)) {
            $item['status'] = 'blocked';
            $item['blockers'][] = 'download_url_not_allowed';

            return $item;
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'image/*,application/pdf,audio/*,video/*,text/plain,text/html;q=0.5,*/*;q=0.1',
                'User-Agent' => 'PLOS-NaraCatalogMediaCapture/1.0',
            ])->connectTimeout(10)->timeout(120)->get($url);

            if (! $response->successful()) {
                $item['status'] = 'failed';
                $item['blockers'][] = 'download_http_'.$response->status();

                return $item;
            }

            $body = (string) $response->body();
            $bytes = strlen($body);
            if ($bytes <= 0) {
                $item['status'] = 'failed';
                $item['blockers'][] = 'empty_download_body';

                return $item;
            }

            if ($bytes > $maxBytes) {
                $item['status'] = 'blocked';
                $item['blockers'][] = 'download_exceeds_max_bytes';
                $item['bytes'] = $bytes;

                return $item;
            }

            $mime = $this->normalizeContentType($response->header('Content-Type'));
            if ($this->looksLikeCatalogShell($body, $mime, $targetPath)) {
                $item['status'] = 'blocked';
                $item['blockers'][] = 'catalog_shell_html_rejected';

                return $item;
            }

            File::ensureDirectoryExists(dirname($targetPath));
            File::put($targetPath, $body);

            $extension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
            $mime = $mime ?: $this->mimeForExtension($extension);
            $mediaType = $this->mediaType($record, $extension);
            $assetUuid = $this->registerFileRegistry($targetPath, $mime, $catalogUrl, $naId, $this->recordTitle($record, $media));
            $this->updateMediaRow(
                media: $media,
                path: $targetPath,
                mime: $mime,
                extension: $extension,
                bytes: $bytes,
                title: $this->recordTitle($record, $media),
                description: $this->descriptionForObject($media, $record, $object, $naId, $catalogUrl),
                mediaType: $mediaType,
                transcriptionText: null
            );

            $item['status'] = 'captured';
            $item['downloaded'] = true;
            $item['media_updated'] = true;
            $item['bytes'] = $bytes;
            $item['mime_type'] = $mime;
            $item['file_registry_asset_uuid'] = $assetUuid;

            return $item;
        } catch (Throwable $exception) {
            Log::warning('NARA catalog media capture failed', [
                'media_id' => (int) $media->id,
                'na_id' => $naId,
                'error' => $exception->getMessage(),
            ]);

            $item['status'] = 'failed';
            $item['blockers'][] = 'capture_exception';
            $item['error'] = $exception->getMessage();

            return $item;
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function captureMetadataSnapshot(
        object $media,
        array $record,
        string $targetPath,
        string $naId,
        string $catalogUrl,
        array $item
    ): array {
        try {
            $html = $this->metadataHtml($record, $naId, $catalogUrl);
            File::ensureDirectoryExists(dirname($targetPath));
            File::put($targetPath, $html);

            $bytes = strlen($html);
            $assetUuid = $this->registerFileRegistry($targetPath, 'text/html', $catalogUrl, $naId, $this->recordTitle($record, $media));
            $this->updateMediaRow(
                media: $media,
                path: $targetPath,
                mime: 'text/html',
                extension: 'html',
                bytes: $bytes,
                title: $this->recordTitle($record, $media),
                description: $this->descriptionForSnapshot($media, $record, $naId, $catalogUrl),
                mediaType: 'document',
                transcriptionText: $this->metadataText($record, $naId, $catalogUrl)
            );

            $item['status'] = 'captured';
            $item['metadata_snapshot'] = true;
            $item['media_updated'] = true;
            $item['bytes'] = $bytes;
            $item['mime_type'] = 'text/html';
            $item['file_registry_asset_uuid'] = $assetUuid;

            return $item;
        } catch (Throwable $exception) {
            $item['status'] = 'failed';
            $item['blockers'][] = 'metadata_snapshot_exception';
            $item['error'] = $exception->getMessage();

            return $item;
        }
    }

    private function extractNaId(string $url): ?string
    {
        if (preg_match('~catalog\.archives\.gov/id/(\d+)~i', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $objects
     * @return array<string, mixed>|null
     */
    private function selectBestObject(array $objects): ?array
    {
        $best = null;
        $bestScore = -1;

        foreach ($objects as $object) {
            $url = (string) ($object['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $extension = $this->extensionFromObject($object);
            $format = strtolower((string) ($object['format'] ?? ''));
            $score = match ($extension) {
                'pdf' => 100,
                'tif', 'tiff' => 90,
                'jpg', 'jpeg' => 80,
                'png' => 70,
                'txt', 'html', 'htm' => 60,
                'mp3', 'wav', 'm4a', 'flac', 'ogg' => 50,
                'mp4', 'm4v', 'mov', 'webm' => 50,
                default => 20,
            };

            if (str_contains($format, 'pdf')) {
                $score += 10;
            }
            if (is_int($object['size'] ?? null) && (int) $object['size'] > 0) {
                $score += 1;
            }

            if ($score > $bestScore) {
                $best = $object;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function extensionFromObject(array $object): string
    {
        $filename = (string) ($object['filename'] ?? '');
        $url = (string) ($object['url'] ?? '');
        $format = strtolower((string) ($object['format'] ?? ''));

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === '' && $url !== '') {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        }

        if ($extension === '') {
            $extension = match (true) {
                str_contains($format, 'pdf') => 'pdf',
                str_contains($format, 'tiff') || str_contains($format, 'tif') => 'tiff',
                str_contains($format, 'jpeg') || str_contains($format, 'jpg') => 'jpg',
                str_contains($format, 'png') => 'png',
                str_contains($format, 'mp3') => 'mp3',
                str_contains($format, 'wav') => 'wav',
                str_contains($format, 'html') => 'html',
                str_contains($format, 'text') => 'txt',
                default => 'bin',
            };
        }

        return in_array($extension, self::ALLOWED_EXTENSIONS, true) ? $extension : 'bin';
    }

    private function allowedDownloadUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if ($host === 'catalog.archives.gov' || str_ends_with($host, '.archives.gov')) {
            return true;
        }

        return str_contains($host, 'amazonaws.com') && str_contains($path, 'NARAprodstorage');
    }

    private function targetRoot(): string
    {
        return rtrim((string) config('genealogy.ft_reference_root', storage_path('app/genealogy/ft-reference')), '/');
    }

    private function targetPath(string $root, int $treeId, int $mediaId, string $naId, string $title, string $extension): string
    {
        $treeName = DB::table('genealogy_trees')->where('id', $treeId)->value('name');
        $treeSlug = Str::slug((string) ($treeName ?: 'tree-'.$treeId));
        if ($treeSlug === '') {
            $treeSlug = 'tree-'.$treeId;
        }

        $titleSlug = Str::slug($title);
        if ($titleSlug === '') {
            $titleSlug = 'nara-record';
        }
        $titleSlug = substr($titleSlug, 0, 80);

        $extension = in_array($extension, self::ALLOWED_EXTENSIONS, true) ? $extension : 'bin';

        $rootTreeSlug = Str::slug(basename($root));
        $treeRoot = $rootTreeSlug === $treeSlug
            ? $root
            : $root.'/'.$treeSlug;

        return $treeRoot.'/documents/nara-catalog/nara-'.$naId.'-m'.$mediaId.'-'.$titleSlug.'.'.$extension;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordTitle(array $record, object $media): string
    {
        $title = trim((string) ($record['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        $fallback = trim((string) ($media->title ?? ''));

        return $fallback !== '' ? $fallback : 'NARA Catalog Record';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function mediaType(array $record, string $extension): string
    {
        $haystack = strtolower(json_encode($record) ?: '');
        if (preg_match('/census|population schedule|enumerat/', $haystack) === 1) {
            return 'census';
        }
        if (preg_match('/military|pension|war|service record|veteran|draft/', $haystack) === 1) {
            return 'military';
        }
        if (preg_match('/birth|death|marriage|certificate|license/', $haystack) === 1) {
            return 'certificate';
        }
        if (in_array($extension, ['mp3', 'wav', 'ogg', 'flac', 'm4a'], true)) {
            return 'audio';
        }
        if (in_array($extension, ['mp4', 'm4v', 'mov', 'webm'], true)) {
            return 'video';
        }

        return 'document';
    }

    private function normalizeContentType(?string $contentType): ?string
    {
        if (! is_string($contentType) || trim($contentType) === '') {
            return null;
        }

        return strtolower(trim(strtok($contentType, ';') ?: $contentType));
    }

    private function mimeForExtension(string $extension): string
    {
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'tif', 'tiff' => 'image/tiff',
            'pdf' => 'application/pdf',
            'html', 'htm' => 'text/html',
            'txt' => 'text/plain',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            'mp4', 'm4v' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            default => 'application/octet-stream',
        };
    }

    private function looksLikeCatalogShell(string $body, ?string $mime, string $targetPath): bool
    {
        $extension = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
        if ($mime !== 'text/html' && ! in_array($extension, ['html', 'htm'], true)) {
            return false;
        }

        $head = strtolower(substr($body, 0, 2000));

        return str_contains($head, '<app-root')
            || (str_contains($head, 'national archives catalog') && str_contains($head, 'main.'));
    }

    private function updateMediaRow(
        object $media,
        string $path,
        string $mime,
        string $extension,
        int $bytes,
        string $title,
        string $description,
        string $mediaType,
        ?string $transcriptionText
    ): void {
        $updates = [
            'nextcloud_path' => $path,
            'local_filename' => basename($path),
            'file_format' => substr($extension, 0, 20),
            'mime_type' => substr($mime, 0, 100),
            'file_size' => $bytes,
            'title' => substr($title, 0, 500),
            'description' => $description,
            'analysis_status' => 'pending',
            'enrichment_status' => 'pending',
            'source_folder' => substr(dirname($path), 0, 500),
            'media_type' => $mediaType,
            'file_exists' => 1,
            'imported_at' => now(),
            'updated_at' => now(),
        ];

        if ($transcriptionText !== null && Schema::hasColumn('genealogy_media', 'transcription_text')) {
            $updates['transcription_text'] = $transcriptionText;
        }

        DB::table('genealogy_media')->where('id', (int) $media->id)->update($updates);
    }

    private function registerFileRegistry(string $path, string $mime, string $catalogUrl, string $naId, string $title): ?string
    {
        if (! Schema::hasTable('file_registry')
            || ! Schema::hasColumn('file_registry', 'current_path')
            || ! Schema::hasColumn('file_registry', 'path_hash')
            || ! Schema::hasColumn('file_registry', 'asset_uuid')) {
            return null;
        }

        try {
            $pathHash = hash('sha256', $path);
            $existing = DB::table('file_registry')->select(['asset_uuid'])->where('path_hash', $pathHash)->first();
            if ($existing && is_string($existing->asset_uuid ?? null)) {
                return $existing->asset_uuid;
            }

            $assetUuid = (string) Str::uuid();
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $contentHash = is_file($path) ? hash_file('sha256', $path) : null;
            $fileSize = is_file($path) ? filesize($path) : 0;

            DB::table('file_registry')->insert([
                'asset_uuid' => $assetUuid,
                'current_path' => $path,
                'path_hash' => $pathHash,
                'original_path' => $catalogUrl,
                'original_source' => 'other',
                'filename' => basename($path),
                'extension' => $extension ?: null,
                'mime_type' => $mime,
                'file_size' => $fileSize ?: 0,
                'content_hash' => $contentHash ?: null,
                'content_hash_verified_at' => $contentHash ? now() : null,
                'status' => 'active',
                'last_verified_at' => now(),
                'title' => substr($title, 0, 500),
                'category' => 'genealogy',
                'tags' => json_encode(['source:nara', 'nara_id:'.$naId], JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $assetUuid;
        } catch (Throwable $exception) {
            Log::warning('NARA catalog media capture file_registry registration failed', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $object
     */
    private function descriptionForObject(object $media, array $record, array $object, string $naId, string $catalogUrl): string
    {
        $lines = [
            'NARA Catalog capture for NAID '.$naId.'.',
            'Catalog URL: '.$catalogUrl,
        ];

        if (! empty($object['object_id'])) {
            $lines[] = 'Digital object ID: '.$object['object_id'];
        }
        if (! empty($object['filename'])) {
            $lines[] = 'Digital object filename: '.$object['filename'];
        }
        if (! empty($object['format'])) {
            $lines[] = 'Digital object format: '.$object['format'];
        }
        if (! empty($record['scopeAndContentNote'])) {
            $lines[] = 'NARA scope/content note: '.$this->plainText($record['scopeAndContentNote']);
        }
        if (! empty($media->description)) {
            $lines[] = 'Previous media note: '.trim((string) $media->description);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function descriptionForSnapshot(object $media, array $record, string $naId, string $catalogUrl): string
    {
        $lines = [
            'NARA Catalog metadata snapshot for NAID '.$naId.'. No downloadable digital object was available through record.digitalObjects at capture time.',
            'Catalog URL: '.$catalogUrl,
        ];

        if (! empty($record['scopeAndContentNote'])) {
            $lines[] = 'NARA scope/content note: '.$this->plainText($record['scopeAndContentNote']);
        }
        if (! empty($media->description)) {
            $lines[] = 'Previous media note: '.trim((string) $media->description);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function metadataHtml(array $record, string $naId, string $catalogUrl): string
    {
        $title = $this->escape((string) ($record['title'] ?? 'NARA Catalog Record'));
        $fields = [
            'NAID' => $naId,
            'Catalog URL' => $catalogUrl,
            'Level of Description' => $record['levelOfDescription'] ?? null,
            'Date' => $this->dateRange($record),
            'Record Group / Series' => $this->seriesTitle($record),
            'Scope and Content' => $record['scopeAndContentNote'] ?? null,
            'Access Restriction' => $record['accessRestriction']['status'] ?? null,
            'Use Restriction' => $record['useRestriction']['status'] ?? null,
        ];

        $rows = '';
        foreach ($fields as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $rows .= '<dt>'.$this->escape($label).'</dt><dd>'.$this->escape($this->plainText($value)).'</dd>'."\n";
        }

        return '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>'.$title.'</title>
</head>
<body>
<h1>'.$title.'</h1>
<p>This product uses the National Archives Catalog API but is not endorsed or certified by NARA.</p>
<dl>
'.$rows.'</dl>
</body>
</html>
';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function metadataText(array $record, string $naId, string $catalogUrl): string
    {
        $parts = [
            'NARA Catalog metadata snapshot',
            'Title: '.(string) ($record['title'] ?? 'NARA Catalog Record'),
            'NAID: '.$naId,
            'Catalog URL: '.$catalogUrl,
        ];

        $date = $this->dateRange($record);
        if ($date !== null) {
            $parts[] = 'Date: '.$date;
        }
        $series = $this->seriesTitle($record);
        if ($series !== null) {
            $parts[] = 'Record group or series: '.$series;
        }
        if (! empty($record['scopeAndContentNote'])) {
            $parts[] = 'Scope and content: '.$this->plainText($record['scopeAndContentNote']);
        }

        return implode("\n", $parts);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function dateRange(array $record): ?string
    {
        $start = $record['inclusiveStartDate']['logicalDate']
            ?? $record['inclusiveStartDate']['year']
            ?? null;
        $end = $record['inclusiveEndDate']['logicalDate']
            ?? $record['inclusiveEndDate']['year']
            ?? null;

        if ($start === null && $end === null) {
            return null;
        }

        if ($end === null || (string) $end === (string) $start) {
            return (string) $start;
        }

        return (string) $start.'-'.$end;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function seriesTitle(array $record): ?string
    {
        foreach ($record['ancestors'] ?? [] as $ancestor) {
            if (is_array($ancestor) && ($ancestor['levelOfDescription'] ?? '') === 'series') {
                return isset($ancestor['title']) ? (string) $ancestor['title'] : null;
            }
        }

        return null;
    }

    private function plainText(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode('; ', array_filter(array_map(
                fn (mixed $item): ?string => is_scalar($item) ? (string) $item : null,
                $value
            )));
        }

        return trim(strip_tags((string) $value));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $item
     */
    private function mergeSummary(array &$summary, array $item): void
    {
        if (($item['status'] ?? null) === 'planned') {
            $summary['planned']++;
        }
        if (($item['downloaded'] ?? false) === true) {
            $summary['downloaded']++;
        }
        if (($item['metadata_snapshot'] ?? false) === true) {
            $summary['metadata_snapshots']++;
        }
        if (($item['media_updated'] ?? false) === true) {
            $summary['media_rows_updated']++;
        }
        if (! empty($item['file_registry_asset_uuid'])) {
            $summary['file_registry_rows']++;
        }
        if (in_array(($item['status'] ?? null), ['blocked', 'no_downloadable_object'], true)) {
            $summary['blocked']++;
        }
        if (($item['status'] ?? null) === 'failed') {
            $summary['failed']++;
        }
        if (in_array('no_downloadable_nara_digital_object', $item['blockers'] ?? [], true)) {
            $summary['no_downloadable_object']++;
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function compactItem(array $item): array
    {
        return [
            'media_id' => $item['media_id'] ?? null,
            'status' => $item['status'] ?? null,
            'action' => $item['action'] ?? null,
            'na_id' => $item['na_id'] ?? null,
            'target_path' => $item['target_path'] ?? null,
            'blockers' => $item['blockers'] ?? [],
        ];
    }
}
