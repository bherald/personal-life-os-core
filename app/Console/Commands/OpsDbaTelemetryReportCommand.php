<?php

namespace App\Console\Commands;

use App\Services\Ops\DbaTelemetryReportService;
use Illuminate\Console\Command;

class OpsDbaTelemetryReportCommand extends Command
{
    protected $signature = 'ops:dba-telemetry-report
        {--weekly : Use weekly report labeling}
        {--markdown : Emit Markdown}
        {--json : Emit machine-readable JSON}
        {--deep : Run raw ARC growth aggregation even when table estimates exceed the safe scan limit}
        {--dry-run : Validate command shape without running database or Redis probes}';

    protected $description = 'Observe-only DBA telemetry report for storage, recursion telemetry, PostgreSQL, and Redis health';

    public function handle(DbaTelemetryReportService $report): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        $payload = $report->collect(
            weekly: (bool) $this->option('weekly'),
            dryRun: (bool) $this->option('dry-run'),
            deep: (bool) $this->option('deep')
        );

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode DBA telemetry JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($report->toMarkdown($payload));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'DBA telemetry report: %s mode=%s dry_run=%s deep=%s window=%s captured=%s',
            $payload['status'] ?? 'unknown',
            $payload['mode'] ?? 'observe',
            ($payload['dry_run'] ?? false) ? 'true' : 'false',
            ($payload['deep'] ?? false) ? 'true' : 'false',
            $payload['window'] ?? 'current',
            $payload['captured_at'] ?? '-'
        ));

        foreach (($payload['sections'] ?? []) as $name => $section) {
            $this->line(sprintf('%s: %s', str_replace('_', '-', (string) $name), (string) ($section['status'] ?? 'unknown')));
        }

        foreach (($payload['recommendations'] ?? []) as $recommendation) {
            $this->warn('review: '.$recommendation);
        }

        return self::SUCCESS;
    }
}
