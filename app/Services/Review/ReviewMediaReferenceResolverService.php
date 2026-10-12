<?php

namespace App\Services\Review;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class ReviewMediaReferenceResolverService
{
    /**
     * @var array<int, array<string, mixed>|null>
     */
    private array $resolvedMedia = [];

    public function __construct(private readonly ?ConnectionInterface $connection = null) {}

    /**
     * @var array<string, true>
     */
    private const STRUCTURED_MEDIA_ID_KEYS = [
        'genealogy_media_id' => true,
        'media_id' => true,
        'source_media_id' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const STRUCTURED_MEDIA_IDS_KEYS = [
        'media_ids' => true,
        'source_media_ids' => true,
    ];

    /**
     * Resolve every media reference needed by a packet page in two bounded
     * queries so per-packet decoration can use the in-request cache.
     *
     * @param  array<int, array<string, mixed>>  $detailsList
     */
    public function warmDetails(array $detailsList, int $limitPerDetails = 25): void
    {
        $ids = [];
        $limitPerDetails = max(1, min($limitPerDetails, 100));

        foreach ($detailsList as $details) {
            if (! is_array($details)) {
                continue;
            }
            foreach (array_slice($this->mediaReferenceIds($details), 0, $limitPerDetails) as $id) {
                $ids[$id] = $id;
            }
        }

        $this->resolveMissingIds(array_values($ids));
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<int, array<string, mixed>>
     */
    public function resolveFromDetails(array $details, int $limit = 25): array
    {
        $ids = $this->mediaReferenceIds($details);
        if ($ids === []) {
            return [];
        }

        $ids = array_slice($ids, 0, max(1, min($limit, 100)));
        $this->resolveMissingIds($ids);

        $out = [];
        foreach ($ids as $id) {
            if (is_array($this->resolvedMedia[$id] ?? null)) {
                $out[] = $this->resolvedMedia[$id];
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     */
    private function resolveMissingIds(array $ids): void
    {
        $missing = array_values(array_filter(
            array_unique($ids),
            fn (int $id): bool => $id > 0 && ! array_key_exists($id, $this->resolvedMedia)
        ));
        if ($missing === []) {
            return;
        }

        foreach ($missing as $id) {
            $this->resolvedMedia[$id] = null;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($missing), '?'));
            $rows = $this->database()->select(
                "SELECT id, tree_id, title, local_filename, file_format, mime_type,
                        media_type, nextcloud_path, original_path, file_exists
                 FROM genealogy_media
                 WHERE id IN ({$placeholders})",
                $missing
            );
        } catch (\Throwable) {
            return;
        }

        $paths = [];
        foreach ($rows as $row) {
            foreach ([$row->nextcloud_path ?? null, $row->original_path ?? null] as $path) {
                $path = $this->firstNonEmptyString($path);
                if ($path !== null) {
                    $paths[$path] = $path;
                }
            }
        }
        $registryByPath = $this->registryRowsByPath(array_values($paths));

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $path = $this->firstNonEmptyString($row->nextcloud_path ?? null, $row->original_path ?? null);
            $registry = $this->firstRegistryMatch($row, $registryByPath);
            $assetUuid = $this->firstNonEmptyString($registry['asset_uuid'] ?? null);
            $mimeType = $this->firstNonEmptyString($registry['mime_type'] ?? null, $row->mime_type ?? null);
            $fileFlag = (bool) $row->file_exists;
            $openable = $path !== null && ($fileFlag || $assetUuid !== null);

            $this->resolvedMedia[$id] = [
                'id' => $id,
                'title' => $this->displayTitle($row),
                'file_format' => $this->firstNonEmptyString($row->file_format ?? null),
                'mime_type' => $mimeType,
                'media_type' => $this->firstNonEmptyString($row->media_type ?? null),
                'nextcloud_path' => $path,
                'file_exists' => $fileFlag,
                'file_available' => $openable,
                'asset_uuid' => $assetUuid,
                'view_url' => $openable && $path !== null
                    ? '/api/media/file?path='.rawurlencode($path)
                    : null,
                'thumbnail_url' => $this->thumbnailUrl($id, (int) $row->tree_id, $assetUuid, $path, $openable),
            ];
        }
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, array{asset_uuid: mixed, mime_type: mixed}>
     */
    private function registryRowsByPath(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($paths), '?'));
            $rows = $this->database()->select(
                "SELECT asset_uuid, mime_type, current_path, original_path
                 FROM file_registry
                 WHERE status = ?
                   AND (current_path IN ({$placeholders}) OR original_path IN ({$placeholders}))",
                ['active', ...$paths, ...$paths]
            );
        } catch (\Throwable) {
            return [];
        }

        $byPath = [];
        foreach ($rows as $row) {
            $entry = [
                'asset_uuid' => $row->asset_uuid ?? null,
                'mime_type' => $row->mime_type ?? null,
            ];
            foreach ([$row->current_path ?? null, $row->original_path ?? null] as $path) {
                $path = $this->firstNonEmptyString($path);
                if ($path !== null && ! isset($byPath[$path])) {
                    $byPath[$path] = $entry;
                }
            }
        }

        return $byPath;
    }

