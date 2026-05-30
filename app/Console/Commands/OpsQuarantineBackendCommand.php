<?php

namespace App\Console\Commands;

use App\Services\Ops\BackendQuarantineService;
use Illuminate\Console\Command;

class OpsQuarantineBackendCommand extends Command
{
    protected $signature = 'ops:quarantine-backend
        {type : Backend type: provider or tool}
        {id : Provider instance_id or agent tool name}
        {--reason=manual quarantine : Operator reason; secrets and local paths are redacted before storage}
        {--confirm : Write quarantine gates; without this, command is dry-run}
        {--dry-run : Force dry-run even when --confirm is present}
        {--allow-primary : Permit quarantining primary local/Codex lanes}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Emergency quarantine for optional LLM providers or agent tools using table-backed gates';

    public function handle(BackendQuarantineService $quarantine): int
    {
        $dryRun = ! (bool) $this->option('confirm') || (bool) $this->option('dry-run');

        $payload = $quarantine->quarantine(
            type: (string) $this->argument('type'),
            id: (string) $this->argument('id'),
            options: [
                'reason' => (string) $this->option('reason'),
                'confirm' => (bool) $this->option('confirm'),
                'dry_run' => $dryRun,
                'allow_primary' => (bool) $this->option('allow-primary'),
                'actor' => 'ops:quarantine-backend',
            ]
        );

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode quarantine JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $line = (string) ($payload['result_text'] ?? $payload['message'] ?? 'Quarantine command completed.');
        ($payload['success'] ?? false) ? $this->line($line) : $this->error($line);

        if (($payload['success'] ?? false) && ($payload['dry_run'] ?? true)) {
            $this->warn('Dry-run only. Re-run with --confirm to write quarantine gates.');
        }

        return ($payload['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
