<?php

namespace Tests\Feature\Scheduler;

use App\Console\Commands\GenealogyWeakSourceAddCleanupCommand;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\Ops\OpsRuntimeDiagnosticsService;
use App\Services\ReviewTypeRegistryService;
use App\Services\ScheduledJobService;
use App\Services\SystemConfigService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ScheduledUnknownPhysicalClosureTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    private Manager $database;

    private ScheduledJobService $service;

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
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('NOW', fn () => date('Y-m-d H:i:s'));
        $pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
        DB::statement('CREATE TABLE scheduled_jobs (id INTEGER PRIMARY KEY, name TEXT, job_type TEXT, command TEXT, enabled INTEGER, cron_expression TEXT, next_run_at TEXT, last_run_at TEXT, last_completed_at TEXT, last_run_status TEXT, running_count INTEGER, running_pids TEXT, last_pid INTEGER, max_parallel INTEGER, without_overlapping INTEGER, run_count INTEGER, fail_count INTEGER, last_run_output TEXT, updated_at TEXT)');
        DB::statement('CREATE TABLE scheduled_job_runs (id INTEGER PRIMARY KEY, scheduled_job_id INTEGER, status TEXT, started_at TEXT, completed_at TEXT, pid INTEGER, worker_id TEXT, output TEXT, duration_seconds REAL, items_processed INTEGER)');
        DB::statement('CREATE TABLE task_custody_records (id INTEGER PRIMARY KEY, surface TEXT, surface_ref TEXT, owner_token TEXT, acquired_at TEXT, expires_at TEXT, released_at TEXT, outcome TEXT, result_envelope TEXT, notification_state TEXT, progress_note TEXT, updated_at TEXT)');
        DB::statement('CREATE TABLE system_configs (id INTEGER PRIMARY KEY, section TEXT, config_key TEXT, config_value TEXT, updated_at TEXT)');
        DB::statement('CREATE TABLE review_type_registry (id INTEGER PRIMARY KEY, name TEXT, source_table TEXT, source_connection TEXT, ignore_sql TEXT, updated_at TEXT)');
        DB::table('system_configs')->insert(['id' => 1, 'section' => 'fixture', 'config_key' => 'housekeeping',
            'config_value' => '{"enabled":true}', 'updated_at' => '2020-01-01 00:00:00']);
        DB::table('review_type_registry')->insert(['id' => 1, 'name' => 'fixture_review', 'source_table' => 'fixture_items',
            'source_connection' => 'mysql', 'ignore_sql' => 'fixture scoped update', 'updated_at' => '2020-01-01 00:00:00']);
        $this->service = new ScheduledJobService;
        DB::table('scheduled_jobs')->insert(['id' => 7, 'name' => 'fixture_db_housekeeping', 'job_type' => 'command',
            'command' => 'genealogy:weak-source-add-cleanup --tree=4 --execute --confirm', 'enabled' => 1, 'cron_expression' => '* * * * *',
            'next_run_at' => '2020-01-01 00:00:00', 'last_run_at' => '2020-01-01 00:00:00',
            'last_completed_at' => '2019-12-31 23:00:00', 'last_run_status' => 'running',
            'running_count' => 1, 'running_pids' => '[123]', 'last_pid' => 123, 'max_parallel' => 1,
            'without_overlapping' => 1, 'run_count' => 9, 'fail_count' => 1,
            'last_run_output' => "original full private output\0tail", 'updated_at' => '2020-01-01 00:00:00']);
        DB::table('scheduled_job_runs')->insert(['id' => 81, 'scheduled_job_id' => 7, 'status' => 'running',
            'started_at' => '2020-01-01 00:00:00', 'completed_at' => null, 'pid' => 123,
            'output' => null, 'duration_seconds' => null, 'items_processed' => null]);
        DB::table('task_custody_records')->insert(['id' => 99, 'surface' => 'scheduled_job', 'surface_ref' => '7:81',
            'owner_token' => 'pid:123:start:444:run:81', 'acquired_at' => '2020-01-01 00:00:00',
            'expires_at' => '2020-01-01 00:45:00', 'released_at' => null, 'outcome' => null,
            'result_envelope' => null, 'notification_state' => null, 'progress_note' => 'scheduler_worker_claimed',
            'updated_at' => '2020-01-01 00:00:00']);
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

    private function hash(array $rows): string
    {
        $sort = function ($value) use (&$sort) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return hash('sha256', json_encode($sort($rows), JSON_THROW_ON_ERROR));
    }

    private function rows(): array
    {
        return ['job' => (array) DB::table('scheduled_jobs')->where('id', 7)->first(),
            'run' => (array) DB::table('scheduled_job_runs')->where('id', 81)->first(),
            'custody' => (array) DB::table('task_custody_records')->where('id', 99)->first()];
    }

    /** Synthetic producer for the trusted persisted proof boundary; no native/DB observer is run. */
    private function persistPhysicalProof(array $overrides = []): void
    {
        $rows = $this->rows();
        $proof = array_replace(['schema' => 'plos.scheduled_db_physical_terminal.v1', 'custody_id' => 99,
            'job_id' => 7, 'run_id' => 81, 'surface_ref' => '7:81', 'owner_token' => $rows['custody']['owner_token'],
            'command_sha256' => hash('sha256', $rows['job']['command']), 'domain_outcome' => 'unknown',
            'replay_authorized' => false, 'source_scope_receipt_sha256' => str_repeat('a', 64),
            'privileged_db_receipt_sha256' => str_repeat('b', 64),
            'original_custody_sha256' => $this->hash($rows['custody']), 'original_custody_row' => $rows['custody'],
            'original_job_sha256' => $this->hash($rows['job']), 'original_run_sha256' => $this->hash($rows['run'])], $overrides);
        DB::table('task_custody_records')->where('id', 99)->update(['released_at' => '2020-01-02 00:00:00',
            'progress_note' => 'scheduler_physical_terminal_verified', 'notification_state' => 'suppressed',
            'result_envelope' => json_encode(['physical_closure' => $proof], JSON_THROW_ON_ERROR)]);
    }

    public function test_absent_parent_age_or_released_marker_without_persisted_proof_cannot_close(): void
    {
        $before = $this->rows();
        $this->assertSame('held', $this->service->reconcilePhysicallyClosedRun(99)['status']);
        $this->assertSame($before, $this->rows());
        DB::table('task_custody_records')->where('id', 99)->update(['released_at' => '2020-01-02 00:00:00']);
        $released = $this->rows();
        $this->assertSame('held', $this->service->reconcilePhysicallyClosedRun(99)['status']);
        $this->assertSame($released, $this->rows());
    }

    public function test_preview_and_apply_keep_all_original_domain_bytes_metrics_and_schedule(): void
    {
        $this->persistPhysicalProof();
        $before = $this->rows();
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        $this->assertSame('dry_run_verified', $preview['status']);
        $this->assertSame($this->hash($before), $preview['snapshot_sha256']);
        $this->assertSame($before, $this->rows());
        $result = $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false);
        $this->assertSame('reconciled', $result['status']);
        $after = $this->rows();
        $this->assertSame('unknown', $after['run']['status']);
        $this->assertSame('unknown', $after['job']['last_run_status']);
        $this->assertSame(0, $after['job']['running_count']);
        $this->assertNull($after['job']['last_pid']);
        $this->assertNull($after['job']['running_pids']);
        $this->assertNull($after['custody']['outcome']);
        $this->assertSame($before['custody']['released_at'], $after['custody']['released_at']);
        foreach (['run_count', 'fail_count', 'last_completed_at', 'next_run_at', 'enabled', 'last_run_output'] as $field) {
            $this->assertSame($before['job'][$field], $after['job'][$field]);
        }
        foreach (['output', 'completed_at', 'items_processed', 'duration_seconds', 'started_at', 'pid'] as $field) {
            $this->assertSame($before['run'][$field], $after['run'][$field]);
        }
        $envelope = json_decode($after['custody']['result_envelope'], true);
        $this->assertSame($before, $envelope['scheduler_lifecycle_reconciliation']['original_rows']);
        $this->assertFalse($result['replay_authorized']);
        $this->assertSame('unknown', $result['domain_outcome']);
    }

    public static function badProofs(): array
    {
        return ['wrong run' => [['run_id' => 82]], 'wrong owner' => [['owner_token' => 'pid:124:start:444:run:81']],
            'wrong command' => [['command_sha256' => str_repeat('c', 64)]], 'domain success invented' => [['domain_outcome' => 'success']],
            'replay asserted' => [['replay_authorized' => true]], 'source evidence absent' => [['source_scope_receipt_sha256' => null]],
            'DB evidence absent' => [['privileged_db_receipt_sha256' => null]], 'wrong original run' => [['original_run_sha256' => str_repeat('d', 64)]],
            'wrong original job' => [['original_job_sha256' => str_repeat('e', 64)]], 'wrong original custody' => [['original_custody_sha256' => str_repeat('f', 64)]]];
    }

    #[DataProvider('badProofs')]
    public function test_mismatched_or_assertion_only_proof_is_held_without_mutation(array $overrides): void
    {
        $this->persistPhysicalProof($overrides);
        $before = $this->rows();
        $this->assertSame('held', $this->service->reconcilePhysicallyClosedRun(99)['status']);
        $this->assertSame($before, $this->rows());
    }

    public function test_apply_requires_fresh_full_snapshot_and_refuses_job_drift(): void
    {
        $this->persistPhysicalProof();
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        $this->assertSame('held', $this->service->reconcilePhysicallyClosedRun(99, null, false)['status']);
        DB::table('scheduled_jobs')->where('id', 7)->update(['fail_count' => 10]);
        $before = $this->rows();
        $result = $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false);
        $this->assertSame('scheduled_physical_closure_snapshot_changed', $result['reason_code']);
        $this->assertSame($before, $this->rows());
    }

    public function test_other_unproven_worker_is_not_removed(): void
    {
        DB::table('scheduled_job_runs')->insert(['id' => 82, 'scheduled_job_id' => 7, 'status' => 'running', 'pid' => 124]);
        $this->persistPhysicalProof();
        $before = $this->rows();
        $this->assertSame('scheduled_other_physical_slots_unqualified', $this->service->reconcilePhysicallyClosedRun(99)['reason_code']);
        $this->assertSame($before, $this->rows());
    }

    public function test_readback_corruption_rolls_back_all_lifecycle_rows(): void
    {
        $this->persistPhysicalProof();
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        $before = $this->rows();
        DB::statement("CREATE TRIGGER fixture_corrupt_status AFTER UPDATE OF status ON scheduled_job_runs BEGIN UPDATE scheduled_job_runs SET status='success' WHERE id=NEW.id; END");
        $this->assertSame('scheduled_physical_closure_outcome_unproven', $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false)['reason_code']);
        $this->assertSame($before, $this->rows());
    }

    public function test_unknown_is_not_automatic_replay_and_independent_job_remains_due(): void
    {
        $this->observeDueJobsOnSqlite();
        $this->persistPhysicalProof();
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false);
        $newJob = $this->rows()['job'];
        $newJob['id'] = 8;
        $newJob['name'] = 'independent_current_work';
        $newJob['last_run_status'] = 'success';
        DB::table('scheduled_jobs')->insert($newJob);
        $this->assertSame([8], array_map(fn ($job) => $job->id, $this->service->getDueJobs()));
        // A job-label edit cannot erase the retained UNKNOWN run admission hold.
        DB::table('scheduled_jobs')->where('id', 7)->update(['last_run_status' => 'success']);
        $this->assertSame([8], array_map(fn ($job) => $job->id, $this->service->getDueJobs()));
        $admission = new \ReflectionMethod(ScheduledJobService::class, 'assertScheduledCustodyAdmission');
        $this->expectException(InferenceAdmissionException::class);
        $admission->invoke($this->service, DB::table('scheduled_jobs')->where('id', 7)->first());
    }

    private function observeDueJobsOnSqlite(): void
    {
        $connection = DB::connection();
        $manager = Mockery::mock($this->database->getDatabaseManager())->makePartial();
        // Only the pre-existing MySQL-specific, observation-only cleanup reads
        // are stubbed. Actual due/admission queries still execute on SQLite.
        $manager->shouldReceive('select')->andReturnUsing(function ($sql, $bindings = []) use ($connection) {
            if (str_contains($sql, 'INTERVAL 5 MINUTE') || str_contains($sql, 'INTERVAL 10 MINUTE')
                || str_contains($sql, 'JSON_LENGTH(running_pids)')) {
                return [];
            }

            return $connection->select($sql, $bindings);
        });
        DB::swap($manager);
    }

    private function persistNewWorkCheckpoint(array $overrides = []): void
    {
        $envelope = json_decode($this->rows()['custody']['result_envelope'], true);
        $class = GenealogyWeakSourceAddCleanupCommand::class;
        $envelope['new_work_checkpoint'] = array_replace(['schema' => 'plos.scheduled_db_new_work_checkpoint.v1',
            'job_id' => 7, 'run_id' => 81, 'custody_id' => 99, 'command_sha256' => hash('sha256', $this->rows()['job']['command']),
            'current_candidate_count' => 0, 'operation_kind' => 'current_pending_idempotent_review_ignore',
            'domain_outcome' => 'unknown', 'historical_replay_authorized' => false, 'preview_receipt_sha256' => str_repeat('d', 64),
            'observed_at' => now()->toIso8601String(), 'policy_section' => 'fixture', 'policy_key' => 'housekeeping',
            'policy_row_sha256' => $this->hash((array) DB::table('system_configs')->first()), 'review_type' => 'fixture_review',
            'registry_row_sha256' => $this->hash((array) DB::table('review_type_registry')->first()),
            'command_class' => $class, 'source_classes_sha256' => array_combine(
                [$class, SystemConfigService::class, ReviewTypeRegistryService::class],
                array_map(fn ($dependency) => hash_file('sha256', (new \ReflectionClass($dependency))->getFileName()),
                    [$class, SystemConfigService::class, ReviewTypeRegistryService::class]))], $overrides);
        DB::table('task_custody_records')->where('id', 99)->update(['result_envelope' => json_encode($envelope, JSON_THROW_ON_ERROR)]);
    }

    public function test_trusted_empty_scope_checkpoint_allows_fresh_work_and_preserves_original_unknown(): void
    {
        $this->observeDueJobsOnSqlite();
        $this->persistPhysicalProof();
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false);
        $this->persistNewWorkCheckpoint();
        $before = $this->rows();
        $this->assertSame([7], array_map(fn ($job) => $job->id, $this->service->getDueJobs()));
        $this->assertSame($before, $this->rows());
        $this->assertSame('unknown', $this->rows()['run']['status']);
        $this->assertNull($this->rows()['custody']['outcome']);
    }

    public static function badCheckpoints(): array
    {
        return ['wrong command class' => [['command_class' => 'App\\Services\\SystemConfigService']], 'missing constructor dependencies' => [['source_classes_sha256' => [GenealogyWeakSourceAddCleanupCommand::class => hash_file('sha256', (new \ReflectionClass(GenealogyWeakSourceAddCleanupCommand::class))->getFileName())]]], 'nonempty scope' => [['current_candidate_count' => 1]], 'historical replay' => [['historical_replay_authorized' => true]],
            'wrong operation' => [['operation_kind' => 'arbitrary_remote_work']], 'missing preview' => [['preview_receipt_sha256' => null]],
            'future observation' => [['observed_at' => '2099-01-01T00:00:00Z']], 'before physical closure' => [['observed_at' => '2019-01-01T00:00:00Z']],
            'source drift' => [['source_classes_sha256' => [GenealogyWeakSourceAddCleanupCommand::class => str_repeat('c', 64)]]]];
    }

    #[DataProvider('badCheckpoints')]
    public function test_unqualified_current_scope_never_grants_new_work(array $overrides): void
    {
        $this->observeDueJobsOnSqlite();
        $this->persistPhysicalProof();
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false);
        $this->persistNewWorkCheckpoint($overrides);
        $before = $this->rows();
        $this->assertSame([], $this->service->getDueJobs());
        $this->assertSame($before, $this->rows());
    }

    public function test_changed_table_policy_or_old_run_payload_holds_fresh_work(): void
    {
        $this->observeDueJobsOnSqlite();
        $this->persistPhysicalProof();
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false);
        $this->persistNewWorkCheckpoint();
        DB::table('system_configs')->where('id', 1)->update(['config_value' => '{"enabled":false}']);
        $this->assertSame([], $this->service->getDueJobs());
        $this->persistNewWorkCheckpoint();
        DB::table('scheduled_job_runs')->where('id', 81)->update(['output' => 'unrecorded late mutation']);
        $this->assertSame([], $this->service->getDueJobs());
    }

    public function test_normal_completion_cannot_overwrite_original_unknown(): void
    {
        $this->persistPhysicalProof();
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false);
        $before = $this->rows();
        $manager = Mockery::mock($this->database->getDatabaseManager())->makePartial();
        $manager->shouldReceive('selectOne')->once()->with('SELECT * FROM scheduled_jobs WHERE id = ? FOR UPDATE', [7])
            ->andReturn((object) $before['job']);
        DB::swap($manager);
        try {
            $this->service->markJobCompleted(7, 81, true, 'late result', 1, 123, 3);
            $this->fail('UNKNOWN was overwritten by normal completion.');
        } catch (InferenceAdmissionException $error) {
            $this->assertStringContainsString('UNKNOWN outcome', $error->getMessage());
            $this->assertSame($before, $this->rows());
        }
    }

    public function test_migration_rollback_refuses_to_coerce_unknown_history(): void
    {
        DB::table('scheduled_job_runs')->where('id', 81)->update(['status' => 'unknown']);
        $before = $this->rows();
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_14_120000_allow_unknown_scheduled_physical_outcomes.php';
        try {
            $migration->down();
            $this->fail('UNKNOWN schema support was removed.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Resolve scheduled UNKNOWN history', $error->getMessage());
            $this->assertSame($before, $this->rows());
        }
    }

    public function test_diagnostics_counts_unknown_separately_from_failure_and_running(): void
    {
        $manager = Mockery::mock($this->database->getDatabaseManager())->makePartial();
        $manager->shouldReceive('select')->once()->with(Mockery::on(fn ($sql) => str_contains($sql, 'SELECT id, name, runtime_mode')), [60])
            ->andReturn([(object) ['id' => 7, 'name' => 'fixture_db_housekeeping', 'last_run_status' => 'unknown']]);
        DB::swap($manager);
        $method = new \ReflectionMethod(OpsRuntimeDiagnosticsService::class, 'buildTasksSection');
        $result = $method->invoke(new OpsRuntimeDiagnosticsService, 60);
        $this->assertSame(1, $result['counts']['unknown']);
        $this->assertSame(0, $result['counts']['failed']);
        $this->assertSame(0, $result['counts']['running']);
    }
}
