<?php

namespace App\Services;

use RuntimeException;

class KimiK3ScorecardBatchService
{
    private const EXPECTED_MAX_TOKENS = 128;

    private const BATCH_COUNT = 4;

    public function __construct(
        private readonly OllamaEvalRunnerService $runner,
        private readonly OllamaEvalLedgerService $ledger
    ) {}

    /** @return array<string,mixed> */
    public function contract(int $batch = 1): array
    {
        $contract = app(SystemConfigService::class)->llmRoutingBinding('kimi_scorecard');
        unset($contract['comparator_model']);
        if (! is_array($contract)) {
            throw new RuntimeException('K3-05 scorecard contract is missing.');
        }

        $batches = is_array($contract['batches'] ?? null) ? $contract['batches'] : [];
        $selectedBatch = $batches[$batch] ?? null;
        unset($contract['batches']);
        if ($batch < 1 || $batch > self::BATCH_COUNT || ! is_array($selectedBatch)) {
            throw new RuntimeException('K3-05 scorecard batch must be an exact configured batch from 1 through 4.');
        }

        $expected = [
            'batch_id' => "K3-05-batch-{$batch}",
            'case_ids' => $selectedBatch['case_ids'] ?? null,
            'candidate_target' => app(SystemConfigService::class)->llmRoutingValue('kimi_scorecard', 'candidate_target'),
            'candidate_instance_id' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'),
            'comparator_target' => app(SystemConfigService::class)->llmRoutingValue('kimi_scorecard', 'comparator_target'),
            'comparator_instance_id' => app(SystemConfigService::class)->llmRoutingValue('kimi_scorecard', 'comparator_instance_id'),
            'max_tokens' => self::EXPECTED_MAX_TOKENS,
            'normal_routing' => false,
            'tools_enabled' => false,
            'fallback_enabled' => false,
        ];

        $selectedContract = [...$selectedBatch, ...$contract];
        if ($selectedContract !== $expected
            || ! is_array($expected['case_ids'])
            || count($expected['case_ids']) !== 5
            || count(array_unique($expected['case_ids'])) !== 5
        ) {
            throw new RuntimeException('K3-05 scorecard contract has drifted.');
        }

        return [
            'schema' => 'plos.kimi_k3_scorecard_contract.v1',
            ...$selectedContract,
            'batch_number' => $batch,
            'case_count' => count($expected['case_ids']),
            'contract_sha256' => hash('sha256', json_encode($selectedContract, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'content_free_receipt' => true,
            'promotion_applied' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function run(int $batch = 1): array
    {
        $contract = $this->contract($batch);
        $caseIds = $contract['case_ids'];
        $maxTokens = (int) $contract['max_tokens'];

        $comparator = $this->runner->runModel(
            app(SystemConfigService::class)->llmRoutingValue('kimi_scorecard', 'comparator_target'),
            $caseIds,
            null,
            app(SystemConfigService::class)->llmRoutingValue('kimi_scorecard', 'comparator_instance_id'),
            $maxTokens
        );
        $this->assertExactRun($comparator, false, $caseIds);
        $comparatorEvidence = $this->ledger->record($comparator);

        $candidate = $this->runner->runModel(
            app(SystemConfigService::class)->llmRoutingValue('kimi_scorecard', 'candidate_target'),
            $caseIds,
            null,
            null,
            $maxTokens
        );
        $this->assertExactRun($candidate, true, $caseIds);
        $candidateEvidence = $this->ledger->record($candidate);

        return [
            'schema' => 'plos.kimi_k3_scorecard_batch.v1',
            'batch_id' => $contract['batch_id'],
            'batch_number' => $batch,
            'contract_sha256' => $contract['contract_sha256'],
            'case_ids' => $caseIds,
            'case_count' => count($caseIds),
            'requested_max_tokens' => self::EXPECTED_MAX_TOKENS,
            'execution_order' => ['comparator', 'candidate'],
            'controls_passed' => true,
            'normal_routing_changed' => false,
            'promotion_applied' => false,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
            'comparator' => $this->contentFreeRun($comparator, $comparatorEvidence),
            'candidate' => $this->contentFreeRun($candidate, $candidateEvidence),
            'score_delta_pct' => round(
                (float) ($candidate['average_score_ratio'] ?? 0.0) * 100
                - (float) ($comparator['average_score_ratio'] ?? 0.0) * 100,
                1
            ),
        ];
    }

    /** @param array<string,mixed> $run */
    private function assertExactRun(array $run, bool $candidate, array $caseIds): void
    {
        $expectedTarget = $candidate ? app(SystemConfigService::class)->llmRoutingValue('kimi_scorecard', 'candidate_target') : app(SystemConfigService::class)->llmRoutingValue('kimi_scorecard', 'comparator_target');
        $expectedInstance = $candidate ? app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id') : app(SystemConfigService::class)->llmRoutingValue('kimi_scorecard', 'comparator_instance_id');

        if (($run['target'] ?? null) !== $expectedTarget
            || ($run['requested_instance_id'] ?? null) !== $expectedInstance
            || (int) ($run['requested_max_tokens'] ?? 0) !== self::EXPECTED_MAX_TOKENS
            || (int) ($run['total_cases'] ?? 0) !== count($caseIds)
        ) {
            throw new RuntimeException('K3-05 target or batch contract was not preserved.');
        }

        $results = is_array($run['results'] ?? null) ? $run['results'] : [];
        if (array_column($results, 'case_id') !== $caseIds) {
            throw new RuntimeException('K3-05 case order or membership drifted.');
        }

        foreach ($results as $result) {
            $transportPassed = (bool) data_get($result, 'checks.transport_success', false);
            $failed = [];
            if (! $transportPassed) {
                $failed[] = 'transport_success';
            }
            if (! ($result['target_match'] ?? false)) {
                $failed[] = 'target_match';
            }
            if (! ($result['instance_match'] ?? false)) {
                $failed[] = 'instance_match';
            }
            if ((int) ($result['requested_max_tokens'] ?? 0) !== self::EXPECTED_MAX_TOKENS) {
                $failed[] = 'max_tokens';
            }
            if (($result['fallback_suppressed'] ?? null) !== true) {
                $failed[] = 'fallback_suppressed';
            }

            if ($failed !== []) {
                throw new RuntimeException(sprintf(
                    'K3-05 %s guard failed for %s: %s.',
                    $candidate ? 'candidate' : 'comparator',
                    (string) ($result['case_id'] ?? 'unknown_case'),
                    implode(',', $failed)
                ));
            }
        }
    }

    /**
     * @param  array<string,mixed>  $run
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function contentFreeRun(array $run, array $evidence): array
    {
        $results = is_array($run['results'] ?? null) ? $run['results'] : [];

        return [
            'target' => (string) ($run['target'] ?? ''),
            'requested_instance_id' => $run['requested_instance_id'] ?? null,
            'total_cases' => (int) ($run['total_cases'] ?? 0),
            'passed_cases' => (int) ($run['passed_cases'] ?? 0),
            'failed_cases' => (int) ($run['failed_cases'] ?? 0),
            'pass_rate' => (float) ($run['pass_rate'] ?? 0.0),
            'average_score_ratio' => (float) ($run['average_score_ratio'] ?? 0.0),
            'duration_ms' => array_sum(array_map(static fn (array $result): int => (int) ($result['duration_ms'] ?? 0), $results)),
            'evidence_record' => $evidence,
            'cases' => array_map(static fn (array $result): array => [
                'case_id' => (string) ($result['case_id'] ?? ''),
                'passed' => (bool) ($result['passed'] ?? false),
                'score_pct' => (float) ($result['score_pct'] ?? 0.0),
                'checks' => array_map('boolval', is_array($result['checks'] ?? null) ? $result['checks'] : []),
                'target_match' => (bool) ($result['target_match'] ?? false),
                'instance_match' => (bool) ($result['instance_match'] ?? false),
                'fallback_suppressed' => isset($result['fallback_suppressed'])
                    ? (bool) $result['fallback_suppressed']
                    : null,
                'requested_max_tokens' => (int) ($result['requested_max_tokens'] ?? 0),
                'completion_tokens' => isset($result['completion_tokens']) ? (int) $result['completion_tokens'] : null,
                'finish_reason' => $result['finish_reason'] ?? null,
                'output_chars' => (int) ($result['output_chars'] ?? 0),
                'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            ], $results),
        ];
    }
}
