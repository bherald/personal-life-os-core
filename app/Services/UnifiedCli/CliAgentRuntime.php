<?php

namespace App\Services\UnifiedCli;

use App\Services\ModelHostControllerResourcePolicyService;
use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use Throwable;

/**
 * Verify actual model/lease/renderer readiness. Used by qualification and admission.
 * Historical qualification artifacts remain evidence, not an extra permission
 * system. PLOS table policy and the current runtime govern ordinary CLI access.
 */
final class CliAgentRuntime
{
    public static function isColibriEngine(mixed $engine): bool
    {
        return is_string($engine) && preg_match('/^colibri(?:-v[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?)?$/D', $engine) === 1;
    }

    public function __construct(
        private readonly CliStateStore $store,
        private readonly CliLocalBindings $bindings,
        private readonly CliArtifactStore $artifacts,
        private readonly ClientInterface $http,
        private readonly Closure $controllerStatus,
    ) {}

    /** Explicit text-only Colibri role; does not infer permission from missing tools. */
    public static function isManualTextCandidate(array $candidate): bool
    {
        $config = CliSettingValue::decode($candidate['instance']['config'] ?? [], 'json');
        $contract = $config['cli_agent_runtime'] ?? [];

        return self::isColibriEngine($candidate['engine'] ?? null)
            && ($candidate['role'] ?? null) === 'offline_manual'
            && ($candidate['native_tools_configured'] ?? null) === false
            && ($contract['manual_text_only'] ?? null) === true
            && ($contract['native_tools_enabled'] ?? null) === false
            && ($contract['input_modalities'] ?? null) === ['text']
            && ($config['route_policy']['tools_enabled'] ?? null) === false
            && ($config['route_policy']['allowed_model_roles'] ?? null) === ['offline_manual']
            && ($config['route_policy']['require_explicit_instance'] ?? null) === true
            && ($config['route_policy']['allow_generic_fallback'] ?? null) === false;
    }

    /** Validate static execution requirements without starting or contacting a runtime. */
    public static function validateContract(array $candidate): array
    {
        $config = CliSettingValue::decode($candidate['instance']['config'] ?? [], 'json');
        $contract = $config['cli_agent_runtime'] ?? [];
        if (($contract['schema'] ?? null) !== 'plos.cli.agent-runtime.v1'
            || ! self::isColibriEngine($candidate['engine'] ?? null)
            || (($candidate['native_tools_configured'] ?? false) !== true && ! self::isManualTextCandidate($candidate))
            || ($contract['model'] ?? null) !== $candidate['model']) {
            throw new RuntimeException('Selected instance has no native CLI runtime contract.');
        }
        if (array_key_exists('manual_text_only', $contract) && ! self::isManualTextCandidate($candidate)) {
            throw new RuntimeException('Manual text contract does not match the selected tool-free role.');
        }
        foreach (['gateway_sha256', 'engine_sha256'] as $field) {
            if (! is_string($contract[$field] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $contract[$field])) {
                throw new RuntimeException('Qualified runtime identity or evidence reference is missing.');
            }
        }
        foreach (['context_window', 'max_output_tokens', 'max_iterations', 'max_tool_calls'] as $field) {
            if (! is_int($contract[$field] ?? null) || $contract[$field] < 1) {
                throw new RuntimeException('Qualified runtime limits must be explicit positive integers.');
            }
        }
        if ($contract['max_output_tokens'] >= $contract['context_window']
            || ! is_bool($contract['enable_thinking'] ?? null)
            || ($contract['tokenizer']['renderer_sha256'] ?? null) !== $contract['gateway_sha256']) {
            throw new RuntimeException('Qualified context or exact renderer contract is inconsistent.');
        }

        return array_diff_key($contract, array_flip(['qualification_status', 'qualification_sha256']));
    }

