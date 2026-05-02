<?php

namespace App\Jobs;

use App\Services\BrokerDiscoveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteBrokerDiscovery implements ShouldQueue
{
    use Queueable;

    public $timeout = 1800;
    public $tries = 1;

    public function __construct(private array $config)
    {
        $this->onQueue('long-running');
    }

    public function handle(BrokerDiscoveryService $discoveryService): void
    {
        Log::info('Starting queued broker discovery', [
            'config' => $this->config,
        ]);

        $result = ($this->config['add_to_db'] ?? false)
            ? $discoveryService->autoDiscoverAndAdd($this->config)
            : $discoveryService->discoverBrokers($this->config);

        Log::info('Queued broker discovery completed', [
            'config' => $this->config,
            'success' => $result['success'] ?? null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued broker discovery failed', [
            'config' => $this->config,
            'error' => $exception?->getMessage(),
        ]);
    }
}
