<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyEvidenceAssetCaptureExecutorService;
use Illuminate\Console\Command;

class GenealogyEvidenceAssetCaptureExecutorCommand extends Command
{
    protected $signature = 'genealogy:evidence-asset-capture-executor
        {--limit=25 : Maximum approved capture review rows to inspect}
        {--json : Emit machine-readable JSON}
        {--markdown : Emit Markdown}
        {--compact : Omit per-row preflight detail}
        {--save-preflight : Persist a noncanonical preflight stamp on approved capture review rows}
        {--confirm-noncanonical-write : Required with --save-preflight}';

    protected $description = 'Preflight approved genealogy evidence media capture reviews before gated capture execution';

    public function handle(GenealogyEvidenceAssetCaptureExecutorService $service): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        $payload = $service->collect(
            limit: (int) $this->option('limit'),
            savePreflight: (bool) $this->option('save-preflight'),
            confirmed: (bool) $this->option('confirm-noncanonical-write'),
            compact: (bool) $this->option('compact'),
        );

        if ($this->option('compact')) {
            $payload = $service->compactPayload($payload);
        }

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy evidence asset capture executor JSON.');

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
