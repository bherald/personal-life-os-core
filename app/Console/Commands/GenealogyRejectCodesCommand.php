<?php

namespace App\Console\Commands;

use App\Services\AgentProceduralMemoryService;
use Illuminate\Console\Command;

class GenealogyRejectCodesCommand extends Command
{
    protected $signature = 'genealogy:reject-codes
                            {--agent= : Limit rollup to one agent_id}
                            {--days=30 : Lookback window in days}
                            {--json : Output machine-readable JSON}
                            {--compact : Emit compact aggregate output}';

    protected $description = 'Read-only daily rollup of structured genealogy reject reason codes';

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
            'rollup' => $memory->getReviewerFeedbackDailyRollup($days, $agent !== '' ? $agent : null),
        ];
        $compact = (bool) $this->option('compact');
        $outputPayload = $compact ? $this->compactPayload($payload) : $payload;

        if ($this->option('json')) {
            $json = json_encode($outputPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy reject-code JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($compact) {
            $this->renderCompact($outputPayload);

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Genealogy reject-code rollup, last %d day(s)%s',
            $days,
            $agent !== '' ? " for {$agent}" : ''
        ));

        if ($payload['rollup'] === []) {
            $this->info('No structured genealogy reject-code feedback found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Date', 'Agent', 'Reviews', 'Accepted', 'Rejected', 'Accept %', 'Top Reject'],
            array_map(fn (array $row): array => [
                $row['date'] ?? '',
                $row['agent_id'] ?? '',
                $row['total_reviews'] ?? 0,
                $row['accepted_proposals'] ?? 0,
                $row['rejected_proposals'] ?? 0,
                $this->acceptancePercent($row['acceptance_rate'] ?? null),
                $this->topRejectReason($row['reject_reason_histogram'] ?? []),
            ], $payload['rollup'])
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        $rows = $payload['rollup'] ?? [];
        if (! is_array($rows)) {
            $rows = [];
        }

        $totalReviews = 0;
        $accepted = 0;
        $rejected = 0;
        $agents = [];
        $rejectReasons = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $totalReviews += (int) ($row['total_reviews'] ?? 0);
            $accepted += (int) ($row['accepted_proposals'] ?? 0);
            $rejected += (int) ($row['rejected_proposals'] ?? 0);

            $agent = (string) ($row['agent_id'] ?? '');
            if ($agent !== '') {
                $agents[$agent] = true;
            }

            $histogram = $row['reject_reason_histogram'] ?? [];
            if (! is_array($histogram)) {
                continue;
            }

            foreach ($histogram as $reason => $count) {
                $reason = (string) $reason;
                if ($reason === '') {
                    continue;
                }

                $rejectReasons[$reason] = ($rejectReasons[$reason] ?? 0) + (int) $count;
            }
        }

        arsort($rejectReasons);
        $topRejectReason = array_key_first($rejectReasons);

        return [
            'compact' => true,
            'generated_at' => $payload['generated_at'] ?? null,
            'window_days' => (int) ($payload['window_days'] ?? 0),
            'agent' => $payload['agent'] ?? null,
            'rollup_rows' => count(array_filter($rows, 'is_array')),
            'agent_count' => count($agents),
            'total_reviews' => $totalReviews,
            'accepted_proposals' => $accepted,
            'rejected_proposals' => $rejected,
            'acceptance_rate' => ($accepted + $rejected) > 0 ? round($accepted / ($accepted + $rejected), 4) : null,
            'top_reject_reason' => $topRejectReason,
            'top_reject_count' => $topRejectReason !== null ? (int) $rejectReasons[$topRejectReason] : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderCompact(array $payload): void
    {
        $agent = $payload['agent'] ?? null;
        $this->line(sprintf(
            'Genealogy reject codes compact: window=%dd agent=%s rows=%d agents=%d reviews=%d accepted=%d rejected=%d accept_rate=%s',
            (int) ($payload['window_days'] ?? 0),
            $agent === null ? 'all' : (string) $agent,
            (int) ($payload['rollup_rows'] ?? 0),
            (int) ($payload['agent_count'] ?? 0),
            (int) ($payload['total_reviews'] ?? 0),
            (int) ($payload['accepted_proposals'] ?? 0),
            (int) ($payload['rejected_proposals'] ?? 0),
            $this->acceptancePercent($payload['acceptance_rate'] ?? null),
        ));

        if (($payload['top_reject_reason'] ?? null) !== null) {
            $this->line(sprintf(
                'top_reject=%s (%d)',
                (string) $payload['top_reject_reason'],
                (int) ($payload['top_reject_count'] ?? 0),
            ));
        }
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
