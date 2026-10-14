<?php

namespace App\Services\UnifiedCli;

use App\Services\ModelHostControllerClient;
use App\Services\ModelHostControllerPolicyService;
use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

/** Read the existing controller directly with session policy and local credentials. */
final class CliModelHostStatus
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly CliLocalBindings $bindings,
        private readonly CliArtifactStore $artifacts,
        private readonly ClientInterface $http,
        private readonly array $transport,
    ) {}

    public function status(?string $target = null): array
    {
        $values = (new CliConfiguration($this->store))->effective($this->store->session($this->sessionId))['values'];

        return ['controller' => $target === null ? $this->client()->status() : $this->client()->targetStatus($target), 'controller_policy' => $this->policy(),
            'controller_endpoint' => $values['cli.controller_url'] ?? $this->transport['base_url'] ?? null, 'runtime_admission_checked' => false,
            'model_requests' => 0, 'lifecycle_actions' => 0];
    }

    public function policy(): array
    {
        return (new ModelHostControllerPolicyService(fn () => (new CliSystemConfigService($this->store, $this->sessionId))->get('ai.model_host_controller_policy', []),
            fn () => (new CliSystemConfigService($this->store, $this->sessionId))->llmRoutingValue('controller_transport', 'expected_host_id')))->required();
    }

    /** Use the existing table policy from the pinned offline registry, without enabling selection. */
    public function renewalTiming(): array
    {
        $policy = (new CliSystemConfigService($this->store, $this->sessionId))
            ->get('ai.model_host_controller_automatic_selection_policy', []);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== 'plos.model_host_controller.automatic_selection_policy.v1'
            || ! is_string($policy['revision'] ?? null) || trim($policy['revision']) === '') {
            throw new RuntimeException('Controller renewal timing requires the registered table policy.');
        }
        foreach (['heartbeat_interval_seconds', 'heartbeat_timeout_seconds'] as $key) {
            if (! is_int($policy[$key] ?? null) || $policy[$key] < 1) {
                throw new RuntimeException('Controller renewal timing requires positive table-owned seconds.');
            }
        }

        return ['revision' => $policy['revision'], 'heartbeat_interval_seconds' => $policy['heartbeat_interval_seconds'],
            'heartbeat_timeout_seconds' => $policy['heartbeat_timeout_seconds']];
    }

    public function identity(): string
    {
        $values = (new CliConfiguration($this->store))->effective($this->store->session($this->sessionId))['values'];
        $endpoint = $values['cli.controller_url'] ?? $this->transport['base_url'] ?? null;
        $host = is_string($endpoint) ? strtolower(trim((string) parse_url($endpoint, PHP_URL_HOST), '[]')) : '';

        $identity = [$endpoint, $this->transport['token_reference'] ?? null, $this->policy()['host_id']];
        if (isset($values['cli.host_addresses'][$host])) {
            $identity[] = ['pinned_address' => $values['cli.host_addresses'][$host]];
        }

        $trust = (new CliTlsPolicy($values['cli.tls_policy'] ?? []))->routeBinding($endpoint, $values['cli.network'] ?? 'offline');
        if ($trust !== []) {
            $identity[] = $trust;
        }

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    /** Mutations require a caller that verifies a durable, exact local intent. */
    public function client(?Closure $beforeMutation = null): ModelHostControllerClient
    {
        return new ModelHostControllerClient(new ModelHostControllerPolicyService($this->policy(...),
            fn () => (new CliSystemConfigService($this->store, $this->sessionId))->llmRoutingValue('controller_transport', 'expected_host_id')),
            requestTransport: function ($method, $path, $payload, $policy, $timeoutSeconds = null) use ($beforeMutation): array {
                $read = ($method === 'get' && $path === '/v1/status' && $payload === [])
                    || ($method === 'post' && $path === '/v1/target/status' && array_keys($payload) === ['target']);
                if ($method === 'post' && $path === '/v1/receipt') {
                    $lease = $this->store->session($this->sessionId)['host_lease'] ?? [];
                    if (($lease['phase'] ?? null) !== 'activate_pending'
                        || ($lease['controller_identity'] ?? null) !== $this->identity()
                        || $payload !== ['operation' => 'activate', 'target' => $lease['target'],
                            'request_id' => $lease['operation_id'], 'lease_id' => $lease['lease_id']]) {
                        throw new RuntimeException('Receipt lookup requires this exact pending activation.');
                    }
                    $read = true;
                }
                $plan = $method === 'post' && $path === '/v1/plan';
                $mutation = $method === 'post' && in_array($path, ['/v1/activate', '/v1/heartbeat', '/v1/release'], true);
                if (! $read && ! $plan && (! $mutation || $beforeMutation === null)) {
                    throw new RuntimeException('This controller operation requires exact local lifecycle ownership.');
                }
                $transport = $this->transport;
                if (($transport['schema'] ?? null) !== 'plos.cli.controller-binding.v1'
                    || array_diff(array_keys($transport), ['schema', 'base_url', 'token_reference']) !== []
                    || ! is_string($transport['base_url'] ?? null)
                    || ! is_string($transport['token_reference'] ?? null)
                    || ! preg_match('/^host:[A-Za-z0-9][A-Za-z0-9_-]*:[A-Za-z_][A-Za-z0-9_]*$/D', $transport['token_reference'])) {
                    throw new RuntimeException('Local controller endpoint and credential reference are unavailable.');
                }
                $session = $this->store->session($this->sessionId);
                $values = (new CliConfiguration($this->store))->effective($session)['values'];
                $network = $values['cli.network'] ?? 'offline';
                $endpoint = $values['cli.controller_url'] ?? $transport['base_url'];
                $readTimeout = $values['cli.controller_request_timeout_seconds'] ?? 30;
                if (! is_int($readTimeout) || $readTimeout < 1 || $readTimeout > 600) {
                    throw new RuntimeException('Controller request timeout must be an integer from 1 to 600 seconds.');
                }
                // Activation must outlast the selected target's bounded startup
                // policy. Allow one read-policy interval for controller checks
                // and the reply, retaining the previous minimum for fast targets.
                // Renewal runs inside model I/O and keeps its short read timeout.
                // A lost mutation reply remains pending and is never replayed.
                $timeout = $mutation && $path !== '/v1/heartbeat' ? 600 : $readTimeout;
                if ($path === '/v1/activate') {
                    $timeout = max($timeout, $policy['targets'][$payload['target']]['startup_timeout_seconds']
                        + ($policy['targets'][$payload['target']]['verification_timeout_seconds'] ?? 0) + $readTimeout);
                }
                if ($path === '/v1/heartbeat' && $timeoutSeconds !== null) {
                    $timeout = $timeoutSeconds;
                }
                $options = (new CliNetworkBoundary($values['cli.host_addresses'] ?? [], $values['cli.tls_policy'] ?? []))->requestOptions($endpoint, $network);
                // Match the existing controller token-file convention; this is
                // credential parsing and never touches a model prompt or result.
                $token = trim($this->bindings->resolve($transport['token_reference']));
                if (strlen($token) < 32 || strlen($token) > 512 || preg_match('/[\x00-\x20\x7f]/', $token)) {
                    throw new RuntimeException('Local controller credential has an invalid format.');
                }
                $started = hrtime(true);
                $rejection = null;
                try {
                    if ($mutation && $beforeMutation($method, $path, $payload, $policy) !== true) {
                        throw new RuntimeException('Controller mutation has no exact durable intent.');
                    }
                    $response = $this->http->request(strtoupper($method), rtrim($endpoint, '/').$path, $options + [
                        'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'],
                        'connect_timeout' => min(5, $timeout), 'timeout' => $timeout, 'http_errors' => false,
                    ] + ($method === 'post' ? ['json' => $payload] : []));
                    $raw = (string) $response->getBody();
                    $artifact = $this->artifacts->captureBytes($raw);
                    $this->store->recordEvent($this->sessionId, $read ? 'controller_status' : 'controller_operation', ['host_id' => $policy['host_id'], 'path' => $path,
                        'http_status' => $response->getStatusCode(), 'elapsed_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                        'original_response' => $artifact]);
                    if ($response->getStatusCode() !== 200 || strlen($raw) > 65536) {
                        if ($response->getStatusCode() === 400 && strlen($raw) <= 4096) {
                            $rejection = CliControllerGateFailure::fromResponse($raw);
                        }
                        throw new RuntimeException('Controller status response was refused; complete private evidence is retained.');
                    }
                    $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($body)) {
                        throw new RuntimeException('Controller returned an invalid response.');
                    }

                    return $body;
                } catch (\Throwable $error) {
                    // Retain transport facts without exception text, URLs,
                    // headers, credentials or any prompt/response fragments.
                    $context = $error instanceof RequestException
                        || $error instanceof ConnectException ? $error->getHandlerContext() : [];
                    $this->store->recordEvent($this->sessionId, 'controller_transport_failure', [
                        'host_id' => $policy['host_id'], 'path' => $path,
                        'elapsed_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                        'exception_type' => get_class($error),
                        'curl_errno' => is_int($context['errno'] ?? null) ? $context['errno'] : null,
                        'timeout_seconds' => $timeout,
                        'failed_checks' => $rejection?->failedChecks ?? [],
                    ]);
                    if ($rejection !== null) {
                        throw $rejection;
                    }
                    throw new RuntimeException($mutation ? 'Controller mutation outcome is unverified; automatic replay is prohibited.'
                        : 'Current controller status is unavailable or invalid. No model or lifecycle action was attempted.');
                }
            });
    }
}
