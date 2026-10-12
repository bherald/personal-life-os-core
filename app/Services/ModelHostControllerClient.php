<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ModelHostControllerClient
{
    public function __construct(
        private readonly ModelHostControllerPolicyService $policies,
        private readonly ?ModelHostControllerMhc08K3PolicyService $mhc08Policies = null,
        private readonly ?Closure $requestTransport = null,
    ) {}

    /** @return array<string,mixed> */
    public function status(): array
    {
        $policy = $this->policies->required();
        if (! $policy['enabled']) {
            return $this->disabledStatus($policy);
        }

        return $this->request('get', '/v1/status', [], $policy,
            (int) config('model_host_controller.status_timeout_seconds', 120));
    }

    /** Fresh read of one qualified runtime and the host lease; no lifecycle mutation. */
    public function targetStatus(string $target): array
    {
        $policy = $this->policies->required();
        $this->assertTarget($target, $policy, false);
        if ($target === 'kimi_k3_87') {
            throw new RuntimeException('K3 requires its dedicated status route.');
        }

        $status = $this->request('post', '/v1/target/status', ['target' => $target], $policy);
        if (($status['mutation_available'] ?? null) !== false || $status['result'] !== 'guarded_target') {
            throw new RuntimeException('Selected-target status is not a read-only observation.');
        }

        return $status;
    }

    /** Exact persisted operation evidence; absence does not prove an operation was never sent. */
    public function receipt(string $operation, string $target, string $requestId, string $leaseId): array
    {
        if (! in_array($operation, ['activate', 'heartbeat', 'release', 'technical_fence'], true)) {
            throw new RuntimeException('Controller receipt operation or target is not admitted.');
        }
        $policy = $this->policies->required();
        $this->assertTarget($target, $policy, false);
        $response = $this->request('post', '/v1/receipt', [
            'operation' => $operation, 'target' => $target,
            'request_id' => $this->requestId($requestId), 'lease_id' => $this->leaseId($leaseId),
        ], $policy);
        if (($response['operation'] ?? null) !== $operation || ($response['mutation_available'] ?? null) !== false
            || ! is_bool($response['record_found'] ?? null)
            || ($response['result'] ?? null) !== ($response['record_found'] ? 'found' : 'not_found')
            || ($response['record_found'] ? ! is_array($response['receipt'] ?? null) : ($response['receipt'] ?? null) !== null)) {
            throw new RuntimeException('Controller receipt lookup is inexact or unsafe.');
        }

        if ($response['record_found']) {
            $receipt = $response['receipt'];
            $kind = $operation === 'activate' ? 'activation' : $operation;
            if (($receipt['schema'] ?? null) !== 'plos.model_host_controller.'.$kind.'.v1'
                || ($receipt['host_id'] ?? null) !== $policy['host_id']
                || ($receipt['request_id'] ?? null) !== trim($requestId) || ($receipt['lease_id'] ?? null) !== trim($leaseId)
                || ($receipt['target'] ?? null) !== $target || ($receipt['contains_prompt_or_response_content'] ?? null) !== false) {
                throw new RuntimeException('Persisted controller receipt differs from its exact lookup.');
            }
        }

        return $response;
    }

    /** Operator-authorized technical fence; never activate, stop, unload or infer success. */
    public function technicalFence(array $binding): array
    {
        $policy = $this->policies->required();
        $this->assertTarget($binding['target'], $policy, false);

        return $this->request('post', '/v1/technical-fence', $binding + ['execute' => true], $policy);
    }

    /** @return array<string,mixed> */
    public function plan(string $target, string $requestId): array
    {
        $policy = $this->policies->required();
        $this->assertTarget($target, $policy, false);

        return $this->request('post', '/v1/plan', [
            'target' => $target,
            'request_id' => $this->requestId($requestId),
        ], $policy);
    }

    /** @return array<string,mixed> */
    public function activate(string $target, string $requestId, string $leaseId): array
    {
        $policy = $this->policies->required();
        $this->assertTarget($target, $policy, true);

        return $this->request('post', '/v1/activate', [
            'target' => $target,
            'request_id' => $this->requestId($requestId),
            'lease_id' => $this->leaseId($leaseId),
            'execute' => true,
        ], $policy, isset($policy['targets'][$target]['verification_timeout_seconds'])
            ? max((int) config('model_host_controller.mutation_timeout_seconds'),
                $policy['targets'][$target]['verification_timeout_seconds'] + $policy['targets'][$target]['startup_timeout_seconds']
                + (int) config('model_host_controller.request_timeout_seconds')) : null);
    }

    /** @return array<string,mixed> */
    public function planK3(string $role, string $requestId, ?array $candidatePolicy = null): array
    {
        $policy = $candidatePolicy ?? $this->mhc08Policy();
        $this->assertK3Role($role, $policy, false, $candidatePolicy !== null);

        $response = $this->request('post', '/v1/k3/plan', [
            'target' => $policy['target']['target_id'],
            'role' => $role,
            'request_id' => $this->requestId($requestId),
        ], $this->policies->required());
        $this->assertK3ResponseRole($response, $role);

        return $response;
    }

    /**
     * Read-only dedicated K3 status used to prove cleanup after a lost release
     * response. The status route has no request/result fields; its exact schema
     * and content-free response are validated by the shared request boundary.
     *
     * @return array<string,mixed>
     */
    public function statusK3(): array
    {
        $policy = $this->mhc08Policy();
        if (($policy['target']['requires_explicit_target'] ?? false) !== true
            || ($policy['target']['target_id'] ?? null) !== 'kimi_k3_87') {
            throw new RuntimeException('MHC-08 K3 target is not explicitly admitted.');
        }

        return $this->request('get', '/v1/k3/status', [], $this->policies->required());
    }

    /** @return array<string,mixed> */
    public function activateK3(string $role, string $requestId, string $leaseId, ?array $candidatePolicy = null): array
    {
        $policy = $candidatePolicy ?? $this->mhc08Policy();
        $this->assertK3Role($role, $policy, true, $candidatePolicy !== null);

        $response = $this->request('post', '/v1/k3/activate', [
            'target' => $policy['target']['target_id'],
            'role' => $role,
            'request_id' => $this->requestId($requestId),
            'lease_id' => $this->leaseId($leaseId),
            'execute' => true,
        ], $this->policies->required());
        $this->assertK3ResponseRole($response, $role);

        return $response;
    }

    /** @return array<string,mixed> */
    public function releaseK3(string $role, string $requestId, string $leaseId, ?array $candidatePolicy = null): array
    {
        $policy = $candidatePolicy ?? $this->mhc08Policy();
        $this->assertK3Role($role, $policy, true, $candidatePolicy !== null);

        $response = $this->request('post', '/v1/k3/release', [
            'target' => $policy['target']['target_id'],
            'role' => $role,
            'request_id' => $this->requestId($requestId),
            'lease_id' => $this->leaseId($leaseId),
            'execute' => true,
        ], $this->policies->required());
        $this->assertK3ResponseRole($response, $role);

        return $response;
    }

    /** @return array<string,mixed> */
    public function heartbeatK3(string $role, string $requestId, string $leaseId, ?array $candidatePolicy = null): array
    {
        $policy = $candidatePolicy ?? $this->mhc08Policy();
        $this->assertK3Role($role, $policy, true, $candidatePolicy !== null);

        $response = $this->request('post', '/v1/k3/heartbeat', [
            'target' => $policy['target']['target_id'],
            'role' => $role,
            'request_id' => $this->requestId($requestId),
            'lease_id' => $this->leaseId($leaseId),
            'execute' => true,
        ], $this->policies->required());
        $this->assertK3ResponseRole($response, $role);

        return $response;
    }

    /** @return array<string,mixed> */
    public function recoverK3(
        string $role,
        string $requestId,
        string $ownerRequestId,
        string $leaseId,
        ?array $candidatePolicy = null,
    ): array {
        $policy = $candidatePolicy ?? $this->mhc08Policy();
        $this->assertK3Role($role, $policy, true, $candidatePolicy !== null);

        $response = $this->request('post', '/v1/k3/recover', [
            'target' => $policy['target']['target_id'],
            'role' => $role,
            'request_id' => $this->requestId($requestId),
            'owner_request_id' => $this->requestId($ownerRequestId),
            'lease_id' => $this->leaseId($leaseId),
            'execute' => true,
        ], $this->policies->required());
        $this->assertK3ResponseRole($response, $role);

        return $response;
    }

    /** @return array<string,mixed> */
    public function release(string $requestId, string $leaseId): array
    {
        $policy = $this->policies->required();
        if (! $policy['enabled']) {
            throw new RuntimeException('Model-host controller is disabled by PLOS policy.');
        }

        return $this->request('post', '/v1/release', [
            'request_id' => $this->requestId($requestId),
            'lease_id' => $this->leaseId($leaseId),
            'execute' => true,
        ], $policy);
    }

    /** @return array<string,mixed> */
    public function heartbeat(string $requestId, string $leaseId, ?int $timeoutSeconds = null, ?array $dispatch = null): array
    {
        if ($timeoutSeconds !== null && $timeoutSeconds < 1) {
            throw new RuntimeException('Heartbeat transport timeout must be positive.');
        }
        $policy = $this->policies->required();
        if (! $policy['enabled']) {
            throw new RuntimeException('Model-host controller is disabled by PLOS policy.');
        }

        $payload = ['request_id' => $this->requestId($requestId),
            'lease_id' => $this->leaseId($leaseId), 'execute' => true];
        if ($dispatch !== null) {
            $keys = array_keys($dispatch);
            sort($keys);
            if ($keys !== ['capacity_binding', 'lease_id', 'original_request_id', 'phase', 'schema', 'target']
                || ($dispatch['schema'] ?? null) !== 'plos.model_host_controller.dispatch.v1'
                || ($dispatch['phase'] ?? null) !== 'in_flight'
                || ($dispatch['lease_id'] ?? null) !== $payload['lease_id']
                || ! is_array($dispatch['capacity_binding'] ?? null)
                || ($dispatch['capacity_binding']['host_id'] ?? null) !== $policy['host_id']
                || ($dispatch['capacity_binding']['target'] ?? null) !== ($dispatch['target'] ?? null)) {
                throw new RuntimeException('Heartbeat dispatch declaration is invalid.');
            }
            $this->requestId($dispatch['original_request_id']);
            $this->assertTarget($dispatch['target'], $policy, false);
            if (($policy['targets'][$dispatch['target']]['engine'] ?? null) !== 'ollama') {
                throw new RuntimeException('Heartbeat loading declaration requires an exact Ollama target.');
            }
            $payload['dispatch'] = $dispatch;
        }

        $response = $this->request('post', '/v1/heartbeat', $payload, $policy, $timeoutSeconds);
        if ($dispatch !== null && (! is_array($response['dispatch'] ?? null)
            || ModelHostControllerResourcePolicyService::requestContractIdentity($response['dispatch'])
                !== ModelHostControllerResourcePolicyService::requestContractIdentity($dispatch))) {
            throw new RuntimeException('Heartbeat did not attest the exact owned dispatch declaration.');
        }

        return $response;
    }

    /** @return array<string,mixed> */
    public function recover(string $requestId): array
    {
        $policy = $this->policies->required();
        if (! $policy['enabled']) {
            throw new RuntimeException('Model-host controller is disabled by PLOS policy.');
        }

        return $this->request('post', '/v1/recover', [
            'request_id' => $this->requestId($requestId),
            'execute' => true,
        ], $policy);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $policy @return array<string,mixed> */
    private function request(string $method, string $path, array $payload, array $policy, ?int $timeoutSeconds = null): array
    {
        if (! $policy['enabled']) {
            throw new RuntimeException('Model-host controller is disabled by PLOS policy.');
        }
        if ($this->requestTransport !== null) {
            $body = ($this->requestTransport)($method, $path, $payload, $policy, $timeoutSeconds);
        } else {
            $body = $this->httpResponse($method, $path, $payload, $timeoutSeconds);
        }
        $expectedSchema = match ($path) {
            '/v1/status' => 'plos.model_host_controller.status.v1',
            '/v1/receipt' => 'plos.model_host_controller.receipt_lookup.v1',
            '/v1/technical-fence' => 'plos.model_host_controller.technical_fence.v1',
            '/v1/target/status' => 'plos.model_host_controller.target_status.v1',
            '/v1/plan' => 'plos.model_host_controller.plan.v1',
            '/v1/activate' => 'plos.model_host_controller.activation.v1',
            '/v1/heartbeat' => 'plos.model_host_controller.heartbeat.v1',
            '/v1/recover' => 'plos.model_host_controller.recovery.v1',
            '/v1/release' => 'plos.model_host_controller.release.v1',
            '/v1/k3/plan' => 'plos.model_host_controller.k3.plan.v1',
            '/v1/k3/status' => 'plos.model_host_controller.k3.status.v1',
            '/v1/k3/activate' => 'plos.model_host_controller.k3.activation.v1',
            '/v1/k3/release' => 'plos.model_host_controller.k3.release.v1',
            '/v1/k3/heartbeat' => 'plos.model_host_controller.k3.heartbeat.v1',
            '/v1/k3/recover' => 'plos.model_host_controller.k3.recovery.v1',
            default => throw new RuntimeException('Model-host controller route is not allowlisted.'),
        };
        $resultValid = $path === '/v1/k3/status'
            ? ! array_key_exists('result', (array) $body)
            : preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', (string) ($body['result'] ?? '')) === 1;
        if (! is_array($body)
            || ($body['schema'] ?? null) !== $expectedSchema
            || ($body['host_id'] ?? null) !== $policy['host_id']
            || ($body['contains_prompt_or_response_content'] ?? null) !== false
            || ! $resultValid
            || (isset($payload['request_id']) && ($body['request_id'] ?? null) !== $payload['request_id'])
            || (isset($payload['target']) && ($body['target'] ?? null) !== $payload['target'])
            || (isset($payload['role']) && isset($body['role']) && ($body['role'] ?? null) !== $payload['role'])
            || (isset($payload['lease_id']) && ($body['lease_id'] ?? null) !== $payload['lease_id'])) {
            throw new RuntimeException('Model-host controller returned an invalid or unsafe response.');
        }

        return $body;
    }

    private function httpResponse(string $method, string $path, array $payload, ?int $timeoutSeconds = null): mixed
    {
        $url = $this->baseUrl().$path;
        $mutation = in_array($path, [
            '/v1/activate', '/v1/heartbeat', '/v1/recover', '/v1/release', '/v1/technical-fence',
            '/v1/k3/activate', '/v1/k3/release', '/v1/k3/heartbeat', '/v1/k3/recover',
        ], true);
        $k3Mutation = in_array($path, [
            '/v1/k3/activate', '/v1/k3/release', '/v1/k3/recover',
        ], true);
        $request = $this->authorizedHttp($mutation, $k3Mutation);
        if ($timeoutSeconds !== null) {
            $request->timeout($timeoutSeconds)->connectTimeout(min($timeoutSeconds, (int) config('model_host_controller.connect_timeout_seconds', 5)));
        }

        $started = microtime(true);
        try {
            $response = $method === 'get'
                ? $request->get($url)
                : $request->post($url, $payload);
        } catch (\Throwable) {
            throw new InferenceAdmissionException('Model-host controller transport is unavailable.', controllerFailure: [
                'operation' => substr($path, strlen('/v1/')), 'reason_code' => 'controller_transport_unavailable',
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        }
        if (! $response->successful()) {
            $diagnostics = ['operation' => substr($path, strlen('/v1/')), 'http_status' => $response->status(),
                'reason_code' => 'controller_request_rejected', 'duration_ms' => (int) ((microtime(true) - $started) * 1000)];
            // Never retain the raw controller body, message, stack, auth, or unknown identifiers.
            if (strlen($response->body()) <= 4096) {
                $failure = $response->json();
                if (is_array($failure) && ($failure['schema'] ?? null) === 'plos.model_host_controller.error.v1'
                    && ($failure['host_id'] ?? null) === $this->policies->required()['host_id']
                    && ($failure['contains_prompt_or_response_content'] ?? null) === false
                    && ($failure['result'] ?? null) === 'rejected') {
                    $safe = InferenceAdmissionException::sanitizeControllerFailure([
                        'reason_code' => $failure['reason_code'] ?? null,
                        'stage' => $failure['stage'] ?? $failure['diagnostic_stage'] ?? null,
                        'exception_kind' => $failure['exception_kind'] ?? null,
                        'source_line' => $failure['source_line'] ?? null,
                        'failed_checks' => $failure['failed_checks'] ?? null,
                    ]);
                    $diagnostics = array_replace($diagnostics, $safe);
                }
            }
            throw new InferenceAdmissionException('Model-host controller rejected the request.', controllerFailure: $diagnostics);
        }
        if (strlen($response->body()) > 65536) {
            throw new RuntimeException('Model-host controller response exceeded the safety limit.');
        }

        return $response->json();
    }

    /** @param array<string,mixed> $policy */
    private function assertTarget(string $target, array $policy, bool $activation): void
    {
        if (! $policy['enabled']) {
            throw new RuntimeException('Model-host controller is disabled by PLOS policy.');
        }
        $targetPolicy = $policy['targets'][$target] ?? null;
        if (! is_array($targetPolicy)) {
            throw new RuntimeException('Requested model-host target is not allowlisted.');
        }
        if ($activation && ! ($targetPolicy['activation_allowed'] ?? false)) {
            throw new RuntimeException('Requested model-host target is not activation-admitted.');
        }
    }

    /** @return array<string,mixed> */
    private function mhc08Policy(): array
    {
        return ($this->mhc08Policies ?? app(ModelHostControllerMhc08K3PolicyService::class))->required();
    }

    /** @param array<string,mixed> $policy */
    private function assertK3Role(string $role, array $policy, bool $activation, bool $candidate = false): void
    {
        $role = trim($role);
        if ($candidate) {
            if (($policy['revision'] ?? null) === ModelHostControllerMhc08K3LifecycleService::CANDIDATE_REVISION) {
                $inactive = [
                    ...$policy,
                    'revision' => ModelHostControllerMhc08K3PolicyService::INACTIVE_REVISION,
                    'enabled' => false,
                ];
                if (($policy['enabled'] ?? null) !== true
                    || $this->mhc08PoliciesOrNew()->normalize($inactive) !== $inactive
                    || $this->mhc08Policy() !== $inactive
                    || ! in_array($role, $inactive['roles'], true)) {
                    throw new RuntimeException('MHC-08 K3 candidate policy is invalid.');
                }
            } elseif (($policy['revision'] ?? null) === ModelHostControllerMhc08K3PolicyService::ACCEPTANCE_REVISION) {
                if ($this->mhc08Policy() !== $policy) {
                    throw new RuntimeException('MHC-08 K3 acceptance policy is not the persisted exact policy.');
                }
                $this->mhc08PoliciesOrNew()->assertAcceptanceRole($role, $policy);
            } else {
                throw new RuntimeException('MHC-08 K3 policy override is invalid.');
            }
        } else {
            $this->mhc08PoliciesOrNew()->assertExplicitRole($role, $policy);
        }
        if (($policy['target']['requires_explicit_target'] ?? false) !== true
            || ($policy['target']['target_id'] ?? null) !== 'kimi_k3_87') {
            throw new RuntimeException('MHC-08 K3 target is not explicitly admitted.');
        }
        if ($activation && ($policy['enabled'] ?? false) !== true) {
            throw new RuntimeException('MHC-08 K3 explicit activation is disabled by PLOS policy.');
        }
    }

    private function mhc08PoliciesOrNew(): ModelHostControllerMhc08K3PolicyService
    {
        return $this->mhc08Policies ?? app(ModelHostControllerMhc08K3PolicyService::class);
    }

    /** @param array<string,mixed> $response */
    private function assertK3ResponseRole(array $response, string $role): void
    {
        if (! array_key_exists('role', $response) || $response['role'] !== trim($role)) {
            throw new RuntimeException('MHC-08 K3 controller response did not echo the exact role.');
        }
    }

    private function requestId(string $requestId): string
    {
        $requestId = trim($requestId);
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}\z/', $requestId) !== 1) {
            throw new RuntimeException('Model-host request identity is invalid.');
        }

        return $requestId;
    }

    private function leaseId(string $leaseId): string
    {
        $leaseId = trim($leaseId);
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}\z/', $leaseId) !== 1) {
            throw new RuntimeException('Model-host lease identity is invalid.');
        }

        return $leaseId;
    }

    private function authorizedHttp(bool $mutation = false, bool $k3Mutation = false): PendingRequest
    {
        $timeoutConfig = $k3Mutation
            ? 'model_host_controller.k3_mutation_timeout_seconds'
            : ($mutation ? 'model_host_controller.mutation_timeout_seconds' : 'model_host_controller.request_timeout_seconds');

        return Http::withToken($this->token())
            ->acceptJson()
            ->asJson()
            ->withoutRedirecting()
            ->connectTimeout((int) config('model_host_controller.connect_timeout_seconds', 5))
            ->timeout((int) config($timeoutConfig, $k3Mutation ? 2400 : ($mutation ? 600 : 30)));
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) config('model_host_controller.url', ''), '/');
        $parts = parse_url($url);
        $allowedHosts = (array) config('model_host_controller.allowed_hosts', []);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'http'
            || ! in_array($parts['host'] ?? null, $allowedHosts, true)
            || ! isset($parts['port'])
            || (int) $parts['port'] !== (int) config('model_host_controller.allowed_port', 11440)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('Model-host controller URL is outside the private allowlist.');
        }

        return $url;
    }

    private function token(): string
    {
        $path = (string) config('model_host_controller.token_file', '');
        if ($path === '' || is_link($path) || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Model-host controller token file is unavailable.');
        }
        $mode = fileperms($path);
        $owner = fileowner($path);
        $group = filegroup($path);
        if ($mode === false || $owner === false || $group === false) {
            throw new RuntimeException('Model-host controller token file permissions are unsafe.');
        }
        $permissions = $mode & 0777;
        if (function_exists('posix_geteuid')) {
            $effectiveUser = posix_geteuid();
            $effectiveGroups = function_exists('posix_getgroups') ? posix_getgroups() : [];
            if (function_exists('posix_getegid')) {
                $effectiveGroups[] = posix_getegid();
            }
            $effectiveGroups = array_values(array_unique(array_map('intval', $effectiveGroups)));
            $ownerOnly = $owner === $effectiveUser && $permissions === 0600;
            $rootOwnedSharedGroup = $owner === 0
                && $permissions === 0640
                && in_array($group, $effectiveGroups, true);
            if (! $ownerOnly && ! $rootOwnedSharedGroup) {
                throw new RuntimeException('Model-host controller token file permissions are unsafe.');
            }
        } elseif (($permissions & 0077) !== 0) {
            throw new RuntimeException('Model-host controller token file permissions are unsafe.');
        }
        $token = trim((string) file_get_contents($path));
        if (strlen($token) < 32 || strlen($token) > 512) {
            throw new RuntimeException('Model-host controller token is invalid.');
        }

        return $token;
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private function disabledStatus(array $policy): array
    {
        return [
            'schema' => 'plos.model_host_controller.status.v1',
            'host_id' => $policy['host_id'],
            'configured' => true,
            'enabled' => false,
            'automatic_activation_enabled' => false,
            'execution_mode' => $policy['execution_mode'],
            'default_target' => $policy['default_target'],
            'targets' => array_keys($policy['targets']),
            'contains_prompt_or_response_content' => false,
            'result' => 'disabled',
        ];
    }
}
