<?php

namespace App\Services;

use RuntimeException;

class ChatRuntimePolicyService
{
    public const RUNTIME_POLICY_KEY = 'ai_chat.runtime_policy';

    public const RUNTIME_POLICY_SCHEMA = 'plos.ai_chat_runtime_policy.v1';

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /**
     * @return array{
     *   direct_ollama_stream:array{idle_timeout_seconds:int,absolute_timeout_seconds:int,connect_timeout_seconds:int,progress_log_interval_seconds:int,num_predict:int,temperature:float},
     *   rag:array{fallback_cosine_threshold:float}
     * }
     */
    public function required(): array
    {
        $policy = $this->systemConfig->get(self::RUNTIME_POLICY_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::RUNTIME_POLICY_SCHEMA) {
            throw new RuntimeException('AI chat runtime policy is unavailable.');
        }

        $stream = $policy['direct_ollama_stream'] ?? null;
        $rag = $policy['rag'] ?? null;
        if (! is_array($stream) || ! is_array($rag)) {
            throw new RuntimeException('AI chat runtime policy is incomplete.');
        }

        $validatedStream = [
            'idle_timeout_seconds' => $this->boundedInt($stream, 'idle_timeout_seconds', 30, 3600),
            'absolute_timeout_seconds' => $this->boundedInt($stream, 'absolute_timeout_seconds', 0, 86400),
            'connect_timeout_seconds' => $this->boundedInt($stream, 'connect_timeout_seconds', 1, 60),
            'progress_log_interval_seconds' => $this->boundedInt($stream, 'progress_log_interval_seconds', 5, 300),
            'num_predict' => $this->boundedInt($stream, 'num_predict', 1, 32768),
            'temperature' => $this->boundedFloat($stream, 'temperature', 0.0, 2.0),
        ];
        $validatedRag = [
            'fallback_cosine_threshold' => $this->boundedFloat($rag, 'fallback_cosine_threshold', 0.0, 1.0),
        ];

        return [
            'direct_ollama_stream' => $validatedStream,
            'rag' => $validatedRag,
        ];
    }

    /** @param array<string,mixed> $values */
    private function boundedInt(array $values, string $key, int $minimum, int $maximum): int
    {
        $value = $values[$key] ?? null;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("AI chat runtime policy has invalid {$key}.");
        }

        return $value;
    }

    /** @param array<string,mixed> $values */
    private function boundedFloat(array $values, string $key, float $minimum, float $maximum): float
    {
        $value = $values[$key] ?? null;
        if (! is_float($value) && ! is_int($value)) {
            throw new RuntimeException("AI chat runtime policy has invalid {$key}.");
        }

        $value = (float) $value;
        if ($value < $minimum || $value > $maximum) {
            throw new RuntimeException("AI chat runtime policy has invalid {$key}.");
        }

        return $value;
    }
}
