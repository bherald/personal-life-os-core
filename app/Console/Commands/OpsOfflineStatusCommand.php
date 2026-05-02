<?php

namespace App\Console\Commands;

use App\Services\OperatorEvidenceService;
use Illuminate\Console\Command;

class OpsOfflineStatusCommand extends Command
{
    protected $signature = 'ops:offline-status
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only offline/degraded runtime profile and audit status';

    public function handle(OperatorEvidenceService $evidence): int
    {
        $payload = $evidence->collectOfflineStatus();

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode offline status JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $counts = $payload['section']['counts'] ?? [];
        $this->line(sprintf(
            'offline status: %s runtime=%s profile=%s offline_mode=%s denials_24h=%d',
            $payload['status'] ?? 'unknown',
            $counts['runtime_state'] ?? 'unknown',
            $counts['active_profile'] ?? 'unknown',
            ($counts['offline_mode_active'] ?? false) ? 'enabled' : 'disabled',
            (int) ($counts['policy_denials_24h'] ?? 0)
        ));

        return self::SUCCESS;
    }
}
