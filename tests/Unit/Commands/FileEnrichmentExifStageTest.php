<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\FileEnrichmentCommand;
use App\Services\NextcloudFileApiService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class FileEnrichmentExifStageTest extends TestCase
{
    public function test_quarantined_newest_source_does_not_starve_unheld_sources_and_release_restores_it(): void
    {
        $bytes = file_get_contents($this->image);
        $this->database->table('file_registry')->insert(['id' => 72, 'current_path' => '/held.png',
            'filename' => 'held.png', 'extension' => 'png', 'status' => 'active',
            'created_at' => '2026-10-07', 'quarantine_status' => 'quarantined', 'exif_checked' => 0]);
        Process::shouldReceive('timeout')->once()->andReturnSelf();
        Process::shouldReceive('run')->once()->andReturn($this->toolResult());
        $this->assertSame(1, $this->runStage(limit: 1)['checked']);
        $this->assertSame(1, $this->file()->exif_checked);
        $this->assertSame(0, (int) $this->database->table('file_registry')->where('id', 72)->value('exif_checked'));
        $this->assertSame($bytes, file_get_contents($this->image));
        $this->database->table('file_registry')->where('id', 72)->update(['quarantine_status' => null]);
        $this->assertSame(1, $this->runStage(limit: 1, dryRun: true)['pending']);
    }

    public function test_quarantine_applied_during_metadata_read_prevents_exif_publication(): void
    {
        Process::shouldReceive('timeout')->once()->andReturnSelf();
        Process::shouldReceive('run')->once()->andReturnUsing(function () {
            $this->database->table('file_registry')->where('id', 71)->update(['quarantine_status' => 'quarantined']);

            return $this->toolResult(['DateTimeOriginal' => '1920:04:03 12:00:00']);
        });
        $this->assertSame(['exif_registry_changed' => 1], $this->runStage()['error_reasons']);
        $this->assertNull($this->file()->exif_checked);
        $this->assertNull($this->file()->date_taken);
    }

    private Connection $database;

    private mixed $manager;

    private string $root;

    private string $image;

    private NextcloudFileApiService $api;

    private ?QueryException $transactionFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.exif_stage_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'file_types.image' => ['png']]);
        $this->manager = DB::getFacadeRoot();
        $this->database = $this->manager->connection('exif_stage_test');
        $this->database->getSchemaBuilder()->create('file_registry', function (Blueprint $table): void {
            $table->integer('id')->primary();
            foreach (['asset_uuid', 'current_path', 'filename', 'extension', 'status', 'created_at', 'updated_at',
                'date_taken', 'date_taken_source', 'gps_latitude', 'gps_longitude', 'camera_make', 'camera_model',
                'exif_rating', 'exif_keywords', 'exif_caption', 'nextcloud_modified_at', 'file_size', 'content_hash',
                'lifecycle_generation_key', 'quarantine_status'] as $field) {
                $table->text($field)->nullable();
            }
            $table->integer('exif_checked')->nullable();
            $table->integer('is_scan')->nullable();
            $table->integer('date_needs_review')->nullable();
            $table->text('scan_date')->nullable();
        });
        $this->root = sys_get_temp_dir().'/plos-exif-stage-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->image = $this->root.'/source.png';
        file_put_contents($this->image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jXioAAAAASUVORK5CYII='));
        config(['services.nextcloud.data_path' => $this->root]);
        $this->database->table('file_registry')->insert(['id' => 71, 'asset_uuid' => '123e4567-e89b-12d3-a456-426614174000',
            'current_path' => '/source.png', 'filename' => 'source.png', 'extension' => 'png', 'status' => 'active',
            'created_at' => '2026-10-06', 'lifecycle_generation_key' => 'generation-a']);
        DB::shouldReceive('transaction')->andReturnUsing(function ($callback) {
            if ($this->transactionFailure !== null) {
                throw $this->transactionFailure;
            }

            return $this->database->transaction($callback);
        });
        DB::shouldReceive('select')->andReturnUsing(fn ($sql, $bindings = []) => $this->database->select($sql, $bindings));
        DB::shouldReceive('update')->andReturnUsing(function ($sql, $bindings = []) {
            $this->assertStringContainsString('BINARY current_path = BINARY ?', $sql);
            $this->assertLessThan(strpos($sql, 'date_taken        ='), strpos($sql, 'date_taken_source ='), 'Source classification must be assigned before the date in MySQL');

            return $this->database->update(str_replace(['BINARY ', 'NOW()', '<=>'], ['', 'CURRENT_TIMESTAMP', 'IS'], $sql), $bindings);
        });
        $this->api = Mockery::mock(NextcloudFileApiService::class);
        $this->api->shouldReceive('localPath')->with('/source.png')->andReturnUsing(fn () => is_file($this->image) ? $this->image : null)->byDefault();
        $this->api->shouldReceive('computeFileHash')->withArgs(fn ($path, $deadline, $localOnly) => $path === '/source.png'
            && is_float($deadline) && $deadline > hrtime(true) / 1e9 && $localOnly === true)
            ->andReturnUsing(function () {
                clearstatcache(true, $this->image);

                return ['success' => true, 'hash' => hash_file('sha256', $this->image),
                    'source_fingerprint' => array_intersect_key(stat($this->image), array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime']))];
            })->byDefault();
        $this->app->instance(NextcloudFileApiService::class, $this->api);
    }

    protected function tearDown(): void
    {
        @unlink($this->image);
        @rmdir($this->root);
        $this->manager->purge('exif_stage_test');
        Mockery::close();
        parent::tearDown();
    }

    public function test_missing_file_stays_pending_and_successful_retry_with_no_date_is_terminal(): void
    {
        $bytes = file_get_contents($this->image);
        unlink($this->image);
        Process::shouldReceive('timeout')->once()->withArgs(fn ($seconds) => $seconds > 0 && $seconds <= 30)->andReturnSelf();
        Process::shouldReceive('run')->once()->andReturn($this->toolResult());
        $this->assertSame(1, $this->runStage()['errors']);
        $this->assertNull($this->file()->exif_checked);
        file_put_contents($this->image, $bytes);
        $result = $this->runStage();
        $this->assertSame(['processed' => 0, 'checked' => 1, 'no_date' => 1, 'errors' => 0, 'error_reasons' => []], $result);
        $this->assertSame(1, $this->file()->exif_checked);
        $this->assertNull($this->file()->date_taken);
        $this->assertSame(0, $this->runStage()['checked']);
    }

    public function test_failed_tool_response_is_pending_and_retries_successfully(): void
    {
        Process::shouldReceive('timeout')->twice()->andReturnSelf();
        Process::shouldReceive('run')->twice()->andReturn($this->toolResult(success: false), $this->toolResult(['DateTimeOriginal' => '1920:04:03 12:00:00']));
        $this->assertSame(1, $this->runStage()['errors']);
        $this->assertNull($this->file()->exif_checked);
        $this->assertSame(1, $this->runStage()['processed']);
        $this->assertSame('1920-04-03 12:00:00', $this->file()->date_taken);
        $this->assertSame('exif_original', $this->file()->date_taken_source);
    }

    public function test_operator_marker_claim_and_conflict_cannot_be_bypassed_by_scanner_timestamp(): void
    {
        foreach (['1900:01:01', '1901:01:01'] as $iptcDate) {
            $this->database->table('file_registry')->where('id', 71)->update(['exif_checked' => null, 'date_needs_review' => null]);
            Process::shouldReceive('timeout')->once()->andReturnSelf();
            Process::shouldReceive('run')->once()->andReturn($this->toolResult([
                'XMP-dc:Source' => 'plos:operator-confirmed-date-only',
                'XMP-photoshop:DateCreated' => '1900:01:01', 'IPTC:DateCreated' => $iptcDate,
                'EXIF:DateTimeOriginal' => '2026:01:01 12:00:00',
            ]));
            $result = $this->runStage();
            $this->assertSame(1, $result['checked']);
            $this->assertSame(0, $result['errors']);
            $this->assertNull($this->file()->date_taken);
            $this->assertNull($this->file()->date_taken_source);
            $this->assertSame(1, $this->file()->date_needs_review);
        }
    }

    public function test_known_scan_preserves_scan_date_without_publishing_capture_date(): void
    {
        $this->database->table('file_registry')->where('id', 71)->update([
            'is_scan' => 1, 'scan_date' => '2025-01-02 12:00:00',
        ]);
        Process::shouldReceive('timeout')->once()->andReturnSelf();
        Process::shouldReceive('run')->once()->andReturn($this->toolResult(['EXIF:DateTimeOriginal' => '2026:01:01 12:00:00']));
        $this->assertSame(1, $this->runStage()['checked']);
        $this->assertNull($this->file()->date_taken);
        $this->assertSame('2025-01-02 12:00:00', $this->file()->scan_date);
        $this->assertSame(1, $this->file()->date_needs_review);
    }

    public function test_existing_scan_path_policy_creates_only_a_technical_hold(): void
    {
        $this->database->table('file_registry')->where('id', 71)->update(['filename' => 'scanned_photo.png']);
        Process::shouldReceive('timeout')->once()->andReturnSelf();
        Process::shouldReceive('run')->once()->andReturn($this->toolResult(['EXIF:DateTimeOriginal' => '2026:01:01 12:00:00']));
        $this->assertSame(1, $this->runStage()['checked']);
        $this->assertNull($this->file()->date_taken);
        $this->assertNull($this->file()->scan_date);
        $this->assertSame(1, $this->file()->is_scan);
        $this->assertSame(1, $this->file()->date_needs_review);
    }

    public function test_concurrent_scan_or_review_classification_change_prevents_publication(): void
    {
        foreach (['is_scan', 'date_needs_review'] as $field) {
            $this->database->table('file_registry')->where('id', 71)->update(['is_scan' => null, 'date_needs_review' => null]);
            Process::shouldReceive('timeout')->once()->andReturnSelf();
            Process::shouldReceive('run')->once()->andReturnUsing(function () use ($field) {
                $this->database->table('file_registry')->where('id', 71)->update([$field => 1]);

                return $this->toolResult(['DateTimeOriginal' => '2009:01:01 12:00:00']);
            });
            $this->assertSame(1, $this->runStage()['errors']);
            $this->assertNull($this->file()->date_taken);
            $this->assertNull($this->file()->exif_checked);
            $this->assertSame(1, $this->file()->{$field});
        }
    }

    public function test_invalid_original_date_falls_through_to_valid_historical_create_date(): void
    {
        Process::shouldReceive('timeout')->once()->andReturnSelf();
        Process::shouldReceive('run')->once()->andReturn($this->toolResult([
            'DateTimeOriginal' => '0000:00:00 00:00:00',
            'CreateDate' => '1962:04:03 12:13:14+02:00',
        ]));
        $result = $this->runStage();
        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame('1962-04-03 12:13:14', $this->file()->date_taken);
        $this->assertSame('exif_digitized', $this->file()->date_taken_source);
    }

    public function test_invalid_calendar_and_blank_dates_do_not_invent_a_date(): void
    {
        Process::shouldReceive('timeout')->once()->andReturnSelf();
        Process::shouldReceive('run')->once()->andReturn($this->toolResult([
            'DateTimeOriginal' => '2020:02:30 12:00:00',
            'CreateDate' => '   ', 'ModifyDate' => '0000:00:00 00:00:00',
        ]));
        $result = $this->runStage();
        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['no_date']);
        $this->assertNull($this->file()->date_taken);
        $this->assertNull($this->file()->date_taken_source);
    }

    public function test_tool_exception_preserves_pending_stage(): void
    {
        Process::shouldReceive('timeout')->once()->andReturnSelf();
        Process::shouldReceive('run')->once()->andThrow(new \RuntimeException('read failed'));
        $result = $this->runStage();
        $this->assertSame(1, $result['errors']);
        $this->assertSame([['file_registry_id' => 71, 'reason' => 'exif_read_exception',
            'stage' => 'metadata_tool', 'exception_kind' => 'exception']], $result['failure_details']);
        $this->assertStringNotContainsString('read failed', json_encode($result));
        $this->assertNull($this->file()->exif_checked);
    }

    #[DataProvider('queryExceptionCodes')]
    public function test_query_exception_receipt_contains_only_validated_database_codes(mixed $sqlState, mixed $driverCode, array $expectedCodes): void
    {
        $previous = new \PDOException('sensitive diagnostic SQL and photo metadata');
        $previous->errorInfo = [$sqlState, $driverCode, 'sensitive driver message'];
        $exception = new QueryException('exif_stage_test', 'UPDATE file_registry SET exif_caption = ?', ['private binding'], $previous);
        $this->transactionFailure = $exception;
        Process::shouldReceive('timeout')->once()->andReturnSelf();
        Process::shouldReceive('run')->once()->andReturn($this->toolResult());

        $result = $this->runStage();
        $this->assertSame([['file_registry_id' => 71, 'reason' => 'exif_read_exception',
            'stage' => 'registry_publish', 'exception_kind' => 'database'] + $expectedCodes], $result['failure_details']);
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['sensitive diagnostic', 'photo metadata', 'UPDATE file_registry', 'private binding', 'sensitive driver message'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $this->assertNull($this->file()->exif_checked);
    }

    public static function queryExceptionCodes(): array
    {
        return [
            'valid codes' => ['22007', 1292, ['sqlstate' => '22007', 'driver_error_code' => 1292]],
            'sqlstate normalized and numeric string code accepted' => ['hy000', '1205', ['sqlstate' => 'HY000', 'driver_error_code' => 1205]],
            'leading zeroes normalized' => ['23000', '001062', ['sqlstate' => '23000', 'driver_error_code' => 1062]],
            'invalid sqlstate omitted' => ['HY00', 1062, ['driver_error_code' => 1062]],
            'nonnumeric driver code omitted' => ['23000', '1062;secret', ['sqlstate' => '23000']],
            'invalid code shapes omitted' => ['HY000 PRIVATE', -1, []],
            'oversized driver code omitted' => ['HY000', '99999999999', ['sqlstate' => 'HY000']],
            'missing codes omitted' => [null, null, []],
        ];
    }

    #[DataProvider('invalidToolOutput')]
    public function test_success_exit_with_invalid_or_error_metadata_remains_pending(string $output): void
    {
        Process::shouldReceive('timeout')->once()->andReturnSelf();
        $result = Mockery::mock();
        $result->shouldReceive('successful')->andReturnTrue();
        $result->shouldReceive('output')->andReturn($output);
        Process::shouldReceive('run')->once()->andReturn($result);
        $this->assertSame(1, $this->runStage()['errors']);
        $this->assertNull($this->file()->exif_checked);
        $this->assertNull($this->file()->date_taken);
    }

    public static function invalidToolOutput(): array
    {
        return [[''], ['not JSON'], ['[]'], ['[{"SourceFile":"different","Error":"unsupported"}]'], ['{"DateTimeOriginal":"1920:01:01"}']];
    }

    #[DataProvider('dateFieldSources')]
    public function test_fallback_dates_keep_their_actual_field_classification(string $tag, string $source): void
    {
        $this->readTool([$tag => '1920:04:03 12:00:00', 'Keywords' => ['source', 'source'], 'Subject' => ['other'], 'Description' => 'Embedded caption']);
        $before = file_get_contents($this->image);
        $this->assertSame(1, $this->runStage()['processed']);
        $this->assertSame($source, $this->file()->date_taken_source);
        $this->assertSame('source, other', $this->file()->exif_keywords);
        $this->assertSame('Embedded caption', $this->file()->exif_caption);
        $this->assertSame($before, file_get_contents($this->image));
    }

    public static function dateFieldSources(): array
    {
        return [['DateTimeOriginal', 'exif_original'], ['CreateDate', 'exif_digitized'], ['ModifyDate', 'exif_modified'],
            ['ExifIFD:DateTimeOriginal', 'exif_original'], ['XMP-xmp:CreateDate', 'exif_digitized'], ['IFD0:ModifyDate', 'exif_modified']];
    }

    public function test_grouped_tool_warning_or_error_cannot_be_terminally_checked(): void
    {
        foreach (['ExifTool:Error', 'ExifTool:Warning'] as $field) {
            Process::shouldReceive('timeout')->once()->andReturnSelf();
            Process::shouldReceive('run')->once()->andReturn($this->toolResult([$field => 'Private diagnostic',
                'ExifIFD:DateTimeOriginal' => '1920:04:03 12:00:00']));
            $this->assertSame(1, $this->runStage()['errors']);
            $this->assertNull($this->file()->exif_checked);
            $this->assertNull($this->file()->date_taken);
        }
    }

    public function test_operator_date_and_metadata_created_during_read_are_preserved(): void
    {
        Process::shouldReceive('timeout')->once()->andReturnSelf();
        Process::shouldReceive('run')->once()->andReturnUsing(function () {
            $this->database->table('file_registry')->where('id', 71)->update(['date_taken' => '1901-01-01', 'date_taken_source' => 'user_manual',
                'exif_caption' => 'Operator caption', 'gps_latitude' => '12', 'camera_make' => 'Operator camera']);

            return $this->toolResult(['DateTimeOriginal' => '1920:04:03 12:00:00', 'Description' => 'Embedded caption']);
        });
        $this->assertSame(1, $this->runStage()['checked']);
        $this->assertSame('1901-01-01', $this->file()->date_taken);
        $this->assertSame('user_manual', $this->file()->date_taken_source);
        $this->assertSame('Operator caption', $this->file()->exif_caption);
        $this->assertSame('12', $this->file()->gps_latitude);
        $this->assertSame('Operator camera', $this->file()->camera_make);
    }

    public function test_same_size_same_time_replacement_is_detected_by_post_read_hash(): void
    {
        Process::shouldReceive('timeout')->once()->andReturnSelf();
        Process::shouldReceive('run')->once()->andReturnUsing(function () {
            $bytes = file_get_contents($this->image);
            $mtime = filemtime($this->image);
            $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
            file_put_contents($this->image, $bytes);
            touch($this->image, $mtime);

            return $this->toolResult(['DateTimeOriginal' => '1920:04:03 12:00:00']);
        });
        $this->assertSame(1, $this->runStage()['errors']);
        $this->assertNull($this->file()->exif_checked);
        $this->assertNull($this->file()->date_taken);
    }

    public function test_source_hash_unavailable_is_held_without_running_metadata_tool(): void
    {
        $this->api->shouldReceive('computeFileHash')->once()->andReturn(['success' => false, 'error' => 'source held']);
        Process::shouldReceive('timeout')->never();
        $this->assertSame(1, $this->runStage()['errors']);
        $this->assertNull($this->file()->exif_checked);
    }

    #[DataProvider('registryChanges')]
    public function test_registry_generation_or_exact_path_change_holds_update_without_marking_checked(string $field, string $value): void
    {
        Process::shouldReceive('timeout')->once()->andReturnSelf();
        Process::shouldReceive('run')->once()->andReturnUsing(function () use ($field, $value) {
            $this->database->table('file_registry')->where('id', 71)->update([$field => $value]);

            return $this->toolResult();
        });
        $this->assertSame(1, $this->runStage()['errors']);
        $this->assertNull($this->file()->exif_checked);
    }

    public static function registryChanges(): array
    {
        return [['lifecycle_generation_key', 'generation-b'], ['current_path', '/SOURCE.png']];
    }

    public function test_source_change_during_publication_rolls_back_all_exif_values_and_checked_marker(): void
    {
        $this->readTool(['DateTimeOriginal' => '1920:04:03 12:00:00', 'Description' => 'Embedded caption']);
        $hashCalls = 0;
        $this->api->shouldReceive('computeFileHash')->times(3)->andReturnUsing(function () use (&$hashCalls) {
            if (++$hashCalls === 3) {
                $this->assertSame(1, $this->file()->exif_checked, 'The final source check must occur after the uncommitted update');
                file_put_contents($this->image, 'source changed while catalog was updated');
            }
            clearstatcache(true, $this->image);

            return ['success' => true, 'hash' => hash_file('sha256', $this->image),
                'source_fingerprint' => array_intersect_key(stat($this->image), array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime']))];
        });
        $result = $this->runStage();
        $this->assertSame(1, $result['errors']);
        $this->assertSame(['exif_source_changed' => 1], $result['error_reasons']);
        $this->assertNull($this->file()->exif_checked);
        $this->assertNull($this->file()->date_taken);
        $this->assertNull($this->file()->date_taken_source);
        $this->assertNull($this->file()->exif_caption);
    }

    public function test_configured_tool_executable_and_timeout_are_used(): void
    {
        config(['file_registry.exif_read.executable' => '/configured/exif-reader', 'file_registry.exif_read.timeout_seconds' => 7]);
        Process::shouldReceive('timeout')->once()->with(7.0)->andReturnSelf();
        Process::shouldReceive('run')->once()->withArgs(fn ($args) => $args[0] === '/configured/exif-reader')->andReturn($this->toolResult());
        $this->assertSame(1, $this->runStage()['checked']);
    }

    public function test_error_metadata_even_with_success_exit_and_correct_source_path_stays_pending(): void
    {
        $this->readTool(['Error' => 'Unsupported source']);
        $result = $this->runStage();
        $this->assertSame(['exif_metadata_read_invalid' => 1], $result['error_reasons']);
        $this->assertNull($this->file()->exif_checked);
    }

    public function test_legacy_contained_physical_path_is_hash_verified_through_exact_connector_round_trip_without_remapping(): void
    {
        $this->database->table('file_registry')->where('id', 71)->update(['current_path' => $this->image]);
        $this->readTool(['DateTimeOriginal' => '1920:04:03 12:00:00']);
        $this->assertSame(1, $this->runStage()['checked']);
        $this->assertSame($this->image, $this->file()->current_path);
        $this->assertSame('exif_original', $this->file()->date_taken_source);
    }

    public function test_legacy_physical_path_without_exact_connector_round_trip_is_held(): void
    {
        $this->database->table('file_registry')->where('id', 71)->update(['current_path' => $this->image]);
        $this->api->shouldReceive('localPath')->once()->with('/source.png')->andReturn(null);
        $this->api->shouldReceive('computeFileHash')->never();
        Process::shouldReceive('timeout')->never();
        $this->assertSame(['exif_source_unproven' => 1], $this->runStage()['error_reasons']);
        $this->assertNull($this->file()->exif_checked);
    }

    #[DataProvider('outsidePaths')]
    public function test_outside_root_or_symlink_escape_stays_pending(string $kind): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'exif-outside-');
        $link = $this->root.'/escape.png';
        try {
            file_put_contents($outside, 'outside source');
            if ($kind === 'symlink') {
                symlink($outside, $link);
            }
            $this->database->table('file_registry')->where('id', 71)->update(['current_path' => $kind === 'symlink' ? $link : $outside]);
            $this->api->shouldReceive('computeFileHash')->never();
            Process::shouldReceive('timeout')->never();
            $this->assertSame(['exif_source_unavailable' => 1], $this->runStage()['error_reasons']);
            $this->assertNull($this->file()->exif_checked);
        } finally {
            @unlink($link);
            @unlink($outside);
        }
    }

    public static function outsidePaths(): array
    {
        return [['direct'], ['symlink']];
    }

    public function test_dry_run_and_zero_limit_do_not_read_or_write_sources(): void
    {
        Process::shouldReceive('timeout')->never();
        $this->api->shouldReceive('localPath')->never();
        $this->api->shouldReceive('computeFileHash')->never();
        $this->assertSame(1, $this->runStage(dryRun: true)['pending']);
        $this->assertSame(['processed' => 0, 'checked' => 0, 'no_date' => 0, 'errors' => 0], $this->runStage(limit: 0));
        $this->assertNull($this->file()->exif_checked);
    }

    private function readTool(array $fields = []): void
    {
        Process::shouldReceive('timeout')->once()->andReturnSelf();
        Process::shouldReceive('run')->once()->withArgs(fn ($args) => $args[0] === '/usr/bin/exiftool' && end($args) === $this->image)->andReturn($this->toolResult($fields));
    }

    private function toolResult(array $fields = [], bool $success = true): object
    {
        $result = Mockery::mock();
        $result->shouldReceive('successful')->andReturn($success);
        $result->shouldReceive('output')->andReturn(json_encode([['SourceFile' => $this->image, ...$fields]], JSON_THROW_ON_ERROR));

        return $result;
    }

    private function runStage(int $limit = 5, bool $dryRun = false): array
    {
        return (new ReflectionMethod(FileEnrichmentCommand::class, 'processExifDates'))->invoke(new FileEnrichmentCommand, $limit, $dryRun);
    }

    private function file(): object
    {
        return $this->database->table('file_registry')->where('id', 71)->first();
    }
}
