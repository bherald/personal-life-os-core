<?php

namespace App\Services;

use App\Engine\AIRouter;
use RuntimeException;

/**
 * Explicit opt-in PLOS inference boundary for MHC-managed .87 targets.
 * Normal AIService routing never enters this service implicitly.
 */
class ModelHostControllerInferenceService
{
    public function __construct(
        private readonly ModelHostControllerAutomaticSelectionService $selection,
        private readonly AIService $ai,
        private readonly BitLlmPrivacyGateService $bitnet,
        private readonly ModelHostControllerBitNetCanaryAdmissionService $bitnetAdmission,
        private readonly ModelHostControllerAutomaticSelectionPolicyService $policies,
        private readonly LLMPoolManagerService $providers,
        private readonly ModelHostControllerResourcePolicyService $resources,
        private readonly ModelHostControllerPolicyService $controllerPolicies,
    ) {}

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public function process(
        string $role,
        string $prompt,
        array $config,
        string $requestId,
        string $leaseId
    ): array {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('MHC-06 managed inference requires a non-empty prompt.');
        }
        foreach ([
            'tools', 'tool_choice', 'allow_tools', 'prefer_external', 'prefer_codex', 'ai_mode',
            'required_instance_id', 'allowed_ollama_instance_ids', 'preferred_ollama_instance_ids',
            'ollama_required_no_fallback', 'model', 'model_override', 'model_role', 'disable_cascade',
            'use_cache', 'dedup', '_skip_decompose',
        ] as $key) {
            if (array_key_exists($key, $config)) {
                throw new RuntimeException("MHC-06 managed inference rejects {$key}.");
            }
        }
        $policy = $this->policies->required();
        $targetId = $policy['role_targets'][$role] ?? null;
        $targetPolicy = is_string($targetId) ? ($policy['targets'][$targetId] ?? null) : null;
        if (! is_array($targetPolicy)) {
            throw new RuntimeException('MHC-06 managed inference role is not admitted.');
        }
        $this->assertLocalProvider($targetPolicy['provider_instance_id'], $targetPolicy['inference_adapter']);
        if ($targetPolicy['inference_adapter'] === 'privacy_gate') {
            if ($role !== BitLlmPrivacyGateService::MODEL_ROLE
                || $targetPolicy['provider_model_role'] !== BitLlmPrivacyGateService::MODEL_ROLE) {
                throw new RuntimeException('Managed privacy inference requires its exact privacy role.');
            }
            if ((array_key_exists('max_tokens', $config) && $config['max_tokens'] !== BitLlmPrivacyGateService::INFERENCE_MAX_TOKENS)
                || (array_key_exists('temperature', $config) && $config['temperature'] !== BitLlmPrivacyGateService::INFERENCE_TEMPERATURE)
                || array_key_exists('ollama_output_schema', $config)) {
                throw new RuntimeException('Managed privacy inference cannot override its fixed decision contract.');
            }
            $config['max_tokens'] = BitLlmPrivacyGateService::INFERENCE_MAX_TOKENS;
            $config['temperature'] = BitLlmPrivacyGateService::INFERENCE_TEMPERATURE;
        }
        $maxTokens = $config['max_tokens'] ?? $targetPolicy['inference_limits']['default_max_tokens'];
        if ((! is_int($maxTokens) && ! (is_string($maxTokens) && preg_match('/\A\d+\z/', $maxTokens) === 1))
            || (int) $maxTokens < 1 || (int) $maxTokens > $targetPolicy['inference_limits']['max_tokens']) {
            throw new RuntimeException('MHC-06 managed inference max_tokens is outside the guarded range.');
        }
        $config['max_tokens'] = (int) $maxTokens;
        $prepared = $this->prepareRequest($targetId, $targetPolicy, $config);
        $config = $prepared['config'] ?? $config;
        $response = null;
        $receipt = $this->selection->run([[
            'role' => $role,
            'request_id' => $requestId,
            'lease_id' => $leaseId,
            'target_policy_sha256' => ModelHostControllerResourcePolicyService::requestContractIdentity($targetPolicy),
            ...($prepared === null ? [] : ['request_contract' => $prepared['contract'], 'capacity_binding' => $prepared['binding']]),
        ]], function (array $request, array $target) use ($prompt, $config, $prepared, &$response): void {
            $invoke = fn () => $this->invokeExact($request['role'], $prompt, $config, $target, $request['request_id']);
            if ($prepared === null) {
                $response = $invoke();

                return;
            }
            $sent = false;
            $response = AIService::withInferenceTransportValidator($invoke,
                function (string $wire, string $endpoint, array $settings) use ($prepared, $request, &$sent): void {
                    if ($sent || $wire !== $prepared['contract']['wire_api'] || $endpoint !== $prepared['endpoint']
                        || ModelHostControllerResourcePolicyService::requestContractIdentity($settings)
                            !== ModelHostControllerResourcePolicyService::requestContractIdentity($prepared['contract']['request_settings'])) {
                        throw new RuntimeException('Actual inference transport differs from its admitted request contract.');
                    }
                    $contract = $prepared['contract'];
                    ModelHostControllerResourcePolicyService::assertReceiptBinding($prepared['binding'],
                        $this->resources->assertRequestContract($contract['controller_host_id'], $contract['controller_target'], $contract));
                    $this->selection->renewInferenceCapacity($request, $prepared['binding']);
                    $sent = true;
                });
            $deterministicDeny = $target['inference_adapter'] === 'privacy_gate'
                && ($response['deterministic'] ?? false) === true
                && ($response['policy_checked'] ?? false) === true
                && ($response['decision'] ?? null) === 'deny';
            if (! $sent && $deterministicDeny) {
                // The deterministic gate still completed under the managed lease.
                $this->selection->renewInferenceCapacity($request, $prepared['binding']);
            }
            if (! $sent && ! $deterministicDeny) {
                throw new RuntimeException('Managed inference did not use the admitted transport boundary.');
            }
        });
        if (($receipt['result'] ?? null) !== 'green' || ! is_array($response)) {
            if (($receipt['result'] ?? null) === 'held') {
                throw new RuntimeException('MHC-06 managed inference is held for outcome reconciliation; automatic retry and fallback are forbidden.');
            }
            throw new RuntimeException('MHC-06 managed inference was queued without fallback.');
        }

