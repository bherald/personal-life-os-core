<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** Carries only validated gate identifiers across the CLI's privacy boundaries. */
final class CliControllerGateFailure extends RuntimeException
{
    private function __construct(public readonly array $failedChecks)
    {
        parent::__construct('Controller reported failed checks: '.implode(', ', $failedChecks).'.');
    }

    /** Return only fixed identifiers; raw errors remain in private artifacts. */
    public static function fromResponse(string $raw): ?self
    {
        if (strlen($raw) > 4096) {
            return null;
        }
        $body = json_decode($raw, true);
        $fields = ['schema', 'host_id', 'reason_code', 'failed_checks', 'contains_prompt_or_response_content', 'result'];
        $allowed = ['task_exact', 'control_exact', 'executable_exact', 'runtime_files_exact', 'running_exact',
            'resource_telemetry', 'free_physical_bytes', 'commit_headroom_bytes', 'drive_c_free_bytes',
            'balanced_power', 'drive_d_free_bytes', 'pagefile_current_mib', 'pagefile_growth_mib',
            'thermal_celsius', 'disk_thermal_celsius', 'resource_gate', 'gpu_free_bytes', 'pagefile_current_bytes', 'pagefile_growth_bytes'];
        if (! is_array($body) || count($body) !== count($fields) || array_diff(array_keys($body), $fields) !== []
            || ($body['schema'] ?? null) !== 'plos.model_host_controller.error.v1' || ($body['host_id'] ?? null) !== '.87'
            || ($body['reason_code'] ?? null) !== 'lifecycle_gate_failed' || ($body['result'] ?? null) !== 'rejected'
            || ($body['contains_prompt_or_response_content'] ?? null) !== false
            || ! is_array($body['failed_checks'] ?? null) || ! array_is_list($body['failed_checks'])
            || count($body['failed_checks']) < 1 || count($body['failed_checks']) > count($allowed)) {
            return null;
        }
        foreach ($body['failed_checks'] as $code) {
            if (! is_string($code) || ! in_array($code, $allowed, true)) {
                return null;
            }
        }

        return new self(array_values(array_unique($body['failed_checks'])));
    }
}
