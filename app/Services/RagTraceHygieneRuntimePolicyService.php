<?php

namespace App\Services;

use RuntimeException;

class RagTraceHygieneRuntimePolicyService
{
    public const CONFIG_KEY = 'rag.trace_hygiene_runtime_policy';

    public const SCHEMA = 'plos.rag_trace_hygiene_runtime_policy.v1';

    private const HARD_MAX_ROWS = 1000000;

    private const HARD_MAX_BATCH_SIZE = 5000;

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /** @return array{default_limit:int,max_rows:int,batch_size:int} */
    public function required(): array
    {
        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('RAG trace hygiene runtime policy is unavailable.');
        }

        $defaultLimit = $this->boundedInt($policy, 'default_limit', self::HARD_MAX_ROWS);
        $maxRows = $this->boundedInt($policy, 'max_rows', self::HARD_MAX_ROWS);
        $batchSize = $this->boundedInt($policy, 'batch_size', self::HARD_MAX_BATCH_SIZE);
        if ($defaultLimit > $maxRows) {
            throw new RuntimeException('RAG trace hygiene runtime policy has invalid row limits.');
        }

        return [
            'default_limit' => $defaultLimit,
            'max_rows' => $maxRows,
            'batch_size' => $batchSize,
        ];
    }

    /** @param array{default_limit:int,max_rows:int,batch_size:int} $policy */
    public function resolveLimit(mixed $requested, array $policy): int
    {
        if ($requested === null || $requested === '') {
            return $policy['default_limit'];
        }

        if (! is_string($requested) || preg_match('/^[1-9][0-9]*$/', $requested) !== 1) {
            throw new RuntimeException('RAG trace hygiene limit must be a positive integer.');
        }

        $limit = (int) $requested;
        if ($limit > $policy['max_rows']) {
            throw new RuntimeException('RAG trace hygiene limit exceeds the runtime policy maximum.');
        }

        return $limit;
    }

    /** @param array<string,mixed> $policy */
    private function boundedInt(array $policy, string $key, int $maximum): int
    {
        $value = $policy[$key] ?? null;
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new RuntimeException("RAG trace hygiene runtime policy has invalid {$key}.");
        }

        return $value;
    }
}
