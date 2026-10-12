<?php

namespace App\Services\UnifiedCli;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Copy complete current skill bundles, bound to their table-owned version. */
final class CliSkillBundleExporter
{
    public function export(string $root, array $versions): array
    {
        $root = realpath($root);
        if ($root === false) {
            throw new InvalidArgumentException('Configured skill source directory is unavailable.');
        }
        $bundles = [];
        foreach ($versions as $version) {
            if (! in_array($version['is_active'] ?? false, [true, 1, '1'], true)) {
                continue;
            }
            $name = $version['skill_name'] ?? '';
            if (! is_string($name) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $name)) {
                throw new InvalidArgumentException('Invalid current skill name.');
            }
            $directory = $root.DIRECTORY_SEPARATOR.$name;
            if (! is_dir($directory) || is_link($directory)) {
                throw new InvalidArgumentException('Missing current skill bundle: '.$name);
            }
            $files = [];
            $paths = [];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $item) {
                if ($item->isLink() || ! $item->isFile()) {
                    throw new InvalidArgumentException('Skill bundle contains a link or non-file: '.$name);
                }
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($directory) + 1));
                if (preg_match('~(?:^|/)(?:\.env(?:\..*)?|\.git|\.ssh|__pycache__|credentials|secrets)(?:/|$)~i', $relative)) {
                    throw new InvalidArgumentException('Skill bundle contains a private or generated path: '.$name);
                }
                $bytes = file_get_contents($item->getPathname());
                if ($bytes === false) {
                    throw new InvalidArgumentException('Cannot read complete skill bundle: '.$name);
                }
                $files[$relative] = ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes), 'base64' => base64_encode($bytes)];
                $paths[$relative] = $item->getPathname();
            }
            ksort($files);
            if (! isset($files['SKILL.md'])) {
                throw new InvalidArgumentException('Complete skill instructions are missing: '.$name);
            }
            foreach ($paths as $relative => $path) {
                if (! hash_equals($files[$relative]['sha256'], (string) hash_file('sha256', $path))) {
                    throw new InvalidArgumentException('Skill bundle changed during capture: '.$name);
                }
            }
            $bundles[] = ['skill_name' => $name, 'version' => $version['version'],
                'content_hash' => $version['content_hash'],
                'source_content_hash' => $files['SKILL.md']['sha256'],
                'version_matches' => $files['SKILL.md']['sha256'] === $version['content_hash'],
                'files' => $files];
        }

        return $bundles;
    }
}
