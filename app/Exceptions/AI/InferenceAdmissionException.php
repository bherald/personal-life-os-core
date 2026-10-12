<?php

namespace App\Exceptions\AI;

use App\Exceptions\ModelHostControllerUnavailableException;

/** A request-policy or custody refusal; it is not a provider failure. */
class InferenceAdmissionException extends \RuntimeException
{
    /** Diagnostic codes already allowlisted by the model-host controller; never action authority. */
    private const FAILED_CHECKS = [
        'task_exact', 'control_exact', 'executable_exact', 'runtime_files_exact', 'running_exact',
        'resource_telemetry', 'free_physical_bytes', 'commit_headroom_bytes', 'drive_c_free_bytes',
        'balanced_power', 'drive_d_free_bytes', 'pagefile_current_mib', 'pagefile_growth_mib',
        'thermal_celsius', 'disk_thermal_celsius', 'resource_gate', 'gpu_free_bytes',
        'pagefile_current_bytes', 'pagefile_growth_bytes',
    ];

    public readonly array $controllerFailure;

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $controllerFailure = [])
    {
        parent::__construct($message, $code, $previous);
        $this->controllerFailure = self::sanitizeControllerFailure($controllerFailure);
    }

    /** Typed admission stops retain no-replay semantics through ordinary exception wrappers. */
    public static function isAdmissionFailure(\Throwable $error): bool
    {
        do {
            if ($error instanceof self
                || ($error instanceof ModelHostControllerUnavailableException && $error->dispatched)) {
                return true;
            }
            $error = $error->getPrevious();
        } while ($error !== null);

        return false;
    }

    /** Wrapping custody errors retain only the bounded diagnostic of the original refusal. */
    public static function controllerFailureFrom(\Throwable $error): array
    {
        $waitFailure = null;
        for ($depth = 0; $depth < 16; $depth++) {
            if ($error instanceof self && $error->controllerFailure !== []) {
                return $error->controllerFailure;
            }
            if ($error instanceof ModelHostControllerUnavailableException && $waitFailure === null) {
                $waitFailure = self::sanitizeControllerFailure([
                    'wait_reason' => $error->reason,
                    'last_wait_reason' => $error->lastWaitReason,
                    'dispatched' => $error->dispatched,
                    'capacity_shortage' => $error->capacityShortage,
                ]);
            }
            $error = $error->getPrevious();
            if ($error === null) {
                break;
            }
        }

        return $waitFailure ?? [];
    }

    /** Fixed identifiers and bounded numbers only; unknown keys and private text are discarded. */
    public static function sanitizeControllerFailure(array $input): array
    {
        $output = [];
        $enums = [
            'operation' => ['status', 'target/status', 'receipt', 'plan', 'activate', 'heartbeat', 'release', 'recover', 'technical-fence',
                'k3/status', 'k3/plan', 'k3/activate', 'k3/heartbeat', 'k3/release', 'k3/recover'],
            'reason_code' => ['heartbeat_internal_failure', 'release_internal_failure', 'lifecycle_gate_failed', 'invalid_request', 'unauthorized',
                'request_too_large', 'unknown_route', 'controller_request_rejected', 'controller_transport_unavailable'],
            'stage' => ['request', 'request_identity', 'read_state', 'target_status', 'resources', 'model_loaded',
                'capacity_projection', 'gate', 'persist', 'k3_lease_status', 'lifecycle_heartbeat', 'lifecycle_release', 'cleanup_residency', 'stop_runtime', 'verify_cleanup', 'response', 'unknown'],
            'wait_reason' => ['cancelled', 'wait_deadline', 'queue_full', 'rejected', 'busy', 'capacity'],
            'last_wait_reason' => ['busy', 'capacity'],
            'exception_kind' => ['RuntimeException', 'InvalidOperationException', 'ParameterBindingException',
                'PropertyNotFoundException', 'MethodInvocationException', 'InvalidCastException', 'CommandNotFoundException',
                'CimException', 'WebException', 'IOException', 'UnauthorizedAccessException', 'ActionPreferenceStopException',
                'ArgumentException', 'ArgumentNullException', 'PSArgumentException', 'PSInvalidCastException',
                'PSInvalidOperationException', 'other'],
        ];
        foreach ($enums as $field => $allowed) {
            if (in_array($input[$field] ?? null, $allowed, true)) {
                $output[$field] = $input[$field];
            }
        }
        foreach (['http_status' => [100, 599], 'source_line' => [0, 9999], 'duration_ms' => [0, 86_400_000]] as $field => [$min, $max]) {
            if (is_int($input[$field] ?? null) && $input[$field] >= $min && $input[$field] <= $max) {
                $output[$field] = $input[$field];
            }
        }
        if (is_bool($input['dispatched'] ?? null)) {
            $output['dispatched'] = $input['dispatched'];
        }
        if (($output['wait_reason'] ?? null) === 'capacity' || ($output['last_wait_reason'] ?? null) === 'capacity') {
            $shortage = self::sanitizeCapacityShortage($input['capacity_shortage'] ?? null);
            if ($shortage !== []) {
                $output['capacity_shortage'] = $shortage;
            }
        }

        $checks = $input['failed_checks'] ?? null;
        if (($output['reason_code'] ?? null) === 'lifecycle_gate_failed'
            && is_array($checks) && array_is_list($checks) && count($checks) <= count(self::FAILED_CHECKS)) {
            $checks = array_values(array_unique(array_filter($checks,
                static fn ($check): bool => is_string($check) && in_array($check, self::FAILED_CHECKS, true))));
            if ($checks !== []) {
                $output['failed_checks'] = $checks;
            }
        }

        return $output;
    }

    private static function sanitizeCapacityShortage(mixed $input): array
    {
        $metrics = ['free_physical_bytes', 'commit_headroom_bytes', 'gpu_free_bytes',
            'drive_c_free_bytes', 'drive_d_free_bytes', 'pagefile_current_bytes', 'pagefile_growth_bytes'];
        if (! is_array($input) || array_diff(array_keys($input), ['phase', 'requirements']) !== []
            || ! in_array($input['phase'] ?? null, ['cold', 'loaded'], true)
            || ! is_array($input['requirements'] ?? null) || $input['requirements'] === []
            || count($input['requirements']) > count($metrics)) {
            return [];
        }
        foreach ($input['requirements'] as $metric => $rule) {
            if (! in_array($metric, $metrics, true) || ! is_array($rule)
                || array_diff(array_keys($rule), ['minimum', 'maximum', 'available', 'passed']) !== []
                || ! is_int($rule['available'] ?? null) || $rule['available'] < 0 || ($rule['passed'] ?? null) !== false
                || (! array_key_exists('minimum', $rule) && ! array_key_exists('maximum', $rule))) {
                return [];
            }
            foreach (['minimum', 'maximum'] as $bound) {
                if (array_key_exists($bound, $rule) && (! is_int($rule[$bound]) || $rule[$bound] < 0)) {
                    return [];
                }
            }
            if ((isset($rule['minimum'], $rule['maximum']) && $rule['minimum'] > $rule['maximum'])
                || ((! isset($rule['minimum']) || $rule['available'] >= $rule['minimum'])
                    && (! isset($rule['maximum']) || $rule['available'] <= $rule['maximum']))) {
                return [];
            }
        }
        ksort($input['requirements']);

        return $input;
    }
}
