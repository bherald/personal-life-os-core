<?php

namespace App\Services;

use RuntimeException;

class RagSourceIdentityDedupRuntimePolicyService
{
    public const CONFIG_KEY = 'rag.source_identity_dedup_runtime_policy';

    public const SCHEMA = 'plos.rag_source_identity_dedup_runtime_policy.v1';

    private const HARD_MAX_GROUPS = 5000;

    private const HARD_MAX_LOCK_TIMEOUT_SECONDS = 60;

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /** @return array{preview_default_groups:int,max_groups_per_run:int,lock_timeout_seconds:int} */
    public function required(): array
    {
        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('RAG source-identity dedup runtime policy is unavailable.');
        }

        $resolved = [
            'preview_default_groups' => $this->boundedInt($policy, 'preview_default_groups', self::HARD_MAX_GROUPS),
            'max_groups_per_run' => $this->boundedInt($policy, 'max_groups_per_run', self::HARD_MAX_GROUPS),
            'lock_timeout_seconds' => $this->boundedInt(
                $policy,
                'lock_timeout_seconds',
                self::HARD_MAX_LOCK_TIMEOUT_SECONDS,
            ),
        ];

        if ($resolved['preview_default_groups'] > $resolved['max_groups_per_run']) {
            throw new RuntimeException('RAG source-identity dedup preview limit exceeds its maximum.');
        }

        return $resolved;
    }

    /** @param array{preview_default_groups:int,max_groups_per_run:int,lock_timeout_seconds:int} $policy */
    public function resolveLimit(mixed $requested, array $policy, bool $execute): int
    {
        if ($requested === null || $requested === '') {
            if ($execute) {
                throw new RuntimeException('RAG source-identity dedup execution requires an explicit --limit.');
            }

            return $policy['preview_default_groups'];
        }

        $value = is_scalar($requested) ? trim((string) $requested) : '';
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1 || (int) $value > $policy['max_groups_per_run']) {
            throw new RuntimeException(
                "RAG source-identity dedup limit must be from 1 to {$policy['max_groups_per_run']}.",
            );
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $policy */
    private function boundedInt(array $policy, string $key, int $maximum): int
    {
        $value = $policy[$key] ?? null;
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new RuntimeException("RAG source-identity dedup runtime policy has invalid {$key}.");
        }

        return $value;
    }
}
