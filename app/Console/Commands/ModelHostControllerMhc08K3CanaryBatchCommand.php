<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerMhc08K3CanaryBatchService;
use App\Services\ModelHostControllerMhc08K3EvidenceService;
use Illuminate\Console\Command;
use Throwable;

class ModelHostControllerMhc08K3CanaryBatchCommand extends Command
{
    protected $signature = 'model-host:mhc08-k3-canary-batch
                            {--execute : Required to run the five fixed synthetic requests}
                            {--json : Emit compact content-free JSON}';

    protected $description = 'Run the exact five-case MHC-08 K3 canary batch';

    public function __construct(
        private readonly ModelHostControllerMhc08K3CanaryBatchService $canary,
    ) {
        parent::__construct();
    }

    public function handle(ModelHostControllerMhc08K3EvidenceService $evidence): int
    {
        if (! $this->option('execute')) {
            $this->error('The MHC-08 K3 canary batch requires --execute.');

            return self::FAILURE;
        }

        try {
            $receipt = $this->canary->run();
            $evidence->store('canary', $receipt);
        } catch (Throwable) {
            $receipt = [
                'schema' => ModelHostControllerMhc08K3CanaryBatchService::RECEIPT_SCHEMA,
                'batch_id' => 'MHC-08D-canary-batch-1',
                'result' => 'rejected',
                'cleanup_result' => 'unproven',
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

        $this->line(sprintf(
            '%s result=%s cases=%d cleanup=%s',
            (string) ($receipt['batch_id'] ?? 'MHC-08D-canary-batch-1'),
            ($receipt['controls_passed'] ?? false) === true ? 'green' : 'rejected',
            (int) ($receipt['case_count'] ?? 0),
            ($receipt['cleanup_proven'] ?? false) === true ? 'proven' : (string) ($receipt['cleanup_result'] ?? 'unproven'),
        ));
    }
}
