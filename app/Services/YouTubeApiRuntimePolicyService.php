<?php

namespace App\Services;

use RuntimeException;

class YouTubeApiRuntimePolicyService
{
    public const CONFIG_KEY = 'youtube.transcript_runtime_policy';

    /** @var array<string,int>|null */
    private ?array $resolved = null;

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /** @return array<string,int> */
    public function required(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy)) {
            throw new RuntimeException('YouTube API transcript runtime policy is unavailable.');
        }

        $requestTimeout = $this->boundedInt($policy, 'phplib_request_timeout_seconds', 1, 1800);
        $connectTimeout = $this->boundedInt($policy, 'phplib_connect_timeout_seconds', 1, 300);
        if ($connectTimeout > $requestTimeout) {
            throw new RuntimeException('YouTube API transcript runtime policy connect timeout exceeds request timeout.');
        }

        return $this->resolved = [
            'phplib_request_timeout_seconds' => $requestTimeout,
            'phplib_connect_timeout_seconds' => $connectTimeout,
            'whisper_youtube_download_timeout_seconds' => $this->boundedInt(
                $policy,
                'whisper_youtube_download_timeout_seconds',
                1,
                7200,
            ),
        ];
    }

    /** @param array<string,mixed> $policy */
    private function boundedInt(array $policy, string $key, int $minimum, int $maximum): int
    {
        $value = $policy[$key] ?? null;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("YouTube API transcript runtime policy has invalid {$key}.");
        }

        return $value;
    }
}
