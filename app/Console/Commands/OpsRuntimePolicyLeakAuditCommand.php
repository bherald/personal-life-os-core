<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

class OpsRuntimePolicyLeakAuditCommand extends Command
{
    protected $signature = 'ops:runtime-policy-leak-audit
                            {--root= : Optional scan root override for local/test audits}
                            {--json : Emit machine-readable JSON}
                            {--compact : Emit aggregate-only scheduled-output-safe JSON}
                            {--strict : Return non-zero when runtime policy candidates are found}';

    protected $description = 'Observe-only audit for hardcoded growth limits that should be table-driven';

    public function handle(): int
    {
        $config = $this->auditConfig();
        $payload = [
            'status' => 'ok',
            'scope' => 'runtime_policy_leak_audit',
            'generated_at' => now()->toIso8601String(),
            'policy_source' => 'system_configs.ops.runtime_policy_leak_audit',
            'read_only' => true,
            'mutation_allowed' => false,
            'compact' => (bool) $this->option('compact'),
            'summary' => [
                'files_scanned' => 0,
                'matches' => 0,
                'runtime_policy_candidates' => 0,
                'table_driven_references' => 0,
                'table_driven_file_contexts' => 0,
                'test_fixtures' => 0,
                'migration_seeds' => 0,
                'config_defaults' => 0,
            ],
            'by_pattern' => [],
            'by_classification' => [],
            'findings' => [],
            'runtime_policy_candidate_samples' => [],
            'top_runtime_policy_candidate_files' => [],
            'next_action' => 'review_runtime_policy_candidates',
        ];

        if (isset($config['__error'])) {
            $payload['status'] = 'warning';
            $payload['config_error'] = $config['__error'];

            return $this->emitPayload($payload);
        }

        if (! (bool) ($config['enabled'] ?? false)) {
            $payload['status'] = 'warning';
            $payload['next_action'] = 'enable_table_policy_before_auditing';
            $payload['config_error'] = 'runtime_policy_leak_audit_disabled';

            return $this->emitPayload($payload);
        }

        $root = $this->scanRoot();
        $includePaths = $this->stringList($config['include_paths'] ?? ['app', 'config', 'database', 'routes']);
        $excludePaths = $this->stringList($config['exclude_paths'] ?? []);
        $extensions = $this->stringList($config['extensions'] ?? ['php']);
        $patterns = $this->patterns($config['patterns'] ?? []);
        $classifier = $this->classifier($config['classifier'] ?? []);
        $maxFindings = $this->positiveInt($config['max_findings'] ?? null) ?? 100;

        foreach ($this->scanFiles($root, $includePaths, $excludePaths, $extensions) as $file) {
            $payload['summary']['files_scanned']++;
            $relativePath = $this->relativePath($root, $file->getPathname());
            $this->scanFile($file, $relativePath, $patterns, $classifier, $maxFindings, $payload);
        }

        $payload['top_runtime_policy_candidate_files'] = $this->topCounts(
            $payload['_runtime_policy_candidate_file_counts'] ?? [],
            $maxFindings
        );
        unset($payload['_runtime_policy_candidate_file_counts']);

        $payload['findings_limit'] = $maxFindings;
        $payload['findings_truncated'] = (int) $payload['summary']['matches'] > count($payload['findings']);
        $payload['runtime_policy_candidate_samples_truncated'] =
            (int) $payload['summary']['runtime_policy_candidates'] > count($payload['runtime_policy_candidate_samples']);
        $payload['status'] = (int) $payload['summary']['runtime_policy_candidates'] > 0 ? 'review_required' : 'ok';
        if ((bool) $this->option('compact')) {
            $payload['compact_detail_omitted'] = [
                'findings' => count($payload['findings']),
                'runtime_policy_candidate_samples' => count($payload['runtime_policy_candidate_samples']),
                'top_runtime_policy_candidate_files' => count($payload['top_runtime_policy_candidate_files']),
            ];
            unset(
                $payload['findings'],
                $payload['runtime_policy_candidate_samples'],
                $payload['top_runtime_policy_candidate_files']
            );
        }

        return $this->emitPayload($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emitPayload(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                'runtime-policy-leak-audit: status=%s files=%d matches=%d candidates=%d table_refs=%d',
                $payload['status'] ?? 'unknown',
                (int) ($payload['summary']['files_scanned'] ?? 0),
                (int) ($payload['summary']['matches'] ?? 0),
                (int) ($payload['summary']['runtime_policy_candidates'] ?? 0),
                (int) ($payload['summary']['table_driven_references'] ?? 0)
            ));
        }

