<?php

namespace App\Services\UnifiedCli;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Read installed Codex skill bundles without executing their scripts. */
final class CliCodexSkillReader
{
    private const MAX_FILE_BYTES = 262144;

    public function __construct(private readonly string $workspace) {}

    public function call(string $tool, array $arguments): array
    {
        $skills = $this->discover();
        if ($tool === 'codex_skills_list') {
            $query = mb_strtolower(trim((string) ($arguments['query'] ?? '')));
            $items = [];
            foreach ($skills as $id => $directory) {
                $manifest = file_get_contents($directory.'/SKILL.md', false, null, 0, 8192);
                $description = is_string($manifest) && preg_match('/^description:\s*["\']?([^\r\n"\']+)/m', $manifest, $match)
                    ? trim($match[1]) : '';
                if ($query === '' || str_contains(mb_strtolower($id.' '.$description), $query)) {
                    $items[] = ['id' => $id, 'description' => $description];
                }
            }

            return ['success' => true, 'count' => count($items), 'skills' => $items];
        }
        if ($tool !== 'codex_skills_read') {
            throw new RuntimeException('Unknown Codex skill reader tool.');
        }
        $id = $arguments['skill_id'] ?? null;
        $relative = $arguments['file'] ?? 'SKILL.md';
        if (! is_string($id) || ! isset($skills[$id]) || ! is_string($relative)
            || $relative === '' || str_starts_with($relative, '/')
            || str_contains($relative, "\0") || preg_match('~(^|/)(\.|\.\.)(/|$)~', $relative)
            || preg_match('~^[A-Za-z]:~', $relative)) {
            throw new RuntimeException('Unknown skill or invalid skill-relative file.');
        }
        $base = $skills[$id];
        $file = realpath($base.'/'.$relative);
        if ($file === false || ! str_starts_with($file, $base.'/') || ! is_file($file)
            || ! is_readable($file) || filesize($file) > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Skill file unavailable or exceeds the read limit.');
        }
        $content = file_get_contents($file);
        if (! is_string($content) || ! mb_check_encoding($content, 'UTF-8')) {
            throw new RuntimeException('Skill file is not readable UTF-8 text.');
        }

        return ['success' => true, 'skill_id' => $id, 'file' => $relative,
            'sha256' => hash('sha256', $content), 'content' => $content];
    }

    private function discover(): array
    {
        $account = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
        $home = is_array($account) ? ($account['dir'] ?? '') : '';
        $roots = [
            'project' => $this->workspace.'/skills',
            'project-codex' => $this->workspace.'/.codex/skills',
            'project-agents' => $this->workspace.'/.agents/skills',
        ];
        if (is_string($home) && $home !== '') {
            $roots += [
                'codex' => $home.'/.codex/skills',
                'agents' => $home.'/.agents/skills',
                'plugin' => $home.'/.codex/plugins/cache',
            ];
        }
        $skills = [];
        foreach ($roots as $label => $root) {
            $canonical = realpath($root);
            if ($canonical === false || ! is_dir($canonical)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($canonical,
                FilesystemIterator::SKIP_DOTS));
            $iterator->setMaxDepth(12);
            foreach ($iterator as $file) {
                if ($file->getFilename() !== 'SKILL.md' || ! $file->isFile() || $file->isLink()) {
                    continue;
                }
                $directory = realpath($file->getPath());
                if ($directory === false || ! str_starts_with($directory, $canonical.'/')) {
                    continue;
                }
                $relative = substr($directory, strlen($canonical) + 1);
                $skills[$label.'/'.$relative] = $directory;
            }
        }
        ksort($skills);

        return $skills;
    }
}
