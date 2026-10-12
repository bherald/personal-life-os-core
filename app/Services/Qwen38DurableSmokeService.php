<?php

namespace App\Services;

use RuntimeException;

class Qwen38DurableSmokeService
{
    private const TARGET = 'provider:qwen38_87/qwen3.8-27b-q4_k_m';

    private const INSTANCE_ID = 'qwen38_87';

    private const CASE_ID = 'genealogy_multipage_packet_synthesis';

    private const MAX_TOKENS = 128;

    public function __construct(
        private readonly OllamaEvalRunnerService $runner,
        private readonly OllamaEvalLedgerService $ledger,
        private readonly Qwen38DurableAdmissionService $admission
    ) {}

    /** @return array<string,mixed> */
    public function run(): array
    {
        $admission = $this->admission->status();
        if (! ($admission['durable'] ?? false)
            || ! ($admission['exact_provider_policy'] ?? false)
            || ! ($admission['exact_profile_policy'] ?? false)
            || ($admission['tools_enabled'] ?? true)
            || ($admission['fallback_enabled'] ?? true)
            || ($admission['thinking_enabled'] ?? true)
        ) {
            throw new RuntimeException('Qwen3.8 durable smoke requires exact live K3-ALT-06 admission.');
        }
        $run = $this->runner->runModel(self::TARGET, [self::CASE_ID], null, null, self::MAX_TOKENS);
        $record = $this->ledger->record($run);
        $result = is_array($run['results'][0] ?? null) ? $run['results'][0] : [];
        $checks = is_array($result['checks'] ?? null) ? $result['checks'] : [];
        if (($run['target'] ?? null) !== self::TARGET
            || ($run['requested_instance_id'] ?? null) !== self::INSTANCE_ID
            || (int) ($run['requested_max_tokens'] ?? 0) !== self::MAX_TOKENS
            || (int) ($run['total_cases'] ?? 0) !== 1
            || ($result['case_id'] ?? null) !== self::CASE_ID
            || ! ($result['target_match'] ?? false)
            || ! ($result['instance_match'] ?? false)
            || ($result['fallback_suppressed'] ?? null) !== true
            || ! ($checks['transport_success'] ?? false)
            || ! ($checks['schema_compliant'] ?? false)
            || (int) ($result['output_chars'] ?? 0) < 1
            || (isset($result['completion_tokens']) && (int) $result['completion_tokens'] > self::MAX_TOKENS)
        ) {
            throw new RuntimeException('Qwen3.8 durable route smoke failed exact transport/schema controls.');
        }

        return [
            'schema' => 'plos.qwen38_durable_smoke.v1',
            'phase' => 'K3-ALT-06-live',
            'target' => self::TARGET,
            'instance_id' => self::INSTANCE_ID,
            'case_id' => self::CASE_ID,
            'requested_max_tokens' => self::MAX_TOKENS,
            'completion_tokens' => isset($result['completion_tokens']) ? (int) $result['completion_tokens'] : null,
            'finish_reason' => $result['finish_reason'] ?? null,
            'output_chars' => (int) ($result['output_chars'] ?? 0),
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            'target_match' => true,
            'instance_match' => true,
            'fallback_suppressed' => true,
            'transport_success' => true,
            'schema_compliant' => true,
            'tools_used' => false,
            'ollama_used' => false,
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
            'evidence_record' => $record,
            'result' => 'green',
        ];
    }
}
