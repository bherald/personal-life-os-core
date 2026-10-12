<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerMhc08K3HostCleanQualificationService;
use Illuminate\Console\Command;
use Throwable;

class ModelHostControllerMhc08K3HostCleanQualificationCommand extends Command
{
    protected $signature = 'model-host:mhc08-k3-host-clean-qualify
                            {--execute : Run the bounded read-only host-clean qualification}
                            {--json : Emit compact content-free JSON}';

    protected $description = 'Bind current clean MHC-08 State host posture to activation and MHC-08C evidence';

    public function handle(ModelHostControllerMhc08K3HostCleanQualificationService $qualification): int
    {
        if (! $this->option('execute')) {
            $this->error('The MHC-08 K3 host-clean qualification requires --execute.');

            return self::FAILURE;
        }
        try {
            $receipt = $qualification->run();
        } catch (Throwable) {
            $receipt = [
                'schema' => ModelHostControllerMhc08K3HostCleanQualificationService::RECEIPT_SCHEMA,
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
        $this->line('MHC-08 host-clean qualification: '.($receipt['result'] ?? 'rejected'));
    }
}
