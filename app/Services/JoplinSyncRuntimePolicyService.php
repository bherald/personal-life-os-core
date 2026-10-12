<?php

namespace App\Services;

use RuntimeException;

class JoplinSyncRuntimePolicyService
{
    public const CONFIG_KEY = 'joplin.sync_runtime_policy';

    public const SCHEMA = 'plos.joplin_sync_runtime_policy.v1';

    /** @var array<string,mixed>|null */
    private ?array $resolved = null;

    public function __construct(private readonly SystemConfigService $systemConfig) {}

    /** @return array<string,mixed> */
    public function required(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $policy = $this->systemConfig->get(self::CONFIG_KEY);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Joplin sync runtime policy is unavailable.');
        }

        foreach (['webdav', 'sync', 'quarantine', 'vision', 'ocr'] as $section) {
            if (! is_array($policy[$section] ?? null)) {
                throw new RuntimeException("Joplin sync runtime policy is missing {$section}.");
            }
        }

        return $this->resolved = [
            'webdav' => [
                'connect_timeout_seconds' => $this->boundedInt($policy['webdav'], 'connect_timeout_seconds', 1, 60),
                'request_timeout_seconds' => $this->boundedInt($policy['webdav'], 'request_timeout_seconds', 1, 1800),
                'health_probe_timeout_seconds' => $this->boundedInt($policy['webdav'], 'health_probe_timeout_seconds', 1, 300),
                'directory_listing_timeout_seconds' => $this->boundedInt($policy['webdav'], 'directory_listing_timeout_seconds', 1, 1800),
                'note_fetch_timeout_seconds' => $this->boundedInt($policy['webdav'], 'note_fetch_timeout_seconds', 1, 1800),
                'retry_attempts' => $this->boundedInt($policy['webdav'], 'retry_attempts', 1, 10),
                'retry_base_delay_seconds' => $this->boundedInt($policy['webdav'], 'retry_base_delay_seconds', 1, 60),
                'attachment_timeout_seconds' => $this->boundedInt($policy['webdav'], 'attachment_timeout_seconds', 1, 1800),
            ],
            'sync' => [
                'default_chunk_size' => $this->boundedInt($policy['sync'], 'default_chunk_size', 1, 1000),
                'last_sync_cache_days' => $this->boundedInt($policy['sync'], 'last_sync_cache_days', 1, 90),
            ],
            'quarantine' => [
                'failure_threshold' => $this->boundedInt($policy['quarantine'], 'failure_threshold', 1, 100),
                'duration_hours' => $this->boundedInt($policy['quarantine'], 'duration_hours', 1, 8760),
                'cache_buffer_hours' => $this->boundedInt($policy['quarantine'], 'cache_buffer_hours', 0, 720),
                'retained_errors' => $this->boundedInt($policy['quarantine'], 'retained_errors', 1, 20),
            ],
            'vision' => [
                'timeout_seconds' => $this->boundedInt($policy['vision'], 'timeout_seconds', 1, 1800),
                'max_tokens' => $this->boundedInt($policy['vision'], 'max_tokens', 1, 32768),
            ],
            'ocr' => [
                'probe_timeout_seconds' => $this->boundedInt($policy['ocr'], 'probe_timeout_seconds', 1, 60),
                'process_timeout_seconds' => $this->boundedInt($policy['ocr'], 'process_timeout_seconds', 1, 3600),
            ],
        ];
    }

    /** @param array<string,mixed> $values */
    private function boundedInt(array $values, string $key, int $minimum, int $maximum): int
    {
        $value = $values[$key] ?? null;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException("Joplin sync runtime policy has invalid {$key}.");
        }

        return $value;
    }
}
