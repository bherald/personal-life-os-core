<?php

namespace App\Services;

use RuntimeException;

class RelevanceGatingRuntimePolicyService
{
    public const CONFIG_KEY = 'rag.relevance_gating_runtime_policy';

    public const SCHEMA = 'plos.rag_relevance_gating_runtime_policy.v1';

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /**
     * @return array{
     *   relevance_threshold:float,
     *   max_documents_to_gate:int,
     *   max_document_chars:int,
     *   neutral_score:float,
     *   scorer:array{max_tokens:int,temperature:float,model_role:string}
     * }
     */
    public function required(): array
    {
        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('RAG relevance-gating runtime policy is unavailable.');
        }

        $scorer = $policy['scorer'] ?? null;
        if (! is_array($scorer)) {
            throw new RuntimeException('RAG relevance-gating runtime policy is incomplete.');
        }

        $modelRole = $scorer['model_role'] ?? null;
        if (! is_string($modelRole) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $modelRole) !== 1) {
            throw new RuntimeException('RAG relevance-gating runtime policy has invalid model_role.');
        }

        return [
            'relevance_threshold' => $this->boundedFloat($policy, 'relevance_threshold', 0.0, 1.0),
            'max_documents_to_gate' => $this->boundedInt($policy, 'max_documents_to_gate', 1, 100),
            'max_document_chars' => $this->boundedInt($policy, 'max_document_chars', 100, 20000),
            'neutral_score' => $this->boundedFloat($policy, 'neutral_score', 0.0, 1.0),
            'scorer' => [
                'max_tokens' => $this->boundedInt($scorer, 'max_tokens', 1, 4096),
                'temperature' => $this->boundedFloat($scorer, 'temperature', 0.0, 2.0),
                'model_role' => $modelRole,
            ],
        ];
    }

    /** @param array<string,mixed> $values */
    private function boundedInt(array $values, string $key, int $minimum, int $maximum): int
    {
        $value = $values[$key] ?? null;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("RAG relevance-gating runtime policy has invalid {$key}.");
        }

        return $value;
    }

    /** @param array<string,mixed> $values */
    private function boundedFloat(array $values, string $key, float $minimum, float $maximum): float
    {
        $value = $values[$key] ?? null;
        if ((! is_float($value) && ! is_int($value)) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("RAG relevance-gating runtime policy has invalid {$key}.");
        }

        return (float) $value;
    }
}
