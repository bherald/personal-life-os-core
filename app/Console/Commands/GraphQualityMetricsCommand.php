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
        {--history=5 : Number of historical runs to show}';

    protected $description = 'Measure and display knowledge graph quality metrics';

    public function handle(KnowledgeGraphService $kgService): int
    {
        if ($this->option('run')) {
            return $this->runEvaluation($kgService);
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

        if (!empty($result['freshness']['age_buckets'])) {
            $this->newLine();
            $this->info('Triple Age Distribution:');
            $this->table(
                ['Bucket', 'Count'],
                array_map(fn($b) => [$b['bucket'], $b['count']], $result['freshness']['age_buckets'])
            );
        }

        return Command::SUCCESS;
    }

    private function showStats(): int
    {
        $limit = (int) $this->option('history');

        try {
            $runs = DB::connection('pgsql_rag')->select("
                SELECT id, accuracy_score, freshness_score, coverage_score, composite_score,
                       sample_size, stale_triple_count, orphan_entity_count,
                       total_triples, total_entities, eligible_documents, extracted_documents,
                       duration_ms, created_at
                FROM kg_quality_runs
                ORDER BY created_at DESC
                LIMIT ?
            ", [$limit]);
        } catch (\Throwable $e) {
            $this->error('No quality runs found (table may not exist yet). Run migration first.');
            return Command::FAILURE;
        }

        if (empty($runs)) {
            $this->warn('No quality runs recorded yet. Use --run to create one.');
            return Command::SUCCESS;
        }

        $this->info("Last {$limit} KG Quality Runs:");
        $this->table(
            ['Date', 'Accuracy', 'Freshness', 'Coverage', 'Composite', 'Triples', 'Entities', 'Stale', 'Orphans', 'Duration'],
            array_map(fn($r) => [
                substr($r->created_at, 0, 16),
                $this->formatScore((float) $r->accuracy_score),
                $this->formatScore((float) $r->freshness_score),
                $this->formatScore((float) $r->coverage_score),
                $this->formatScore((float) $r->composite_score),
                number_format($r->total_triples),
                number_format($r->total_entities),
                $r->stale_triple_count,
                $r->orphan_entity_count,
                $r->duration_ms . 'ms',
            ], $runs)
        );

        return Command::SUCCESS;
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
