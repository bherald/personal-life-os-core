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

        $compactSummary = [
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
        ];
        $compactTargets = array_values(array_map(
            fn (array $target): array => $this->compactTarget($target),
            array_filter($targets, 'is_array')
        ));
        $compactRecommendations = array_values(array_map('strval', $recommendations));

        return [
            'version' => $payload['version'] ?? 1,
            'mode' => $payload['mode'] ?? 'observe',
            'compact' => true,
            'generated_at' => $payload['generated_at'] ?? null,
            'window_days' => (int) ($payload['window_days'] ?? 0),
            'status' => $payload['status'] ?? 'unknown',
            'summary' => $compactSummary,
            'validation_envelope' => $this->validationEnvelope(
                $payload,
                $compactSummary,
                $compactTargets,
                $compactRecommendations
            ),
            'targets' => $compactTargets,
            'recommendation_count' => count($compactRecommendations),
            'recommendations' => $compactRecommendations,
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
        $preEnableGates = $target['pre_enable_gates'] ?? [];
        if (! is_array($preEnableGates)) {
            $preEnableGates = [];
        }
        $scenarioTests = $preEnableGates['scenario_tests_required'] ?? [];
        if (! is_array($scenarioTests)) {
            $scenarioTests = [];
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
            'scenario_test_count' => count($scenarioTests),
            'minimum_awo_scored_reviews' => (int) ($preEnableGates['minimum_awo_scored_reviews'] ?? 10),
            'scheduler_enablement_allowed' => (bool) ($preEnableGates['scheduler_enablement_allowed'] ?? false),
            'production_writeback_allowed' => (bool) ($preEnableGates['production_writeback_allowed'] ?? false),
            'next_action' => $target['next_action'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $targets
     * @param  array<int, string>  $recommendations
     * @return array<string, mixed>
     */
    private function validationEnvelope(array $payload, array $summary, array $targets, array $recommendations): array
    {
        $status = (string) ($payload['status'] ?? 'unknown');
        $targetsTotal = (int) ($summary['targets_total'] ?? 0);
        $targetsNeedingReview = (int) ($summary['targets_needing_review_count'] ?? 0);
        $mode = (string) ($payload['mode'] ?? 'observe');

        $schedulerStateVisible = $targetsTotal > 0
            && count($targets) === $targetsTotal
            && $this->allTargetsHave($targets, ['job_name', 'enabled', 'status', 'triage_state']);
        $reviewGateVisible = $targetsTotal > 0
            && count($targets) === $targetsTotal
            && $this->allTargetsHave($targets, ['reviews_completed', 'reviews_total', 'next_action']);
        $awoGateVisible = $targetsTotal > 0
            && count($targets) === $targetsTotal
            && $this->allTargetsHave($targets, ['awo_completed_reviews', 'awo_approval_worthy_reviews', 'awo_approval_worthy_rate']);
        $operatorReviewVisible = array_key_exists('targets_needing_review_count', $summary);

        $gates = [
            [
                'id' => 'observe_only_mode',
                'visible' => true,
                'passed' => $mode === 'observe',
                'evidence' => 'mode='.$mode,
            ],
            [
                'id' => 'production_writeback_guard',
                'visible' => true,
                'passed' => true,
                'evidence' => 'command emits read-only triage; no scheduler, approval, rejection, or agent state writeback is performed',
            ],
            [
                'id' => 'scheduler_state_visible',
                'visible' => $schedulerStateVisible,
                'passed' => $schedulerStateVisible && (int) ($summary['missing_targets'] ?? 0) === 0,
                'evidence' => sprintf(
                    'targets=%d/%d missing=%d disabled=%d enabled=%d',
                    count($targets),
                    $targetsTotal,
                    (int) ($summary['missing_targets'] ?? 0),
                    (int) ($summary['disabled_targets'] ?? 0),
                    (int) ($summary['enabled_targets'] ?? 0),
                ),
            ],
            [
                'id' => 'review_output_gate_visible',
                'visible' => $reviewGateVisible,
                'passed' => $reviewGateVisible && (int) ($summary['review_outputs_window'] ?? 0) > 0,
                'evidence' => 'review_outputs_window='.(int) ($summary['review_outputs_window'] ?? 0),
            ],
            [
                'id' => 'awo_gate_visible',
                'visible' => $awoGateVisible,
                'passed' => $awoGateVisible && (int) ($summary['awo_completed_reviews_window'] ?? 0) >= 10,
                'evidence' => sprintf(
                    'awo_completed=%d awo_approval_worthy=%d rate=%s',
                    (int) ($summary['awo_completed_reviews_window'] ?? 0),
                    (int) ($summary['awo_approval_worthy_reviews_window'] ?? 0),
                    is_numeric($summary['awo_approval_worthy_rate'] ?? null)
                        ? (string) $summary['awo_approval_worthy_rate']
                        : 'null',
                ),
            ],
            [
                'id' => 'operator_review_gate_visible',
                'visible' => $operatorReviewVisible,
                'passed' => $operatorReviewVisible && $targetsNeedingReview === 0 && $status === 'healthy',
                'evidence' => sprintf(
                    'status=%s review_needed=%d recommendations=%d',
                    $status,
                    $targetsNeedingReview,
                    count($recommendations),
                ),
            ],
        ];

        $operatorSafeGatesVisible = count(array_filter($gates, fn (array $gate): bool => empty($gate['visible']))) === 0;
        $blockingGates = array_values(array_map(
            fn (array $gate): string => (string) $gate['id'],
            array_filter($gates, fn (array $gate): bool => empty($gate['passed']))
        ));
        $futureEnablementBlocked = ! $operatorSafeGatesVisible
            || $targetsNeedingReview > 0
            || $status !== 'healthy'
            || $blockingGates !== [];

        return [
            'schema' => 'genealogy_agent_triage.validation_envelope.v1',
            'intent' => 'pre_enable_review',
            'automatic_enablement_allowed' => false,
            'production_writeback_allowed' => false,
            'operator_safe_gates_visible' => $operatorSafeGatesVisible,
            'operator_review_required' => $targetsNeedingReview > 0 || $status !== 'healthy',
            'future_enablement_blocked' => $futureEnablementBlocked,
            'decision' => match (true) {
                ! $operatorSafeGatesVisible => 'blocked_missing_gate_visibility',
                $futureEnablementBlocked => 'blocked_pending_operator_review',
                default => 'operator_review_ready',
            },
            'required_gate_count' => count($gates),
            'visible_gate_count' => count(array_filter($gates, fn (array $gate): bool => ! empty($gate['visible']))),
            'passing_gate_count' => count(array_filter($gates, fn (array $gate): bool => ! empty($gate['passed']))),
            'blocking_gate_count' => count($blockingGates),
            'blocking_gates' => $blockingGates,
            'required_gates' => $gates,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $targets
     * @param  array<int, string>  $keys
     */
    private function allTargetsHave(array $targets, array $keys): bool
    {
        foreach ($targets as $target) {
            foreach ($keys as $key) {
                if (! array_key_exists($key, $target)) {
                    return false;
                }
            }
        }

        return $targets !== [];
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
                'target=%s agent=%s enabled=%s state=%s sessions=%d/%d reviews=%d/%d awo=%d/%d scenarios=%d min_awo=%d scheduler_enable=%s writeback=%s action=%s',
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
                (int) ($target['scenario_test_count'] ?? 0),
                (int) ($target['minimum_awo_scored_reviews'] ?? 10),
                ! empty($target['scheduler_enablement_allowed']) ? 'yes' : 'no',
                ! empty($target['production_writeback_allowed']) ? 'yes' : 'no',
                (string) ($target['next_action'] ?? ''),
            ));
        }

        $validation = $payload['validation_envelope'] ?? [];
        if (is_array($validation)) {
            $this->line(sprintf(
                'validation=%s gates_visible=%s future_enablement_blocked=%s review_required=%s writeback_allowed=%s required_gates=%d visible_gates=%d passing_gates=%d blocking_gates=%d blocking_gate_ids=%s',
                (string) ($validation['decision'] ?? 'unknown'),
                ! empty($validation['operator_safe_gates_visible']) ? 'yes' : 'no',
                ! empty($validation['future_enablement_blocked']) ? 'yes' : 'no',
                ! empty($validation['operator_review_required']) ? 'yes' : 'no',
                ! empty($validation['production_writeback_allowed']) ? 'yes' : 'no',
                (int) ($validation['required_gate_count'] ?? 0),
                (int) ($validation['visible_gate_count'] ?? 0),
                (int) ($validation['passing_gate_count'] ?? 0),
                (int) ($validation['blocking_gate_count'] ?? count((array) ($validation['blocking_gates'] ?? []))),
                $this->formatGateIdList($validation['blocking_gates'] ?? []),
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

    private function formatGateIdList(mixed $gateIds): string
    {
        if (! is_array($gateIds)) {
            return 'unknown';
        }

        $ids = array_values(array_filter(
            array_map('strval', $gateIds),
            fn (string $gateId): bool => $gateId !== ''
        ));

        if ($ids === []) {
            return 'none';
        }

        $limit = 5;
        $visible = array_slice($ids, 0, $limit);
        if (count($ids) > $limit) {
            $visible[] = '+'.(count($ids) - $limit);
        }

        return implode(',', $visible);
    }
}
