<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class CodeQualityService
{
    /**
     * Directories to scan by default.
     */
    private const SCAN_DIRS = [
        'app/Services/',
        'app/Controllers/',
        'app/Console/Commands/',
    ];

    /**
     * Directories to always exclude.
     */
    private const EXCLUDE_DIRS = [
        'database/migrations',
        'tests',
        'config',
        'vendor',
        'storage',
        'node_modules',
    ];

    /**
     * PostgreSQL tables that require pgsql_rag connection.
     */
    private const PGSQL_TABLES = [
        'rag_documents',
        'rag_sentence_embeddings',
        'rag_evaluations',
        'raptor_summaries',
        'claims',
        'evidence',
        'verdicts',
        'research_sources',
        'research_topics',
        'research_results',
        'research_rejections',
    ];

    /**
     * Run pattern compliance checks across the codebase.
     *
     * @param string|null $path Optional specific path to scan
     * @return array Violations, summary, and file counts
     */
    public function checkPatternCompliance(?string $path = null): array
    {
        $violations = [];
        $filesScanned = 0;
        $cleanFiles = 0;

        $files = $this->collectFiles($path);

        foreach ($files as $file) {
            $filesScanned++;
            $fileViolations = $this->scanFile($file);

            if (empty($fileViolations)) {
                $cleanFiles++;
            } else {
                $violations = array_merge($violations, $fileViolations);
            }
        }

        $summary = $this->buildSummary($violations, $filesScanned, $cleanFiles);

        return [
            'violations' => $violations,
            'summary' => $summary,
            'files_scanned' => $filesScanned,
            'clean_files' => $cleanFiles,
        ];
    }

    /**
     * Collect PHP files to scan.
     */
    private function collectFiles(?string $path): array
    {
        $basePath = base_path();
        $files = [];

        if ($path !== null) {
            $fullPath = str_starts_with($path, '/') ? $path : $basePath . '/' . $path;

            if (is_file($fullPath) && str_ends_with($fullPath, '.php')) {
                return [$fullPath];
            }

            if (is_dir($fullPath)) {
                return $this->scanDirectory($fullPath);
            }

            return [];
        }

        foreach (self::SCAN_DIRS as $dir) {
            $fullDir = $basePath . '/' . $dir;
            if (is_dir($fullDir)) {
                $files = array_merge($files, $this->scanDirectory($fullDir));
            }
        }

        return $files;
    }

    /**
     * Recursively collect PHP files from a directory.
     */
    private function scanDirectory(string $dir): array
    {
        $files = [];

        foreach (self::EXCLUDE_DIRS as $excluded) {
            if (str_contains($dir, '/' . $excluded . '/') || str_ends_with($dir, '/' . $excluded)) {
                return [];
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            $skip = false;
            foreach (self::EXCLUDE_DIRS as $excluded) {
                if (str_contains($path, '/' . $excluded . '/')) {
                    $skip = true;
                    break;
                }
            }

            if (!$skip) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * Scan a single file for all rule violations.
     */
    private function scanFile(string $filePath): array
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        $lines = explode("\n", $content);
        $violations = [];
        $relativePath = $this->relativePath($filePath);

        foreach ($lines as $lineNum => $line) {
            $lineNumber = $lineNum + 1;
            $trimmed = trim($line);

            // Skip comments and empty lines
            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            // Rule 1: Eloquent/QueryBuilder violations (HIGH)
            $eloquentPatterns = [
                '::where(' => 'Eloquent ::where() — use DB::select() with raw SQL',
                '::find(' => 'Eloquent ::find() — use DB::select() with raw SQL',
                '::findOrFail(' => 'Eloquent ::findOrFail() — use DB::select() with raw SQL',
                '::all()' => 'Eloquent ::all() — use DB::select() with raw SQL',
                '->save()' => 'Eloquent ->save() — use DB::insert/update() with raw SQL',
                'DB::table(' => 'QueryBuilder DB::table() — use DB::select/insert/update/delete() with raw SQL',
                '->belongsTo(' => 'Eloquent relationship definition',
                '->hasMany(' => 'Eloquent relationship definition',
                '->hasOne(' => 'Eloquent relationship definition',
                '->belongsToMany(' => 'Eloquent relationship definition',
            ];

            foreach ($eloquentPatterns as $pattern => $message) {
                if (str_contains($line, $pattern)) {
                    // Exclude string literals and comments containing these patterns
                    if ($this->isInStringOrComment($line, $pattern)) {
                        continue;
                    }
                    $violations[] = [
                        'file' => $relativePath,
                        'line' => $lineNumber,
                        'rule' => 'eloquent_usage',
                        'severity' => 'high',
                        'snippet' => trim($line),
                        'message' => $message,
                    ];
                }
            }

            // Rule 2: SQL injection risks (CRITICAL)
            if (preg_match('/DB::(select|insert|update|delete|statement)\s*\(/', $line)) {
                // Check for string concatenation in SQL
                if (preg_match('/\.\s*\$/', $line) && !str_contains($line, 'json_encode')) {
                    $violations[] = [
                        'file' => $relativePath,
                        'line' => $lineNumber,
                        'rule' => 'sql_injection_risk',
                        'severity' => 'critical',
                        'snippet' => trim($line),
                        'message' => 'Possible SQL injection: variable concatenation in DB query',
                    ];
                }
            }

            // Check DB::raw() with variables
            if (str_contains($line, 'DB::raw(') && preg_match('/DB::raw\([^)]*\$/', $line)) {
                $violations[] = [
                    'file' => $relativePath,
                    'line' => $lineNumber,
                    'rule' => 'sql_injection_risk',
                    'severity' => 'critical',
                    'snippet' => trim($line),
                    'message' => 'Possible SQL injection: variable in DB::raw()',
                ];
            }

            // Rule 3: Wrong DB connection for PostgreSQL tables (HIGH)
            foreach (self::PGSQL_TABLES as $table) {
                if (preg_match("/['\"]" . preg_quote($table, '/') . "['\"]/", $line)) {
                    // Skip array/constant definitions (e.g. this service's own PGSQL_TABLES)
                    if (preg_match('/^\s*[\'"]' . preg_quote($table, '/') . '[\'"],?\s*$/', $trimmed)) {
                        continue;
                    }

                    // Check if this line or nearby context uses pgsql_rag connection
                    $contextStart = max(0, $lineNum - 10);
                    $contextSlice = array_slice($lines, $contextStart, 20);
                    $context = implode("\n", $contextSlice);

                    if (!str_contains($context, 'pgsql_rag') && !str_contains($context, 'pgsql')) {
                        $violations[] = [
                            'file' => $relativePath,
                            'line' => $lineNumber,
                            'rule' => 'wrong_db_connection',
                            'severity' => 'high',
                            'snippet' => trim($line),
                            'message' => "PostgreSQL table '{$table}' used without pgsql_rag connection",
                        ];
                    }
                }
            }

            // Rule 4: Constructor injection instead of lazy loading (MEDIUM)
            if (preg_match('/public\s+function\s+__construct\s*\(/', $line)) {
                // Check if constructor has typed service parameters
                $constructorBlock = $this->extractMethodSignature($lines, $lineNum);
                if (preg_match('/\b[A-Z][a-zA-Z]+Service\s+\$/', $constructorBlock)) {
                    $violations[] = [
                        'file' => $relativePath,
                        'line' => $lineNumber,
                        'rule' => 'constructor_injection',
                        'severity' => 'medium',
                        'snippet' => trim($constructorBlock),
                        'message' => 'Constructor injection detected — PLOS uses lazy-loaded getters',
                    ];
                }
            }

            // Rule 5: Hardcoded credentials (CRITICAL)
            if (preg_match("/(password|api_key|secret|token)\s*=\s*['\"][^'\"]{4,}['\"]/i", $line)) {
                // Exclude config files, test data, and documentation strings
                if (!preg_match("/(config|env|getenv|Config::get|->get\()/", $line)
                    && !str_contains($relativePath, 'test')
                    && !str_contains($relativePath, 'Test')) {
                    $violations[] = [
                        'file' => $relativePath,
                        'line' => $lineNumber,
                        'rule' => 'hardcoded_credentials',
                        'severity' => 'critical',
                        'snippet' => trim($line),
                        'message' => 'Possible hardcoded credential — use config/env instead',
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * Check if a pattern appears inside a string literal or comment context.
     */
    private function isInStringOrComment(string $line, string $pattern): bool
    {
        $pos = strpos($line, $pattern);
        if ($pos === false) {
            return false;
        }

        // Check if inside a single-quoted string containing the pattern as description text
        $beforePattern = substr($line, 0, $pos);

        // Count unescaped quotes before the pattern - if odd, we're inside a string
        $singleQuotes = substr_count($beforePattern, "'") - substr_count($beforePattern, "\\'");
        $doubleQuotes = substr_count($beforePattern, '"') - substr_count($beforePattern, '\\"');

        return ($singleQuotes % 2 === 1) || ($doubleQuotes % 2 === 1);
    }

    /**
     * Extract a method signature spanning multiple lines.
     */
    private function extractMethodSignature(array $lines, int $startLine): string
    {
        $signature = '';
        $depth = 0;
        $started = false;

        for ($i = $startLine; $i < min($startLine + 10, count($lines)); $i++) {
            $signature .= ' ' . trim($lines[$i]);

            if (str_contains($lines[$i], '(')) {
                $started = true;
            }

            $depth += substr_count($lines[$i], '(') - substr_count($lines[$i], ')');

            if ($started && $depth <= 0) {
                break;
            }
        }

        return trim($signature);
    }

    /**
     * Convert absolute path to project-relative path.
     */
    private function relativePath(string $filePath): string
    {
        $basePath = base_path() . '/';
        if (str_starts_with($filePath, $basePath)) {
            return substr($filePath, strlen($basePath));
        }
        return $filePath;
    }

    /**
     * Build a human-readable summary of findings.
     */
    private function buildSummary(array $violations, int $filesScanned, int $cleanFiles): string
    {
        if (empty($violations)) {
            return "All {$filesScanned} files passed pattern compliance checks. No violations found.";
        }

        $bySeverity = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $byRule = [];

        foreach ($violations as $v) {
            $bySeverity[$v['severity']] = ($bySeverity[$v['severity']] ?? 0) + 1;
            $byRule[$v['rule']] = ($byRule[$v['rule']] ?? 0) + 1;
        }

        $total = count($violations);
        $parts = [];
        foreach ($bySeverity as $sev => $count) {
            if ($count > 0) {
                $parts[] = "{$count} {$sev}";
            }
        }

        $summary = "Scanned {$filesScanned} files ({$cleanFiles} clean). ";
        $summary .= "Found {$total} violations: " . implode(', ', $parts) . ". ";
        $summary .= "Rules triggered: " . implode(', ', array_map(
            fn($rule, $count) => "{$rule}({$count})",
            array_keys($byRule),
            array_values($byRule)
        )) . ".";

        return $summary;
    }
}
