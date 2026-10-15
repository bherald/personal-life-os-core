<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\AIService;
use App\Services\ModelHostControllerTransportSupervisor;
use App\Services\RerankerSidecarRuntimePolicyService;
use App\Services\RerankerSidecarService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RerankerControlledTransportTest extends TestCase
{
    public function test_native_transport_verifies_exact_body_and_complete_terminal_score(): void
    {
        $service = $this->service();
        Http::fake(fn (Request $request) => Http::response($this->response($request)));
        $documents = [['id' => 'one', 'text' => "  José\r\nCritical tail  "]];
        $result = $service->rerankControlled('  query  ', $documents);
        $this->assertSame('pass', $result['status']);
        $this->assertSame('transformers', $result['engine']);
        $this->assertSame('fixture-provider', $result['instance_id']);
        $this->assertSame([['id' => 'one', 'score' => 0.8]], $result['results']);
        Http::assertSent(fn (Request $request) => $request['documents'] === $documents && $request['query'] === '  query  ' && $request->hasHeader('Host', '127.0.0.1:9230'));
    }

    public static function invalidResponses(): array
    {
        return ['body identity' => [['request_sha256' => 'other']], 'missing coverage' => [['scores' => []]],
            'receipt drift' => [['controller_receipt_sha256' => 'other']], 'invalid score' => [['scores' => [['id' => 'one', 'score' => '0.8']]]]];
    }

    #[DataProvider('invalidResponses')]
    public function test_response_drift_keeps_work_held(array $change): void
    {
        $service = $this->service();
        Http::fake(fn (Request $request) => Http::response(array_replace($this->response($request), $change)));
        $this->expectException(InferenceAdmissionException::class);
        $service->rerankControlled('query', [['id' => 'one', 'text' => 'original']]);
    }

    private function response(Request $request): array
    {
        return ['status' => 'pass', 'request_sha256' => hash('sha256', $request->body()),
            'model' => 'fixture', 'revision' => str_repeat('a', 40), 'device' => 'cpu',
            'scoring' => 'yes_no_softmax', 'complete_input' => true, 'controller_receipt_sha256' => str_repeat('b', 64),
            'scores' => [['id' => 'one', 'score' => 0.8]]];
    }

    private function service(): RerankerSidecarService
    {
        Http::preventStrayRequests();
        $policy = Mockery::mock(RerankerSidecarRuntimePolicyService::class);
        $policy->shouldReceive('controllerPolicy')->once()->andReturn(['instance_id' => 'fixture-provider', 'model' => 'fixture']);
        $contract = ['model' => 'fixture', 'wire_api' => 'rerank', 'instruction' => 'Evidence.',
            'revision' => str_repeat('a', 40), 'runtime_receipt_sha256' => str_repeat('b', 64),
            'maximum_input_bytes' => 2048, 'maximum_documents' => 2, 'device' => 'cpu', 'scoring' => 'yes_no_softmax',
            'complete_input' => true, 'timeout_seconds' => 180, 'native_port' => 9230, 'backend_http_host' => '127.0.0.1:9230'];
        $provider = (object) ['is_active' => true, 'routability' => 'allowed', 'base_url' => 'http://fixture',
            'api_key' => str_repeat('x', 32), 'compat_runtime_family' => 'transformers', 'config' => json_encode(['controller_workloads' => ['rerank' => ['fixture' => ['contract' => $contract]]]])];
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->once()->with('llm_instances')->andReturn($query);
        $query->shouldReceive('where')->once()->with('instance_id', 'fixture-provider')->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn($provider);
        $supervisor = Mockery::mock(ModelHostControllerTransportSupervisor::class);
        $supervisor->shouldReceive('run')->once()->with('rerank', 'http://fixture/rerank', Mockery::type('array'), Mockery::type('Closure'))
            ->andReturnUsing(fn ($wire, $endpoint, $payload, $operation) => AIService::withInferenceTransportValidator(
                $operation, function ($actualWire, $actualEndpoint, $settings) use ($wire, $endpoint, $payload): void {
                    $this->assertSame($wire, $actualWire);
                    $this->assertSame($endpoint, $actualEndpoint);
                    $this->assertSame(ModelHostControllerTransportSupervisor::settings($payload, ['Host' => '127.0.0.1:9230']), $settings);
                }));
        app()->instance(ModelHostControllerTransportSupervisor::class, $supervisor);

        return new RerankerSidecarService($policy);
    }
}
