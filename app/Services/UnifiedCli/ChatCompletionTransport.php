<?php

namespace App\Services\UnifiedCli;

use App\Services\OpenAICompatibleProgressClient;
use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use OverflowException;
use RuntimeException;
use Throwable;

/** Exact selected-route transport; no retries, redirects, stop filters or fallback. */
final class ChatCompletionTransport
{
    public function __construct(private readonly ClientInterface $client, private readonly ?Closure $resolveHeaders = null,
        private readonly array $addressBindings = [], private readonly ?Closure $maintainLease = null, private readonly array $tlsPolicy = []) {}

    public function complete(array $route, array $messages, array $tools, Closure $capture): array
    {
        if (! is_string($route['base_url'] ?? null) || ! is_string($route['model'] ?? null)
            || ! is_string($route['instance_id'] ?? null) || ! is_int($route['max_output_tokens'] ?? null)
            || $route['max_output_tokens'] < 1) {
            throw new RuntimeException('Incomplete selected-route transport contract.');
        }
        $networkOptions = (new CliNetworkBoundary($this->addressBindings, $this->tlsPolicy))->requestOptions($route['base_url'], $route['network_mode'] ?? '', $this->maintainLease !== null);
        $tokenParameter = $route['output_token_parameter'] ?? 'max_tokens';
        if (! in_array($tokenParameter, ['max_tokens', 'max_completion_tokens'], true)) {
            throw new RuntimeException('Unsupported selected provider output-token parameter.');
        }
        $payload = ['model' => $route['model'], 'messages' => $messages,
            'stream' => true, 'stream_options' => ['include_usage' => true],
            $tokenParameter => $route['max_output_tokens']];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }
        foreach (['temperature', 'top_p', 'enable_thinking', 'reasoning_effort'] as $parameter) {
            if (array_key_exists($parameter, $route)) {
                $payload[$parameter] = $route[$parameter];
            }
        }
        if (array_key_exists('plos_cli_raw_output', $route)) {
            if ($route['plos_cli_raw_output'] !== true) {
                throw new RuntimeException('Invalid raw CLI output contract.');
            }
            $payload['plos_cli_raw_output'] = true;
        }
        if (isset($route['plos_cli_qualification'])) {
            if ($route['plos_cli_qualification'] !== 'echo-v1') {
                throw new RuntimeException('Unknown CLI qualification contract.');
            }
            $payload['plos_cli_qualification'] = 'echo-v1';
        }
        $sink = null;
        $received = false;
        $leaseMaintenanceFailed = false;
        $leaseFailurePhase = 'during the model request';
        $status = null;
        try {
            if ($this->maintainLease !== null) {
                try {
                    ($this->maintainLease)();
                } catch (Throwable $error) {
                    $leaseMaintenanceFailed = true;
                    $leaseFailurePhase = 'before the model request';
                    throw $error;
                }
                $networkOptions['curl'][CURLOPT_NOPROGRESS] = false;
                // PHP may defer an exception from a cURL callback until the
                // transfer ends. Return cURL's explicit abort signal instead.
                $networkOptions['curl'][CURLOPT_XFERINFOFUNCTION] = function () use (&$leaseMaintenanceFailed): int {
                    try {
                        ($this->maintainLease)();

                        return 0;
                    } catch (Throwable) {
                        $leaseMaintenanceFailed = true;

                        return 1;
                    }
                };
            }
            if (isset($networkOptions['handler'])) {
                $sink = Utils::streamFor(Utils::tryFopen('php://temp', 'w+b'));
                $networkOptions['sink'] = $sink;
                $networkOptions['curl'][CURLOPT_LOW_SPEED_LIMIT] = 1;
                $networkOptions['curl'][CURLOPT_LOW_SPEED_TIME] = max(1, (int) ($route['idle_timeout_seconds'] ?? 3600));
            }
            $headers = $this->resolveHeaders === null ? ($route['headers'] ?? []) : ($this->resolveHeaders)($route);
            if (! is_array($headers)) {
                throw new RuntimeException('Selected model credential binding is unavailable.');
            }
            $response = (new OpenAICompatibleProgressClient($this->client))->requestChat(
                $route['base_url'], $payload,
                array_merge($headers, ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream']),
                $route, $networkOptions, $capture,
                function (string $endpoint, array $settings) use ($route, $payload): void {
                    if ($endpoint !== rtrim($route['base_url'], '/').'/chat/completions'
                        || $settings !== OpenAICompatibleProgressClient::contentFreeSettings($payload)) {
                        throw new RuntimeException('CLI request changed after selected-route admission.');
                    }
                    if (($route['runtime_management'] ?? null) === 'controller' && $this->maintainLease === null) {
                        throw new RuntimeException('Managed CLI request requires its admitted lease keeper.');
                    }
                    $this->maintainLease?->__invoke();
                });
            $received = true;
            $status = $response->getStatusCode() === 200 ? null : $response->getStatusCode();
            $stream = $response->getBody();
            try {
                if ($response->getStatusCode() !== 200) {
                    $errorBody = '';
                    while (! $stream->eof()) {
                        $bytes = $stream->read(8192);
                        $capture('response', $bytes);
                        $errorBody .= $bytes;
                    }
                    $error = json_decode($errorBody, true);
                    if ($response->getStatusCode() === 400 && ($error['error']['code'] ?? null) === 'context_length_exceeded') {
                        throw new OverflowException('Selected model context is full. Originals are retained. Choose /compact with confirmation, /model, or /clear; no automatic shortening or retry occurred.');
                    }
                    throw new RuntimeException('Selected provider returned HTTP '.$response->getStatusCode().'; full response retained.');
                }
                $chunks = (function () use ($stream): \Generator {
                    while (! $stream->eof()) {
                        yield $stream->read(8192);
                    }
                })();
                try {
                    $reply = (new ChatStreamDecoder)->decode($chunks, $route['model'], fn ($bytes) => $capture('response', $bytes), $route['accepted_response_models'] ?? []);
                } catch (Throwable $error) {
                    // A rejected event must not discard the remainder of the same
                    // response. Retain it without interpreting or executing it.
                    while (! $stream->eof()) {
                        $capture('response', $stream->read(8192));
                    }
                    throw $error;
                }

                return array_merge($reply, ['instance_id' => $route['instance_id']]);
            } finally {
                $stream->close();
            }
        } catch (Throwable $error) {
            if ($leaseMaintenanceFailed) {
                throw new RuntimeException('Controller lease maintenance failed '.$leaseFailurePhase.'. Review /host lease and the private controller evidence. Captured bytes remain available; no retry or fallback was attempted.');
            }
            if ($error instanceof OverflowException) {
                throw $error;
            }
            // Transport exceptions can contain headers; never expose their message.
            throw new RuntimeException(CliModelFailure::message($route, $error, $status));
        } finally {
            if ($sink !== null) {
                try {
                    if (! $received) {
                        $sink->rewind();
                        while (! $sink->eof()) {
                            $capture('response', $sink->read(8192));
                        }
                    }
                } finally {
                    $sink->close();
                }
            }
        }
    }
}
