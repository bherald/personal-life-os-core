<?php

namespace App\Services;

use RuntimeException;

class RerankerSidecarRuntimePolicyService
{
    public const CONFIG_KEY = 'rag.reranker_sidecar_runtime_policy';

    public const SCHEMA = 'plos.rag_reranker_sidecar_runtime_policy.v1';

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /** Opt-in dedicated retrieval; a catalog entry alone does not grant model execution. */
    public function controllerPolicy(): ?array
    {
        $policy = $this->systemConfig->get('rag.reranker_controller_policy', null, strict: true);
        if ($policy === null || (is_array($policy) && ($policy['enabled'] ?? false) === false)) {
            return null;
        }
        if (! is_array($policy) || ($policy['schema'] ?? null) !== 'plos.reranker_controller_policy.v1'
            || ($policy['enabled'] ?? null) !== true
            || ! is_string($policy['instance_id'] ?? null) || $policy['instance_id'] === ''
            || ! is_string($policy['model'] ?? null) || $policy['model'] === '') {
            throw new RuntimeException('Dedicated reranker selection is not table-qualified.');
        }

        return $policy;
    }

    /**
     * @return array{
     *   health:array{connect_timeout_seconds:int,request_timeout_seconds:int},
     *   launcher_timeout_seconds:int,
     *   startup_timeout_seconds:int,
     *   startup_poll_seconds:int,
     *   shutdown:array{connect_timeout_seconds:int,request_timeout_seconds:int},
     *   rerank:array{connect_timeout_seconds:int,request_timeout_seconds:int},
     *   inference:array{max_length:int,batch_size:int,threads:int}
     * }
     */
    public function required(): array
    {
        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('RAG reranker-sidecar runtime policy is unavailable.');
        }

        $health = $this->requestPolicy($policy, 'health', 1, 300);
        $shutdown = $this->requestPolicy($policy, 'shutdown', 1, 300);
        $rerank = $this->requestPolicy($policy, 'rerank', 10, 3600);
        $inference = $policy['inference'] ?? null;
        if (! is_array($inference)) {
            throw new RuntimeException('RAG reranker-sidecar runtime policy has invalid inference.');
        }

        $startupTimeout = $this->boundedInt($policy, 'startup_timeout_seconds', 10, 3600);
        $startupPoll = $this->boundedInt($policy, 'startup_poll_seconds', 1, 60);
        if ($startupPoll >= $startupTimeout) {
            throw new RuntimeException('RAG reranker-sidecar runtime policy has invalid startup timing.');
        }

        return [
            'health' => $health,
            'launcher_timeout_seconds' => $this->boundedInt($policy, 'launcher_timeout_seconds', 1, 300),
            'startup_timeout_seconds' => $startupTimeout,
            'startup_poll_seconds' => $startupPoll,
            'shutdown' => $shutdown,
            'rerank' => $rerank,
            'inference' => [
                'max_length' => $this->boundedInt($inference, 'max_length', 128, 8192, 'inference.'),
                'batch_size' => $this->boundedInt($inference, 'batch_size', 1, 16, 'inference.'),
                'threads' => $this->boundedInt($inference, 'threads', 1, 32, 'inference.'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array{connect_timeout_seconds:int,request_timeout_seconds:int}
     */
    private function requestPolicy(array $policy, string $key, int $minimumRequest, int $maximumRequest): array
    {
        $request = $policy[$key] ?? null;
        if (! is_array($request)) {
            throw new RuntimeException("RAG reranker-sidecar runtime policy has invalid {$key}.");
        }

        $connectTimeout = $this->boundedInt($request, 'connect_timeout_seconds', 1, 60, $key.'.');
        $requestTimeout = $this->boundedInt(
            $request,
            'request_timeout_seconds',
            $minimumRequest,
            $maximumRequest,
            $key.'.',
        );
        if ($connectTimeout > $requestTimeout) {
            throw new RuntimeException("RAG reranker-sidecar runtime policy has invalid {$key} timing.");
        }

        return [
            'connect_timeout_seconds' => $connectTimeout,
            'request_timeout_seconds' => $requestTimeout,
        ];
    }

    /** @param array<string,mixed> $values */
    private function boundedInt(array $values, string $key, int $minimum, int $maximum, string $prefix = ''): int
    {
        $value = $values[$key] ?? null;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("RAG reranker-sidecar runtime policy has invalid {$prefix}{$key}.");
        }

        return $value;
    }
}
