<?php

namespace App\Console\Commands;

use App\Services\AgentProceduralMemoryService;
use Illuminate\Console\Command;

class GenealogyPacketReasonCodesCommand extends Command
{
    protected $signature = 'genealogy:packet-reason-codes
                            {--agent= : Limit rollup to one agent_id}
                            {--days=30 : Lookback window in days}
                            {--json : Output machine-readable JSON}
                            {--compact : Emit compact aggregate output}';

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
        $compact = (bool) $this->option('compact');
        $outputPayload = $compact ? $this->compactPayload($payload) : $payload;

        if ($this->option('json')) {
            $json = json_encode($outputPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy review-packet reason-code JSON.');

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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        $rows = $payload['rollup'] ?? [];
        if (! is_array($rows)) {
            $rows = [];
        }

        $totalDecisions = 0;
        $agents = [];
        $actions = [];
        $reasons = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $totalDecisions += (int) ($row['total_decisions'] ?? 0);

            $agent = (string) ($row['agent_id'] ?? '');
            if ($agent !== '') {
                $agents[$agent] = true;
            }

            $this->addHistogram($actions, $row['action_histogram'] ?? []);
            $this->addHistogram($reasons, $row['reason_code_histogram'] ?? []);
        }

        arsort($actions);
        arsort($reasons);
        $topAction = array_key_first($actions);
        $topReason = array_key_first($reasons);

        return [
            'compact' => true,
            'generated_at' => $payload['generated_at'] ?? null,
            'window_days' => (int) ($payload['window_days'] ?? 0),
            'agent' => $payload['agent'] ?? null,
            'rollup_rows' => count(array_filter($rows, 'is_array')),
            'agent_count' => count($agents),
            'total_decisions' => $totalDecisions,
            'top_action' => $topAction,
            'top_action_count' => $topAction !== null ? (int) $actions[$topAction] : 0,
            'top_reason_code' => $topReason,
            'top_reason_code_count' => $topReason !== null ? (int) $reasons[$topReason] : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function addHistogram(array &$target, mixed $histogram): void
    {
        if (! is_array($histogram)) {
            return;
        }

        foreach ($histogram as $key => $count) {
            $key = (string) $key;
            if ($key === '') {
                continue;
            }

            $target[$key] = ($target[$key] ?? 0) + (int) $count;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderCompact(array $payload): void
    {
        $agent = $payload['agent'] ?? null;
        $this->line(sprintf(
            'Genealogy packet reason codes compact: window=%dd agent=%s rows=%d agents=%d decisions=%d',
            (int) ($payload['window_days'] ?? 0),
            $agent === null ? 'all' : (string) $agent,
            (int) ($payload['rollup_rows'] ?? 0),
            (int) ($payload['agent_count'] ?? 0),
            (int) ($payload['total_decisions'] ?? 0),
        ));

        if (($payload['top_action'] ?? null) !== null) {
            $this->line(sprintf(
                'top_action=%s (%d)',
                (string) $payload['top_action'],
                (int) ($payload['top_action_count'] ?? 0),
            ));
        }

        if (($payload['top_reason_code'] ?? null) !== null) {
            $this->line(sprintf(
                'top_reason=%s (%d)',
                (string) $payload['top_reason_code'],
                (int) ($payload['top_reason_code_count'] ?? 0),
            ));
        }
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
