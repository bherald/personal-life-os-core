<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class LlmCircuitBreakerPolicyService
{
    public const CONFIG_KEY = 'llm.circuit_breaker_runtime_policy';

    private const DEFAULT_POLICY = [
        'failure_threshold' => 5,
        'cooldown_seconds' => 30,
        'retry_after_max_seconds' => 7200,
    ];

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    public function failureThreshold(): int
    {
        return max(1, min(100, $this->intValue('failure_threshold')));
    }

    public function cooldownSeconds(): int
    {
        return max(1, min(86400, $this->intValue('cooldown_seconds')));
    }

    public function retryAfterMaxSeconds(): int
    {
        $configured = max(1, min(604800, $this->intValue('retry_after_max_seconds')));

        return max($this->cooldownSeconds(), $configured);
    }

    public function retryAfterSeconds(mixed $value, ?DateTimeInterface $now = null): int
    {
        $seconds = null;

        if (is_int($value) || (is_float($value) && is_finite($value))) {
            $seconds = (int) ceil($value);
        } elseif (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1) {
            $seconds = (int) trim($value);
        } elseif (is_string($value) && trim($value) !== '') {
            $retryAt = $this->httpDate(trim($value));
            if ($retryAt !== null) {
                $seconds = $retryAt->getTimestamp() - ($now ?? new DateTimeImmutable)->getTimestamp();
            }
        }

        if ($seconds === null || $seconds <= 0) {
            $seconds = $this->cooldownSeconds();
        }

        return min($seconds, $this->retryAfterMaxSeconds());
    }

    public function retryAfterMilliseconds(mixed $value, ?DateTimeInterface $now = null): int
    {
        return $this->retryAfterSeconds($value, $now) * 1000;
    }

    public function retryAfterSecondsFromMessage(string $message): int
    {
        if (preg_match('/retry[- ]?after[:= ]+(\d+(?:\.\d+)?)\s*(?:s|sec(?:ond)?s?)?/i', $message, $matches) === 1) {
            return $this->retryAfterSeconds($matches[1]);
        }

        return $this->cooldownSeconds();
    }

    public function retryAfterSecondsFromBackoffMilliseconds(int $milliseconds): int
    {
        return $this->retryAfterSeconds($milliseconds / 1000);
    }

    private function httpDate(string $value): ?DateTimeImmutable
    {
        $timezone = new DateTimeZone('GMT');
        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed;
            }
        }

        return null;
    }

    /** @return array<string, int> */
    public function resolvedPolicy(): array
    {
        return [
            'failure_threshold' => $this->failureThreshold(),
            'cooldown_seconds' => $this->cooldownSeconds(),
            'retry_after_max_seconds' => $this->retryAfterMaxSeconds(),
        ];
    }

    private function intValue(string $key): int
    {
        $configured = $this->systemConfig->get(self::CONFIG_KEY, self::DEFAULT_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($configured) || ! is_numeric($configured[$key] ?? null)) {
            return self::DEFAULT_POLICY[$key];
        }

        return (int) $configured[$key];
    }
}
