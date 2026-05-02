<?php

namespace App\Console\Commands;

use App\Services\EntityResolutionService;
use Illuminate\Console\Command;

class EntityResolveCommand extends Command
{
    protected $signature = 'entity:resolve
        {--backfill : Backfill embeddings for entities missing them}
        {--scan : Scan for candidate duplicate pairs}
        {--resolve : Run full resolution pipeline (find + compare + merge)}
        {--stats : Show entity resolution statistics}
        {--dry-run : Preview actions without making changes}
        {--limit=50 : Max entities to process per batch}
        {--type= : Filter by entity type (person, organization, etc.)}
        {--sleep=1000 : Sleep between batches in ms}';

    protected $description = 'Entity resolution pipeline — embedding-based duplicate detection, LLM comparison, and merge';

    public function handle(EntityResolutionService $service): int
    {
        if ($this->option('backfill')) {
            return $this->runBackfill($service);
        }

        if ($this->option('scan')) {
            return $this->runScan($service);
        }

        if ($this->option('resolve')) {
            return $this->runResolve($service);
        }

        // Default: show stats
        return $this->showStats($service);
    }

    private function runBackfill(EntityResolutionService $service): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');
        $entityType = $this->option('type');

        $this->info("Backfilling entity embeddings (limit: {$limit}" . ($dryRun ? ', dry-run' : '') . ')');

        if ($dryRun) {
            $result = $service->backfillEmbeddings([
                'limit' => $limit,
                'entity_type' => $entityType,
                'dry_run' => true,
            ]);

            $this->table(['Metric', 'Value'], [
                ['Total missing embeddings', number_format($result['total_missing'])],
                ['Would process this run', $result['would_process']],
            ]);

            return 0;
        }

        $bar = $this->output->createProgressBar($limit);
        $bar->start();

        $result = $service->backfillEmbeddings(
            [
                'limit' => $limit,
                'entity_type' => $entityType,
            ],
            function ($processed, $total) use ($bar) {
                $bar->setProgress($processed);
            }
        );

        $bar->finish();
        $this->newLine(2);

        $this->table(['Metric', 'Count'], [
            ['Processed', $result['processed']],
            ['Successfully embedded', $result['success']],
            ['Failed', $result['failed']],
        ]);

        if (($result['failed'] ?? 0) > 0) {
            $this->warn("Partial success: {$result['failed']} entity embedding(s) failed.");
        }

        return ($result['success'] ?? 0) > 0 || ($result['failed'] ?? 0) === 0 ? 0 : 1;
    }

    private function runScan(EntityResolutionService $service): int
    {
        $limit = (int) $this->option('limit');
        $entityType = $this->option('type');

        $this->info("Scanning for duplicate candidates (limit: {$limit})");

        $candidates = $service->findCandidates([
            'limit' => $limit,
            'entity_type' => $entityType,
        ]);

        if (empty($candidates)) {
            $this->info('No duplicate candidates found.');
            return 0;
        }

        $rows = [];
        foreach ($candidates as $c) {
            $rows[] = [
                $c['entity_a_id'],
                substr($c['entity_a_name'], 0, 30),
                $c['entity_b_id'],
                substr($c['entity_b_name'], 0, 30),
                $c['entity_type'],
                $c['similarity'],
                $c['similarity'] >= 0.95 ? 'AUTO' : 'LLM',
            ];
        }

        $this->table(
            ['ID A', 'Name A', 'ID B', 'Name B', 'Type', 'Similarity', 'Action'],
            $rows
        );

        $this->info(count($candidates) . ' candidate pair(s) found.');
        return 0;
    }

    private function runResolve(EntityResolutionService $service): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');
        $entityType = $this->option('type');

        $this->info("Running entity resolution" . ($dryRun ? ' (dry-run)' : ''));

        $stats = $service->resolveCandidates(
            [
                'limit' => $limit,
                'entity_type' => $entityType,
                'dry_run' => $dryRun,
            ],
            function ($phase, $stats) {
                if ($phase === 'candidates') {
                    $this->info("  Found {$stats['candidates_found']} candidate pairs");
                }
            }
        );

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Entities scanned', $stats['entities_processed']],
            ['Candidates found', $stats['candidates_found']],
            ['Auto-merged (sim >= 0.95)', $stats['auto_merged']],
            ['LLM compared', $stats['llm_compared']],
            ['LLM merged (conf >= 0.85)', $stats['llm_merged']],
            ['Submitted for review', $stats['submitted_for_review']],
            ['Errors', $stats['errors']],
            ['Duration', number_format($stats['duration_ms'] ?? 0) . 'ms'],
        ]);

        return ($stats['errors'] ?? 0) > 0 ? 1 : 0;
    }

    private function showStats(EntityResolutionService $service): int
    {
        $stats = $service->getStatistics();

        if (isset($stats['error'])) {
            $this->error("Error: {$stats['error']}");
            return 1;
        }

        $this->info('Entity Resolution Statistics');
        $this->newLine();

        $this->table(['Metric', 'Value'], [
            ['Total entities', number_format($stats['total_entities'])],
            ['Embedded', number_format($stats['embedded_count'])],
            ['Coverage', "{$stats['coverage_pct']}%"],
            ['Pending reviews', $stats['pending_reviews']],
        ]);

        $totals = $stats['totals_7d'] ?? [];
        if (!empty($totals) && ($totals['run_count'] ?? 0) > 0) {
            $this->newLine();
            $this->info('Last 7 Days:');
            $this->table(['Metric', 'Count'], [
                ['Runs', $totals['run_count']],
                ['Auto-merged', $totals['auto_merged']],
                ['LLM-merged', $totals['llm_merged']],
                ['Total merged', $totals['total_merged']],
                ['Candidates found', $totals['candidates_found']],
                ['Submitted for review', $totals['submitted_for_review']],
            ]);
        }

        $recentRuns = $stats['recent_runs'] ?? [];
        if (!empty($recentRuns)) {
            $this->newLine();
            $this->info('Recent Runs:');
            $rows = [];
            foreach (array_slice($recentRuns, 0, 5) as $run) {
                $rows[] = [
                    $run['created_at'],
                    $run['phase'],
                    $run['candidates_found'],
                    $run['auto_merged'],
                    $run['llm_merged'],
                    $run['errors'],
                    number_format($run['duration_ms']) . 'ms',
                ];
            }
            $this->table(
                ['Date', 'Phase', 'Candidates', 'Auto', 'LLM', 'Errors', 'Duration'],
                $rows
            );
        }

        return 0;
    }
}
