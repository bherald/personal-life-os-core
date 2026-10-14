<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Exceptions\ModelHostControllerUnavailableException;
use App\Services\AgentDSGovernanceService;
use App\Services\AgentEpisodicMemoryService;
use App\Services\AgentLoop\RunMemoryService;
use App\Services\AgentLoopService;
use App\Services\AgentSessionService;
use App\Services\AgentToolRegistryService;
use App\Services\SkillLoaderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentLoopAdmissionFailureReceiptTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public static function guardModes(): array
    {
        return ['ordinary_admission' => [false, 'typed'], 'execution_guard' => [true, 'typed'],
            'wrapped_admission' => [false, 'wrapped'], 'ordinary_failure' => [false, 'generic'],
            'raw_dispatched_controller' => [false, 'raw_controller'],
            'wrapped_dispatched_controller' => [false, 'wrapped_controller'],
            'clean_capacity_refusal' => [false, 'clean_capacity'], 'session_metadata' => [false, 'metadata']];
    }

    #[DataProvider('guardModes')]
    public function test_actual_execute_catch_retains_nested_typed_cause_in_result_and_log(bool $guarded, string $kind): void
    {
        Http::preventStrayRequests();
        Cache::setDefaultDriver('array');
        Log::spy();

        $skills = Mockery::mock(SkillLoaderService::class);
        $skills->shouldReceive('skillExists')->once()->with('log-analyst')->andReturn(true);
        $skills->shouldReceive('loadSkillSnapshot')->once()->andReturn(['frontmatter' => []]);
        $skills->shouldReceive('getVersionInfo')->once()->andReturn([]);
        $this->app->instance(SkillLoaderService::class, $skills);

        $tools = Mockery::mock(AgentToolRegistryService::class);
        $tools->shouldReceive('resolveToolContractForAgent')->once()->andReturn([
            'unresolved_declared_tools' => [], 'tools' => [],
        ]);
        $this->app->instance(AgentToolRegistryService::class, $tools);

        $governance = Mockery::mock(AgentDSGovernanceService::class);
        $governance->shouldReceive('maxDepthForContext')->once()->andReturn(3);
        $governance->shouldReceive('buildMarker')->once()->andReturn([]);
        $this->app->instance(AgentDSGovernanceService::class, $governance);

        $cause = new InferenceAdmissionException('not retained in diagnostics', controllerFailure: [
            'operation' => 'activate', 'reason_code' => 'lifecycle_gate_failed',
            'stage' => 'gate', 'http_status' => 409,
            'failed_checks' => ['task_exact', 'private host text'],
            'message' => 'secret response body',
        ]);
        $failure = match ($kind) {
            'wrapped' => new \RuntimeException('Controller admission did not complete.', 0, $cause),
            'raw_controller' => new ModelHostControllerUnavailableException('wait_deadline', true, 'capacity'),
            'wrapped_controller' => new \RuntimeException('Controller admission did not complete.', 0,
                new ModelHostControllerUnavailableException('wait_deadline', true, 'capacity')),
            'clean_capacity' => new ModelHostControllerUnavailableException('wait_deadline', false, 'capacity', capacityCustodyCleared: true),
            'generic' => new \RuntimeException('{"inference_admission_held":true}'),
            default => new InferenceAdmissionException('Controller admission did not complete.', 0, $cause),
        };
        $sessions = Mockery::mock(AgentSessionService::class);
        // Throw inside the real execute try block before any session persistence or model work.
        $loop = new AgentLoopService;
        $guard = function () use (&$guardCalls, $failure): void {
            if (++$guardCalls > 1) {
                throw $failure;
            }
        };
        $guardCalls = 0;
        $sessions->shouldReceive('findOrCreateSession')->once()->andReturnUsing(function ($user, $type, $sessionOptions) use ($loop, $guarded, $guard, $failure, $kind) {
            if ($kind === 'metadata') {
                $this->assertSame('complete original tail', $sessionOptions['context']['operator_note']);
                $this->assertSame('log-analyst', $sessionOptions['metadata']['skill']);
                $this->assertSame('caller-skill', $sessionOptions['metadata']['caller_metadata']['skill']);
                $this->assertSame(['job_id' => 7, 'run_id' => 81, 'parent_custody_id' => 99], $sessionOptions['metadata']['scheduled_execution']);
                $this->assertSame(['existing' => 'full original'], $sessionOptions['metadata']['caller_metadata']['caller_metadata']);
            }
            if ($guarded) {
                (new \ReflectionMethod($loop, 'assertExecutionGuard'))->invoke($loop, ['execution_guard' => $guard]);
            }
            throw $failure;
        });
        $sessions->shouldReceive('completeSession')->twice()->with('test-admission-receipt');
        $this->app->instance(AgentSessionService::class, $sessions);

        DB::shouldReceive('insert')->once()->andReturn(true);
        $episodes = Mockery::mock(AgentEpisodicMemoryService::class);
        $episodes->shouldReceive('distillRunEpisodes')->once()->andReturn(null);
        $this->app->instance(AgentEpisodicMemoryService::class, $episodes);
        $runMemory = Mockery::mock(RunMemoryService::class);
        $runMemory->shouldReceive('clear')->once()->with('test-admission-receipt');
        $this->app->instance(RunMemoryService::class, $runMemory);

        $result = $loop->execute('log-analyst', 'sanitized task', [
            'session_id' => 'test-admission-receipt', 'benchmark_mode' => true,
        ] + ($kind === 'metadata' ? ['context' => ['operator_note' => 'complete original tail'],
            'metadata' => ['skill' => 'caller-skill', 'caller_metadata' => ['existing' => 'full original'],
                'scheduled_execution' => ['job_id' => 7, 'run_id' => 81, 'parent_custody_id' => 99]]] : [])
            + ($guarded ? ['execution_guard' => $guard] : []));
        $this->assertSame($guarded, $result['execution_guard_held'] ?? false);
        $this->assertSame(! in_array($kind, ['generic', 'clean_capacity'], true), $result['inference_admission_held'] ?? false);

        $expected = ['operation' => 'activate', 'reason_code' => 'lifecycle_gate_failed',
            'stage' => 'gate', 'http_status' => 409, 'failed_checks' => ['task_exact']];
        $expected = match ($kind) {
            'generic' => [],
            'raw_controller', 'wrapped_controller', 'clean_capacity' => [
                'wait_reason' => 'wait_deadline', 'last_wait_reason' => 'capacity', 'dispatched' => $kind !== 'clean_capacity',
            ],
            default => $expected,
        };
        $this->assertFalse($result['success']);
        $this->assertSame($kind === 'clean_capacity', $result['controller_capacity_wait_expired']);
        $this->assertSame($failure->getMessage(), $result['error']);
        $this->assertSame($expected, $result['controller_failure'] ?? []);
        $this->assertStringNotContainsString('secret response body', json_encode($result));
        $this->assertStringNotContainsString('private host text', json_encode($result));
        Log::shouldHaveReceived('error')->once()->with('AgentLoop: Execution failed',
            Mockery::on(fn (array $context): bool => ($context['controller_failure'] ?? []) === $expected));
        $this->assertTrue(Cache::lock('agent_session_lock:test-admission-receipt')->get());
        Cache::lock('agent_session_lock:test-admission-receipt')->forceRelease();
    }
}
