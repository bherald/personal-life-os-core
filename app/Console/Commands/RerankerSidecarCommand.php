<?php

namespace App\Console\Commands;

use App\Services\RerankerSidecarService;
use Illuminate\Console\Command;

class RerankerSidecarCommand extends Command
{
    protected $signature = 'rag:reranker-sidecar
        {action=status : status, start, stop, or restart}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Manage the authenticated loopback-only reranker evaluation sidecar';

    public function handle(RerankerSidecarService $sidecar): int
    {
        $action = (string) $this->argument('action');
        $success = match ($action) {
            'status' => true,
            'start' => $sidecar->start(),
            'stop' => $sidecar->stop(),
            'restart' => $this->restart($sidecar),
            default => false,
        };
        $payload = $sidecar->status();
        $payload['action'] = $action;
        $payload['action_success'] = $success;

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                'Reranker sidecar: %s  action=%s  model=%s  revision=%s  device=%s',
                ($payload['available'] ?? false) ? 'RUNNING' : 'STOPPED',
                $action,
                $payload['model'],
                $payload['revision'],
                $payload['device'],
            ));
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    private function restart(RerankerSidecarService $sidecar): bool
    {
        $sidecar->stop();
        sleep(2);

        return $sidecar->start();
    }
}