        if ((bool) $this->option('strict') && (int) ($payload['summary']['runtime_policy_candidates'] ?? 0) > 0) {
            return self::FAILURE;
        }

        return ($payload['status'] ?? 'warning') === 'warning' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function auditConfig(): array
    {
        try {
            $row = DB::selectOne(
                'SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
                ['ops', 'runtime_policy_leak_audit']
            );
        } catch (Throwable) {
            return ['__error' => 'runtime_policy_leak_audit_config_unavailable'];
        }

        if ($row === null) {
            return ['__error' => 'runtime_policy_leak_audit_config_missing'];
        }

        $decoded = json_decode((string) $row->config_value, true);
        if (! is_array($decoded)) {
            return ['__error' => 'runtime_policy_leak_audit_config_invalid_json'];
        }

        if (! array_key_exists('enabled', $decoded) || ! is_array($decoded['patterns'] ?? null)) {
            return ['__error' => 'runtime_policy_leak_audit_config_missing_keys'];
        }

        if (! is_array($decoded['classifier'] ?? null)
            || ! is_array($decoded['classifier']['line_table_driven_markers'] ?? null)
            || ! is_array($decoded['classifier']['file_table_policy_markers'] ?? null)) {
            return ['__error' => 'runtime_policy_leak_audit_config_missing_classifier'];
        }

        return $decoded;
    }

    private function scanRoot(): string
    {
        $root = trim((string) ($this->option('root') ?: base_path()));

        return rtrim(realpath($root) ?: $root, DIRECTORY_SEPARATOR);
    }

