<?php

namespace App\Services;

use App\Exceptions\ChatStreamCancelledException;
use App\Services\UnifiedCli\ChatStreamDecoder;
use App\Support\AI\PoolExecutionReceipt;
use Closure;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;

class OpenAICompatibleProgressClient
{
    /** Share the exact streamed request shape between admission and transport. */
    public static function streamPayload(array $payload, array $policy = []): array
    {
        $payload['stream'] = true;
        $payload['stream_options'] = array_merge(
            is_array($payload['stream_options'] ?? null) ? $payload['stream_options'] : [],
            ['include_usage' => true]
        );
        $stops = array_values(array_slice(array_unique(array_filter(
            is_array($policy['stop_sequences'] ?? null) ? $policy['stop_sequences'] : [],
            static fn (mixed $value): bool => is_string($value) && strlen($value) >= 1 && strlen($value) <= 64
        )), 0, 8));
        if (($policy['native_stop_sequences'] ?? false) === true && $stops !== []) {
            $payload['stop'] = array_slice($stops, 0, 4);
            if (($policy['ignore_leading_stop_sequences'] ?? false) === true) {
                $payload['x_colibri_ignore_leading_stop'] = true;
            }
        }

        return $payload;
    }

    /** Messages stay on the wire and never enter resource-contract metadata. */
    public static function contentFreeSettings(array $payload): array
    {
        unset($payload['messages']);

        return $payload;
    }

    private const ACTIVE_CACHE_KEY = 'llm:openai_compatible:active_generations';

    private ClientInterface $client;

    private ?ChatStreamCancellationService $cancellations;

    public function __construct(
        ?ClientInterface $client = null,
        ?ChatStreamCancellationService $cancellations = null
    ) {
        $this->client = $client ?? new Client;
        $this->cancellations = $cancellations;
    }

