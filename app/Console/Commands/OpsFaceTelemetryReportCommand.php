<?php

namespace App\Console\Commands;

use App\Services\Ops\FaceTelemetryReportService;
use Illuminate\Console\Command;

class OpsFaceTelemetryReportCommand extends Command
{
    protected $signature = 'ops:face-telemetry-report
        {--hours=24 : Recent activity window in hours}
        {--markdown : Emit Markdown}
        {--json : Emit machine-readable JSON}
        {--dry-run : Validate command shape without running database probes}';

    protected $description = 'Observe-only face, genealogy bridge, review queue, and pgvector telemetry report';

    public function handle(FaceTelemetryReportService $report): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        $payload = $report->collect(
            hours: (int) $this->option('hours'),
            dryRun: (bool) $this->option('dry-run')
        );

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode face telemetry JSON.');

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
            'Face telemetry report: %s mode=%s dry_run=%s window_hours=%s captured=%s',
            $payload['status'] ?? 'unknown',
            $payload['mode'] ?? 'observe',
            ($payload['dry_run'] ?? false) ? 'true' : 'false',
            $payload['window_hours'] ?? '-',
            $payload['captured_at'] ?? '-'
        ));

        foreach (($payload['sections'] ?? []) as $name => $section) {
            $summary = $section['summary'] ?? [];
            if ($name === 'candidate_decisions' && is_array($summary)) {
                $this->line(sprintf(
                    '%s: %s decisions=%s recent=%s latest=%s terminal=%s keep=%s outside=%s vague=%s not-this=%s defer=%s',
                    str_replace('_', '-', (string) $name),
                    (string) ($section['status'] ?? 'unknown'),
                    $summary['decision_rows'] ?? 0,
                    $summary['recent_decisions'] ?? 0,
                    $summary['latest_decision_at'] ?? 'none',
                    $summary['terminal_decisions'] ?? 0,
                    $summary['keep_name_only'] ?? 0,
                    $summary['outside_tree'] ?? 0,
                    $summary['too_vague'] ?? 0,
                    $summary['not_this_person'] ?? 0,
                    $summary['deferred'] ?? 0,
                ));

                continue;
            }

            $this->line(sprintf('%s: %s', str_replace('_', '-', (string) $name), (string) ($section['status'] ?? 'unknown')));
        }

        foreach (($payload['recommendations'] ?? []) as $recommendation) {
            $this->warn('review: '.$recommendation);
        }

        return self::SUCCESS;
    }
}
