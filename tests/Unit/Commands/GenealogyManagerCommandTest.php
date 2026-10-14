<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\GenealogyManagerCommand;
use App\Services\Genealogy\GenealogyReviewPressureService;
use App\Services\ScheduledJobService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class GenealogyManagerCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_disabled_registered_job_waits_before_lane_trigger_or_scheduler_admission(): void
    {
        $service = Mockery::mock(ScheduledJobService::class);
        $service->shouldReceive('getJobByName')->once()->with('registered_lane')
            ->andReturn((object) ['enabled' => 0]);
        $command = new GenealogyManagerCommand($service,
            Mockery::mock(GenealogyReviewPressureService::class));
        $method = new \ReflectionMethod($command, 'evaluateLane');
        $lane = $method->invoke($command, ['name' => 'lane', 'job_name' => 'registered_lane',
            'enabled' => true, 'triggers' => ['queue_pending_min' => 1]], ['queue' => ['pending' => 100]]);
        $this->assertSame('waiting', $lane['decision']);
        $this->assertSame('scheduled_job_disabled', $lane['reason']);
        $this->assertSame([], $lane['triggers']);
        $this->assertFalse($lane['job']['enabled']);
    }

    public function test_job_disabled_after_selection_is_not_force_dispatched(): void
    {
        $service = Mockery::mock(ScheduledJobService::class);
        $service->shouldReceive('getJobByName')->once()->with('registered_lane')
            ->andReturn((object) ['enabled' => 0]);
        Artisan::shouldReceive('call')->never();
        $command = new GenealogyManagerCommand($service,
            Mockery::mock(GenealogyReviewPressureService::class));
        $method = new \ReflectionMethod($command, 'runLane');
        $lane = $method->invoke($command, ['job_name' => 'registered_lane', 'decision' => 'run',
            'job' => ['enabled' => true]]);
        $this->assertSame('waiting', $lane['decision']);
        $this->assertSame('scheduled_job_disabled', $lane['reason']);
        $this->assertFalse($lane['started']);
        $this->assertArrayNotHasKey('scheduler_run', $lane);
    }

    public function test_scheduler_handoff_respects_scheduled_job_background_policy(): void
    {
        $source = (string) file_get_contents(app_path('Console/Commands/GenealogyManagerCommand.php'));

        $this->assertStringContainsString("'forced_sync' => false", $source);
        $this->assertStringContainsString("'respects_run_in_background' => true", $source);
        $this->assertStringNotContainsString("'--sync' => true", $source);
    }

    public function test_dry_run_reports_table_driven_lane_eligibility_without_starting_jobs(): void
    {
        $service = Mockery::mock(ScheduledJobService::class);
        $service->shouldReceive('getJobByName')
            ->once()
            ->with('genealogy_supervisor')
            ->andReturn((object) [
                'enabled' => 1,
                'last_run_status' => 'success',
                'running_count' => 0,
                'max_parallel' => 1,
                'resource_profile' => 'db',
                'workload_family' => 'genealogy',
                'backlog_metric' => 'genealogy_supervisor',
                'last_run_at' => '2037-05-02 13:40:00',
                'last_completed_at' => '2037-05-02 13:40:03',
            ]);
        $service->shouldReceive('getJobByName')
            ->once()
            ->with('genealogy_agent_research_queue')
            ->andReturn((object) [
                'enabled' => 1,
                'last_run_status' => 'success',
                'running_count' => 1,
                'max_parallel' => 6,
                'resource_profile' => 'genealogy_llm',
                'workload_family' => 'genealogy',
                'backlog_metric' => 'genealogy_queue',
            ]);
        $service->shouldReceive('resolveMaxParallel')->once()->andReturn(6);
        $this->app->instance(ScheduledJobService::class, $service);

        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM genealogy_research_queue')
                && $bindings === [4])
            ->andReturn((object) [
                'pending' => 36,
                'in_progress' => 1,
                'completed' => 2007,
                'skipped' => 1,
                'failed' => 0,
                'total' => 2045,
            ]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql): bool => str_contains($sql, 'FROM agent_review_queue')
                && str_contains($sql, 'genealogy_review_packet'))
            ->andReturn((object) [
                'pending_total' => 66,
                'genealogy_finding' => 40,
                'genealogy_review_packet' => 26,
                'weak_source_add_findings' => 12,
            ]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM system_configs')
                && $bindings === ['faces', 'face_person_source_repair_plan'])
            ->andReturn((object) [
                'config_value' => json_encode([
                    'finding_type' => 'face_person_source_repair_plan',
                    'refill_policy' => ['max_pending_total' => 28],
                ], JSON_UNESCAPED_SLASHES),
            ]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM agent_review_queue')
                && $bindings === ['pending', 'genealogy_review_packet', 'face_person_source_repair_plan'])
            ->andReturn((object) ['pending' => 25]);
        $this->expectCoverageGapCounts(7, 3);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM system_configs')
                && $bindings === ['genealogy', 'supervisor_manager'])
            ->andReturn((object) [
                'config_value' => json_encode([
                    'enabled' => true,
                    'max_jobs_per_pass' => 2,
                    'lanes' => [[
                        'name' => 'genealogy_supervisor',
                        'job_name' => 'genealogy_supervisor',
                        'enabled' => true,
                        'min_minutes_between_runs' => 5,
                        'trigger_mode' => 'all',
                        'triggers' => [
                            'queue_pending_min' => 1,
                            'slot_job_name' => 'genealogy_agent_research_queue',
                            'slots_available_min' => 1,
                        ],
                    ]],
                ], JSON_UNESCAPED_SLASHES),
            ]);

        $this->travelTo('2037-05-02 13:52:00');
        $exitCode = Artisan::call('genealogy:manager', [
            '--tree' => 4,
            '--json' => true,
            '--compact' => true,
        ]);
        $this->travelBack();

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['execute']);
        $this->assertArrayNotHasKey('tree_id', $payload);
        $this->assertSame('single_tree', $payload['tree_scope']);
        $this->assertSame(36, $payload['queue']['pending']);
        $this->assertSame(12, $payload['review_backlog']['triage']['weak_source_add_findings']);
        $this->assertSame(25, $payload['face_repair']['pending_packets']);
        $this->assertSame(1, $payload['summary']['configured_lanes']);
        $this->assertSame(1, $payload['summary']['eligible_lanes']);
        $this->assertSame(0, $payload['summary']['started_lanes']);
        $this->assertSame('genealogy_supervisor', $payload['lanes'][0]['lane_label']);
        $this->assertArrayNotHasKey('name', $payload['lanes'][0]);
        $this->assertSame('run', $payload['lanes'][0]['decision']);
        $this->assertSame('eligible', $payload['lanes'][0]['reason']);
        $this->assertSame('queue_pending_min', $payload['lanes'][0]['triggers'][0]['trigger_label']);
        $this->assertSame('slots_available_min', $payload['lanes'][0]['triggers'][1]['trigger_label']);
        $this->assertArrayNotHasKey('name', $payload['lanes'][0]['triggers'][0]);
        $this->assertFalse($payload['safety']['canonical_writes']);
        $this->assertFalse($payload['safety']['direct_batch_apply']);
        $this->assertTrue($payload['safety']['guarded_source_media_capture']);
        $this->assertFalse($payload['safety']['face_identity_automation']);
        $this->assertTrue($payload['safety']['visible_chrome_capture_requires_operator']);
        $this->assertTrue($payload['safety']['scheduler_bookkeeping_required']);
    }

    public function test_missing_table_policy_blocks_manager_without_starting_jobs(): void
    {
        $service = Mockery::mock(ScheduledJobService::class);
        $service->shouldReceive('getJobByName')->never();
        $service->shouldReceive('resolveMaxParallel')->never();
        $this->app->instance(ScheduledJobService::class, $service);

        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM genealogy_research_queue')
                && $bindings === [4])
            ->andReturn((object) [
                'pending' => 0,
                'in_progress' => 0,
                'completed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'total' => 0,
            ]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql): bool => str_contains($sql, 'FROM agent_review_queue')
                && str_contains($sql, 'genealogy_review_packet'))
            ->andReturn((object) [
                'pending_total' => 0,
                'genealogy_finding' => 0,
                'genealogy_review_packet' => 0,
                'weak_source_add_findings' => 0,
            ]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM system_configs')
                && $bindings === ['faces', 'face_person_source_repair_plan'])
            ->andReturn(null);
        $this->expectCoverageGapCounts(0, 0);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM system_configs')
                && $bindings === ['genealogy', 'supervisor_manager'])
            ->andReturn(null);

        $exitCode = Artisan::call('genealogy:manager', [
            '--tree' => 4,
            '--json' => true,
            '--compact' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('warning', $payload['status']);
        $this->assertSame('genealogy_supervisor_manager_config_missing', $payload['config_error']);
        $this->assertSame(0, $payload['summary']['started_lanes']);
    }

    public function test_cooldown_active_lane_reports_whether_triggers_would_run_after_cooldown(): void
    {
        $service = Mockery::mock(ScheduledJobService::class);
        $service->shouldReceive('getJobByName')
            ->once()
            ->with('genealogy_supervisor')
            ->andReturn((object) [
                'enabled' => 1,
                'last_run_status' => 'success',
                'running_count' => 0,
                'max_parallel' => 1,
                'resource_profile' => 'db',
                'workload_family' => 'genealogy',
                'backlog_metric' => 'genealogy_queue',
                'last_run_at' => '2037-05-02 13:50:00',
                'last_completed_at' => '2037-05-02 13:50:03',
            ]);
        $service->shouldReceive('getJobByName')
            ->once()
            ->with('genealogy_agent_research_queue')
            ->andReturn((object) [
                'enabled' => 1,
                'last_run_status' => 'success',
                'running_count' => 0,
                'max_parallel' => 6,
                'resource_profile' => 'genealogy_llm',
                'workload_family' => 'genealogy',
                'backlog_metric' => 'genealogy_queue',
            ]);
        $service->shouldReceive('resolveMaxParallel')->once()->andReturn(6);
        $this->app->instance(ScheduledJobService::class, $service);

        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM genealogy_research_queue')
                && $bindings === [4])
            ->andReturn((object) [
                'pending' => 18,
                'in_progress' => 0,
                'completed' => 2045,
                'skipped' => 1,
                'failed' => 0,
                'total' => 2064,
            ]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql): bool => str_contains($sql, 'FROM agent_review_queue')
                && str_contains($sql, 'genealogy_review_packet'))
            ->andReturn((object) [
                'pending_total' => 8,
                'genealogy_finding' => 0,
                'genealogy_review_packet' => 8,
                'weak_source_add_findings' => 0,
            ]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM system_configs')
                && $bindings === ['faces', 'face_person_source_repair_plan'])
            ->andReturn(null);
        $this->expectCoverageGapCounts(0, 0);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM system_configs')
                && $bindings === ['genealogy', 'supervisor_manager'])
            ->andReturn((object) [
                'config_value' => json_encode([
                    'enabled' => true,
                    'max_jobs_per_pass' => 2,
                    'lanes' => [[
                        'name' => 'genealogy_supervisor_worker_drain',
                        'job_name' => 'genealogy_supervisor',
                        'enabled' => true,
                        'min_minutes_between_runs' => 5,
                        'trigger_mode' => 'all',
                        'triggers' => [
                            'queue_pending_min' => 1,
                            'slot_job_name' => 'genealogy_agent_research_queue',
                            'slots_available_min' => 1,
                        ],
                    ]],
                ], JSON_UNESCAPED_SLASHES),
            ]);

        $this->travelTo('2037-05-02 13:52:00');
        $exitCode = Artisan::call('genealogy:manager', [
            '--tree' => 4,
            '--json' => true,
            '--compact' => true,
        ]);
        $this->travelBack();

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $payload['summary']['eligible_lanes']);
        $this->assertSame(1, $payload['summary']['waiting_lanes']);
        $this->assertSame('cooldown_active', $payload['lanes'][0]['reason']);
        $this->assertSame(3, $payload['lanes'][0]['cooldown']['remaining_minutes']);
        $this->assertTrue($payload['lanes'][0]['would_run_after_cooldown']);
        $this->assertSame('all', $payload['lanes'][0]['trigger_mode']);
        $this->assertSame('queue_pending_min', $payload['lanes'][0]['triggers'][0]['trigger_label']);
        $this->assertTrue($payload['lanes'][0]['triggers'][0]['passed']);
        $this->assertSame('slots_available_min', $payload['lanes'][0]['triggers'][1]['trigger_label']);
        $this->assertTrue($payload['lanes'][0]['triggers'][1]['passed']);
    }

    public function test_queue_pending_max_can_wake_low_runway_from_table_policy(): void
    {
        $service = Mockery::mock(ScheduledJobService::class);
        $service->shouldReceive('getJobByName')
            ->once()
            ->with('genealogy_supervisor')
            ->andReturn((object) [
                'enabled' => 1,
                'last_run_status' => 'success',
                'running_count' => 0,
                'max_parallel' => 1,
                'resource_profile' => 'db',
                'workload_family' => 'genealogy',
                'backlog_metric' => 'genealogy_queue',
                'last_run_at' => '2037-05-02 13:40:00',
                'last_completed_at' => '2037-05-02 13:40:03',
            ]);
        $service->shouldReceive('getJobByName')
            ->once()
            ->with('genealogy_agent_research_queue')
            ->andReturn((object) [
                'enabled' => 1,
                'last_run_status' => 'success',
                'running_count' => 0,
                'max_parallel' => 6,
                'resource_profile' => 'genealogy_llm',
                'workload_family' => 'genealogy',
                'backlog_metric' => 'genealogy_queue',
            ]);
        $service->shouldReceive('resolveMaxParallel')->once()->andReturn(6);
        $this->app->instance(ScheduledJobService::class, $service);

        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM genealogy_research_queue')
                && $bindings === [4])
            ->andReturn((object) [
                'pending' => 0,
                'in_progress' => 0,
                'completed' => 2045,
                'skipped' => 1,
                'failed' => 0,
                'total' => 2046,
            ]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql): bool => str_contains($sql, 'FROM agent_review_queue')
                && str_contains($sql, 'genealogy_review_packet'))
            ->andReturn((object) [
                'pending_total' => 12,
                'genealogy_finding' => 4,
                'genealogy_review_packet' => 8,
                'weak_source_add_findings' => 0,
            ]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM system_configs')
                && $bindings === ['faces', 'face_person_source_repair_plan'])
            ->andReturn(null);
        $this->expectCoverageGapCounts(0, 0);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM system_configs')
                && $bindings === ['genealogy', 'supervisor_manager'])
            ->andReturn((object) [
                'config_value' => json_encode([
                    'enabled' => true,
                    'max_jobs_per_pass' => 2,
                    'lanes' => [[
                        'name' => 'genealogy_supervisor_low_queue_runway',
                        'job_name' => 'genealogy_supervisor',
                        'enabled' => true,
                        'min_minutes_between_runs' => 5,
                        'trigger_mode' => 'all',
                        'triggers' => [
                            'queue_pending_min' => 0,
                            'queue_pending_max' => 8,
                            'slot_job_name' => 'genealogy_agent_research_queue',
                            'slots_available_min' => 1,
                        ],
                    ]],
                ], JSON_UNESCAPED_SLASHES),
            ]);

        $this->travelTo('2037-05-02 13:52:00');
        $exitCode = Artisan::call('genealogy:manager', [
            '--tree' => 4,
            '--json' => true,
            '--compact' => true,
        ]);
        $this->travelBack();

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $triggers = [];
        foreach ($payload['lanes'][0]['triggers'] as $trigger) {
            $triggers[$trigger['trigger_label']] = $trigger;
        }

        $this->assertSame(0, $exitCode);
        $this->assertSame('run', $payload['lanes'][0]['decision']);
        $this->assertSame(0, $triggers['queue_pending_min']['observed']);
        $this->assertTrue($triggers['queue_pending_min']['passed']);
        $this->assertSame(8, $triggers['queue_pending_max']['configured']);
        $this->assertSame(0, $triggers['queue_pending_max']['observed']);
        $this->assertTrue($triggers['queue_pending_max']['passed']);
        $this->assertSame(6, $triggers['slots_available_min']['observed']);
    }

    public function test_worker_drain_lane_runs_when_pending_queue_exceeds_low_runway(): void
    {
        $service = Mockery::mock(ScheduledJobService::class);
        $service->shouldReceive('getJobByName')
            ->twice()
            ->with('genealogy_supervisor')
            ->andReturn((object) [
                'enabled' => 1,
                'last_run_status' => 'success',
                'running_count' => 0,
                'max_parallel' => 1,
                'resource_profile' => 'db',
                'workload_family' => 'genealogy',
                'backlog_metric' => 'genealogy_queue',
                'last_run_at' => '2037-05-02 13:40:00',
                'last_completed_at' => '2037-05-02 13:40:03',
            ]);
        $service->shouldReceive('getJobByName')
            ->twice()
            ->with('genealogy_agent_research_queue')
            ->andReturn((object) [
                'enabled' => 1,
                'last_run_status' => 'success',
                'running_count' => 0,
                'max_parallel' => 6,
                'resource_profile' => 'genealogy_llm',
                'workload_family' => 'genealogy',
                'backlog_metric' => 'genealogy_queue',
            ]);
        $service->shouldReceive('resolveMaxParallel')->twice()->andReturn(6);
        $this->app->instance(ScheduledJobService::class, $service);

        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM genealogy_research_queue')
                && $bindings === [4])
            ->andReturn((object) [
                'pending' => 20,
                'in_progress' => 0,
                'completed' => 2045,
                'skipped' => 1,
                'failed' => 0,
                'total' => 2066,
            ]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql): bool => str_contains($sql, 'FROM agent_review_queue')
                && str_contains($sql, 'genealogy_review_packet'))
            ->andReturn((object) [
                'pending_total' => 12,
                'genealogy_finding' => 4,
                'genealogy_review_packet' => 8,
                'weak_source_add_findings' => 0,
            ]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM system_configs')
                && $bindings === ['faces', 'face_person_source_repair_plan'])
            ->andReturn(null);
        $this->expectCoverageGapCounts(0, 0);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM system_configs')
                && $bindings === ['genealogy', 'supervisor_manager'])
            ->andReturn((object) [
                'config_value' => json_encode([
                    'enabled' => true,
                    'max_jobs_per_pass' => 2,
                    'lanes' => [
                        [
                            'name' => 'genealogy_supervisor',
                            'job_name' => 'genealogy_supervisor',
                            'enabled' => true,
                            'min_minutes_between_runs' => 3,
                            'trigger_mode' => 'all',
                            'triggers' => [
                                'queue_pending_min' => 0,
                                'queue_pending_max' => 8,
                                'slot_job_name' => 'genealogy_agent_research_queue',
                                'slots_available_min' => 1,
                            ],
                        ],
                        [
                            'name' => 'genealogy_supervisor_worker_drain',
                            'job_name' => 'genealogy_supervisor',
                            'enabled' => true,
                            'min_minutes_between_runs' => 3,
                            'trigger_mode' => 'all',
                            'triggers' => [
                                'queue_pending_min' => 1,
                                'slot_job_name' => 'genealogy_agent_research_queue',
                                'slots_available_min' => 1,
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES),
            ]);

        $this->travelTo('2037-05-02 13:52:00');
        $exitCode = Artisan::call('genealogy:manager', [
            '--tree' => 4,
            '--json' => true,
            '--compact' => true,
        ]);
        $this->travelBack();

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, $payload['summary']['configured_lanes']);
        $this->assertSame(1, $payload['summary']['eligible_lanes']);
        $this->assertSame(1, $payload['summary']['waiting_lanes']);
        $this->assertSame('genealogy_supervisor', $payload['lanes'][0]['lane_label']);
        $this->assertSame('waiting', $payload['lanes'][0]['decision']);
        $this->assertSame('triggers_not_met', $payload['lanes'][0]['reason']);
        $this->assertSame('genealogy_supervisor_worker_drain', $payload['lanes'][1]['lane_label']);
        $this->assertSame('run', $payload['lanes'][1]['decision']);
    }

    public function test_dry_run_reports_weak_source_add_cleanup_lane_eligibility(): void
    {
        $service = Mockery::mock(ScheduledJobService::class);
        $service->shouldReceive('getJobByName')
            ->twice()
            ->with('genealogy_weak_source_add_cleanup')
            ->andReturn((object) [
                'enabled' => 1,
                'last_run_status' => 'success',
                'running_count' => 0,
                'max_parallel' => 1,
                'resource_profile' => 'db',
                'workload_family' => 'genealogy',
                'backlog_metric' => 'genealogy_weak_source_add',
                'last_run_at' => '2037-05-02 13:00:00',
                'last_completed_at' => '2037-05-02 13:00:03',
            ]);
        $service->shouldReceive('resolveMaxParallel')->never();
        $this->app->instance(ScheduledJobService::class, $service);

        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM genealogy_research_queue')
                && $bindings === [4])
            ->andReturn((object) [
                'pending' => 30,
                'in_progress' => 5,
                'completed' => 3041,
                'skipped' => 1,
                'failed' => 0,
                'total' => 3077,
            ]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql): bool => str_contains($sql, 'weak_source_add_findings')
                && str_contains($sql, 'FROM agent_review_queue'))
            ->andReturn((object) [
                'pending_total' => 43,
                'genealogy_finding' => 10,
                'genealogy_review_packet' => 26,
                'weak_source_add_findings' => 12,
            ]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM system_configs')
                && $bindings === ['faces', 'face_person_source_repair_plan'])
            ->andReturn(null);
        $this->expectCoverageGapCounts(0, 0);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM system_configs')
                && $bindings === ['genealogy', 'supervisor_manager'])
            ->andReturn((object) [
                'config_value' => json_encode([
                    'enabled' => true,
                    'max_jobs_per_pass' => 2,
                    'lanes' => [[
                        'name' => 'genealogy_weak_source_add_cleanup',
                        'job_name' => 'genealogy_weak_source_add_cleanup',
                        'enabled' => true,
                        'min_minutes_between_runs' => 15,
                        'trigger_mode' => 'all',
                        'triggers' => [
                            'weak_source_add_min' => 10,
                            'stale_job_name' => 'genealogy_weak_source_add_cleanup',
                            'stale_minutes' => 15,
                        ],
                    ]],
                ], JSON_UNESCAPED_SLASHES),
            ]);

        $this->travelTo('2037-05-02 13:52:00');
        $exitCode = Artisan::call('genealogy:manager', [
            '--tree' => 4,
            '--json' => true,
            '--compact' => true,
        ]);
        $this->travelBack();

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $payload['summary']['configured_lanes']);
        $this->assertSame(1, $payload['summary']['eligible_lanes']);
        $this->assertSame('run', $payload['lanes'][0]['decision']);
        $this->assertSame('weak_source_add_min', $payload['lanes'][0]['triggers'][1]['trigger_label']);
        $this->assertSame(12, $payload['lanes'][0]['triggers'][1]['observed']);
        $this->assertTrue($payload['lanes'][0]['triggers'][1]['passed']);
    }

    private function expectCoverageGapCounts(int $faceRows, int $photoRows): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM file_registry_faces frf')
                && str_contains($sql, 'face_identity_provenance')
                && $bindings === [4, 4])
            ->andReturn((object) ['total' => $faceRows]);
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'FROM genealogy_media gm')
                && str_contains($sql, 'genealogy_person_media')
                && $bindings === [4])
            ->andReturn((object) ['total' => $photoRows]);
    }
}
