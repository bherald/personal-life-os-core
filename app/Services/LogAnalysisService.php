<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LogAnalysisService
{
    private string $logsPath;

    private const KNOWN_LOG_FILES = [
        'laravel.log',
        'horizon.log',
        'horizon-error.log',
        'scheduler-bg.log',
        'agent-proxy.log',
        'queue-worker-error.log',
        'mcp-server.log',
        'queue.log',
        'queue-worker.log',
        'extraction-failures.log',
        'framework-monitor.log',
    ];

    // Laravel log format: [YYYY-MM-DD HH:MM:SS] env.LEVEL:
    private const LARAVEL_LOG_PATTERN = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY|WARNING):/';

    // Generic timestamp pattern for non-Laravel logs
    private const TIMESTAMP_PATTERN = '/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})/';

    // Horizon FAIL pattern
    private const HORIZON_FAIL_PATTERN = '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}).*FAIL/';

    // Patterns to strip for signature normalization
    private const NORMALIZATION_PATTERNS = [
        '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i' => '{UUID}',     // UUIDs
        '/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(\+\d{2}:\d{2})?/' => '{TIMESTAMP}', // Timestamps
        '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/' => '{IP}',                                // IP addresses
        '/\b(id|ID|Id)\s*[=:]\s*\d+/' => '{ID_REF}',                                          // id=123
        '/(?<=\/)\d+(?=\/|$|\s|\))/' => '{NUM}',                                               // /path/123/
        '/vendor\/[^\s:]+/' => '{VENDOR_PATH}',                                                // vendor paths
        '/\#\d+/' => '{LINE_REF}',                                                             // #123 line refs
        '/\b\d{10,}\b/' => '{LARGE_NUM}',                                                      // Large numeric IDs
    ];

    public function __construct()
    {
        $this->logsPath = storage_path('logs');
    }

    /**
     * Inventory log files with sizes, modification times, and error estimates
     */
    public function scanLogFiles(array $params = []): array
    {
        $hoursBack = $params['hours_back'] ?? 24;
        $cutoff = now()->subHours($hoursBack)->timestamp;

        $files = [];
        foreach (self::KNOWN_LOG_FILES as $filename) {
            $path = $this->logsPath . '/' . $filename;
            if (!file_exists($path)) {
                continue;
            }

            $stat = stat($path);
            $sizeBytes = $stat['size'];
            $modTime = $stat['mtime'];

            // Skip files not modified in our window
            if ($modTime < $cutoff) {
                $files[] = [
                    'file' => $filename,
                    'size_kb' => round($sizeBytes / 1024, 1),
                    'modified' => date('Y-m-d H:i:s', $modTime),
                    'in_window' => false,
                    'estimated_errors' => 0,
                ];
                continue;
            }

            // Quick error estimate from tail
            $errorEstimate = 0;
            if ($sizeBytes > 0) {
                $tail = $this->tailFile($path, 500);
                foreach ($tail as $line) {
                    if (preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY):|FAIL|Exception|Fatal/i', $line)) {
                        $errorEstimate++;
                    }
                }
            }

            $files[] = [
                'file' => $filename,
                'size_kb' => round($sizeBytes / 1024, 1),
                'size_mb' => round($sizeBytes / (1024 * 1024), 2),
                'modified' => date('Y-m-d H:i:s', $modTime),
                'in_window' => true,
                'estimated_errors' => $errorEstimate,
            ];
        }

        // Sort: files with errors first, then by modification time
        usort($files, function ($a, $b) {
            if ($a['estimated_errors'] !== $b['estimated_errors']) {
                return $b['estimated_errors'] - $a['estimated_errors'];
            }
            return strcmp($b['modified'], $a['modified']);
        });

        $totalErrors = array_sum(array_column($files, 'estimated_errors'));
        $activeFiles = count(array_filter($files, fn($f) => $f['in_window']));

        return [
            'success' => true,
            'result' => [
                'scan_window_hours' => $hoursBack,
                'total_files' => count($files),
                'active_files' => $activeFiles,
                'total_estimated_errors' => $totalErrors,
                'files' => $files,
            ],
            'result_text' => "Scanned {$this->logsPath}: " . count($files) . " log files found, {$activeFiles} active in last {$hoursBack}h, ~{$totalErrors} errors estimated.",
        ];
    }

    /**
     * Extract error entries from a specific log file with multiline stack trace grouping
     */
    public function parseLogErrors(array $params = []): array
    {
        $filename = $params['file'] ?? 'laravel.log';
        $maxLines = $params['max_lines'] ?? 5000;
        $severityFilter = $params['severity'] ?? 'all'; // all|error|critical|warning
        $hoursBack = $params['hours_back'] ?? 2;

        $path = $this->logsPath . '/' . $filename;
        if (!file_exists($path)) {
            return [
                'success' => false,
                'result' => [],
                'result_text' => "Log file not found: {$filename}",
            ];
        }

        $cutoff = now()->subHours($hoursBack);
        $lines = $this->tailFile($path, $maxLines);
        $errors = [];
        $currentError = null;

        foreach ($lines as $line) {
            // Check if this is a new log entry (starts with timestamp)
            $isNewEntry = preg_match(self::LARAVEL_LOG_PATTERN, $line, $matches);

            if (!$isNewEntry && strpos($filename, 'horizon') !== false) {
                $isNewEntry = preg_match(self::HORIZON_FAIL_PATTERN, $line, $matches);
                if ($isNewEntry) {
                    $matches[2] = 'ERROR'; // Normalize horizon FAIL to ERROR
                }
            }

            if (!$isNewEntry && !preg_match(self::LARAVEL_LOG_PATTERN, $line)) {
                // Continuation line (stack trace, context)
                if ($currentError !== null) {
                    $currentError['stack_trace'] .= "\n" . $line;
                    $currentError['line_count']++;
                }
                continue;
            }

            // Save previous error
            if ($currentError !== null) {
                $errors[] = $currentError;
            }

            if (!$isNewEntry) {
                $currentError = null;
                continue;
            }

            $timestamp = $matches[1];
            $level = strtoupper($matches[2] ?? 'ERROR');

            // Time filter
            try {
                $entryTime = \Carbon\Carbon::parse($timestamp);
                if ($entryTime->lt($cutoff)) {
                    $currentError = null;
                    continue;
                }
            } catch (\Throwable $e) {
                Log::debug('LogAnalysisService: log entry timestamp parse failed', ['error' => $e->getMessage()]);
                $currentError = null;
                continue;
            }

            // Severity filter
            if ($severityFilter !== 'all') {
                $severityRank = ['EMERGENCY' => 4, 'ALERT' => 4, 'CRITICAL' => 3, 'ERROR' => 2, 'WARNING' => 1];
                $filterRank = ['critical' => 3, 'error' => 2, 'warning' => 1];
                if (($severityRank[$level] ?? 0) < ($filterRank[$severityFilter] ?? 0)) {
                    $currentError = null;
                    continue;
                }
            }

            // Extract message (everything after level:)
            $message = preg_replace(self::LARAVEL_LOG_PATTERN, '', $line);
            $message = trim($message);

            $currentError = [
                'timestamp' => $timestamp,
                'level' => $level,
                'message' => mb_substr($message, 0, 500),
                'stack_trace' => '',
                'line_count' => 1,
            ];
        }

        // Don't forget the last error
        if ($currentError !== null) {
            $errors[] = $currentError;
        }

        // Trim stack traces to reasonable length
        foreach ($errors as &$error) {
            if (strlen($error['stack_trace']) > 500) {
                $error['stack_trace'] = mb_substr($error['stack_trace'], 0, 500) . "\n... (truncated)";
            }
        }
        unset($error);

        $errorCount = count($errors);
        $bySeverity = array_count_values(array_column($errors, 'level'));

        return [
            'success' => true,
            'result' => [
                'file' => $filename,
                'hours_back' => $hoursBack,
                'severity_filter' => $severityFilter,
                'total_errors' => $errorCount,
                'by_severity' => $bySeverity,
                'errors' => array_slice($errors, 0, 50), // Cap at 50 for LLM context size
            ],
            'result_text' => "Parsed {$filename}: {$errorCount} errors in last {$hoursBack}h" .
                ($errorCount > 50 ? " (showing 50)" : "") . ". " .
                implode(', ', array_map(fn($k, $v) => "{$v} {$k}", array_keys($bySeverity), $bySeverity)),
        ];
    }

    /**
     * Deduplicate errors by normalizing dynamic values and hashing
     */
    public function clusterErrorSignatures(array $params = []): array
    {
        $errors = $params['errors'] ?? [];
        if (empty($errors)) {
            // If no errors passed, parse from file
            $parseResult = $this->parseLogErrors($params);
            if (!$parseResult['success']) {
                return $parseResult;
            }
            $errors = $parseResult['result']['errors'] ?? [];
        }

        $clusters = [];

        foreach ($errors as $error) {
            $message = $error['message'] ?? '';
            $normalized = $this->normalizeSignature($message);
            $hash = hash('sha256', $normalized);

            if (!isset($clusters[$hash])) {
                $clusters[$hash] = [
                    'signature_hash' => substr($hash, 0, 12),
                    'normalized_message' => mb_substr($normalized, 0, 300),
                    'sample_message' => mb_substr($message, 0, 300),
                    'level' => $error['level'] ?? 'ERROR',
                    'count' => 0,
                    'first_seen' => $error['timestamp'] ?? null,
                    'last_seen' => $error['timestamp'] ?? null,
                    'sample_stack' => mb_substr($error['stack_trace'] ?? '', 0, 500),
                ];
            }

            $clusters[$hash]['count']++;
            $ts = $error['timestamp'] ?? null;
            if ($ts) {
                if ($ts < $clusters[$hash]['first_seen'] || $clusters[$hash]['first_seen'] === null) {
                    $clusters[$hash]['first_seen'] = $ts;
                }
                if ($ts > $clusters[$hash]['last_seen'] || $clusters[$hash]['last_seen'] === null) {
                    $clusters[$hash]['last_seen'] = $ts;
                }
            }
        }

        // Sort by count descending
        $clusterList = array_values($clusters);
        usort($clusterList, fn($a, $b) => $b['count'] - $a['count']);

        return [
            'success' => true,
            'result' => [
                'total_errors' => count($errors),
                'unique_signatures' => count($clusterList),
                'dedup_ratio' => count($errors) > 0 ? round(1 - count($clusterList) / count($errors), 2) : 0,
                'clusters' => $clusterList,
            ],
            'result_text' => count($errors) . " errors deduplicated to " . count($clusterList) .
                " unique signatures (" . round((1 - count($clusterList) / max(count($errors), 1)) * 100) . "% dedup).",
        ];
    }

    /**
     * Time-bucketed error frequency with trend direction
     */
    public function getErrorTimeline(array $params = []): array
    {
        $filename = $params['file'] ?? 'laravel.log';
        $hoursBack = $params['hours_back'] ?? 24;
        $bucketMinutes = $params['bucket_minutes'] ?? 30;

        // Parse errors for full window
        $parseResult = $this->parseLogErrors([
            'file' => $filename,
            'max_lines' => 10000,
            'hours_back' => $hoursBack,
        ]);

        if (!$parseResult['success']) {
            return $parseResult;
        }

        $errors = $parseResult['result']['errors'] ?? [];
        $buckets = [];
        $now = now();

        // Initialize buckets
        $totalBuckets = (int) ceil($hoursBack * 60 / $bucketMinutes);
        for ($i = 0; $i < $totalBuckets; $i++) {
            $bucketStart = $now->copy()->subMinutes(($i + 1) * $bucketMinutes);
            $bucketEnd = $now->copy()->subMinutes($i * $bucketMinutes);
            $key = $bucketStart->format('Y-m-d H:i');
            $buckets[$key] = [
                'start' => $bucketStart->toDateTimeString(),
                'end' => $bucketEnd->toDateTimeString(),
                'count' => 0,
                'levels' => [],
            ];
        }

        // Fill buckets
        foreach ($errors as $error) {
            try {
                $ts = \Carbon\Carbon::parse($error['timestamp']);
                $minutesAgo = $now->diffInMinutes($ts);
                $bucketIndex = (int) floor($minutesAgo / $bucketMinutes);
                $bucketStart = $now->copy()->subMinutes(($bucketIndex + 1) * $bucketMinutes);
                $key = $bucketStart->format('Y-m-d H:i');

                if (isset($buckets[$key])) {
                    $buckets[$key]['count']++;
                    $level = $error['level'] ?? 'ERROR';
                    $buckets[$key]['levels'][$level] = ($buckets[$key]['levels'][$level] ?? 0) + 1;
                }
            } catch (\Throwable $e) {
                Log::debug('LogAnalysisService: trend bucket calculation failed', ['error' => $e->getMessage()]);
                continue;
            }
        }

        // Calculate trend from most recent vs older buckets
        $bucketList = array_values($buckets);
        $recentHalf = array_slice($bucketList, 0, (int) ceil(count($bucketList) / 2));
        $olderHalf = array_slice($bucketList, (int) ceil(count($bucketList) / 2));

        $recentAvg = count($recentHalf) > 0 ? array_sum(array_column($recentHalf, 'count')) / count($recentHalf) : 0;
        $olderAvg = count($olderHalf) > 0 ? array_sum(array_column($olderHalf, 'count')) / count($olderHalf) : 0;

        if ($olderAvg == 0 && $recentAvg == 0) {
            $trend = 'stable';
        } elseif ($olderAvg == 0) {
            $trend = 'rising';
        } else {
            $ratio = $recentAvg / $olderAvg;
            $trend = $ratio > 1.5 ? 'rising' : ($ratio < 0.5 ? 'falling' : 'stable');
        }

        return [
            'success' => true,
            'result' => [
                'file' => $filename,
                'hours_back' => $hoursBack,
                'bucket_minutes' => $bucketMinutes,
                'total_errors' => count($errors),
                'trend' => $trend,
                'recent_avg_per_bucket' => round($recentAvg, 1),
                'older_avg_per_bucket' => round($olderAvg, 1),
                'buckets' => $bucketList,
            ],
            'result_text' => "Timeline for {$filename}: " . count($errors) . " errors over {$hoursBack}h, trend: {$trend} " .
                "(recent avg: " . round($recentAvg, 1) . "/bucket, older avg: " . round($olderAvg, 1) . "/bucket).",
        ];
    }

    /**
     * Find errors within N seconds of each other across different log files
     */
    public function correlateAcrossLogs(array $params = []): array
    {
        $files = $params['files'] ?? self::KNOWN_LOG_FILES;
        $windowSeconds = $params['window_seconds'] ?? 30;
        $hoursBack = $params['hours_back'] ?? 2;

        $allErrors = [];

        foreach ($files as $filename) {
            $path = $this->logsPath . '/' . $filename;
            if (!file_exists($path)) {
                continue;
            }

            $parseResult = $this->parseLogErrors([
                'file' => $filename,
                'max_lines' => 2000,
                'hours_back' => $hoursBack,
            ]);

            if (!$parseResult['success']) {
                continue;
            }

            foreach ($parseResult['result']['errors'] ?? [] as $error) {
                $error['source_file'] = $filename;
                $allErrors[] = $error;
            }
        }

        // Sort all errors by timestamp
        usort($allErrors, function ($a, $b) {
            return strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? '');
        });

        // Find correlations — errors from different files within window
        $correlations = [];
        $used = [];

        for ($i = 0; $i < count($allErrors); $i++) {
            if (isset($used[$i])) {
                continue;
            }

            $group = [$allErrors[$i]];
            $used[$i] = true;

            try {
                $baseTime = \Carbon\Carbon::parse($allErrors[$i]['timestamp']);
            } catch (\Throwable $e) {
                Log::debug('LogAnalysisService: correlation base timestamp parse failed', ['error' => $e->getMessage()]);
                continue;
            }

            for ($j = $i + 1; $j < count($allErrors); $j++) {
                if (isset($used[$j])) {
                    continue;
                }

                try {
                    $compareTime = \Carbon\Carbon::parse($allErrors[$j]['timestamp']);
                    $diffSeconds = abs($baseTime->diffInSeconds($compareTime));

                    if ($diffSeconds > $windowSeconds) {
                        break; // Sorted, so no more matches
                    }

                    // Only correlate across different files
                    if ($allErrors[$j]['source_file'] !== $allErrors[$i]['source_file']) {
                        $group[] = $allErrors[$j];
                        $used[$j] = true;
                    }
                } catch (\Throwable $e) {
                    Log::debug('LogAnalysisService: correlation compare timestamp parse failed', ['error' => $e->getMessage()]);
                    continue;
                }
            }

            if (count($group) > 1) {
                $filesInvolved = array_unique(array_column($group, 'source_file'));
                $correlations[] = [
                    'timestamp' => $allErrors[$i]['timestamp'],
                    'files_involved' => $filesInvolved,
                    'error_count' => count($group),
                    'errors' => array_map(fn($e) => [
                        'file' => $e['source_file'],
                        'level' => $e['level'],
                        'message' => mb_substr($e['message'], 0, 200),
                        'timestamp' => $e['timestamp'],
                    ], $group),
                ];
            }
        }

        return [
            'success' => true,
            'result' => [
                'window_seconds' => $windowSeconds,
                'hours_back' => $hoursBack,
                'files_scanned' => count($files),
                'total_errors_found' => count($allErrors),
                'correlation_groups' => count($correlations),
                'correlations' => array_slice($correlations, 0, 50),
            ],
            'result_text' => "Cross-log correlation: " . count($allErrors) . " errors across " . count($files) .
                " files, found " . count($correlations) . " correlated groups (within {$windowSeconds}s window).",
        ];
    }

    /**
     * Compare current 2h window against previous 48h baseline
     */
    public function compareToBaseline(array $params = []): array
    {
        $filename = $params['file'] ?? 'laravel.log';
        $currentWindowHours = $params['current_hours'] ?? 2;
        $baselineHours = $params['baseline_hours'] ?? 48;

        // Get current window errors
        $currentResult = $this->parseLogErrors([
            'file' => $filename,
            'max_lines' => 5000,
            'hours_back' => $currentWindowHours,
        ]);

        // Get baseline errors
        $baselineResult = $this->parseLogErrors([
            'file' => $filename,
            'max_lines' => 20000,
            'hours_back' => $baselineHours,
        ]);

        if (!$currentResult['success'] || !$baselineResult['success']) {
            return [
                'success' => false,
                'result' => [],
                'result_text' => "Failed to parse log files for comparison.",
            ];
        }

        // Cluster both sets
        $currentClusters = $this->clusterErrorSignatures(['errors' => $currentResult['result']['errors']])['result']['clusters'] ?? [];
        $baselineClusters = $this->clusterErrorSignatures(['errors' => $baselineResult['result']['errors']])['result']['clusters'] ?? [];

        // Build lookup by signature hash
        $baselineByHash = [];
        foreach ($baselineClusters as $cluster) {
            $baselineByHash[$cluster['signature_hash']] = $cluster;
        }

        $currentByHash = [];
        foreach ($currentClusters as $cluster) {
            $currentByHash[$cluster['signature_hash']] = $cluster;
        }

        // Classify
        $newErrors = [];
        $spikes = [];
        $resolved = [];

        foreach ($currentByHash as $hash => $cluster) {
            if (!isset($baselineByHash[$hash])) {
                $newErrors[] = $cluster;
            } else {
                // Compare rates (normalize to per-hour)
                $currentRate = $cluster['count'] / max($currentWindowHours, 0.1);
                $baselineRate = $baselineByHash[$hash]['count'] / max($baselineHours, 0.1);

                if ($baselineRate > 0 && $currentRate / $baselineRate >= 3) {
                    $cluster['current_rate_per_hour'] = round($currentRate, 2);
                    $cluster['baseline_rate_per_hour'] = round($baselineRate, 2);
                    $cluster['spike_ratio'] = round($currentRate / $baselineRate, 1);
                    $spikes[] = $cluster;
                }
            }
        }

        foreach ($baselineByHash as $hash => $cluster) {
            if (!isset($currentByHash[$hash]) && $cluster['count'] >= 3) {
                $resolved[] = $cluster;
            }
        }

        return [
            'success' => true,
            'result' => [
                'file' => $filename,
                'current_window_hours' => $currentWindowHours,
                'baseline_hours' => $baselineHours,
                'current_unique_signatures' => count($currentByHash),
                'baseline_unique_signatures' => count($baselineByHash),
                'new_errors' => $newErrors,
                'spikes' => $spikes,
                'resolved' => $resolved,
                'summary' => [
                    'new_count' => count($newErrors),
                    'spike_count' => count($spikes),
                    'resolved_count' => count($resolved),
                ],
            ],
            'result_text' => "Baseline comparison for {$filename}: " . count($newErrors) . " new errors, " .
                count($spikes) . " spikes (3x+), " . count($resolved) . " resolved since baseline.",
        ];
    }

    /**
     * Persist structured findings to log_analysis_snapshots table
     */
    public function saveAnalysisSnapshot(array $params = []): array
    {
        $scanResult = $params['scan_result'] ?? [];
        $classifications = $params['classifications'] ?? [];
        $signatureDetails = $params['signature_details'] ?? [];
        $findingsSummary = $params['findings_summary'] ?? '';
        $status = $params['status'] ?? 'completed';

        try {
            DB::insert("
                INSERT INTO log_analysis_snapshots
                (scanned_at, files_scanned, total_errors, unique_signatures,
                 bugs_found, config_issues_found, transient_count, alert_by_design_count,
                 status, signature_details, findings_summary, created_at, updated_at)
                VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $scanResult['total_files'] ?? 0,
                $scanResult['total_estimated_errors'] ?? 0,
                count($signatureDetails),
                $classifications['bug'] ?? 0,
                $classifications['config_issue'] ?? 0,
                $classifications['transient'] ?? 0,
                $classifications['alert_by_design'] ?? 0,
                $status,
                json_encode($signatureDetails),
                $findingsSummary,
            ]);

            $id = DB::selectOne("SELECT LAST_INSERT_ID() as id")->id;

            return [
                'success' => true,
                'result' => ['snapshot_id' => $id],
                'result_text' => "Analysis snapshot saved (ID: {$id}).",
            ];
        } catch (\Throwable $e) {
            Log::error('LogAnalysisService: Failed to save snapshot', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'result' => [],
                'result_text' => "Failed to save snapshot: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Normalize an error message for signature hashing
     */
    private function normalizeSignature(string $message): string
    {
        $normalized = $message;
        foreach (self::NORMALIZATION_PATTERNS as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized);
        }
        // Collapse whitespace
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));
        return $normalized;
    }

    /**
     * Read last N lines of a file efficiently (reverse seek)
     */
    private function tailFile(string $file, int $lines): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $result = [];
        $fp = fopen($file, 'r');

        if (!$fp) {
            return [];
        }

        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);

        $lineCount = 0;
        $buffer = '';

        while ($pos > 0 && $lineCount < $lines) {
            $pos--;
            fseek($fp, $pos);
            $char = fgetc($fp);

            if ($char === "\n") {
                if ($buffer !== '') {
                    array_unshift($result, $buffer);
                    $lineCount++;
                    $buffer = '';
                }
            } else {
                $buffer = $char . $buffer;
            }
        }

        if ($buffer !== '' && $lineCount < $lines) {
            array_unshift($result, $buffer);
        }

        fclose($fp);
        return $result;
    }
}
