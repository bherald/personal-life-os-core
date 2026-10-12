<?php

namespace App\Services;

use RuntimeException;

class Qwen38PLOSTransportCanaryService
{
    private const TARGET = 'provider:qwen38_87/qwen3.8-27b-q4_k_m';

    private const INSTANCE_ID = 'qwen38_87';

    private const MAX_TOKENS = 128;

    private const CASE_IDS = [
        'genealogy_extract_names_dates_places',
        'genealogy_wrong_subject_rejection',
        'genealogy_source_triage',
        'genealogy_multipage_packet_synthesis',
        'prompt_injection_external_data_override',
    ];

    public function __construct(
        private readonly OllamaEvalRunnerService $runner,
        private readonly OllamaEvalLedgerService $ledger,
        private readonly Qwen38BenchAdmissionService $admission
    ) {}

    /** @return array<string,mixed> */
    public function contract(): array
    {
        $batch = config('ollama_eval.k3_05.batches.1', []);
        if (! is_array($batch)
            || ($batch['batch_id'] ?? null) !== 'K3-05-batch-1'
            || ($batch['case_ids'] ?? null) !== self::CASE_IDS
        ) {
            throw new RuntimeException('Qwen3.8 PLOS canary case contract has drifted.');
        }
        $contract = [
            'batch_id' => 'K3-ALT-05-batch-1',
            'case_ids' => self::CASE_IDS,
            'candidate_target' => self::TARGET,
            'candidate_instance_id' => self::INSTANCE_ID,
            'max_tokens' => self::MAX_TOKENS,
            'minimum_average_score_ratio' => 0.55,
            'minimum_strict_passes' => 1,
            'normal_routing' => false,
            'tools_enabled' => false,
            'fallback_enabled' => false,
            'thinking_enabled' => false,
            'ollama_required_offline' => true,
        ];

        return [
            'schema' => 'plos.qwen38_plos_transport_contract.v1',
            ...$contract,
            'case_count' => count(self::CASE_IDS),
            'contract_sha256' => hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'content_free_receipt' => true,
            'promotion_applied' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        $contract = $this->contract();
        $admission = $this->admission->status();
        if (! $admission['is_active']
            || ! $admission['is_healthy']
            || $admission['routability'] !== 'allowed'
            || $admission['admission_state'] !== 'plos_canary'
            || $admission['phase'] !== 'K3-ALT-04-live'
            || ! $admission['exact_provider_policy']
            || $admission['normal_routing']
            || $admission['tools_enabled']
            || $admission['fallback_enabled']
            || $admission['thinking_enabled']
        ) {
            throw new RuntimeException('Qwen3.8 PLOS canary requires exact temporary admission.');
        }

        $run = $this->runner->runModel(self::TARGET, self::CASE_IDS, null, null, self::MAX_TOKENS);
        $evidence = $this->ledger->record($run);
        $this->assertExactRun($run, $evidence);
        $average = (float) ($run['average_score_ratio'] ?? 0.0);
        $strictPasses = (int) ($run['passed_cases'] ?? 0);
        $qualityGate = $average >= 0.55 && $strictPasses >= 1;

        return [
            'schema' => 'plos.qwen38_plos_transport_canary.v1',
            'batch_id' => $contract['batch_id'],
            'contract_sha256' => $contract['contract_sha256'],
            'case_ids' => self::CASE_IDS,
            'case_count' => count(self::CASE_IDS),
            'requested_max_tokens' => self::MAX_TOKENS,
            'controls_passed' => true,
            'quality_gate_passed' => $qualityGate,
            'minimum_average_score_ratio' => 0.55,
            'minimum_strict_passes' => 1,
            'normal_routing_changed' => false,
            'promotion_applied' => false,
            'ollama_used' => false,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
            'candidate' => $this->contentFreeRun($run, $evidence),
        ];
    }

    /**
     * @param  array<string,mixed>  $run
     * @param  array<string,mixed>  $evidence
     */
    private function assertExactRun(array $run, array $evidence): void
    {
        if (($run['target'] ?? null) !== self::TARGET
            || ($run['requested_instance_id'] ?? null) !== self::INSTANCE_ID
            || (int) ($run['requested_max_tokens'] ?? 0) !== self::MAX_TOKENS
            || (int) ($run['total_cases'] ?? 0) !== count(self::CASE_IDS)
        ) {
            throw new RuntimeException('Qwen3.8 PLOS target or batch contract was not preserved.');
        }
        $results = is_array($run['results'] ?? null) ? $run['results'] : [];
        if (array_column($results, 'case_id') !== self::CASE_IDS) {
            throw new RuntimeException('Qwen3.8 PLOS case order or membership drifted.');
        }
        foreach ($results as $result) {
            $failed = [];
            if (! (bool) data_get($result, 'checks.transport_success', false)) {
                $failed[] = 'transport_success';
            }
            if (array_key_exists('schema_compliant', $result['checks'] ?? [])
                && ! (bool) data_get($result, 'checks.schema_compliant', false)
            ) {
                $failed[] = 'schema_compliant';
            }
            if (! ($result['target_match'] ?? false)) {
                $failed[] = 'target_match';
            }
            if (! ($result['instance_match'] ?? false)) {
                $failed[] = 'instance_match';
            }
            if (($result['fallback_suppressed'] ?? null) !== true) {
                $failed[] = 'fallback_suppressed';
            }
            if ((int) ($result['requested_max_tokens'] ?? 0) !== self::MAX_TOKENS) {
                $failed[] = 'max_tokens';
            }
            if ((int) ($result['output_chars'] ?? 0) < 1) {
                $failed[] = 'output_present';
            }
            if (isset($result['completion_tokens']) && (int) $result['completion_tokens'] > self::MAX_TOKENS) {
                $failed[] = 'completion_tokens';
            }
            if ($failed !== []) {
                throw new RuntimeException(sprintf(
                    'Qwen3.8 PLOS guard failed for %s: %s. Evidence run_uuid=%s completion_tokens=%s finish_reason=%s output_chars=%d.',
                    (string) ($result['case_id'] ?? 'unknown_case'),
                    implode(',', $failed),
                    (string) ($evidence['run_uuid'] ?? 'unavailable'),
                    isset($result['completion_tokens']) ? (string) ((int) $result['completion_tokens']) : 'unknown',
                    isset($result['finish_reason']) ? (string) $result['finish_reason'] : 'unknown',
                    (int) ($result['output_chars'] ?? 0)
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
