<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\GenealogyManagerCommand;
use App\Console\Commands\OpsCapacityUtilizationManagerCommand;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\Custody\TaskCustodyService;
use App\Services\Genealogy\GenealogyReviewPressureService;
use App\Services\ScheduledJobService;
use App\Services\SystemConfigService;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual assertion and manager boundaries; facade doubles perform no DB/runtime writes. */
class ManagerPreDispatchCustodyRefusalTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $container->instance('date', new DateFactory);
        $container->instance(TaskCustodyService::class, new TaskCustodyService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public static function cases(): array
    {
        $out = [];
        foreach ([GenealogyManagerCommand::class, OpsCapacityUtilizationManagerCommand::class] as $class) {
            foreach (['pre_dispatch', 'wrapped', 'post_dispatch', 'unknown', 'success', 'ordinary_failure'] as $mode) {
                $out[$class.' '.$mode] = [$class, $mode];
            }
        }

        return $out;
    }

    #[DataProvider('cases')]
    public function test_only_actual_pre_enrollment_refusal_becomes_blocked(string $class, string $mode): void
    {
        $service = $class === GenealogyManagerCommand::class
            ? Mockery::mock(ScheduledJobService::class)->makePartial() : new ScheduledJobService;
        if ($class === GenealogyManagerCommand::class) {
            $service->shouldReceive('getJobByName')->once()->with('held_child')
                ->andReturn((object) ['enabled' => 1]);
        }
        $db = Mockery::mock();
        $db->shouldNotReceive('update');
        $db->shouldNotReceive('insert');
        if (in_array($mode, ['pre_dispatch', 'wrapped', 'post_dispatch'], true)) {
            $db->shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
            $db->shouldReceive('selectOne')->once()->andReturn((object) ['id' => 7]);
            $db->shouldReceive('select')->twice()->andReturn(
                [(object) ['surface_ref' => '7:81', 'is_expired' => 1]], []
            );
        }
        Container::getInstance()->instance('db', $db);
        $artisan = Mockery::mock();
        $artisan->shouldReceive('call')->with('scheduler:run', ['--job' => 'held_child', '--force' => true])
            ->once()->andReturnUsing(function () use ($service, $mode) {
                if ($mode === 'success' || $mode === 'ordinary_failure') {
                    return $mode === 'success' ? 0 : 1;
                }
                if ($mode === 'unknown') {
                    throw new InferenceAdmissionException('Scheduled work custody is unproven; admission held.', 0,
                        new \RuntimeException('scheduled_work_custody_unproven'));
                }
                if ($mode === 'post_dispatch') {
                    $output = '';

                    return (new \ReflectionMethod(ScheduledJobService::class, 'runArtisanCommand'))->invokeArgs($service, ['fixture:child', &$output]);
                }
                try {
                    return $service->markJobStarted(7);
                } catch (InferenceAdmissionException $error) {
                    if ($mode === 'wrapped') {
                        throw new \RuntimeException('wrapped admission', 0, $error);
                    }
                    throw $error;
                }
            });
        if ($mode === 'post_dispatch') {
            $artisan->shouldReceive('call')->with('fixture:child', [], Mockery::any())->once()
                ->andReturnUsing(fn () => $service->markJobStarted(7));
        }
        if ($mode === 'success' || $mode === 'ordinary_failure') {
            $artisan->shouldReceive('output')->once()->andReturn('ordinary output');
        } else {
            $artisan->shouldNotReceive('output');
        }
        Container::getInstance()->instance(Kernel::class, $artisan);
        $manager = $class === GenealogyManagerCommand::class
            ? new $class($service, Mockery::mock(GenealogyReviewPressureService::class)) : new $class($service, new SystemConfigService);
        if (in_array($mode, ['wrapped', 'post_dispatch', 'unknown'], true)) {
            $this->expectException($mode === 'wrapped' ? \RuntimeException::class : InferenceAdmissionException::class);
        }
        $lane = (new \ReflectionMethod($manager, 'runLane'))->invoke($manager, ['job_name' => 'held_child']);
        $this->assertSame($mode === 'success', $lane['started']);
        $this->assertSame($mode === 'success' ? 'started' : 'blocked', $lane['decision']);
        $this->assertSame($mode === 'pre_dispatch' ? 'scheduled_work_custody_unproven'
            : ($mode === 'success' ? 'scheduler_run_completed' : 'scheduler_run_failed'), $lane['reason']);
        $this->assertFalse($lane['scheduler_run']['forced_sync']);
        $this->assertTrue($lane['scheduler_run']['respects_run_in_background']);
    }
}
