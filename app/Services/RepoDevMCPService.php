<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use RuntimeException;

class RepoDevMCPService
{
    private const DEFAULT_MATCH_LIMIT = 50;

    private const DEFAULT_DIRECTORY_LIMIT = 200;

    private const DEFAULT_READ_MAX_LINES = 300;

    private const ALLOWED_VERIFICATION_RUNNERS = [
        'unit',
        'feature',
        'stabilization',
        'phpunit-target',
        'pint-test',
        'composer-analyse',
        'diff-check',
    ];

    public function __construct(
        private OfflinePolicyService $policy,
    ) {}

    public function findRepoFiles(string $pattern, ?string $path = '.', int $limit = self::DEFAULT_MATCH_LIMIT): array
    {
        $resolved = $this->resolveDirectoryPath($path ?? '.', 'read');
        $limit = $this->clamp($limit, 1, 200);

        $matches = $this->findFilesWithRipgrep($pattern, $resolved['absolute'], $resolved['relative'], $limit);
        if ($matches === null) {
            $matches = $this->findFilesWithPhp($pattern, $resolved['absolute'], $resolved['relative'], $limit);
        }

        return [
            'repo_root' => base_path(),
            'scope_path' => $resolved['relative'],
            'pattern' => $pattern,
            'count' => count($matches),
            'files' => $matches,
        ];
    }

    public function searchRepo(string $query, ?string $path = '.', int $limit = self::DEFAULT_MATCH_LIMIT): array
    {
        $resolved = $this->resolveDirectoryPath($path ?? '.', 'read');
        $limit = $this->clamp($limit, 1, 200);

        $matches = $this->searchWithRipgrep($query, $resolved['absolute'], $resolved['relative'], $limit);
        if ($matches === null) {
            $matches = $this->searchWithPhp($query, $resolved['absolute'], $resolved['relative'], $limit);
        }

        return [
            'repo_root' => base_path(),
            'scope_path' => $resolved['relative'],
            'query' => $query,
            'count' => count($matches),
            'matches' => $matches,
        ];
    }

    public function listRepoDirectory(string $path = '.', bool $recursive = false, int $limit = self::DEFAULT_DIRECTORY_LIMIT): array
    {
        $resolved = $this->resolveDirectoryPath($path, 'read');
        $limit = $this->clamp($limit, 1, 500);

        $entries = [];

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($resolved['absolute'], \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                $entries[] = $this->serializeFilesystemItem($item->getPathname());
                if (count($entries) >= $limit) {
                    break;
                }
            }
        } else {
            $iterator = new \FilesystemIterator($resolved['absolute'], \FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $item) {
                $entries[] = $this->serializeFilesystemItem($item->getPathname());
                if (count($entries) >= $limit) {
                    break;
                }
            }
        }