    /**
     * Shared request path for the pipeline and callers retaining native frames.
     * No decoding, content filtering, retries or model substitution occurs here.
     * Capture receives only the exact JSON body, never authentication headers.
     */
    public function requestChat(string $baseUrl, array $payload, array $headers = [], array $policy = [],
        array $transportOptions = [], ?Closure $capture = null, ?Closure $admission = null): ResponseInterface
    {
        if (array_key_exists('verify', $transportOptions) && $transportOptions['verify'] !== true) {
            throw new Exception('Scoped transport verification must remain enabled.');
        }
        if (array_diff(array_keys($transportOptions), ['handler', 'curl', 'proxy', 'allow_redirects', 'sink', 'verify'])) {
            throw new Exception('Unsupported OpenAI-compatible transport binding.');
        }
        $idleTimeout = $this->positiveInt($policy['idle_timeout_seconds'] ?? null, 3600);
        $options = [
            'headers' => array_merge(['Accept' => 'text/event-stream', 'Content-Type' => 'application/json'], $headers),
            'stream' => true, 'http_errors' => false,
            'connect_timeout' => $this->positiveInt($policy['connect_timeout_seconds'] ?? null, 10),
            'timeout' => $this->nonNegativeInt($policy['absolute_timeout_seconds'] ?? null, 0),
            'read_timeout' => $idleTimeout,
        ];
        if (defined('CURLOPT_LOW_SPEED_LIMIT') && defined('CURLOPT_LOW_SPEED_TIME')) {
            $options['curl'] = [CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => $idleTimeout];
        }
        if (isset($transportOptions['curl'])) {
            $transportOptions['curl'] = array_replace($options['curl'] ?? [], $transportOptions['curl']);
        }
        $options = array_replace($options, $transportOptions);
        if ($capture === null) {
            $options['json'] = $payload;
        } else {
            $options['body'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $capture('request', $options['body']);
        }

        $endpoint = rtrim($baseUrl, '/').'/chat/completions';
        if ($admission !== null) {
            $admission($endpoint, self::contentFreeSettings($payload));
        } else {
            AIService::assertInferenceTransport('openai_chat_completions', $endpoint, self::contentFreeSettings($payload));
        }
        if (AIService::hasManagedInferenceContract()) {
            $options = AIService::inferenceProgressOptions($options);
            // Maintain custody through silent prefill without allowing a transport replay.
            $options['handler'] = HandlerStack::create(PipelineHttpClientFactory::oneShotHandler());
        }

        $receipt = PoolExecutionReceipt::fromConfig($policy);
        $client = PipelineHttpClientFactory::forRequest($this->client);
        if ($receipt !== null) {
            $options = $receipt->transportOptions($options);
            $receipt->beforeSend('openai_chat_completions', $endpoint, (string) ($payload['model'] ?? ''));
        }

        return $client->request('POST', $endpoint, $options);
    }

    /** Preserve native frames and decode them with the same strict codec as the CLI. */
    public function completeNativeToolTurn(string $baseUrl, array $payload, array $headers, array $policy,
        string $instanceId, Closure $capture): array
    {
        $payload = self::streamPayload($payload);
        $endpoint = rtrim($baseUrl, '/').'/chat/completions';

        return app(ModelHostControllerTransportSupervisor::class)->run('openai_chat_completions', $endpoint, $payload,
            function () use ($baseUrl, $payload, $headers, $policy, $instanceId, $capture): array {
                $receipt = PoolExecutionReceipt::fromConfig($policy);
                $body = null;
                $terminalStop = null;
                try {
                    if ($receipt !== null) {
                        $this->throwIfCancelled((string) ($policy['cancellation_run_id'] ?? ''));
                    }
                    $response = $this->requestChat($baseUrl, $payload, $headers, $policy, [], $capture);
                    if ($response->getStatusCode() !== 200) {
                        throw new Exception('Native tool transport failed: HTTP '.$response->getStatusCode());
                    }
                    $body = $response->getBody();
                    $chunks = function () use ($body, $policy): \Generator {
                        while (! $body->eof()) {
                            $this->throwIfCancelled((string) ($policy['cancellation_run_id'] ?? ''));
                            yield $body->read(8192);
                        }
                    };
                    $reply = (new ChatStreamDecoder)->decode($chunks(), $payload['model'],
                        fn ($bytes) => $capture('response', $bytes), [],
                        function (string $marker) use ($receipt, $payload): void {
                            if ($marker !== 'finish_reason' || ! array_key_exists('n', $payload)
                                || (is_int($payload['n']) && $payload['n'] === 1)) {
                                $receipt?->openAiWireTerminal($marker);
                            }
                        });

                    if ($receipt !== null && ($reply['completed'] ?? false) !== true) {
                        $receipt->stopReplayAfterFailure(new \RuntimeException('Native wire response was incomplete; no tool action or replay is allowed.'));
                    }

                    return $reply + ['instance_id' => $instanceId];
                } catch (\Throwable $error) {
                    try {
                        $receipt?->stopReplayAfterFailure($error);
                    } catch (\Throwable $stop) {
                        $terminalStop = $stop;
                        throw $stop;
                    }
                    throw $error;
                } finally {
                    try {
                        $body?->close();
                    } catch (\Throwable $closeError) {
                        if ($terminalStop !== null) {
                            throw $terminalStop;
                        }
                        $receipt?->stopReplayAfterFailure($closeError);
                        throw $closeError;
                    }
                }
            });
    }

    /**
     * Stream an OpenAI-compatible chat completion with content-free progress
     * telemetry and inactivity-based timeout handling.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,string>  $headers
     * @param  array<string,mixed>  $policy
     * @return \Generator<int,array<string,mixed>>
     */
    public function streamChat(string $baseUrl, array $payload, array $headers = [], array $policy = []): \Generator
    {
        $endpoint = rtrim($baseUrl, '/').'/chat/completions';
        if (! AIService::hasManagedInferenceContract()
            && app(ModelHostControllerResourcePolicyService::class)->managedProvider($endpoint) !== null) {
            yield from app(ModelHostControllerTransportSupervisor::class)->stream(
                'openai_chat_completions', $endpoint, self::streamPayload($payload, $policy),
                fn () => $this->streamChat($baseUrl, $payload, $headers, $policy),
                ['cancelled' => function () use ($policy): bool {
                    $this->throwIfCancelled((string) ($policy['cancellation_run_id'] ?? ''));

                    return connection_aborted() !== 0;
                }]);

            return;
        }
        $idleTimeout = $this->positiveInt($policy['idle_timeout_seconds'] ?? null, 3600);
        $absoluteTimeout = $this->nonNegativeInt($policy['absolute_timeout_seconds'] ?? null, 0);
        $logInterval = $this->positiveInt($policy['log_interval_seconds'] ?? null, 30);
        $provider = trim((string) ($policy['provider'] ?? 'openai_compatible')) ?: 'openai_compatible';
        $model = is_string($payload['model'] ?? null) ? $payload['model'] : 'unknown';
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $requestId = (string) Str::uuid();
        $startedAt = microtime(true);
        $lastLogAt = $startedAt;
        $lastProgressAt = null;
        $firstContentAt = null;
        $downloadedBytes = 0;
        $chunkCount = 0;
        $done = false;
        $poolReceipt = PoolExecutionReceipt::fromConfig($policy);
        $finishReason = null;
        $usage = null;
        $queueWaitMs = null;
        $cancellationRunId = is_string($policy['cancellation_run_id'] ?? null)
            ? trim($policy['cancellation_run_id'])
            : '';
        $stopSequences = array_values(array_slice(array_unique(array_filter(
            is_array($policy['stop_sequences'] ?? null) ? $policy['stop_sequences'] : [],
            static fn (mixed $value): bool => is_string($value)
                && strlen($value) >= 1
                && strlen($value) <= 64
        )), 0, 8));
        $ignoreLeadingStopSequences = ($policy['ignore_leading_stop_sequences'] ?? false) === true;
        $nativeStopSequences = ($policy['native_stop_sequences'] ?? false) === true
            ? array_slice($stopSequences, 0, 4)
            : [];
        $nativePatientStop = $nativeStopSequences !== [] && $ignoreLeadingStopSequences;
        $pendingStopContent = '';
        $maxSentences = $this->nonNegativeInt($policy['max_sentences'] ?? null, 0);
        $maxSentences = min(20, $maxSentences);
        $pendingSentenceContent = '';
        $sentencesSeen = 0;
        $providerStop = false;
        $usefulContentSeen = false;
        $leadingStopSequencesIgnored = 0;

        $payload = self::streamPayload($payload, $policy);
        $headers = array_merge(['Accept' => 'text/event-stream', 'Content-Type' => 'application/json'], $headers);

        $started = [
            'request_id' => $requestId,
            'provider' => $provider,
            'model' => $model,
            'host' => $host,
            'state' => 'starting',
            'started_at_epoch' => (int) $startedAt,
            'elapsed_seconds' => 0.0,
            'downloaded_bytes' => 0,
            'seconds_since_progress' => null,
            'idle_timeout_seconds' => $idleTimeout,
            'absolute_timeout_seconds' => $absoluteTimeout > 0 ? $absoluteTimeout : null,
            'cancellation_run_sha256' => $cancellationRunId !== '' ? hash('sha256', $cancellationRunId) : null,
        ];
        Log::info('OpenAI-compatible monitored chat started', $started);
        $this->publishActive($requestId, $started, $idleTimeout);

        try {
            $this->throwIfCancelled($cancellationRunId);
            $response = $this->requestChat($baseUrl, $payload, $headers, $policy);
            if ($response->getStatusCode() !== 200) {
                throw new Exception('OpenAI-compatible monitored chat failed: HTTP '.$response->getStatusCode());
            }

            $queueWaitHeader = trim($response->getHeaderLine('x-colibri-queue-wait-ms'));
            $queueWaitMs = is_numeric($queueWaitHeader) ? max(0, (int) $queueWaitHeader) : null;
            $connected = array_merge($started, [
                'state' => 'waiting_for_first_chunk',
                'elapsed_seconds' => round(microtime(true) - $startedAt, 1),
                'queue_wait_ms' => $queueWaitMs,
            ]);
            $this->publishActive($requestId, $connected, $idleTimeout);
            yield ['content' => '', 'finish_reason' => null, 'usage' => null, 'done' => false, 'queue_wait_ms' => $queueWaitMs];

            $stream = $response->getBody();
            $buffer = '';
            while (! $stream->eof()) {
                $this->throwIfCancelled($cancellationRunId);
                if (connection_aborted()) {
                    $stream->close();
                    throw new ChatStreamCancelledException;
                }

                $bytes = $stream->read(8192);
                if ($bytes === '') {
                    continue;
                }
                $this->throwIfCancelled($cancellationRunId);
                $downloadedBytes += strlen($bytes);
                $lastProgressAt = microtime(true);
                $buffer .= $bytes;
                $buffer = str_replace("\r\n", "\n", $buffer);

                while (($position = strpos($buffer, "\n\n")) !== false) {
                    $event = substr($buffer, 0, $position);
                    $buffer = substr($buffer, $position + 2);
                    foreach ($this->decodeEvent($event) as $chunk) {
                        if (isset($chunk['_wire_terminal'])
                            && ($chunk['_wire_terminal'] !== 'finish_reason'
                                || (! array_key_exists('n', $payload) || (is_int($payload['n']) && $payload['n'] === 1)))) {
                            $poolReceipt?->openAiWireTerminal($chunk['_wire_terminal']);
                        }
                        unset($chunk['_wire_terminal']);
                        $matchedStop = false;
                        $matchedSentenceLimit = false;
                        $stopRemainder = '';
                        if ($stopSequences !== [] && (string) ($chunk['content'] ?? '') !== '') {
                            [$chunk['content'], $matchedStop, $stopRemainder] = $this->filterStopSequences(
                                (string) $chunk['content'],
                                $pendingStopContent,
                                $stopSequences
                            );
                        }
                        if ($matchedStop
                            && $ignoreLeadingStopSequences
                            && ! $usefulContentSeen
                            && trim($pendingSentenceContent.(string) $chunk['content']) === ''
                        ) {
                            // Some local chat templates can emit a role marker before
                            // their first answer token. Discard that marker and keep
                            // waiting; the output-token and cancellation ceilings still
                            // bound a provider that never produces a useful answer.
                            $matchedStop = false;
                            $chunk['content'] = $stopRemainder;
                            $leadingStopSequencesIgnored++;
                        }
                        if ($matchedStop) {
                            // A role marker completes any useful prefix even when the
                            // optional sentence limiter was still holding an
                            // unpunctuated fragment.
                            $chunk['content'] = $pendingSentenceContent.(string) $chunk['content'];
                            $pendingSentenceContent = '';
                        } elseif ($maxSentences > 0 && (string) ($chunk['content'] ?? '') !== '') {
                            [$chunk['content'], $matchedSentenceLimit] = $this->filterSentenceLimit(
                                (string) $chunk['content'],
                                $pendingSentenceContent,
                                $sentencesSeen,
                                $maxSentences
                            );
                        }
                        $chunkCount++;
                        if ((string) ($chunk['content'] ?? '') !== '' && $firstContentAt === null) {
                            $firstContentAt = microtime(true);
                        }
                        if (trim((string) ($chunk['content'] ?? '')) !== '') {
                            $usefulContentSeen = true;
                        }
                        if (is_string($chunk['finish_reason'] ?? null) && $chunk['finish_reason'] !== '') {
                            $finishReason = $chunk['finish_reason'];
                        }
                        if (is_array($chunk['usage'] ?? null)) {
                            $usage = $chunk['usage'];
                        }
                        $done = $done || ($chunk['done'] ?? false) === true;
                        if ($matchedStop || $matchedSentenceLimit) {
                            $finishReason = 'stop';
                            $done = true;
                            $providerStop = true;
                            $pendingStopContent = '';
                            $pendingSentenceContent = '';
                            if ((string) ($chunk['content'] ?? '') !== '') {
                                yield $chunk;
                            }
                            $telemetry = $this->completionTelemetry(
                                $startedAt,
                                $firstContentAt,
                                $queueWaitMs,
                                $downloadedBytes,
                                $chunkCount + 1,
                                $finishReason,
                                $usage,
                                count($nativeStopSequences),
                                $nativePatientStop
                            );
                            $telemetry['provider_stop_sequence_matched'] = $matchedStop;
                            $telemetry['sentence_limit_matched'] = $matchedSentenceLimit;
                            $telemetry['leading_stop_sequences_ignored'] = $leadingStopSequencesIgnored;
                            $stream->close();
                            yield [
                                'content' => '',
                                'finish_reason' => 'stop',
                                'usage' => null,
                                'done' => true,
                                'telemetry' => $telemetry,
                            ];
                            break 3;
                        }
                        if (($chunk['done'] ?? false) === true) {
                            if ($pendingSentenceContent !== '' || $pendingStopContent !== '') {
                                $flush = $pendingSentenceContent.$pendingStopContent;
                                $pendingSentenceContent = '';
                                $pendingStopContent = '';
                                if ($firstContentAt === null) {
                                    $firstContentAt = microtime(true);
                                }
                                yield ['content' => $flush, 'finish_reason' => null, 'usage' => null, 'done' => false];
                            }
                            $chunk['telemetry'] = $this->completionTelemetry(
                                $startedAt,
                                $firstContentAt,
                                $queueWaitMs,
                                $downloadedBytes,
                                $chunkCount,
                                $finishReason,
                                $usage,
                                count($nativeStopSequences),
                                $nativePatientStop
                            );
                            $chunk['telemetry']['leading_stop_sequences_ignored'] = $leadingStopSequencesIgnored;
                        }
                        yield $chunk;
                    }
                }

                $now = microtime(true);
                if (($now - $lastLogAt) >= $logInterval) {
                    $progress = [
                        'request_id' => $requestId,
                        'provider' => $provider,
                        'model' => $model,
                        'host' => $host,
                        'state' => 'receiving',
                        'elapsed_seconds' => round($now - $startedAt, 1),
                        'seconds_since_progress' => $lastProgressAt !== null ? round($now - $lastProgressAt, 1) : null,
                        'downloaded_bytes' => $downloadedBytes,
                        'idle_timeout_seconds' => $idleTimeout,
                        'absolute_timeout_seconds' => $absoluteTimeout > 0 ? $absoluteTimeout : null,
                    ];
                    Log::info('OpenAI-compatible monitored chat progress', $progress);
                    $this->publishActive($requestId, $progress, $idleTimeout);
                    $lastLogAt = $now;
                }
            }

            if (! $providerStop && trim($buffer) !== '') {
                foreach ($this->decodeEvent($buffer) as $chunk) {
                    if (isset($chunk['_wire_terminal'])
                            && ($chunk['_wire_terminal'] !== 'finish_reason'
                                || (! array_key_exists('n', $payload) || (is_int($payload['n']) && $payload['n'] === 1)))) {
                        $poolReceipt?->openAiWireTerminal($chunk['_wire_terminal']);
                    }
                    unset($chunk['_wire_terminal']);
                    $chunkCount++;
                    if ((string) ($chunk['content'] ?? '') !== '' && $firstContentAt === null) {
                        $firstContentAt = microtime(true);
                    }
                    if (is_string($chunk['finish_reason'] ?? null) && $chunk['finish_reason'] !== '') {
                        $finishReason = $chunk['finish_reason'];
                    }
                    if (is_array($chunk['usage'] ?? null)) {
                        $usage = $chunk['usage'];
                    }
                    $done = $done || ($chunk['done'] ?? false) === true;
                    if (($chunk['done'] ?? false) === true) {
                        $chunk['telemetry'] = $this->completionTelemetry(
                            $startedAt,
                            $firstContentAt,
                            $queueWaitMs,
                            $downloadedBytes,
                            $chunkCount,
                            $finishReason,
                            $usage,
                            count($nativeStopSequences),
                            $nativePatientStop
                        );
                    }
                    yield $chunk;
                }
            }
            if ($chunkCount === 0 || ! $done) {
                throw new Exception('Incomplete OpenAI-compatible monitored chat stream');
            }

            Log::info('OpenAI-compatible monitored chat completed', array_merge([
                'request_id' => $requestId,
                'provider' => $provider,
                'model' => $model,
                'host' => $host,
            ], $this->completionTelemetry(
                $startedAt,
                $firstContentAt,
                $queueWaitMs,
                $downloadedBytes,
                $chunkCount,
                $finishReason,
                $usage,
                count($nativeStopSequences),
                $nativePatientStop
            )));
        } finally {
            $this->removeActive($requestId);
        }
    }

    /** @return list<array<string,mixed>> */
    private function decodeEvent(string $event): array
    {
        $dataLines = [];
        foreach (preg_split('/\r?\n/', trim($event)) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }
        if ($dataLines === []) {
            return [];
        }

        $data = implode("\n", $dataLines);
        if ($data === '[DONE]') {
            return [['done' => true, '_wire_terminal' => 'done_marker']];
        }

        $decoded = json_decode($data, true);
        if (! is_array($decoded)) {
            throw new Exception('Invalid SSE JSON from OpenAI-compatible monitored chat');
        }
        if (is_string($decoded['error']['message'] ?? null)) {
            throw new Exception('OpenAI-compatible monitored chat returned an SSE error');
        }

        $choice = $decoded['choices'][0] ?? [];
        $content = $choice['delta']['content'] ?? '';
        if (! is_string($content)) {
            $content = '';
        }
        $toolCalls = $choice['delta']['tool_calls'] ?? [];
        if (! is_array($toolCalls)) {
            $toolCalls = [];
        }

        $terminal = is_array($decoded['choices'] ?? null) && count($decoded['choices']) === 1
            && ($choice['index'] ?? null) === 0 && is_string($choice['finish_reason'] ?? null)
            && in_array($choice['finish_reason'], ['stop', 'length', 'tool_calls', 'content_filter', 'function_call'], true);

        return [[
            ...($terminal ? ['_wire_terminal' => 'finish_reason'] : []),
            'content' => $content,
            'tool_calls' => $toolCalls,
            'finish_reason' => $choice['finish_reason'] ?? null,
            'usage' => is_array($decoded['usage'] ?? null) ? $decoded['usage'] : null,
            'done' => false,
        ]];
    }

    /** @return array{available:bool,active_generations:list<array<string,mixed>>} */
    public function progressSnapshot(): array
    {
        try {
            $active = Cache::get(self::ACTIVE_CACHE_KEY, []);
        } catch (\Throwable $e) {
            Log::debug('OpenAI-compatible progress heartbeat store unavailable', ['error_class' => get_class($e)]);

            return ['available' => false, 'active_generations' => []];
        }
        $active = is_array($active) ? $active : [];
        $now = now()->timestamp;
        $active = array_filter($active, static fn (mixed $entry): bool => is_array($entry)
            && (int) ($entry['expires_at_epoch'] ?? 0) >= $now);
        $active = array_map(static function (array $entry) use ($now): array {
            if ((int) ($entry['started_at_epoch'] ?? 0) > 0) {
                $entry['elapsed_seconds'] = max(0, $now - (int) $entry['started_at_epoch']);
            }

            return $entry;
        }, $active);

        return ['available' => true, 'active_generations' => array_values($active)];
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }

    private function nonNegativeInt(mixed $value, int $fallback): int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $fallback;
    }

