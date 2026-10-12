<?php

namespace App\Console\Commands;

use App\Services\Qwen38DurableSmokeService;
use Illuminate\Console\Command;
use RuntimeException;

class Qwen38DurableSmokeCommand extends Command
{
    protected $signature = 'k3:qwen38-durable-smoke
        {--execute : Required to run the exact durable route smoke}
        {--json : Emit compact content-free JSON}';

    protected $description = 'Run one exact content-free K3-ALT-06 Qwen3.8 durable-route smoke';

    public function __construct(private readonly Qwen38DurableSmokeService $smoke)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('execute')) {
            $this->error('The Qwen3.8 durable route smoke requires --execute.');

            return self::FAILURE;
        }
        try {
            $payload = $this->smoke->run();
        } catch (\JsonException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf('%s result=%s tokens=%s', $payload['case_id'], $payload['result'], $payload['completion_tokens'] ?? 'unknown'));
        }

        return self::SUCCESS;
    }
}