    /**
     * @param  list<string>  $includePaths
     * @param  list<string>  $excludePaths
     * @param  list<string>  $extensions
     * @return iterable<SplFileInfo>
     */
    private function scanFiles(string $root, array $includePaths, array $excludePaths, array $extensions): iterable
    {
        $extensions = array_flip(array_map(static fn (string $extension): string => ltrim($extension, '.'), $extensions));

        foreach ($includePaths as $includePath) {
            $path = $root.DIRECTORY_SEPARATOR.trim($includePath, DIRECTORY_SEPARATOR);
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $relativePath = $this->relativePath($root, $file->getPathname());
                if ($this->isExcluded($relativePath, $excludePaths)) {
                    continue;
                }

                if (! isset($extensions[$file->getExtension()])) {
                    continue;
                }

                yield $file;
            }
        }
    }

    /**
     * @param  list<array{name:string,regex:string,severity:string,recommendation:string}>  $patterns
     * @param  array{line_table_driven_markers:list<string>,file_table_policy_markers:list<string>}  $classifier
     * @param  array<string,mixed>  $payload
     */
    private function scanFile(SplFileInfo $file, string $relativePath, array $patterns, array $classifier, int $maxFindings, array &$payload): void
    {
        $lines = @file($file->getPathname(), FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        $fileContext = $this->filePolicyContext($relativePath, implode("\n", $lines), $classifier);
        if ($fileContext !== null) {
            $payload['summary']['table_driven_file_contexts']++;
        }

        foreach ($lines as $index => $line) {
            if ($this->isCommentOnlyLine($line)) {
                continue;
            }

            $lineNumber = $index + 1;
            foreach ($patterns as $pattern) {
                if (@preg_match($pattern['regex'], $line) !== 1) {
                    continue;
                }

                $classification = $this->classifyFinding($relativePath, $line, $classifier, $fileContext);
                $payload['summary']['matches']++;
                $payload['by_pattern'][$pattern['name']] = (int) ($payload['by_pattern'][$pattern['name']] ?? 0) + 1;
                $payload['by_classification'][$classification] = (int) ($payload['by_classification'][$classification] ?? 0) + 1;
                $summaryKey = match ($classification) {
                    'runtime_policy_candidate' => 'runtime_policy_candidates',
                    'table_driven_reference' => 'table_driven_references',
                    'test_fixture' => 'test_fixtures',
                    'migration_seed' => 'migration_seeds',
                    'config_default' => 'config_defaults',
                    default => null,
                };
                if ($summaryKey !== null) {
                    $payload['summary'][$summaryKey]++;
                }

                $finding = [
                    'file' => $relativePath,
                    'line' => $lineNumber,
                    'pattern' => $pattern['name'],
                    'severity' => $pattern['severity'],
                    'classification' => $classification,
                    'recommendation' => $pattern['recommendation'],
                    'snippet' => trim($line),
                ];
                if ($fileContext !== null) {
                    $finding['file_context'] = $fileContext;
                }

                if (count($payload['findings']) < $maxFindings) {
                    $payload['findings'][] = $finding;
                }

                if ($classification === 'runtime_policy_candidate') {
                    $payload['_runtime_policy_candidate_file_counts'][$relativePath] =
                        (int) ($payload['_runtime_policy_candidate_file_counts'][$relativePath] ?? 0) + 1;

                    if (count($payload['runtime_policy_candidate_samples']) < $maxFindings) {
                        $payload['runtime_policy_candidate_samples'][] = $finding;
                    }
                }
            }
        }
    }

    /**
     * @param  array<string,int>  $counts
     * @return list<array{file:string,count:int}>
     */
    private function topCounts(array $counts, int $limit): array
    {
        arsort($counts);

        $top = [];
        foreach (array_slice($counts, 0, $limit, true) as $file => $count) {
            $top[] = [
                'file' => (string) $file,
                'count' => (int) $count,
            ];
        }

        return $top;
    }

    private function isCommentOnlyLine(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return false;
        }

        return str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '#')
            || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '/*')
            || str_starts_with($trimmed, '*/');
    }

    /**
     * @param  array{line_table_driven_markers:list<string>,file_table_policy_markers:list<string>}  $classifier
     */
    private function classifyFinding(string $relativePath, string $line, array $classifier, ?string $fileContext): string
    {
        if (str_starts_with($relativePath, 'tests/')) {
            return 'test_fixture';
        }

        if (str_starts_with($relativePath, 'database/migrations/')) {
            return 'migration_seed';
        }

        if (str_starts_with($relativePath, 'config/')) {
            return 'config_default';
        }

        if ($this->containsAnyMarker($line, $classifier['line_table_driven_markers'])) {
            return 'table_driven_reference';
        }

        if ($fileContext === 'table_driven_policy_owner') {
            return 'table_driven_reference';
        }

        return 'runtime_policy_candidate';
    }

    /**
     * @param  array{line_table_driven_markers:list<string>,file_table_policy_markers:list<string>}  $classifier
     */
    private function filePolicyContext(string $relativePath, string $contents, array $classifier): ?string
    {
        if (str_starts_with($relativePath, 'tests/')
            || str_starts_with($relativePath, 'database/migrations/')
            || str_starts_with($relativePath, 'config/')) {
            return null;
        }

        if ($this->containsAnyMarker($contents, $classifier['file_table_policy_markers'])) {
            return 'table_driven_policy_owner';
        }

        return null;
    }

    /**
     * @param  list<string>  $excludePaths
     */
    private function isExcluded(string $relativePath, array $excludePaths): bool
    {
        foreach ($excludePaths as $excludePath) {
            $excludePath = trim($excludePath, '/');
            if ($excludePath !== '' && str_starts_with($relativePath, $excludePath.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{name:string,regex:string,severity:string,recommendation:string}>
     */
    private function patterns(mixed $patterns): array
    {
        if (! is_array($patterns)) {
            return [];
        }

        $normalized = [];
        foreach ($patterns as $pattern) {
            if (! is_array($pattern)) {
                continue;
            }

            $name = $this->cleanCode($pattern['name'] ?? null);
            $regex = is_string($pattern['regex'] ?? null) ? $pattern['regex'] : null;
            if ($name === null || $regex === null || @preg_match($regex, '') === false) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'regex' => $regex,
                'severity' => $this->cleanCode($pattern['severity'] ?? null) ?? 'medium',
                'recommendation' => is_string($pattern['recommendation'] ?? null)
                    ? trim($pattern['recommendation'])
                    : 'Move runtime policy to table-owned configuration.',
            ];
        }

        return $normalized;
    }

    /**
     * @return array{line_table_driven_markers:list<string>,file_table_policy_markers:list<string>}
     */
    private function classifier(mixed $classifier): array
    {
        if (! is_array($classifier)) {
            return [
                'line_table_driven_markers' => [],
                'file_table_policy_markers' => [],
            ];
        }

        return [
            'line_table_driven_markers' => $this->stringList($classifier['line_table_driven_markers'] ?? []),
            'file_table_policy_markers' => $this->stringList($classifier['file_table_policy_markers'] ?? []),
        ];
    }

    /**
     * @param  list<string>  $markers
     */
    private function containsAnyMarker(string $value, array $markers): bool
    {
        foreach ($markers as $marker) {
            if ($marker !== '' && stripos($value, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== ''
        ));
    }

    private function relativePath(string $root, string $path): string
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $root)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)));
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function cleanCode(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $code = trim((string) $value);
        if ($code === '' || preg_match('/^[A-Za-z0-9_.:-]+$/', $code) !== 1) {
            return null;
        }

        return $code;
    }
}