    /**
     * Hold only the suffix that could begin a cross-chunk stop sequence.
     *
     * @param  list<string>  $stopSequences
     * @return array{0:string,1:bool,2:string}
     */
    private function filterStopSequences(string $content, string &$pending, array $stopSequences): array
    {
        $pending .= $content;
        $matchAt = null;
        foreach ($stopSequences as $sequence) {
            $position = strpos($pending, $sequence);
            if ($position !== false && ($matchAt === null || $position < $matchAt)) {
                $matchAt = $position;
            }
        }
        if ($matchAt !== null) {
            $safe = substr($pending, 0, $matchAt);
            $matchedLength = 0;
            foreach ($stopSequences as $sequence) {
                if (substr($pending, $matchAt, strlen($sequence)) === $sequence) {
                    $matchedLength = strlen($sequence);
                    break;
                }
            }
            $remainder = substr($pending, $matchAt + $matchedLength);
            $pending = '';

            return [$safe, true, $remainder];
        }

        $heldBytes = max(array_map('strlen', $stopSequences)) - 1;
        $safeBytes = max(0, strlen($pending) - $heldBytes);
        $safe = substr($pending, 0, $safeBytes);
        $pending = substr($pending, $safeBytes);

        return [$safe, false, ''];
    }

    /** @return array{0:string,1:bool} */
    private function filterSentenceLimit(
        string $content,
        string &$pending,
        int &$sentencesSeen,
        int $maximum
    ): array {
        $pending .= $content;
        preg_match_all('/[.!?](?:["\')\]]*)\s+/u', $pending, $matches, PREG_OFFSET_CAPTURE);
        $boundaries = $matches[0] ?? [];
        if ($boundaries === []) {
            return ['', false];
        }

        $remaining = $maximum - $sentencesSeen;
        if (count($boundaries) >= $remaining) {
            [$match, $offset] = $boundaries[$remaining - 1];
            $end = $offset + strlen(rtrim($match));
            $safe = substr($pending, 0, $end);
            $pending = '';
            $sentencesSeen = $maximum;

            return [$safe, true];
        }

        [$lastMatch, $lastOffset] = $boundaries[array_key_last($boundaries)];
        $end = $lastOffset + strlen($lastMatch);
        $safe = substr($pending, 0, $end);
        $pending = substr($pending, $end);
        $sentencesSeen += count($boundaries);

        return [$safe, false];
    }

