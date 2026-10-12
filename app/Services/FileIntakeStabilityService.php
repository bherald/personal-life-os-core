<?php

namespace App\Services;

use Illuminate\Support\Sleep;

class FileIntakeStabilityService
{
    public const CONFIG_KEY = 'files.file_intake_stability_policy';

    private const MAX_DELAY_SECONDS = 300.0;

    private const DEFAULT_POLICY = [
        'enabled' => true,
        'stability_delay_seconds' => 5.0,
    ];

    public function __construct(
        private SystemConfigService $systemConfig
    ) {}

    /**
     * Wait once, then retain only files whose size and modification time are unchanged.
     *
     * @param  array<int,array<string,mixed>>  $files
     * @return array{stable: array<int,array<string,mixed>>, deferred: int, deferred_reasons: array<string,int>, wait_seconds: float, policy_source: string}
     */
    public function partitionStableFiles(array $files, NextcloudFileApiService $nextcloudApi): array
    {
        $policy = $this->policy();
        $delaySeconds = $policy['enabled'] ? $policy['stability_delay_seconds'] : 0.0;

        if ($files === [] || $delaySeconds <= 0.0) {
            return [
                'stable' => array_values($files),
                'deferred' => 0,
                'deferred_reasons' => [],
                'wait_seconds' => 0.0,
                'policy_source' => self::CONFIG_KEY,
            ];
        }

        Sleep::for($delaySeconds)->seconds();

        $stable = [];
        $deferredReasons = [];

        foreach ($files as $file) {
            $path = $file['path'] ?? null;
            $before = $this->snapshot($file);

            if (! is_string($path) || $path === '' || $before === null) {
                $this->incrementReason($deferredReasons, 'initial_metadata_incomplete');

                continue;
            }

            $fresh = $nextcloudApi->getFileInfo($path);
            if (! ($fresh['success'] ?? false)) {
                $this->incrementReason($deferredReasons, 'restat_failed');

                continue;
            }

            $after = $this->snapshot($fresh);
            if ($after === null) {
                $this->incrementReason($deferredReasons, 'restat_metadata_incomplete');

                continue;
            }

            if ($before !== $after) {
                $this->incrementReason($deferredReasons, 'changed_during_delay');

                continue;
            }

            $stable[] = $file;
        }

        return [
            'stable' => $stable,
            'deferred' => count($files) - count($stable),
            'deferred_reasons' => $deferredReasons,
            'wait_seconds' => $delaySeconds,
            'policy_source' => self::CONFIG_KEY,
        ];
    }

    /** @return array{enabled: bool, stability_delay_seconds: float} */
    public function policy(): array
    {
        $configured = $this->systemConfig->get(self::CONFIG_KEY, self::DEFAULT_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        $delay = $configured['stability_delay_seconds'] ?? self::DEFAULT_POLICY['stability_delay_seconds'];
        $delay = is_numeric($delay) ? (float) $delay : self::DEFAULT_POLICY['stability_delay_seconds'];

        return [
            'enabled' => filter_var(
                $configured['enabled'] ?? self::DEFAULT_POLICY['enabled'],
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE
            ) ?? self::DEFAULT_POLICY['enabled'],
            'stability_delay_seconds' => max(0.0, min(self::MAX_DELAY_SECONDS, $delay)),
        ];
    }

    /** @return array{size: int, modified_at: int}|null */
    private function snapshot(array $file): ?array
    {
        $size = $file['size'] ?? null;
        $modifiedAt = $file['last_modified'] ?? null;

        if (! is_numeric($size) || ! is_string($modifiedAt) || trim($modifiedAt) === '') {
            return null;
        }

        $timestamp = strtotime($modifiedAt);
        if ($timestamp === false) {
            return null;
        }

        return [
            'size' => (int) $size,
            'modified_at' => $timestamp,
        ];
    }

    /** @param array<string,int> $reasons */
    private function incrementReason(array &$reasons, string $reason): void
    {
        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
    }
}
