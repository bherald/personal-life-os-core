<?php

namespace App\Console\Commands;

use App\Services\FactCheckPipelineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FactCheckBenchmarkCommand extends Command
{
    protected $signature = 'factcheck:benchmark
        {--dataset=averitec : Dataset to use}
        {--split=dev : Dataset split}
        {--limit=50 : Max claims to evaluate}
        {--offset=0 : Starting offset}
        {--dry-run : Download dataset and show stats only}
        {--analyze= : Show detailed analysis for a run_id}
        {--stats : List all previous benchmark runs}
        {--consensus : Enable consensus verification}
        {--refresh : Force re-download of cached dataset}';

    protected $description = 'Benchmark PLOS fact-check pipeline against AVeriTeC dataset';

    /** AVeriTeC 4-class → PLOS 3-class mapping */
    private const LABEL_MAP = [
        'Supported' => 'supported',
        'Refuted' => 'refuted',
        'Not Enough Evidence' => 'inconclusive',
        'Conflicting Evidence/Cherrypicking' => 'inconclusive',
    ];

    /** PLOS labels for metrics computation (FC-8: 5-class + inconclusive) */
    private const PLOS_LABELS = ['true', 'mostly_true', 'half_true', 'mostly_false', 'false', 'inconclusive'];

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        if ($analyzeId = $this->option('analyze')) {
            return $this->analyzeRun($analyzeId);
        }

        return $this->runBenchmark();
    }

    private function showStats(): int
    {
        $runs = DB::connection('pgsql_rag')->select("
            SELECT run_id, dataset, split, claims_evaluated, accuracy, macro_f1, weighted_f1,
                   avg_duration_ms, total_duration_ms, config, created_at
            FROM fact_check_benchmark_runs
            ORDER BY created_at DESC
            LIMIT 20
        ");

        if (empty($runs)) {
            $this->info('No benchmark runs found.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($runs as $run) {
            $config = json_decode($run->config ?? '{}', true);
            $rows[] = [
                $run->run_id,
                $run->created_at,
                $run->claims_evaluated,
                $this->fmt($run->accuracy),
                $this->fmt($run->macro_f1),
                $this->fmt($run->weighted_f1),
                $run->avg_duration_ms ? $run->avg_duration_ms . 'ms' : '-',
                !empty($config['consensus']) ? 'Yes' : 'No',
            ];
        }

        $this->table(
            ['Run ID', 'Date', 'Claims', 'Accuracy', 'Macro F1', 'Weighted F1', 'Avg Time', 'Consensus'],
            $rows
        );

        return Command::SUCCESS;
    }

    private function analyzeRun(string $runId): int
    {
        $run = DB::connection('pgsql_rag')->select("
            SELECT * FROM fact_check_benchmark_runs WHERE run_id = ?
        ", [$runId]);

        if (empty($run)) {
            $this->error("Run '{$runId}' not found.");
            return Command::FAILURE;
        }

        $run = $run[0];
        $this->info("=== Benchmark Run: {$run->run_id} ===");
        $this->line("Dataset: {$run->dataset} ({$run->split})  |  Date: {$run->created_at}");
        $this->line("Claims evaluated: {$run->claims_evaluated}");
        $this->newLine();

        // Overall metrics
        $this->info('--- Overall Metrics ---');
        $this->table(['Metric', 'Value'], [
            ['Accuracy', $this->fmt($run->accuracy)],
            ['Macro F1', $this->fmt($run->macro_f1)],
            ['Weighted F1', $this->fmt($run->weighted_f1)],
            ['Avg Confidence (Correct)', $this->fmt($run->avg_confidence_correct)],
            ['Avg Confidence (Incorrect)', $this->fmt($run->avg_confidence_incorrect)],
            ['Avg Duration', ($run->avg_duration_ms ?? '-') . 'ms'],
            ['Total Duration', $this->formatDuration($run->total_duration_ms)],
        ]);

        // Per-class metrics
        $perClass = json_decode($run->per_class_metrics ?? '{}', true);
        if ($perClass) {
            $this->info('--- Per-Class Metrics ---');
            $classRows = [];
            foreach ($perClass as $label => $m) {
                $classRows[] = [
                    $label,
                    $this->fmt($m['precision'] ?? null),
                    $this->fmt($m['recall'] ?? null),
                    $this->fmt($m['f1'] ?? null),
                    $m['support'] ?? 0,
                ];
            }
            $this->table(['Class', 'Precision', 'Recall', 'F1', 'Support'], $classRows);
        }

        // Confusion matrix
        $matrix = json_decode($run->confusion_matrix ?? '{}', true);
        if ($matrix) {
            $this->info('--- Confusion Matrix (rows=gold, cols=predicted) ---');
            $header = array_merge([''], self::PLOS_LABELS);
            $matrixRows = [];
            foreach (self::PLOS_LABELS as $gold) {
                $row = [$gold];
                foreach (self::PLOS_LABELS as $pred) {
                    $row[] = $matrix[$gold][$pred] ?? 0;
                }
                $matrixRows[] = $row;
            }
            $this->table($header, $matrixRows);
        }

        // Sample errors
        $errors = DB::connection('pgsql_rag')->select("
            SELECT claim_index, claim_text, gold_label, predicted_label, confidence
            FROM fact_check_benchmark_claims
            WHERE run_id = ? AND correct = false AND predicted_label != 'error'
            ORDER BY confidence DESC
            LIMIT 10
        ", [$runId]);

        if (!empty($errors)) {
            $this->info('--- High-Confidence Errors (top 10) ---');
            $errRows = [];
            foreach ($errors as $e) {
                $errRows[] = [
                    $e->claim_index,
                    Str::limit($e->claim_text, 80),
                    $e->gold_label,
                    $e->predicted_label,
                    $this->fmt($e->confidence),
                ];
            }
            $this->table(['#', 'Claim', 'Gold', 'Predicted', 'Confidence'], $errRows);
        }

        return Command::SUCCESS;
    }

    private function runBenchmark(): int
    {
        $dataset = $this->option('dataset');
        $split = $this->option('split');
        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');
        $dryRun = $this->option('dry-run');
        $consensus = $this->option('consensus');
        $refresh = $this->option('refresh');

        // Download/load dataset
        $claims = $this->loadDataset($dataset, $split, $refresh);
        if ($claims === null) {
            return Command::FAILURE;
        }

        $this->info("Loaded " . count($claims) . " claims from {$dataset}/{$split}");

        // Show label distribution
        $distribution = [];
        foreach ($claims as $c) {
            $label = $c['label'] ?? 'unknown';
            $distribution[$label] = ($distribution[$label] ?? 0) + 1;
        }
        $this->info('--- Label Distribution ---');
        $distRows = [];
        foreach ($distribution as $label => $count) {
            $mapped = self::LABEL_MAP[$label] ?? '?';
            $distRows[] = [$label, $mapped, $count, round($count / count($claims) * 100, 1) . '%'];
        }
        $this->table(['AVeriTeC Label', 'PLOS Label', 'Count', '%'], $distRows);

        if ($dryRun) {
            $this->info('Dry run complete. No LLM calls made.');
            return Command::SUCCESS;
        }

        // Apply offset/limit
        $subset = array_slice($claims, $offset, $limit);
        $this->info("Evaluating claims {$offset}–" . ($offset + count($subset) - 1) . " ({$limit} max)");

        $runId = 'fcb_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $pipeline = app(FactCheckPipelineService::class);
        $results = [];
        $totalStart = microtime(true);

        $bar = $this->output->createProgressBar(count($subset));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %message%');
        $bar->setMessage('Starting...');
        $bar->start();

        foreach ($subset as $i => $claim) {
            $claimIndex = $offset + $i;
            $claimText = $claim['claim'] ?? '';
            $goldLabel = self::LABEL_MAP[$claim['label'] ?? ''] ?? 'inconclusive';

            $bar->setMessage("Claim #{$claimIndex}");

            try {
                $result = $pipeline->verifyClaim($claimText, [
                    'persist' => false,
                    'consensus_verification' => $consensus,
                ]);

                $predicted = $result['verdict'] ?? 'inconclusive';
                $confidence = $result['confidence'] ?? 0;
                $evidenceCount = $result['evidence_count'] ?? 0;
                $durationMs = $result['duration_ms'] ?? 0;
            } catch (\Throwable $e) {
                Log::warning("FC-Benchmark: Claim #{$claimIndex} failed: " . $e->getMessage());
                $predicted = 'error';
                $confidence = 0;
                $evidenceCount = 0;
                $durationMs = 0;
            }

            $correct = ($predicted !== 'error') ? ($predicted === $goldLabel) : null;

            $results[] = [
                'run_id' => $runId,
                'claim_index' => $claimIndex,
                'claim_text' => $claimText,
                'gold_label' => $goldLabel,
                'predicted_label' => $predicted,
                'confidence' => $confidence,
                'evidence_count' => $evidenceCount,
                'duration_ms' => $durationMs,
                'correct' => $correct,
            ];

            $bar->advance();
        }

        $bar->setMessage('Done');
        $bar->finish();
        $this->newLine(2);

        $totalDuration = round((microtime(true) - $totalStart) * 1000);

        // Compute metrics
        $metrics = $this->computeMetrics($results);
        $metrics['total_duration_ms'] = $totalDuration;

        // Persist results
        $this->persistResults($runId, $dataset, $split, $consensus, $results, $metrics);

        // Display results
        $this->displayResults($runId, $metrics, $results);

        // Compare with previous run
        $this->compareWithPrevious($runId, $metrics);

        return Command::SUCCESS;
    }

    private function loadDataset(string $dataset, string $split, bool $refresh): ?array
    {
        if ($dataset !== 'averitec') {
            $this->error("Unsupported dataset: {$dataset}. Only 'averitec' is supported.");
            return null;
        }

        $cachePath = "benchmarks/averitec/{$split}.json";

        if (!$refresh && Storage::disk('local')->exists($cachePath)) {
            $this->line("Loading cached dataset from storage/app/{$cachePath}");
            $data = json_decode(Storage::disk('local')->get($cachePath), true);
            if (!empty($data)) {
                return $data;
            }
        }

        $this->info("Downloading AVeriTeC {$split} split from HuggingFace...");

        $allRows = [];
        $pageSize = 100;
        $maxPages = ($split === 'dev') ? 5 : 50; // dev=500, train=4568

        $bar = $this->output->createProgressBar($maxPages);
        $bar->setFormat(' %current%/%max% pages [%bar%] %percent:3s%%');
        $bar->start();

        for ($page = 0; $page < $maxPages; $page++) {
            $offset = $page * $pageSize;
            $url = "https://datasets-server.huggingface.co/rows?" . http_build_query([
                'dataset' => 'pminervini/averitec',
                'config' => 'default',
                'split' => $split,
                'offset' => $offset,
                'length' => $pageSize,
            ]);

            try {
                $response = Http::timeout(30)->get($url);

                if (!$response->successful()) {
                    // If we get a 404 or similar, we've exhausted the split
                    if ($response->status() === 404 || $page > 0) {
                        break;
                    }
                    $this->error("HuggingFace API returned {$response->status()}: " . $response->body());
                    return null;
                }

                $body = $response->json();
                $rows = $body['rows'] ?? [];

                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    $allRows[] = $row['row'] ?? $row;
                }
            } catch (\Throwable $e) {
                $this->error("Failed to fetch page {$page}: " . $e->getMessage());
                if (empty($allRows)) {
                    return null;
                }
                break;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if (empty($allRows)) {
            $this->error('No data retrieved from HuggingFace.');
            return null;
        }

        // Ensure directory exists and cache
        Storage::disk('local')->makeDirectory('benchmarks/averitec');
        Storage::disk('local')->put($cachePath, json_encode($allRows, JSON_PRETTY_PRINT));
        $this->info("Cached " . count($allRows) . " claims to storage/app/{$cachePath}");

        return $allRows;
    }

    private function computeMetrics(array $results): array
    {
        // Filter out errors
        $valid = array_filter($results, fn($r) => $r['predicted_label'] !== 'error');
        $errorCount = count($results) - count($valid);

        if (empty($valid)) {
            return [
                'accuracy' => 0,
                'macro_f1' => 0,
                'weighted_f1' => 0,
                'confusion_matrix' => [],
                'per_class_metrics' => [],
                'avg_confidence_correct' => 0,
                'avg_confidence_incorrect' => 0,
                'avg_duration_ms' => 0,
                'error_count' => $errorCount,
                'coverage' => 0,
            ];
        }

        // Confusion matrix
        $matrix = [];
        foreach (self::PLOS_LABELS as $g) {
            foreach (self::PLOS_LABELS as $p) {
                $matrix[$g][$p] = 0;
            }
        }
        foreach ($valid as $r) {
            $g = $r['gold_label'];
            $p = $r['predicted_label'];
            if (isset($matrix[$g][$p])) {
                $matrix[$g][$p]++;
            }
        }

        // Per-class precision, recall, F1
        $perClass = [];
        foreach (self::PLOS_LABELS as $label) {
            $tp = $matrix[$label][$label] ?? 0;
            $fp = 0;
            $fn = 0;
            foreach (self::PLOS_LABELS as $other) {
                if ($other !== $label) {
                    $fp += $matrix[$other][$label] ?? 0;  // predicted as label but gold is other
                    $fn += $matrix[$label][$other] ?? 0;  // gold is label but predicted as other
                }
            }
            $support = $tp + $fn;
            $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0;
            $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0;
            $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0;

            $perClass[$label] = [
                'precision' => round($precision, 4),
                'recall' => round($recall, 4),
                'f1' => round($f1, 4),
                'support' => $support,
                'tp' => $tp,
                'fp' => $fp,
                'fn' => $fn,
            ];
        }

        // Accuracy
        $correct = count(array_filter($valid, fn($r) => $r['correct']));
        $accuracy = count($valid) > 0 ? $correct / count($valid) : 0;

        // Macro F1
        $f1s = array_column($perClass, 'f1');
        $classesWithSupport = array_filter($perClass, fn($m) => $m['support'] > 0);
        $macroF1 = !empty($classesWithSupport)
            ? array_sum(array_column($classesWithSupport, 'f1')) / count($classesWithSupport)
            : 0;

        // Weighted F1
        $totalSupport = array_sum(array_column($perClass, 'support'));
        $weightedF1 = $totalSupport > 0
            ? array_sum(array_map(fn($m) => $m['f1'] * $m['support'], $perClass)) / $totalSupport
            : 0;

        // Confidence calibration
        $correctConfs = array_column(array_filter($valid, fn($r) => $r['correct']), 'confidence');
        $incorrectConfs = array_column(array_filter($valid, fn($r) => !$r['correct']), 'confidence');
        $avgConfCorrect = !empty($correctConfs) ? array_sum($correctConfs) / count($correctConfs) : 0;
        $avgConfIncorrect = !empty($incorrectConfs) ? array_sum($incorrectConfs) / count($incorrectConfs) : 0;

        // Duration
        $durations = array_column($valid, 'duration_ms');
        $avgDuration = !empty($durations) ? (int) round(array_sum($durations) / count($durations)) : 0;

        // Coverage (% non-inconclusive)
        $nonInconclusive = count(array_filter($valid, fn($r) => $r['predicted_label'] !== 'inconclusive'));
        $coverage = count($valid) > 0 ? $nonInconclusive / count($valid) : 0;

        return [
            'accuracy' => round($accuracy, 4),
            'macro_f1' => round($macroF1, 4),
            'weighted_f1' => round($weightedF1, 4),
            'confusion_matrix' => $matrix,
            'per_class_metrics' => $perClass,
            'avg_confidence_correct' => round($avgConfCorrect, 4),
            'avg_confidence_incorrect' => round($avgConfIncorrect, 4),
            'avg_duration_ms' => $avgDuration,
            'error_count' => $errorCount,
            'coverage' => round($coverage, 4),
        ];
    }

    private function persistResults(string $runId, string $dataset, string $split, bool $consensus, array $results, array $metrics): void
    {
        $validCount = count(array_filter($results, fn($r) => $r['predicted_label'] !== 'error'));

        DB::connection('pgsql_rag')->insert("
            INSERT INTO fact_check_benchmark_runs
                (run_id, dataset, split, claims_evaluated, accuracy, macro_f1, weighted_f1,
                 confusion_matrix, per_class_metrics, config,
                 avg_confidence_correct, avg_confidence_incorrect, avg_duration_ms, total_duration_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?, ?, ?, ?)
        ", [
            $runId, $dataset, $split, $validCount,
            $metrics['accuracy'], $metrics['macro_f1'], $metrics['weighted_f1'],
            json_encode($metrics['confusion_matrix']),
            json_encode($metrics['per_class_metrics']),
            json_encode(['consensus' => $consensus, 'limit' => count($results), 'errors' => $metrics['error_count']]),
            $metrics['avg_confidence_correct'], $metrics['avg_confidence_incorrect'],
            $metrics['avg_duration_ms'], $metrics['total_duration_ms'],
        ]);

        foreach ($results as $r) {
            DB::connection('pgsql_rag')->insert("
                INSERT INTO fact_check_benchmark_claims
                    (run_id, claim_index, claim_text, gold_label, predicted_label, confidence, evidence_count, duration_ms, correct)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $r['run_id'], $r['claim_index'], $r['claim_text'], $r['gold_label'],
                $r['predicted_label'], $r['confidence'], $r['evidence_count'],
                $r['duration_ms'], $r['correct'],
            ]);
        }

        Log::info("FC-Benchmark: Run {$runId} persisted — {$validCount} claims, accuracy={$metrics['accuracy']}, macro_f1={$metrics['macro_f1']}");
    }

    private function displayResults(string $runId, array $metrics, array $results): void
    {
        $this->info("=== Benchmark Results: {$runId} ===");
        $this->newLine();

        $this->table(['Metric', 'Value'], [
            ['Accuracy', $this->fmt($metrics['accuracy'])],
            ['Macro F1', $this->fmt($metrics['macro_f1'])],
            ['Weighted F1', $this->fmt($metrics['weighted_f1'])],
            ['Coverage (non-inconclusive)', $this->fmt($metrics['coverage'])],
            ['Avg Confidence (Correct)', $this->fmt($metrics['avg_confidence_correct'])],
            ['Avg Confidence (Incorrect)', $this->fmt($metrics['avg_confidence_incorrect'])],
            ['Avg Duration/Claim', $metrics['avg_duration_ms'] . 'ms'],
            ['Errors (excluded)', $metrics['error_count']],
        ]);

        // Per-class
        $classRows = [];
        foreach ($metrics['per_class_metrics'] as $label => $m) {
            $classRows[] = [$label, $this->fmt($m['precision']), $this->fmt($m['recall']), $this->fmt($m['f1']), $m['support']];
        }
        $this->table(['Class', 'Precision', 'Recall', 'F1', 'Support'], $classRows);

        // Confusion matrix
        $this->info('Confusion Matrix (rows=gold, cols=predicted):');
        $header = array_merge([''], self::PLOS_LABELS);
        $matrixRows = [];
        foreach (self::PLOS_LABELS as $gold) {
            $row = [$gold];
            foreach (self::PLOS_LABELS as $pred) {
                $row[] = $metrics['confusion_matrix'][$gold][$pred] ?? 0;
            }
            $matrixRows[] = $row;
        }
        $this->table($header, $matrixRows);
    }

    private function compareWithPrevious(string $currentRunId, array $currentMetrics): void
    {
        $prev = DB::connection('pgsql_rag')->select("
            SELECT run_id, accuracy, macro_f1, weighted_f1, avg_confidence_correct, claims_evaluated, created_at
            FROM fact_check_benchmark_runs
            WHERE run_id != ?
            ORDER BY created_at DESC
            LIMIT 1
        ", [$currentRunId]);

        if (empty($prev)) {
            $this->info('First benchmark run — no comparison available.');
            return;
        }

        $prev = $prev[0];
        $this->info("--- Comparison with previous run ({$prev->run_id}, {$prev->created_at}) ---");

        $comparisons = [
            ['Accuracy', $prev->accuracy, $currentMetrics['accuracy']],
            ['Macro F1', $prev->macro_f1, $currentMetrics['macro_f1']],
            ['Weighted F1', $prev->weighted_f1, $currentMetrics['weighted_f1']],
        ];

        $rows = [];
        foreach ($comparisons as [$metric, $old, $new]) {
            $delta = $new - (float) $old;
            $arrow = $delta > 0 ? '↑' : ($delta < 0 ? '↓' : '=');
            $rows[] = [$metric, $this->fmt($old), $this->fmt($new), sprintf('%s%+.2f%%', $arrow, $delta * 100)];
        }

        $this->table(['Metric', 'Previous', 'Current', 'Delta'], $rows);
    }

    private function fmt($value): string
    {
        if ($value === null) {
            return '-';
        }
        return sprintf('%.2f%%', (float) $value * 100);
    }

    private function formatDuration(?int $ms): string
    {
        if (!$ms) {
            return '-';
        }
        if ($ms < 1000) {
            return $ms . 'ms';
        }
        $seconds = $ms / 1000;
        if ($seconds < 60) {
            return round($seconds, 1) . 's';
        }
        $minutes = floor($seconds / 60);
        $remaining = round($seconds - $minutes * 60);
        return "{$minutes}m {$remaining}s";
    }
}
