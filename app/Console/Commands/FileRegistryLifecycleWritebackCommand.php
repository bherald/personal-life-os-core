<?php

namespace App\Console\Commands;

use App\Services\FileRegistryLifecycleWritebackService;
use Illuminate\Console\Command;

class FileRegistryLifecycleWritebackCommand extends Command
{
    protected $signature = 'files:reconcile-lifecycle-writeback
        {--move=* : Registry-only move selector in asset_uuid=/new/path form}
        {--soft-delete=* : Registry lifecycle soft-delete selector in asset_uuid[=reason] form}
        {--apply : Apply planned writeback operations}
        {--confirm= : Required confirmation token for --apply}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Dry-run-first file lifecycle writeback for explicit move and soft-delete selectors';

    public function handle(FileRegistryLifecycleWritebackService $writeback): int
    {
        $moveSelectors = (array) $this->option('move');
        $softDeleteSelectors = (array) $this->option('soft-delete');
        $apply = (bool) $this->option('apply');

        if ($moveSelectors === [] && $softDeleteSelectors === []) {
            $this->emit([
                'version' => 1,
                'mode' => $apply ? 'apply' : 'dry_run',
                'status' => 'blocked',
                'errors' => ['At least one selector is required: --move=asset_uuid=/new/path or --soft-delete=asset_uuid[=reason].'],
                'unsupported_operations' => $writeback->unsupportedOperations(),
            ]);

            return self::FAILURE;
        }

        if ($apply && ! (bool) config('file_lifecycle.writeback_enabled', false)) {
            $this->emit([
                'version' => 1,
                'mode' => 'apply',
                'status' => 'blocked',
                'errors' => ['Apply is blocked until FILE_LIFECYCLE_WRITEBACK_ENABLED=true.'],
                'unsupported_operations' => $writeback->unsupportedOperations(),
            ]);

            return self::FAILURE;
        }

        if ($apply && $this->option('confirm') !== FileRegistryLifecycleWritebackService::CONFIRMATION_TOKEN) {
            $this->emit([
                'version' => 1,
                'mode' => 'apply',
                'status' => 'blocked',
                'errors' => ['Apply requires --confirm='.FileRegistryLifecycleWritebackService::CONFIRMATION_TOKEN.'.'],
                'unsupported_operations' => $writeback->unsupportedOperations(),
            ]);

            return self::FAILURE;
        }

        $payload = $writeback->run($moveSelectors, $softDeleteSelectors, $apply);
        $this->emit($payload);

        return ($payload['errors'] ?? []) === [] && ($payload['status'] ?? null) !== 'partial_failure'
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->line($json === false ? '{"status":"encode_failed"}' : $json);

            return;
        }

        $this->line(sprintf(
            'file-lifecycle-writeback: %s mode=%s planned=%s applied=%s failed=%s',
            $payload['status'] ?? 'unknown',
            $payload['mode'] ?? 'dry_run',
            $payload['counts']['planned'] ?? 0,
            $payload['counts']['applied'] ?? 0,
            $payload['counts']['failed'] ?? 0
        ));

        foreach (($payload['errors'] ?? []) as $error) {
            $this->error($error);
        }

        foreach (($payload['unsupported_operations'] ?? []) as $operation => $details) {
            $this->warn(sprintf(
                'unsupported: %s status=%s reason=%s',
                $operation,
                $details['status'] ?? 'blocked',
                $details['reason'] ?? 'unsupported'
            ));
        }
    }
}
