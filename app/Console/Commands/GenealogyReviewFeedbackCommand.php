<?php

namespace App\Console\Commands;

use App\Services\AgentProceduralMemoryService;
use Illuminate\Console\Command;

class GenealogyReviewFeedbackCommand extends Command
{
    protected $signature = 'genealogy:review-feedback
                            {--agent= : Limit rollup to one agent_id}
                            {--days=30 : Lookback window in days}
                            {--json : Output machine-readable JSON}';

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

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        return $this->renderTable($payload);
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
