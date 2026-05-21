<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyMediaIntakeReportService;
use Illuminate\Console\Command;

class GenealogyMediaIntakeReportCommand extends Command
{
    protected $signature = 'genealogy:media-intake-report
        {--tree=4 : Tree ID to inspect}
        {--root= : File registry / Nextcloud root to inspect; defaults to tree-inferred media root}
        {--limit=50 : Max sample rows for non-compact output}
        {--json : Emit machine-readable JSON}
        {--markdown : Emit Markdown}
        {--compact : Omit path/sample details for operator dashboards and MCP}
        {--dry-run : Validate command shape without querying row data}';

    protected $description = 'Read-only Genea media intake gap report across FT files, HTR, enrichment, and review packets';

    public function handle(GenealogyMediaIntakeReportService $report): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        $payload = $report->collect(
            treeId: (int) $this->option('tree'),
            root: $this->option('root') ? (string) $this->option('root') : null,
            limit: (int) $this->option('limit'),
            dryRun: (bool) $this->option('dry-run'),
        );

        if ($this->option('compact')) {
            $payload = $report->compactPayload($payload);
        }

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy media intake report JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($report->toMarkdown($payload));

            return self::SUCCESS;
        }

        $this->line($report->toText($payload));

        return self::SUCCESS;
    }
}