    private function throwIfCancelled(string $runId): void
    {
        if ($runId !== '' && ($this->cancellations ??= app(ChatStreamCancellationService::class))->requested($runId)) {
            throw new ChatStreamCancelledException;
        }
    }

    /** @return array<string,mixed> */
    private function completionTelemetry(
        float $startedAt,
        ?float $firstContentAt,
        ?int $queueWaitMs,
        int $downloadedBytes,
        int $chunkCount,
        ?string $finishReason,
        ?array $usage,
        int $nativeStopSequenceCount = 0,
        bool $nativePatientStop = false
    ): array {
        $completedAt = microtime(true);
        $completionTokens = is_numeric($usage['completion_tokens'] ?? null)
            ? (int) $usage['completion_tokens']
            : null;
        $decodeSeconds = $firstContentAt !== null ? max(0.001, $completedAt - $firstContentAt) : null;

        return [
            'elapsed_ms' => (int) round(($completedAt - $startedAt) * 1000),
            'time_to_first_content_ms' => $firstContentAt !== null
                ? (int) round(($firstContentAt - $startedAt) * 1000)
                : null,
            'queue_wait_ms' => $queueWaitMs,
            'downloaded_bytes' => $downloadedBytes,
            'chunk_count' => $chunkCount,
            'finish_reason' => $finishReason,
            'native_stop_sequences_requested' => $nativeStopSequenceCount,
            'native_patient_stop_requested' => $nativePatientStop,
            'prompt_tokens' => is_numeric($usage['prompt_tokens'] ?? null) ? (int) $usage['prompt_tokens'] : null,
            'completion_tokens' => $completionTokens,
            'total_tokens' => is_numeric($usage['total_tokens'] ?? null) ? (int) $usage['total_tokens'] : null,
            'decode_tokens_per_second' => $completionTokens !== null && $decodeSeconds !== null
                ? round($completionTokens / $decodeSeconds, 4)
                : null,
        ];
    }

