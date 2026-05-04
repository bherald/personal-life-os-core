<?php

namespace App\Console\Commands;

use App\Services\AgentProceduralMemoryService;
use Illuminate\Console\Command;

class GenealogyReviewFeedbackCommand extends Command
{
    protected $signature = 'genealogy:review-feedback
                            {--agent= : Limit rollup to one agent_id}
                            {--days=30 : Lookback window in days}
                            {--json : Output machine-readable JSON}
                            {--compact : Output compact aggregate summary}';

    protected $description = 'Read-only rollup of Phase 3 genealogy reviewer accept/reject feedback';

    public function handle(AgentProceduralMemoryService $memory): int
    {
        $days = (int) $this->option('days');
        if ($days < 1 || $days > 365) {
            $this->error('--days must be between 1 and 365');

            return self::FAILURE;
        }

        $agent = trim((string) ($this->option('agent') ?? ''));
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'window_days' => $days,
            'agent' => $agent !== '' ? $agent : null,
        ];

        if ($agent !== '') {
            $payload['summary'] = $memory->getReviewerFeedbackSummary($agent, $days);
        } else {
            $summaries = $memory->getReviewerFeedbackForAllAgents($days);
            $payload['total_agents'] = count($summaries);
            $payload['summaries'] = $summaries;
        }

        if ($this->option('compact')) {
            $compact = $this->compactPayload($payload);

            if ($this->option('json')) {
                $this->line(json_encode($compact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            return $this->renderCompact($compact);
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        return $this->renderTable($payload);
    }

    private function compactPayload(array $payload): array
    {
        $rows = isset($payload['summary'])
            ? [$payload['summary']]
            : ($payload['summaries'] ?? []);

        $accepted = 0;
        $rejected = 0;
        $reviews = 0;
        $rejectReasons = [];

        foreach ($rows as $row) {
            $reviews += (int) ($row['total_reviews'] ?? 0);
            $accepted += (int) ($row['accepted_proposals'] ?? 0);
            $rejected += (int) ($row['rejected_proposals'] ?? 0);

            foreach (($row['reject_reason_histogram'] ?? []) as $reason => $count) {
                $rejectReasons[$reason] = ($rejectReasons[$reason] ?? 0) + (int) $count;
            }
        }

        $total = $accepted + $rejected;
        $compact = [
            'compact' => true,
            'generated_at' => $payload['generated_at'] ?? now()->toIso8601String(),
            'window_days' => (int) ($payload['window_days'] ?? 30),
            'agent' => $payload['agent'] ?? null,
            'total_agents' => isset($payload['summary']) ? 1 : (int) ($payload['total_agents'] ?? count($rows)),
            'total_reviews' => $reviews,
            'accepted_proposals' => $accepted,
            'rejected_proposals' => $rejected,
            'acceptance_rate' => $total > 0 ? round($accepted / $total, 4) : null,
        ];

        if ($rejectReasons !== []) {
            arsort($rejectReasons);
            $reason = array_key_first($rejectReasons);
            $compact['top_reject_reason'] = $reason;
            $compact['top_reject_count'] = $rejectReasons[$reason];
        }

        return $compact;
    }

    private function renderCompact(array $compact): int
    {
        $this->line(sprintf(
            'Genealogy reviewer feedback: %d review(s), %d accepted, %d rejected, %s accepted over %d day(s).',
            (int) $compact['total_reviews'],
            (int) $compact['accepted_proposals'],
            (int) $compact['rejected_proposals'],
            $this->acceptancePercent($compact['acceptance_rate']),
            (int) $compact['window_days']
        ));

        if (isset($compact['top_reject_reason'], $compact['top_reject_count'])) {
            $this->line(sprintf(
                'Top reject: %s (%d).',
                (string) $compact['top_reject_reason'],
                (int) $compact['top_reject_count']
            ));
        }

        return self::SUCCESS;
    }

    private function renderTable(array $payload): int
    {
        $rows = isset($payload['summary'])
            ? [$payload['summary']]
            : ($payload['summaries'] ?? []);

        $this->line(sprintf(
            'Genealogy reviewer feedback, last %d day(s)',
            (int) ($payload['window_days'] ?? 30)
        ));

        if ($rows === []) {
            $this->info('No Phase 3 reviewer feedback found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Agent', 'Reviews', 'Accepted', 'Rejected', 'Accept %', 'Top Reject'],
            array_map(fn (array $row): array => [
                $row['agent_id'] ?? '',
                $row['total_reviews'] ?? 0,
                $row['accepted_proposals'] ?? 0,
                $row['rejected_proposals'] ?? 0,
                $this->acceptancePercent($row['acceptance_rate'] ?? null),
                $this->topRejectReason($row['reject_reason_histogram'] ?? []),
            ], $rows)
        );

        return self::SUCCESS;
    }

    private function acceptancePercent(mixed $rate): string
    {
        if (! is_numeric($rate)) {
            return 'n/a';
        }

        return (string) round(((float) $rate) * 100).'%';
    }

    /**
     * @param  array<string, int>  $histogram
     */
    private function topRejectReason(array $histogram): string
    {
        if ($histogram === []) {
            return '';
        }

        arsort($histogram);
        $reason = array_key_first($histogram);
        $count = $histogram[$reason] ?? 0;

        return "{$reason} ({$count})";
    }
}
