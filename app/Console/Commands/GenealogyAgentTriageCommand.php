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

        $minimumAwoReviews = (int) ($preEnableGates['minimum_awo_scored_reviews'] ?? 10);
        $awoCompleted = (int) ($awo['completed_reviews'] ?? 0);
        $awoApprovalWorthy = (int) ($awo['approval_worthy_reviews'] ?? 0);
        $awoSampleFloorMet = array_key_exists('sample_floor_met', $awo)
            ? ! empty($awo['sample_floor_met'])
            : $awoCompleted >= $minimumAwoReviews;
        $awoApprovalWorthyPresent = array_key_exists('approval_worthy_present', $awo)
            ? ! empty($awo['approval_worthy_present'])
            : $awoApprovalWorthy > 0;
        $awoYieldState = $this->safeCompactCode($awo['yield_state'] ?? null)
            ?? ($awoSampleFloorMet
                ? ($awoApprovalWorthyPresent ? 'approval_worthy_present' : 'no_approval_worthy')
                : 'insufficient_sample');

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
            'awo_completed_reviews' => $awoCompleted,
            'awo_approval_worthy_reviews' => $awoApprovalWorthy,
            'awo_approval_worthy_rate' => is_numeric($awo['approval_worthy_rate'] ?? null)
                ? (float) $awo['approval_worthy_rate']
                : null,
            'awo_sample_floor_met' => $awoSampleFloorMet,
            'awo_approval_worthy_present' => $awoApprovalWorthyPresent,
            'awo_yield_state' => $awoYieldState,
            'scenario_test_count' => count($scenarioTests),
            'source_backed_review_packets_required' => (bool) ($preEnableGates['source_backed_review_packets_required'] ?? true),
            'minimum_awo_scored_reviews' => $minimumAwoReviews,
            'operator_approval_required' => (bool) ($preEnableGates['operator_approval_required'] ?? true),
            'scheduler_enablement_allowed' => (bool) ($preEnableGates['scheduler_enablement_allowed'] ?? false),
            'production_writeback_allowed' => (bool) ($preEnableGates['production_writeback_allowed'] ?? false),
            'canonical_genealogy_writeback_allowed' => (bool) ($preEnableGates['canonical_genealogy_writeback_allowed'] ?? false),
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
        $sourceBackedPacketGateVisible = $targetsTotal > 0
            && count($targets) === $targetsTotal
            && $this->allTargetsHave($targets, ['source_backed_review_packets_required']);
        $scenarioTestGateVisible = $targetsTotal > 0
            && count($targets) === $targetsTotal
            && $this->allTargetsHave($targets, ['scenario_test_count']);
        $awoGateVisible = $targetsTotal > 0
            && count($targets) === $targetsTotal
            && $this->allTargetsHave($targets, [
                'awo_completed_reviews',
                'awo_approval_worthy_reviews',
                'awo_approval_worthy_rate',
                'awo_sample_floor_met',
                'awo_approval_worthy_present',
                'awo_yield_state',
            ]);
        $operatorApprovalGateVisible = $targetsTotal > 0
            && count($targets) === $targetsTotal
            && $this->allTargetsHave($targets, ['operator_approval_required']);
        $schedulerEnablementGuardVisible = $targetsTotal > 0
            && count($targets) === $targetsTotal
            && $this->allTargetsHave($targets, ['scheduler_enablement_allowed']);
        $productionWritebackGuardVisible = $targetsTotal > 0
            && count($targets) === $targetsTotal
            && $this->allTargetsHave($targets, ['production_writeback_allowed']);
        $canonicalWritebackGuardVisible = $targetsTotal > 0
            && count($targets) === $targetsTotal
            && $this->allTargetsHave($targets, ['canonical_genealogy_writeback_allowed']);
        $operatorReviewVisible = array_key_exists('targets_needing_review_count', $summary);
        $allTargetsRequireSourceBackedPackets = $sourceBackedPacketGateVisible
            && count(array_filter($targets, fn (array $target): bool => empty($target['source_backed_review_packets_required']))) === 0;
        $allTargetsHaveScenarioTests = $scenarioTestGateVisible
            && count(array_filter($targets, fn (array $target): bool => (int) ($target['scenario_test_count'] ?? 0) < 1)) === 0;
        $allTargetsRequireOperatorApproval = $operatorApprovalGateVisible
            && count(array_filter($targets, fn (array $target): bool => empty($target['operator_approval_required']))) === 0;
        $noTargetSchedulerEnablement = $schedulerEnablementGuardVisible
            && count(array_filter($targets, fn (array $target): bool => ! empty($target['scheduler_enablement_allowed']))) === 0;
        $noTargetProductionWriteback = $productionWritebackGuardVisible
            && count(array_filter($targets, fn (array $target): bool => ! empty($target['production_writeback_allowed']))) === 0;
        $noTargetCanonicalWriteback = $canonicalWritebackGuardVisible
            && count(array_filter($targets, fn (array $target): bool => ! empty($target['canonical_genealogy_writeback_allowed']))) === 0;
        $awoApprovalWorthyRate = is_numeric($summary['awo_approval_worthy_rate'] ?? null)
            ? (string) $summary['awo_approval_worthy_rate']
            : 'null';

        $gates = [
            [
                'id' => 'observe_only_mode',
                'visible' => true,
                'passed' => $mode === 'observe',
                'evidence' => 'mode='.$mode,
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
                'id' => 'source_backed_packet_gate_visible',
                'visible' => $sourceBackedPacketGateVisible,
                'passed' => $allTargetsRequireSourceBackedPackets,
                'evidence' => 'source_backed_required_targets='.count(array_filter($targets, fn (array $target): bool => ! empty($target['source_backed_review_packets_required']))).'/'.$targetsTotal,
            ],
            [
                'id' => 'scenario_test_gate_visible',
                'visible' => $scenarioTestGateVisible,
                'passed' => $allTargetsHaveScenarioTests,
                'evidence' => 'scenario_test_targets='.count(array_filter($targets, fn (array $target): bool => (int) ($target['scenario_test_count'] ?? 0) > 0)).'/'.$targetsTotal,
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
                    'awo_completed=%d awo_approval_worthy=%d rate=%s sample_floor_met_targets=%d/%d approval_worthy_present_targets=%d/%d',
                    (int) ($summary['awo_completed_reviews_window'] ?? 0),
                    (int) ($summary['awo_approval_worthy_reviews_window'] ?? 0),
                    $awoApprovalWorthyRate,
                    count(array_filter($targets, fn (array $target): bool => ! empty($target['awo_sample_floor_met']))),
                    $targetsTotal,
                    count(array_filter($targets, fn (array $target): bool => ! empty($target['awo_approval_worthy_present']))),
                    $targetsTotal,
                ),
            ],
            [
                'id' => 'operator_approval_gate_visible',
                'visible' => $operatorApprovalGateVisible,
                'passed' => $allTargetsRequireOperatorApproval,
                'evidence' => 'operator_approval_required_targets='.count(array_filter($targets, fn (array $target): bool => ! empty($target['operator_approval_required']))).'/'.$targetsTotal,
            ],
            [
                'id' => 'scheduler_enablement_guard',
                'visible' => $schedulerEnablementGuardVisible,
                'passed' => $noTargetSchedulerEnablement,
                'evidence' => 'scheduler_enablement_allowed_targets='.count(array_filter($targets, fn (array $target): bool => ! empty($target['scheduler_enablement_allowed']))).'/'.$targetsTotal,
            ],
            [
                'id' => 'production_writeback_guard',
                'visible' => $productionWritebackGuardVisible,
                'passed' => $noTargetProductionWriteback,
                'evidence' => 'production_writeback_allowed_targets='.count(array_filter($targets, fn (array $target): bool => ! empty($target['production_writeback_allowed']))).'/'.$targetsTotal,
            ],
            [
                'id' => 'canonical_writeback_guard',
                'visible' => $canonicalWritebackGuardVisible,
                'passed' => $noTargetCanonicalWriteback,
                'evidence' => 'canonical_writeback_allowed_targets='.count(array_filter($targets, fn (array $target): bool => ! empty($target['canonical_genealogy_writeback_allowed']))).'/'.$targetsTotal,
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
                'target=%s agent=%s enabled=%s state=%s sessions=%d/%d reviews=%d/%d awo=%d/%d awo_floor=%s awo_yield=%s source_packet=%s scenarios=%d min_awo=%d operator_approval=%s scheduler_enable=%s writeback=%s canonical_writeback=%s action=%s',
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
                ! empty($target['awo_sample_floor_met']) ? 'met' : 'waiting',
                (string) ($target['awo_yield_state'] ?? 'unknown'),
                ! empty($target['source_backed_review_packets_required']) ? 'yes' : 'no',
                (int) ($target['scenario_test_count'] ?? 0),
                (int) ($target['minimum_awo_scored_reviews'] ?? 10),
                ! empty($target['operator_approval_required']) ? 'yes' : 'no',
                ! empty($target['scheduler_enablement_allowed']) ? 'yes' : 'no',
                ! empty($target['production_writeback_allowed']) ? 'yes' : 'no',
                ! empty($target['canonical_genealogy_writeback_allowed']) ? 'yes' : 'no',
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

    private function safeCompactCode(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $code = trim((string) $value);
        if ($code === '' || preg_match('/^[a-z][a-z0-9_]{1,80}$/', $code) !== 1) {
            return null;
        }

        return $code;
    }
}
