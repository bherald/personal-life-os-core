<?php

namespace App\Services;

use App\Support\AI\PoolExecutionReceipt;
use Closure;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OllamaProgressClient
{
    private const ACTIVE_CACHE_KEY = 'llm:ollama:active_generations';

    private ClientInterface $client;

    public function __construct(?ClientInterface $client = null, private readonly ?Closure $progressObserver = null)
    {
        $this->client = $client ?? new Client;
    }

    /**
     * Generate through Ollama's NDJSON stream while keeping prompt and response
     * content out of progress telemetry.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $policy
     * @return array{response:string,chunk_count:int,downloaded_bytes:int,done:bool}
     */
    public function generate(string $baseUrl, array $payload, array $policy = []): array
    {
        $idleTimeout = $this->positiveInt($policy['idle_timeout_seconds'] ?? null, 600);
        $absoluteTimeout = $this->nonNegativeInt($policy['absolute_timeout_seconds'] ?? null, 0);
        $connectTimeout = $this->positiveInt($policy['connect_timeout_seconds'] ?? null, 5);
        $logInterval = $this->positiveInt($policy['log_interval_seconds'] ?? null, 30);
        $model = is_string($payload['model'] ?? null) ? $payload['model'] : 'unknown';
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $requestId = (string) Str::uuid();

        $payload['stream'] = true;
        $startedAt = microtime(true);
        $lastLogAt = $startedAt;
        $lastDownloadedBytes = 0;
        $lastProgressAt = null;
        $firstByteAt = null;

        $options = [
            'json' => $payload,
            'connect_timeout' => $connectTimeout,
            // Zero deliberately disables a transport wall clock. A bounded
            // token count plus inactivity detection lets slow active models
            // finish; the owning worker remains the outer safety ceiling.
            'timeout' => $absoluteTimeout,
            'read_timeout' => $idleTimeout,
            'progress' => function (
                int $downloadTotal,
                int $downloadedBytes,
                int $uploadTotal,
                int $uploadedBytes
            ) use (
                &$firstByteAt,
                &$lastDownloadedBytes,
                &$lastProgressAt,
                &$lastLogAt,
                $startedAt,
                $logInterval,
                $model,
                $host,
                $idleTimeout,
                $absoluteTimeout,
                $requestId
            ): void {
                $now = microtime(true);
                if ($downloadedBytes > 0 && $firstByteAt === null) {
                    $firstByteAt = $now;
                }

                $madeProgress = $downloadedBytes > $lastDownloadedBytes;
                if ($madeProgress) {
                    $lastDownloadedBytes = $downloadedBytes;
                    $lastProgressAt = $now;
                }

                if (($now - $lastLogAt) < $logInterval) {
                    return;
                }

                $recentProgress = $lastProgressAt !== null && ($now - $lastProgressAt) <= $logInterval;
                $state = $recentProgress ? 'receiving' : ($firstByteAt === null ? 'waiting_for_first_chunk' : 'waiting_for_next_chunk');
                $progress = [
                    'request_id' => $requestId,
                    'model' => $model,
                    'host' => $host,
                    'state' => $state,
                    'elapsed_seconds' => round($now - $startedAt, 1),
                    'seconds_since_progress' => $lastProgressAt !== null ? round($now - $lastProgressAt, 1) : null,
                    'downloaded_bytes' => $downloadedBytes,
                    'download_total_bytes' => $downloadTotal,
                    'uploaded_bytes' => $uploadedBytes,
                    'upload_total_bytes' => $uploadTotal,
                    'idle_timeout_seconds' => $idleTimeout,
                    'absolute_timeout_seconds' => $absoluteTimeout > 0 ? $absoluteTimeout : null,
                ];
                Log::info('Ollama monitored generation progress', $progress);
                $this->publishActive($requestId, $progress, $idleTimeout);
                $lastLogAt = $now;
            },
        ];

        if (defined('CURLOPT_LOW_SPEED_LIMIT') && defined('CURLOPT_LOW_SPEED_TIME')) {
            $options['curl'] = [
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => $idleTimeout,
            ];
        }

        $options = AIService::inferenceProgressOptions($options);
        if (AIService::maintainsInference()) {
            $options['handler'] = HandlerStack::create(PipelineHttpClientFactory::oneShotHandler());
        }

        $started = [
            'request_id' => $requestId,
            'model' => $model,
            'host' => $host,
            'state' => 'starting',
            'elapsed_seconds' => 0.0,
            'downloaded_bytes' => 0,
            'seconds_since_progress' => null,
            'idle_timeout_seconds' => $idleTimeout,
            'absolute_timeout_seconds' => $absoluteTimeout > 0 ? $absoluteTimeout : null,
        ];
        Log::info('Ollama monitored generation started', $started);
        $this->publishActive($requestId, $started, $idleTimeout);

        try {
            $receipt = PoolExecutionReceipt::fromConfig($policy);
            $client = PipelineHttpClientFactory::forRequest($this->client);
            if ($receipt !== null) {
                $options = $receipt->transportOptions($options);
                $receipt->beforeSend('ollama_generate', rtrim($baseUrl, '/').'/api/generate', $model);
            }
            $response = $client->request('POST', rtrim($baseUrl, '/').'/api/generate', $options);
            if ($response->getStatusCode() !== 200) {
                throw new Exception('Ollama monitored generation failed: HTTP '.$response->getStatusCode());
            }

            $rawBody = $response->getBody()->getContents();
            $decoded = $this->decodeNdjson($rawBody, $receipt);

            Log::info('Ollama monitored generation completed', [
                'request_id' => $requestId,
                'model' => $model,
                'host' => $host,
                'elapsed_seconds' => round(microtime(true) - $startedAt, 1),
                'downloaded_bytes' => strlen($rawBody),
                'chunk_count' => $decoded['chunk_count'],
                'done' => $decoded['done'],
            ]);

            return [
                'response' => $decoded['response'],
                'chunk_count' => $decoded['chunk_count'],
                'downloaded_bytes' => strlen($rawBody),
                'done' => $decoded['done'],
            ];
        } finally {
            $this->removeActive($requestId);
        }
    }

    /**
     * Stream Ollama /api/chat chunks with the same inactivity-based monitoring
     * used by background generation. Prompt and response content never enters
     * progress telemetry.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $policy
     * @return \Generator<int,array<string,mixed>>
     */
    public function streamChat(string $baseUrl, array $payload, array $policy = [], ?Closure $capture = null, array $transportOptions = []): \Generator
    {
        $idleTimeout = $this->positiveInt($policy['idle_timeout_seconds'] ?? null, 600);
        $absoluteTimeout = $this->nonNegativeInt($policy['absolute_timeout_seconds'] ?? null, 0);
        $connectTimeout = $this->positiveInt($policy['connect_timeout_seconds'] ?? null, 5);
        $logInterval = $this->positiveInt($policy['log_interval_seconds'] ?? null, 30);
        $model = is_string($payload['model'] ?? null) ? $payload['model'] : 'unknown';
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $requestId = (string) Str::uuid();

        $payload['stream'] = true;
        $startedAt = microtime(true);
        $lastLogAt = $startedAt;
        $lastDownloadedBytes = 0;
        $lastProgressAt = null;
        $firstByteAt = null;
        $chunkCount = 0;
        $done = false;

        $options = [
            'json' => $payload,
            'stream' => true,
            'connect_timeout' => $connectTimeout,
            'timeout' => $absoluteTimeout,
            'read_timeout' => $idleTimeout,
            'progress' => function (
                int $downloadTotal,
                int $downloadedBytes,
                int $uploadTotal,
                int $uploadedBytes
            ) use (
                &$firstByteAt,
                &$lastDownloadedBytes,
                &$lastProgressAt,
                &$lastLogAt,
                $startedAt,
                $logInterval,
                $model,
                $host,
                $idleTimeout,
                $absoluteTimeout,
                $requestId
            ): void {
                $now = microtime(true);
                if ($downloadedBytes > 0 && $firstByteAt === null) {
                    $firstByteAt = $now;
                }
                if ($downloadedBytes > $lastDownloadedBytes) {
                    $lastDownloadedBytes = $downloadedBytes;
                    $lastProgressAt = $now;
                }
                if (($now - $lastLogAt) < $logInterval) {
                    return;
                }

                $recentProgress = $lastProgressAt !== null && ($now - $lastProgressAt) <= $logInterval;
                $state = $recentProgress ? 'receiving' : ($firstByteAt === null ? 'waiting_for_first_chunk' : 'waiting_for_next_chunk');
                $progress = [
                    'request_id' => $requestId,
                    'model' => $model,
                    'host' => $host,
                    'state' => $state,
                    'elapsed_seconds' => round($now - $startedAt, 1),
                    'seconds_since_progress' => $lastProgressAt !== null ? round($now - $lastProgressAt, 1) : null,
                    'downloaded_bytes' => $downloadedBytes,
                    'download_total_bytes' => $downloadTotal,
                    'uploaded_bytes' => $uploadedBytes,
                    'upload_total_bytes' => $uploadTotal,
                    'idle_timeout_seconds' => $idleTimeout,
                    'absolute_timeout_seconds' => $absoluteTimeout > 0 ? $absoluteTimeout : null,
                ];
                if ($this->progressObserver === null) {
                    Log::info('Ollama monitored chat progress', $progress);
                }
                $this->publishActive($requestId, $progress, $idleTimeout);
                $lastLogAt = $now;
            },
        ];

        if (defined('CURLOPT_LOW_SPEED_LIMIT') && defined('CURLOPT_LOW_SPEED_TIME')) {
            $options['curl'] = [
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => $idleTimeout,
            ];
        }

        if (array_key_exists('verify', $transportOptions) && $transportOptions['verify'] !== true) {
            throw new Exception('Scoped transport verification must remain enabled.');
        }
        if (array_diff(array_keys($transportOptions), ['handler', 'curl', 'proxy', 'allow_redirects', 'headers', 'http_errors', 'sink', 'verify'])) {
            throw new Exception('Unsupported Ollama transport binding; payload and timeout policy remain explicit.');
        }
        if (isset($transportOptions['curl'])) {
            $transportOptions['curl'] = array_replace($options['curl'] ?? [], $transportOptions['curl']);
        }
        $options = array_replace($options, $transportOptions);
        if (defined('CURLOPT_XFERINFOFUNCTION') && isset($options['curl'][CURLOPT_XFERINFOFUNCTION])) {
            // A maintained CLI transfer needs cURL's explicit abort return.
            // Keep the existing progress reporting inside that same callback.
            $maintain = $options['curl'][CURLOPT_XFERINFOFUNCTION];
            $progress = $options['progress'];
            unset($options['progress']);
            $options['curl'][CURLOPT_XFERINFOFUNCTION] = static function ($handle, $total, $downloaded, $uploadTotal, $uploaded) use ($maintain, $progress): int {
                $abort = $maintain($handle, $total, $downloaded, $uploadTotal, $uploaded);
                if ($abort !== 0) {
                    return 1;
                }
                $progress($total, $downloaded, $uploadTotal, $uploaded);

                return 0;
            };
        }
        if ($capture !== null) {
            if (($payload['truncate'] ?? null) !== false || ($payload['shift'] ?? null) !== false) {
                throw new Exception('Lossless Ollama chat requires explicit truncate=false and shift=false.');
            }
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            unset($options['json']);
            $options['body'] = $body;
            $options['headers'] = array_replace($options['headers'] ?? [], ['Content-Type' => 'application/json']);
            $options['http_errors'] = false;
            $options['allow_redirects'] = false;
            $capture('request', $body);
        }

        $started = [
            'request_id' => $requestId,
            'model' => $model,
            'host' => $host,
            'state' => 'starting',
            'elapsed_seconds' => 0.0,
            'downloaded_bytes' => 0,
            'seconds_since_progress' => null,
            'idle_timeout_seconds' => $idleTimeout,
            'absolute_timeout_seconds' => $absoluteTimeout > 0 ? $absoluteTimeout : null,
        ];
        if ($this->progressObserver === null) {
            Log::info('Ollama monitored chat started', $started);
        }
        $this->publishActive($requestId, $started, $idleTimeout);

        $stream = null;
        try {
            $receipt = PoolExecutionReceipt::fromConfig($policy);
            $client = PipelineHttpClientFactory::forRequest($this->client);
            if ($receipt !== null) {
                $options = $receipt->transportOptions($options);
                $receipt->beforeSend('ollama_chat', rtrim($baseUrl, '/').'/api/chat', $model);
            }
            $response = $client->request('POST', rtrim($baseUrl, '/').'/api/chat', $options);
            $stream = $response->getBody();
            if ($response->getStatusCode() !== 200) {
                throw new Exception('Ollama monitored chat failed: HTTP '.$response->getStatusCode());
            }

            $buffer = '';
            while (! $stream->eof()) {
                $bytes = $stream->read(1024);
                $capture?->__invoke('response', $bytes);
                $buffer .= $bytes;
                while (($position = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $position));
                    $buffer = substr($buffer, $position + 1);
                    if ($line === '') {
                        continue;
                    }

                    $chunk = $this->decodeChatChunk($line, $capture !== null);
                    $chunkCount++;
                    $done = $done || ($chunk['done'] ?? false) === true;
                    if (($chunk['done'] ?? null) === true) {
                        $receipt?->ollamaTerminal($chunk);
                    }
                    yield $chunk;
                }
            }

            $line = trim($buffer);
            if ($line !== '') {
                $chunk = $this->decodeChatChunk($line, $capture !== null);
                $chunkCount++;
                $done = $done || ($chunk['done'] ?? false) === true;
                if (($chunk['done'] ?? null) === true) {
                    $receipt?->ollamaTerminal($chunk);
                }
                yield $chunk;
            }

            if ($chunkCount === 0 || ! $done) {
                throw new Exception('Incomplete Ollama monitored chat stream');
            }

            if ($this->progressObserver === null) {
                Log::info('Ollama monitored chat completed', [
                    'request_id' => $requestId,
                    'model' => $model,
                    'host' => $host,
                    'elapsed_seconds' => round(microtime(true) - $startedAt, 1),
                    'downloaded_bytes' => $lastDownloadedBytes,
                    'chunk_count' => $chunkCount,
                    'done' => true,
                ]);
            }
        } finally {
            try {
                // A rejected frame or HTTP error must retain the response bytes
                // already received and the remainder of this same response.
                if ($capture !== null && $stream !== null) {
                    try {
                        while (! $stream->eof()) {
                            $capture('response', $stream->read(8192));
                        }
                    } finally {
                        $stream->close();
                    }
                }
            } finally {
                $this->removeActive($requestId);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function activeGenerations(): array
    {
        return $this->progressSnapshot()['active_generations'];
    }

    /** @return array{available:bool,active_generations:list<array<string,mixed>>} */
    public function progressSnapshot(): array
    {
        try {
            $active = Cache::get(self::ACTIVE_CACHE_KEY, []);
        } catch (\Throwable $e) {
            Log::debug('Ollama progress heartbeat store unavailable', [
                'error_class' => get_class($e),
            ]);

            return ['available' => false, 'active_generations' => []];
        }

        if (! is_array($active)) {
            return ['available' => true, 'active_generations' => []];
        }

        $now = now()->timestamp;
        $active = array_filter($active, static fn (mixed $entry): bool => is_array($entry)
            && (int) ($entry['expires_at_epoch'] ?? 0) >= $now);

        return ['available' => true, 'active_generations' => array_values($active)];
    }

    /**
     * @return array{response:string,chunk_count:int,done:bool}
     */
    public function decodeNdjson(string $body, ?PoolExecutionReceipt $receipt = null): array
    {
        $result = '';
        $chunkCount = 0;
        $done = false;
        $terminalFrame = null;

        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $chunk = json_decode($line, true);
            if (! is_array($chunk)) {
                throw new Exception('Invalid NDJSON chunk from Ollama monitored generation');
            }
            if (is_string($chunk['error'] ?? null) && $chunk['error'] !== '') {
                throw new Exception('Ollama monitored generation error: '.$chunk['error']);
            }

            $chunkCount++;
            if (is_string($chunk['response'] ?? null)) {
                $result .= $chunk['response'];
            }
            if (($chunk['done'] ?? false) === true) {
                if ($receipt !== null && ! is_string($chunk['response'] ?? null)) {
                    throw new Exception('Invalid Ollama monitored generation terminal frame');
                }
                $done = true;
                $terminalFrame = $chunk;
            }
        }

        if ($chunkCount === 0 || ! $done) {
            throw new Exception('Incomplete Ollama monitored generation stream');
        }

        if ($terminalFrame !== null) {
            $receipt?->ollamaTerminal($terminalFrame);
        }

        return [
            'response' => $result,
            'chunk_count' => $chunkCount,
            'done' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeChatChunk(string $line, bool $preserveArgumentObjects = false): array
    {
        $chunk = json_decode($line, true);
        if (! is_array($chunk)) {
            throw new Exception('Invalid NDJSON chunk from Ollama monitored chat');
        }
        if (is_string($chunk['error'] ?? null) && $chunk['error'] !== '') {
            throw new Exception('Ollama monitored chat error: '.$chunk['error']);
        }
        if ($preserveArgumentObjects && isset($chunk['message']['tool_calls'])) {
            // Session capture retains the wire bytes. Preserve JSON object/array
            // distinctions too when adapting native arguments to the agent loop.
            $objects = json_decode($line);
            foreach ($objects->message->tool_calls ?? [] as $index => $call) {
                if (isset($call->function) && property_exists($call->function, 'arguments')) {
                    $chunk['message']['tool_calls'][$index]['function']['arguments'] = $call->function->arguments;
                }
            }
        }

        return $chunk;
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }

    private function nonNegativeInt(mixed $value, int $fallback): int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $fallback;
    }

    /** @param array<string,mixed> $state */
    private function publishActive(string $requestId, array $state, int $idleTimeout): void
    {
        if ($this->progressObserver !== null) {
            ($this->progressObserver)('progress', $requestId, $state);

            return;
        }
        try {
            Cache::lock(self::ACTIVE_CACHE_KEY.':lock', 5)->block(2, function () use ($requestId, $state, $idleTimeout): void {
                $active = Cache::get(self::ACTIVE_CACHE_KEY, []);
                $active = is_array($active) ? $active : [];
                $active[$requestId] = array_merge($state, [
                    'provider' => 'ollama',
                    'updated_at' => now()->toIso8601String(),
                    'expires_at_epoch' => now()->addSeconds($idleTimeout + 120)->timestamp,
                    'transport_wall_clock_disabled' => ($state['absolute_timeout_seconds'] ?? null) === null,
                ]);
                Cache::put(self::ACTIVE_CACHE_KEY, $active, $idleTimeout + 180);
            });
        } catch (\Throwable $e) {
            Log::debug('Ollama progress heartbeat update skipped', [
                'request_id' => $requestId,
                'error_class' => get_class($e),
            ]);
        }
    }

    private function removeActive(string $requestId): void
    {
        if ($this->progressObserver !== null) {
            ($this->progressObserver)('closed', $requestId, []);

            return;
        }
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
            Log::debug('Ollama progress heartbeat cleanup skipped', [
                'request_id' => $requestId,
                'error_class' => get_class($e),
            ]);
        }
    }
}
