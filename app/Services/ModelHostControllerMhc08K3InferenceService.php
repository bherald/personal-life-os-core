<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

/** Explicit K3 inference boundary; never called by normal routing. */
class ModelHostControllerMhc08K3InferenceService
{
    private const CANCELLATION_PROMPT = 'Reply with exactly MHC08-K3-CANCEL-UNREACHABLE.';

    public function __construct(
        private readonly ModelHostControllerMhc08K3LifecycleService $lifecycle,
        private readonly ModelHostControllerMhc08K3AdmissionService $admission,
        private readonly AIService $ai,
    ) {}

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public function process(string $role, string $prompt, array $config, string $requestId, string $leaseId): array
    {
        [$prompt, $config] = $this->validatedRequest($prompt, $config);
        $response = null;
        $receipt = $this->lifecycle->run($role, $requestId, $leaseId, function (array $target) use ($prompt, $config, $requestId, &$response): array {
            $execution = $this->executeTarget($prompt, $config, $requestId, $target);
            $response = $execution['response'];

            return $execution['execution_receipt'];
        });

        if (($receipt['result'] ?? null) !== 'green' || ! is_array($response)) {
            throw new RuntimeException('MHC-08 K3 inference was queued without fallback.');
        }

        return [
            'response' => $response,
            'controller_receipt' => $receipt,
        ];
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public function processAcceptance(
        string $role,
        string $prompt,
        array $config,
        string $requestId,
        string $leaseId,
    ): array {
        [$prompt, $config] = $this->validatedRequest($prompt, $config);
        $response = null;
        $receipt = $this->lifecycle->runAcceptance(
            $role,
            $requestId,
            $leaseId,
            function (array $target) use ($prompt, $config, $requestId, &$response): array {
                $execution = $this->executeTarget($prompt, $config, $requestId, $target);
                $response = $execution['response'];

                return $execution['execution_receipt'];
            },
        );
        if (($receipt['result'] ?? null) !== 'green' || ! is_array($response)) {
            throw new RuntimeException('MHC-08 K3 acceptance inference was queued without fallback.');
        }

        return ['response' => $response, 'controller_receipt' => $receipt];
    }

    /**
     * Execute only inside ModelHostControllerMhc08K3LifecycleService::runCandidateCanary.
     *
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $target
     * @return array{response:array<string,mixed>,execution_receipt:array<string,mixed>}
     */
    public function executeCandidateCanary(string $prompt, array $config, string $requestId, array $target): array
    {
        [$prompt, $config] = $this->validatedRequest($prompt, $config);

        return $this->executeTarget($prompt, $config, $requestId, $target);
    }

    /**
     * Execute only inside the fixed candidate cancellation qualification.
     *
     * @param  array<string,mixed>  $target
     * @return array{execution_receipt:array<string,mixed>}
     */
    public function executeCandidateCancellation(string $cancellationRunId, string $requestId, array $target): array
    {
        [$prompt, $config] = $this->validatedRequest(self::CANCELLATION_PROMPT, [
            'max_tokens' => 128,
            'cancellation_run_id' => $cancellationRunId,
        ]);
        $response = $this->requestTarget($prompt, $config, $requestId, $target);
        if (($response['success'] ?? true) !== false
            || ($response['response'] ?? null) !== null
            || ($response['provider'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
            || ($response['model'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')
            || ($response['error'] ?? null) !== 'Response cancelled by the operator before content was produced.'
            || ($response['fallback_suppressed'] ?? false) !== true
            || ($response['partial_content_preserved'] ?? true) !== false) {
            throw new RuntimeException('MHC-08 K3 candidate cancellation did not reach the exact stream boundary.');
        }

        return [
            'execution_receipt' => [
                'schema' => 'plos.model_host_controller.mhc08_k3_candidate_cancellation_execution.v1',
                'result' => 'cancelled',
                'stream_cancelled' => true,
                'partial_output_preserved' => false,
                'fallback_suppressed' => true,
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $config @return array{string,array<string,mixed>} */
    private function validatedRequest(string $prompt, array $config): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('MHC-08 K3 inference requires a non-empty prompt.');
        }
        $allowedConfig = ['max_tokens', 'cancellation_run_id', 'system_prompt'];
        foreach (array_keys($config) as $key) {
            if (! is_string($key) || ! in_array($key, $allowedConfig, true)) {
                throw new RuntimeException("MHC-08 K3 inference rejects {$key}.");
            }
        }

        $maxTokens = $config['max_tokens'] ?? 128;
        if ((! is_int($maxTokens) && ! (is_string($maxTokens) && preg_match('/\A\d+\z/', $maxTokens) === 1))
            || (int) $maxTokens < 1 || (int) $maxTokens > 128) {
            throw new RuntimeException('MHC-08 K3 inference max_tokens is outside the guarded range.');
        }
        $config['max_tokens'] = (int) $maxTokens;
        $cancellationRunId = trim((string) ($config['cancellation_run_id'] ?? ''));
        if (! Str::isUuid($cancellationRunId)) {
            throw new RuntimeException('MHC-08 K3 inference requires an exact UUID cancellation_run_id.');
        }
        $systemPrompt = $config['system_prompt'] ?? '';
        if (! is_string($systemPrompt) || mb_strlen($systemPrompt) > 4000
            || mb_strlen($prompt) + mb_strlen($systemPrompt) > 16000) {
            throw new RuntimeException('MHC-08 K3 inference system/prompt content exceeds the guarded boundary.');
        }
        $config = [
            'max_tokens' => (int) $maxTokens,
            'cancellation_run_id' => $cancellationRunId,
            'system_prompt' => trim($systemPrompt),
            'temperature' => 0,
            'enable_thinking' => false,
        ];

        return [$prompt, $config];
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $target
     * @return array{response:array<string,mixed>,execution_receipt:array<string,mixed>}
     */
    private function executeTarget(string $prompt, array $config, string $requestId, array $target): array
    {
        $response = $this->requestTarget($prompt, $config, $requestId, $target);
        $this->assertResponse($response);

        return [
            'response' => $response,
            'execution_receipt' => [
                'success' => true,
                'provider' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'),
                'model' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model'),
                'fallback_suppressed' => true,
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
                'result' => 'green',
            ],
        ];
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $target @return array<string,mixed> */
    private function requestTarget(string $prompt, array $config, string $requestId, array $target): array
    {
        if (($target['target'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
            || ($target['provider_instance_id'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
            || ($target['provider_model_role'] ?? null) !== 'deep_offline'
            || ($target['model'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')
            || ($target['engine'] ?? null) !== 'colibri-k3') {
            throw new RuntimeException('MHC-08 K3 inference target contract is not exact.');
        }

        $response = $this->admission->run($requestId, fn (string $admissionToken): array => $this->ai->process($prompt, [
            ...$config,
            'model' => app(SystemConfigService::class)->llmRoutingValue('kimi_scorecard', 'candidate_target'),
            'model_override' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model'),
            'model_role' => 'deep_offline',
            'required_instance_id' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'),
            'use_cache' => false,
            'dedup' => false,
            '_skip_decompose' => true,
            'disable_cascade' => true,
            'suppressAlert' => true,
            '_mhc08_admission_request_id' => $requestId,
            '_mhc08_admission_token' => $admissionToken,
        ]));
        if (! is_array($response)) {
            throw new RuntimeException('MHC-08 K3 inference returned an invalid response.');
        }

        return $response;
    }

    /** @param array<string,mixed> $response */
    private function assertResponse(array $response): void
    {
        $telemetry = is_array($response['transport_telemetry'] ?? null)
            ? $response['transport_telemetry']
            : [];
        $completionTokens = $telemetry['completion_tokens'] ?? null;
        if (($response['success'] ?? false) !== true
            || ($response['provider'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id')
            || ($response['model'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model')
            || ($response['fallback_suppressed'] ?? false) !== true
            || ! is_string($response['response'] ?? null)
            || trim($response['response']) === ''
            || ! is_int($completionTokens)
            || $completionTokens < 1
            || $completionTokens > 128
            || ($telemetry['finish_reason'] ?? null) !== 'stop') {
            throw new RuntimeException('MHC-08 K3 inference returned an inexact or fallback response.');
        }
    }
}
