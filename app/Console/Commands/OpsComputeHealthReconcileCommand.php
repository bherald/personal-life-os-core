<?php

namespace App\Console\Commands;

use App\Services\ComputeRouterService;
use Illuminate\Console\Command;

class OpsComputeHealthReconcileCommand extends Command
{
    protected $signature = 'ops:compute-health-reconcile
        {--capability= : Limit reconciliation to one exact capability}
        {--json : Emit machine-readable JSON}
        {--compact : Emit compact JSON; requires --json}';

    protected $description = 'Reconcile compute worker health and recover eligible circuits';

    public function handle(ComputeRouterService $router): int
    {
        if ($this->option('compact') && ! $this->option('json')) {
            $this->error('Use --compact with --json.');

            return self::INVALID;
        }

        $capability = trim((string) $this->option('capability'));
        $started = microtime(true);

        if ($capability !== '') {
            $result = $router->recoverCapability($capability);
            $payload = [
                'schema' => 'plos.compute_health_reconcile.v1',
                'status' => ($result['state'] ?? null) === 'available' ? 'healthy' : 'deferred',
                'scope' => 'capability',
                'capability' => $capability,
                'summary' => [
                    'registered' => (int) ($result['registered'] ?? 0),
                    'available' => (int) ($result['available'] ?? 0),
                    'recovery_attempted' => (int) ($result['recovery_attempted'] ?? 0),
                    'recovered' => (bool) ($result['recovered'] ?? false),
                    'state' => (string) ($result['state'] ?? 'unavailable'),
                ],
            ];
        } else {
            $results = $router->healthCheckAll();
            $payload = [
                'schema' => 'plos.compute_health_reconcile.v1',
                'status' => 'completed',
                'scope' => 'all_active',
                'summary' => [
                    'checked' => count($results),
                    'healthy' => count(array_filter($results, static fn (array $row): bool => (bool) ($row['healthy'] ?? false))),
                    'unhealthy' => count(array_filter($results, static fn (array $row): bool => ! (bool) ($row['healthy'] ?? false))),
                    'circuits_reset' => count(array_filter($results, static fn (array $row): bool => (bool) ($row['circuit_reset'] ?? false))),
                ],
            ];
        }

        $payload['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
        $processed = (int) ($payload['summary']['checked'] ?? $payload['summary']['recovery_attempted'] ?? 0);

        if ($this->option('json')) {
            $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
            if (! $this->option('compact')) {
                $flags |= JSON_PRETTY_PRINT;
            }
            $this->line(json_encode($payload, $flags));
        } else {
            $this->info(sprintf(
                'Compute health reconcile: status=%s scope=%s processed=%d',
                $payload['status'],
                $payload['scope'],
                $processed
            ));
            if (($payload['status'] ?? null) === 'deferred') {
                $this->line('[DEFERRED:compute_capability_unavailable]');
            }
            $this->line(sprintf('[ITEMS_PROCESSED:%d]', $processed));
        }

        return self::SUCCESS;
    }
}
