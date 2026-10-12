<?php

namespace App\Console\Commands;

use App\Services\OsToolFixtureService;
use Illuminate\Console\Command;

class OsToolFixtureCommand extends Command
{
    protected $signature = 'os-tools:fixture {fixture : risky, fixed or clean synthetic shell script} {--identity= : Exact observed ShellCheck identity} {--environment= : Reviewed execution-environment fingerprint} {--evidence= : Reviewed current tool-evidence fingerprint}';

    protected $description = 'Qualify the bounded offline ShellCheck adapter using a named synthetic fixture only';

    public function handle(OsToolFixtureService $service): int
    {
        try {
            $result = $service->checkFixture($this->argument('fixture'), (string) $this->option('identity'), $this->option('environment'), $this->option('evidence'));
        } catch (\Throwable $error) {
            $result = ['success' => false, 'status' => 'failed', 'complete' => false, 'error' => $error->getMessage()];
        }
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
