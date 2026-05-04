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
        {--compact : Emit routine-check compact output}
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
            $json = json_encode(
                $this->option('compact') ? $report->compactPayload($payload) : $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
            if ($json === false) {
                $this->error('Failed to encode review backlog JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($this->option('compact') ? $report->toCompactMarkdown($payload) : $report->toMarkdown($payload));

            return self::SUCCESS;
        }

        if ($this->option('compact')) {
            $this->line($report->toCompactText($payload));

            return self::SUCCESS;
        }

        $summary = $payload['summary'] ?? [];
        $this->line(sprintf(
            'Review backlog report: %s mode=%s dry_run=%s queries_executed=%s query_state=%s pending=%s stale=%s high_priority=%s captured=%s',
            $payload['status'] ?? 'unknown',
            $payload['mode'] ?? 'observe',
            ($payload['dry_run'] ?? false) ? 'true' : 'false',
            ($payload['queries_executed'] ?? false) ? 'true' : 'false',
            $payload['query_state'] ?? 'unknown',
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

        foreach (($payload['triage_buckets'] ?? []) as $row) {
            $this->line(sprintf(
                'triage=%s pending=%s high_priority=%s oldest=%s action=%s',
                $row['category'] ?? 'unknown',
                $row['pending'] ?? 0,
                $row['high_priority_pending'] ?? 0,
                $row['oldest_pending_at'] ?? 'none',
                $row['next_action'] ?? 'Review one at a time.'
            ));
        }

        foreach (($payload['next_classification_needed'] ?? []) as $row) {
            $this->line(sprintf(
                'next_classification=%s stale=%s high_priority=%s oldest=%s action=%s',
                $row['classification'] ?? 'unknown',
                $row['stale_pending'] ?? 0,
                $row['high_priority_pending'] ?? 0,
                $row['oldest_pending_at'] ?? 'none',
                $row['next_action'] ?? 'Classify one at a time.'
            ));
        }

        foreach (($payload['cleanup_sequence'] ?? []) as $row) {
            $this->line(sprintf(
                'cleanup_step=%s focus=%s pending=%s stale=%s high_priority=%s action=%s',
                $row['rank'] ?? 0,
                $row['focus'] ?? 'unknown',
                $row['pending'] ?? 0,
                $row['stale_pending'] ?? 0,
                $row['high_priority_pending'] ?? 0,
                $row['next_action'] ?? 'Review one at a time.'
            ));
        }

        $readiness = $payload['remediation_readiness'] ?? [];
        if ($readiness !== []) {
            $this->line(sprintf(
                'remediation_readiness sample_limit=%s pending_typed=%s apply_preview=%s preview_only=%s supported_preview=%s context_without_preview=%s without_ids=%s source_duplicate_ids=%s family_duplicate_ids=%s source_change_context=%s family_context=%s family_id_keys=%s family_ids=%s family_comparisons=%s malformed_details=%s',
                $readiness['sample_limit'] ?? 0,
                $readiness['pending_typed_remediation_rows'] ?? 0,
                $readiness['apply_preview_rows'] ?? 0,
                $readiness['preview_only_rows'] ?? 0,
                $readiness['supported_preview_operation_rows'] ?? 0,
                $readiness['context_ready_without_preview_rows'] ?? 0,
                $readiness['without_materialized_ids'] ?? 0,
                $readiness['source_duplicate_id_candidates'] ?? 0,
                $readiness['family_duplicate_id_candidates'] ?? 0,
                $readiness['source_proposed_change_id_rows'] ?? 0,
                $readiness['family_context_rows'] ?? 0,
                $readiness['family_id_key_context_rows'] ?? 0,
                $readiness['family_ids_context_rows'] ?? 0,
                $readiness['family_comparison_context_rows'] ?? 0,
                $readiness['malformed_details'] ?? 0,
            ));

            foreach (($readiness['possible_change_type_typos'] ?? []) as $changeType => $typo) {
                if (! is_array($typo)) {
                    continue;
                }

                $this->line(sprintf(
                    'remediation_change_type_typo=%s suggested=%s rows=%s',
                    $changeType,
                    $typo['suggested_change_type'] ?? 'unknown',
                    $typo['rows'] ?? 0,
                ));
            }
        }

        foreach (($payload['recommendations'] ?? []) as $recommendation) {
            $this->warn('review: '.$recommendation);
        }

        return self::SUCCESS;
    }
}
