<?php

namespace App\Console\Commands;

use App\Services\AgentProceduralMemoryService;
use Illuminate\Console\Command;

class GenealogyRejectCodesCommand extends Command
{
    protected $signature = 'genealogy:reject-codes
                            {--agent= : Limit rollup to one agent_id}
                            {--days=30 : Lookback window in days}
                            {--json : Output machine-readable JSON}';

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

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy reject-code JSON.');

                return self::FAILURE;
            }

            $this->line($json);

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
