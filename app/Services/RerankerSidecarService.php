<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class RerankerSidecarService
{
    private const SERVER_SCRIPT = 'scripts/reranker/qwen_reranker_server.py';

    private const LAUNCHER_SCRIPT = 'scripts/reranker/start-server.sh';

    public function __construct(private readonly RerankerSidecarRuntimePolicyService $runtimePolicy) {}

    public function available(): bool
    {
        $policy = $this->runtimePolicy->required();

        return $this->availableWithPolicy($policy);
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        return $this->healthWithPolicy($this->runtimePolicy->required());
    }

    /** @param array<string,mixed> $policy */
    private function healthWithPolicy(array $policy): array
    {
        if (! $this->hasLoopbackUrl()) {
            return ['status' => 'unavailable', 'error' => 'non_loopback_url_rejected'];
        }

        try {
            $response = $this->authorizedHttp()
                ->connectTimeout($policy['health']['connect_timeout_seconds'])
                ->timeout($policy['health']['request_timeout_seconds'])
                ->get($this->url().'/health');

            return $response->successful()
                ? (array) $response->json()
                : ['status' => 'unavailable', 'http_status' => $response->status()];
        } catch (\Throwable) {
            return ['status' => 'unavailable'];
        }
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $policy = $this->runtimePolicy->required();
        $health = $this->healthWithPolicy($policy);

        return [
            'schema' => 'plos.reranker_sidecar_status.v1',
            'configured' => $this->hasLoopbackUrl(),
            'available' => $this->availableFromHealth($health),
            'url' => $this->url(),
            'model' => $this->model(),
            'revision' => $this->revision(),
            'device' => $this->device(),
            'python' => $this->pythonPath(),
            'health' => $health,
            'policy_source' => 'system_configs.'.RerankerSidecarRuntimePolicyService::CONFIG_KEY,
            'effective_policy' => $policy,
            'posture' => [
                'loopback_only' => true,
                'read_only_evaluation' => true,
                'production_route' => false,
                'content_logging' => false,
            ],
        ];
    }

    public function start(): bool
    {
        $policy = $this->runtimePolicy->required();
        if (! $this->hasLoopbackUrl()) {
            return false;
        }

        if ($this->availableWithPolicy($policy)) {
            return true;
        }

        $python = $this->pythonPath();
        $server = base_path(self::SERVER_SCRIPT);
        $launcher = base_path(self::LAUNCHER_SCRIPT);
        if (! is_executable($python) || ! is_file($server) || ! is_file($launcher)) {
            Log::warning('Reranker sidecar runtime is incomplete', [
                'python_ready' => is_executable($python),
                'server_ready' => is_file($server),
                'launcher_ready' => is_file($launcher),
            ]);

            return false;
        }

        $result = Process::timeout($policy['launcher_timeout_seconds'])
            ->path(base_path())
            ->env($this->runtimeEnvironment($policy))
            ->run([
                'bash',
                $launcher,
            ]);
        if (! $result->successful()) {
            Log::error('Reranker sidecar launcher failed', [
                'exit_code' => $result->exitCode(),
                'error' => trim($result->errorOutput()),
            ]);

            return false;
        }

        $deadline = microtime(true) + $policy['startup_timeout_seconds'];
        while (microtime(true) < $deadline) {
            if ($this->availableWithPolicy($policy)) {
                return true;
            }
            sleep($policy['startup_poll_seconds']);
        }

        Log::error('Reranker sidecar failed its authenticated startup health gate');

        return false;
    }

    public function stop(): bool
    {
        $policy = $this->runtimePolicy->required();
        if (! $this->hasLoopbackUrl()) {
            return false;
        }

        if (($this->healthWithPolicy($policy)['status'] ?? null) === 'unavailable') {
            return true;
        }

        try {
            $response = $this->authorizedHttp()
                ->connectTimeout($policy['shutdown']['connect_timeout_seconds'])
                ->timeout($policy['shutdown']['request_timeout_seconds'])
                ->post($this->url().'/shutdown');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  list<array<string,mixed>>  $documents
     * @return array<string,mixed>
     */
    public function rerank(string $query, array $documents, string $instruction): array
    {
        $policy = $this->runtimePolicy->required();
        if (! $this->availableWithPolicy($policy)) {
            return ['status' => 'sidecar_unavailable', 'mode' => 'cross_encoder', 'results' => []];
        }

        $normalized = [];
        foreach ($documents as $document) {
            $id = trim((string) ($document['id'] ?? ''));
            $text = trim((string) ($document['text'] ?? $document['content'] ?? ''));
            if ($id === '' || $text === '') {
                continue;
            }
            $normalized[] = ['id' => $id, 'text' => $text];
        }
        if (count($normalized) !== count($documents)
            || count(array_unique(array_column($normalized, 'id'))) !== count($normalized)) {
            return ['status' => 'invalid_documents', 'mode' => 'cross_encoder', 'results' => []];
        }

        try {
            $response = $this->authorizedHttp()
                ->connectTimeout($policy['rerank']['connect_timeout_seconds'])
                ->timeout($policy['rerank']['request_timeout_seconds'])
                ->post($this->url().'/rerank', [
                    'query' => $query,
                    'instruction' => $instruction,
                    'documents' => $normalized,
                ]);
            if (! $response->successful() || $response->json('status') !== 'pass') {
                return [
                    'status' => 'sidecar_request_failed',
                    'mode' => 'cross_encoder',
                    'results' => [],
                    'http_status' => $response->status(),
                ];
            }
            if ($response->json('model') !== $this->model() || $response->json('revision') !== $this->revision()) {
                return ['status' => 'sidecar_identity_mismatch', 'mode' => 'cross_encoder', 'results' => []];
            }

            $scores = [];
            foreach ((array) $response->json('scores', []) as $score) {
                if (! is_array($score) || ! isset($score['id'], $score['score'])) {
                    continue;
                }
                $scores[(string) $score['id']] = (float) $score['score'];
            }
            $expectedIds = array_column($normalized, 'id');
            $returnedIds = array_keys($scores);
            sort($expectedIds);
            sort($returnedIds);
            if ($returnedIds !== $expectedIds) {
                return ['status' => 'sidecar_incomplete_scores', 'mode' => 'cross_encoder', 'results' => []];
            }

            $results = array_map(static fn (array $document): array => [
                'id' => $document['id'],
                'score' => $scores[$document['id']],
            ], $normalized);
            usort($results, static fn (array $left, array $right): int => ($right['score'] <=> $left['score'])
                ?: strcmp((string) $left['id'], (string) $right['id']));

            return [
                'status' => 'pass',
                'mode' => 'cross_encoder',
                'model' => (string) $response->json('model', ''),
                'revision' => (string) $response->json('revision', ''),
                'device' => (string) $response->json('device', ''),
                'duration_ms' => (int) $response->json('duration_ms', 0),
                'results' => $results,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Reranker sidecar request failed', ['error_class' => $exception::class]);

            return ['status' => 'sidecar_request_failed', 'mode' => 'cross_encoder', 'results' => []];
        }
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,string>
     */
    private function runtimeEnvironment(array $policy): array
    {
        return [
            'PLOS_RERANKER_TOKEN' => $this->token(),
            'PLOS_RERANKER_PYTHON' => $this->pythonPath(),
            'PLOS_RERANKER_MODEL' => $this->model(),
            'PLOS_RERANKER_REVISION' => $this->revision(),
            'PLOS_RERANKER_DEVICE' => $this->device(),
            'PLOS_RERANKER_PORT' => (string) parse_url($this->url(), PHP_URL_PORT),
            'PLOS_RERANKER_MAX_LENGTH' => (string) $policy['inference']['max_length'],
            'PLOS_RERANKER_BATCH_SIZE' => (string) $policy['inference']['batch_size'],
            'PLOS_RERANKER_THREADS' => (string) $policy['inference']['threads'],
            'PLOS_RERANKER_LOCAL_ONLY' => '1',
            'HF_HOME' => $this->cachePath(),
            'HF_HUB_CACHE' => $this->cachePath(),
        ];
    }

    private function authorizedHttp(): PendingRequest
    {
        return Http::withToken($this->token())->acceptJson();
    }

    /** @param array<string,mixed> $policy */
    private function availableWithPolicy(array $policy): bool
    {
        return $this->availableFromHealth($this->healthWithPolicy($policy));
    }

    /** @param array<string,mixed> $health */
    private function availableFromHealth(array $health): bool
    {
        return ($health['status'] ?? null) === 'ok'
            && ($health['model'] ?? null) === $this->model()
            && ($health['revision'] ?? null) === $this->revision()
            && data_get($health, 'security.authRequired') === true
            && data_get($health, 'security.hostProtection') === true
            && data_get($health, 'security.originProtection') === true
            && data_get($health, 'security.contentLogging') === false;
    }

    private function token(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is required for reranker sidecar authentication.');
        }

        return hash_hmac('sha256', 'reranker-sidecar-control-v1', $key);
    }

    private function url(): string
    {
        return rtrim((string) config('services.reranker_sidecar.url', 'http://127.0.0.1:9230'), '/');
    }

    private function hasLoopbackUrl(): bool
    {
        $url = parse_url($this->url());

        return is_array($url)
            && ($url['scheme'] ?? null) === 'http'
            && in_array($url['host'] ?? null, ['127.0.0.1', 'localhost'], true)
            && is_int($url['port'] ?? null);
    }

    private function model(): string
    {
        return (string) config('services.reranker_sidecar.model', 'Qwen/Qwen3-Reranker-0.6B');
    }

    private function revision(): string
    {
        return (string) config('services.reranker_sidecar.revision', 'e61197ed45024b0ed8a2d74b80b4d909f1255473');
    }

    private function device(): string
    {
        return (string) config('services.reranker_sidecar.device', 'cpu');
    }

    private function pythonPath(): string
    {
        $configured = trim((string) config('services.reranker_sidecar.python', ''));

        return $configured !== '' ? $configured : $this->runtimeRoot().'/venv/bin/python';
    }

    private function cachePath(): string
    {
        $configured = trim((string) config('services.reranker_sidecar.cache', ''));

        return $configured !== '' ? $configured : $this->runtimeRoot().'/huggingface';
    }

    private function runtimeRoot(): string
    {
        $home = getenv('HOME') ?: '/home/'.get_current_user();

        return rtrim($home, '/').'/.local/share/plos/qwen-reranker';
    }
}