        return [
            'response' => $response,
            'controller_receipt' => $receipt,
        ];
    }

    /** Prepare the settings that the existing gateway will send, before lifecycle work. */
    private function prepareRequest(string $targetId, array $target, array $config): ?array
    {
        if (! $this->resources->required()['enabled']) {
            return null;
        }
        if (! in_array($target['inference_adapter'], ['ollama', 'openai_compatible', 'privacy_gate'], true)) {
            throw new RuntimeException('Managed capacity requires a qualified transport contract for this adapter.');
        }
        $provider = $this->providers->getInstance($target['provider_instance_id']);
        $providerConfig = is_string($provider->config ?? null)
            ? json_decode($provider->config, true, flags: JSON_THROW_ON_ERROR) : ($provider->config ?? []);
        if (! is_array($providerConfig)) {
            throw new RuntimeException('Managed provider runtime configuration is invalid.');
        }
        // Reuse the existing gateway merge; explicit resolved options will win again at dispatch.
        $transportAdapter = $target['inference_adapter'] === 'privacy_gate' ? 'openai_compatible' : $target['inference_adapter'];
        $config = $this->ai->prepareManagedRuntimeConfig($config, $providerConfig, $target['provider_model_role'], $transportAdapter);
        if ($target['inference_adapter'] === 'privacy_gate') {
            // The gate owns these values at dispatch; role/runtime settings cannot reinterpret them.
            $config['max_tokens'] = BitLlmPrivacyGateService::INFERENCE_MAX_TOKENS;
            $config['temperature'] = BitLlmPrivacyGateService::INFERENCE_TEMPERATURE;
        }
        $temperature = $config['temperature'] ?? null;
        if ((! is_int($temperature) && ! is_float($temperature)) || ! is_finite((float) $temperature)) {
            throw new RuntimeException('Managed inference requires explicit table or request sampling settings.');
        }
        $context = $config['ollama_options']['num_ctx'] ?? $config['num_ctx'] ?? null;
        if (! is_int($context) || $context < 1) {
            throw new RuntimeException('Managed inference requires an explicit table or request context window.');
        }
        $base = rtrim((string) ($provider->base_url ?? ''), '/');
        if (! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)
            || ! is_string(parse_url($base, PHP_URL_HOST)) || parse_url($base, PHP_URL_USER) !== null
            || parse_url($base, PHP_URL_QUERY) !== null || parse_url($base, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('Managed inference provider endpoint is invalid.');
        }
        if ($target['inference_adapter'] === 'ollama') {
            if (! array_key_exists('ollama_think', $config) || ! is_bool($config['ollama_think'])
                || ! isset($config['ollama_options']['top_p'])) {
                throw new RuntimeException('Managed Ollama requires explicit table or request think and top_p settings.');
            }
            $settings = AIRouter::ollamaGenerationSettings($target['model'], (float) $temperature, $config['max_tokens'], $config);
            $output = $settings['options']['num_predict'];
            $wire = 'ollama_generate';
            $endpoint = $base.'/api/generate';
        } else {
            // Context is launch-owned for this API and is attested by the host runtime identity.
            if (isset($config['ollama_options']) && $config['ollama_options'] !== []) {
                throw new RuntimeException('OpenAI-compatible capacity cannot reinterpret Ollama options.');
            }
            $format = null;
            $schema = is_string($config['ollama_output_schema'] ?? null) ? trim($config['ollama_output_schema']) : '';
            if ($schema !== '') {
                $format = ['type' => 'json_schema', 'json_schema' => ['name' => $schema, 'strict' => true,
                    'schema' => app(OllamaStructuredOutputSchemaService::class)->resolve($schema)]];
            }
            $settings = OpenAICompatibleProgressClient::contentFreeSettings(
                OpenAICompatibleProgressClient::streamPayload(
                    AIService::openAICompatibleTextPayload($config, $providerConfig, $target['model'], $format),
                    is_array($providerConfig['stream_policy'] ?? null) ? $providerConfig['stream_policy'] : []
                )
            );
            $output = $settings['max_tokens'];
            $wire = 'openai_chat_completions';
            $endpoint = $base.'/chat/completions';
        }
        if (! is_int($output) || $output < 1 || $output > $target['inference_limits']['max_tokens'] || $output >= $context) {
            throw new RuntimeException('Resolved inference output exceeds its table limit or context window.');
        }
        $host = $this->controllerPolicies->required()['host_id'];
        $contract = ['schema' => 'plos.managed_inference.request.v1', 'controller_host_id' => $host,
            'controller_target' => $targetId, 'context_window' => $context, 'wire_api' => $wire,
            'endpoint_sha256' => hash('sha256', $endpoint), 'request_settings' => $settings];
        $binding = $this->resources->assertRequestContract($host, $targetId, $contract);
        if ($binding === null) {
            throw new RuntimeException('Capacity policy changed during request preparation.');
        }

        return compact('config', 'contract', 'binding', 'endpoint');
    }

    private function assertLocalProvider(string $providerId, mixed $adapter): void
    {
        $provider = $this->providers->getInstance($providerId);
        $expectedType = match ($adapter) {
            'ollama' => 'ollama',
            'openai_compatible', 'privacy_gate' => 'local_llm',
            default => null,
        };
        if ($expectedType === null || $provider === null
            || ($provider->instance_id ?? null) !== $providerId
            || ($provider->instance_type ?? null) !== $expectedType) {
            throw new RuntimeException('MHC-06 inference adapter requires the exact table-admitted local provider type.');
        }
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $target @return array<string,mixed> */
    private function invokeExact(string $role, string $prompt, array $config, array $target, string $requestId): array
    {
        $instanceId = (string) ($target['target'] ?? '');
        $providerId = (string) ($target['provider_instance_id'] ?? $instanceId);
        $providerModelRole = (string) ($target['provider_model_role'] ?? '');
        $model = (string) ($target['model'] ?? '');
        if ($providerModelRole === '') {
            throw new RuntimeException('MHC-06 managed inference provider role is not admitted.');
        }
        $guarded = [
            ...$config,
            'model_role' => $providerModelRole,
            'model_override' => $model,
            'use_cache' => false,
            'dedup' => false,
            '_skip_decompose' => true,
            'disable_cascade' => true,
            'suppressAlert' => true,
        ];

        $adapter = $target['inference_adapter'] ?? null;
        $this->assertLocalProvider($providerId, $adapter);

        if ($adapter === 'openai_compatible') {
            $response = $this->ai->process($prompt, [
                ...$guarded,
                'model' => "provider:{$providerId}/{$model}",
                'required_instance_id' => $providerId,
            ]);
        } elseif ($adapter === 'ollama') {
            $response = $this->ai->process($prompt, [
                ...$guarded,
                'model' => $model,
                'allowed_ollama_instance_ids' => [$providerId],
                'preferred_ollama_instance_ids' => [$providerId],
                'ollama_required_no_fallback' => true,
            ]);
        } elseif ($adapter === 'privacy_gate' && $role === BitLlmPrivacyGateService::MODEL_ROLE
            && $providerModelRole === BitLlmPrivacyGateService::MODEL_ROLE) {
            $response = $this->bitnetAdmission->runAutomatic($requestId, fn (): array => $this->bitnet->decide($prompt, [
                'required_instance_id' => $providerId,
                'redacted_input' => true,
                'sensitive_data' => (bool) ($config['sensitive_data'] ?? false),
                'timeout' => (int) ($config['timeout'] ?? $target['inference_limits']['privacy_timeout_seconds']),
            ]));
        } else {
            throw new RuntimeException('MHC-06 managed inference target is not admitted.');
        }

        $actualProvider = (string) ($response['provider'] ?? '');
        if (($response['success'] ?? false) !== true
            || $actualProvider !== $providerId
            || (isset($response['model']) && (string) $response['model'] !== $model)
            || ($adapter === 'openai_compatible' && ($response['fallback_suppressed'] ?? false) !== true)) {
            throw new RuntimeException('MHC-06 managed inference returned an inexact or failed target response.');
        }
        $response['fallback_suppressed'] = true;

        return $response;
    }
}
