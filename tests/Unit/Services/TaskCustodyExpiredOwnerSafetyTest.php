<?php

namespace Tests\Unit\Services;

use App\Services\Custody\TaskCustodyService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../app/Services/Custody/TaskCustodyService.php';

class TaskCustodyExpiredOwnerSafetyTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    private mixed $db;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $this->db = Mockery::mock();
        $container->instance('db', $this->db);
        $log = Mockery::mock();
        $log->shouldReceive('info')->zeroOrMoreTimes();
        $log->shouldReceive('warning')->zeroOrMoreTimes();
        $container->instance('log', $log);
        $redis = Mockery::mock();
        $redis->shouldReceive('exists')->never();
        $container->instance('redis', $redis);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->previousFacadeApplication);
            Container::setInstance($this->previousContainer);
        }
    }

    private function row(string $surface, string $owner): object
    {
        return (object) ['id' => 1, 'surface' => $surface, 'surface_ref' => 'synthetic-ref',
            'owner_token' => $owner, 'is_expired' => 1, 'expires_at' => '2000-01-01 00:00:00'];
    }

    public function test_expired_current_process_owner_is_not_preempted(): void
    {
        $service = new TaskCustodyService;
        $row = $this->row(TaskCustodyService::SURFACE_SCHEDULED_JOB, $service->scheduledJobOwnerToken(getmypid(), 1));
        $this->db->shouldReceive('selectOne')->once()->andReturn($row);
        $this->db->shouldReceive('update')->never();
        $this->db->shouldReceive('insert')->never();

        $this->assertTrue($service->hasLiveOwner($row));
        $this->assertNull($service->acquire($row->surface, $row->surface_ref, 'different-owner', 60));
    }

    public function test_unknown_owner_and_unprobeable_surface_are_retained(): void
    {
        $service = new TaskCustodyService;
        foreach (['pid:unknown:run:1', 'pid:0:run:1', 'malformed-owner'] as $owner) {
            $this->assertTrue($service->hasLiveOwner($this->row(TaskCustodyService::SURFACE_SCHEDULED_JOB, $owner)));
        }
        $row = $this->row(TaskCustodyService::SURFACE_HORIZON_JOB, 'synthetic-horizon');
        $this->db->shouldReceive('selectOne')->once()->andReturn($row);
        $this->db->shouldReceive('update')->never();
        $this->db->shouldReceive('insert')->never();
        $this->assertNull($service->acquire($row->surface, $row->surface_ref, 'different-owner', 60));
    }

    public function test_expired_same_owner_remains_idempotent_without_mutation(): void
    {
        $service = new TaskCustodyService;
        $row = $this->row(TaskCustodyService::SURFACE_SCHEDULED_JOB, 'pid:unknown:run:1');
        $this->db->shouldReceive('selectOne')->once()->andReturn($row);
        $this->db->shouldReceive('update')->never();
        $this->db->shouldReceive('insert')->never();
        $this->assertSame($row, $service->acquire($row->surface, $row->surface_ref, $row->owner_token, 60));
    }

    public function test_renewal_race_prevents_insert_after_dead_owner_observation(): void
    {
        $service = new class extends TaskCustodyService
        {
            public function hasLiveOwner(object $custody): bool
            {
                return false;
            }
        };
        $row = $this->row('synthetic_expiry_fixture', 'synthetic-dead-owner');
        $this->db->shouldReceive('selectOne')->once()->andReturn($row);
        $this->db->shouldReceive('update')->once()->withArgs(fn ($sql, $params) => str_contains($sql, 'owner_token = ?') && str_contains($sql, 'released_at IS NULL')
            && str_contains($sql, 'expires_at < NOW()') && $params[1] === $row->id && $params[2] === $row->owner_token
        )->andReturn(0);
        $this->db->shouldReceive('insert')->never();
        $this->assertNull($service->acquire($row->surface, $row->surface_ref, 'different-owner', 60));
    }

    /** Conditional SQL seam only; no registered surface qualifies automatic expiry recovery. */
    public function test_synthetic_expiry_fixture_preserves_conditional_sql_recovery(): void
    {
        $service = new class extends TaskCustodyService
        {
            public function hasLiveOwner(object $custody): bool
            {
                return false;
            }
        };
        $row = $this->row('synthetic_expiry_fixture', 'synthetic-dead-owner');
        $next = (object) ['id' => 2, 'owner_token' => 'different-owner'];
        $this->assertFalse($service->hasLiveOwner($row));
        $this->db->shouldReceive('selectOne')->twice()->andReturn($row, $next);
        $this->db->shouldReceive('update')->once()->withArgs(fn ($sql, $params) => str_contains($sql, 'owner_token = ?') && str_contains($sql, 'released_at IS NULL')
            && str_contains($sql, 'expires_at < NOW()') && $params[2] === $row->owner_token
        )->andReturn(1);
        $this->db->shouldReceive('insert')->once()->andReturnTrue();
        $pdo = Mockery::mock(\PDO::class);
        $pdo->shouldReceive('lastInsertId')->once()->andReturn('2');
        $this->db->shouldReceive('getPdo')->once()->andReturn($pdo);
        $this->assertSame($next, $service->acquire($row->surface, $row->surface_ref, 'different-owner', 60));
    }

    public function test_scheduled_missing_parent_cannot_release_expired_custody_in_sweep(): void
    {
        $row = $this->row(TaskCustodyService::SURFACE_SCHEDULED_JOB, 'pid:99999999:start:123:run:1');
        $this->db->shouldReceive('select')->once()->andReturn([$row]);
        $this->db->shouldReceive('update')->never();
        $this->db->shouldReceive('insert')->never();
        $this->assertTrue((new TaskCustodyService)->hasLiveOwner($row));
        $this->assertSame([], (new TaskCustodyService)->sweep());
    }

    public function test_scheduled_recycled_parent_cannot_release_or_reassign_custody(): void
    {
        $row = $this->row(TaskCustodyService::SURFACE_SCHEDULED_JOB, 'pid:'.getmypid().':start:0:run:1');
        $this->db->shouldReceive('selectOne')->once()->andReturn($row);
        $this->db->shouldReceive('update')->never();
        $this->db->shouldReceive('insert')->never();
        $this->assertTrue((new TaskCustodyService)->hasLiveOwner($row));
        $this->assertNull((new TaskCustodyService)->acquire($row->surface, $row->surface_ref, 'different-owner', 60));
    }

    public function test_owner_driven_scheduled_terminal_release_is_unchanged(): void
    {
        $row = $this->row(TaskCustodyService::SURFACE_SCHEDULED_JOB, 'pid:unknown:run:1');
        $row->released_at = null;
        $this->db->shouldReceive('selectOne')->once()->with('SELECT * FROM task_custody_records WHERE id = ?', [1])->andReturn($row);
        $this->db->shouldReceive('update')->once()->withArgs(fn ($sql, $params) => str_contains($sql, 'released_at IS NULL')
            && $params[0] === 'success' && json_decode($params[1], true) === ['complete' => true] && $params[2] === 1)->andReturn(1);
        $this->assertTrue((new TaskCustodyService)->release(1, 'success', ['complete' => true]));
    }

    public static function physicalUnknownSurfaces(): array
    {
        return ['MHC parent ended' => [TaskCustodyService::SURFACE_MHC08_K3_JOB, 'pid:99999999:start:123:run:1'],
            'agent cache absent or lost' => [TaskCustodyService::SURFACE_AGENT_SESSION, 'synthetic-agent']];
    }

    #[DataProvider('physicalUnknownSurfaces')]
    public function test_unknown_physical_work_is_never_swept_or_reassigned(string $surface, string $owner): void
    {
        $row = $this->row($surface, $owner);
        $this->db->shouldReceive('select')->once()->andReturn([$row]);
        $this->db->shouldReceive('selectOne')->once()->andReturn($row);
        $this->db->shouldReceive('update')->never();
        $this->db->shouldReceive('insert')->never();
        $service = new TaskCustodyService;
        $this->assertTrue($service->hasLiveOwner($row));
        $this->assertSame([], $service->sweep());
        $this->assertNull($service->acquire($surface, $row->surface_ref, 'new-owner', 60));
    }

    #[DataProvider('physicalUnknownSurfaces')]
    public function test_explicit_owner_terminal_release_still_works(string $surface, string $owner): void
    {
        $row = $this->row($surface, $owner);
        $row->released_at = null;
        $this->db->shouldReceive('selectOne')->once()->andReturn($row);
        $this->db->shouldReceive('update')->once()->withArgs(fn ($sql, $params) => str_contains($sql, 'released_at IS NULL')
            && $params[0] === 'success' && json_decode($params[1], true) === ['complete' => true])->andReturn(1);
        $this->assertTrue((new TaskCustodyService)->release(1, 'success', ['complete' => true]));
    }
}
