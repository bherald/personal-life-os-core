<?php

namespace App\Console\Commands;

use App\Services\Genealogy\SearchCoverageService;
use Illuminate\Console\Command;

/**
 * N71 — Negative evidence coverage gap report.
 *
 * Shows which persons in a tree are missing searches for critical
 * repository types, enabling targeted research prioritization.
 */
class GenealogyGapReport extends Command
{
    protected $signature = 'genealogy:coverage-gaps
                            {--tree=    : Tree ID (required)}
                            {--type=    : Filter to a specific repository type}
                            {--limit=50 : Max persons to show in gap report}
                            {--summary  : Show tree-level summary only (no person list)}';

    protected $description = 'N71: Negative evidence gap report — persons missing critical repository searches';

    private const VALID_TYPES = [
        'vital_records', 'census', 'church', 'military', 'immigration',
        'land', 'probate', 'newspaper', 'cemetery', 'dna', 'other',
    ];

    public function handle(SearchCoverageService $coverage): int
    {
        $treeId = (int) $this->option('tree');
        if (!$treeId) {
            $this->error('--tree is required');
            return 1;
        }

        $type   = $this->option('type') ?: null;
        $limit  = max(1, (int) $this->option('limit'));
        $summary = $this->option('summary');

        if ($type && !in_array($type, self::VALID_TYPES)) {
            $this->error("Unknown type '{$type}'. Valid: " . implode(', ', self::VALID_TYPES));
            return 1;
        }

        // Tree-level summary
        $treeStats = $coverage->getCoverageForTree($treeId);

        $this->line("=== Tree {$treeId} Coverage Summary ===");
        $this->line("Persons: {$treeStats['total_persons']} total | {$treeStats['persons_with_coverage']} with coverage | {$treeStats['gps_complete_persons']} GPS-complete ({$treeStats['gps_complete_pct']}%)");
        $this->line("Total searches logged: {$treeStats['total_searches']}");
        $this->newLine();

        // Core gap table
        $this->line("Core Repository Type Coverage:");
        $gapRows = [];
        foreach ($treeStats['core_gaps'] as $repoType => $g) {
            $bar = str_repeat('█', (int)($g['coverage_pct'] / 5)) . str_repeat('░', 20 - (int)($g['coverage_pct'] / 5));
            $gapRows[] = [
                $repoType,
                $g['covered_persons'],
                $g['missing_persons'],
                $g['coverage_pct'] . '%',
                $bar,
            ];
        }
        $this->table(['Repository Type', 'Covered', 'Missing', 'Coverage%', 'Bar'], $gapRows);

        if ($summary) {
            return 0;
        }

        // Person-level gap report
        $this->newLine();
        $label = $type ? "type={$type}" : 'all core types';
        $this->line("=== Persons with Gaps ({$label}, limit={$limit}) ===");

        $report = $coverage->getGapReport($treeId, $type, $limit);

        if (empty($report['gaps'])) {
            $this->info("No gaps found" . ($type ? " for {$type}" : '') . ".");
            return 0;
        }

        $rows = array_map(fn($g) => [
            $g['person_id'],
            mb_substr($g['person_name'], 0, 30),
            $g['birth_date'] ?? '—',
            $g['total_searches'],
            $g['repo_types_covered'],
            implode(', ', $g['missing_types']),
        ], $report['gaps']);

        $this->table(
            ['Person ID', 'Name', 'Birth', 'Searches', 'Types Covered', 'Missing Types'],
            $rows
        );

        $this->line("Total persons with gaps: {$report['total_gap_persons']}");

        return 0;
    }
}
