<?php

namespace App\Console\Commands;

use App\Services\Genealogy\RecordHintService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenealogyRecordHints extends Command
{
    protected $signature = 'genealogy:record-hints
                            {--tree= : Tree ID to generate hints for}
                            {--person= : Specific person ID}
                            {--limit=50 : Max persons to check per tree}
                            {--min-confidence=0.5 : Minimum confidence threshold}
                            {--dry-run : Show what would be generated without persisting}';

    protected $description = 'Generate record hints by matching persons against external record sources';

    public function handle(RecordHintService $recordHintService): int
    {
        $treeId = $this->option('tree');
        $personId = $this->option('person');
        $limit = (int) $this->option('limit');
        $minConfidence = (float) $this->option('min-confidence');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN - no hints will be persisted');
        }

        // Single person mode
        if ($personId) {
            $this->info("Generating record hints for person #{$personId}...");

            $result = $recordHintService->generateRecordHints((int) $personId, $minConfidence);

            if (!$result['success']) {
                $this->error($result['error'] ?? 'Failed');
                return Command::FAILURE;
            }

            $this->info("Hints generated: {$result['hints_generated']}");

            if (!empty($result['hints'])) {
                $this->table(
                    ['Source', 'Record Type', 'Confidence', 'Title'],
                    array_map(fn($h) => [
                        $h['record_source'] ?? '-',
                        $h['suggested_record_type'] ?? '-',
                        round(($h['confidence'] ?? 0) * 100) . '%',
                        substr($h['title'] ?? '', 0, 50),
                    ], $result['hints'])
                );
            }

            return Command::SUCCESS;
        }

        // Tree mode (single tree or all trees)
        $trees = [];
        if ($treeId) {
            $trees = DB::select("SELECT id, name FROM genealogy_trees WHERE id = ?", [$treeId]);
        } else {
            $trees = DB::select("SELECT id, name FROM genealogy_trees ORDER BY id");
        }

        if (empty($trees)) {
            $this->warn('No trees found');
            return Command::SUCCESS;
        }

        $totalHints = 0;
        foreach ($trees as $tree) {
            $this->info("Processing tree: {$tree->name} (#{$tree->id})...");

            $result = $recordHintService->generateTreeRecordHints($tree->id, $limit, $minConfidence);

            $this->info(sprintf(
                '  Checked %d persons, generated %d hints, %d errors',
                $result['persons_checked'] ?? 0,
                $result['hints_generated'] ?? 0,
                $result['errors'] ?? 0,
            ));

            $totalHints += $result['hints_generated'] ?? 0;
        }

        $this->newLine();
        $this->info("Total record hints generated: {$totalHints}");
        $this->line("[ITEMS_PROCESSED:{$totalHints}]");

        return Command::SUCCESS;
    }
}
