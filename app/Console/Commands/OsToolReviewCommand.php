<?php

namespace App\Console\Commands;

use App\Services\OsToolDiscoveryService;
use Illuminate\Console\Command;

class OsToolReviewCommand extends Command
{
    protected $signature = 'os-tools:review {tool : Executable or candidate definition name} {--against= : Previous evidence SHA-256 to compare after fresh discovery} {--json}';

    protected $description = 'Prepare documentation-backed OS-tool observations for human review; never installs or enables a tool';

    public function handle(OsToolDiscoveryService $service): int
    {
        try {
            $result = $service->review((string) $this->argument('tool'), $this->option('against'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
