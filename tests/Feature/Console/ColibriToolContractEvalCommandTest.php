<?php

namespace Tests\Feature\Console;

use App\Services\ColibriResearchPolicyService;
use App\Services\ColibriResearchSidecarService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class ColibriToolContractEvalCommandTest extends TestCase
{
    public function test_plan_mode_lists_cases_without_model_network_or_dispatch(): void
    {
        $sidecar = Mockery::mock(ColibriResearchSidecarService::class);
        $sidecar->shouldNotReceive('evaluateModelToolContractCase');
        $this->app->instance(ColibriResearchSidecarService::class, $sidecar);

        $exit = Artisan::call('ai:colibri-tool-contract-eval', [
            '--pack' => ColibriResearchPolicyService::LOCAL_OFFLINE_PACK,
            '--max-cases' => 20,
            '--json' => true,
            '--compact' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('plan', $payload['status']);
        $this->assertSame(20, $payload['selected_case_count']);
        $this->assertFalse($payload['model_invoked']);
        $this->assertFalse($payload['network_performed']);
        $this->assertFalse($payload['tool_dispatch_performed']);
        $this->assertFalse($payload['writes_performed']);
        $this->assertFalse($payload['promotion_eligible']);
    }

    public function test_execute_runs_one_exact_synthetic_case_without_dispatch(): void
    {
        $sidecar = Mockery::mock(ColibriResearchSidecarService::class);
        $sidecar->shouldReceive('evaluateModelToolContractCase')
            ->once()
            ->with(
                'rag_read-42',
                'Read the first bounded window of local RAG document 42.',
                'rag__rag_read',
                ColibriResearchPolicyService::LOCAL_OFFLINE_PACK,
            )
            ->andReturn([
                'schema' => 'plos.colibri_model_tool_contract_case.v1',
                'case_id' => 'rag_read-42',
                'success' => true,
                'passed' => true,
                'tool_dispatch_performed' => false,
                'writes_performed' => false,
            ]);
        $this->app->instance(ColibriResearchSidecarService::class, $sidecar);

        $exit = Artisan::call('ai:colibri-tool-contract-eval', [
            '--pack' => ColibriResearchPolicyService::LOCAL_OFFLINE_PACK,
            '--case' => 'rag_read-42',
            '--execute' => true,
            '--fail-on-gaps' => true,
            '--json' => true,
            '--compact' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('evaluated_pass', $payload['status']);
        $this->assertSame(1, $payload['evaluated_case_count']);
        $this->assertSame(1, $payload['passed_count']);
        $this->assertTrue($payload['model_invoked']);
        $this->assertTrue($payload['network_performed']);
        $this->assertFalse($payload['tool_dispatch_performed']);
        $this->assertFalse($payload['writes_performed']);
        $this->assertFalse($payload['raw_prompts_or_model_outputs_included']);
        $this->assertFalse($payload['promotion_eligible']);
    }

    public function test_fail_on_gaps_fails_a_nonconforming_case(): void
    {
        $sidecar = Mockery::mock(ColibriResearchSidecarService::class);
        $sidecar->shouldReceive('evaluateModelToolContractCase')->once()->andReturn([
            'schema' => 'plos.colibri_model_tool_contract_case.v1',
            'case_id' => 'status-one',
            'success' => true,
            'passed' => false,
            'tool_dispatch_performed' => false,
            'writes_performed' => false,
        ]);
        $this->app->instance(ColibriResearchSidecarService::class, $sidecar);

        $exit = Artisan::call('ai:colibri-tool-contract-eval', [
            '--case' => 'status-one',
            '--execute' => true,
            '--fail-on-gaps' => true,
            '--json' => true,
            '--compact' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('evaluated_gap', $payload['status']);
        $this->assertSame(0, $payload['passed_count']);
        $this->assertFalse($payload['promotion_eligible']);
    }
}
