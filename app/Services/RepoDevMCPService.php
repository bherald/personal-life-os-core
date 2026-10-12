<?php

namespace App\Services;

use App\Support\BoundedProcessOutput;
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
        'phpunit-pao-target',
        'phpstan-pao',
        'pint-test',
        'composer-analyse',
        'diff-check',
    ];

    public function __construct(
        private OfflinePolicyService $policy,
        private RepoEditRecoveryService $recovery,
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

    public function contextMap(string $task, ?string $path = '.', int $limit = 20): array
    {
        $policy = config('dev_agent.repo_context_map', []);
        if (! is_array($policy) || ! (bool) ($policy['enabled'] ?? false)) {
            throw new RuntimeException('Repository context mapping is disabled.');
        }

        $startedNs = hrtime(true);
        $runtimeBudgetMs = $this->clamp((int) ($policy['max_runtime_ms'] ?? 60000), 1, 120000);
        $deadlineNs = $startedNs + ($runtimeBudgetMs * 1_000_000);

        $task = trim($task);
        if (mb_strlen($task) < 3 || mb_strlen($task) > 2000) {
            throw new RuntimeException('Context-map task must contain 3 to 2000 characters.');
        }

        $terms = $this->contextMapTerms($task, $policy);
        if ($terms === []) {
            throw new RuntimeException('Context-map task does not contain any usable search terms.');
        }
        $this->contextMapAssertWithinDeadline($deadlineNs);

        $scope = $this->resolveDirectoryPath($path ?? '.', 'read');
        $maxResults = $this->clamp((int) ($policy['max_results'] ?? 20), 1, 50);
        $limit = $this->clamp($limit, 1, $maxResults);
        $maxIndexedFiles = $this->clamp((int) ($policy['max_indexed_files'] ?? 5000), 100, 20000);
        $maxFileBytes = $this->clamp((int) ($policy['max_file_bytes'] ?? 524288), 4096, 2097152);
        $maxScanBytes = $this->clamp((int) ($policy['max_scan_bytes'] ?? 33554432), 65536, 134217728);
        $maxSymbols = $this->clamp((int) ($policy['max_symbols_per_file'] ?? 12), 1, 50);
        $maxMetadataOnlyFiles = $this->clamp((int) ($policy['max_metadata_only_files'] ?? 20), 1, 100);
        $metadataOnlyMatchWeight = $this->clamp((int) ($policy['metadata_only_match_weight'] ?? 2), 1, 8);
        $metadataOnlyFrontendMatchWeight = $this->clamp(
            (int) ($policy['metadata_only_frontend_match_weight'] ?? 4),
            $metadataOnlyMatchWeight,
            8
        );
        $maxNativeMatchFileBytes = $this->clamp(
            (int) ($policy['max_native_match_file_bytes'] ?? 2097152),
            $maxFileBytes,
            8388608
        );

        $inventory = $this->contextMapFileInventory($scope, $maxIndexedFiles, $deadlineNs);
        $inventoryCompletedNs = hrtime(true);
        $tracked = array_fill_keys($inventory['files'], true);
        $pathMatches = [];
        foreach ($inventory['files'] as $file) {
            $this->contextMapAssertWithinDeadline($deadlineNs);
            $score = $this->contextMapPathScore($file, $terms);
            if ($score > 0) {
                $pathMatches[$file] = $score;
            }
        }
        $pathScoringCompletedNs = hrtime(true);

        $contentMatches = $this->contextMapRipgrepMatches($scope, $terms, $deadlineNs);
        $nativeContentSearchSource = null;
        $nativeContentMatchCounts = [];
        $nativeSymbolMatchCounts = [];
        $nativeDeclarationPrescoreApplied = false;
        $nativeDeclarationPrescoreFiles = 0;
        if ($contentMatches === null && $inventory['source'] === 'php') {
            $grepMatchCounts = $this->contextMapGrepMatchCounts(
                $inventory['files'],
                $terms,
                $deadlineNs
            );
            if ($grepMatchCounts !== null) {
                $nativeContentMatchCounts = $grepMatchCounts;
                $nativeContentSearchSource = 'grep';
                $contentMatches = array_keys(array_filter(
                    $nativeContentMatchCounts,
                    static fn (int $count): bool => $count > 0
                ));
            }
        }
        $fallbackScan = $contentMatches === null;
        $nativeFallbackScan = $fallbackScan && $inventory['source'] === 'php';
        $candidates = $fallbackScan
            ? ($nativeFallbackScan && $pathMatches !== [] ? array_keys($pathMatches) : $inventory['files'])
            : array_keys($pathMatches);
        if (is_array($contentMatches)) {
            foreach ($contentMatches as $file) {
                if (isset($tracked[$file])) {
                    $candidates[] = $file;
                }
            }
        }
        $contentMatched = array_fill_keys($contentMatches ?? [], true);
        $candidates = array_values(array_unique($candidates));
        $nativeCandidateScore = static function (string $file) use (
            $pathMatches,
            $nativeContentMatchCounts,
            &$nativeSymbolMatchCounts,
            $terms,
            $metadataOnlyMatchWeight,
            $metadataOnlyFrontendMatchWeight
        ): int {
            $pathScore = $pathMatches[$file] ?? 0;
            $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
            $contentWeight = $pathScore > 0 && in_array($extension, ['js', 'jsx', 'ts', 'tsx', 'vue'], true)
                ? $metadataOnlyFrontendMatchWeight
                : $metadataOnlyMatchWeight;
            $contentScore = min($nativeContentMatchCounts[$file] ?? 0, count($terms) * 3) * $contentWeight;
            $symbolWeight = str_starts_with($file, 'tests/') ? 3 : 8;
            $symbolScore = min($nativeSymbolMatchCounts[$file] ?? 0, count($terms)) * $symbolWeight;
            $sourcePathBias = preg_match('#^(app|config|resources|routes)/#', $file) === 1 ? 4 : 0;

            return $pathScore + $contentScore + $symbolScore + $sourcePathBias;
        };
        $sortNativeCandidates = static function (string $a, string $b) use ($nativeCandidateScore): int {
            $scoreComparison = $nativeCandidateScore($b) <=> $nativeCandidateScore($a);

            return $scoreComparison !== 0 ? $scoreComparison : strcmp($a, $b);
        };
        usort($candidates, $sortNativeCandidates);
        if ($nativeContentSearchSource === 'grep') {
            $maxNativeDeclarationCandidates = $this->clamp(
                (int) ($policy['max_native_declaration_candidates'] ?? 750),
                25,
                2000
            );
            $declarationCandidates = array_slice($candidates, 0, $maxNativeDeclarationCandidates);
            $nativeDeclarationPrescoreFiles = count(array_filter(
                $declarationCandidates,
                static fn (string $file): bool => str_ends_with(strtolower($file), '.php')
            ));
            $declarationCounts = $this->contextMapGrepDeclarationMatchCounts(
                $declarationCandidates,
                $terms,
                $deadlineNs
            );
            if ($declarationCounts !== null) {
                $nativeSymbolMatchCounts = $declarationCounts;
                $nativeDeclarationPrescoreApplied = true;
                usort($candidates, $sortNativeCandidates);
            }
        }
        $nativeContentSearchIncomplete = $nativeFallbackScan
            && $pathMatches !== []
            && count($candidates) < count($inventory['files']);
        $nativeContentFilesOmitted = $nativeContentSearchIncomplete
            ? count($inventory['files']) - count($candidates)
            : 0;
        $nativeCandidateLimitReached = false;
        $nativeCandidateFilesOmitted = 0;
        $nativePrescoreCandidateLimitApplied = false;
        $nativePrescoreFilesOmitted = 0;
        if ($nativeContentSearchSource === 'grep') {
            $maxNativeCandidates = $this->clamp((int) ($policy['max_native_candidates'] ?? 250), 25, 1000);
            $nativePrescoreFilesOmitted = max(0, count($candidates) - $maxNativeCandidates);
            $nativePrescoreCandidateLimitApplied = $nativePrescoreFilesOmitted > 0;
            $candidates = array_slice($candidates, 0, $maxNativeCandidates);
        } elseif ($nativeFallbackScan) {
            $maxNativeCandidates = $this->clamp((int) ($policy['max_native_candidates'] ?? 250), 25, 1000);
            $nativeCandidateFilesOmitted = max(0, count($candidates) - $maxNativeCandidates);
            $nativeCandidateLimitReached = $nativeCandidateFilesOmitted > 0;
            $candidates = array_slice($candidates, 0, $maxNativeCandidates);
        }
        $candidateSelectionCompletedNs = hrtime(true);

        $files = [];
        $scannedBytes = 0;
        $scannedFiles = 0;
        $skippedFiles = 0;
        $metadataOnlyFiles = 0;
        $scanTruncated = false;
        $unscannedFiles = $nativeContentFilesOmitted + $nativeCandidateFilesOmitted;
        $skipReasons = [
            'path_resolution_failed' => 0,
            'size_unavailable' => 0,
            'oversized_without_content_match' => 0,
            'metadata_only_limit_reached' => 0,
            'content_match_not_confirmed' => 0,
            'unreadable_or_binary' => 0,
        ];
        foreach ($candidates as $candidateIndex => $file) {
            $this->contextMapAssertWithinDeadline($deadlineNs);
            try {
                $resolved = $this->resolveFilePath($file, 'read', mustExist: true);
            } catch (RuntimeException) {
                $skippedFiles++;
                $skipReasons['path_resolution_failed']++;

                continue;
            }

            $size = @filesize($resolved['absolute']);
            if (! is_int($size)) {
                $skippedFiles++;
                $skipReasons['size_unavailable']++;

                continue;
            }
            if ($size > $maxFileBytes) {
                if (! isset($contentMatched[$file])) {
                    $skippedFiles++;
                    $skipReasons['oversized_without_content_match']++;

                    continue;
                }
                if ($metadataOnlyFiles >= $maxMetadataOnlyFiles) {
                    $skippedFiles++;
                    $skipReasons['metadata_only_limit_reached']++;

                    continue;
                }

                $matchOccurrences = $this->contextMapMatchCount(
                    $resolved['absolute'],
                    $terms,
                    $deadlineNs,
                    $maxNativeMatchFileBytes
                );
                if ($matchOccurrences === 0) {
                    $skippedFiles++;
                    $skipReasons['content_match_not_confirmed']++;

                    continue;
                }

                $pathScore = $pathMatches[$file] ?? 0;
                $boundedOccurrences = min($matchOccurrences, count($terms) * 3);
                $sourcePathBias = preg_match('#^(app|config|resources|routes)/#', $file) === 1 ? 4 : 0;
                $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
                $matchWeight = $pathScore > 0 && in_array($extension, ['js', 'jsx', 'ts', 'tsx', 'vue'], true)
                    ? $metadataOnlyFrontendMatchWeight
                    : $metadataOnlyMatchWeight;
                $files[] = [
                    'path' => $file,
                    'score' => $pathScore + ($boundedOccurrences * $matchWeight) + $sourcePathBias,
                    'size_bytes' => $size,
                    'selection' => [
                        'path_score' => $pathScore,
                        'content_term_matches' => 1,
                        'content_match_occurrences' => $boundedOccurrences,
                        'content_match_weight' => $matchWeight,
                        'symbol_term_matches' => 0,
                        'source_path_bias' => $sourcePathBias,
                        'metadata_only' => true,
                    ],
                    'declaration_count' => 0,
                    'declarations' => [],
                ];
                $metadataOnlyFiles++;

                continue;
            }
            if ($scannedBytes + $size > $maxScanBytes) {
                $scanTruncated = true;
                $unscannedFiles += count($candidates) - $candidateIndex;

                break;
            }

            $contents = @file_get_contents($resolved['absolute']);
            if (! is_string($contents) || str_contains(substr($contents, 0, 8192), "\0")) {
                $skippedFiles++;
                $skipReasons['unreadable_or_binary']++;

                continue;
            }
            $scannedFiles++;
            $scannedBytes += $size;

            $lowerContents = strtolower($contents);
            $pathScore = $pathMatches[$file] ?? 0;
            $contentScore = 0;
            $contentTermMatches = 0;
            foreach ($terms as $term) {
                $count = substr_count($lowerContents, $term);
                if ($count === 0) {
                    continue;
                }
                $contentTermMatches++;
                $contentScore += min($count, 3) * 2;
            }

            $declarations = str_ends_with(strtolower($file), '.php')
                ? $this->contextMapPhpDeclarations($contents)
                : [];
            $this->contextMapAssertWithinDeadline($deadlineNs);
            $matchedSymbolTerms = [];
            foreach ($declarations as $declaration) {
                $name = strtolower($declaration['name']);
                foreach ($terms as $term) {
                    if ($name !== '' && str_contains($name, $term)) {
                        $matchedSymbolTerms[$term] = true;
                        break;
                    }
                }
            }
            $symbolTermMatches = count($matchedSymbolTerms);
            $symbolScore = $symbolTermMatches * (str_starts_with($file, 'tests/') ? 3 : 8);
            $sourcePathBias = preg_match('#^(app|config|resources|routes)/#', $file) === 1 ? 4 : 0;

            $score = $pathScore + $contentScore + $symbolScore + $sourcePathBias;
            if ($score <= 0) {
                continue;
            }

            usort($declarations, static function (array $a, array $b) use ($terms): int {
                $aRelevant = self::contextMapDeclarationRelevant($a, $terms);
                $bRelevant = self::contextMapDeclarationRelevant($b, $terms);
                if ($aRelevant !== $bRelevant) {
                    return $bRelevant <=> $aRelevant;
                }

                return $a['line'] <=> $b['line'];
            });

            $files[] = [
                'path' => $file,
                'score' => $score,
                'size_bytes' => $size,
                'selection' => [
                    'path_score' => $pathScore,
                    'content_term_matches' => $contentTermMatches,
                    'symbol_term_matches' => $symbolTermMatches,
                    'source_path_bias' => $sourcePathBias,
                ],
                'declaration_count' => count($declarations),
                'declarations' => array_slice($declarations, 0, $maxSymbols),
            ];
        }

        usort($files, static function (array $a, array $b): int {
            $scoreComparison = $b['score'] <=> $a['score'];

            return $scoreComparison !== 0
                ? $scoreComparison
                : strcmp($a['path'], $b['path']);
        });
        $this->contextMapAssertWithinDeadline($deadlineNs);
        $candidateAnalysisCompletedNs = hrtime(true);
        $matchedFiles = count($files);
        $coverageReasons = [];
        if ($inventory['truncated']) {
            $coverageReasons[] = 'inventory_limit_reached';
        }
        if ($scanTruncated) {
            $coverageReasons[] = 'scan_byte_limit_reached';
        }
        if ($nativeCandidateLimitReached) {
            $coverageReasons[] = 'native_candidate_limit_reached';
        }
        if ($nativeContentSearchIncomplete) {
            $coverageReasons[] = 'native_content_search_unavailable';
        }
        if ($skippedFiles > 0) {
            $coverageReasons[] = 'candidate_files_skipped';
        }
        $coverageStatus = $inventory['truncated']
            || $scanTruncated
            || $nativeCandidateLimitReached
            || $nativeContentSearchIncomplete
            ? 'incomplete'
            : ($skippedFiles > 0 ? 'degraded' : 'complete');
        $returnedFiles = min($matchedFiles, $limit);
        $durationMs = (int) floor((hrtime(true) - $startedNs) / 1_000_000);

        return [
            'schema' => 'plos.repo_context_map.v1',
            'mode' => 'observe',
            'read_only' => true,
            'task_sha256' => hash('sha256', $task),
            'task_term_count' => count($terms),
            'scope_path' => $scope['relative'],
            'inventory_source' => $inventory['source'],
            'summary' => [
                'inventory_files' => count($inventory['files']),
                'candidate_files' => count($candidates),
                'scanned_files' => $scannedFiles,
                'scanned_bytes' => $scannedBytes,
                'skipped_files' => $skippedFiles,
                'unscanned_files' => $unscannedFiles,
                'metadata_only_files' => $metadataOnlyFiles,
                'matched_files' => $matchedFiles,
                'returned_files' => $returnedFiles,
                'runtime_budget_ms' => $runtimeBudgetMs,
                'duration_ms' => $durationMs,
                'phase_duration_ms' => [
                    'inventory' => (int) floor(($inventoryCompletedNs - $startedNs) / 1_000_000),
                    'path_scoring' => (int) floor(($pathScoringCompletedNs - $inventoryCompletedNs) / 1_000_000),
                    'content_search_and_candidate_selection' => (int) floor(
                        ($candidateSelectionCompletedNs - $pathScoringCompletedNs) / 1_000_000
                    ),
                    'candidate_analysis_and_ranking' => (int) floor(
                        ($candidateAnalysisCompletedNs - $candidateSelectionCompletedNs) / 1_000_000
                    ),
                ],
                'coverage_status' => $coverageStatus,
                'truncated' => $inventory['truncated']
                    || $scanTruncated
                    || $nativeCandidateLimitReached
                    || $nativeContentSearchIncomplete
                    || $nativePrescoreCandidateLimitApplied
                    || $matchedFiles > $limit,
            ],
            'coverage' => [
                'status' => $coverageStatus,
                'reason_codes' => $coverageReasons,
                'search_space_complete' => ! $inventory['truncated']
                    && ! $scanTruncated
                    && ! $nativeCandidateLimitReached
                    && ! $nativeContentSearchIncomplete,
                'inventory' => [
                    'source' => $inventory['source'],
                    'complete' => ! $inventory['truncated'],
                    'files_considered' => count($inventory['files']),
                    'files_discovered' => $inventory['discovered_files'],
                    'discovered_count_exact' => $inventory['count_exact'],
                    'omitted_files_minimum' => $inventory['omitted_files_minimum'],
                ],
                'candidate_analysis' => [
                    'complete' => ! $scanTruncated
                        && ! $nativeCandidateLimitReached
                        && ! $nativeContentSearchIncomplete
                        && $skippedFiles === 0,
                    'candidate_files' => count($candidates),
                    'fully_scanned_files' => $scannedFiles,
                    'metadata_only_files' => $metadataOnlyFiles,
                    'skipped_files' => $skippedFiles,
                    'unscanned_files' => $unscannedFiles,
                    'skip_reason_counts' => array_filter(
                        $skipReasons,
                        static fn (int $count): bool => $count > 0
                    ),
                ],
                'candidate_selection' => [
                    'source' => $nativeContentSearchSource === 'grep'
                        ? 'global_path_content_bounded_declaration_prescore'
                        : ($nativeFallbackScan ? 'bounded_path_only' : 'ripgrep_path_content_union'),
                    'complete' => ! $nativeContentSearchIncomplete && ! $nativeCandidateLimitReached,
                    'globally_prescored_files' => $nativeContentSearchSource === 'grep'
                        ? count($inventory['files'])
                        : null,
                    'declaration_prescored_files' => $nativeContentSearchSource === 'grep'
                        ? $nativeDeclarationPrescoreFiles
                        : null,
                    'selected_files' => count($candidates),
                    'prescored_files_omitted_from_deep_analysis' => $nativePrescoreFilesOmitted,
                ],
                'result_window' => [
                    'complete' => $matchedFiles <= $limit,
                    'matched_files' => $matchedFiles,
                    'returned_files' => $returnedFiles,
                    'omitted_files' => max(0, $matchedFiles - $limit),
                ],
            ],
            'files' => array_slice($files, 0, $limit),
            'posture' => [
                'deterministic' => true,
                'ignored_files_excluded' => $inventory['source'] !== 'php',
                'native_inventory_exclusions_applied' => $inventory['source'] === 'php',
                'native_inventory_candidate_cap_applied' => $nativeCandidateLimitReached
                    || $nativePrescoreCandidateLimitApplied,
                'native_candidate_cap_globally_prescored' => $nativePrescoreCandidateLimitApplied,
                'native_declaration_prescore_applied' => $nativeDeclarationPrescoreApplied,
                'native_content_search_source' => $nativeContentSearchSource,
                'native_content_search_complete' => ! $nativeContentSearchIncomplete,
                'path_policy_enforced' => true,
                'raw_task_included' => false,
                'source_bodies_included' => false,
                'oversized_matches_metadata_only' => true,
                'coverage_claims_complete_only_when_proven' => true,
                'runtime_deadline_enforced' => true,
                'partial_results_returned_after_deadline' => false,
                'supplemental_context_selection_not_prompt_compression' => true,
                'llm_calls' => false,
                'network_calls' => false,
                'database_reads' => false,
                'database_writes' => false,
            ],
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
        return $this->recovery->locked(
            fn (): array => $this->writeRepoFileUnlocked($path, $content, $createDirectories)
        );
    }

    private function writeRepoFileUnlocked(string $path, string $content, bool $createDirectories): array
    {
        $resolved = $this->resolveFilePath($path, 'write', mustExist: false);
        $recoveryFiles = [['path' => $resolved['relative'], 'absolute' => $resolved['absolute']]];
        $recoveryContext = $this->recovery->capture($recoveryFiles);
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

        $recovery = $this->recovery->finalize($recoveryContext, $recoveryFiles);
        $diagnostics = $this->runPostEditDiagnostics([$resolved['relative']]);

        return [
            'repo_root' => base_path(),
            'path' => $resolved['relative'],
            'bytes_written' => $bytes,
            'created' => ! $existing,
            'recovery' => $recovery,
            'change_summary' => $this->recoverySummary($recovery),
            'diagnostics' => $diagnostics,
        ];
    }

    public function applyRepoPatch(string $patch, bool $checkOnly = false): array
    {
        return $this->recovery->locked(
            fn (): array => $this->applyRepoPatchUnlocked($patch, $checkOnly)
        );
    }

    private function applyRepoPatchUnlocked(string $patch, bool $checkOnly): array
    {
        $patch = trim($patch);
        if ($patch === '') {
            throw new RuntimeException('Patch cannot be empty.');
        }

        $paths = $this->extractPatchPaths($patch);
        if ($paths === []) {
            throw new RuntimeException('Patch does not contain any repo file paths.');
        }

        $recoveryFiles = [];
        foreach ($paths as $path) {
            $resolved = $this->resolveFilePath($path, 'write', mustExist: false);
            $recoveryFiles[] = ['path' => $resolved['relative'], 'absolute' => $resolved['absolute']];
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
                    'recovery' => $this->recovery->unavailable('patch_check_failed'),
                    'change_summary' => $this->summaryNotAvailable('patch_check_failed'),
                    'diagnostics' => $this->diagnosticsNotRun('patch_check_failed'),
                ];
            }

            $apply = null;
            $recoveryContext = $checkOnly ? null : $this->recovery->capture($recoveryFiles);
            if (! $checkOnly) {
                $apply = $this->runProcess(['git', 'apply', '--whitespace=nowarn', $tmp], 30);
            }
            $applySucceeded = $apply !== null && $apply['success'];

            $recovery = $checkOnly
                ? $this->recovery->unavailable('check_only')
                : ($applySucceeded && is_array($recoveryContext)
                    ? $this->recovery->finalize($recoveryContext, $recoveryFiles)
                    : $this->recovery->unavailable('patch_apply_failed'));

            $diagnostics = $checkOnly
                ? $this->diagnosticsNotRun('check_only')
                : ($applySucceeded
                    ? $this->runPostEditDiagnostics($paths)
                    : $this->diagnosticsNotRun('patch_apply_failed'));

            return [
                'applied' => ! $checkOnly && $applySucceeded,
                'check_only' => $checkOnly,
                'paths' => $paths,
                'check' => $check,
                'apply' => $apply,
                'recovery' => $recovery,
                'change_summary' => $this->recoverySummary($recovery),
                'diagnostics' => $diagnostics,
            ];
        } finally {
            @unlink($tmp);
        }
    }

    public function restoreRepoEdit(string $token, bool $checkOnly = false): array
    {
        return $this->recovery->locked(
            fn (): array => $this->restoreRepoEditUnlocked($token, $checkOnly)
        );
    }

    private function restoreRepoEditUnlocked(string $token, bool $checkOnly): array
    {
        $packet = $this->recovery->load(trim($token));
        $absolutePaths = [];
        foreach ($packet['files'] as $file) {
            if (! is_array($file) || ! is_string($file['path'] ?? null)) {
                throw new RuntimeException('Recovery packet is invalid.');
            }
            $resolved = $this->resolveFilePath($file['path'], 'write', mustExist: false);
            $absolutePaths[$resolved['relative']] = $resolved['absolute'];
        }

        $result = $this->recovery->restore(trim($token), $absolutePaths, $checkOnly);
        $result['diagnostics'] = ($result['restored'] ?? false)
            ? $this->runPostEditDiagnostics($result['paths'])
            : $this->diagnosticsNotRun((string) ($result['reason'] ?? ($checkOnly ? 'check_only' : 'not_restored')));

        return $result;
    }

    /**
     * Return the net content-free effect of ordered, still-valid repo edit tokens.
     *
     * @param  list<string>  $tokens
     * @return array<string, mixed>
     */
    public function summarizeRepoEdits(array $tokens): array
    {
        return $this->recovery->locked(
            fn (): array => $this->recovery->summarize($tokens)
        );
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
            'phpunit-pao-target' => $this->targetedPaoPhpunitCommand($target, $filter),
            'phpstan-pao' => $this->phpstanPaoCommand($target),
        };

        $env = str_contains($runner, 'pao') ? $this->paoEnvironment() : [];
        $result = $this->runProcess($command, $timeoutSeconds, $env);

        return [
            'runner' => $runner,
            'target' => $target,
            'filter' => $filter,
            'command' => $command,
            'env' => $env === [] ? [] : ['AI_AGENT' => 'codex', 'CODEX_CI' => '1'],
            'pao_summary' => str_contains($runner, 'pao') ? $this->extractPaoSummary($result) : null,
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

        foreach (Route::getRoutes()->getRoutes() as $route) {
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
            return strcmp($a['uri'], $b['uri']);
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
     * @return list<string>
     */
    private function targetedPaoPhpunitCommand(?string $target, ?string $filter): array
    {
        $target = trim((string) $target);
        if ($target === '') {
            throw new RuntimeException('phpunit-pao-target requires a test file or directory target.');
        }

        $resolved = is_dir(base_path($target))
            ? $this->resolveDirectoryPath($target, 'read')
            : $this->resolveFilePath($target, 'read', mustExist: true);

        if ($resolved['relative'] !== 'tests' && ! str_starts_with($resolved['relative'], 'tests/')) {
            throw new RuntimeException('phpunit-pao-target may only run paths under tests/.');
        }

        $this->requireExecutable('./vendor/bin/phpunit', 'phpunit-pao-target requires vendor/bin/phpunit.');
        $command = ['./vendor/bin/phpunit', $resolved['relative']];
        $filter = trim((string) $filter);
        if ($filter !== '') {
            if (strlen($filter) > 200) {
                throw new RuntimeException('phpunit-pao-target filter is too long.');
            }
            $command[] = '--filter='.$filter;
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    private function phpstanPaoCommand(?string $target): array
    {
        $this->requireExecutable('./vendor/bin/phpstan', 'phpstan-pao requires vendor/bin/phpstan.');
        $command = ['./vendor/bin/phpstan', 'analyse', '--no-progress'];
        $target = trim((string) $target);
        if ($target !== '') {
            $resolved = is_dir(base_path($target))
                ? $this->resolveDirectoryPath($target, 'read')
                : $this->resolveFilePath($target, 'read', mustExist: true);

            if (! $this->isPhpstanTarget($resolved['relative'])) {
                throw new RuntimeException('phpstan-pao target must be a repo source, config, route, database, resource, or test path.');
            }

            $command[] = $resolved['relative'];
        }

        return $command;
    }

    private function requireExecutable(string $relativePath, string $message): void
    {
        $absolute = base_path(ltrim($relativePath, './'));
        if (! is_file($absolute) || ! is_executable($absolute)) {
            throw new RuntimeException($message);
        }
    }

    private function isPhpstanTarget(string $relative): bool
    {
        return $relative === 'phpstan.neon'
            || $relative === 'app'
            || $relative === 'tests'
            || str_starts_with($relative, 'app/')
            || str_starts_with($relative, 'tests/')
            || str_starts_with($relative, 'config/')
            || str_starts_with($relative, 'routes/')
            || str_starts_with($relative, 'database/')
            || str_starts_with($relative, 'resources/');
    }

    /**
     * @return array<string, string>
     */
    private function paoEnvironment(): array
    {
        return [
            'AI_AGENT' => 'codex',
            'CODEX_CI' => '1',
        ];
    }

    /**
     * @param  array{output:string,error:string}  $result
     * @return array<string, mixed>|null
     */
    private function extractPaoSummary(array $result): ?array
    {
        $combined = trim($result['output']."\n".$result['error']);
        if ($combined === '') {
            return null;
        }

        $lines = array_reverse(preg_split('/\r?\n/', $combined) ?: []);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, '{')) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }

            return array_intersect_key($decoded, array_flip([
                'result',
                'tests',
                'passed',
                'failed',
                'errors',
                'skipped',
                'duration_ms',
                'totals',
                'files',
            ]));
        }

        return null;
    }

    /**
     * @param  list<string>  $command
     * @return array{success:bool,exit_code:int|null,duration_ms:int,output:string,error:string}
     */
    private function runProcess(array $command, int $timeoutSeconds, array $env = []): array
    {
        $started = microtime(true);
        $process = Process::path(base_path())
            ->env($env)
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

    private function truncateOutput(string $output, ?int $limit = null): string
    {
        return BoundedProcessOutput::format(
            $output,
            $limit ?? (int) config('dev_agent.process_output.max_bytes', 12000),
            (int) config('dev_agent.process_output.head_percent', 67),
        );
    }

    /**
     * Run configured, extension-scoped diagnostics after repository edits.
     *
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function runPostEditDiagnostics(array $paths): array
    {
        $config = config('dev_agent.post_edit_diagnostics', []);
        if (! is_array($config) || ! (bool) ($config['enabled'] ?? false)) {
            return [
                'status' => 'disabled',
                'reason' => 'disabled',
                'checked_files' => 0,
                'failed_files' => 0,
                'skipped_files' => count(array_unique($paths)),
                'truncated' => false,
                'results' => [],
            ];
        }

        $runners = is_array($config['runners'] ?? null) ? $config['runners'] : [];
        $maxFiles = $this->clamp((int) ($config['max_files'] ?? 20), 1, 100);
        $timeoutSeconds = $this->clamp((int) ($config['timeout_seconds'] ?? 15), 1, 120);
        $paths = array_values(array_unique(array_filter(
            array_map(static fn (mixed $path): string => trim((string) $path), $paths),
            static fn (string $path): bool => $path !== ''
        )));

        $truncated = count($paths) > $maxFiles;
        $results = [];
        $failedFiles = 0;
        $skippedFiles = 0;

        foreach (array_slice($paths, 0, $maxFiles) as $path) {
            $resolved = $this->resolveFilePath($path, 'write', mustExist: false);
            if (! is_file($resolved['absolute'])) {
                $skippedFiles++;

                continue;
            }

            $extension = strtolower((string) pathinfo($resolved['relative'], PATHINFO_EXTENSION));
            $matched = null;
            foreach ($runners as $name => $runner) {
                if (! is_array($runner)) {
                    continue;
                }

                $extensions = array_map(
                    static fn (mixed $value): string => strtolower(ltrim(trim((string) $value), '.')),
                    is_array($runner['extensions'] ?? null) ? $runner['extensions'] : []
                );
                if (in_array($extension, $extensions, true)) {
                    $matched = ['name' => (string) $name, 'config' => $runner];

                    break;
                }
            }

            if ($matched === null) {
                $skippedFiles++;

                continue;
            }

            $command = $this->postEditDiagnosticCommand($matched['name'], $resolved['relative']);
            if ($command === null) {
                $results[] = [
                    'path' => $resolved['relative'],
                    'runner' => $matched['name'],
                    'success' => false,
                    'exit_code' => null,
                    'duration_ms' => 0,
                    'output' => '',
                    'error' => 'Diagnostic runner is not allowlisted.',
                ];
                $failedFiles++;

                continue;
            }

            try {
                $result = $this->runProcess($command, $timeoutSeconds);
            } catch (\Throwable $e) {
                $result = [
                    'success' => false,
                    'exit_code' => null,
                    'duration_ms' => 0,
                    'output' => '',
                    'error' => $this->truncateOutput($e->getMessage()),
                ];
            }

            $results[] = ['path' => $resolved['relative'], 'runner' => $matched['name']] + $result;
            if (! $result['success']) {
                $failedFiles++;
            }
        }

        return [
            'status' => $results === [] ? 'not_applicable' : ($failedFiles > 0 ? 'failed' : 'passed'),
            'reason' => null,
            'checked_files' => count($results),
            'failed_files' => $failedFiles,
            'skipped_files' => $skippedFiles + max(0, count($paths) - $maxFiles),
            'truncated' => $truncated,
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnosticsNotRun(string $reason): array
    {
        return [
            'status' => 'not_run',
            'reason' => $reason,
            'checked_files' => 0,
            'failed_files' => 0,
            'skipped_files' => 0,
            'truncated' => false,
            'results' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $recovery
     * @return array<string, mixed>
     */
    private function recoverySummary(array $recovery): array
    {
        $token = $recovery['token'] ?? null;
        if (! ($recovery['available'] ?? false) || ! is_string($token)) {
            return $this->summaryNotAvailable((string) ($recovery['reason'] ?? 'recovery_unavailable'));
        }

        return $this->recovery->summarize([$token]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryNotAvailable(string $reason): array
    {
        return [
            'schema' => 'plos.repo_edit_summary.v1',
            'status' => 'not_available',
            'reason' => $reason,
            'operation_count' => 0,
            'files_changed' => 0,
            'additions' => 0,
            'deletions' => 0,
            'binary_files' => 0,
            'line_stats_unavailable_files' => 0,
            'line_stats_complete' => false,
            'files' => [],
            'posture' => [
                'content_included' => false,
                'recovery_tokens_included' => false,
                'write_authority_changed' => false,
            ],
        ];
    }

    /** @return list<string>|null */
    private function postEditDiagnosticCommand(string $runner, string $path): ?array
    {
        return match ($runner) {
            'php-syntax' => [PHP_BINARY, '-l', './'.ltrim($path, '/')],
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return list<string>
     */
    private function contextMapTerms(string $task, array $policy): array
    {
        $expanded = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $task) ?? $task;
        $parts = preg_split('/[^A-Za-z0-9]+/', strtolower($expanded)) ?: [];
        $stopWords = array_fill_keys(array_map(
            static fn (mixed $word): string => strtolower(trim((string) $word)),
            is_array($policy['stop_words'] ?? null) ? $policy['stop_words'] : []
        ), true);
        $maxTerms = $this->clamp((int) ($policy['max_terms'] ?? 12), 1, 24);
        $terms = [];
        foreach ($parts as $part) {
            if (strlen($part) < 3 || isset($stopWords[$part]) || in_array($part, $terms, true)) {
                continue;
            }
            $terms[] = $part;
            if (count($terms) >= $maxTerms) {
                break;
            }
        }

        return $terms;
    }

    /**
     * @param  array{absolute:string,relative:string}  $scope
     * @return array{files:list<string>,truncated:bool,source:string,discovered_files:int,count_exact:bool,omitted_files_minimum:int}
     */
    private function contextMapFileInventory(array $scope, int $limit, int $deadlineNs): array
    {
        $path = $scope['relative'] === '.' ? '.' : $scope['relative'];
        $result = Process::path(base_path())
            ->timeout($this->contextMapProcessTimeoutSeconds($deadlineNs, 15))
            ->run(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '--', $path]);
        $this->contextMapAssertWithinDeadline($deadlineNs);
        $files = [];
        $source = 'git';
        if ($result->successful()) {
            foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $file) {
                $file = trim($file);
                if ($file !== '') {
                    $files[] = $file;
                }
            }
        } else {
            $fallback = Process::path($scope['absolute'])
                ->timeout($this->contextMapProcessTimeoutSeconds($deadlineNs, 15))
                ->run([
                    'rg', '--files', '--hidden',
                    '--glob=!.git/**', '--glob=!vendor/**', '--glob=!node_modules/**',
                ]);
            $this->contextMapAssertWithinDeadline($deadlineNs);
            if ($fallback->successful()) {
                $source = 'ripgrep';
                foreach (preg_split('/\r?\n/', trim($fallback->output())) ?: [] as $file) {
                    $file = trim($file);
                    if ($file !== '') {
                        $files[] = $this->joinRelativePath($scope['relative'], $file);
                    }
                }
            } else {
                return $this->contextMapNativeFileInventory($scope, $limit, $deadlineNs);
            }
        }

        $files = array_values(array_unique($files));
        sort($files);
        $discoveredFiles = count($files);
        $truncated = $discoveredFiles > $limit;

        return [
            'files' => array_slice($files, 0, $limit),
            'truncated' => $truncated,
            'source' => $source,
            'discovered_files' => $discoveredFiles,
            'count_exact' => true,
            'omitted_files_minimum' => max(0, $discoveredFiles - $limit),
        ];
    }

    /**
     * @param  array{absolute:string,relative:string}  $scope
     * @return array{files:list<string>,truncated:bool,source:string,discovered_files:int,count_exact:bool,omitted_files_minimum:int}
     */
    private function contextMapNativeFileInventory(array $scope, int $limit, int $deadlineNs): array
    {
        $files = [];
        $directories = [$scope['absolute']];
        for ($index = 0; $index < count($directories); $index++) {
            $this->contextMapAssertWithinDeadline($deadlineNs);
            $directory = $directories[$index];
            $entries = @scandir($directory);
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                $this->contextMapAssertWithinDeadline($deadlineNs);
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $absolute = $directory.DIRECTORY_SEPARATOR.$entry;
                $relative = str_replace('\\', '/', ltrim(substr($absolute, strlen(base_path())), DIRECTORY_SEPARATOR));
                if (is_link($absolute)) {
                    continue;
                }
                if (is_dir($absolute)) {
                    if (! $this->contextMapNativePathExcluded($relative, true)) {
                        $directories[] = $absolute;
                    }

                    continue;
                }
                if (! is_file($absolute) || $this->contextMapNativePathExcluded($relative, false)) {
                    continue;
                }

                $files[] = $relative;
                if (count($files) > $limit) {
                    sort($files);

                    return [
                        'files' => array_slice($files, 0, $limit),
                        'truncated' => true,
                        'source' => 'php',
                        'discovered_files' => count($files),
                        'count_exact' => false,
                        'omitted_files_minimum' => 1,
                    ];
                }
            }
        }

        sort($files);

        return [
            'files' => $files,
            'truncated' => false,
            'source' => 'php',
            'discovered_files' => count($files),
            'count_exact' => true,
            'omitted_files_minimum' => 0,
        ];
    }

    private function contextMapNativePathExcluded(string $relative, bool $directory): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        $segments = explode('/', $relative);
        $excludedSegments = array_values(array_filter(array_map(
            static fn (mixed $segment): string => trim((string) $segment),
            (array) config('dev_agent.repo_context_map.native_excluded_segments', [
                '.git',
                '.venv',
                '__pycache__',
                'node_modules',
                'vendor',
            ])
        ), static fn (string $segment): bool => $segment !== '' && ! str_contains($segment, '/')));
        if (array_intersect($segments, $excludedSegments) !== []) {
            return true;
        }

        $excludedPaths = config('dev_agent.repo_context_map.native_excluded_paths', []);
        foreach (is_array($excludedPaths) ? $excludedPaths : [] as $excludedPath) {
            $excludedPath = trim(str_replace('\\', '/', (string) $excludedPath), '/');
            if ($excludedPath !== '' && ($relative === $excludedPath || str_starts_with($relative, $excludedPath.'/'))) {
                return true;
            }
        }

        if (! $directory) {
            $basename = basename($relative);

            return ($basename === '.env' || str_starts_with($basename, '.env.')) && $basename !== '.env.example';
        }

        return false;
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>|null
     */
    private function contextMapRipgrepMatches(array $scope, array $terms, int $deadlineNs): ?array
    {
        $command = ['rg', '--files-with-matches', '--ignore-case', '--fixed-strings', '--hidden', '--no-messages'];
        foreach ($terms as $term) {
            $command[] = '-e';
            $command[] = $term;
        }
        $command[] = '--';
        $command[] = '.';

        $result = Process::path($scope['absolute'])
            ->timeout($this->contextMapProcessTimeoutSeconds($deadlineNs, 20))
            ->run($command);
        $this->contextMapAssertWithinDeadline($deadlineNs);
        if (! $result->successful() && $result->exitCode() !== 1) {
            return null;
        }

        $files = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $file) {
            $file = preg_replace('#^\./#', '', trim($file)) ?? trim($file);
            if ($file !== '') {
                $files[] = $this->joinRelativePath($scope['relative'], $file);
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $terms
     * @return array<string,int>|null
     */
    private function contextMapGrepMatchCounts(array $files, array $terms, int $deadlineNs): ?array
    {
        if ($files === []) {
            return [];
        }

        $command = [
            'grep', '--with-filename', '--count', '--ignore-case', '--fixed-strings',
            '--binary-files=without-match', '--null',
        ];
        foreach ($terms as $term) {
            $command[] = '-e';
            $command[] = $term;
        }
        $command[] = '--';
        array_push($command, ...$files);

        $result = Process::path(base_path())
            ->timeout($this->contextMapProcessTimeoutSeconds($deadlineNs, 20))
            ->run($command);
        $this->contextMapAssertWithinDeadline($deadlineNs);
        if (! $result->successful() && $result->exitCode() !== 1) {
            return null;
        }

        return $this->contextMapParseGrepCounts($result->output());
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $terms
     * @return array<string,int>|null
     */
    private function contextMapGrepDeclarationMatchCounts(array $files, array $terms, int $deadlineNs): ?array
    {
        $phpFiles = array_values(array_filter(
            $files,
            static fn (string $file): bool => str_ends_with(strtolower($file), '.php')
        ));
        if ($phpFiles === []) {
            return [];
        }

        $termPattern = implode('|', array_map(
            static fn (string $term): string => preg_quote($term, '/'),
            $terms
        ));
        $command = [
            'grep', '--with-filename', '--count', '--ignore-case', '--extended-regexp',
            '--binary-files=without-match', '--null',
            '-e', '(class|interface|trait|enum|function)[^[:cntrl:]]*('.$termPattern.')',
            '--',
        ];
        array_push($command, ...$phpFiles);

        $result = Process::path(base_path())
            ->timeout($this->contextMapProcessTimeoutSeconds($deadlineNs, 20))
            ->run($command);
        $this->contextMapAssertWithinDeadline($deadlineNs);
        if (! $result->successful() && $result->exitCode() !== 1) {
            return null;
        }

        return $this->contextMapParseGrepCounts($result->output());
    }

    /** @return array<string,int> */
    private function contextMapParseGrepCounts(string $output): array
    {
        $matches = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            [$file, $count] = array_pad(explode("\0", $line, 2), 2, null);
            $file = preg_replace('#^\./#', '', trim((string) $file)) ?? trim((string) $file);
            if ($file !== '' && is_string($count) && ctype_digit($count)) {
                $matches[$file] = (int) $count;
            }
        }

        return $matches;
    }

    /** @param list<string> $terms */
    private function contextMapMatchCount(
        string $absolute,
        array $terms,
        int $deadlineNs,
        int $maxNativeBytes
    ): int {
        $command = ['rg', '--count-matches', '--ignore-case', '--fixed-strings', '--no-messages'];
        foreach ($terms as $term) {
            $command[] = '-e';
            $command[] = $term;
        }
        $command[] = '--';
        $command[] = $absolute;

        $result = Process::path(base_path())
            ->timeout($this->contextMapProcessTimeoutSeconds($deadlineNs, 5))
            ->run($command);
        $this->contextMapAssertWithinDeadline($deadlineNs);
        if ($result->successful()) {
            $output = trim($result->output());
            $lastSeparator = strrpos($output, ':');
            $count = $lastSeparator === false ? $output : substr($output, $lastSeparator + 1);

            return ctype_digit($count) ? (int) $count : 0;
        }

        $size = @filesize($absolute);
        if (! is_int($size) || $size > $maxNativeBytes) {
            return 0;
        }

        $contents = @file_get_contents($absolute);
        if (! is_string($contents) || str_contains(substr($contents, 0, 8192), "\0")) {
            return 0;
        }

        $this->contextMapAssertWithinDeadline($deadlineNs);
        $contents = strtolower($contents);
        $count = 0;
        foreach ($terms as $term) {
            $count += substr_count($contents, $term);
        }

        return $count;
    }

    private function contextMapAssertWithinDeadline(int $deadlineNs): void
    {
        if (hrtime(true) < $deadlineNs) {
            return;
        }

        throw new RuntimeException(
            'Repository context map exceeded its runtime budget; narrow the task or path and retry.'
        );
    }

    private function contextMapProcessTimeoutSeconds(int $deadlineNs, int $maximumSeconds): int
    {
        $this->contextMapAssertWithinDeadline($deadlineNs);
        $remainingNs = $deadlineNs - hrtime(true);
        $remainingSeconds = (int) ceil($remainingNs / 1_000_000_000);

        return max(1, min($maximumSeconds, $remainingSeconds));
    }

    /** @param list<string> $terms */
    private function contextMapPathScore(string $file, array $terms): int
    {
        $path = strtolower($file);
        $basename = strtolower(pathinfo($file, PATHINFO_FILENAME));
        $score = 0;
        foreach ($terms as $term) {
            if (str_contains($path, $term)) {
                $score += 4;
            }
            if (str_contains($basename, $term)) {
                $score += 6;
            }
        }

        return $score;
    }

    /** @return list<array{kind:string,name:string,line:int}> */
    private function contextMapPhpDeclarations(string $contents): array
    {
        $tokens = token_get_all($contents);
        $namespace = '';
        $declarations = [];
        $typeTokens = [T_CLASS => 'class', T_INTERFACE => 'interface', T_TRAIT => 'trait'];
        if (defined('T_ENUM')) {
            $typeTokens[T_ENUM] = 'enum';
        }

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->contextMapNamespace($tokens, $index + 1);

                continue;
            }

            $kind = $typeTokens[$token[0]] ?? ($token[0] === T_FUNCTION ? 'callable' : null);
            if ($kind === null) {
                continue;
            }
            if ($token[0] === T_CLASS && $this->contextMapPreviousSignificantToken($tokens, $index - 1) === T_DOUBLE_COLON) {
                continue;
            }
            $name = $this->contextMapNextTokenString($tokens, $index + 1);
            if ($name === null) {
                continue;
            }
            if ($kind !== 'callable' && $namespace !== '') {
                $name = $namespace.'\\'.$name;
            }
            $declarations[] = ['kind' => $kind, 'name' => $name, 'line' => (int) $token[2]];
        }

        return $declarations;
    }

    /** @param list<array|string> $tokens */
    private function contextMapNamespace(array $tokens, int $start): string
    {
        $namespace = '';
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_string($token) && ($token === ';' || $token === '{')) {
                break;
            }
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $namespace .= $token[1];
            }
        }

        return trim($namespace, '\\');
    }

    /** @param list<array|string> $tokens */
    private function contextMapNextTokenString(array $tokens, int $start): ?string
    {
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_string($token) && in_array($token, ['(', '{', ';'], true)) {
                return null;
            }
            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
        }

        return null;
    }

    /** @param list<array|string> $tokens */
    private function contextMapPreviousSignificantToken(array $tokens, int $start): int|string|null
    {
        for ($index = $start; $index >= 0; $index--) {
            $token = $tokens[$index];
            if (is_string($token)) {
                if (trim($token) !== '') {
                    return $token;
                }

                continue;
            }
            if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $token[0];
            }
        }

        return null;
    }

    /** @param array<string,mixed> $declaration @param list<string> $terms */
    private static function contextMapDeclarationRelevant(array $declaration, array $terms): int
    {
        $name = strtolower((string) ($declaration['name'] ?? ''));
        foreach ($terms as $term) {
            if ($name !== '' && str_contains($name, $term)) {
                return 1;
            }
        }

        return 0;
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
