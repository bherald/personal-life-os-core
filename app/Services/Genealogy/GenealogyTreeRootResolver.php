<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GenealogyTreeRootResolver
{
    private const MEDIA_ROOT_MARKERS = [
        'document',
        'documents',
        'docs',
        'audio',
        'census',
        'certificates',
        'downloads',
        'evidence-assets',
        'headstones',
        'image',
        'images',
        'intake',
        'media',
        'military',
        'nara-catalog',
        'newspapers',
        'obituaries',
        'other',
        'photo',
        'photos',
        'picture',
        'pictures',
        'records',
        'source',
        'sources',
        'sync-files',
        'video',
    ];

    public function mediaRoot(int $treeId, mixed $explicitRoot = null, bool $inferFromMedia = true): string
    {
        return $this->resolve(
            treeId: $treeId,
            explicitRoot: $explicitRoot,
            fallbackRoot: config('genealogy.nextcloud_root', '/Library/Genealogy'),
            inferFromMedia: $inferFromMedia
        );
    }

    public function referenceRoot(int $treeId, mixed $explicitRoot = null, bool $inferFromMedia = true): string
    {
        return $this->resolve(
            treeId: $treeId,
            explicitRoot: $explicitRoot,
            fallbackRoot: config('genealogy.ft_reference_root', storage_path('app/genealogy/ft-reference')),
            inferFromMedia: $inferFromMedia
        );
    }

    public function treeScopedRoot(int $treeId, mixed $root, ?string $treeName = null): string
    {
        $root = $this->normalizePath($root) ?? '/Library/Genealogy';
        $treeSlug = $this->treeSlug($treeId, $treeName);
        if ($treeSlug === '') {
            $treeSlug = 'tree-'.$treeId;
        }
        $folderSlug = $this->treeSlugCollides($treeId, $treeSlug)
            ? $treeSlug.'-tree-'.$treeId
            : $treeSlug;

        return Str::slug(basename($root)) === $folderSlug
            ? $root
            : $root.'/'.$folderSlug;
    }

    private function resolve(int $treeId, mixed $explicitRoot, mixed $fallbackRoot, bool $inferFromMedia): string
    {
        $explicit = $this->normalizePath($explicitRoot);
        if ($explicit !== null) {
            return $explicit;
        }

        if ($inferFromMedia) {
            $inferred = $this->inferFromGenealogyMedia($treeId);
            if ($inferred !== null) {
                return $inferred;
            }
        }

        return $this->treeScopedRoot($treeId, $this->treeAwareFallback($treeId, $fallbackRoot));
    }

    private function inferFromGenealogyMedia(int $treeId): ?string
    {
        if ($treeId <= 0 || ! Schema::hasTable('genealogy_media') || ! Schema::hasColumn('genealogy_media', 'nextcloud_path')) {
            return null;
        }

        $paths = $this->mediaPaths($treeId, onlyExistingFiles: true);
        if ($paths === []) {
            $paths = $this->mediaPaths($treeId, onlyExistingFiles: false);
        }

        $counts = [];
        foreach ($paths as $path) {
            $root = $this->rootFromMediaPath($path);
            if ($root === null) {
                continue;
            }

            $counts[$root] = ($counts[$root] ?? 0) + 1;
        }

        if ($counts === []) {
            return null;
        }

        uksort($counts, static function (string $left, string $right) use ($counts): int {
            $byCount = $counts[$right] <=> $counts[$left];
            if ($byCount !== 0) {
                return $byCount;
            }

            return strlen($right) <=> strlen($left);
        });

        return array_key_first($counts);
    }

    /**
     * @return list<string>
     */
    private function mediaPaths(int $treeId, bool $onlyExistingFiles): array
    {
        $query = DB::table('genealogy_media')
            ->where('tree_id', $treeId)
            ->whereNotNull('nextcloud_path')
            ->where('nextcloud_path', '<>', '')
            ->where('nextcloud_path', 'not like', 'http%')
            ->orderByDesc('id')
            ->limit(5000);

        if ($onlyExistingFiles && Schema::hasColumn('genealogy_media', 'file_exists')) {
            $query->where('file_exists', 1);
        }

        return $query->pluck('nextcloud_path')
            ->map(static fn ($path): string => (string) $path)
            ->filter(static fn (string $path): bool => trim($path) !== '')
            ->values()
            ->all();
    }

    private function rootFromMediaPath(string $path): ?string
    {
        $path = $this->normalizePath($path);
        if ($path === null || preg_match('~^https?://~i', $path) === 1) {
            return null;
        }

        $segments = explode('/', trim($path, '/'));
        foreach ($segments as $index => $segment) {
            $normalized = Str::slug($segment);
            if ($index > 0 && (in_array($normalized, self::MEDIA_ROOT_MARKERS, true) || str_starts_with($normalized, 'evidence-sprint'))) {
                return '/'.implode('/', array_slice($segments, 0, $index));
            }
        }

        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $directory !== '' && $directory !== '.' ? $directory : null;
    }

    private function treeAwareFallback(int $treeId, mixed $fallbackRoot): string
    {
        $root = $this->normalizePath($fallbackRoot) ?? '/Library/Genealogy';
        if ($treeId <= 0 || ! Schema::hasTable('genealogy_trees')) {
            return $root;
        }

        $treeSlug = $this->treeSlug($treeId);
        $rootSlug = Str::slug(basename($root));
        if ($treeSlug === '' || $rootSlug === '') {
            return $root;
        }

        if ($rootSlug === $treeSlug && ! $this->treeSlugCollides($treeId, $treeSlug)) {
            return $root;
        }

        if ($this->rootLooksLikeAnotherTree($treeId, $rootSlug) || str_ends_with($rootSlug, 'family-tree')) {
            $parent = rtrim(str_replace('\\', '/', dirname($root)), '/');

            return $parent !== '' && $parent !== '.' ? $parent : $root;
        }

        return $root;
    }

    private function treeSlug(int $treeId, ?string $fallbackName = null): string
    {
        if (Schema::hasTable('genealogy_trees') && Schema::hasColumn('genealogy_trees', 'name')) {
            $name = trim((string) DB::table('genealogy_trees')->where('id', $treeId)->value('name'));
            if ($name !== '') {
                return Str::slug($name);
            }
        }

        return Str::slug((string) $fallbackName);
    }

    private function rootLooksLikeAnotherTree(int $treeId, string $rootSlug): bool
    {
        if (! Schema::hasTable('genealogy_trees') || ! Schema::hasColumn('genealogy_trees', 'name')) {
            return false;
        }

        return DB::table('genealogy_trees')
            ->where('id', '<>', $treeId)
            ->whereNotNull('name')
            ->pluck('name')
            ->contains(static fn ($name): bool => Str::slug((string) $name) === $rootSlug);
    }

    private function treeSlugCollides(int $treeId, string $treeSlug): bool
    {
        if (! Schema::hasTable('genealogy_trees') || ! Schema::hasColumn('genealogy_trees', 'name')) {
            return false;
        }

        return DB::table('genealogy_trees')
            ->where('id', '<>', $treeId)
            ->whereNotNull('name')
            ->pluck('name')
            ->contains(static fn ($name): bool => Str::slug((string) $name) === $treeSlug);
    }

    private function normalizePath(mixed $path): ?string
    {
        if (! is_scalar($path)) {
            return null;
        }

        $path = trim((string) $path);
        if ($path === '' || preg_match('~^https?://~i', $path) === 1) {
            return null;
        }

        $path = preg_replace('~^file://~i', '', $path) ?? $path;
        $path = preg_replace('~/+~', '/', str_replace('\\', '/', $path)) ?? $path;
        $path = rtrim($path, '/');
        if ($path === '') {
            return '/';
        }

        if (preg_match('~^[A-Za-z]:/~', $path) === 1) {
            return $path;
        }

        return '/'.trim($path, '/');
    }
}
