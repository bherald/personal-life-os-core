<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerMhc08K3ExplicitJobSmokeService;
use Illuminate\Console\Command;
use Throwable;

class ModelHostControllerMhc08K3ExplicitJobSmokeCommand extends Command
{
    protected $signature = 'model-host:mhc08-k3-explicit-job-smoke
                            {--execute : Run the fixed post-promotion API and one-shot-worker smoke}
                            {--json : Emit compact content-free JSON}';

    protected $description = 'Prove both explicit K3 roles, owner isolation, cancellation, and cleanup';

    public function handle(ModelHostControllerMhc08K3ExplicitJobSmokeService $smoke): int
    {
        if (! $this->option('execute')) {
            $this->error('The MHC-08 explicit job smoke requires --execute.');

            return self::FAILURE;
        }
        try {
            $receipt = $smoke->run();
        } catch (Throwable) {
            $receipt = [
                'schema' => ModelHostControllerMhc08K3ExplicitJobSmokeService::RECEIPT_SCHEMA,
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
        $this->line('MHC-08 explicit job smoke: '.($receipt['result'] ?? 'rejected'));
    }
}
