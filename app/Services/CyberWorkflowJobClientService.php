<?php

namespace App\Services;

use App\Services\UnifiedCli\CliLocalBindings;
use App\Services\UnifiedCli\CliNetworkBoundary;
use App\Services\UnifiedCli\CliToolOutcomeUnknown;
use App\Services\UnifiedCli\CliWorkflowExecutionScope;
use Illuminate\Support\Str;
use RuntimeException;

/** Thin client invoked through the existing CLI framework-service approval/skill path. */
final class CyberWorkflowJobClientService
{
    public function submit(string $submission_id, string $execution_intent): array
    {
        return $this->call('POST', '', ['submission_id' => $submission_id, 'execution_intent' => $execution_intent], true);
    }

    public function status(string $handle): array
    {
        return $this->call('GET', $this->path($handle), []);
    }

    public function cancel(string $handle, string $execution_intent): array
    {
        return $this->call('POST', $this->path($handle).'/cancel', ['execution_intent' => $execution_intent]);
    }

    public function refresh(string $handle, string $execution_intent): array
    {
        return $this->call('POST', $this->path($handle).'/refresh', ['execution_intent' => $execution_intent]);
    }

    public function reconcile(string $handle, string $execution_intent): array
    {
        return $this->call('POST', $this->path($handle).'/reconcile', ['execution_intent' => $execution_intent]);
    }

    public function resume(string $handle, string $execution_intent): array
    {
        return $this->call('POST', $this->path($handle).'/resume', ['execution_intent' => $execution_intent], true);
    }

    private function path(string $handle): string
    {
        if (! Str::isUuid($handle)) {
            throw new RuntimeException('Exact durable task handle required.');
        }

        return '/'.$handle;
    }

    private function call(string $method, string $path, array $payload, bool $scoped = false): array
    {
        $settings = app(SystemConfigService::class);
        $policy = $settings->get('cyber.workflow_job_client', []);
        if (! is_array($policy) || ($policy['enabled'] ?? false) !== true) {
            throw new RuntimeException('CYBER job client is not configured.');
        }
        $context = CliWorkflowExecutionScope::current();
        if ($context === null) {
            throw new RuntimeException('Existing CLI execution scope required.');
        }
        $base = $policy['base_url'] ?? '';
        $parts = is_string($base) ? parse_url($base) : false;
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['scheme'] ?? '') !== 'https' && ! (($parts['scheme'] ?? '') === 'http' && in_array($parts['host'] ?? '', ['127.0.0.1', '::1'], true)))) {
            throw new RuntimeException('CYBER job API requires HTTPS, except an explicit loopback proof.');
        }
        $url = rtrim($base, '/').'/api/v1/cyber/workflow-jobs'.$path;
        $options = (new CliNetworkBoundary($context['settings']['network']['host_addresses'], $settings->get('cli.tls_policy', [])))->requestOptions($url, $context['settings']['network']['mode']);
        $bindings = CliLocalBindings::fromFile($policy['bindings_path'] ?? '');
        $token = trim($bindings->resolve($policy['token_reference'] ?? ''));
        if ($token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new RuntimeException('Private CYBER token reference is invalid.');
        }
        if ($scoped) {
            $payload['execution_scope'] = $context['settings'];
        }
        $options = array_replace($options, ['headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token], 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 15, 'connect_timeout' => 3]);
        if ($method !== 'GET') {
            $options['json'] = $payload;
        }
        try {
            $response = PipelineHttpClientFactory::make()->request($method, $url, $options);
        } catch (\Throwable) {
            throw new CliToolOutcomeUnknown('CYBER request outcome is unknown; reconnect using the original submission handle. No automatic retry.');
        }
        if (in_array($response->getStatusCode(), [401, 403], true)) {
            // A received authorization denial is a known logical failure. Keep
            // response bodies and credentials out of the returned tool result.
            return ['success' => false, 'error' => 'CYBER API authorization denied. Check the configured token, its scopes and owner.',
                'status_code' => $response->getStatusCode(), 'automatic_retry_allowed' => false];
        }
        $data = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 500 || ! is_array($data)) {
            throw new CliToolOutcomeUnknown('CYBER API outcome is unknown. Inspect the original handle; no automatic replay.');
        }
        if (! in_array($response->getStatusCode(), [200, 202], true) || ($data['success'] ?? null) !== true || ! is_array($data['data'] ?? null)) {
            throw new RuntimeException('CYBER API did not admit this request. Check exact token scope, owner and original handle.');
        }
        if ($scoped && ($data['execution_scope_schema'] ?? null) !== CliWorkflowExecutionScope::SCHEMA) {
            throw new CliToolOutcomeUnknown('CYBER response did not verify the execution scope. Inspect the original handle.');
        }

        return $data['data'];
    }
}
