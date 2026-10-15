<?php

namespace Tests\Unit\Services;

use App\Services\RerankerSidecarRuntimePolicyService;
use App\Services\RerankerSidecarService;
use App\Services\SystemConfigService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class RerankerSidecarServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:dGVzdC1yZXJhbmtlci1zaWRlY2FyLWtleQ==',
            'services.reranker_sidecar.url' => 'http://127.0.0.1:9230',
            'services.reranker_sidecar.model' => 'Qwen/Qwen3-Reranker-0.6B',
            'services.reranker_sidecar.revision' => 'test-revision',
            'services.reranker_sidecar.python' => '/bin/true',
        ]);
        $this->bindPolicy();
        Http::preventStrayRequests();
    }

    public function test_hardened_health_contract_requires_expected_identity_and_bearer_token(): void
    {
        Http::fake([
            'http://127.0.0.1:9230/health' => Http::response($this->healthyPayload()),
        ]);

        $this->assertTrue(app(RerankerSidecarService::class)->available());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:9230/health'
            && $request->hasHeader('Authorization', 'Bearer '.$this->expectedToken()));
    }

    public function test_status_exposes_table_policy_without_changing_evaluation_posture(): void
    {
        Http::fake([
            'http://127.0.0.1:9230/health' => Http::response(['status' => 'unavailable'], 503),
        ]);
        $this->bindPolicy([
            'rerank' => ['request_timeout_seconds' => 240],
            'inference' => ['batch_size' => 2, 'threads' => 4],
        ]);

        $status = app(RerankerSidecarService::class)->status();

        $this->assertSame('system_configs.rag.reranker_sidecar_runtime_policy', $status['policy_source']);
        $this->assertSame(240, $status['effective_policy']['rerank']['request_timeout_seconds']);
        $this->assertSame(2, $status['effective_policy']['inference']['batch_size']);
        $this->assertSame(4, $status['effective_policy']['inference']['threads']);
        $this->assertFalse($status['available']);
        $this->assertFalse($status['posture']['production_route']);
        $this->assertTrue($status['posture']['read_only_evaluation']);
    }

    public function test_start_passes_table_policy_to_launcher_environment(): void
    {
        Http::fake([
            'http://127.0.0.1:9230/health' => Http::sequence()
                ->push(['status' => 'unavailable'], 503)
                ->push($this->healthyPayload()),
        ]);
        Process::fake(fn () => Process::result('Reranker sidecar launched with PID 12345'));
        $this->bindPolicy([
            'launcher_timeout_seconds' => 17,
            'inference' => [
                'max_length' => 2048,
                'batch_size' => 2,
                'threads' => 4,
            ],
        ]);

        $this->assertTrue(app(RerankerSidecarService::class)->start());
        Process::assertRan(fn ($process): bool => $process->timeout === 17
            && ($process->environment['PLOS_RERANKER_MAX_LENGTH'] ?? null) === '2048'
            && ($process->environment['PLOS_RERANKER_BATCH_SIZE'] ?? null) === '2'
            && ($process->environment['PLOS_RERANKER_THREADS'] ?? null) === '4');
    }

    public function test_wrong_revision_or_external_url_is_rejected(): void
    {
        Http::fake([
            'http://127.0.0.1:9230/health' => Http::response([
                ...$this->healthyPayload(),
                'revision' => 'unexpected-revision',
            ]),
        ]);
        $this->assertFalse(app(RerankerSidecarService::class)->available());

        config(['services.reranker_sidecar.url' => 'http://198.51.100.18:9230']);
        $this->assertFalse(app(RerankerSidecarService::class)->available());
    }

    public function test_rerank_requires_complete_scores_and_sorts_deterministically(): void
    {
        Http::fake([
            'http://127.0.0.1:9230/health' => Http::response($this->healthyPayload()),
            'http://127.0.0.1:9230/rerank' => Http::response([
                'status' => 'pass',
                'model' => 'Qwen/Qwen3-Reranker-0.6B',
                'revision' => 'test-revision',
                'device' => 'cpu',
                'duration_ms' => 42,
                'scores' => [
                    ['id' => 'low', 'score' => 0.1],
                    ['id' => 'high', 'score' => 0.9],
                ],
            ]),
        ]);

        $result = app(RerankerSidecarService::class)->rerank('query', [
            ['id' => 'low', 'text' => 'Low relevance.'],
            ['id' => 'high', 'text' => 'High relevance.'],
        ], 'Retrieve direct evidence.');

        $this->assertSame('pass', $result['status']);
        $this->assertSame(['high', 'low'], array_column($result['results'], 'id'));
        $this->assertSame('test-revision', $result['revision']);
        Http::assertSent(fn (Request $request): bool => $request->url() !== 'http://127.0.0.1:9230/rerank'
            || ($request['instruction'] === 'Retrieve direct evidence.' && count($request['documents']) === 2));
    }

    public function test_rerank_preserves_all_document_bytes_in_transport(): void
    {
        $text = "  José\r\n\tOriginal evidence. Critical tail  ";
        Http::fake([
            'http://127.0.0.1:9230/health' => Http::response($this->healthyPayload()),
            'http://127.0.0.1:9230/rerank' => Http::response([
                'status' => 'pass', 'model' => 'Qwen/Qwen3-Reranker-0.6B', 'revision' => 'test-revision',
                'scores' => [['id' => 'one', 'score' => 0.8]],
            ]),
        ]);
        $result = app(RerankerSidecarService::class)->rerank('query', [['id' => 'one', 'text' => $text]], 'Evidence.');
        $this->assertSame('pass', $result['status']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:9230/rerank'
            && $request['documents'][0]['text'] === $text);
    }

    public function test_incomplete_sidecar_scores_fail_closed(): void
    {
        Http::fake([
            'http://127.0.0.1:9230/health' => Http::response($this->healthyPayload()),
            'http://127.0.0.1:9230/rerank' => Http::response([
                'status' => 'pass',
                'model' => 'Qwen/Qwen3-Reranker-0.6B',
                'revision' => 'test-revision',
                'scores' => [['id' => 'one', 'score' => 0.9]],
            ]),
        ]);

        $result = app(RerankerSidecarService::class)->rerank('query', [
            ['id' => 'one', 'text' => 'First.'],
            ['id' => 'two', 'text' => 'Second.'],
        ], 'Retrieve direct evidence.');

        $this->assertSame('sidecar_incomplete_scores', $result['status']);
        $this->assertSame([], $result['results']);
    }

    public function test_missing_policy_stops_before_http_dispatch(): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturn(null);
        $this->app->instance(SystemConfigService::class, $systemConfig);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('runtime policy is unavailable');
        try {
            app(RerankerSidecarService::class)->status();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_invalid_request_timing_stops_before_http_dispatch(): void
    {
        $this->bindPolicy([
            'health' => [
                'connect_timeout_seconds' => 5,
                'request_timeout_seconds' => 3,
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid health timing');
        try {
            app(RerankerSidecarService::class)->status();
        } finally {
            Http::assertNothingSent();
        }
    }

    /** @return array<string,mixed> */
    private function healthyPayload(): array
    {
        return [
            'status' => 'ok',
            'model' => 'Qwen/Qwen3-Reranker-0.6B',
            'revision' => 'test-revision',
            'device' => 'cpu',
            'security' => [
                'authRequired' => true,
                'hostProtection' => true,
                'originProtection' => true,
                'contentLogging' => false,
            ],
        ];
    }

    private function expectedToken(): string
    {
        return hash_hmac('sha256', 'reranker-sidecar-control-v1', (string) config('app.key'));
    }

    /** @param array<string,mixed> $overrides */
    private function bindPolicy(array $overrides = []): void
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')
            ->with(RerankerSidecarRuntimePolicyService::CONFIG_KEY)
            ->willReturn(array_replace_recursive($this->policy(), $overrides));
        $this->app->instance(SystemConfigService::class, $systemConfig);
    }

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => RerankerSidecarRuntimePolicyService::SCHEMA,
            'health' => [
                'connect_timeout_seconds' => 1,
                'request_timeout_seconds' => 3,
            ],
            'launcher_timeout_seconds' => 10,
            'startup_timeout_seconds' => 240,
            'startup_poll_seconds' => 2,
            'shutdown' => [
                'connect_timeout_seconds' => 1,
                'request_timeout_seconds' => 5,
            ],
            'rerank' => [
                'connect_timeout_seconds' => 2,
                'request_timeout_seconds' => 180,
            ],
            'inference' => [
                'max_length' => 1024,
                'batch_size' => 4,
                'threads' => 6,
            ],
        ];
    }
}
