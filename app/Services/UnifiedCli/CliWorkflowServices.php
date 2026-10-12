<?php

namespace App\Services\UnifiedCli;

use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use RuntimeException;
use Throwable;

/** Table-selected workflow transport into the existing PLOS MCP router API. */
final class CliWorkflowServices
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly CliArtifactStore $artifacts,
        private readonly CliLocalBindings $bindings,
        private readonly ClientInterface $http,
    ) {}

    public static function validate(array $policy): void
    {
        if (array_diff(array_keys($policy), ['servers', 'targets']) !== []
            || ! is_array($policy['servers'] ?? null) || ! is_array($policy['targets'] ?? null)) {
            throw new RuntimeException('Workflow services require server rules and target definitions.');
        }
        foreach ($policy['servers'] as $server => $rule) {
            if (! is_string($server) || ! preg_match('/^[a-z][a-z0-9_-]*$/D', $server)
                || ! is_array($rule) || array_diff(array_keys($rule), ['exclude_tools', 'tree_scoped']) !== []
                || ! is_bool($rule['tree_scoped'] ?? null) || ! is_array($rule['exclude_tools'] ?? null)
                || ! array_is_list($rule['exclude_tools'])) {
                throw new RuntimeException('Workflow server rules are invalid.');
            }
            foreach ($rule['exclude_tools'] as $tool) {
                if (! is_string($tool) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $tool)) {
                    throw new RuntimeException('Workflow tool exclusions require exact method names.');
                }
            }
        }
        foreach ($policy['targets'] as $target => $definition) {
            if (! is_string($target) || ! preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $target)
                || ! is_array($definition) || array_diff(array_keys($definition), ['driver', 'base_url', 'token_reference']) !== []
                || ! in_array($definition['driver'] ?? null, ['local', 'plos_mcp_api'], true)) {
                throw new RuntimeException('Workflow target definitions are invalid.');
            }
            if ($definition['driver'] === 'local') {
                if (count($definition) !== 1) {
                    throw new RuntimeException('Local workflow targets cannot carry API credentials or endpoints.');
                }
            } else {
                if (! is_string($definition['base_url'] ?? null)) {
                    throw new RuntimeException('Workflow API target requires a base URL.');
                }
                (new CliNetworkBoundary)->requestOptions($definition['base_url'], 'online');
                if (isset($definition['token_reference']) && (! is_string($definition['token_reference'])
                    || ! preg_match('/^host:[A-Za-z0-9][A-Za-z0-9_-]*:[A-Za-z_][A-Za-z0-9_]*$/D', $definition['token_reference']))) {
                    throw new RuntimeException('Workflow credentials require an existing local reference.');
                }
            }
        }
    }

    /** Inspect metadata only. An explicit local driver uses existing local adapters. */
    public function context(string $server, string $tool): ?array
    {
        $configuration = new CliConfiguration($this->store);
        $session = $this->store->session($this->sessionId);
        $values = $configuration->effective($session)['values'];
        $policy = $values['cli.workflow_services'] ?? null;
        if ($policy === null) {
            return null;
        }
        if (! is_array($policy)) {
            throw new RuntimeException('Workflow service configuration is invalid.');
        }
        self::validate($policy);
        $rule = $policy['servers'][$server] ?? null;
        if ($rule === null || in_array($tool, $rule['exclude_tools'], true)) {
            return null;
        }
        $selection = $configuration->operationSelection($session, workflow: true);
        $definition = $policy['targets'][$selection['target'] ?? ''] ?? null;
        if ($definition === null) {
            throw new RuntimeException('Selected workflow target is unconfigured. Inspect /target; choose a configured target explicitly. No alternate target was tried.');
        }
        if ($definition['driver'] === 'local') {
            return null;
        }
        if ($rule['tree_scoped'] && $selection['tree_id'] === null) {
            throw new RuntimeException('Select /tree before this genealogy workflow. Inspect /target to confirm the data host.');
        }

        $identity = [$selection['target'], $rule, $definition, CliWorkflowExecutionScope::fromValues($values)];
        $tlsBinding = (new CliTlsPolicy($values['cli.tls_policy'] ?? []))->routeBinding(
            $definition['base_url'], $values['cli.network'] ?? 'online');
        // Preserve existing prepared-action identity when no scoped TLS applies.
        if ($tlsBinding !== []) {
            $identity[] = $tlsBinding;
        }

        return ['operation_target' => $selection['target'], 'tree_id' => $selection['tree_id'],
            'workflow_endpoint' => $definition['base_url'],
            'workflow_service_sha256' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR))];
    }

    public function inspectTarget(): ?array
    {
        $configuration = new CliConfiguration($this->store);
        $session = $this->store->session($this->sessionId);
        $policy = $configuration->effective($session)['values']['cli.workflow_services'] ?? null;
        if ($policy === null) {
            return null;
        }
        if (! is_array($policy)) {
            throw new RuntimeException('Workflow service configuration is invalid.');
        }
        self::validate($policy);
        $selection = $configuration->operationSelection($session, workflow: true);
        $definition = $policy['targets'][$selection['target'] ?? ''] ?? null;
        if (($definition['driver'] ?? null) !== 'plos_mcp_api') {
            return null;
        }

        return ['configured' => true, 'connection_checked' => false, 'credentials_checked' => false,
            'driver' => 'plos_mcp_api', 'endpoint' => $definition['base_url'],
            'context' => ['operation_target' => $selection['target'], 'tree_id' => $selection['tree_id']]];
    }

    public function call(string $server, string $tool, array $arguments, array $context, ?Closure $keeper = null): array
    {
        $expected = $this->context($server, $tool);
        if ($expected === null) {
            throw new RuntimeException('Workflow execution differs from its prepared target or service binding.');
        }
        foreach ($expected as $key => $value) {
            if (($context[$key] ?? null) !== $value) {
                throw new RuntimeException('Workflow execution differs from its prepared target or service binding.');
            }
        }
        $session = $this->store->session($this->sessionId);
        $values = (new CliConfiguration($this->store))->effective($session)['values'];
        $definition = $values['cli.workflow_services']['targets'][$expected['operation_target']];
        if ($values['cli.workflow_services']['servers'][$server]['tree_scoped']) {
            if (isset($arguments['tree_id']) && $arguments['tree_id'] !== $expected['tree_id']) {
                throw new RuntimeException('Workflow arguments cannot change the selected genealogy tree.');
            }
            $arguments['tree_id'] = $expected['tree_id'];
        }
        $timeout = $values['cli.mcp_timeout_ms'] ?? 30000;
        if (! is_int($timeout) || $timeout < 50 || $timeout > 3600000) {
            throw new RuntimeException('Workflow timeout must be a valid millisecond value.');
        }
        $options = (new CliNetworkBoundary($values['cli.host_addresses'] ?? [], $values['cli.tls_policy'] ?? []))->requestOptions(
            $expected['workflow_endpoint'], $values['cli.network'] ?? 'online', $keeper !== null);
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($keeper !== null) {
            try {
                $keeper();
            } catch (Throwable) {
                throw new RuntimeException('Controller ownership check failed before the workflow request. Review /host lease; no workflow request was sent.');
            }
        }
        if (isset($definition['token_reference'])) {
            $token = trim($this->bindings->resolve($definition['token_reference']));
            if ($token === '' || preg_match('/[\x00-\x20\x7f]/', $token)) {
                throw new RuntimeException('Workflow credential is unavailable or invalid.');
            }
            $headers['Authorization'] = 'Bearer '.$token;
        }
        $sink = Utils::streamFor(Utils::tryFopen('php://temp', 'w+b'));
        $request = json_encode(['server' => $server, 'tool' => $tool, 'params' => (object) $arguments,
            'execution_scope' => CliWorkflowExecutionScope::fromValues($values)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $leaseFailed = false;
        if ($keeper !== null) {
            $options['curl'][CURLOPT_NOPROGRESS] = false;
            $options['curl'][CURLOPT_XFERINFOFUNCTION] = function () use ($keeper, &$leaseFailed): int {
                try {
                    $keeper();

                    return 0;
                } catch (Throwable) {
                    $leaseFailed = true;

                    return 1;
                }
            };
        }
        $status = null;
        try {
            if ($this->context($server, $tool) !== $expected) {
                throw new RuntimeException('Workflow target changed before dispatch.');
            }
            $response = $this->http->request('POST', rtrim($expected['workflow_endpoint'], '/').'/api/mcp/call-direct-scoped',
                $options + ['headers' => $headers, 'body' => $request, 'sink' => $sink,
                    'connect_timeout' => 5, 'timeout' => $timeout / 1000, 'http_errors' => false]);
            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();
            $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if ($status !== 200 || ($body['success'] ?? null) !== true || ! array_key_exists('response', $body)
                || ($body['execution_scope_schema'] ?? null) !== CliWorkflowExecutionScope::SCHEMA) {
                throw new RuntimeException('The selected workflow returned no verified success response.');
            }

            $result = is_array($body['response']) ? $body['response'] : ['success' => true, 'result' => $body['response']];

            return $result + array_intersect_key($body, array_flip(['compute_executions', 'codex_executions']));
        } catch (Throwable) {
            throw new CliToolOutcomeUnknown($leaseFailed
                ? 'Workflow interrupted after controller ownership was lost. Inspect the selected target and retained output before retrying.'
                : ($status === 404
                    ? 'The selected PLOS target does not provide the CLI settings endpoint. Update it through the normal release process; no legacy endpoint or alternate target was tried.'
                    : 'Selected PLOS workflow did not return a verified result. Inspect the target and retained output before retrying; no alternate target was tried.'));
        } finally {
            try {
                $sink->rewind();
                $this->store->recordEvent($this->sessionId, 'workflow_service_io', $expected + [
                    'server' => $server, 'tool' => $tool, 'http_status' => $status,
                    'request' => $this->artifacts->captureBytes($request), 'response' => $this->artifacts->captureBytes($sink->getContents())]);
            } finally {
                $sink->close();
            }
        }
    }
}
