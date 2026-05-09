<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyEvidenceAssetCaptureReviewService;
use Illuminate\Console\Command;

class GenealogyEvidenceAssetCaptureReviewCommand extends Command
{
    protected $signature = 'genealogy:evidence-asset-capture-review
        {--limit=50 : Maximum pending genealogy review packet rows to scan}
        {--execute : Create or reuse noncanonical capture-approval review rows}
        {--confirm-noncanonical-write : Required with --execute to write agent_review_queue rows}
        {--eligible-only : Omit candidates that still require manual/provider review}
        {--json : Emit machine-readable JSON}
        {--markdown : Emit Markdown}
        {--compact : Omit per-row review item detail}
        {--dry-run : Force dry-run mode}';

    protected $description = 'Materialize review-first genealogy evidence media capture approvals without downloads or canonical writes';

    public function handle(GenealogyEvidenceAssetCaptureReviewService $service): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        if ($this->option('execute') && $this->option('dry-run')) {
            $this->error('Choose either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        $payload = $service->collect(
            limit: (int) $this->option('limit'),
            execute: (bool) $this->option('execute'),
            confirmed: (bool) $this->option('confirm-noncanonical-write'),
            compact: (bool) $this->option('compact'),
            eligibleOnly: (bool) $this->option('eligible-only'),
        );

        if ($this->option('compact')) {
            $payload = $service->compactPayload($payload);
        }

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy evidence asset capture review JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($service->toMarkdown($payload));

            return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->line($service->toText($payload));

        return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
