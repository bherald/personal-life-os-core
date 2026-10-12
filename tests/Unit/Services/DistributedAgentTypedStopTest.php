<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Exceptions\ModelHostControllerUnavailableException;
use App\Jobs\ProcessAgentTask;
use App\Services\AgentHandoffService;
use App\Services\AgentLoopService;
use App\Services\DistributedAgentService;
use App\Services\SystemConfigService;
use App\Support\AI\PoolExecutionReceipt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/** Real job/service conditional SQL, per-test SQLite, and fake model/queue boundaries. */
class DistributedAgentTypedStopTest extends TestCase
{
    private string $priorConnection;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->priorConnection = DB::getDefaultConnection();
        config()->set('database.connections.distributed_typed_stop_test', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('distributed_typed_stop_test');
        DB::connection()->getPdo()->sqliteCreateFunction('GREATEST', 'max');
        Queue::fake();
        Schema::create('distributed_agents', function (Blueprint $table): void {
            $table->id();
            $table->string('agent_id');
            $table->string('status')->default('online');
            $table->integer('current_load')->default(1);
            $table->integer('max_concurrent_tasks')->default(5);
            $table->integer('total_tasks_completed')->default(0);
            $table->integer('total_tasks_failed')->default(0);
            $table->float('avg_task_duration_ms')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('distributed_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('task_id');
            $table->string('task_type')->default('private-preparation');
            $table->text('payload')->nullable();
            $table->string('priority')->default('normal');
            $table->unsignedBigInteger('assigned_agent_id')->nullable();
            $table->string('status');
            $table->integer('retry_count')->default(0);
            $table->unsignedBigInteger('assignment_generation')->default(7);
            $table->integer('max_retries')->default(2);
            $table->text('result')->nullable();
            $table->text('error_message')->nullable();
            foreach (['assigned_at', 'started_at', 'completed_at', 'created_at', 'updated_at'] as $field) {
                $table->timestamp($field)->nullable();
            }
        });
        Schema::create('distributed_task_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_id');
        });
        Schema::create('distributed_task_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('task_id');
        });
        DB::table('distributed_agents')->insert(['id' => 1, 'agent_id' => 'owned-worker']);
        DB::table('distributed_tasks')->insert([
            'task_id' => 'owned-task', 'assigned_agent_id' => 1,
            'status' => DistributedAgentService::TASK_ASSIGNED,
            'payload' => json_encode(['handoff_id' => 'hnd_0123456789abcdef01234567']),
            'result' => json_encode(['existing_evidence' => ['dispatch_intent' => 'unreconciled']]),
        ]);
        $policy = Mockery::mock(SystemConfigService::class);
        $policy->shouldReceive('get')->with('agents.process_agent_task_job_runtime_policy', Mockery::type('array'))
            ->andReturnUsing(fn ($key, $default) => $default);
        $this->app->instance(SystemConfigService::class, $policy);
        $this->app->instance(DistributedAgentService::class, new DistributedAgentService);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->priorConnection);
        DB::purge('distributed_typed_stop_test');
        parent::tearDown();
    }

    public static function stops(): array
    {
        return ['admission' => [false, false], 'wrapped_admission' => [true, false],
            'uncertain_execution' => [false, true], 'wrapped_uncertain_execution' => [true, true]];
    }

    private function stop(bool $wrapped, bool $dispatched, array &$phases, int &$released): Throwable
    {
        if ($dispatched) {
            $receipt = new PoolExecutionReceipt('fixture-model', ['ollama_generate' => hash('sha256', 'http://fixture.invalid')],
                function ($from, $next) use (&$phases): void {
                    $phases[] = $next;
                },
                function () use (&$released): void {
                    $released++;
                });
            $receipt->beforeSend('ollama_generate', 'http://fixture.invalid', 'fixture-model');
            try {
                $receipt->stopReplayAfterFailure(new RuntimeException('lost acknowledgement'));
            } catch (InferenceAdmissionException $error) {
                return $wrapped ? new RuntimeException('wrapped original stop', 0, $error) : $error;
            }
        }
        $error = new InferenceAdmissionException('fixture admission held');

        return $wrapped ? new RuntimeException('wrapped original stop', 0, $error) : $error;
    }

    #[DataProvider('stops')]
    public function test_real_job_preserves_typed_stop_without_reassignment_or_queue_retry(bool $wrapped, bool $dispatched): void
    {
        $phases = [];
        $released = 0;
        $error = $this->stop($wrapped, $dispatched, $phases, $released);
        $loop = Mockery::mock(AgentLoopService::class);
        $loop->shouldReceive('execute')->once()->andThrow($error);
        $this->app->instance(AgentLoopService::class, $loop);
        $handoff = Mockery::mock(AgentHandoffService::class);
        $handoff->shouldReceive('syncDistributedTaskState')->once()->with(
            'hnd_0123456789abcdef01234567', 'owned-task', DistributedAgentService::TASK_RUNNING, 0, null, 7
        )->andReturnTrue();
        $handoff->shouldReceive('syncDistributedTaskState')->once()->with(
            'hnd_0123456789abcdef01234567', 'owned-task', DistributedAgentService::TASK_FAILED,
            Mockery::type('int'), $error->getMessage(), 7
        )->andReturnTrue();
        $this->app->instance(AgentHandoffService::class, $handoff);
        $job = (new ProcessAgentTask('owned-task', 'owned-worker', 0, 7))->withFakeQueueInteractions();
        $job->handle();
        $job->assertFailedWith($error);
        $task = DB::table('distributed_tasks')->first();
        $this->assertSame(DistributedAgentService::TASK_FAILED, $task->status);
        $this->assertSame(0, $task->retry_count);
        $this->assertSame(7, $task->assignment_generation);
        $this->assertSame(1, $task->assigned_agent_id);
        $this->assertSame($error->getMessage(), $task->error_message);
        $this->assertSame(['existing_evidence' => ['dispatch_intent' => 'unreconciled']], json_decode($task->result, true));
        $this->assertSame(0, DB::table('distributed_agents')->value('total_tasks_completed'));
        $this->assertSame(0, $released);
        $this->assertSame($dispatched ? ['pool_dispatched:1'] : [], $phases);
        Queue::assertNothingPushed();
        // Duplicate queue delivery and failure callback cannot execute or change the failed generation.
        $job->handle();
        $job->failed($error);
        $this->assertSame($task->error_message, DB::table('distributed_tasks')->value('error_message'));
    }

    #[DataProvider('stops')]
    public function test_service_rejects_explicit_retry_for_original_or_wrapped_stop(bool $wrapped, bool $dispatched): void
    {
        $phases = [];
        $released = 0;
        $error = $this->stop($wrapped, $dispatched, $phases, $released);
        $service = app(DistributedAgentService::class);
        $this->assertTrue($service->failTask('owned-task', $error, true, 'owned-worker', 0, 7));
        $this->assertSame(DistributedAgentService::TASK_FAILED, DB::table('distributed_tasks')->value('status'));
        $this->assertSame(0, DB::table('distributed_tasks')->value('retry_count'));
        $this->assertSame(7, DB::table('distributed_tasks')->value('assignment_generation'));
        Queue::assertNothingPushed();
    }

    public static function returnedFailures(): array
    {
        return ['guard_stop' => [['execution_guard_held' => true], true],
            'admission_stop' => [['inference_admission_held' => true], true],
            'both_stops' => [['execution_guard_held' => true, 'inference_admission_held' => true], true],
            'capacity_never_exempts_admission' => [['inference_admission_held' => true, 'controller_capacity_wait_expired' => true], true],
            'capacity_never_exempts_guard' => [['execution_guard_held' => true, 'controller_capacity_wait_expired' => true], true],
            'false' => [['execution_guard_held' => false, 'inference_admission_held' => false], false],
            'null' => [['execution_guard_held' => null, 'inference_admission_held' => null], false],
            'string_true' => [['execution_guard_held' => 'true', 'inference_admission_held' => 'true'], false],
            'integer_one' => [['execution_guard_held' => 1, 'inference_admission_held' => 1], false],
            'model_output_marker' => [['output' => ['inference_admission_held' => true]], false],
            'diagnostic_only' => [['controller_failure' => ['reason_code' => 'lifecycle_gate_failed']], false],
            'no_marker' => [[], false]];
    }

    #[DataProvider('returnedFailures')]
    public function test_only_strict_outer_service_receipt_stops_returned_failure_retry(array $markers, bool $held): void
    {
        $loop = Mockery::mock(AgentLoopService::class);
        $loop->shouldReceive('execute')->once()->andReturn(['success' => false,
            'error' => 'InferenceAdmissionException custody unknown'] + $markers);
        $this->app->instance(AgentLoopService::class, $loop);
        $handoff = Mockery::mock(AgentHandoffService::class);
        $handoff->shouldReceive('syncDistributedTaskState')->once()->with(
            'hnd_0123456789abcdef01234567', 'owned-task', DistributedAgentService::TASK_RUNNING, 0, null, 7
        )->andReturnTrue();
        $handoff->shouldReceive('syncDistributedTaskState')->once()->with(
            'hnd_0123456789abcdef01234567', 'owned-task',
            $held ? DistributedAgentService::TASK_FAILED : DistributedAgentService::TASK_PENDING,
            Mockery::type('int'), 'InferenceAdmissionException custody unknown', $held ? 7 : 8
        )->andReturnTrue();
        $this->app->instance(AgentHandoffService::class, $handoff);
        $job = (new ProcessAgentTask('owned-task', 'owned-worker', 0, 7))->withFakeQueueInteractions();
        $job->handle();
        $held ? $job->assertFailed() : $job->assertNotFailed();
        $this->assertSame($held ? DistributedAgentService::TASK_FAILED : DistributedAgentService::TASK_PENDING,
            DB::table('distributed_tasks')->value('status'));
        $this->assertSame($held ? 0 : 1, DB::table('distributed_tasks')->value('retry_count'));
        $this->assertSame($held ? 7 : 8, DB::table('distributed_tasks')->value('assignment_generation'));
        if ($held) {
            Queue::assertNothingPushed();
        }
    }

    public function test_job_stop_after_reassignment_cannot_change_foreign_generation_or_handoff(): void
    {
        $error = new InferenceAdmissionException('old execution held');
        $loop = Mockery::mock(AgentLoopService::class);
        $loop->shouldReceive('execute')->once()->andReturnUsing(function () use ($error): never {
            DB::table('distributed_tasks')->update(['assignment_generation' => 8, 'error_message' => 'new owner evidence']);
            throw $error;
        });
        $this->app->instance(AgentLoopService::class, $loop);
        $handoff = Mockery::mock(AgentHandoffService::class);
        $handoff->shouldReceive('syncDistributedTaskState')->once()->with(
            'hnd_0123456789abcdef01234567', 'owned-task', DistributedAgentService::TASK_RUNNING, 0, null, 7
        )->andReturnTrue();
        $this->app->instance(AgentHandoffService::class, $handoff);
        $job = (new ProcessAgentTask('owned-task', 'owned-worker', 0, 7))->withFakeQueueInteractions();
        $job->handle();
        $job->assertFailedWith($error);
        $this->assertSame(DistributedAgentService::TASK_RUNNING, DB::table('distributed_tasks')->value('status'));
        $this->assertSame(8, DB::table('distributed_tasks')->value('assignment_generation'));
        $this->assertSame('new owner evidence', DB::table('distributed_tasks')->value('error_message'));
        Queue::assertNothingPushed();
    }

    public static function bookkeepingFailures(): array
    {
        return ['typed_exception' => [false, false], 'logging_unavailable' => [false, true],
            'outer_receipt' => [true, false]];
    }

    #[DataProvider('bookkeepingFailures')]
    public function test_original_stop_survives_failed_bookkeeping_without_claiming_terminal_persistence(bool $returned, bool $logFailed): void
    {
        $error = new RuntimeException('outer original', 0, new InferenceAdmissionException('unknown custody'));
        $service = Mockery::mock(DistributedAgentService::class)->makePartial();
        $service->shouldReceive('failTask')->once()->with('owned-task', Mockery::on(fn ($cause): bool => $cause instanceof Throwable && InferenceAdmissionException::isAdmissionFailure($cause)), false, 'owned-worker', 0, 7)
            ->andThrow(new RuntimeException('fixture persistence unavailable'));
        $this->app->instance(DistributedAgentService::class, $service);
        $loop = Mockery::mock(AgentLoopService::class);
        if ($returned) {
            $loop->shouldReceive('execute')->once()->andReturn(['success' => false,
                'error' => $error->getMessage(), 'inference_admission_held' => true]);
        } else {
            $loop->shouldReceive('execute')->once()->andThrow($error);
        }
        if ($logFailed) {
            Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('fixture logging unavailable'));
        }
        $this->app->instance(AgentLoopService::class, $loop);
        $handoff = Mockery::mock(AgentHandoffService::class);
        $handoff->shouldReceive('syncDistributedTaskState')->once()->with(
            'hnd_0123456789abcdef01234567', 'owned-task', DistributedAgentService::TASK_RUNNING, 0, null, 7
        )->andReturnTrue();
        $this->app->instance(AgentHandoffService::class, $handoff);
        $job = (new ProcessAgentTask('owned-task', 'owned-worker', 0, 7))->withFakeQueueInteractions();
        $job->handle();
        $returned ? $job->assertFailedWith(InferenceAdmissionException::class) : $job->assertFailedWith($error);
        $this->assertSame(DistributedAgentService::TASK_RUNNING, DB::table('distributed_tasks')->value('status'));
        $this->assertNull(DB::table('distributed_tasks')->value('completed_at'));
        $this->assertSame(0, DB::table('distributed_tasks')->value('retry_count'));
        Queue::assertNothingPushed();
    }

    public function test_ordinary_job_exception_retains_existing_retry_and_rethrow(): void
    {
        $error = new RuntimeException('qualified predispatch fixture failure');
        $loop = Mockery::mock(AgentLoopService::class);
        $loop->shouldReceive('execute')->once()->andThrow($error);
        $this->app->instance(AgentLoopService::class, $loop);
        $handoff = Mockery::mock(AgentHandoffService::class);
        $handoff->shouldReceive('syncDistributedTaskState')->once()->with(
            'hnd_0123456789abcdef01234567', 'owned-task', DistributedAgentService::TASK_RUNNING, 0, null, 7
        )->andReturnTrue();
        $handoff->shouldReceive('syncDistributedTaskState')->once()->with(
            'hnd_0123456789abcdef01234567', 'owned-task', DistributedAgentService::TASK_PENDING,
            Mockery::type('int'), $error->getMessage(), 8
        )->andReturnTrue();
        $this->app->instance(AgentHandoffService::class, $handoff);
        $job = (new ProcessAgentTask('owned-task', 'owned-worker', 0, 7))->withFakeQueueInteractions();
        try {
            $job->handle();
            $this->fail('Ordinary failure was expected to rethrow.');
        } catch (RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }
        $job->assertNotFailed();
        $this->assertSame(DistributedAgentService::TASK_PENDING, DB::table('distributed_tasks')->value('status'));
        $this->assertSame(1, DB::table('distributed_tasks')->value('retry_count'));
        $this->assertSame(8, DB::table('distributed_tasks')->value('assignment_generation'));
    }

    public static function staleOwners(): array
    {
        return ['wrong_agent' => ['foreign-worker', 0, 7], 'old_attempt' => ['owned-worker', 1, 7],
            'old_generation' => ['owned-worker', 0, 6], 'missing_generation' => ['owned-worker', 0, null]];
    }

    #[DataProvider('staleOwners')]
    public function test_typed_stop_cannot_fail_foreign_or_stale_assignment(string $worker, int $attempt, ?int $generation): void
    {
        $this->assertFalse(app(DistributedAgentService::class)->failTask(
            'owned-task', new InferenceAdmissionException('held'), true, $worker, $attempt, $generation
        ));
        $this->assertSame(DistributedAgentService::TASK_ASSIGNED, DB::table('distributed_tasks')->value('status'));
        $this->assertSame(7, DB::table('distributed_tasks')->value('assignment_generation'));
        Queue::assertNothingPushed();
    }

    public static function controllerStops(): array
    {
        return ['raw_dispatched' => [false, true], 'wrapped_dispatched' => [true, true],
            'raw_no_dispatch' => [false, false], 'wrapped_no_dispatch' => [true, false]];
    }

    #[DataProvider('controllerStops')]
    public function test_real_controller_dispatch_flag_controls_service_replay_without_text_inference(bool $wrapped, bool $dispatched): void
    {
        $error = new ModelHostControllerUnavailableException('busy', dispatched: $dispatched);
        if ($wrapped) {
            $error = new RuntimeException('outer controller fixture', 0, $error);
        }
        $this->assertTrue(app(DistributedAgentService::class)->failTask('owned-task', $error, true, 'owned-worker', 0, 7));
        $this->assertSame($dispatched ? DistributedAgentService::TASK_FAILED : DistributedAgentService::TASK_PENDING,
            DB::table('distributed_tasks')->value('status'));
        $this->assertSame($dispatched ? 0 : 1, DB::table('distributed_tasks')->value('retry_count'));
        $this->assertSame($dispatched ? 7 : 8, DB::table('distributed_tasks')->value('assignment_generation'));
        if ($dispatched) {
            Queue::assertNothingPushed();
        }
    }

    #[DataProvider('controllerStops')]
    public function test_job_retains_exact_original_controller_failure_and_ordinary_no_send_retry(bool $wrapped, bool $dispatched): void
    {
        $error = new ModelHostControllerUnavailableException('busy', dispatched: $dispatched);
        if ($wrapped) {
            $error = new RuntimeException('outer controller fixture', 0, $error);
        }
        $loop = Mockery::mock(AgentLoopService::class);
        $loop->shouldReceive('execute')->once()->andThrow($error);
        $this->app->instance(AgentLoopService::class, $loop);
        $handoff = Mockery::mock(AgentHandoffService::class);
        $handoff->shouldReceive('syncDistributedTaskState')->once()->with(
            'hnd_0123456789abcdef01234567', 'owned-task', DistributedAgentService::TASK_RUNNING, 0, null, 7
        )->andReturnTrue();
        $handoff->shouldReceive('syncDistributedTaskState')->once()->with(
            'hnd_0123456789abcdef01234567', 'owned-task',
            $dispatched ? DistributedAgentService::TASK_FAILED : DistributedAgentService::TASK_PENDING,
            Mockery::type('int'), $error->getMessage(), $dispatched ? 7 : 8
        )->andReturnTrue();
        $this->app->instance(AgentHandoffService::class, $handoff);
        $job = (new ProcessAgentTask('owned-task', 'owned-worker', 0, 7))->withFakeQueueInteractions();
        try {
            $job->handle();
            $this->assertTrue($dispatched, 'Undispatched ordinary failure must retain its rethrow.');
        } catch (Throwable $caught) {
            $this->assertFalse($dispatched, 'Dispatched controller stop must not rethrow into queue retry.');
            $this->assertSame($error, $caught);
        }
        $dispatched ? $job->assertFailedWith($error) : $job->assertNotFailed();
        $this->assertSame($dispatched ? 7 : 8, DB::table('distributed_tasks')->value('assignment_generation'));
        if ($dispatched) {
            Queue::assertNothingPushed();
        }
    }

    public static function ordinaryFailures(): array
    {
        return ['ordinary_throwable' => [new RuntimeException('qualified predispatch fixture failure')],
            'qualified_clean_capacity_fallback' => [new ModelHostControllerUnavailableException('wait_deadline', false, 'capacity', capacityCustodyCleared: true)],
            'string_looks_like_stop' => ['InferenceAdmissionException custody unknown'],
            'throwable_looks_like_stop' => [new RuntimeException('InferenceAdmissionException custody unknown')]];
    }

    #[DataProvider('ordinaryFailures')]
    public function test_ordinary_failure_retry_uses_existing_budget_and_generation(string|Throwable $error): void
    {
        $this->assertTrue(app(DistributedAgentService::class)->failTask('owned-task', $error, true, 'owned-worker', 0, 7));
        $this->assertSame(DistributedAgentService::TASK_PENDING, DB::table('distributed_tasks')->value('status'));
        $this->assertSame(1, DB::table('distributed_tasks')->value('retry_count'));
        $this->assertSame(8, DB::table('distributed_tasks')->value('assignment_generation'));
        $this->assertNull(DB::table('distributed_tasks')->value('assigned_agent_id'));
        $this->assertNotSame(DistributedAgentService::TASK_COMPLETED, DB::table('distributed_tasks')->value('status'));
    }
}
