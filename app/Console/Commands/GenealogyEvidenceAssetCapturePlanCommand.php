<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyEvidenceAssetCapturePlanService;
use Illuminate\Console\Command;

class GenealogyEvidenceAssetCapturePlanCommand extends Command
{
    protected $signature = 'genealogy:evidence-asset-capture-plan
        {--limit=50 : Maximum pending genealogy review packet rows to scan}
        {--json : Emit machine-readable JSON}
        {--markdown : Emit Markdown}
        {--compact : Omit per-row plan detail}
        {--eligible-only : Omit candidates that still require manual/provider review}
        {--dry-run : Validate command shape without querying review rows}';

    protected $description = 'Plan review-approved genealogy evidence media capture without downloading or mutating genealogy records';

    public function handle(GenealogyEvidenceAssetCapturePlanService $planner): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        $compact = (bool) $this->option('compact');
        $payload = $planner->collect(
            limit: (int) $this->option('limit'),
            dryRun: (bool) $this->option('dry-run'),
            compact: $compact,
            eligibleOnly: (bool) $this->option('eligible-only'),
        );

        if ($compact) {
            $payload = $planner->compactPayload($payload);
        }

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy evidence asset capture plan JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($planner->toMarkdown($payload));

            return self::SUCCESS;
        }

        $this->line($planner->toText($payload));

        return self::SUCCESS;
    }
}
