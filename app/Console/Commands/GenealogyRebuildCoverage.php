<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenealogyRebuildCoverage extends Command
{
    protected $signature = 'genealogy:rebuild-coverage
                            {--tree= : Specific tree ID (default: all trees)}
                            {--root= : Root person ID for ancestor path BFS (auto-detected if omitted)}
                            {--paths-only : Only rebuild ancestor paths, skip coverage scores}
                            {--scores-only : Only refresh coverage scores, skip ancestor paths}
                            {--stats : Show coverage stats for each tree}';

    protected $description = 'Rebuild genealogy_ancestor_paths and genealogy_person_coverage for priority-aware research';

    public function handle(GenealogyService $genealogy): int
    {
        $treeId = $this->option('tree') ? (int) $this->option('tree') : null;
        $rootId = $this->option('root') ? (int) $this->option('root') : null;
        $pathsOnly = $this->option('paths-only');
        $scoresOnly = $this->option('scores-only');
        $showStats = $this->option('stats');

        $treeQuery = "SELECT t.id, t.name, t.person_count, t.root_person_id,
                             CONCAT(p.given_name, ' ', p.surname) AS root_person_name
                      FROM genealogy_trees t
                      LEFT JOIN genealogy_persons p ON p.id = t.root_person_id";
        $trees = $treeId
            ? DB::select($treeQuery . " WHERE t.id = ?", [$treeId])
            : DB::select($treeQuery . " ORDER BY t.id");

        if (empty($trees)) {
            $this->error("No trees found.");
            return 1;
        }

        $totalPaths = 0;
        $totalCoverage = 0;

        foreach ($trees as $tree) {
            $this->info("Tree #{$tree->id}: {$tree->name} ({$tree->person_count} persons)");

            // Determine root person — explicit option, then tree.root_person_id, then warn
            $root = $rootId ?? ($tree->root_person_id ?? null);
            if (!$root) {
                $this->warn("  Tree has no root_person_id set — skipping ancestor paths. Set via genealogy_trees.root_person_id");
            }

            // Step 1: Rebuild ancestor paths
            if (!$scoresOnly && $root) {
                $this->line("  Building ancestor paths from person #{$root} ({$tree->root_person_name})...");
                $paths = $genealogy->rebuildAncestorPaths((int) $tree->id, (int) $root);
                $this->line("  → {$paths} paths written");
                $totalPaths += $paths;
            }

            // Step 2: Refresh coverage scores
            if (!$pathsOnly) {
                $this->line("  Refreshing priority scores...");
                $scored = $genealogy->refreshPersonCoverage((int) $tree->id);
                $this->line("  → {$scored} persons scored");
                $totalCoverage += $scored;
            }

            // Stats
            if ($showStats) {
                $this->showTreeStats((int) $tree->id);
            }
        }

        $this->info("Done. Paths: {$totalPaths} | Coverage: {$totalCoverage}");
        $this->line("[ITEMS_PROCESSED:" . ($totalPaths + $totalCoverage) . "]");

        return 0;
    }

    private function showTreeStats(int $treeId): void
    {
        $stats = DB::select("
            SELECT
                bloodline_tier,
                COUNT(*) AS count,
                ROUND(AVG(priority_score), 4) AS avg_priority,
                SUM(CASE WHEN last_searched_at IS NULL THEN 1 ELSE 0 END) AS never_searched,
                ROUND(AVG(data_gap_score), 3) AS avg_gap,
                ROUND(AVG(research_exhaustion_score), 3) AS avg_exhaustion
            FROM genealogy_person_coverage
            WHERE tree_id = ?
            GROUP BY bloodline_tier
            ORDER BY bloodline_tier
        ", [$treeId]);

        $tierLabels = [1 => 'Direct ancestor', 2 => 'Sibling of direct', 3 => 'Collateral', 4 => 'Married-in'];
        foreach ($stats as $s) {
            $label = $tierLabels[$s->bloodline_tier] ?? "Tier {$s->bloodline_tier}";
            $this->line(sprintf(
                "  Tier %d (%s): %d persons | avg priority %.4f | never searched: %d | avg gap: %.3f | avg exhaustion: %.3f",
                $s->bloodline_tier, $label, $s->count, $s->avg_priority,
                $s->never_searched, $s->avg_gap, $s->avg_exhaustion
            ));
        }
    }
}
