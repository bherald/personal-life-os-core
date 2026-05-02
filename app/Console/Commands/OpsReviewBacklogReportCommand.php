<?php

namespace App\Console\Commands;

use App\Services\Ops\ReviewBacklogReportService;
use Illuminate\Console\Command;

class OpsReviewBacklogReportCommand extends Command
{
    protected $signature = 'ops:review-backlog-report
        {--stale-days=7 : Age threshold for stale pending review rows}
        {--high-priority=8 : Priority threshold for high-priority pending review rows}
        {--markdown : Emit Markdown}
        {--json : Emit machine-readable JSON}
        {--dry-run : Validate command shape without running review backlog queries}';

    protected $description = 'Observe-only review backlog summary grouped by age, type, agent, and priority';

    public function handle(ReviewBacklogReportService $report): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        $payload = $report->collect(
            staleDays: (int) $this->option('stale-days'),
            highPriorityThreshold: (int) $this->option('high-priority'),
            dryRun: (bool) $this->option('dry-run')
        );

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode review backlog JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($report->toMarkdown($payload));

            return self::SUCCESS;
        }

        $summary = $payload['summary'] ?? [];
        $this->line(sprintf(
            'Review backlog report: %s mode=%s dry_run=%s pending=%s stale=%s high_priority=%s captured=%s',
            $payload['status'] ?? 'unknown',
            $payload['mode'] ?? 'observe',
            ($payload['dry_run'] ?? false) ? 'true' : 'false',
            $summary['pending_total'] ?? 0,
            $summary['stale_pending'] ?? 0,
            $summary['high_priority_pending'] ?? 0,
            $payload['captured_at'] ?? '-'
        ));

        foreach (($payload['pending_by_age'] ?? []) as $row) {
            $this->line(sprintf(
                'age=%s pending=%s high_priority=%s oldest=%s',
                $row['bucket'] ?? 'unknown',
                $row['pending'] ?? 0,
                $row['high_priority_pending'] ?? 0,
                $row['oldest_pending_at'] ?? 'none'
            ));
        }

        foreach (array_slice(($payload['pending_by_type'] ?? []), 0, 10) as $row) {
            $this->line(sprintf(
                'type=%s finding=%s pending=%s high_priority=%s oldest=%s',
                $row['review_type'] ?? 'unknown',
                $row['finding_type'] ?? 'none',
                $row['pending'] ?? 0,
                $row['high_priority_pending'] ?? 0,
                $row['oldest_pending_at'] ?? 'none'
            ));
        }

        foreach (($payload['recommendations'] ?? []) as $recommendation) {
            $this->warn('review: '.$recommendation);
        }

        return self::SUCCESS;
    }
}
