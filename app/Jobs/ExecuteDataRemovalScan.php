<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteDataRemovalScan implements ShouldQueue
{
    use Queueable;

    public $timeout = 3600;
    public $tries = 1;

    public function __construct(private array $args)
    {
        $this->onQueue('long-running');
    }

    public function handle(): void
    {
        Log::info('Starting queued data removal scan', [
            'args' => $this->args,
        ]);

        $exitCode = Artisan::call('data-removal:scan', $this->args);

        Log::info('Queued data removal scan completed', [
            'args' => $this->args,
            'exit_code' => $exitCode,
            'output' => trim(Artisan::output()),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued data removal scan failed', [
            'args' => $this->args,
            'error' => $exception?->getMessage(),
        ]);
    }
}
