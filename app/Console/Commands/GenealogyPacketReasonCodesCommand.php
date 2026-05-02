<?php

namespace App\Console\Commands;

use App\Services\AgentProceduralMemoryService;
use Illuminate\Console\Command;

class GenealogyPacketReasonCodesCommand extends Command
{
    protected $signature = 'genealogy:packet-reason-codes
                            {--agent= : Limit rollup to one agent_id}
                            {--days=30 : Lookback window in days}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Read-only daily rollup of genealogy review-packet reason codes';

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
            'rollup' => $memory->getReviewPacketReasonCodeDailyRollup($days, $agent !== '' ? $agent : null),
        ];

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy review-packet reason-code JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Genealogy review-packet reason-code rollup, last %d day(s)%s',
            $days,
            $agent !== '' ? " for {$agent}" : ''
        ));

        if ($payload['rollup'] === []) {
            $this->info('No genealogy review-packet reason-code feedback found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Date', 'Agent', 'Decisions', 'Top Action', 'Top Reason'],
            array_map(fn (array $row): array => [
                $row['date'] ?? '',
                $row['agent_id'] ?? '',
                $row['total_decisions'] ?? 0,
                $this->topHistogramValue($row['action_histogram'] ?? []),
                $this->topHistogramValue($row['reason_code_histogram'] ?? []),
            ], $payload['rollup'])
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $histogram
     */
    private function topHistogramValue(array $histogram): string
    {
        if ($histogram === []) {
            return '';
        }

        arsort($histogram);
        $key = array_key_first($histogram);
        $count = $histogram[$key] ?? 0;

        return "{$key} ({$count})";
    }
}
