<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyEvidenceScoreReportService;
use Illuminate\Console\Command;

class GenealogyEvidenceScoreCommand extends Command
{
    protected $signature = 'genealogy:evidence-score
        {--tree= : Tree ID to inspect}
        {--all-trees : Score every known genealogy tree}
        {--limit=50 : Max proposal rows per proposal type and tree}
        {--json : Emit machine-readable JSON}
        {--compact : Emit aggregate-only output without proposal rows, IDs, agent IDs, sources, or evidence excerpts}';

    protected $description = 'Observe-only genealogy proposal evidence scoring report';

    public function handle(GenealogyEvidenceScoreReportService $service): int
    {
        if (! $this->option('all-trees') && ($this->option('tree') === null || $this->option('tree') === '')) {
            $this->error('Provide --tree=<id> or --all-trees.');

            return self::FAILURE;
        }

        $treeId = $this->option('all-trees') ? null : (int) $this->option('tree');
        if ($treeId !== null && $treeId < 1) {
            $this->error('Tree ID must be a positive integer.');

            return self::FAILURE;
        }

        $payload = $service->collect($treeId, (int) $this->option('limit'));
        if ($this->option('compact')) {
            $payload = $service->compactPayload($payload);
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Genealogy evidence score report');
        $this->line('Mode: observe-only, no writes');
        $this->table(
            ['Tree', 'Strong', 'Medium', 'Weak', 'Conflict', 'Missing', 'Total'],
            array_map(static fn (array $tree): array => [
                $tree['tree_id'],
                $tree['counts']['strong'] ?? 0,
                $tree['counts']['medium'] ?? 0,
                $tree['counts']['weak'] ?? 0,
                $tree['counts']['conflict'] ?? 0,
                $tree['counts']['missing'] ?? 0,
                $tree['counts']['total'] ?? 0,
            ], $payload['trees'])
        );
        $this->line('[ITEMS_PROCESSED:'.($payload['summary']['total'] ?? 0).']');

        return self::SUCCESS;
    }
}
