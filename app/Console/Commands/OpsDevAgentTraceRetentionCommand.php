<?php

namespace App\Console\Commands;

use App\Services\DevAgent\TraceEnvelopeService;
use Illuminate\Console\Command;

class OpsDevAgentTraceRetentionCommand extends Command
{
    protected $signature = 'ops:dev-agent-trace-retention
        {--retention-days= : Days of date-named trace files to retain; defaults to dev_agent.trace.retention_days}
        {--max-files=100 : Maximum expired trace files selected per run, capped at 1000}
        {--execute : Delete the selected expired trace files}
        {--confirm : Confirm deletion when --execute is used}
        {--json : Emit machine-readable JSON}
        {--compact : Emit aggregate-only scheduled-output-safe JSON}';

    protected $description = 'Dry-run-first bounded retention for date-named dev-agent trace files';

    public function handle(TraceEnvelopeService $traces): int
    {
        $retentionDays = $this->positiveIntOption('retention-days')
            ?? max(1, (int) config('dev_agent.trace.retention_days', 14));
        $maxFiles = $this->positiveIntOption('max-files');

        if ($retentionDays > 3650) {
            $this->error('Retention days must be between 1 and 3650.');

            return self::INVALID;
        }

        if ($maxFiles === null || $maxFiles > 1000) {
            $this->error('Max files must be between 1 and 1000.');

            return self::INVALID;
        }

        $execute = (bool) $this->option('execute');
        if ($execute && ! (bool) $this->option('confirm')) {
            $this->error('Trace retention execution requires --confirm. Run without --execute to preview.');

            return self::INVALID;
        }

        $payload = $traces->pruneRetention($retentionDays, $maxFiles, $execute);
        $payload['command'] = 'ops:dev-agent-trace-retention';
        $payload['confirmed'] = $execute && (bool) $this->option('confirm');
        $payload['generated_at'] = now()->utc()->toIso8601String();
        $payload['posture'] = [
            'strict_date_named_files_only' => true,
            'storage_app_scope_only' => true,
            'raw_trace_content_included' => false,
            'filesystem_paths_included' => false,
        ];

        if ((bool) $this->option('compact')) {
            unset($payload['candidates'], $payload['failed_files']);
            $payload['compact'] = true;
        }

        if ((bool) $this->option('json') || (bool) $this->option('compact')) {
            $flags = JSON_UNESCAPED_SLASHES;
            if (! (bool) $this->option('compact')) {
                $flags |= JSON_PRETTY_PRINT;
            }

            $this->line(json_encode($payload, $flags) ?: '{}');
        } else {
            $this->line(sprintf(
                'dev-agent-trace-retention: status=%s dry_run=%s eligible=%d selected=%d deleted=%d failures=%d',
                (string) ($payload['status'] ?? 'unknown'),
                ($payload['dry_run'] ?? true) ? 'yes' : 'no',
                (int) ($payload['eligible_files'] ?? 0),
                (int) ($payload['selected_files'] ?? 0),
                (int) ($payload['deleted_files'] ?? 0),
                (int) ($payload['failures'] ?? 0),
            ));
        }

        return in_array(($payload['status'] ?? null), ['pass'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = $this->option($name);
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
