<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\AIService;
use App\Services\ModelHostControllerAutomaticSelectionService;
use App\Services\ModelHostControllerResourcePolicyService;
use App\Services\ModelHostControllerTransportSupervisor;
use App\Services\ModelHostControllerWaitService;
use App\Services\RerankerService;
use App\Services\RerankerSidecarService;
use Mockery;
use Tests\TestCase;

class RerankerControlledRetrievalTest extends TestCase
{
    public function test_explicit_dedicated_mode_preserves_sources_and_ranks_binary_scores(): void
    {
        $results = $this->results();
        $sidecar = Mockery::mock(RerankerSidecarService::class);
        $sidecar->shouldReceive('rerankControlled')->once()->with('query', [
            ['id' => '0', 'text' => $results[0]['document']->content],
            ['id' => '1', 'text' => $results[1]['document']->content],
        ])->andReturn(['status' => 'pass', 'results' => [['id' => '0', 'score' => 0.1], ['id' => '1', 'score' => 0.9]]]);
        app()->instance(RerankerSidecarService::class, $sidecar);
        $output = (new RerankerService(Mockery::mock(AIService::class)))->rerank('query', $results, 'cross_encoder');
        $this->assertSame('second', $output[0]['document']->title);
        $this->assertSame($results[1]['document']->content, $output[0]['document']->content);
        $this->assertSame('cross_encoder', $output[0]['rerank_method']);
    }

    public function test_unadmitted_dedicated_route_preserves_original_order_without_chat_fallback(): void
    {
        $sidecar = Mockery::mock(RerankerSidecarService::class);
        $sidecar->shouldReceive('rerankControlled')->once()->andReturn(['status' => 'controller_not_admitted']);
        app()->instance(RerankerSidecarService::class, $sidecar);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('process');
        $results = $this->results();
        $this->assertSame($results, (new RerankerService($ai))->rerank('query', $results, 'cross_encoder'));
    }

    public function test_dedicated_postdispatch_unknown_stops_without_fallback(): void
    {
        $stop = new InferenceAdmissionException('native outcome unknown');
        $sidecar = Mockery::mock(RerankerSidecarService::class);
        $sidecar->shouldReceive('rerankControlled')->once()->andThrow($stop);
        app()->instance(RerankerSidecarService::class, $sidecar);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('process');
        try {
            (new RerankerService($ai))->rerank('query', $this->results(), 'cross_encoder');
            $this->fail('Unknown work must remain held.');
        } catch (InferenceAdmissionException $observed) {
            $this->assertSame($stop, $observed);
        }
    }

    public function test_dedicated_wire_cannot_use_unmanaged_transport(): void
    {
        $resources = Mockery::mock(ModelHostControllerResourcePolicyService::class);
        $resources->shouldReceive('managedProvider')->once()->with('http://fixture/rerank')->andReturnNull();
        $selection = Mockery::mock(ModelHostControllerAutomaticSelectionService::class);
        $selection->shouldNotReceive('beginExact');
        $supervisor = new ModelHostControllerTransportSupervisor($selection, $resources, Mockery::mock(ModelHostControllerWaitService::class));
        $called = false;
        try {
            $supervisor->run('rerank', 'http://fixture/rerank', [], function () use (&$called): void {
                $called = true;
            });
            $this->fail('Unmanaged dedicated inference was dispatched.');
        } catch (InferenceAdmissionException) {
            $this->assertFalse($called);
        }
    }

    private function results(): array
    {
        return [['document' => (object) ['title' => 'first', 'content' => "  José\r\nCritical tail  "], 'similarity' => 0.9],
            ['document' => (object) ['title' => 'second', 'content' => 'Complete second source'], 'similarity' => 0.1]];
    }
}