    /** @param array<string,mixed> $state */
    private function publishActive(string $requestId, array $state, int $idleTimeout): void
    {
        try {
            Cache::lock(self::ACTIVE_CACHE_KEY.':lock', 5)->block(2, function () use ($requestId, $state, $idleTimeout): void {
                $active = Cache::get(self::ACTIVE_CACHE_KEY, []);
                $active = is_array($active) ? $active : [];
                $active[$requestId] = array_merge($state, [
                    'updated_at' => now()->toIso8601String(),
                    'expires_at_epoch' => now()->addSeconds($idleTimeout + 120)->timestamp,
                    'transport_wall_clock_disabled' => ($state['absolute_timeout_seconds'] ?? null) === null,
                ]);
                Cache::put(self::ACTIVE_CACHE_KEY, $active, $idleTimeout + 180);
            });
        } catch (\Throwable $e) {
            Log::debug('OpenAI-compatible progress heartbeat update skipped', [
                'request_id' => $requestId,
                'error_class' => get_class($e),
            ]);
        }
    }

    private function removeActive(string $requestId): void
    {
        try {
            Cache::lock(self::ACTIVE_CACHE_KEY.':lock', 5)->block(2, function () use ($requestId): void {
                $active = Cache::get(self::ACTIVE_CACHE_KEY, []);
                if (! is_array($active)) {
                    return;
                }
                unset($active[$requestId]);
                Cache::put(self::ACTIVE_CACHE_KEY, $active, 3600);
            });
        } catch (\Throwable $e) {
            Log::debug('OpenAI-compatible progress heartbeat cleanup skipped', [
                'request_id' => $requestId,
                'error_class' => get_class($e),
            ]);
        }
    }
}
