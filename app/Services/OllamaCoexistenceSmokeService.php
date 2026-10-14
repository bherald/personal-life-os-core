<?php

namespace App\Services;

use RuntimeException;

class OllamaCoexistenceSmokeService
{
    private const CASE_ID = 'genealogy_wrong_subject_rejection';

    private const MAX_TOKENS = 128;

    public function __construct(
        private readonly OllamaEvalRunnerService $runner,
        private readonly OllamaEvalLedgerService $ledger,
        private readonly OllamaCoexistenceAdmissionService $admission
    ) {}

    /** @return array<string,mixed> */
    public function run(): array
    {
        $admission = $this->admission->status();
        if (! ($admission['durable'] ?? false)
            || ! ($admission['exact_provider_policy'] ?? false)
            || ! ($admission['explicit_instance_required'] ?? false)
            || ($admission['generic_routing_enabled'] ?? true)
            || ($admission['tools_enabled'] ?? true)
            || ($admission['fallback_enabled'] ?? true)
            || ($admission['thinking_enabled'] ?? true)
        ) {
            throw new RuntimeException('Ollama coexistence smoke requires exact live serialized K3-COEX-04 admission.');
        }

        $run = $this->runner->runModel(app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'model'), [self::CASE_ID], null, app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'instance_id'), self::MAX_TOKENS);
        $record = $this->ledger->record($run);
        $result = is_array($run['results'][0] ?? null) ? $run['results'][0] : [];
        $checks = is_array($result['checks'] ?? null) ? $result['checks'] : [];
        if (($run['provider'] ?? null) !== 'ollama'
            || ($run['model'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'model')
            || ($run['requested_instance_id'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'instance_id')
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
            throw new RuntimeException('Ollama coexistence smoke failed exact transport/schema controls.');
        }

        return [
            'schema' => 'plos.ollama_coexistence_smoke.v1',
            'phase' => 'K3-COEX-04-live',
            'instance_id' => app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'instance_id'),
            'model' => app(SystemConfigService::class)->llmRoutingValue('ollama_coexistence', 'model'),
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
            'qwen_preserved' => true,
            'admission_mode' => 'serialized',
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
            'evidence_record' => $record,
            'result' => 'green',
        ];
    }
}