        usort($entries, static function (array $a, array $b): int {
            if (($a['type'] ?? '') !== ($b['type'] ?? '')) {
                return ($a['type'] ?? '') === 'dir' ? -1 : 1;
            }

            return strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? ''));
        });

        return [
            'repo_root' => base_path(),
            'path' => $resolved['relative'],
            'recursive' => $recursive,
            'count' => count($entries),
            'entries' => $entries,
        ];
    }

    public function readRepoFile(
        string $path,
        ?int $startLine = null,
        ?int $endLine = null,
        int $maxLines = self::DEFAULT_READ_MAX_LINES,
    ): array {
        $resolved = $this->resolveFilePath($path, 'read', mustExist: true);
        $this->guardAgainstBinaryFile($resolved['absolute']);

        $maxLines = $this->clamp($maxLines, 1, 500);
        $lines = @file($resolved['absolute'], FILE_IGNORE_NEW_LINES);
        if (! is_array($lines)) {
            throw new RuntimeException("Unable to read file '{$resolved['relative']}'.");
        }

        $totalLines = count($lines);
        $start = max(1, $startLine ?? 1);
        $end = $endLine ?? min($totalLines, $start + $maxLines - 1);
        if ($end < $start) {
            $end = $start;
        }
        if (($end - $start + 1) > $maxLines) {
            $end = $start + $maxLines - 1;
        }

        $slice = [];
        foreach (array_slice($lines, $start - 1, max(0, $end - $start + 1), true) as $index => $line) {
            $slice[] = [
                'line' => $index + 1,
                'text' => $line,
            ];
        }

        return [
            'repo_root' => base_path(),
            'path' => $resolved['relative'],
            'line_start' => $start,
            'line_end' => $slice === [] ? null : (int) end($slice)['line'],
            'total_lines' => $totalLines,
            'truncated' => $totalLines > count($slice),
            'lines' => $slice,
        ];
    }

    public function writeRepoFile(string $path, string $content, bool $createDirectories = false): array
    {
        $resolved = $this->resolveFilePath($path, 'write', mustExist: false);
        $directory = dirname($resolved['absolute']);

        if (! is_dir($directory)) {
            if (! $createDirectories) {
                throw new RuntimeException("Parent directory does not exist for '{$resolved['relative']}'.");
            }

            if (! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException("Unable to create parent directory for '{$resolved['relative']}'.");
            }
        }

        $existing = is_file($resolved['absolute']);
        $bytes = @file_put_contents($resolved['absolute'], $content);
        if ($bytes === false) {
            throw new RuntimeException("Unable to write file '{$resolved['relative']}'.");
        }

        return [
            'repo_root' => base_path(),
            'path' => $resolved['relative'],
            'bytes_written' => $bytes,
            'created' => ! $existing,
        ];
    }

    public function applyRepoPatch(string $patch, bool $checkOnly = false): array
    {
        $patch = trim($patch);
        if ($patch === '') {
            throw new RuntimeException('Patch cannot be empty.');
        }

        $paths = $this->extractPatchPaths($patch);
        if ($paths === []) {
            throw new RuntimeException('Patch does not contain any repo file paths.');
        }

        foreach ($paths as $path) {
            $this->resolveFilePath($path, 'write', mustExist: false);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'plos-repo-patch-');
        if ($tmp === false) {
            throw new RuntimeException('Unable to create temporary patch file.');
        }

        try {
            file_put_contents($tmp, $patch."\n");
            $check = $this->runProcess(['git', 'apply', '--check', '--whitespace=nowarn', $tmp], 30);
            if (! $check['success']) {
                return [
                    'applied' => false,
                    'check_only' => $checkOnly,
                    'paths' => $paths,
                    'check' => $check,
                    'apply' => null,
                ];
            }

            $apply = null;
            if (! $checkOnly) {
                $apply = $this->runProcess(['git', 'apply', '--whitespace=nowarn', $tmp], 30);
            }

            return [
                'applied' => ! $checkOnly && (bool) ($apply['success'] ?? false),
                'check_only' => $checkOnly,
                'paths' => $paths,
                'check' => $check,
                'apply' => $apply,
            ];
        } finally {
            @unlink($tmp);
        }
    }

    public function runVerification(
        string $runner,
        ?string $target = null,
        ?string $filter = null,
        int $timeoutSeconds = 120,
    ): array {
        $runner = strtolower(trim($runner));
        if (! in_array($runner, self::ALLOWED_VERIFICATION_RUNNERS, true)) {
            throw new RuntimeException('Unknown verification runner. Allowed: '.implode(', ', self::ALLOWED_VERIFICATION_RUNNERS));
        }

        $timeoutSeconds = $this->clamp($timeoutSeconds, 1, 600);
        $command = match ($runner) {
            'unit' => [PHP_BINARY, 'artisan', 'test', '--testsuite=Unit'],
            'feature' => [PHP_BINARY, 'artisan', 'test', '--testsuite=Feature'],
            'stabilization' => [PHP_BINARY, 'artisan', 'test', 'tests/Feature/Stabilization'],
            'pint-test' => ['./vendor/bin/pint', '--dirty', '--test'],
            'composer-analyse' => ['composer', 'analyse'],
            'diff-check' => ['git', 'diff', '--check'],
            'phpunit-target' => $this->targetedTestCommand($target, $filter),
        };

        $result = $this->runProcess($command, $timeoutSeconds);

        return [
            'runner' => $runner,
            'target' => $target,
            'filter' => $filter,
            'command' => $command,
            'result' => $result,
        ];
    }

    public function listRoutes(string $scope = 'frontend', ?string $filter = null): array
    {
        $scope = match (strtolower(trim($scope))) {
            'web', 'frontend' => 'frontend',
            'api' => 'api',
            default => 'all',
        };

        $filter = trim((string) $filter);
        $needle = strtolower($filter);
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $uri = (string) $route->uri();
            $isApi = str_starts_with($uri, 'api/');
            if ($scope === 'frontend' && $isApi) {
                continue;
            }
            if ($scope === 'api' && ! $isApi) {
                continue;
            }

            $action = (string) $route->getActionName();
            $name = (string) ($route->getName() ?? '');
            $haystack = strtolower($uri.' '.$name.' '.$action);
            if ($needle !== '' && ! str_contains($haystack, $needle)) {
                continue;
            }

            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            sort($methods);

            $routes[] = [
                'methods' => $methods,
                'uri' => $uri,
                'name' => $name !== '' ? $name : null,
                'action' => $action === 'Closure' ? 'Closure' : $action,
                'middleware' => array_values($route->gatherMiddleware()),
            ];
        }

        usort($routes, static function (array $a, array $b): int {
            return strcmp((string) ($a['uri'] ?? ''), (string) ($b['uri'] ?? ''));
        });

        return [
            'scope' => $scope,
            'filter' => $filter !== '' ? $filter : null,
            'count' => count($routes),
            'routes' => $routes,
        ];
    }

    /**
     * @return array{absolute:string,relative:string}
     */
    private function resolveDirectoryPath(string $path, string $mode): array
    {
        $resolved = $this->resolvePath($path, $mode);
        if (! is_dir($resolved['absolute'])) {
            throw new RuntimeException("Directory '{$resolved['relative']}' does not exist.");
        }

        return $resolved;
    }

    /**
     * @return array{absolute:string,relative:string}
     */
    private function resolveFilePath(string $path, string $mode, bool $mustExist): array
    {
        $resolved = $this->resolvePath($path, $mode);

        if (is_dir($resolved['absolute'])) {
            throw new RuntimeException("'{$resolved['relative']}' is a directory, not a file.");
        }

        if ($mustExist && ! is_file($resolved['absolute'])) {
            throw new RuntimeException("File '{$resolved['relative']}' does not exist.");
        }

        return $resolved;
    }

    /**
     * @return array{absolute:string,relative:string}
     */
    private function resolvePath(string $path, string $mode): array
    {
        $input = trim($path);
        if ($input === '') {
            $input = '.';
        }

        $absolute = $this->normalizeAbsolutePath($input);

        // Finding H1: pure lexical normalization ignores symlinks. A symlink
        // inside the repo that points at /etc/passwd would still classify as
        // repo_* without this realpath hop. If the target doesn't exist yet
        // (e.g. writing a new file) we resolve the nearest existing ancestor
        // so a symlinked directory still can't smuggle a write outside base.
        $physical = $this->resolvePhysicalPath($absolute);
        if ($physical !== $absolute) {
            $decision = $this->policy->evaluatePath($physical, $mode);
            if (! $decision->allowed) {
                throw new RuntimeException($decision->reason);
            }
        }

        $decision = $this->policy->evaluatePath($absolute, $mode);
        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason);
        }

        return [
            'absolute' => $absolute,
            'relative' => $this->repoRelativePath($absolute),
        ];
    }

    /**
     * Resolve a path's physical filesystem target, following symlinks.
     * Walks up to the nearest existing ancestor when the path itself is
     * missing so the check still fires for not-yet-created files.
     */
    private function resolvePhysicalPath(string $absolute): string
    {
        $candidate = $absolute;
        for ($i = 0; $i < 32; $i++) {
            if ($candidate === '' || $candidate === '/') {
                return $absolute;
            }
            $real = @realpath($candidate);
            if ($real !== false) {
                if ($candidate === $absolute) {
                    return $real;
                }
                // Rejoin the unresolved tail against the resolved ancestor.
                $tail = substr($absolute, strlen($candidate));

                return rtrim($real, '/').$tail;
            }
            $candidate = dirname($candidate);
        }

        return $absolute;
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $absolute = str_starts_with($path, '/') ? $path : base_path($path);
        $parts = explode('/', $absolute);
        $stack = [];

        foreach ($parts as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($stack);

                continue;
            }

            $stack[] = $segment;
        }

        return '/'.implode('/', $stack);
    }

    private function repoRelativePath(string $absolute): string
    {
        $root = rtrim(base_path(), '/');
        if ($absolute === $root) {
            return '.';
        }

        if (str_starts_with($absolute, $root.'/')) {
            return substr($absolute, strlen($root) + 1);
        }

        return $absolute;
    }

    private function guardAgainstBinaryFile(string $absolute): void
    {
        $sample = @file_get_contents($absolute, false, null, 0, 8192);
        if ($sample !== false && str_contains($sample, "\0")) {
            throw new RuntimeException('Binary files are not supported by repo-dev read tools.');
        }
    }

    /**
     * @return list<string>
     */
    private function extractPatchPaths(string $patch): array
    {
        $paths = [];
        foreach (preg_split('/\r?\n/', $patch) ?: [] as $line) {
            if (preg_match('/^diff --git a\/(.+) b\/(.+)$/', $line, $matches) === 1) {
                foreach ([$matches[1], $matches[2]] as $path) {
                    if ($path !== '/dev/null') {
                        $paths[] = $path;
                    }
                }
            }

            if (preg_match('/^(---|\+\+\+) (?:a|b)\/(.+)$/', $line, $matches) === 1) {
                if ($matches[2] !== '/dev/null') {
                    $paths[] = $matches[2];
                }
            }
        }

        $paths = array_values(array_unique(array_filter($paths, static fn (string $path): bool => trim($path) !== '')));
        sort($paths);

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function targetedTestCommand(?string $target, ?string $filter): array
    {
        $target = trim((string) $target);
        if ($target === '') {
            throw new RuntimeException('phpunit-target requires a test file or directory target.');
        }

        $resolved = is_dir(base_path($target))
            ? $this->resolveDirectoryPath($target, 'read')
            : $this->resolveFilePath($target, 'read', mustExist: true);

        if ($resolved['relative'] !== 'tests' && ! str_starts_with($resolved['relative'], 'tests/')) {
            throw new RuntimeException('phpunit-target may only run paths under tests/.');
        }

        $command = [PHP_BINARY, 'artisan', 'test', $resolved['relative']];
        $filter = trim((string) $filter);
        if ($filter !== '') {
            if (strlen($filter) > 200) {
                throw new RuntimeException('phpunit-target filter is too long.');
            }
            $command[] = '--filter='.$filter;
        }

        return $command;
    }

    /**
     * @param  list<string>  $command
     * @return array{success:bool,exit_code:int|null,duration_ms:int,output:string,error:string}
     */
    private function runProcess(array $command, int $timeoutSeconds): array
    {
        $started = microtime(true);
        $process = Process::path(base_path())
            ->timeout($timeoutSeconds)
            ->run($command);

        return [
            'success' => $process->successful(),
            'exit_code' => $process->exitCode(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'output' => $this->truncateOutput(trim($process->output())),
            'error' => $this->truncateOutput(trim($process->errorOutput())),
        ];
    }

    private function truncateOutput(string $output, int $limit = 12000): string
    {
        if (strlen($output) <= $limit) {
            return $output;
        }

        return substr($output, 0, $limit)."\n...[truncated]";
    }

    /**
     * @return list<string>|null
     */
    private function findFilesWithRipgrep(string $pattern, string $absolute, string $relativeBase, int $limit): ?array
    {
        $command = ['rg', '--files'];
        if ($absolute === base_path()) {
            array_push($command, '--glob=!vendor/**', '--glob=!node_modules/**', '--glob=!.git/**');
        }

        $result = Process::path($absolute)->run($command);
        if (! $result->successful()) {
            return null;
        }

        $needle = strtolower($pattern);
        $matches = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (! str_contains(strtolower($line), $needle)) {
                continue;
            }

            $matches[] = $this->joinRelativePath($relativeBase, $line);
            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @return list<array<string,mixed>>|null
     */
    private function searchWithRipgrep(string $query, string $absolute, string $relativeBase, int $limit): ?array
    {
        $command = ['rg', '--line-number', '--no-heading', '--smart-case', '--fixed-strings', $query, '.'];
        if ($absolute === base_path()) {
            array_splice($command, 1, 0, ['--glob=!vendor/**', '--glob=!node_modules/**', '--glob=!.git/**']);
        }

        $result = Process::path($absolute)->run($command);
        if (! $result->successful() && $result->exitCode() !== 1) {
            return null;
        }

        $matches = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$file, $lineNumber, $text] = array_pad(explode(':', $line, 3), 3, '');
            $matches[] = [
                'path' => $this->joinRelativePath($relativeBase, $file),
                'line' => (int) $lineNumber,
                'text' => $text,
            ];

            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function findFilesWithPhp(string $pattern, string $absolute, string $relativeBase, int $limit): array
    {
        $needle = strtolower($pattern);
        $matches = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $relative = $this->joinRelativePath($relativeBase, substr($item->getPathname(), strlen(rtrim($absolute, '/')) + 1));
            if (! str_contains(strtolower($relative), $needle)) {
                continue;
            }

            $matches[] = $relative;
            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function searchWithPhp(string $query, string $absolute, string $relativeBase, int $limit): array
    {
        $needle = strtolower($query);
        $matches = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (! $item->isFile() || $item->getSize() > 1024 * 1024) {
                continue;
            }

            $contents = @file($item->getPathname(), FILE_IGNORE_NEW_LINES);
            if (! is_array($contents)) {
                continue;
            }

            foreach ($contents as $index => $line) {
                if (! str_contains(strtolower($line), $needle)) {
                    continue;
                }

                $relative = substr($item->getPathname(), strlen(rtrim($absolute, '/')) + 1);
                $matches[] = [
                    'path' => $this->joinRelativePath($relativeBase, $relative),
                    'line' => $index + 1,
                    'text' => $line,
                ];

                if (count($matches) >= $limit) {
                    break 2;
                }
            }
        }

        return $matches;
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeFilesystemItem(string $absolute): array
    {
        return [
            'path' => $this->repoRelativePath($absolute),
            'type' => is_dir($absolute) ? 'dir' : 'file',
            'size' => is_dir($absolute) ? null : (@filesize($absolute) ?: 0),
        ];
    }

    private function joinRelativePath(string $base, string $child): string
    {
        $base = trim($base);
        $child = trim($child);

        if ($base === '' || $base === '.') {
            return ltrim($child, '/');
        }

        return trim($base, '/').'/'.ltrim($child, '/');
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
