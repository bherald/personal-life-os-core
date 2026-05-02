<?php

namespace App\Console\Commands;

use App\Services\AgentMetrics\GenealogyAgentTriageService;
use Illuminate\Console\Command;

class GenealogyAgentTriageCommand extends Command
{
    protected $signature = 'genealogy:agent-triage
        {--days=30 : Lookback window in days, 1-365}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only triage of disabled or low-yield genealogy sub-agents before re-enablement review';

    public function handle(GenealogyAgentTriageService $triage): int
    {
        $days = (int) $this->option('days');
        if ($days < 1 || $days > 365) {
            $this->error('--days must be between 1 and 365');

            return self::FAILURE;
        }

        $payload = $triage->collect($days);

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy agent triage JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $summary = $payload['summary'] ?? [];
        $this->line(sprintf(
            'Genealogy agent triage: %s window=%dd targets=%d disabled=%d missing=%d',
            strtoupper((string) ($payload['status'] ?? 'unknown')),
            (int) ($payload['window_days'] ?? $days),
            (int) ($summary['targets_total'] ?? 0),
            (int) ($summary['disabled_targets'] ?? 0),
            (int) ($summary['missing_targets'] ?? 0),
        ));

        $this->table(
            ['Job', 'Agent', 'Enabled', 'Status', 'Sessions', 'Reviews', 'AWO', 'Next Action'],
            array_map(fn (array $target): array => [
                $target['job_name'] ?? '',
                $target['agent_id'] ?? '-',
                ! empty($target['enabled']) ? 'yes' : 'no',
                $target['triage_state'] ?? $target['status'] ?? 'unknown',
                (string) ($target['sessions']['completed'] ?? 0).'/'.(string) ($target['sessions']['total'] ?? 0),
                (string) ($target['reviews']['completed'] ?? 0).'/'.(string) ($target['reviews']['total'] ?? 0),
                $this->awoCell($target['awo'] ?? []),
                $target['next_action'] ?? '',
            ], $payload['targets'] ?? [])
        );

        foreach (($payload['recommendations'] ?? []) as $recommendation) {
            $this->warn((string) $recommendation);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $awo
     */
    private function awoCell(array $awo): string
    {
        $completed = (int) ($awo['completed_reviews'] ?? 0);
        $worthy = (int) ($awo['approval_worthy_reviews'] ?? 0);
        $rate = $awo['approval_worthy_rate'] ?? null;

        if (! is_numeric($rate)) {
            return "{$worthy}/{$completed}";
        }

        return sprintf('%d/%d (%d%%)', $worthy, $completed, (int) round((float) $rate * 100));
    }
}
