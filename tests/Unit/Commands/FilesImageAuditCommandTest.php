<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\FilesImageAuditCommand;
use App\Services\FileRegistryImageAuditService;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class FilesImageAuditCommandTest extends TestCase
{
    private string $originalConnection;

    private array $policy;

    private array $aggregate;

    private array $sliceResult;

    private int $sliceCalls = 0;

    private int $policyCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(realpath(base_path('app/Console/Commands/FilesImageAuditCommand.php')), (new ReflectionClass(FilesImageAuditCommand::class))->getFileName());
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.image_audit_command_private' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('image_audit_command_private');
        DB::statement('CREATE TABLE scheduled_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE,
            command TEXT, enabled INTEGER, run_in_background INTEGER, without_overlapping INTEGER,
            max_parallel INTEGER, timeout_minutes INTEGER)');
        DB::table('scheduled_jobs')->insert(['name' => 'file_registry_image_audit', 'command' => 'files:image-audit --json',
            'enabled' => 1, 'run_in_background' => 1, 'without_overlapping' => 1, 'max_parallel' => 1, 'timeout_minutes' => 3]);
        $this->policy = config('file_registry.image_audit.maintenance_policy');
        $this->aggregate = ['state' => 'pending', 'reason' => null, 'registry_epoch_complete' => false,
            'media_epoch_complete' => false, 'counters' => ['registry' => ['attempts' => 0, 'inspections' => 0, 'findings' => 0, 'retries' => 0],
                'media' => ['attempts' => 0, 'inspections' => 0, 'findings' => 0, 'retries' => 0]], 'next_attempt_at' => null,
            'completion_scope' => 'captured_maximum_selector_ids'];
        $this->sliceResult = $this->aggregate + ['processed' => 2, 'findings' => 1, 'held' => 0];
        $audit = Mockery::mock(FileRegistryImageAuditService::class);
        $audit->shouldReceive('policy')->andReturnUsing(function () {
            $this->policyCalls++;

            return $this->policy;
        });
        $audit->shouldReceive('status')->andReturnUsing(fn () => $this->aggregate);
        $audit->shouldReceive('runSlice')->andReturnUsing(function () {
            $this->sliceCalls++;

            return $this->sliceResult;
        });
        $this->app->instance(FileRegistryImageAuditService::class, $audit);
        Process::fake();
        Process::preventStrayProcesses();
    }

    protected function tearDown(): void
    {
        DB::purge('image_audit_command_private');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_status_reads_only_adapter_aggregates_without_schedule_activation_or_worker(): void
    {
        DB::table('scheduled_jobs')->update(['enabled' => 0]);
        $before = DB::table('scheduled_jobs')->get()->all();
        $this->maintenance();
        [$exit, $report] = $this->runCommand(['--status' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame($this->aggregate['counters'], $report['counters']);
        $this->assertSame('pending', $report['state']);
        $this->assertSame('captured_maximum_selector_ids', $report['completion_scope']);
        $this->assertFalse($report['worker_started']);
        $this->assertSame(0, $this->policyCalls);
        $this->assertSame(0, $this->sliceCalls);
        $this->assertEquals($before, DB::table('scheduled_jobs')->get()->all());
        Process::assertNothingRan();
    }

    public function test_waiting_complete_busy_or_held_status_never_launches_an_idle_child(): void
    {
        foreach (['waiting', 'complete', 'busy', 'held'] as $state) {
            $this->aggregate['state'] = $state;
            [$exit, $report] = $this->runCommand();
            $this->assertSame($state === 'held' ? 1 : 0, $exit);
            $this->assertSame($state, $report['state']);
            $this->assertFalse($report['worker_started']);
        }
        $this->assertSame(0, $this->sliceCalls);
        Process::assertNothingRan();
    }

    public function test_parent_uses_registered_independently_bounded_child_and_safe_before_after(): void
    {
        Process::fake(function () {
            $this->aggregate['counters']['registry']['inspections'] = 2;

            return Process::result(output: '{"source_path":"private-photo","source_id":123,"sha256":"private-hash"}', errorOutput: 'private SQL and credentials');
        });
        [$exit, $report] = $this->runCommand();
        $this->assertSame(0, $exit);
        $this->assertSame('completed_slice', $report['state']);
        $this->assertTrue($report['worker_started']);
        $this->assertSame(0, $report['worker_exit_code']);
        $this->assertSame(0, $report['before']['counters']['registry']['inspections']);
        $this->assertSame(2, $report['after']['counters']['registry']['inspections']);
        $this->assertSame(0, $this->sliceCalls);
        Process::assertRan(fn ($process) => $process->timeout === 95 && ! $process->quietly && $process->path === base_path()
            && $process->command === [$this->policy['timeout_binary'], '--signal=KILL', '90s', PHP_BINARY,
                base_path('artisan'), 'files:image-audit', '--slice', '--json', '--no-interaction']);
        $this->assertStringNotContainsString('private-photo', Artisan::output());
        $this->assertStringNotContainsString('private SQL', Artisan::output());
        $this->assertStringNotContainsString('private-hash', Artisan::output());
    }

    public function test_internal_slice_repeats_admission_and_runs_one_adapter_slice_without_nesting_processes(): void
    {
        [$exit, $report] = $this->runCommand(['--slice' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame(1, $this->sliceCalls);
        $this->assertSame(2, $report['processed']);
        $this->assertSame(1, $report['findings']);
        $this->assertTrue($report['source_identifiers_withheld']);
        Process::assertNothingRan();
        DB::table('scheduled_jobs')->update(['enabled' => 0]);
        [$exit, $report] = $this->runCommand(['--slice' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame('audit_scheduler_admission_invalid', $report['reason']);
        $this->assertSame(1, $this->sliceCalls);
    }

    #[DataProvider('invalidSchedule')]
    public function test_manual_and_internal_admission_require_exact_enabled_registered_schedule(array $changes): void
    {
        DB::table('scheduled_jobs')->update($changes);
        foreach ([[], ['--slice' => true]] as $arguments) {
            [$exit, $report] = $this->runCommand($arguments);
            $this->assertSame(1, $exit);
            $this->assertSame('audit_scheduler_admission_invalid', $report['reason']);
        }
        $this->assertSame(0, $this->sliceCalls);
        Process::assertNothingRan();
    }

    public static function invalidSchedule(): array
    {
        return [[['enabled' => 0]], [['command' => 'files:image-audit --slice --json']], [['run_in_background' => 0]],
            [['without_overlapping' => 0]], [['max_parallel' => 2]], [['timeout_minutes' => 4]]];
    }

    public function test_timeout_ordering_and_hard_bounds_hold_before_any_inspection(): void
    {
        $original = $this->policy;
        foreach ([['max_seconds' => 90], ['hard_timeout_seconds' => 95], ['outer_timeout_seconds' => 180],
            ['lease_seconds' => 95], ['hard_timeout_seconds' => 241], ['hard_timeout_seconds' => '90']] as $changes) {
            $this->policy = array_replace($original, $changes);
            [$exit, $report] = $this->runCommand();
            $this->assertSame(1, $exit);
            $this->assertContains($report['reason'], ['audit_timeout_fence_invalid', 'invalid_audit_bounds']);
        }
        $this->policy = array_replace($original, ['hard_timeout_seconds' => 190, 'outer_timeout_seconds' => 200, 'lease_seconds' => 250]);
        [$exit, $report] = $this->runCommand();
        $this->assertSame(1, $exit);
        $this->assertSame('audit_timeout_fence_invalid', $report['reason']);
        $this->assertSame(0, $this->sliceCalls);
        Process::assertNothingRan();
    }

    public function test_missing_registration_and_private_adapter_exception_hold_without_inspection(): void
    {
        DB::table('scheduled_jobs')->delete();
        [$exit, $report] = $this->runCommand();
        $this->assertSame(1, $exit);
        $this->assertSame('audit_scheduler_admission_invalid', $report['reason']);
        $audit = Mockery::mock(FileRegistryImageAuditService::class);
        $audit->shouldReceive('status')->once()->andThrow(new \RuntimeException('SQL source_id=123 /private/photo.jpg private-uuid'));
        $audit->shouldNotReceive('policy', 'runSlice');
        $this->app->instance(FileRegistryImageAuditService::class, $audit);
        [$exit, $report] = $this->runCommand(['--status' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame('audit_admission_or_reporting_unavailable', $report['reason']);
        $this->assertStringNotContainsString('source_id', Artisan::output());
        $this->assertStringNotContainsString('/private/photo.jpg', Artisan::output());
        $this->assertStringNotContainsString('private-uuid', Artisan::output());
        $this->assertSame(0, $this->sliceCalls);
        Process::assertNothingRan();
    }

    public function test_missing_independent_timeout_and_maintenance_hold_without_force_bypass(): void
    {
        $this->policy['timeout_binary'] = base_path('tests/nonexistent-private-timeout-fixture');
        [$exit, $report] = $this->runCommand();
        $this->assertSame(1, $exit);
        $this->assertSame('independent_timeout_unavailable', $report['reason']);
        $this->maintenance();
        foreach ([[], ['--slice' => true]] as $arguments) {
            [$exit, $report] = $this->runCommand($arguments);
            $this->assertSame(1, $exit);
            $this->assertSame('maintenance_mode', $report['reason']);
        }
        $this->assertSame(0, $this->sliceCalls);
        Process::assertNothingRan();
    }

    public function test_hard_timeout_and_nonzero_worker_exit_are_truthful_and_hide_output(): void
    {
        foreach ([124, 137, 1] as $code) {
            Process::fake(['*' => Process::result(output: 'private-source', errorOutput: 'private-error', exitCode: $code)]);
            [$exit, $report] = $this->runCommand();
            $this->assertSame(1, $exit);
            $this->assertSame($code === 1 ? 'failed' : 'timed_out', $report['state']);
            $this->assertSame($code, $report['worker_exit_code']);
            $this->assertStringNotContainsString('private-source', Artisan::output());
            $this->assertStringNotContainsString('private-error', Artisan::output());
        }
    }

    public function test_parent_retains_closed_child_database_reason_and_codes_without_raw_fields(): void
    {
        Process::fake(['*' => Process::result(output: json_encode([
            'state' => 'held', 'reason' => 'image_audit_database_query_failed',
            'database_error' => ['sqlstate' => '23000', 'driver_code' => 1062, 'message' => 'private SQL'],
            'source_path' => '/private/photo.jpg', 'bindings' => ['private-binding'],
        ]), errorOutput: 'private stderr', exitCode: 1)]);
        [$exit, $report] = $this->runCommand();
        $this->assertSame(1, $exit);
        $this->assertSame('failed', $report['state']);
        $this->assertSame('image_audit_database_query_failed', $report['reason']);
        $this->assertSame(['sqlstate' => '23000', 'driver_code' => 1062], $report['worker']['database_error']);
        $this->assertSame(1, $report['worker_exit_code']);
        $this->assertStringNotContainsString('private', Artisan::output());
        $this->assertSame(0, $this->sliceCalls);
    }

    #[DataProvider('nativeChildAggregates')]
    public function test_native_child_output_is_captured_without_exposing_raw_fields(array $child, int $expectedExit, string $expectedState): void
    {
        $payload = json_encode($child + ['source_path' => '/private/synthetic-photo.jpg'], JSON_THROW_ON_ERROR);
        Process::swap(new class($payload) extends Factory
        {
            public function __construct(private string $payload) {}

            public function newPendingProcess()
            {
                return new class($this, $this->payload) extends PendingProcess
                {
                    public function __construct(Factory $factory, private string $payload)
                    {
                        parent::__construct($factory);
                    }

                    protected function toSymfonyProcess(array|string|null $command)
                    {
                        // Retain the real adapter and timeout; replace only the business worker.
                        return parent::toSymfonyProcess([$command[0], '--signal=KILL', '5s', PHP_BINARY, '-r',
                            'fwrite(STDOUT, $argv[1]); fwrite(STDERR, "private synthetic stderr");', $this->payload]);
                    }
                };
            }
        });

        [$exit, $report] = $this->runCommand();
        $this->assertSame($expectedExit, $exit);
        $this->assertSame($expectedState, $report['state']);
        $this->assertSame(0, $report['worker_exit_code']);
        $this->assertSame($child['state'], $report['worker']['state']);
        $this->assertSame($child['reason'], $report['worker']['reason']);
        $this->assertSame('pending', $report['after']['state']);
        $this->assertStringNotContainsString('private', Artisan::output());
        $this->assertSame(0, $this->sliceCalls);
    }

    public static function nativeChildAggregates(): array
    {
        return [
            'successful child' => [['state' => 'progressed', 'reason' => null], 0, 'completed_slice'],
            'held child despite zero exit' => [['state' => 'held', 'reason' => 'image_audit_database_query_failed'], 1, 'failed'],
        ];
    }

    public function test_parent_refuses_a_success_exit_with_a_held_child_and_hides_unknown_reason(): void
    {
        Process::fake(['*' => Process::result(output: '{"state":"held","reason":"image_audit_database_query_failed"}')]);
        [$exit, $report] = $this->runCommand();
        $this->assertSame(1, $exit);
        $this->assertSame('failed', $report['state']);
        $this->assertSame('image_audit_database_query_failed', $report['reason']);
        Process::fake(['*' => Process::result(output: '{"state":"held","reason":"image_audit_private_secret"}', exitCode: 1)]);
        [$exit, $report] = $this->runCommand();
        $this->assertSame(1, $exit);
        $this->assertSame('worker_nonzero_exit', $report['reason']);
        $this->assertStringNotContainsString('private', Artisan::output());
        $this->assertSame(0, $this->sliceCalls);
    }

    public function test_slice_and_parent_omit_malformed_database_codes_and_hard_timeout_keeps_priority(): void
    {
        $this->sliceResult['state'] = 'held';
        $this->sliceResult['reason'] = 'image_audit_database_query_failed';
        $this->sliceResult['database_error'] = ['sqlstate' => 'private-value', 'driver_code' => -1, 'message' => 'private SQL'];
        [$exit, $report] = $this->runCommand(['--slice' => true]);
        $this->assertSame(1, $exit);
        $this->assertArrayNotHasKey('database_error', $report);
        $this->assertStringNotContainsString('private', Artisan::output());
        Process::fake(['*' => Process::result(output: '{"state":"held","reason":"image_audit_database_query_failed","database_error":{"sqlstate":"23000","driver_code":1062}}', exitCode: 137)]);
        [$exit, $report] = $this->runCommand();
        $this->assertSame('timed_out', $report['state']);
        $this->assertSame('worker_hard_timeout', $report['reason']);
        $this->assertSame(137, $report['worker_exit_code']);
    }

    public function test_outer_process_timeout_does_not_echo_private_exception_or_claim_success(): void
    {
        Process::fake(function () {
            $process = new SymfonyProcess(['private-source-command']);
            $process->setTimeout(95);
            throw new ProcessTimedOutException(new SymfonyTimeoutException($process, SymfonyTimeoutException::TYPE_GENERAL), Process::result(errorOutput: 'private failure'));
        });
        [$exit, $report] = $this->runCommand();
        $this->assertSame(1, $exit);
        $this->assertSame('timed_out', $report['state']);
        $this->assertSame('worker_hard_timeout', $report['reason']);
        $this->assertStringNotContainsString('private-source-command', Artisan::output());
        $this->assertStringNotContainsString('private failure', Artisan::output());
    }

    public function test_adapter_private_fields_are_withheld_and_raw_exception_reasons_are_sanitized(): void
    {
        $this->aggregate += ['path' => 'private-path', 'source_id' => 123, 'uuid' => 'private-uuid', 'hash' => 'private-hash',
            'binding' => ['root' => 'private-root'], 'cursor' => 123];
        $this->aggregate['counters']['source_id'] = 123;
        [$exit, $report] = $this->runCommand(['--status' => true]);
        $this->assertSame(0, $exit);
        $this->assertArrayNotHasKey('source_id', $report['counters']);
        $this->assertStringNotContainsString('private-', Artisan::output());
        $this->aggregate['reason'] = 'SQL failure at /private/photo.jpg';
        [$exit, $report] = $this->runCommand(['--status' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame('audit_admission_or_reporting_unavailable', $report['reason']);
        $this->assertStringNotContainsString('/private/photo.jpg', Artisan::output());
        Process::assertNothingRan();
    }

    public function test_successful_child_cannot_mask_a_held_after_status_or_internal_slice(): void
    {
        Process::fake(function () {
            $this->aggregate['state'] = 'held';
            $this->aggregate['reason'] = 'image_audit_local_root_unavailable';

            return Process::result();
        });
        [$exit, $report] = $this->runCommand();
        $this->assertSame(1, $exit);
        $this->assertSame('held', $report['state']);
        $this->sliceResult['state'] = 'held';
        [$exit, $report] = $this->runCommand(['--slice' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame('held', $report['state']);
    }

    private function maintenance(): void
    {
        $maintenance = Mockery::mock(MaintenanceMode::class);
        $maintenance->shouldReceive('active')->andReturn(true);
        $this->app->instance(MaintenanceMode::class, $maintenance);
    }

    private function runCommand(array $arguments = []): array
    {
        $exit = Artisan::call('files:image-audit', $arguments + ['--json' => true]);

        return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
    }
}
