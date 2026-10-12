<?php

namespace App\Services\UnifiedCli;

use App\Services\OllamaProgressClient;
use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use OverflowException;
use RuntimeException;
use stdClass;
use Throwable;

/** Protocol adaptation only; native HTTP/NDJSON uses the existing PLOS client. */
final class OllamaChatTransport
{
    public function __construct(private readonly ClientInterface $client, private readonly Closure $resolveHeaders,
        private readonly array $addressBindings = [], private readonly ?Closure $maintainLease = null, private readonly array $tlsPolicy = []) {}

    public function complete(array $route, array $messages, array $tools, Closure $capture): array
    {
        foreach (['context_window', 'max_output_tokens'] as $limit) {
            if (! is_int($route[$limit] ?? null) || $route[$limit] < 1) {
                throw new RuntimeException('Ollama requires a positive table-backed '.$limit.'.');
            }
        }
        $base = rtrim($route['base_url'], '/');
        if (str_ends_with($base, '/v1')) {
            $base = substr($base, 0, -3);
        }
        $options = (new CliNetworkBoundary($this->addressBindings, $this->tlsPolicy))->requestOptions($base, $route['network_mode'], $this->maintainLease !== null);
        $payload = ['model' => $route['model'], 'messages' => $this->messages($messages),
            'truncate' => false, 'shift' => false,
            'options' => array_replace($route['ollama_options'] ?? [], ['num_ctx' => $route['context_window'], 'num_predict' => $route['max_output_tokens']])];
        if ($tools !== []) {
            $payload['tools'] = $tools;
        }
        foreach (['temperature', 'top_p'] as $key) {
            if (array_key_exists($key, $route)) {
                $payload['options'][$key] = $route[$key];
            }
        }
        if (array_key_exists('enable_thinking', $route)) {
            $payload['think'] = $route['enable_thinking'];
        }
        if (isset($route['reasoning_effort']) && ($payload['think'] ?? true) !== false) {
            $payload['think'] = $route['reasoning_effort'];
        }
        $sink = null;
        $capturedResponse = false;
        $leaseFailed = false;
        $leaseFailurePhase = 'during Ollama generation';
        $errorProbe = '';
        try {
            if ($this->maintainLease !== null) {
                try {
                    ($this->maintainLease)();
                } catch (Throwable $error) {
                    $leaseFailed = true;
                    $leaseFailurePhase = 'before the model request';
                    throw $error;
                }
                $options['curl'][CURLOPT_NOPROGRESS] = false;
                $options['curl'][CURLOPT_XFERINFOFUNCTION] = function () use (&$leaseFailed): int {
                    try {
                        ($this->maintainLease)();

                        return 0;
                    } catch (Throwable) {
                        $leaseFailed = true;

                        return 1;
                    }
                };
            }
            if (isset($options['handler'])) {
                $sink = Utils::streamFor(Utils::tryFopen('php://temp', 'w+b'));
                $options['sink'] = $sink;
            }
            $options['headers'] = ($this->resolveHeaders)();
            $client = new OllamaProgressClient($this->client, fn () => null);
            $content = $reasoning = '';
            $calls = $ids = [];
            $done = false;
            $finish = null;
            $usage = null;
            $stream = $client->streamChat($base, $payload, $route,
                function ($kind, $bytes) use ($capture, &$capturedResponse, &$errorProbe): void {
                    if ($kind === 'response') {
                        $capturedResponse = true;
                        // Only the diagnostic probe is bounded; capture below
                        // retains every original response byte independently.
                        if (strlen($errorProbe) < 8192) {
                            $errorProbe .= substr($bytes, 0, 8192 - strlen($errorProbe));
                        }
                    }
                    $capture($kind, $bytes);
                }, $options);
            try {
                foreach ($stream as $chunk) {
                    if ($done || ($chunk['model'] ?? null) !== $route['model'] || ! is_bool($chunk['done'] ?? null)) {
                        throw new RuntimeException('Invalid Ollama completion sequence or model identity.');
                    }
                    $message = $chunk['message'] ?? [];
                    if (! is_array($message) || (isset($message['role']) && $message['role'] !== 'assistant')
                        || array_diff(array_keys($message), ['role', 'content', 'thinking', 'tool_calls'])) {
                        throw new RuntimeException('Invalid Ollama assistant message.');
                    }
                    foreach (['content', 'thinking'] as $field) {
                        if (isset($message[$field]) && ! is_string($message[$field])) {
                            throw new RuntimeException('Unsupported Ollama output type; original response retained.');
                        }
                    }
                    $content .= $message['content'] ?? '';
                    $reasoning .= $message['thinking'] ?? '';
                    $nativeCalls = $message['tool_calls'] ?? [];
                    if (! is_array($nativeCalls) || ! array_is_list($nativeCalls)) {
                        throw new RuntimeException('Invalid native Ollama tool-call list.');
                    }
                    foreach ($nativeCalls as $call) {
                        $function = $call['function'] ?? [];
                        // Native chunks contain complete calls, not compatibility
                        // deltas. Index may be absent or the Go zero default;
                        // preserve supplied IDs, otherwise assign a journal ID.
                        $id = $call['id'] ?? 'ollama_'.bin2hex(random_bytes(16));
                        if (! is_string($id) || $id === '' || isset($ids[$id])
                            || ! is_string($function['name'] ?? null) || $function['name'] === ''
                            || ! ($function['arguments'] ?? null) instanceof stdClass) {
                            throw new RuntimeException('Invalid or repeated native Ollama tool call.');
                        }
                        $ids[$id] = true;
                        $calls[] = ['id' => $id, 'type' => 'function', 'function' => ['name' => $function['name'],
                            'arguments' => json_encode($function['arguments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]];
                    }
                    $done = $chunk['done'];
                    if ($done) {
                        $finish = $chunk['done_reason'] ?? null;
                        $usage = array_intersect_key($chunk, array_flip(['prompt_eval_count', 'eval_count', 'total_duration', 'load_duration', 'prompt_eval_duration', 'eval_duration']));
                    }
                }
            } finally {
                // Close a suspended generator on rejection so its shared client
                // retains the unread response before model_io is committed.
                unset($stream);
            }

            return ['instance_id' => $route['instance_id'], 'model' => $route['model'],
                'completed' => $done && $finish === 'stop', 'content' => $content,
                'reasoning_content' => $reasoning, 'tool_calls' => $calls, 'finish_reason' => $finish, 'usage' => $usage];
        } catch (Throwable $error) {
            $record = json_decode($errorProbe, true);
            $reason = is_array($record) ? ($record['error'] ?? null) : null;
            $streamPrefix = 'Ollama monitored chat error: ';
            if ($reason === null && str_starts_with($error->getMessage(), $streamPrefix)) {
                $reason = substr($error->getMessage(), strlen($streamPrefix));
            }
            $nested = is_string($reason) ? json_decode($reason, true) : null;
            $contextExceeded = is_string($reason)
                && str_starts_with($reason, 'the prompt is longer than the context length currently available to the model;');
            if (! $contextExceeded && is_array($nested) && is_array($nested['error'] ?? null)) {
                $providerError = $nested['error'];
                $contextExceeded = ($providerError['code'] ?? null) === 400
                    && ($providerError['type'] ?? null) === 'exceed_context_size_error'
                    && is_string($providerError['message'] ?? null)
                    && preg_match('/^request \([0-9]+ tokens\) exceeds the available context size \([0-9]+ tokens\)/D', $providerError['message']) === 1;
            }
            // Both Ollama context diagnostics are matched by fixed identifiers.
            // Never echo provider-controlled content.
            if (! $leaseFailed && $contextExceeded) {
                throw new OverflowException('Selected Ollama context limit reached. Full originals remain saved. Choose /compact for explicit confirmation, /model to select another model, or /clear for a new session. No retry or fallback was attempted.');
            }
            $status = preg_match('/^Ollama monitored chat failed: HTTP ([0-9]{3})$/D', $error->getMessage(), $match)
                ? (int) $match[1] : null;
            throw new RuntimeException($leaseFailed
                ? 'Controller lease maintenance failed '.$leaseFailurePhase.'. Review /host lease. Full captured bytes remain available; no retry was attempted.'
                : CliModelFailure::message($route, $error, $status));
        } finally {
            if ($sink !== null) {
                try {
                    if (! $capturedResponse) {
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

    private function messages(array $messages): array
    {
        $converted = $names = [];
        foreach ($messages as $message) {
            if (array_diff(array_keys($message), ['role', 'content', 'reasoning_content', 'tool_calls', 'tool_call_id', 'images'])
                || ! in_array($message['role'] ?? null, ['system', 'user', 'assistant', 'tool'], true)) {
                throw new RuntimeException('Message fields require an explicit Ollama mapping; original history is unchanged.');
            }
            $native = ['role' => $message['role'], 'content' => $message['content'] ?? ''];
            if (is_array($native['content'])) {
                $parts = $native['content'];
                $native['content'] = '';
                foreach ($parts as $part) {
                    if (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)
                        && ! array_diff(array_keys($part), ['type', 'text'])) {
                        $native['content'] .= $part['text'];
                    } elseif (($part['type'] ?? null) === 'image_url' && is_array($part['image_url'] ?? null)
                        && ! array_diff(array_keys($part), ['type', 'image_url'])
                        && ! array_diff(array_keys($part['image_url']), ['url', 'detail'])
                        && ($part['image_url']['detail'] ?? 'auto') === 'auto'
                        && is_string($part['image_url']['url'] ?? null)
                        && preg_match('~^data:image/[a-z0-9.+-]+;base64,([A-Za-z0-9+/]+={0,2})$~D', $part['image_url']['url'], $match)) {
                        $native['images'][] = $match[1];
                    } else {
                        throw new RuntimeException('Input part has no native Ollama mapping. No remote media fetch or omission was attempted.');
                    }
                }
            }
            if (! is_string($native['content'])) {
                throw new RuntimeException('Structured media requires an explicit native Ollama input mapping.');
            }
            if (isset($message['images'])) {
                if (isset($native['images'])) {
                    throw new RuntimeException('Ambiguous mixed native and structured image ordering.');
                }
                $native['images'] = $message['images'];
            }
            if (isset($native['images'])) {
                if (! is_array($native['images']) || ! array_is_list($native['images'])) {
                    throw new RuntimeException('Native Ollama images require an ordered base64 list.');
                }
                foreach ($native['images'] as $image) {
                    if (! is_string($image) || $image === '' || base64_encode(base64_decode($image, true) ?: '') !== $image) {
                        throw new RuntimeException('Native Ollama image encoding is invalid.');
                    }
                }
            }
            if (isset($message['reasoning_content'])) {
                $native['thinking'] = $message['reasoning_content'];
            }
            foreach ($message['tool_calls'] ?? [] as $call) {
                $arguments = json_decode($call['function']['arguments'], flags: JSON_THROW_ON_ERROR);
                if (! $arguments instanceof stdClass) {
                    throw new RuntimeException('Ollama tool arguments must be JSON objects.');
                }
                $native['tool_calls'][] = ['id' => $call['id'], 'function' => ['name' => $call['function']['name'], 'arguments' => $arguments]];
                $names[$call['id']] = $call['function']['name'];
            }
            if ($message['role'] === 'tool') {
                $id = $message['tool_call_id'] ?? '';
                if (! isset($names[$id])) {
                    throw new RuntimeException('Ollama tool result has no matching call in original history.');
                }
                $native['tool_call_id'] = $id;
                $native['tool_name'] = $names[$id];
            }
            $converted[] = $native;
        }

        return $converted;
    }
}
