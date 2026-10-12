<?php

namespace App\Console\Commands;

use App\Services\OllamaCoexistenceSmokeService;
use Illuminate\Console\Command;
use RuntimeException;

class OllamaCoexistenceSmokeCommand extends Command
{
    protected $signature = 'k3:ollama-coexistence-smoke
        {--execute : Required to run the exact serialized-route smoke}
        {--json : Emit compact content-free JSON}';

    protected $description = 'Run one exact content-free K3-COEX-04 Ollama serialized-route smoke';

    public function __construct(private readonly OllamaCoexistenceSmokeService $smoke)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('execute')) {
            $this->error('The Ollama coexistence smoke requires --execute.');

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
