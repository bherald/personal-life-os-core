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
        {--compact : Emit compact status-check summary without full table dumps}
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
        if ($this->option('compact')) {
            $payload = $report->compactPayload($payload);
        }

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
            $this->line($this->option('compact')
                ? $report->compactToMarkdown($payload)
                : $report->toMarkdown($payload));

            return self::SUCCESS;
        }

        if ($this->option('compact')) {
            $this->renderCompactText($payload);

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

    private function renderCompactText(array $payload): void
    {
        $this->line(sprintf(
            'DBA telemetry report: %s mode=%s dry_run=%s deep=%s window=%s compact=true breaches=%d recommendations=%d captured=%s',
            $payload['status'] ?? 'unknown',
            $payload['mode'] ?? 'observe',
            ($payload['dry_run'] ?? false) ? 'true' : 'false',
            ($payload['deep'] ?? false) ? 'true' : 'false',
            $payload['window'] ?? 'current',
            (int) ($payload['threshold_breach_count'] ?? 0),
            (int) ($payload['recommendation_count'] ?? 0),
            $payload['captured_at'] ?? '-'
        ));

        $breachIds = $payload['threshold_breach_ids'] ?? [];
        $this->line('threshold_breach_ids: '.($breachIds === [] ? 'none' : implode(', ', $breachIds)));

        $arc = $payload['arc'] ?? [];
        $this->line(sprintf(
            'arc: rows_estimate=%s total_gb=%s oldest=%s raw_scan_skipped=%s',
            $arc['rows_total_estimate'] ?? 'n/a',
            $arc['total_gb'] ?? 'n/a',
            $arc['oldest_created_at'] ?? 'n/a',
            ($arc['raw_recent_scan_skipped'] ?? false) ? 'true' : 'false'
        ));

        $redis = $payload['redis'] ?? [];
        $this->line(sprintf(
            'redis: status=%s used_mb=%s memory_ratio=%s fragmentation=%s keys=%s',
            $redis['status'] ?? 'unknown',
            $redis['used_memory_mb'] ?? 'n/a',
            $redis['memory_ratio'] ?? 'n/a',
            $redis['fragmentation_ratio'] ?? 'n/a',
            $redis['key_count'] ?? 'n/a'
        ));

        $postgres = $payload['postgres'] ?? [];
        $this->line(sprintf(
            'postgres: status=%s total_gb=%s dead_tuple_top_count=%s',
            $postgres['status'] ?? 'unknown',
            $postgres['database_total_gb'] ?? 'n/a',
            $postgres['dead_tuple_top_count'] ?? 'n/a'
        ));
    }
}
