<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerMhc08K3JobService;
use Illuminate\Console\Command;
use Throwable;

class ModelHostControllerMhc08K3WorkCommand extends Command
{
    protected $signature = 'model-host-controller:mhc08-k3-work';

    protected $description = 'Process one explicit queued MHC-08 K3 job without generic routing';

    public function handle(ModelHostControllerMhc08K3JobService $jobs): int
    {
        try {
            $receipt = $jobs->workOne();
            $this->line(json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return in_array($receipt['state'] ?? null, ['idle', 'completed'], true)
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable) {
            $this->line(json_encode([
                'schema' => ModelHostControllerMhc08K3JobService::WORK_RECEIPT_SCHEMA,
                'task_id' => null,
                'state' => 'rejected',
                'output_sha256' => null,
                'cleanup_result' => 'unproven',
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
