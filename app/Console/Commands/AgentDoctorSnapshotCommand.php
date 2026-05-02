<?php

namespace App\Console\Commands;

use App\Services\Ops\AgentDoctorReadinessSnapshotService;
use Illuminate\Console\Command;

class AgentDoctorSnapshotCommand extends Command
{
    protected $signature = 'ops:agent-doctor-snapshot
        {--dry-run : Build the snapshot payload without writing}
        {--json : Emit machine-readable JSON}
        {--since=24 : Window size in hours, 1-168}';

    protected $description = 'Persist a manual aggregate Agent Doctor readiness snapshot';

    public function handle(AgentDoctorReadinessSnapshotService $service): int
    {
        $hours = filter_var($this->option('since'), FILTER_VALIDATE_INT);
        if (! is_int($hours) || $hours < 1 || $hours > 168) {
            $this->error('Since must be an integer from 1 to 168 hours.');

            return self::INVALID;
        }

        $payload = $service->capture(
            windowHours: $hours,
            dryRun: (bool) $this->option('dry-run')
        );

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode agent-doctor snapshot JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $snapshot = $payload['snapshot'];
        $this->line(sprintf(
            'agent doctor snapshot: %s agents=%d warnings=%d critical=%d trace=%s persisted=%s id=%s',
            (string) ($snapshot['overall_status'] ?? 'unknown'),
            (int) ($snapshot['agent_count'] ?? 0),
            (int) ($snapshot['warning_count'] ?? 0),
            (int) ($snapshot['critical_count'] ?? 0),
            (string) ($snapshot['trace_status'] ?? 'unknown'),
            ($payload['persisted'] ?? false) ? 'yes' : 'no',
            (string) ($payload['snapshot_id'] ?? 'dry-run'),
        ));

        return self::SUCCESS;
    }
}
