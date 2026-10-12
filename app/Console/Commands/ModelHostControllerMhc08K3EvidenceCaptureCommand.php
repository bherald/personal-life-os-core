<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerMhc08K3EvidenceService;
use Illuminate\Console\Command;
use Throwable;

class ModelHostControllerMhc08K3EvidenceCaptureCommand extends Command
{
    protected $signature = 'model-host:mhc08-k3-evidence-capture
                            {kind : activation-transfer, activation-transfer-v2, or mhc08c-qualification}
                            {--source= : Exact content-free JSON receipt to validate and atomically store}
                            {--execute : Required atomic evidence-write gate}
                            {--json : Emit compact content-free JSON}';

    protected $description = 'Validate and atomically store host-produced MHC-08 acceptance evidence';

    public function handle(ModelHostControllerMhc08K3EvidenceService $evidence): int
    {
        $kind = trim((string) $this->argument('kind'));
        $source = trim((string) $this->option('source'));
        if (! in_array($kind, ['activation-transfer', 'activation-transfer-v2', 'mhc08c-qualification'], true)
            || $source === '' || ! $this->option('execute')) {
            $this->emit([
                'schema' => ModelHostControllerMhc08K3EvidenceService::CAPTURE_SCHEMA,
                'kind' => $kind,
                'result' => 'rejected',
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ]);

            return self::FAILURE;
        }

        try {
            $receipt = $evidence->capture($kind, $source);
        } catch (Throwable) {
            $receipt = [
                'schema' => ModelHostControllerMhc08K3EvidenceService::CAPTURE_SCHEMA,
                'kind' => $kind,
                'result' => 'rejected',
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ];
            $this->emit($receipt);

            return self::FAILURE;
        }
        $this->emit($receipt);

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $receipt */
    private function emit(array $receipt): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }
        $this->line(sprintf('MHC-08 evidence %s: %s', $receipt['kind'] ?? 'unknown', $receipt['result'] ?? 'rejected'));
    }
}
