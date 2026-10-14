<?php

namespace Tests\Feature\Scheduler;

use App\Console\Commands\FilesDiscoveryDrainCommand;
use App\Console\Commands\GenealogyBackfillSourceMedia;
use App\Console\Commands\GenealogyManagerCommand;
use App\Console\Commands\GenealogyWeakSourceAddCleanupCommand;
use App\Console\Commands\OpsCapacityUtilizationManagerCommand;
use App\Console\Commands\SchedulerRunCommand;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Jobs\ExecuteFileRegistryScan;
use App\Jobs\RetireFileRegistryDerivedArtifacts;
use App\Services\Custody\TaskCustodyService;
use App\Services\FileCategorizationRAGService;
use App\Services\FileRegistryDiscoveryService;
use App\Services\FileRegistryDiscoveryStateService;
use App\Services\FileRegistryLifecycleService;
use App\Services\FileRegistryService;
use App\Services\Genealogy\GenealogyEvidenceAssetCaptureStorageService;
use App\Services\Genealogy\GenealogySemanticMemoryService;
use App\Services\Genealogy\NaraCatalogMediaCaptureService;
use App\Services\NextcloudFileApiService;
use App\Services\Ops\OpsRuntimeDiagnosticsService;
use App\Services\RAGService;
use App\Services\ReviewTypeRegistryService;
use App\Services\ScheduledJobService;
use App\Services\SystemConfigService;
use App\Services\ThumbnailService;
use Illuminate\Config\Repository;
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

    private function sourceHashes(array $classes): array
    {
        return array_combine($classes, array_map(fn ($class) => hash_file('sha256', (new \ReflectionClass($class))->getFileName()), $classes));
    }

    private function managerFixture(bool $historicalFailure = false, string $commandClass = GenealogyManagerCommand::class): array
    {
        $signature = (new \ReflectionClass($commandClass))->getDefaultProperties()['signature'];
        $command = preg_split('/\s+/', trim($signature))[0].' --execute --json --compact';
        DB::table('scheduled_jobs')->where('id', 7)->update(['name' => 'fixture_manager', 'command' => $command]);
        if ($historicalFailure) {
            DB::table('scheduled_jobs')->where('id', 7)->update(['last_run_status' => 'failed', 'running_count' => 0, 'last_pid' => null, 'running_pids' => '[]']);
            DB::table('scheduled_job_runs')->where('id', 81)->update(['status' => 'failed', 'completed_at' => '2020-01-01 00:45:00', 'output' => '[historical age label: not terminal evidence]']);
        }
        $child = $this->rows()['job'];
        foreach ([8 => 'fixture_child_held', 9 => 'fixture_child_ready'] as $id => $name) {
            DB::table('scheduled_jobs')->insert(array_replace($child, ['id' => $id, 'name' => $name, 'command' => 'fixture:child',
                'last_run_status' => $id === 8 ? 'failed' : 'success', 'running_count' => 0, 'last_pid' => null, 'running_pids' => '[]']));
        }
        DB::table('scheduled_job_runs')->insert(['id' => 90, 'scheduled_job_id' => 8, 'status' => 'failed',
            'started_at' => '2020-01-01 00:01:00', 'completed_at' => '2020-01-01 00:45:00', 'pid' => 124, 'output' => 'old unknown child label']);
        DB::table('task_custody_records')->insert(['id' => 200, 'surface' => 'scheduled_job', 'surface_ref' => '8:90',
            'owner_token' => 'pid:124:start:555:run:90', 'acquired_at' => '2020-01-01 00:01:00', 'expires_at' => '2020-01-01 00:46:00',
            'progress_note' => 'scheduler_worker_claimed', 'updated_at' => '2020-01-01 00:01:00']);
        [$section, $key] = explode('.', (new \ReflectionClass($commandClass))->getConstant('CONFIG_KEY'), 2);
        DB::table('system_configs')->where('id', 1)->update(['section' => $section, 'config_key' => $key,
            'config_value' => '{"enabled":true,"lanes":[{"job_name":"fixture_child_held"},{"job_name":"fixture_child_ready"}]}']);
        $binding = ['command_class' => $commandClass,
            'section' => $section, 'key' => $key, 'row_sha256' => $this->hash((array) DB::table('system_configs')->first()), 'job_ids' => [8, 9]];
        $namespace = (new \ReflectionMethod(ScheduledJobService::class, 'managerChildNamespace'))->invoke($this->service,
            DB::table('scheduled_jobs')->where('id', 7)->first(), $binding);
        $this->assertIsArray($namespace);
        $this->persistPhysicalProof(['schema' => 'plos.scheduled_manager_custody_transfer.v1', 'child_policy' => $binding,
            'child_namespace_sha256' => $this->hash($namespace),
            'retained_child_custody' => (new \ReflectionMethod(ScheduledJobService::class, 'retainedChildCustody'))->invoke($this->service, $namespace),
            'admission_contract_receipt_sha256' => str_repeat('e', 64)]);

        return $binding;
    }

    public static function parentStates(): array
    {
        return ['genea running' => [false, GenealogyManagerCommand::class], 'genea old failed label' => [true, GenealogyManagerCommand::class],
            'capacity running' => [false, OpsCapacityUtilizationManagerCommand::class],
            'capacity old failed label' => [true, OpsCapacityUtilizationManagerCommand::class]];
    }

    #[DataProvider('parentStates')]
    public function test_manager_parent_closure_retains_child_holds_and_every_historical_domain_byte(bool $failed, string $commandClass): void
    {
        $this->managerFixture($failed, $commandClass);
        $before = $this->rows();
        $childBefore = [(array) DB::table('scheduled_job_runs')->where('id', 90)->first(), (array) DB::table('task_custody_records')->where('id', 200)->first()];
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        $this->assertSame('dry_run_verified', $preview['status']);
        $this->assertSame($before, $this->rows());
        $this->assertSame('reconciled', $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false)['status']);
        $after = $this->rows();
        $this->assertSame(array_replace($before['run'], ['status' => 'unknown']), $after['run']);
        $this->assertSame($before['job']['run_count'], $after['job']['run_count']);
        $this->assertSame($before['job']['fail_count'], $after['job']['fail_count']);
        $this->assertSame($before['job']['last_run_output'], $after['job']['last_run_output']);
        $this->assertSame($before['job']['next_run_at'], $after['job']['next_run_at']);
        $this->assertNull($after['custody']['outcome']);
        $this->assertSame($childBefore, [(array) DB::table('scheduled_job_runs')->where('id', 90)->first(), (array) DB::table('task_custody_records')->where('id', 200)->first()]);
    }

    public function test_child_owner_or_registered_policy_drift_prevents_parent_closure(): void
    {
        $this->managerFixture();
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        DB::table('task_custody_records')->where('id', 200)->update(['owner_token' => 'pid:125:start:555:run:90']);
        $before = $this->rows();
        $this->assertSame('held', $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false)['status']);
        $this->assertSame($before, $this->rows());
        DB::table('task_custody_records')->where('id', 200)->update(['owner_token' => 'pid:124:start:555:run:90']);
        DB::table('system_configs')->where('id', 1)->update(['config_value' => '{"lanes":[{"job_name":"fixture_child_ready"}]}']);
        $this->assertSame('held', $this->service->reconcilePhysicallyClosedRun(99)['status']);
    }

    public function test_missing_child_custody_cannot_be_asserted_as_a_completed_transfer(): void
    {
        $this->managerFixture();
        DB::table('task_custody_records')->where('id', 200)->delete();
        // A failed label without custody would otherwise disappear from the
        // active query; the frozen complete namespace digest still refuses it.
        $this->assertSame('held', $this->service->reconcilePhysicallyClosedRun(99)['status']);
    }

    public function test_child_runtime_drift_requires_fresh_full_namespace_preview(): void
    {
        $this->managerFixture();
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        DB::table('scheduled_jobs')->where('id', 9)->update(['run_count' => 10]);
        $before = $this->rows();
        $this->assertSame('held', $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false)['status']);
        $this->assertSame($before, $this->rows());
    }

    private function persistSpecialCheckpoint(string $schema, string $kind, string $class, array $extra = []): void
    {
        $job = $this->rows()['job'];
        $definition = (new \ReflectionMethod(ScheduledJobService::class, 'scheduledDefinition'))->invoke($this->service, $job);
        $envelope = json_decode($this->rows()['custody']['result_envelope'], true);
        $classes = [$class];
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && str_starts_with($type->getName(), 'App\\')) {
                $classes[] = $type->getName();
            }
        }
        $classes = array_unique(array_merge($classes, $extra['required_classes'] ?? []));
        unset($extra['required_classes']);
        $envelope['new_work_checkpoint'] = array_replace(['schema' => $schema, 'job_id' => 7, 'run_id' => 81, 'custody_id' => 99,
            'command_sha256' => hash('sha256', $job['command']), 'job_definition_sha256' => $this->hash($definition),
            'operation_kind' => $kind, 'domain_outcome' => 'unknown', 'historical_replay_authorized' => false,
            'preview_receipt_sha256' => str_repeat('d', 64), 'observed_at' => now()->toIso8601String(),
            'command_class' => $class, 'source_classes_sha256' => $this->sourceHashes($classes)], $extra);
        DB::table('task_custody_records')->where('id', 99)->update(['result_envelope' => json_encode($envelope, JSON_THROW_ON_ERROR)]);
    }

    public function test_registered_child_checkpoint_allows_only_fresh_parent_admission_while_child_stays_held(): void
    {
        $binding = $this->managerFixture();
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        $this->assertSame('reconciled', $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false)['status']);
        $namespace = (new \ReflectionMethod(ScheduledJobService::class, 'managerChildNamespace'))->invoke($this->service, DB::table('scheduled_jobs')->where('id', 7)->first(), $binding);
        $definition = new \ReflectionMethod(ScheduledJobService::class, 'scheduledDefinition');
        $this->persistSpecialCheckpoint('plos.scheduled_registered_children_checkpoint.v1', 'current_registered_child_custody_admission',
            GenealogyManagerCommand::class, ['child_policy' => $binding,
                'admission_contract_receipt_sha256' => str_repeat('e', 64),
                'retained_child_custody' => (new \ReflectionMethod(ScheduledJobService::class, 'retainedChildCustody'))->invoke($this->service, $namespace),
                'child_definitions_sha256' => $this->hash(array_map(fn ($row) => $definition->invoke($this->service, $row), $namespace['jobs'])),
                'required_classes' => [SchedulerRunCommand::class, TaskCustodyService::class]]);
        $qualified = new \ReflectionMethod(ScheduledJobService::class, 'qualifiedUnknownNewWork');
        $this->assertTrue($qualified->invoke($this->service, DB::table('scheduled_jobs')->where('id', 7)->first(), 81));
        DB::table('scheduled_jobs')->where('id', 9)->update(['run_count' => 10, 'last_run_at' => '2020-01-02 00:00:00']);
        $this->assertTrue($qualified->invoke($this->service, DB::table('scheduled_jobs')->where('id', 7)->first(), 81));
        $admission = new \ReflectionMethod(ScheduledJobService::class, 'assertScheduledCustodyAdmission');
        $admission->invoke($this->service, DB::table('scheduled_jobs')->where('id', 7)->first());
        try {
            $admission->invoke($this->service, DB::table('scheduled_jobs')->where('id', 8)->first());
            $this->fail('Held child was admitted');
        } catch (InferenceAdmissionException $held) {
            $this->assertNull(DB::table('task_custody_records')->where('id', 200)->value('released_at'));
        }
        $originalChild = (array) DB::table('task_custody_records')->where('id', 200)->first();
        DB::table('task_custody_records')->where('id', 200)->delete();
        $this->assertFalse($qualified->invoke($this->service, DB::table('scheduled_jobs')->where('id', 7)->first(), 81));
        DB::table('task_custody_records')->insert($originalChild);
        DB::table('scheduled_jobs')->where('id', 9)->update(['command' => 'unqualified:replacement']);
        $this->assertFalse($qualified->invoke($this->service, DB::table('scheduled_jobs')->where('id', 7)->first(), 81));
    }

    public function test_capture_checkpoint_uses_its_actual_scope_and_never_the_review_ignore_kind(): void
    {
        DB::table('scheduled_jobs')->where('id', 7)->update(['command' => 'genealogy:backfill-source-media --mode=sources --tree=all --since=30d --limit=25 --confirm-download --confirm-storage-write']);
        $this->persistPhysicalProof();
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        $this->assertSame('reconciled', $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false)['status']);
        $extra = ['current_candidate_count' => 0, 'original_empty_scope_sha256' => str_repeat('a', 64),
            'required_classes' => [GenealogyEvidenceAssetCaptureStorageService::class,
                NaraCatalogMediaCaptureService::class, GenealogySemanticMemoryService::class,
                FileRegistryService::class, NextcloudFileApiService::class]];
        $this->persistSpecialCheckpoint('plos.scheduled_empty_capture_checkpoint.v1', 'current_empty_source_media_capture', GenealogyBackfillSourceMedia::class, $extra);
        $qualified = new \ReflectionMethod(ScheduledJobService::class, 'qualifiedUnknownNewWork');
        $this->assertTrue($qualified->invoke($this->service, DB::table('scheduled_jobs')->where('id', 7)->first(), 81));
        $this->persistSpecialCheckpoint('plos.scheduled_empty_capture_checkpoint.v1', 'current_pending_idempotent_review_ignore', GenealogyBackfillSourceMedia::class, $extra);
        $this->assertFalse($qualified->invoke($this->service, DB::table('scheduled_jobs')->where('id', 7)->first(), 81));
        $this->persistSpecialCheckpoint('plos.scheduled_empty_capture_checkpoint.v1', 'current_empty_source_media_capture', GenealogyBackfillSourceMedia::class, array_replace($extra, ['current_candidate_count' => 1]));
        $this->assertFalse($qualified->invoke($this->service, DB::table('scheduled_jobs')->where('id', 7)->first(), 81));
    }

    private function discoveryCheckpointFixture(array $extra = []): void
    {
        $config = new Repository(array_replace_recursive(Container::getInstance()->make('config')->all(), ['file_registry' => ['discovery' => ['worker_bounds' => ['max_jobs' => 5]]],
            'queue' => ['connections' => ['database' => ['driver' => 'database', 'table' => 'jobs', 'retry_after' => 600]],
                'failed' => ['database' => 'mysql', 'table' => 'failed_jobs']],
            'cache' => ['stores' => ['database' => ['driver' => 'database', 'lock_table' => 'cache_locks']]]]));
        Container::getInstance()->instance('config', $config);
        DB::table('scheduled_jobs')->where('id', 7)->update(['name' => 'file_registry_discovery_worker', 'command' => 'files:discovery-drain --json']);
        $this->persistPhysicalProof();
        $preview = $this->service->reconcilePhysicallyClosedRun(99);
        $this->assertSame('reconciled', $this->service->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false)['status']);
        $policyRows = [];
        foreach (['registry_discovery_worker_policy' => ['connection' => 'database', 'queue' => 'file-discovery', 'cache_store' => 'database'],
            'registry_discovery_retirement_policy' => ['connection' => 'database', 'queue' => 'file-discovery']] as $key => $value) {
            $id = DB::table('system_configs')->insertGetId(['section' => 'files', 'config_key' => $key,
                'config_value' => json_encode($value), 'updated_at' => '2020-01-01 00:00:00']);
            $policyRows[$key] = $this->hash((array) DB::table('system_configs')->where('id', $id)->first());
        }
        $backlog = ['pending' => 2, 'ready' => 2, 'reserved' => 0, 'delayed' => 0, 'failed' => 0];
        $this->persistSpecialCheckpoint('plos.scheduled_discovery_queue_checkpoint.v1', 'current_durable_discovery_queue', FilesDiscoveryDrainCommand::class,
            array_replace(['original_no_business_scope_sha256' => str_repeat('a', 64), 'policy_rows_sha256' => $policyRows,
                'runtime_configuration_sha256' => $this->hash(['worker_bounds' => $config->get('file_registry.discovery.worker_bounds'),
                    'queue_connection' => $config->get('queue.connections.database'), 'failed_queue' => $config->get('queue.failed'),
                    'cache_store' => $config->get('cache.stores.database')]),
                'admission_report' => ['state' => 'admitted', 'reason' => null, 'worker_started' => false, 'before' => $backlog, 'after' => $backlog],
                'required_classes' => [SystemConfigService::class, FileRegistryLifecycleService::class,
                    FileRegistryDiscoveryService::class, FileRegistryDiscoveryStateService::class,
                    FileRegistryService::class, NextcloudFileApiService::class, ThumbnailService::class,
                    FileCategorizationRAGService::class, RAGService::class,
                    ExecuteFileRegistryScan::class, RetireFileRegistryDerivedArtifacts::class]], $extra));
    }

    private function discoveryQualified(): bool
    {
        return (new \ReflectionMethod(ScheduledJobService::class, 'qualifiedUnknownNewWork'))
            ->invoke($this->service, DB::table('scheduled_jobs')->where('id', 7)->first(), 81);
    }

    public function test_discovery_checkpoint_admits_later_queue_work_and_preserves_original_unknown(): void
    {
        $this->discoveryCheckpointFixture();
        $before = $this->rows();
        $this->assertTrue($this->discoveryQualified());
        DB::table('scheduled_jobs')->where('id', 7)->update(['run_count' => 10, 'last_run_at' => '2020-01-02 00:00:00']);
        $this->assertTrue($this->discoveryQualified());
        $this->assertSame($before['run'], $this->rows()['run']);
        $this->assertSame($before['custody'], $this->rows()['custody']);
        $this->assertSame('unknown', $this->rows()['run']['status']);
        $this->assertNull($this->rows()['custody']['outcome']);
    }

    public function test_discovery_checkpoint_refuses_policy_and_runtime_configuration_drift(): void
    {
        $this->discoveryCheckpointFixture();
        $this->assertTrue($this->discoveryQualified());
        Container::getInstance()->make('config')->set('queue.connections.database.retry_after', 1);
        $this->assertFalse($this->discoveryQualified());
        Container::getInstance()->make('config')->set('queue.connections.database.retry_after', 600);
        DB::table('system_configs')->where('section', 'files')->where('config_key', 'registry_discovery_retirement_policy')->update(['config_value' => '{"queue":"different"}']);
        $this->assertFalse($this->discoveryQualified());
    }

    public function test_discovery_checkpoint_refuses_mutating_or_held_status_reports(): void
    {
        $this->discoveryCheckpointFixture();
        $original = json_decode($this->rows()['custody']['result_envelope'], true);
        foreach (['worker_started' => true, 'state' => 'held', 'reason' => 'worker_busy'] as $key => $value) {
            $envelope = $original;
            $envelope['new_work_checkpoint']['admission_report'][$key] = $value;
            DB::table('task_custody_records')->where('id', 99)->update(['result_envelope' => json_encode($envelope)]);
            $this->assertFalse($this->discoveryQualified());
        }
        $envelope = $original;
        $envelope['new_work_checkpoint']['admission_report']['before']['reserved'] = 1;
        $envelope['new_work_checkpoint']['admission_report']['after']['reserved'] = 1;
        DB::table('task_custody_records')->where('id', 99)->update(['result_envelope' => json_encode($envelope)]);
        $this->assertFalse($this->discoveryQualified());
    }

    public function test_discovery_checkpoint_refuses_original_scope_source_or_run_drift(): void
    {
        $this->discoveryCheckpointFixture();
        $original = json_decode($this->rows()['custody']['result_envelope'], true);
        foreach (['original_no_business_scope_sha256', 'source_classes_sha256'] as $key) {
            $envelope = $original;
            if ($key === 'source_classes_sha256') {
                unset($envelope['new_work_checkpoint'][$key][RetireFileRegistryDerivedArtifacts::class]);
            } else {
                $envelope['new_work_checkpoint'][$key] = str_repeat('b', 64);
            }
            DB::table('task_custody_records')->where('id', 99)->update(['result_envelope' => json_encode($envelope)]);
            $this->assertFalse($this->discoveryQualified());
        }
        DB::table('task_custody_records')->where('id', 99)->update(['result_envelope' => json_encode($original)]);
        DB::table('scheduled_job_runs')->where('id', 81)->update(['output' => 'changed original']);
        $this->assertFalse($this->discoveryQualified());
    }
}
