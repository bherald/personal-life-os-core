<?php

namespace App\Services;

use RuntimeException;

class AgentSkillAuditRuntimePolicyService
{
    public const CONFIG_KEY = 'agents.skill_audit_runtime_policy';

    public const SCHEMA = 'plos.agent_skill_audit_runtime_policy.v1';

    /** @var array{skillspector: array{timeout_seconds: int}}|null */
    private ?array $resolved = null;

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /** @return array{skillspector: array{timeout_seconds: int}} */
    public function required(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Agent skill audit runtime policy is unavailable.');
        }

        $skillspector = $policy['skillspector'] ?? null;
        if (! is_array($skillspector)) {
            throw new RuntimeException('Agent skill audit runtime policy is missing skillspector.');
        }

        return $this->resolved = [
            'skillspector' => [
                'timeout_seconds' => $this->boundedInt($skillspector, 'timeout_seconds', 10, 3600),
            ],
        ];
    }

    /** @param array<string,mixed> $values */
    private function boundedInt(array $values, string $key, int $minimum, int $maximum): int
    {
        $value = $values[$key] ?? null;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("Agent skill audit runtime policy has invalid {$key}.");
        }

        return $value;
    }
}
