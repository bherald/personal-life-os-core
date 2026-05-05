<?php

namespace App\Console\Commands;

use App\Services\AgentMetrics\AwoReplayService;
use Illuminate\Console\Command;

class AwoReplayCommand extends Command
{
    protected $signature = 'awo:replay
        {--window=7d : Replay window, e.g. 60m, 24h, 7d}
        {--limit=500 : Maximum review rows to score}
        {--compare-scheduled : Compare current replay summary with latest retained weekly scheduled report}
        {--scheduled-job=awo_replay_weekly_report : Scheduled job name for --compare-scheduled}
        {--compact : Emit sanitized compact output without row-level replay items}
        {--markdown : Emit Markdown}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only replay of approval-worthy-output scoring over stored review rows';

    public function handle(AwoReplayService $replay): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        if ($this->option('compare-scheduled') && $this->option('compact')) {
            $this->error('The --compact option is only supported for normal replay output.');

            return self::FAILURE;
        }

        if ($this->option('compare-scheduled')) {
            $payload = $replay->collectScheduledComparison(
                window: (string) $this->option('window'),
                limit: (int) $this->option('limit'),
                jobName: (string) $this->option('scheduled-job')
            );

            if ($this->option('json')) {
                $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    $this->error('Failed to encode AWO scheduled comparison JSON.');

                    return self::FAILURE;
                }

                $this->line($json);

                return self::SUCCESS;
            }

            if ($this->option('markdown')) {
                $this->line($replay->comparisonToMarkdown($payload));

                return self::SUCCESS;
            }

            $this->line(sprintf(
                'AWO scheduled comparison: %s job=%s latest_run=%s fields=%d',
                $payload['status'] ?? 'unknown',
                $payload['job']['name'] ?? 'missing',
                $payload['latest_scheduled_run']['completed_at'] ?? 'none',
                count($payload['field_matches'] ?? [])
            ));

            if (($payload['status'] ?? null) !== 'observe_ok') {
                $this->warn('review: scheduled report comparison is not yet matched; keep AWO recording disabled.');
            }

            return self::SUCCESS;
        }

        try {
            $payload = $replay->collect(
                window: (string) $this->option('window'),
                limit: (int) $this->option('limit')
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $json = json_encode(
                $this->option('compact') ? $replay->compactPayload($payload) : $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
            if ($json === false) {
                $this->error('Failed to encode AWO replay JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($this->option('compact') ? $replay->toCompactMarkdown($payload) : $replay->toMarkdown($payload));

            return self::SUCCESS;
        }

        if ($this->option('compact')) {
            $this->line($replay->toCompactText($payload));

            return self::SUCCESS;
        }

        $summary = $payload['summary'] ?? [];
        $this->line(sprintf(
            'AWO replay: %s window=%s rows=%d completed=%d approval_worthy=%d completed_hard_fails=%d scanned_hard_fail_signals=%d pending_hard_fail_signals=%d',
            $payload['status'] ?? 'unknown',
            $payload['window'] ?? 'unknown',
            (int) ($summary['rows_scanned'] ?? 0),
            (int) ($summary['completed_reviews'] ?? 0),
            (int) ($summary['approval_worthy_reviews'] ?? 0),
            (int) ($summary['completed_hard_fail_count'] ?? $summary['hard_fail_count'] ?? 0),
            (int) ($summary['scanned_hard_fail_signal_count'] ?? $summary['hard_fail_count'] ?? 0),
            (int) ($summary['pending_hard_fail_signal_count'] ?? 0),
        ));

        if (($summary['insufficient_data'] ?? true) === true) {
            $this->warn('insufficient_data: fewer than 10 completed reviews in the replay window.');
        }

        return self::SUCCESS;
    }
}
