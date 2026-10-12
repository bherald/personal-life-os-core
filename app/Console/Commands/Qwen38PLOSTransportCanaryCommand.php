<?php

namespace App\Console\Commands;

use App\Services\Qwen38PLOSTransportCanaryService;
use Illuminate\Console\Command;
use RuntimeException;

class Qwen38PLOSTransportCanaryCommand extends Command
{
    protected $signature = 'k3:qwen38-plos-canary
        {--execute : Required to run the five exact requests}
        {--json : Emit compact content-free JSON}';

    protected $description = 'Run the exact five-case Qwen3.8 PLOS transport canary';

    public function __construct(private readonly Qwen38PLOSTransportCanaryService $canary)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('execute')) {
            $this->error('The Qwen3.8 PLOS canary requires --execute.');

            return self::FAILURE;
        }

        try {
            $payload = $this->canary->run();
        } catch (\JsonException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf(
                '%s quality=%s average=%.2f strict=%d/%d',
                $payload['batch_id'],
                $payload['quality_gate_passed'] ? 'pass' : 'fail',
                (float) $payload['candidate']['average_score_ratio'],
                (int) $payload['candidate']['passed_cases'],
                (int) $payload['candidate']['total_cases']
            ));
        }

        return $payload['quality_gate_passed'] ? self::SUCCESS : self::FAILURE;
    }
}
