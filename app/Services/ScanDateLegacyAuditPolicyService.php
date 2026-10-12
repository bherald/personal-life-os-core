<?php

namespace App\Services;

use RuntimeException;

class ScanDateLegacyAuditPolicyService
{
    public const CONFIG_KEY = 'files.scan_date_legacy_audit_policy';

    public const SCHEMA = 'plos.files_scan_date_legacy_audit_policy.v1';

    private const HARD_MAX_LIMIT = 10000;

    private const HARD_MAX_OFFSET = 10000000;

    private const HARD_MAX_TIMEOUT_SECONDS = 300;

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /**
     * @return array{default_limit:int,max_limit:int,exiftool_timeout_seconds:int}
     */
    public function required(): array
    {
        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Legacy scan-date audit policy is unavailable.');
        }

        $resolved = [
            'default_limit' => $this->boundedInt($policy, 'default_limit', self::HARD_MAX_LIMIT),
            'max_limit' => $this->boundedInt($policy, 'max_limit', self::HARD_MAX_LIMIT),
            'exiftool_timeout_seconds' => $this->boundedInt(
                $policy,
                'exiftool_timeout_seconds',
                self::HARD_MAX_TIMEOUT_SECONDS,
            ),
        ];

        if ($resolved['default_limit'] > $resolved['max_limit']) {
            throw new RuntimeException('Legacy scan-date audit default_limit exceeds max_limit.');
        }

        return $resolved;
    }

    /** @param array{default_limit:int,max_limit:int} $policy */
    public function resolveLimit(mixed $requested, array $policy): int
    {
        if ($requested === null || $requested === '') {
            return $policy['default_limit'];
        }

        $limit = $this->positiveInteger($requested, '--limit');
        if ($limit > $policy['max_limit']) {
            throw new RuntimeException('Legacy scan-date audit limit exceeds the table-owned maximum.');
        }

        return $limit;
    }

    public function resolveOffset(mixed $requested): int
    {
        if ($requested === null || $requested === '') {
            return 0;
        }
        if (! is_string($requested) || preg_match('/^(0|[1-9][0-9]*)$/', $requested) !== 1) {
            throw new RuntimeException('Legacy scan-date audit --offset must be a non-negative integer.');
        }

        $offset = (int) $requested;
        if ($offset > self::HARD_MAX_OFFSET) {
            throw new RuntimeException('Legacy scan-date audit offset exceeds the safety maximum.');
        }

        return $offset;
    }

    /** @param array<string,mixed> $policy */
    private function boundedInt(array $policy, string $key, int $maximum): int
    {
        $value = $policy[$key] ?? null;
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new RuntimeException("Legacy scan-date audit policy has invalid {$key}.");
        }

        return $value;
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new RuntimeException("Legacy scan-date audit {$label} must be a positive integer.");
        }

        return (int) $value;
    }
}