    /** Reuse the selected registry and existing profile; no separate CLI qualification ledger. */
    public static function assertCapacityContract(CliStateStore $store, array $session, array $contract): ?array
    {
        if (($contract['runtime_management'] ?? null) === 'provider') {
            return null;
        }
        $values = (new CliConfiguration($store))->effective($session)['values'];
        $key = 'ai.'.ModelHostControllerResourcePolicyService::CONFIG_KEY;
        if (($contract['runtime_management'] ?? null) === 'controller'
            && (! is_array($values[$key] ?? null) || ($values[$key]['enabled'] ?? null) !== true)) {
            throw new RuntimeException('Controller-managed compatible requests require an enabled capacity policy.');
        }
        if (! array_key_exists($key, $values)) {
            return null;
        }
        $raw = $values[$key];
        $host = $values['ai.model_host_controller_policy']['host_id'] ?? '';

        return (new ModelHostControllerResourcePolicyService(fn () => $raw))->assertRequestContract(
            is_string($host) ? $host : '', (string) ($contract['controller_target'] ?? ''), $contract);
    }

    public function admit(array $session, array $candidate, array $action = []): array
    {
        try {
            $configuration = new CliConfiguration($this->store);
            $values = $configuration->effective($session)['values'];
            $trust = (new CliTlsPolicy($values['cli.tls_policy'] ?? []))->routeBinding($candidate['instance']['base_url'], $values['cli.network']);
            $contract = match (true) {
                ($candidate['instance']['instance_type'] ?? null) === 'ollama' => (new CliOllamaRuntimePolicy)->resolve($candidate, $values),
                ! self::isColibriEngine($candidate['engine'] ?? null) && CliProviderRuntimePolicy::supports($candidate['instance']) => (new CliProviderRuntimePolicy)->resolve($candidate, $values),
                default => self::validateContract($candidate),
            };
            $capacity = self::assertCapacityContract($this->store, $session, $contract);
            if (($contract['runtime_management'] ?? null) === 'provider') {
                // A hosted API needs no local process, lease or custom /health
                // endpoint. The actual request reports connectivity/auth errors.
                (new CliNetworkBoundary($values['cli.host_addresses'] ?? [], $values['cli.tls_policy'] ?? []))->requestOptions($candidate['instance']['base_url'], $values['cli.network']);
                $live = $contract['wire_api'] === 'ollama_chat'
                    ? $this->ollamaMetadata($session, $candidate, $contract, $values) : [];

                return ['allowed' => true, 'route' => $contract + $live + $trust + [
                    'instance_id' => $candidate['instance_id'], 'base_url' => $candidate['instance']['base_url'],
                    'network_mode' => $values['cli.network'], 'agent_admitted' => true,
                    'runtime_contract_sha256' => hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR)),
                ]];
            }
            $lease = $session['host_lease'] ?? [];
            $target = $contract['controller_target'] ?? null;
            if (! is_string($target) || ($lease['phase'] ?? null) !== 'owned'
            || ($lease['target'] ?? null) !== $target || ($lease['model'] ?? null) !== $candidate['model']
            || ! is_string($lease['lease_id'] ?? null) || $lease['lease_id'] === ''
            || ! is_string($lease['controller_identity'] ?? null) || $lease['controller_identity'] === ''
            || ! is_string($lease['host_id'] ?? null) || $lease['host_id'] === ''
            || strtotime($lease['expires_at'] ?? '') <= time()) {
                throw new RuntimeException('The selected model requires this session to own its current controller lease. Use /host activate '.$target.' or /host mode auto before retrying.');
            }
            ModelHostControllerResourcePolicyService::assertReceiptBinding($capacity, $lease['capacity_binding'] ?? null);
            $observed = ($this->controllerStatus)($session, $target);
            $status = $observed['controller'] ?? [];
            $lifecycle = $status['lifecycle'] ?? [];
            ModelHostControllerResourcePolicyService::assertReceiptBinding($capacity, $lifecycle['capacity_binding'] ?? null);
            $targets = array_values(array_filter($lifecycle['targets'] ?? [], fn ($row) => ($row['target'] ?? null) === $target));
            if (($observed['controller_identity'] ?? null) !== ($lease['controller_identity'] ?? null)
                || ($status['host_id'] ?? null) !== ($lease['host_id'] ?? null)
                || ($lifecycle['lease_present'] ?? null) !== true || ($lifecycle['lease_expired'] ?? null) !== false
                || ($lifecycle['lease_id_sha256'] ?? null) !== hash('sha256', $lease['lease_id'])
                || ($lifecycle['active_target'] ?? null) !== $target
                || count($targets) !== 1 || ($targets[0]['running_exact'] ?? null) !== true
                || ($targets[0]['task_exact'] ?? null) !== true || ($targets[0]['control_exact'] ?? null) !== true
                || ($targets[0]['executable_exact'] ?? null) !== true || ($targets[0]['owned_process_count'] ?? null) !== 1
                || ($targets[0]['healthy'] ?? null) !== true || ($targets[0]['model_exact'] ?? null) !== true) {
                throw new RuntimeException('Current controller identity, ownership or exact model process differs.');
            }
            $values = $configuration->effective($session)['values'];
            $endpoint = $candidate['instance']['base_url'] ?? null;
            $network = $values['cli.network'] ?? 'offline';
            if (($contract['wire_api'] ?? null) === 'ollama_chat'
                || ($contract['runtime_management'] ?? null) === 'controller') {
                $engine = ($contract['wire_api'] ?? null) === 'ollama_chat' ? 'ollama' : $contract['controller_engine'];
                $policy = $observed['controller_policy'] ?? [];
                $spec = $policy['targets'][$target] ?? [];
                if (($policy['enabled'] ?? false) !== true || ($spec['activation_allowed'] ?? false) !== true
                    || ! in_array($spec['state'] ?? null, ['production', 'staged'], true)
                    || ($spec['model'] ?? null) !== $candidate['model'] || ($spec['engine'] ?? null) !== $engine
                    || ! in_array($candidate['role'] ?? null, $spec['roles'] ?? [], true)) {
                    throw new RuntimeException('Current controller table policy does not admit the selected model and role.');
                }
                if (($contract['controller_host_id'] ?? null) !== $status['host_id']) {
                    throw new RuntimeException('Controller catalog belongs to another host.');
                }
                $identityBoundary = new CliNetworkBoundary($values['cli.host_addresses'] ?? []);
                $host = $identityBoundary->effectiveHost(...);
                if ($host($endpoint) === null || $host($endpoint) !== $host($observed['controller_endpoint'] ?? null)) {
                    throw new RuntimeException('Selected endpoint does not belong to the bound controller host.');
                }
                // The exact controller receipt already attests this compatible process,
                // model, health and pinned launch. It needs no Colibri-only health schema.
                (new CliNetworkBoundary($values['cli.host_addresses'] ?? [], $values['cli.tls_policy'] ?? []))
                    ->requestOptions($endpoint, $network);
                $live = ($contract['wire_api'] ?? null) === 'ollama_chat'
                    ? $this->ollamaMetadata($session, $candidate, $contract, $values) : [];

                return ['allowed' => true, 'route' => $contract + $live + $trust + [
                    'instance_id' => $candidate['instance_id'], 'base_url' => $endpoint,
                    'network_mode' => $network, 'agent_admitted' => true,
                    'runtime_contract_sha256' => hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR)),
                    'lease_binding_sha256' => hash('sha256', json_encode([$lease['controller_identity'], $lease['lease_id']], JSON_THROW_ON_ERROR)),
                ]];
            }
            if (! is_string($endpoint) || ! str_ends_with(rtrim($endpoint, '/'), '/v1')) {
                throw new RuntimeException('Selected gateway requires its explicit v1 endpoint.');
            }
            $options = (new CliNetworkBoundary($values['cli.host_addresses'] ?? [], $values['cli.tls_policy'] ?? []))->requestOptions($endpoint, $network);
            $response = $this->http->request('GET', substr(rtrim($endpoint, '/'), 0, -3).'/health', $options + [
                'headers' => $this->bindings->modelHeaders($candidate['instance']),
                'connect_timeout' => 5, 'timeout' => 15, 'http_errors' => false,
            ]);
            $raw = (string) $response->getBody();
            $this->store->recordEvent($session['id'], 'agent_runtime_status', [
                'instance_id' => $candidate['instance_id'], 'http_status' => $response->getStatusCode(),
                'original_response' => $this->artifacts->captureBytes($raw),
            ]);
            $health = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $runtime = $health['plos_cli_runtime'] ?? [];
            if ($response->getStatusCode() !== 200 || ($health['status'] ?? null) !== 'ok'
                || ($runtime['schema'] ?? null) !== 'plos.cli.colibri-live.v1'
                || ($runtime['model'] ?? null) !== $candidate['model']
                || ($runtime['gateway_sha256'] ?? null) !== $contract['gateway_sha256']
                || ($runtime['engine_sha256'] ?? null) !== $contract['engine_sha256']
                || ($runtime['native_tools_enabled'] ?? null) !== ($contract['native_tools_enabled'] ?? true)
                || ($runtime['lossless_output'] ?? null) !== true || ($runtime['raw_output'] ?? null) !== true) {
                throw new RuntimeException('Authenticated gateway does not match the qualified native CLI contract.');
            }
            $route = array_intersect_key($contract, array_flip(['context_window', 'max_output_tokens', 'max_iterations',
                'max_tool_calls', 'enable_thinking', 'tokenizer', 'temperature', 'top_p', 'reasoning_effort',
                'absolute_timeout_seconds', 'idle_timeout_seconds', 'manual_text_only', 'native_tools_enabled', 'input_modalities']));

            return ['allowed' => true, 'route' => $route + $trust + [
                'instance_id' => $candidate['instance_id'], 'model' => $candidate['model'],
                'base_url' => $endpoint, 'network_mode' => $network, 'agent_admitted' => true,
                'plos_cli_raw_output' => true, 'controller_target' => $target,
                'runtime_contract_sha256' => hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR)),
                'lease_binding_sha256' => hash('sha256', json_encode([$lease['controller_identity'], $lease['lease_id']], JSON_THROW_ON_ERROR)),
            ]];
        } catch (Throwable $error) {
            if ($error instanceof GuzzleException) {
                return ['allowed' => false, 'reason' => CliModelFailure::message($candidate, $error)];
            }

            return ['allowed' => false, 'reason' => $error instanceof RuntimeException ? $error->getMessage()
                : 'Current native CLI runtime verification failed.'];
        }
    }

    /** Read engine/model metadata only; never load a model or repeat qualification. */
    private function ollamaMetadata(array $session, array $candidate, array $contract, array $values): array
    {
        $base = rtrim($candidate['instance']['base_url'], '/');
        if (str_ends_with($base, '/v1')) {
            $base = substr($base, 0, -3);
        }
        $network = new CliNetworkBoundary($values['cli.host_addresses'] ?? [], $values['cli.tls_policy'] ?? []);
        $metadata = [];
        $hash = null;
        foreach (['version' => 'GET', 'show' => 'POST'] as $path => $method) {
            $options = $network->requestOptions($base, $values['cli.network'] ?? 'offline');
            $response = $this->http->request($method, $base.'/api/'.$path, $options + [
                'headers' => $this->bindings->modelHeaders($candidate['instance']),
                'connect_timeout' => 5, 'timeout' => 15, 'http_errors' => false,
            ] + ($path === 'show' ? ['json' => ['model' => $candidate['model']]] : []));
            $raw = (string) $response->getBody();
            $artifact = $this->artifacts->captureBytes($raw);
            $this->store->recordEvent($session['id'], 'agent_runtime_status', [
                'instance_id' => $candidate['instance_id'], 'operation' => $path,
                'http_status' => $response->getStatusCode(), 'original_response' => $artifact,
            ]);
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if ($response->getStatusCode() !== 200 || ! is_array($data) || isset($data['error'])) {
                throw new RuntimeException('Selected Ollama metadata is unavailable; no model request was made.');
            }
            if ($path === 'version') {
                $version = $data['version'] ?? null;
                if (! is_string($version) || ! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)
                    || version_compare($version, '0.13.3', '<')) {
                    throw new RuntimeException('Selected Ollama server lacks a supported native context-preservation API.');
                }
                $metadata['engine_version'] = $version;
            } else {
                $capabilities = $data['capabilities'] ?? [];
                if (! is_array($capabilities)
                    || ($contract['native_tools_enabled'] && ! in_array('tools', $capabilities, true))
                    || (in_array('image', $contract['input_modalities'], true) && ! in_array('vision', $capabilities, true))) {
                    throw new RuntimeException('Selected Ollama model no longer supplies its table-declared native capabilities.');
                }
                $info = $data['model_info'] ?? [];
                $architecture = $info['general.architecture'] ?? null;
                $context = is_string($architecture) ? ($info[$architecture.'.context_length'] ?? null) : null;
                if (! is_int($context) || $context < $contract['context_window']) {
                    throw new RuntimeException('Selected Ollama model metadata does not support the configured context window.');
                }
                $hash = $artifact['sha256'];
            }
        }

        return $metadata + ['runtime_metadata_sha256' => $hash];
    }
}
