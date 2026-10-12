<?php

namespace App\Services;

use RuntimeException;

class RaptorCommunityLinkRuntimePolicyService
{
    public const CONFIG_KEY = 'rag.raptor_community_link_runtime_policy';

    public const SCHEMA = 'plos.rag_raptor_community_link_runtime_policy.v1';

    private const HARD_MAX_LIMIT = 1000000;

    private const HARD_MAX_ENTITY_VOTES = 1000;

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /** @return array{max_limit:int,minimum_entity_votes:int} */
    public function required(): array
    {
        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('RAPTOR community-link runtime policy is unavailable.');
        }

        return [
            'max_limit' => $this->boundedInt($policy, 'max_limit', self::HARD_MAX_LIMIT),
            'minimum_entity_votes' => $this->boundedInt(
                $policy,
                'minimum_entity_votes',
                self::HARD_MAX_ENTITY_VOTES,
            ),
        ];
    }

    /** @param array{max_limit:int,minimum_entity_votes:int} $policy */
    public function resolveLimit(mixed $requested, array $policy): int
    {
        if (! is_string($requested) || preg_match('/^[1-9][0-9]*$/', $requested) !== 1) {
            throw new RuntimeException('RAPTOR community linking requires an explicit positive --limit.');
        }

        $limit = (int) $requested;
        if ($limit > $policy['max_limit']) {
            throw new RuntimeException('RAPTOR community-link limit exceeds the runtime policy maximum.');
        }

        return $limit;
    }

    /** @param array<string,mixed> $policy */
    private function boundedInt(array $policy, string $key, int $maximum): int
    {
        $value = $policy[$key] ?? null;
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new RuntimeException("RAPTOR community-link runtime policy has invalid {$key}.");
        }

        return $value;
    }
}
