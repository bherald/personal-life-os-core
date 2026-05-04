<?php

namespace App\Console\Commands;

use App\Services\KnowledgeGraphService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GraphQualityMetricsCommand extends Command
{
    protected $signature = 'graph:quality-metrics
        {--stats : Show most recent quality runs (default)}
        {--run : Run full quality evaluation with sampling}
        {--sample=50 : Sample size for accuracy measurement}
        {--history=5 : Number of historical runs to show}
        {--json : Output machine-readable JSON}';

    protected $description = 'Measure and display knowledge graph quality metrics';

    public function handle(): int
    {
        if ($this->option('run')) {
            return $this->runEvaluation(app(KnowledgeGraphService::class));
        }

        return $this->showStats();
    }

    private function runEvaluation(KnowledgeGraphService $kgService): int
    {
        $sampleSize = (int) $this->option('sample');
        $this->info("Running KG quality evaluation (sample={$sampleSize})...");

        $result = $kgService->getQualityMetrics([
            'sample_size' => $sampleSize,
            'persist' => true,
        ]);

        if ($this->option('json')) {
            $this->line(json_encode([
                'generated_at' => now()->toIso8601String(),
                'mode' => 'evaluation',
                'status' => 'pass',
                'sample_size' => $sampleSize,
                'result' => $result,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info('Quality Scores:');
        $this->table(['Dimension', 'Score', 'Details'], [
            [
                'Accuracy',
                $this->formatScore($result['accuracy']['score'] ?? 0),
                sprintf('%d/%d verified', $result['accuracy']['verified'] ?? 0, $result['accuracy']['sampled'] ?? 0),
            ],
            [
                'Freshness',
                $this->formatScore($result['freshness']['score'] ?? 0),
                sprintf('%d stale of %d linked', $result['freshness']['stale'] ?? 0, $result['freshness']['linked'] ?? 0),
            ],
            [
                'Coverage',
                $this->formatScore($result['coverage']['score'] ?? 0),
                sprintf('%d/%d docs extracted, %d orphans', $result['coverage']['extracted_documents'] ?? 0, $result['coverage']['eligible_documents'] ?? 0, $result['coverage']['orphan_entities'] ?? 0),
            ],
            [
                'COMPOSITE',
                $this->formatScore($result['composite_score']),
                sprintf('%dms', $result['duration_ms']),
            ],
        ]);

        if (! empty($result['freshness']['age_buckets'])) {
            $this->newLine();
            $this->info('Triple Age Distribution:');
            $this->table(
                ['Bucket', 'Count'],
                array_map(fn ($b) => [$b['bucket'], $b['count']], $result['freshness']['age_buckets'])
            );
        }

        return Command::SUCCESS;
    }

    private function showStats(): int
    {
        $limit = (int) $this->option('history');

        try {
            $runs = DB::connection('pgsql_rag')->select('
                SELECT id, accuracy_score, freshness_score, coverage_score, composite_score,
                       sample_size, stale_triple_count, orphan_entity_count,
                       total_triples, total_entities, eligible_documents, extracted_documents,
                       duration_ms, created_at
                FROM kg_quality_runs
                ORDER BY created_at DESC
                LIMIT ?
            ', [$limit]);
        } catch (\Throwable $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'generated_at' => now()->toIso8601String(),
                    'mode' => 'stats',
                    'status' => 'fail',
                    'history' => $limit,
                    'runs' => [],
                    'error' => 'No quality runs found (table may not exist yet). Run migration first.',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return Command::FAILURE;
            }

            $this->error('No quality runs found (table may not exist yet). Run migration first.');

            return Command::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'generated_at' => now()->toIso8601String(),
                'mode' => 'stats',
                'status' => empty($runs) ? 'empty' : 'pass',
                'history' => $limit,
                'runs' => array_map(fn ($r) => $this->formatRunForJson($r), $runs),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if (empty($runs)) {
            $this->warn('No quality runs recorded yet. Use --run to create one.');

            return Command::SUCCESS;
        }

        $this->info("Last {$limit} KG Quality Runs:");
        $this->table(
            ['Date', 'Accuracy', 'Freshness', 'Coverage', 'Composite', 'Triples', 'Entities', 'Stale', 'Orphans', 'Duration'],
            array_map(fn ($r) => [
                substr($r->created_at, 0, 16),
                $this->formatScore((float) $r->accuracy_score),
                $this->formatScore((float) $r->freshness_score),
                $this->formatScore((float) $r->coverage_score),
                $this->formatScore((float) $r->composite_score),
                number_format($r->total_triples),
                number_format($r->total_entities),
                $r->stale_triple_count,
                $r->orphan_entity_count,
                $r->duration_ms.'ms',
            ], $runs)
        );

        return Command::SUCCESS;
    }

    private function formatRunForJson(object $run): array
    {
        $accuracy = (float) $run->accuracy_score;
        $freshness = (float) $run->freshness_score;
        $coverage = (float) $run->coverage_score;
        $composite = (float) $run->composite_score;

        return [
            'id' => (int) $run->id,
            'created_at' => (string) $run->created_at,
            'accuracy_score' => $accuracy,
            'accuracy_percent' => round($accuracy * 100, 1),
            'freshness_score' => $freshness,
            'freshness_percent' => round($freshness * 100, 1),
            'coverage_score' => $coverage,
            'coverage_percent' => round($coverage * 100, 1),
            'composite_score' => $composite,
            'composite_percent' => round($composite * 100, 1),
            'sample_size' => (int) $run->sample_size,
            'stale_triple_count' => (int) $run->stale_triple_count,
            'orphan_entity_count' => (int) $run->orphan_entity_count,
            'total_triples' => (int) $run->total_triples,
            'total_entities' => (int) $run->total_entities,
            'eligible_documents' => (int) $run->eligible_documents,
            'extracted_documents' => (int) $run->extracted_documents,
            'duration_ms' => (int) $run->duration_ms,
        ];
    }

    private function formatScore(float $score): string
    {
        $pct = round($score * 100, 1);
        if ($pct >= 80) {
            return "{$pct}%";
        } elseif ($pct >= 60) {
            return "{$pct}%";
        }

        return "{$pct}%";
    }
}
