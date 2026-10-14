<?php

namespace Tests\Feature\Scheduler;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\AgentLoopService;
use App\Services\AIService;
use App\Services\Custody\TaskCustodyService;
use App\Services\ScheduledJobService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ScheduledAgentLineageTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    private Manager $database;

    private array $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        Container::setInstance($container);
        $this->database = new Manager($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('log', new NullLogger);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        DB::statement('CREATE TABLE scheduled_jobs (id INTEGER PRIMARY KEY, name TEXT, job_type TEXT, command TEXT)');
        DB::statement('CREATE TABLE scheduled_job_runs (id INTEGER PRIMARY KEY, scheduled_job_id INTEGER, status TEXT, pid INTEGER, worker_id TEXT)');
        DB::statement('CREATE TABLE task_custody_records (id INTEGER PRIMARY KEY, surface TEXT, surface_ref TEXT, owner_token TEXT, released_at TEXT, outcome TEXT, result_envelope TEXT)');
        $this->context = ['job_id' => 7, 'run_id' => 81, 'pid' => getmypid(), 'worker_id' => 'synthetic-worker'];
        DB::table('scheduled_jobs')->insert(['id' => 7, 'name' => 'fixture_scheduled_agent', 'job_type' => 'agent_task', 'command' => 'ai-ops']);
        DB::table('scheduled_job_runs')->insert(['id' => 81, 'scheduled_job_id' => 7, 'status' => 'running', 'pid' => getmypid(), 'worker_id' => 'synthetic-worker']);
        DB::table('task_custody_records')->insert(['id' => 99, 'surface' => 'scheduled_job', 'surface_ref' => '7:81',
            'owner_token' => (new TaskCustodyService)->scheduledJobOwnerToken(getmypid(), 81),
            'released_at' => null, 'outcome' => null, 'result_envelope' => '{"existing_invocation_receipt":"retained"}']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->database->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function execute(array $context, array $params = []): bool
    {
        $output = '';

        return (new \ReflectionMethod(ScheduledJobService::class, 'runAgentTask'))->invokeArgs(new ScheduledJobService,
            ['ai-ops', json_encode(['task' => 'complete synthetic task', 'notify' => false] + $params), &$output,
                30, null, 'fixture_scheduled_agent', $context]);
    }

    public function test_generic_scheduled_agent_carries_owned_parent_and_preserves_custom_data(): void
    {
        $custom = ['operator_note' => 'complete original tail', 'scheduled_execution' => ['run_id' => 'caller-value'],
            'caller_metadata' => ['existing' => 'retained']];
        $parentBefore = (array) DB::table('task_custody_records')->first();
        $loop = Mockery::mock(AgentLoopService::class);
        $loop->shouldReceive('execute')->once()->with('ai-ops', 'complete synthetic task', Mockery::on(function ($options) use ($custom, $parentBefore): bool {
            $this->assertSame(['opaque' => ['original' => 'tail']], $options['context']);
            $this->assertSame($custom, $options['metadata']['caller_metadata']);
            $this->assertSame('complete original tail', $options['metadata']['operator_note']);
            $binding = $options['metadata']['scheduled_execution'];
            $this->assertSame(7, $binding['job_id']);
            $this->assertSame(81, $binding['run_id']);
            $this->assertSame(getmypid(), $binding['pid']);
            $this->assertSame(99, $binding['parent_custody_id']);
            $this->assertSame('7:81', $binding['parent_surface_ref']);
            $this->assertSame(hash('sha256', $parentBefore['owner_token']), $binding['parent_owner_sha256']);
            $this->assertArrayNotHasKey('owner_token', $binding);
            $options['execution_guard']();

            return true;
        }))->andReturn(['success' => true, 'response' => 'synthetic complete']);
        Container::getInstance()->instance(AgentLoopService::class, $loop);
        $this->assertTrue($this->execute($this->context, ['metadata' => $custom, 'context' => ['opaque' => ['original' => 'tail']]]));
        $this->assertSame($parentBefore, (array) DB::table('task_custody_records')->first());
        $this->assertSame('running', DB::table('scheduled_job_runs')->value('status'));
    }

    public function test_unowned_or_mismatched_parent_blocks_agent_entry(): void
    {
        $loop = Mockery::mock(AgentLoopService::class);
        $loop->shouldNotReceive('execute');
        Container::getInstance()->instance(AgentLoopService::class, $loop);
        DB::table('task_custody_records')->where('id', 99)->update(['owner_token' => 'different-native-owner']);
        $before = (array) DB::table('task_custody_records')->first();
        try {
            $this->execute($this->context);
            $this->fail('Unowned parent entered the agent loop');
        } catch (InferenceAdmissionException) {
            $this->assertSame($before, (array) DB::table('task_custody_records')->first());
        }
    }

    public function test_owner_loss_stops_the_existing_actual_model_boundary(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('process');
        Container::getInstance()->instance(AIService::class, $ai);
        $loop = Mockery::mock(AgentLoopService::class);
        $loop->shouldReceive('execute')->once()->andReturnUsing(function ($skill, $task, $options): array {
            $options['execution_guard']();
            DB::table('task_custody_records')->where('id', 99)->update(['released_at' => '2020-01-01 00:00:00']);

            return (new \ReflectionMethod(AgentLoopService::class, 'processGuardedAI'))->invoke(new AgentLoopService, 'synthetic', [], $options);
        });
        Container::getInstance()->instance(AgentLoopService::class, $loop);
        $this->expectException(InferenceAdmissionException::class);
        $this->execute($this->context);
    }

    public function test_generic_agent_preserves_the_exact_existing_unknown_stop(): void
    {
        $stop = new InferenceAdmissionException('original synthetic UNKNOWN');
        $loop = Mockery::mock(AgentLoopService::class);
        $loop->shouldReceive('execute')->once()->andThrow($stop);
        Container::getInstance()->instance(AgentLoopService::class, $loop);
        $before = (array) DB::table('task_custody_records')->first();
        try {
            $this->execute($this->context);
            $this->fail('Original stop was replaced');
        } catch (InferenceAdmissionException $actual) {
            $this->assertSame($stop, $actual);
            $this->assertSame($before, (array) DB::table('task_custody_records')->first());
        }
    }
}
