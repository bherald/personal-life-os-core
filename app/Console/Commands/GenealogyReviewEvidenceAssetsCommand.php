<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyReviewEvidenceAssetCandidateReportService;
use Illuminate\Console\Command;

class GenealogyReviewEvidenceAssetsCommand extends Command
{
    protected $signature = 'genealogy:evidence-asset-candidates
        {--limit=50 : Maximum pending genealogy_review_packet rows to scan}
        {--json : Emit machine-readable JSON}
        {--markdown : Emit Markdown}
        {--compact : Omit per-row candidate detail}
        {--dry-run : Validate command shape without querying review rows}';

    protected $description = 'Observe pending genealogy review packet evidence asset candidates without downloading or mutating media';

    public function handle(GenealogyReviewEvidenceAssetCandidateReportService $report): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        $compact = (bool) $this->option('compact');
        $payload = $report->collect(
            limit: (int) $this->option('limit'),
            dryRun: (bool) $this->option('dry-run'),
            compact: $compact,
        );

        if ($compact) {
            $payload = $report->compactPayload($payload);
        }

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode review evidence asset candidate JSON.');

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