    /**
     * @param  array<string, array{asset_uuid: mixed, mime_type: mixed}>  $registryByPath
     * @return array{asset_uuid: mixed, mime_type: mixed}|null
     */
    private function firstRegistryMatch(object $media, array $registryByPath): ?array
    {
        foreach ([$media->nextcloud_path ?? null, $media->original_path ?? null] as $path) {
            $path = $this->firstNonEmptyString($path);
            if ($path !== null && isset($registryByPath[$path])) {
                return $registryByPath[$path];
            }
        }

        return null;
    }

    private function database(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<int>
     */
    public function mediaReferenceIds(array $details): array
    {
        $ids = [];
        $this->collectStructuredMediaReferenceIds($details, $ids);

        $haystackText = implode(' ', $this->mediaReferenceTextCandidates($details));
        if ($haystackText !== ''
            && preg_match_all('/\b(?:media\s*#?|media_id|genealogy_media_id)\s*[:=#]?\s*(\d{1,9})\b/i', $haystackText, $matches)
        ) {
            $this->appendMediaReferenceIds($ids, $matches[1]);
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function collectStructuredMediaReferenceIds(mixed $value, array &$ids, int $depth = 0): void
    {
        if ($depth > 6 || ! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $normalizedKey = strtolower((string) $key);
            if (isset(self::STRUCTURED_MEDIA_ID_KEYS[$normalizedKey])) {
                $this->appendMediaReferenceId($ids, $child);

                continue;
            }

            if (isset(self::STRUCTURED_MEDIA_IDS_KEYS[$normalizedKey])) {
                $this->appendMediaReferenceIds($ids, $child);

                continue;
            }

            if (is_array($child)) {
                $this->collectStructuredMediaReferenceIds($child, $ids, $depth + 1);
            }
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function appendMediaReferenceIds(array &$ids, mixed $values): void
    {
        if (is_array($values)) {
            foreach ($values as $value) {
                $this->appendMediaReferenceIds($ids, $value);
            }

            return;
        }

        $this->appendMediaReferenceId($ids, $values);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function appendMediaReferenceId(array &$ids, mixed $value): void
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && ctype_digit(trim($value))) {
            $id = (int) trim($value);
        } else {
            return;
        }

        if ($id <= 0 || $id > 999_999_999 || in_array($id, $ids, true)) {
            return;
        }

        $ids[] = $id;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<string>
     */
    private function mediaReferenceTextCandidates(array $details): array
    {
        $haystack = [];

        foreach (['summary', 'source_locator', 'packet_label', 'packet_key'] as $key) {
            $this->appendMediaTextCandidate($haystack, $details[$key] ?? null);
        }

        $packet = $details['packet'] ?? null;
        if (is_array($packet)) {
            foreach (['summary', 'description', 'packet_label', 'packet_key'] as $key) {
                $this->appendMediaTextCandidate($haystack, $packet[$key] ?? null);
            }
        }

        foreach (['proposals', 'claims'] as $collectionKey) {
            $items = $details[$collectionKey] ?? null;
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach ([
                    'evidence_summary',
                    'proposed_value',
                    'summary',
                    'claim',
                    'claim_text',
                    'statement',
                    'extracted_claim',
                    'extracted_text',
                    'text',
                    'source_ref',
                    'source_locator',
                ] as $key) {
                    $this->appendMediaTextCandidate($haystack, $item[$key] ?? null);
                }
                $this->appendMediaTextLeaves($haystack, $item['raw'] ?? null);
                $this->appendMediaTextLeaves($haystack, $item['evidence_sources'] ?? null);
            }
        }

        return $haystack;
    }

    /**
     * @param  array<int, string>  $haystack
     */
    private function appendMediaTextCandidate(array &$haystack, mixed $value): void
    {
        if (is_scalar($value) && trim((string) $value) !== '') {
            $haystack[] = trim((string) $value);
        }
    }

    /**
     * @param  array<int, string>  $haystack
     */
    private function appendMediaTextLeaves(array &$haystack, mixed $value, int $depth = 0): void
    {
        if ($depth > 4) {
            return;
        }
        if (is_scalar($value)) {
            $this->appendMediaTextCandidate($haystack, $value);

            return;
        }
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $child) {
            $this->appendMediaTextLeaves($haystack, $child, $depth + 1);
        }
    }

    private function displayTitle(object $row): string
    {
        foreach ([$row->title ?? null, $row->local_filename ?? null, $row->nextcloud_path ?? null, $row->original_path ?? null] as $value) {
            $text = $this->firstNonEmptyString($value);
            if ($text !== null) {
                return basename($text) ?: $text;
            }
        }

        return 'Media item';
    }

    private function thumbnailUrl(int $mediaId, int $treeId, ?string $assetUuid, ?string $path, bool $openable): ?string
    {
        if (! $openable) {
            return null;
        }

        if ($assetUuid !== null) {
            return "/api/media/{$assetUuid}/thumbnail/medium";
        }

        if ($path !== null) {
            return "/api/genealogy/media/{$mediaId}/thumbnail?size=medium&tree_id={$treeId}";
        }

        return null;
    }

    private function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
