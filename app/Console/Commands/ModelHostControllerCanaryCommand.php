<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerCanaryService;
use Illuminate\Console\Command;
use RuntimeException;

class ModelHostControllerCanaryCommand extends Command
{
    protected $signature = 'model-host:canary
        {target : Exact allowlisted MHC-05 target}
        {--request-id= : Exact request identity}
        {--lease-id= : Exact lease identity}
        {--execute : Required to run one exact canary}
        {--json : Emit compact content-free JSON}';

    protected $description = 'Run one exact manual MHC-05 PLOS target canary';

    public function __construct(private readonly ModelHostControllerCanaryService $canary)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('execute')) {
            $this->error('MHC-05 canary requires --execute.');

            return self::FAILURE;
        }

        $requestId = trim((string) $this->option('request-id'));
        $leaseId = trim((string) $this->option('lease-id'));
        if ($requestId === '' || $leaseId === '') {
            $this->error('MHC-05 canary requires --request-id and --lease-id.');

            return self::FAILURE;
        }

        try {
            $payload = $this->canary->run((string) $this->argument('target'), $requestId, $leaseId);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf(
                'MHC-05 target=%s result=%s queue_preserved=%s',
                $payload['target'],
                $payload['result'],
                $payload['queue_preserved'] ? 'true' : 'false',
            ));
        }

        return $payload['result'] === 'green' ? self::SUCCESS : self::FAILURE;
    }
}
