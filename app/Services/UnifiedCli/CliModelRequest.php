<?php

namespace App\Services\UnifiedCli;

use Closure;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/** Already-admitted model I/O with local credentials and complete private bytes. */
final class CliModelRequest
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly CliArtifactStore $artifacts,
        private readonly CliLocalBindings $bindings,
        private readonly ClientInterface $client,
    ) {}

    public function complete(array $route, array $messages, array $tools, ?Closure $maintainLease = null): array
    {
        if (($route['agent_admitted'] ?? false) !== true || isset($route['headers'])) {
            throw new RuntimeException('Model I/O requires prior admission and local header resolution.');
        }
        if (($route['native_tools_enabled'] ?? true) === false && $tools !== []) {
            throw new RuntimeException('The selected model has tools disabled; no model request was sent.');
        }
        if (($route['runtime_management'] ?? null) === 'controller'
            && ($maintainLease === null || ! is_string($route['lease_binding_sha256'] ?? null)
                || ! is_string($route['controller_target'] ?? null))) {
            throw new RuntimeException('Controller-managed model I/O requires the admitted lease and its keeper.');
        }
        $session = $this->store->session($this->sessionId);
        $configuration = new CliConfiguration($this->store);
        $instances = array_column($configuration->definitions($session, 'llm_instances'), null, 'instance_id');
        $instance = $instances[$route['instance_id'] ?? ''] ?? null;
        $values = $configuration->effective($session)['values'];
        $network = $values['cli.network'] ?? 'offline';
        $models = CliSettingValue::decode($instance['supported_models'] ?? [], 'json');
        if (($route['context_handling'] ?? null) === 'provider_reject'
            && ! ((($instance['instance_type'] ?? null) === 'ollama' && ($route['wire_api'] ?? null) === 'ollama_chat')
                || (is_array($instance) && CliProviderRuntimePolicy::supports($instance)
                    && ($route['wire_api'] ?? null) === 'chat_completions'
                    && in_array($route['runtime_management'] ?? null, ['provider', 'controller'], true)))) {
            throw new RuntimeException('Native context rejection requires the selected supported provider transport.');
        }
        if (! is_array($instance) || ($instance['base_url'] ?? null) !== ($route['base_url'] ?? null)
            || $network !== ($route['network_mode'] ?? null)
            || ! is_array($models) || ! in_array($route['model'] ?? null, $models, true)) {
            throw new RuntimeException('Selected model endpoint, model or network mode differs from current session definitions.');
        }
        $tlsPolicy = $values['cli.tls_policy'] ?? [];
        $trust = (new CliTlsPolicy($tlsPolicy))->routeBinding($route['base_url'], $network);
        if (($route['tls_binding_sha256'] ?? null) !== ($trust['tls_binding_sha256'] ?? null)) {
            throw new RuntimeException('Selected model TLS trust differs from current session admission.');
        }
        $streams = ['request' => fopen('php://temp', 'w+b'), 'response' => fopen('php://temp', 'w+b')];
        if (in_array(false, $streams, true)) {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            throw new RuntimeException('Cannot stage complete model I/O.');
        }
        $complete = false;
        $cancelled = false;
        $turn = $session['turns'][array_key_last($session['turns'] ?? [])] ?? [];
        if (($turn['status'] ?? null) === 'inProgress') {
            $leaseKeeper = $maintainLease;
            $maintainLease = function () use ($turn, $leaseKeeper, &$cancelled): void {
                try {
                    $this->store->assertTurnNotCancelled($this->sessionId, $turn['id']);
                } catch (RuntimeException $error) {
                    $cancelled = true;
                    throw $error;
                }
                $leaseKeeper?->__invoke();
            };
        }
        try {
            $addresses = $configuration->effective($session)['values']['cli.host_addresses'] ?? [];
            $transport = ($instance['instance_type'] ?? null) === 'ollama'
                ? new OllamaChatTransport($this->client, fn () => $this->bindings->modelHeaders($instance), $addresses, $maintainLease, $tlsPolicy)
                : new ChatCompletionTransport($this->client, fn () => $this->bindings->modelHeaders($instance), $addresses, $maintainLease, $tlsPolicy);
            $result = $transport->complete($route, $messages, $tools, function (string $kind, string $bytes) use ($streams): void {
                if (! isset($streams[$kind])) {
                    throw new RuntimeException('Unknown model capture stream.');
                }
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($streams[$kind], substr($bytes, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Complete model I/O could not be captured.');
                    }
                    $offset += $written;
                }
            });
            $maintainLease?->__invoke();
            $complete = ($result['completed'] ?? false) === true;

            return $result;
        } catch (\Throwable $error) {
            if ($cancelled) {
                throw new RuntimeException('Operator cancelled the model request. All captured request/response bytes are retained; no retry or fallback was attempted.');
            }
            throw $error;
        } finally {
            try {
                $evidence = [];
                foreach ($streams as $kind => $stream) {
                    rewind($stream);
                    $evidence[$kind] = $this->artifacts->captureStream($stream);
                }
                $this->store->recordEvent($this->sessionId, 'model_io', ['instance_id' => $route['instance_id'],
                    'model' => $route['model'], 'generation' => $session['generation'], 'completed' => $complete, 'evidence' => $evidence]);
            } finally {
                foreach ($streams as $stream) {
                    fclose($stream);
                }
            }
        }
    }
}
