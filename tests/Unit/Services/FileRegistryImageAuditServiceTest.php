<?php

namespace Tests\Unit\Services;

use App\Services\FileRegistryImageAuditService;
use App\Services\FileRegistryImageAuditStateService;
use App\Services\FileRegistryImageSourceAuditService;
use App\Services\NextcloudFileApiService;
use App\Services\SystemConfigService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/** Real custody migration/state with per-test SQLite; no shared schema or filesystem inspection. */
class FileRegistryImageAuditServiceTest extends TestCase
{
    private string $originalConnection;

    private string $root;

    private string $otherRoot;

    private array $policy;

    private array $rows = ['registry' => [], 'media' => []];

    private array $cutoffs = ['registry' => 0, 'media' => 0];

    private array $batches = [];

    private ?Closure $beforeBatchReturn = null;

    private ?Closure $beforeMetadataReturn = null;

    private FileRegistryImageAuditService $audit;

    private FileRegistryImageAuditStateService $state;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([FileRegistryImageAuditService::class, FileRegistryImageAuditStateService::class] as $class) {
            $this->assertSame(realpath(base_path('app/Services/'.class_basename($class).'.php')), (new ReflectionClass($class))->getFileName());
        }
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.image_audit_adapter_private' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('image_audit_adapter_private');
        DB::setDefaultConnection('image_audit_adapter_private');
        (require database_path('migrations/2026_10_06_020000_create_file_registry_image_audit_custody.php'))->up();
        Schema::create('file_registry', function (Blueprint $table): void {
            $table->id();
            $table->uuid('asset_uuid')->nullable();
            $table->string('current_path');
            $table->char('path_hash', 64);
            $table->string('status');
            $table->unsignedBigInteger('file_size');
            $table->char('content_hash', 64)->nullable();
            $table->dateTime('content_hash_verified_at')->nullable();
            $table->dateTime('nextcloud_modified_at')->nullable();
            $table->uuid('lifecycle_generation_key')->nullable();
        });
        Schema::create('genealogy_media', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tree_id');
            $table->string('nextcloud_path')->nullable();
            $table->string('original_path')->nullable();
            $table->unsignedBigInteger('file_size');
        });
        Schema::create('review_type_registry', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('enabled');
            $table->string('source_table');
            $table->string('source_connection');
            $table->text('actions');
            $table->text('ui_schema');
        });
        Schema::create('agent_review_queue', function (Blueprint $table): void {
            $table->id();
            foreach (['agent_id', 'review_type', 'finding_type', 'title', 'summary', 'status', 'token'] as $column) {
                $table->string($column);
            }
            $table->text('details');
            $table->integer('priority');
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });
        DB::table('review_type_registry')->insert(['name' => config('file_registry.image_audit.review.type'), 'enabled' => 1,
            'source_table' => 'agent_review_queue', 'source_connection' => 'mysql', 'actions' => '[]', 'ui_schema' => '{"inspection_only":true}']);
        $this->root = sys_get_temp_dir().'/plos-audit-adapter-'.bin2hex(random_bytes(8));
        $this->otherRoot = $this->root.'-other';
        mkdir($this->root);
        mkdir($this->otherRoot);
        config(['services.nextcloud.data_path' => $this->root]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-06 04:00:00', 'UTC'));
        $this->policy = array_replace(config('file_registry.image_audit.maintenance_policy'), ['max_rows' => 10, 'max_seconds' => 5, 'epoch_interval_seconds' => 1, 'retry_seconds' => 5]);
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')->with('files.registry_image_audit_policy')->andReturnUsing(fn () => $this->policy);
        $sources = Mockery::mock(FileRegistryImageSourceAuditService::class);
        $sources->shouldReceive('captureCutoffs')->andReturnUsing(fn () => $this->cutoffs);
        foreach (['registry' => 'auditRegistryBatch', 'media' => 'auditMediaBatch'] as $lane => $method) {
            $sources->shouldReceive($method)->andReturnUsing(fn ($after, $limit, $seconds, $cutoff) => $this->batch($lane, $after, $limit, $seconds, $cutoff));
        }
        $resolver = new FileRegistryImageSourceAuditService(new NextcloudFileApiService);
        $sources->shouldReceive('mediaRegistrySnapshot')->andReturnUsing(
            fn ($path, $columns, $lock) => $resolver->mediaRegistrySnapshot($path, $columns, $lock));
        $sources->shouldReceive('hasRemaining')->andReturnUsing(function ($lane, $after, $cutoff): bool {
            $this->assertGreaterThan(0, DB::transactionLevel());

            return $this->eligible($lane, $after, $cutoff) !== [];
        });
        $nc = Mockery::mock(NextcloudFileApiService::class);
        $nc->shouldReceive('getFileInfo')->andReturnUsing(function ($path, $localOnly): array {
            $this->assertTrue($localOnly);
            $this->assertGreaterThan(0, DB::transactionLevel());
            if ($this->beforeMetadataReturn !== null) {
                ($this->beforeMetadataReturn)($path);
            }

            return ['success' => true, 'source' => 'filesystem', 'source_fingerprint' => $this->fingerprint()];
        });
        // Unexpected hash/download/network methods fail through the strict mock.
        $this->state = new FileRegistryImageAuditStateService;
        $this->audit = new FileRegistryImageAuditService($this->state, $sources, $nc, $config);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        rmdir($this->root);
        rmdir($this->otherRoot);
        DB::purge('image_audit_adapter_private');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_read_only_status_creates_no_custody_and_exposes_only_aggregates(): void
    {
        $status = $this->audit->status();
        $this->assertSame('ready', $status['state']);
        $this->assertSame(0, DB::table('file_registry_image_audit_checkpoints')->count());
        $this->assertSame(0, DB::table('agent_review_queue')->count());
        $this->assertSame([], $this->batches);
        $this->assertSafe($status);
        config(['services.nextcloud.data_path' => $this->root.'/private-missing']);
        $held = $this->audit->status();
        $this->assertSame('held', $held['state']);
        $this->assertSame(0, DB::table('file_registry_image_audit_checkpoints')->count());
        $this->assertSafe($held);
    }

    public function test_one_row_batches_publish_fairly_and_complete_sparse_captured_cutoffs(): void
    {
        $this->registry(2);
        $this->registry(7);
        $this->media(3, 4);
        $this->media(9, 8);
        $this->cutoffs = ['registry' => 12, 'media' => 20];
        $result = $this->audit->runSlice();
        $this->assertSame(['registry', 'media', 'registry', 'media', 'registry', 'media'], array_column($this->batches, 'lane'));
        $this->assertSame(4, $result['processed']);
        $this->assertTrue($result['registry_epoch_complete']);
        $this->assertTrue($result['media_epoch_complete']);
        $this->assertSame(4, DB::table('file_registry_image_audit_observations')->count());
        $checkpoint = $this->checkpoint();
        $this->assertSame(7, $checkpoint['registry']['after_id']);
        $this->assertSame(9, $checkpoint['media']['after_id']);
        $this->assertSame(12, $checkpoint['registry']['cutoff']);
        $this->assertSame(20, $checkpoint['media']['cutoff']);
        $this->assertSame('captured_maximum_selector_ids', $result['completion_scope']);
        $this->assertSame(0, DB::table('agent_review_queue')->count());
        $this->assertSafe($result);
    }

    public function test_scheduled_audit_persists_format_evidence_in_finding_and_observation(): void
    {
        $this->registry(4);
        $this->rows['registry'][4] = array_replace($this->rows['registry'][4], [
            'classification' => 'unsupported_non_raster_format', 'content_format' => 'svg',
            'format_classification' => 'non_raster_graphic', 'format_evidence' => 'content_signature',
            'finding_required' => true,
        ]);

        $result = $this->audit->runSlice();
        $observation = DB::table('file_registry_image_audit_observations')->where('source_id', 4)->first();
        $finding = $this->finding();

        $this->assertSame(1, $result['findings']);
        $this->assertSame('svg', $finding['evidence']['row']['content_format']);
        $this->assertSame('content_signature', $finding['evidence']['row']['format_evidence']);
        $this->assertSame(['content_format' => 'svg', 'format_classification' => 'non_raster_graphic',
            'format_evidence' => 'content_signature'], json_decode($observation->source_fingerprint, true)['format']);
        $this->assertSame(1, DB::table('agent_review_queue')->count());
    }

    public function test_missing_media_retains_private_hold_and_later_tree_progresses_without_hash_backfill(): void
    {
        $this->registry(10, false);
        $this->media(1, 4, ['successful' => false, 'observed_sha256' => null, 'source_fingerprint' => null,
            'classification' => 'missing_or_unavailable', 'finding_required' => true]);
        $this->media(2, 8);
        $before = DB::table('file_registry')->first();
        $result = $this->audit->runSlice();
        $this->assertSame(2, $result['processed']);
        $this->assertSame(1, $result['findings']);
        $this->assertSame(1, DB::table('file_registry_image_audit_observations')->count());
        $this->assertSame(8, DB::table('file_registry_image_audit_observations')->value('tree_id'));
        $this->assertEquals($before, DB::table('file_registry')->first());
        $details = $this->finding();
        $this->assertTrue($details['inspection_only']);
        $this->assertFalse($details['identity_decisions_performed']);
        $this->assertFalse($details['historical_hashes_backfilled']);
        $this->assertNull($details['evidence']['row']['observed_sha256']);
        $this->assertSame('unknown', $details['evidence']['assessment']['baseline']);
        $this->assertTrue($result['media_epoch_complete']);
        $this->assertSafe($result);
    }

    public function test_same_size_and_mtime_sha_change_retains_actual_previous_inspection_before_advancing(): void
    {
        $this->media(1, 4);
        $this->audit->runSlice();
        $old = DB::table('file_registry_image_audit_observations')->first();
        $snapshot = $this->rows['media'][1]['source_snapshot'];
        $this->advance(1);
        $this->rows['media'][1]['observed_sha256'] = str_repeat('b', 64);
        $writes = [];
        DB::listen(function ($event) use (&$writes): void {
            if (preg_match('/^(insert|update)/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        $result = $this->audit->runSlice();
        $details = $this->finding();
        $this->assertSame('changed_since_physical_inspection', DB::table('agent_review_queue')->value('finding_type'));
        $this->assertSame(str_repeat('a', 64), $details['evidence']['previous_inspection']['observed_sha256']);
        $this->assertSame($old->epoch_uuid, $details['evidence']['previous_inspection']['epoch_uuid']);
        $this->assertEquals($snapshot, $details['evidence']['row']['source_snapshot']);
        $this->assertEquals(json_decode($old->source_fingerprint, true), $details['evidence']['row']['source_fingerprint']);
        $this->assertTrue($details['evidence']['assessment']['observed_change']);
        $this->assertSame(str_repeat('b', 64), DB::table('file_registry_image_audit_observations')->value('observed_sha256'));
        $findingWrite = array_search(true, array_map(fn ($sql) => str_contains($sql, 'insert into "agent_review_queue"'), $writes), true);
        $observationWrite = array_search(true, array_map(fn ($sql) => str_contains($sql, 'update "file_registry_image_audit_observations"'), $writes), true);
        $this->assertIsInt($findingWrite);
        $this->assertIsInt($observationWrite);
        $this->assertLessThan($observationWrite, $findingWrite);
        $this->assertSame(1, $result['findings']);
        $this->assertSafe($result);
    }

    public function test_timeout_retains_cursor_and_exact_dedup_preserves_operator_closed_finding(): void
    {
        $this->media(1, 4, ['successful' => false, 'observed_sha256' => null, 'source_fingerprint' => null,
            'classification' => 'time_budget_exceeded', 'finding_required' => true, 'retry' => true]);
        $first = $this->audit->runSlice();
        $this->assertSame('waiting', $first['state']);
        $this->assertSame(0, $this->checkpoint()['media']['after_id']);
        $this->assertSame('2026-10-06 04:00:05.000000', $first['next_attempt_at']);
        DB::table('agent_review_queue')->update(['status' => 'rejected', 'title' => 'Operator closed inspection']);
        $operator = DB::table('agent_review_queue')->first();
        $this->advance(5);
        $again = $this->audit->runSlice();
        $this->assertSame(1, DB::table('agent_review_queue')->count());
        $this->assertEquals($operator, DB::table('agent_review_queue')->first());
        $this->assertSame(0, DB::table('file_registry_image_audit_observations')->count());
        $this->assertSame(0, $this->checkpoint()['media']['after_id']);
        $this->assertSame(2, $again['counters']['media']['retries']);
        $this->assertSame('2026-10-06 04:00:10.000000', $again['next_attempt_at']);
        $this->assertSafe($again);
    }

    public function test_source_snapshot_race_rolls_back_only_failed_row_and_reports_committed_counters(): void
    {
        $this->registry(1);
        $this->media(1, 4);
        $this->beforeBatchReturn = function ($lane): void {
            if ($lane === 'media') {
                DB::table('genealogy_media')->where('id', 1)->update(['tree_id' => 8]);
            }
        };
        $result = $this->audit->runSlice();
        $this->assertSame('held', $result['state']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['counters']['registry']['inspections']);
        $this->assertSame(0, $result['counters']['media']['attempts']);
        $this->assertSame(1, DB::table('file_registry_image_audit_observations')->count());
        $this->assertSame(0, $this->checkpoint()['media']['after_id']);
        $this->assertSame(0, DB::table('agent_review_queue')->count());
        $this->assertSafe($result);
    }

    public function test_companion_generation_and_path_races_each_hold_without_publication(): void
    {
        $this->registry(10, false, '/private-photo-media-1.jpg');
        $this->media(1, 4);
        $this->rows['media'][1]['registry_snapshot'] = (array) DB::table('file_registry')->where('id', 10)->first();
        $this->beforeBatchReturn = function (): void {
            DB::table('file_registry')->where('id', 10)->update(['lifecycle_generation_key' => '11111111-1111-4111-8111-111111111111']);
        };
        $result = $this->audit->runSlice();
        $this->assertSame('held', $result['state']);
        $this->assertSame(0, DB::table('file_registry_image_audit_observations')->count());
        $this->assertSame(0, $this->checkpoint()['media']['after_id']);
        $this->rows['media'][1]['registry_snapshot'] = (array) DB::table('file_registry')->where('id', 10)->first();
        $this->beforeBatchReturn = function (): void {
            DB::table('genealogy_media')->where('id', 1)->update(['nextcloud_path' => '/operator-new-path.jpg']);
        };
        $again = $this->audit->runSlice();
        $this->assertSame('held', $again['state']);
        $this->assertSame(0, DB::table('file_registry_image_audit_observations')->count());
        $this->assertSame(0, DB::table('agent_review_queue')->count());
        $this->assertSafe($again);
    }

    public function test_root_binding_race_and_physical_metadata_race_hold_before_publication(): void
    {
        $this->registry(1);
        $this->beforeBatchReturn = fn () => config(['services.nextcloud.data_path' => $this->otherRoot]);
        $result = $this->audit->runSlice();
        $this->assertSame('held', $result['state']);
        $this->assertSame(0, DB::table('file_registry_image_audit_observations')->count());
        config(['services.nextcloud.data_path' => $this->root]);
        $this->beforeBatchReturn = null;
        $this->rows['registry'][1]['source_fingerprint']['mtime'] = 99;
        $again = $this->audit->runSlice();
        $this->assertSame('held', $again['state']);
        $this->assertSame(0, DB::table('file_registry_image_audit_observations')->count());
        $this->assertSame(0, $this->checkpoint()['registry']['after_id']);
        $this->assertSafe($again);
    }

    public function test_disabled_or_missing_review_registration_preserves_prior_proof_and_cursor(): void
    {
        $this->media(1, 4);
        $this->audit->runSlice();
        $previous = DB::table('file_registry_image_audit_observations')->first();
        $this->advance(1);
        $this->rows['media'][1]['observed_sha256'] = str_repeat('b', 64);
        DB::table('review_type_registry')->update(['enabled' => 0]);
        $disabled = $this->audit->runSlice();
        $this->assertSame('image_audit_review_retention_unavailable', $disabled['reason']);
        $this->assertEquals($previous, DB::table('file_registry_image_audit_observations')->first());
        $this->assertSame(0, $this->checkpoint()['media']['after_id']);
        DB::table('review_type_registry')->delete();
        $missing = $this->audit->runSlice();
        $this->assertSame('held', $missing['state']);
        $this->assertSame(0, DB::table('agent_review_queue')->count());
        $this->assertEquals($previous, DB::table('file_registry_image_audit_observations')->first());
        $this->assertSame(['attempts' => 0, 'inspections' => 0, 'findings' => 0, 'retries' => 0], $this->checkpoint()['counters']['media']);
        $this->assertSafe($missing);
    }

    public function test_completed_epoch_waits_then_new_epoch_captures_new_cutoff(): void
    {
        $this->media(1, 4);
        $done = $this->audit->runSlice();
        $oldEpoch = $this->checkpoint()['epoch_uuid'];
        $this->assertSame('waiting', $done['state']);
        $batchCount = count($this->batches);
        $this->assertSame(0, $this->audit->runSlice()['processed']);
        $this->assertCount($batchCount, $this->batches);
        $this->advance(1);
        $this->assertSame('ready', $this->audit->status()['state']);
        $this->media(3, 8);
        $result = $this->audit->runSlice();
        $this->assertSame(2, $result['processed']);
        $this->assertNotSame($oldEpoch, $this->checkpoint()['epoch_uuid']);
        $this->assertSame(3, $this->checkpoint()['media']['cutoff']);
        $this->assertSame(2, DB::table('file_registry_image_audit_observations')->count());
        $this->assertSafe($result);
    }

    public function test_current_policy_mismatch_holds_existing_custody_without_rewriting_it(): void
    {
        $this->media(1, 4, ['successful' => false, 'observed_sha256' => null, 'source_fingerprint' => [],
            'classification' => 'time_budget_exceeded', 'finding_required' => true, 'retry' => true]);
        $this->audit->runSlice();
        $previous = DB::table('file_registry_image_audit_checkpoints')->first();
        $this->policy['retry_seconds'] = 6;
        $result = $this->audit->runSlice();
        $this->assertSame('held', $result['state']);
        $this->assertEquals($previous, DB::table('file_registry_image_audit_checkpoints')->first());
        $this->assertSafe($result);
    }

    public function test_raw_source_failure_remains_private_and_retains_previous_committed_progress(): void
    {
        $this->registry(1);
        $this->media(1, 4);
        $this->beforeBatchReturn = function ($lane): void {
            if ($lane === 'media') {
                throw new \RuntimeException($this->root.'/private-photo-secret.jpg '.str_repeat('a', 64));
            }
        };
        $result = $this->audit->runSlice();
        $this->assertSame('held', $result['state']);
        $this->assertSame('image_audit_slice_held', $result['reason']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['counters']['registry']['inspections']);
        $this->assertSame(0, $this->checkpoint()['media']['after_id']);
        $this->assertSame(1, DB::table('file_registry_image_audit_observations')->count());
        $this->assertSafe($result);
    }

    public function test_database_failure_retains_only_sqlstate_and_numeric_driver_code(): void
    {
        $this->registry(1);
        $previous = new \PDOException('SQL /private/photo.jpg synthetic binding');
        $previous->errorInfo = ['23000', 1062, 'private SQL and binding'];
        $error = new QueryException('synthetic', 'SELECT private_column FROM private_table', ['private-binding'], $previous);
        $this->beforeBatchReturn = fn () => throw $error;
        Log::shouldReceive('warning')->once()->with('Image audit slice held', Mockery::on(fn ($context) => $context === [
            'exception_class' => QueryException::class,
            'reason' => 'image_audit_database_query_failed',
            'database_error' => ['sqlstate' => '23000', 'driver_code' => 1062],
        ]));
        $result = $this->audit->runSlice();
        $this->assertSame('held', $result['state']);
        $this->assertSame('image_audit_database_query_failed', $result['reason']);
        $this->assertSame(['sqlstate' => '23000', 'driver_code' => 1062], $result['database_error']);
        $this->assertSafe($result);
        $this->assertStringNotContainsString('private', json_encode($result));
    }

    public function test_malformed_database_error_info_and_code_shaped_private_reason_are_withheld(): void
    {
        $this->registry(1);
        $previous = new \PDOException('image_audit_private_secret');
        $previous->errorInfo = ['private_sqlstate', 'private-driver-code', 'private SQL'];
        $error = new QueryException('synthetic', 'SELECT private_column', [], $previous);
        $this->beforeBatchReturn = fn () => throw $error;
        $result = $this->audit->runSlice();
        $this->assertSame('image_audit_database_query_failed', $result['reason']);
        $this->assertArrayNotHasKey('database_error', $result);
        $this->beforeBatchReturn = fn () => throw new \RuntimeException('image_audit_private_secret');
        $result = $this->audit->runSlice();
        $this->assertSame('image_audit_slice_held', $result['reason']);
        $this->assertStringNotContainsString('private', json_encode($result));
    }

    private function registry(int $id, bool $inspect = true, ?string $path = null): void
    {
        $path ??= '/private-photo-registry-'.$id.'.jpg';
        DB::table('file_registry')->insert(['id' => $id, 'current_path' => $path, 'path_hash' => hash('sha256', $path),
            'status' => 'active', 'file_size' => 100, 'content_hash' => str_repeat('c', 64),
            'content_hash_verified_at' => null, 'nextcloud_modified_at' => '2026-10-01 00:00:00', 'lifecycle_generation_key' => null]);
        if ($inspect) {
            $this->rows['registry'][$id] = $this->row('registry', $id, null, $path, (array) DB::table('file_registry')->where('id', $id)->first());
            $this->cutoffs['registry'] = max($this->cutoffs['registry'], $id);
        }
    }

    private function media(int $id, int $tree, array $changes = []): void
    {
        $path = '/private-photo-media-'.$id.'.jpg';
        DB::table('genealogy_media')->insert(['id' => $id, 'tree_id' => $tree, 'nextcloud_path' => $path, 'original_path' => null, 'file_size' => 100]);
        $this->rows['media'][$id] = array_replace($this->row('media', $id, $tree, $path, (array) DB::table('genealogy_media')->where('id', $id)->first()), $changes);
        $this->cutoffs['media'] = max($this->cutoffs['media'], $id);
    }

    private function row(string $lane, int $id, ?int $tree, string $path, array $snapshot): array
    {
        return ['source_kind' => $lane, 'source_id' => $id, 'tree_id' => $tree, 'source_snapshot' => $snapshot,
            'nextcloud_path' => $path, 'registry_snapshot' => $lane === 'registry' ? $snapshot : null,
            'observed_sha256' => str_repeat('a', 64), 'source_fingerprint' => $this->fingerprint(),
            'classification' => 'header_readable', 'successful' => true, 'finding_required' => false, 'retry' => false];
    }

    private function fingerprint(): array
    {
        return ['dev' => 1, 'ino' => 2, 'size' => 100, 'mtime' => 1, 'ctime' => 1];
    }

    private function eligible(string $lane, int $after, int $cutoff): array
    {
        $rows = array_filter($this->rows[$lane], fn ($row) => $row['source_id'] > $after && $row['source_id'] <= $cutoff);
        ksort($rows);

        return $rows;
    }

    private function batch(string $lane, int $after, int $limit, int $seconds, int $cutoff): array
    {
        $this->assertSame(0, DB::transactionLevel(), 'Physical source inspection must happen outside the fenced publisher.');
        $this->assertSame(1, $limit, 'A fair lane flip must not waste physical inspection of a larger batch.');
        $this->assertGreaterThanOrEqual(1, $seconds);
        $this->batches[] = compact('lane', 'after', 'limit', 'cutoff');
        $eligible = $this->eligible($lane, $after, $cutoff);
        $row = $eligible === [] ? null : reset($eligible);
        if ($this->beforeBatchReturn !== null) {
            ($this->beforeBatchReturn)($lane);
        }

        return ['status' => 'completed_batch', 'rows' => $row === null ? [] : [$row], 'complete' => $row === null];
    }

    private function advance(int $seconds): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds($seconds));
    }

    private function checkpoint(): array
    {
        return json_decode(DB::table('file_registry_image_audit_checkpoints')->value('checkpoint'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function finding(): array
    {
        return json_decode(DB::table('agent_review_queue')->value('details'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertSafe(array $output): void
    {
        $this->assertSame([], array_diff(array_keys($output), ['state', 'reason', 'registry_epoch_complete', 'media_epoch_complete',
            'counters', 'next_attempt_at', 'findings', 'completion_scope', 'processed', 'database_error']));
        $encoded = json_encode($output, JSON_THROW_ON_ERROR);
        foreach ([$this->root, 'private-photo', 'source_id', 'tree_id', 'binding', 'epoch_uuid', 'checkpoint_id', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64)] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $encoded);
    }
}
