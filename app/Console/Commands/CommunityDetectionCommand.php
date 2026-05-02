<?php

namespace App\Console\Commands;

use App\Services\CommunityDetectionService;
use App\Services\CommunityReportService;
use Illuminate\Console\Command;

class CommunityDetectionCommand extends Command
{
    protected $signature = 'graph:detect-communities
        {--stats : Show community detection statistics only}
        {--force : Force rebuild (clear existing communities first)}
        {--resolutions=1.0,0.5,0.25 : Comma-separated Leiden resolution parameters}
        {--min-size=2 : Minimum community size}
        {--reports : Generate LLM reports for communities after detection}
        {--report-limit=50 : Max reports to generate per run}
        {--report-min-size=3 : Min community size for report generation}
        {--sleep=2000 : Milliseconds between LLM calls for reports}
        {--reports-only : Only generate reports (skip detection)}';

    protected $description = 'Run Leiden community detection on the knowledge graph';

    public function handle(
        CommunityDetectionService $detectionService,
        CommunityReportService $reportService
    ): int {
        if ($this->option('stats')) {
            return $this->showStats($detectionService, $reportService);
        }

        if ($this->option('reports-only')) {
            return $this->generateReportsOnly($reportService);
        }

        // Run community detection
        $this->info('Running Leiden community detection...');

        $resolutions = array_map('floatval', explode(',', $this->option('resolutions')));
        $this->info('  Resolutions: ' . implode(', ', $resolutions));
        $this->info('  Min community size: ' . $this->option('min-size'));

        if ($this->option('force')) {
            $this->warn('  Force rebuild: clearing existing communities');
        }

        $result = $detectionService->detectCommunities([
            'resolutions' => $resolutions,
            'min_community_size' => (int) $this->option('min-size'),
            'force_rebuild' => $this->option('force'),
        ]);

        if (!$result['success']) {
            $this->error('Community detection failed: ' . ($result['error'] ?? 'Unknown'));
            return Command::FAILURE;
        }

        $this->info('Community detection complete:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Communities Detected', $result['communities_detected']],
                ['Hierarchy Levels', $result['levels']],
                ['Duration', $result['duration_ms'] . 'ms'],
                ['Run ID', $result['run_id']],
                ['Graph Nodes', $result['stats']['total_nodes'] ?? 'N/A'],
                ['Graph Edges', $result['stats']['total_edges'] ?? 'N/A'],
                ['Components', $result['stats']['components'] ?? 'N/A'],
            ]
        );

        // Generate reports if requested
        if ($this->option('reports')) {
            $this->newLine();
            return $this->generateReports($reportService, $result['run_id']);
        }

        $this->line(sprintf('[ITEMS_PROCESSED:%d]', $result['communities_detected'] ?? 0));

        return Command::SUCCESS;
    }

    private function generateReportsOnly(CommunityReportService $reportService): int
    {
        $this->info('Generating community reports (detection skipped)...');
        return $this->generateReports($reportService);
    }

    private function generateReports(CommunityReportService $reportService, ?string $runId = null): int
    {
        $limit = (int) $this->option('report-limit');
        $minSize = (int) $this->option('report-min-size');
        $sleepMs = (int) $this->option('sleep');

        $this->info("Generating community reports (min size: {$minSize}, limit: {$limit}, sleep: {$sleepMs}ms)...");

        $result = $reportService->generateReports([
            'min_community_size' => $minSize,
            'limit' => $limit,
            'sleep_ms' => $sleepMs,
            'run_id' => $runId,
        ]);

        if (!$result['success']) {
            $this->error('Report generation failed: ' . ($result['error'] ?? 'Unknown'));
            return Command::FAILURE;
        }

        $this->info('Report generation complete:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Reports Generated', $result['reports_generated']],
                ['Reports Failed', $result['reports_failed'] ?? 0],
                ['Total Tokens (est)', number_format($result['total_tokens'] ?? 0)],
                ['Duration', ($result['duration_ms'] ?? 0) . 'ms'],
            ]
        );

        if (!empty($result['errors'])) {
            $this->warn('Errors:');
            foreach (array_slice($result['errors'], 0, 5) as $err) {
                $this->line("  - {$err}");
            }
            if (count($result['errors']) > 5) {
                $this->line("  ... and " . (count($result['errors']) - 5) . " more");
            }
        }

        return Command::SUCCESS;
    }

    private function showStats(
        CommunityDetectionService $detectionService,
        CommunityReportService $reportService
    ): int {
        $stats = $detectionService->getStatistics();

        $this->info('Knowledge Graph Community Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Communities', $stats['total_communities']],
                ['Hierarchy Levels', $stats['max_level'] + 1],
                ['Avg Community Size', $stats['avg_size']],
                ['Largest Community', $stats['largest_community']],
                ['Smallest Community', $stats['smallest_community']],
                ['Avg Modularity', $stats['avg_modularity']],
                ['---', '---'],
                ['Total Entities', $stats['total_entities']],
                ['Entities in Communities', $stats['entities_in_communities']],
                ['Avg Entity Degree', $stats['avg_degree']],
                ['Max Entity Degree', $stats['max_degree']],
                ['Avg PageRank', $stats['avg_pagerank']],
                ['---', '---'],
                ['Community Reports', $stats['community_reports']],
                ['Bridge Entities', $stats['bridge_entities']],
            ]
        );

        if ($stats['last_run']) {
            $this->newLine();
            $this->info('Last Detection Run:');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Run ID', $stats['last_run']['id']],
                    ['Communities', $stats['last_run']['communities']],
                    ['Levels', $stats['last_run']['levels']],
                    ['Duration', ($stats['last_run']['duration_ms'] ?? 'N/A') . 'ms'],
                    ['Reports Generated', $stats['last_run']['reports']],
                    ['Timestamp', $stats['last_run']['at']],
                ]
            );
        }

        return Command::SUCCESS;
    }
}
