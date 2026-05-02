<?php

namespace App\Support;

class JoplinPaths
{
    public static function syncPath(bool $trailingSlash = false): string
    {
        $path = '/'.trim((string) config('services.nextcloud.joplin_path', '/Joplin-data'), '/');

        return $trailingSlash ? $path.'/' : $path;
    }

    public static function localRoot(): ?string
    {
        $dataPath = trim((string) config('services.nextcloud.data_path', ''), '/');
        if ($dataPath === '') {
            return null;
        }

        $candidate = '/'.$dataPath.self::syncPath(false);

        return is_dir($candidate) ? $candidate : null;
    }

    public static function localFile(?string $localRoot, string $syncPath, string $targetPath): ?string
    {
        $normalizedSyncPath = rtrim($syncPath, '/');

        if (
            ! $localRoot
            || ($targetPath !== $normalizedSyncPath && ! str_starts_with($targetPath, $normalizedSyncPath.'/'))
        ) {
            return null;
        }

        $relativePath = ltrim(substr($targetPath, strlen($normalizedSyncPath)), '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        return rtrim($localRoot, '/').'/'.$relativePath;
    }
}
