<?php

namespace App\Services;

use RuntimeException;

class GroundingVerifierRuntimePolicyService
{
    public const CONFIG_KEY = 'rag.grounding_verifier_runtime_policy';

    public const SCHEMA = 'plos.rag_grounding_verifier_runtime_policy.v1';

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /**
     * @return array{
     *   max_claims:int,
     *   max_source_documents:int,
     *   response_max_chars:int,
     *   document_max_chars:int,
     *   minimum_claim_chars:int,
     *   grounded_threshold:float,
     *   partial_threshold:float,
     *   neutral_score:float,
     *   claim_extractor:array{max_tokens:int,temperature:float,model_role:string},
     *   nli:array{max_tokens:int,temperature:float,model_role:string}
     * }
     */
    public function required(): array
    {
        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('RAG grounding-verifier runtime policy is unavailable.');
        }

        $claimExtractor = $this->scorer($policy, 'claim_extractor');
        $nli = $this->scorer($policy, 'nli');
        $groundedThreshold = $this->boundedFloat($policy, 'grounded_threshold', 0.0, 1.0);
        $partialThreshold = $this->boundedFloat($policy, 'partial_threshold', 0.0, 1.0);
        if ($partialThreshold > $groundedThreshold) {
            throw new RuntimeException('RAG grounding-verifier runtime policy has invalid verdict thresholds.');
        }

        return [
            'max_claims' => $this->boundedInt($policy, 'max_claims', 1, 50),
            'max_source_documents' => $this->boundedInt($policy, 'max_source_documents', 1, 20),
            'response_max_chars' => $this->boundedInt($policy, 'response_max_chars', 100, 50000),
            'document_max_chars' => $this->boundedInt($policy, 'document_max_chars', 100, 20000),
            'minimum_claim_chars' => $this->boundedInt($policy, 'minimum_claim_chars', 1, 500),
            'grounded_threshold' => $groundedThreshold,
            'partial_threshold' => $partialThreshold,
            'neutral_score' => $this->boundedFloat($policy, 'neutral_score', 0.0, 1.0),
            'claim_extractor' => $claimExtractor,
            'nli' => $nli,
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array{max_tokens:int,temperature:float,model_role:string}
     */
    private function scorer(array $policy, string $key): array
    {
        $scorer = $policy[$key] ?? null;
        if (! is_array($scorer)) {
            throw new RuntimeException("RAG grounding-verifier runtime policy has invalid {$key}.");
        }

        $modelRole = $scorer['model_role'] ?? null;
        if (! is_string($modelRole) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $modelRole) !== 1) {
            throw new RuntimeException("RAG grounding-verifier runtime policy has invalid {$key}.model_role.");
        }

        return [
            'max_tokens' => $this->boundedInt($scorer, 'max_tokens', 1, 4096, $key.'.'),
            'temperature' => $this->boundedFloat($scorer, 'temperature', 0.0, 2.0, $key.'.'),
            'model_role' => $modelRole,
        ];
    }

    /** @param array<string,mixed> $values */
    private function boundedInt(array $values, string $key, int $minimum, int $maximum, string $prefix = ''): int
    {
        $value = $values[$key] ?? null;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("RAG grounding-verifier runtime policy has invalid {$prefix}{$key}.");
        }

        return $value;
    }

    /** @param array<string,mixed> $values */
    private function boundedFloat(array $values, string $key, float $minimum, float $maximum, string $prefix = ''): float
    {
        $value = $values[$key] ?? null;
        if ((! is_float($value) && ! is_int($value)) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("RAG grounding-verifier runtime policy has invalid {$prefix}{$key}.");
        }

        return (float) $value;
    }
}
