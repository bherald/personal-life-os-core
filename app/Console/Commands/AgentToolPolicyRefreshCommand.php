<?php

namespace App\Console\Commands;

use App\Services\Ops\AgentToolPolicyRefreshService;
use Illuminate\Console\Command;

class AgentToolPolicyRefreshCommand extends Command
{
    protected $signature = 'ops:agent-tool-policy-refresh
                            {--apply : Write refreshed table-backed policy metadata}
                            {--name= : Refresh one tool name}
                            {--limit=0 : Limit scanned rows, capped at 1000}
                            {--json : Output machine-readable JSON}
                            {--compact : Omit per-tool changes from JSON output}';

    protected $description = 'Refresh table-backed agent tool availability, privacy posture, and output limits';

    public function handle(AgentToolPolicyRefreshService $service): int
    {
        $payload = $service->refresh(
            apply: (bool) $this->option('apply'),
            name: $this->option('name') ? (string) $this->option('name') : null,
            limit: (int) $this->option('limit')
        );

        if ((bool) $this->option('compact')) {
            unset($payload['changes']);
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $summary = $payload['summary'] ?? [];
        $this->line(sprintf(
            'Agent tool policy refresh: %s scanned=%s changed=%s applied=%s unavailable=%s external_no_private=%s',
            $payload['mode'] ?? 'blocked',
            $summary['total_scanned'] ?? 0,
            $summary['changed_total'] ?? 0,
            $summary['applied_total'] ?? 0,
            $summary['unavailable_total'] ?? 0,
            $summary['external_no_private_total'] ?? 0
        ));

        if (! empty($payload['message'])) {
            $this->line((string) $payload['message']);
        }

        return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
