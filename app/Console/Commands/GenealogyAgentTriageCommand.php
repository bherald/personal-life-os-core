<?php

namespace App\Console\Commands;

use App\Services\AgentMetrics\GenealogyAgentTriageService;
use Illuminate\Console\Command;

class GenealogyAgentTriageCommand extends Command
{
    protected $signature = 'genealogy:agent-triage
        {--days=30 : Lookback window in days, 1-365}
        {--json : Emit machine-readable JSON}
        {--compact : Emit a reduced operator/MCP-safe projection}';

    protected $description = 'Read-only triage of disabled or low-yield genealogy sub-agents before re-enablement review';

    public function handle(GenealogyAgentTriageService $triage): int
    {
        $days = (int) $this->option('days');
        if ($days < 1 || $days > 365) {
            $this->error('--days must be between 1 and 365');

            return self::FAILURE;
        }

        $payload = $triage->collect($days);
        $compact = (bool) $this->option('compact');
        $outputPayload = $compact ? $this->compactPayload($payload) : $payload;

        if ($this->option('json')) {
            $json = json_encode($outputPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode genealogy agent triage JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($compact) {
            $this->renderCompact($outputPayload);

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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        $summary = $payload['summary'] ?? [];
        if (! is_array($summary)) {
            $summary = [];
        }

        $targetsNeedingReview = $summary['targets_needing_review'] ?? [];
        if (! is_array($targetsNeedingReview)) {
            $targetsNeedingReview = [];
        }

        $recommendations = $payload['recommendations'] ?? [];
        if (! is_array($recommendations)) {
            $recommendations = [];
        }

        $targets = $payload['targets'] ?? [];
        if (! is_array($targets)) {
            $targets = [];
        }

        return [
            'version' => $payload['version'] ?? 1,
            'mode' => $payload['mode'] ?? 'observe',
            'compact' => true,
            'generated_at' => $payload['generated_at'] ?? null,
            'window_days' => (int) ($payload['window_days'] ?? 0),
            'status' => $payload['status'] ?? 'unknown',
            'summary' => [
                'targets_total' => (int) ($summary['targets_total'] ?? 0),
                'configured_targets' => (int) ($summary['configured_targets'] ?? 0),
                'enabled_targets' => (int) ($summary['enabled_targets'] ?? 0),
                'disabled_targets' => (int) ($summary['disabled_targets'] ?? 0),
                'missing_targets' => (int) ($summary['missing_targets'] ?? 0),
                'blocked_targets' => (int) ($summary['blocked_targets'] ?? 0),
                'degraded_targets' => (int) ($summary['degraded_targets'] ?? 0),
                'watch_targets' => (int) ($summary['watch_targets'] ?? 0),
                'completed_sessions_window' => (int) ($summary['completed_sessions_window'] ?? 0),
                'review_outputs_window' => (int) ($summary['review_outputs_window'] ?? 0),
                'awo_completed_reviews_window' => (int) ($summary['awo_completed_reviews_window'] ?? 0),
                'awo_approval_worthy_reviews_window' => (int) ($summary['awo_approval_worthy_reviews_window'] ?? 0),
                'awo_approval_worthy_rate' => is_numeric($summary['awo_approval_worthy_rate'] ?? null)
                    ? (float) $summary['awo_approval_worthy_rate']
                    : null,
                'targets_needing_review_count' => count($targetsNeedingReview),
            ],
            'targets' => array_values(array_map(
                fn (array $target): array => $this->compactTarget($target),
                array_filter($targets, 'is_array')
            )),
            'recommendation_count' => count($recommendations),
            'recommendations' => array_values(array_map('strval', $recommendations)),
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function compactTarget(array $target): array
    {
        $sessions = $target['sessions'] ?? [];
        if (! is_array($sessions)) {
            $sessions = [];
        }

        $reviews = $target['reviews'] ?? [];
        if (! is_array($reviews)) {
            $reviews = [];
        }

        $awo = $target['awo'] ?? [];
        if (! is_array($awo)) {
            $awo = [];
        }

        return [
            'job_name' => $target['job_name'] ?? '',
            'agent_id' => $target['agent_id'] ?? null,
            'enabled' => ! empty($target['enabled']),
            'status' => $target['status'] ?? 'unknown',
            'triage_state' => $target['triage_state'] ?? 'unknown',
            'sessions_completed' => (int) ($sessions['completed'] ?? 0),
            'sessions_total' => (int) ($sessions['total'] ?? 0),
            'reviews_completed' => (int) ($reviews['completed'] ?? 0),
            'reviews_total' => (int) ($reviews['total'] ?? 0),
            'awo_completed_reviews' => (int) ($awo['completed_reviews'] ?? 0),
            'awo_approval_worthy_reviews' => (int) ($awo['approval_worthy_reviews'] ?? 0),
            'awo_approval_worthy_rate' => is_numeric($awo['approval_worthy_rate'] ?? null)
                ? (float) $awo['approval_worthy_rate']
                : null,
            'next_action' => $target['next_action'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderCompact(array $payload): void
    {
        $summary = $payload['summary'] ?? [];
        if (! is_array($summary)) {
            $summary = [];
        }

        $this->line(sprintf(
            'Genealogy agent triage compact: %s window=%dd targets=%d disabled=%d missing=%d review_needed=%d',
            strtoupper((string) ($payload['status'] ?? 'unknown')),
            (int) ($payload['window_days'] ?? 0),
            (int) ($summary['targets_total'] ?? 0),
            (int) ($summary['disabled_targets'] ?? 0),
            (int) ($summary['missing_targets'] ?? 0),
            (int) ($summary['targets_needing_review_count'] ?? 0),
        ));

        foreach (($payload['targets'] ?? []) as $target) {
            if (! is_array($target)) {
                continue;
            }

            $this->line(sprintf(
                'target=%s agent=%s enabled=%s state=%s sessions=%d/%d reviews=%d/%d awo=%d/%d action=%s',
                (string) ($target['job_name'] ?? ''),
                (string) ($target['agent_id'] ?? '-'),
                ! empty($target['enabled']) ? 'yes' : 'no',
                (string) ($target['triage_state'] ?? $target['status'] ?? 'unknown'),
                (int) ($target['sessions_completed'] ?? 0),
                (int) ($target['sessions_total'] ?? 0),
                (int) ($target['reviews_completed'] ?? 0),
                (int) ($target['reviews_total'] ?? 0),
                (int) ($target['awo_approval_worthy_reviews'] ?? 0),
                (int) ($target['awo_completed_reviews'] ?? 0),
                (string) ($target['next_action'] ?? ''),
            ));
        }

        $recommendationCount = (int) ($payload['recommendation_count'] ?? 0);
        if ($recommendationCount > 0) {
            $this->warn("recommendations={$recommendationCount}");
        }
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
