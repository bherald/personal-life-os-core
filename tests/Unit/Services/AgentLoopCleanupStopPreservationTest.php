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
use App\Services\MonitoringPreScreenService;
use App\Services\SkillLoaderService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentLoopCleanupStopPreservationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public static function secondaryFailures(): array
    {
        return ['typed_error_log' => ['typed', 'error_log'], 'wrapped_error_log' => ['wrapped', 'error_log'],
            'typed_episode_logging' => ['typed', 'episode_log'], 'wrapped_episode_error' => ['wrapped', 'episode_error'],
            'raw_dispatched_lock_throw' => ['controller', 'lock_throw'], 'wrapped_dispatched_lock_false' => ['wrapped_controller', 'lock_false'],
            'typed_memory_cleanup' => ['typed', 'memory_clear'], 'wrapped_session_cleanup' => ['wrapped', 'final_session'],
            'wrapped_error_lock_throw' => ['wrapped_error', 'lock_throw']];
    }

    #[DataProvider('secondaryFailures')]
    public function test_secondary_failure_cannot_replace_the_same_original_typed_stop(string $kind, string $fault): void
    {
        $cause = new InferenceAdmissionException('original synthetic stop', controllerFailure: ['reason_code' => 'lifecycle_gate_failed', 'failed_checks' => ['task_exact']]);
        $controller = new ModelHostControllerUnavailableException('wait_deadline', true, 'capacity');
        $original = match ($kind) {
            'wrapped' => new \RuntimeException('wrapped original', 0, $cause),
            'controller' => $controller,
            'wrapped_controller' => new \RuntimeException('wrapped controller', 0, $controller),
            'wrapped_error' => new \Error('wrapped error', 0, $cause),
            default => $cause,
        };
        $this->prepare($original, $fault);
        try {
            $this->execute();
            $this->fail('Secondary failure must preserve the original typed stop.');
        } catch (\Throwable $caught) {
            $this->assertSame($original, $caught);
            $this->assertTrue(InferenceAdmissionException::isAdmissionFailure($caught));
            $this->assertSame(InferenceAdmissionException::controllerFailureFrom($original), InferenceAdmissionException::controllerFailureFrom($caught));
        }
        Http::assertNothingSent();
    }

    public function test_clean_ordinary_failure_retains_failure_array_without_new_no_replay_marker(): void
    {
        $original = new \RuntimeException('ordinary qualified failure');
        $this->prepare($original);
        $result = $this->execute();
        $this->assertFalse($result['success']);
        $this->assertSame($original->getMessage(), $result['error']);
        $this->assertArrayNotHasKey('inference_admission_held', $result);
        $this->assertArrayNotHasKey('execution_guard_held', $result);
        Http::assertNothingSent();
    }

    public function test_plain_error_with_clean_cleanup_remains_the_exact_original_error(): void
    {
        $original = new \Error('ordinary error');
        $this->prepare($original);
        try {
            $this->execute();
            $this->fail('The ordinary Error must retain its existing thrown API.');
        } catch (\Throwable $caught) {
            $this->assertSame($original, $caught);
            $this->assertFalse(InferenceAdmissionException::isAdmissionFailure($caught));
        }
        Http::assertNothingSent();
    }

    public static function uncertainFailureCleanup(): array
    {
        return ['logger' => ['error_log'], 'owned_lock' => ['lock_throw'], 'unconfirmed_release' => ['lock_false']];
    }

    #[DataProvider('uncertainFailureCleanup')]
    public function test_secondary_failure_after_ordinary_error_becomes_genuine_typed_unknown(string $fault): void
    {
        $original = new \RuntimeException('ordinary qualified failure');
        $this->prepare($original, $fault);
        try {
            $this->execute();
            $this->fail('Uncertain failure cleanup cannot become an ordinary retry.');
        } catch (InferenceAdmissionException $stop) {
            $this->assertTrue(InferenceAdmissionException::isAdmissionFailure($stop));
            $this->assertSame($original, $stop->getPrevious());
            $this->assertStringNotContainsString('secondary-private', $stop->getMessage());
        }
        Http::assertNothingSent();
    }

    public static function prescreenCleanup(): array
    {
        return ['clean' => [''], 'throw' => ['lock_throw'], 'false' => ['lock_false'], 'memory' => ['memory_clear'], 'session' => ['final_session']];
    }

    #[DataProvider('prescreenCleanup')]
    public function test_actual_prescreen_return_requires_one_confirmed_owned_lock_release(string $fault): void
    {
        $this->prepare(null, $fault);
        if ($fault !== '') {
            $this->expectException(InferenceAdmissionException::class);
        }
        $result = $this->execute();
        $this->assertTrue($result['success']);
        $this->assertTrue($result['pre_screened']);
        $this->assertSame([], $result['tool_calls']);
        Http::assertNothingSent();
    }

    private function execute(): array
    {
        return (new AgentLoopService)->execute('log-analyst', 'sanitized synthetic task', ['session_id' => 'cleanup-stop-test', 'benchmark_mode' => true]);
    }

    private function prepare(?\Throwable $original, string $fault = ''): void
    {
        Http::preventStrayRequests();
        Cache::shouldReceive('has')->andReturn(true)->byDefault();
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $release = $lock->shouldReceive('release')->once();
        if ($fault === 'lock_throw') {
            $release->andThrow(new \RuntimeException('secondary-private lock failure'));
        } else {
            $release->andReturn($fault !== 'lock_false');
        }
        Cache::shouldReceive('lock')->once()->with('agent_session_lock:cleanup-stop-test', Mockery::type('int'))->andReturn($lock);
        foreach (['debug', 'warning', 'error', 'info'] as $method) {
            Log::shouldReceive($method)->andReturn(null)->byDefault();
        }
        if ($fault === 'error_log') {
            Log::shouldReceive('error')->once()->andThrow(new \RuntimeException('secondary-private logger failure'));
        } elseif ($fault === 'episode_log') {
            Log::shouldReceive('debug')->once()->andThrow(new \RuntimeException('secondary-private episode logger failure'));
        }
        $skills = Mockery::mock(SkillLoaderService::class);
        $skills->shouldReceive('skillExists')->once()->with('log-analyst')->andReturn(true);
        $skills->shouldReceive('loadSkillSnapshot')->once()->andReturn(['frontmatter' => []]);
        $skills->shouldReceive('getVersionInfo')->andReturn([])->byDefault();
        $skills->shouldReceive('loadSkill')->andReturn(['frontmatter' => [], 'body' => 'synthetic'])->byDefault();
        $this->app->instance(SkillLoaderService::class, $skills);
        $tools = Mockery::mock(AgentToolRegistryService::class);
        $tools->shouldReceive('resolveToolContractForAgent')->once()->andReturn(['unresolved_declared_tools' => [], 'tools' => []]);
        $tools->shouldNotReceive('executeTool');
        $this->app->instance(AgentToolRegistryService::class, $tools);
        $governance = Mockery::mock(AgentDSGovernanceService::class);
        $governance->shouldReceive('maxDepthForContext')->once()->andReturn(3);
        $governance->shouldReceive('buildMarker')->once()->andReturn([]);
        $this->app->instance(AgentDSGovernanceService::class, $governance);
        $sessions = Mockery::mock(AgentSessionService::class);
        $find = $sessions->shouldReceive('findOrCreateSession')->once();
        if ($original === null) {
            $find->andReturn(['session_id' => 'cleanup-stop-test']);
        } else {
            $find->andThrow($original);
        }
        $completions = $original instanceof \Error || in_array($fault, ['error_log', 'episode_log', 'episode_error'], true) ? 1 : 2;
        $complete = $sessions->shouldReceive('completeSession')->with('cleanup-stop-test')->times($completions);
        if ($fault === 'final_session') {
            $calls = 0;
            $complete->andReturnUsing(function () use (&$calls): bool {
                if (++$calls === 2) {
                    throw new \RuntimeException('secondary-private session failure');
                }

                return true;
            });
        } else {
            $complete->andReturn(true);
        }
        $this->app->instance(AgentSessionService::class, $sessions);
        if ($fault === 'episode_log') {
            DB::shouldReceive('insert')->andThrow(new \RuntimeException('secondary-private episode failure'));
        } elseif ($fault === 'episode_error') {
            DB::shouldReceive('insert')->andThrow(new \Error('secondary-private episode error'));
        } else {
            DB::shouldReceive('insert')->andReturn(true)->byDefault();
        }
        DB::shouldReceive('update')->andReturn(1)->byDefault();
        $episodes = Mockery::mock(AgentEpisodicMemoryService::class);
        $episodes->shouldReceive('distillRunEpisodes')->andReturn(null)->byDefault();
        $this->app->instance(AgentEpisodicMemoryService::class, $episodes);
        $runMemory = Mockery::mock(RunMemoryService::class);
        $runMemory->shouldReceive('start')->andReturn(null)->byDefault();
        $clear = $runMemory->shouldReceive('clear')->once()->with('cleanup-stop-test');
        if ($fault === 'memory_clear') {
            $clear->andThrow(new \RuntimeException('secondary-private memory cleanup'));
        } else {
            $clear->andReturn(null);
        }
        $this->app->instance(RunMemoryService::class, $runMemory);
        if ($original === null) {
            $preScreen = Mockery::mock(MonitoringPreScreenService::class);
            $preScreen->shouldReceive('preScreenLogAnalyst')->once()->andReturn(['checks' => ['synthetic' => 'healthy']]);
            $this->app->instance(MonitoringPreScreenService::class, $preScreen);
        }
    }
}
