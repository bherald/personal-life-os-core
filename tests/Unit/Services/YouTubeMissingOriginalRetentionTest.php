<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\Custody\TaskCustodyService;
use App\Services\JoplinFilesService;
use App\Services\JoplinLockHandler;
use App\Services\JoplinWriteService;
use App\Services\YouTubeJoplinDeliveryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\CreatesYouTubeSourceCustodySchema;
use Tests\TestCase;

class YouTubeMissingOriginalRetentionTest extends TestCase
{
    use CreatesYouTubeSourceCustodySchema;

    private JoplinWriteService $writer;

    private JoplinLockHandler $locks;

    private string $noteId;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'source_retention_test', 'database.connections.source_retention_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('source_retention_test');
        $this->createSourceCustodySchema();
        Schema::create('youtube_joplin_deliveries', function (Blueprint $table): void {
            $table->string('playlist_id');
            $table->string('video_id');
            $table->timestamp('verified_at')->nullable();
        });
        Schema::create('youtube_transcripts', function (Blueprint $table): void {
            $table->id();
            $table->string('video_id')->collation('NOCASE');
            $table->string('language')->default('en');
            $table->text('content');
            $table->string('source_method');
            $table->string('fetched_at');
        });
        Schema::create('joplin_queue_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
        });
        Schema::create('system_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('section');
            $table->string('config_key');
            $table->text('config_value');
        });
        DB::table('youtube_transcripts')->insert(['id' => 7, 'video_id' => 'abcdefghijk', 'content' => 'Exact full original source.', 'source_method' => 'fixture', 'fetched_at' => '2026-10-09']);
        DB::table('system_configs')->insert(['section' => 'joplin', 'config_key' => 'youtube_knowledge', 'config_value' => json_encode(['rewrite' => ['backup_relative' => 'youtube/backups']])]);
        $this->noteId = str_repeat('a', 32);
        $this->writer = Mockery::mock(JoplinWriteService::class)->makePartial();
        $this->writer->shouldReceive('assertReviewTargetCompatible')->andReturnNull();
        $this->app->instance(JoplinWriteService::class, $this->writer);
        $this->locks = Mockery::mock(JoplinLockHandler::class);
        $this->app->instance(JoplinLockHandler::class, $this->locks);
        $this->app->instance(JoplinFilesService::class, Mockery::mock(JoplinFilesService::class)->makePartial());
        $this->storage = sys_get_temp_dir().'/youtube-source-'.bin2hex(random_bytes(6));
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->storage);
        }
        DB::purge('source_retention_test');
        Mockery::close();
        parent::tearDown();
    }

    private function snapshot(string $extra = ''): array
    {
        $body = "Operator title\n\n**Video:** https://youtu.be/abcdefghijk\n\nOperator edits preserved.{$extra}\n\nid: {$this->noteId}\nparent_id: ".str_repeat('b', 32)."\ntype_: 1\ncreated_time: 1\nupdated_time: 2\nuser_updated_time: 2\ncustom: keep\n";

        return ['body' => $body, 'etag' => 'revision', 'hash' => hash('sha256', $body)];
    }

    public function test_placeholder_source_requires_unique_exact_video_original_and_canonical_section(): void
    {
        $body = "**Video:** https://youtu.be/abcdefghijk\n\n## Key Points\n\n*Key points will be extracted*\n\n---\n\n## Full Transcript\n\nExact full original source.";
        $note = ['id' => $this->noteId, 'type' => 1, 'title' => 'Operator title', 'content' => $body, 'raw' => 'exact wire'];
        $service = app(YouTubeJoplinDeliveryService::class);
        $binding = $service->placeholderSource($note);
        $this->assertSame(7, $binding['source_id']);
        $this->assertSame('Exact full original source.', $binding['content']);
        $this->assertSame(hash('sha256', $note['raw']), $binding['wire_hash']);
        $this->assertNull($service->placeholderSource(array_replace($note, ['content' => str_replace('original source.', 'edited source.', $body)])));
        $this->assertNull($service->placeholderSource(array_replace($note, ['content' => str_replace('abcdefghijk', 'xxxxxxxxxxx', $body)])));
        $this->assertNull($service->placeholderSource(array_replace($note, ['content' => str_replace('*Key points will be extracted*', 'Operator-edited points.', $body)])));
        DB::table('youtube_transcripts')->insert(['id' => 8, 'video_id' => 'abcdefghijk', 'language' => 'fr',
            'content' => 'Exact full original source.', 'source_method' => 'fixture', 'fetched_at' => '2026-10-09']);
        $this->assertNull($service->placeholderSource($note));
    }

    public function test_case_insensitive_database_lookup_cannot_bind_a_different_video(): void
    {
        DB::table('youtube_transcripts')->where('id', 7)->update(['video_id' => 'Abcdefghijk']);
        $this->assertSame(1, DB::table('youtube_transcripts')->where('video_id', 'abcdefghijk')->count());
        $body = "**Video:** https://youtu.be/abcdefghijk\n\n## Key Points\n\n*Key points will be extracted*\n\n---\n\nExact full original source.";
        $service = app(YouTubeJoplinDeliveryService::class);
        $this->assertNull($service->placeholderSource(['id' => $this->noteId, 'type' => 1, 'title' => 'Operator title', 'content' => $body, 'raw' => 'exact wire']));
        $this->writer->shouldNotReceive('reviewSnapshot');
        $this->expectExceptionMessage('Exact stored transcript is missing');
        $service->retainMissingOriginal('abcdefghijk', $this->noteId, 7, replacePlaceholder: true);
    }

    public function test_canonical_placeholder_replacement_reuses_durable_intent_and_exact_readback(): void
    {
        $before = $this->snapshot("\n\n## Key Points\n\n*Key points will be extracted*\n\n---\n\n## Full Transcript\n\nExact full original source.\n\nQuoted marker: *Key points will be extracted*");
        $points = ['One concrete source point.', 'Two concrete source points.', 'Three concrete source points.'];
        $this->locks->shouldReceive('acquireSyncLock')->once()->andReturn(['id' => 'fixture']);
        $this->locks->shouldReceive('releaseLock')->once();
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($before);
        $this->writer->shouldReceive('reviewPut')->once()->andReturnUsing(function ($path, $wire, $snapshot) use ($before): array {
            $intent = DB::table('task_custody_records')->sole();
            $envelope = json_decode($intent->result_envelope, true);
            $this->assertNull($intent->released_at);
            $this->assertSame($before, $snapshot);
            $this->assertSame(7, $envelope['source_id']);
            $this->assertSame(hash('sha256', $wire), $envelope['desired_wire_hash']);
            $this->assertStringContainsString('Quoted marker: *Key points will be extracted*', $wire);
            $this->assertSame(1, substr_count($wire, 'Exact full original source.'));
            $this->assertStringStartsWith('Operator title', $wire);
            $this->assertStringContainsString('Operator edits preserved.', $wire);
            $this->assertStringContainsString('custom: keep', $wire);

            return ['hash' => hash('sha256', $wire)];
        });
        $result = app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal('abcdefghijk', $this->noteId, 7, true,
            $before['hash'], hash('sha256', 'Exact full original source.'), $points, replacePlaceholder: true);
        $this->assertSame('retained', $result['status']);
        $this->assertSame('validated_placeholder_replace', $result['key_points_disposition']);
        $row = DB::table('task_custody_records')->sole();
        $this->assertNotNull($row->released_at);
        $this->assertSame('success', $row->outcome);
    }

    public function test_placeholder_lost_ack_holds_same_note_without_replay(): void
    {
        $before = $this->snapshot("\n\n## Key Points\n\n*Key points will be extracted*\n\n---\n\nExact full original source.");
        $this->locks->shouldReceive('acquireSyncLock')->once()->andReturn(['id' => 'fixture']);
        $this->locks->shouldReceive('releaseLock')->once();
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($before);
        $this->writer->shouldReceive('reviewPut')->once()->andThrow(new \RuntimeException('Lost acknowledgement'));
        $service = app(YouTubeJoplinDeliveryService::class);
        $result = $service->retainMissingOriginal('abcdefghijk', $this->noteId, 7, true,
            $before['hash'], hash('sha256', 'Exact full original source.'), ['One source point.', 'Two source points.', 'Three source points.'], replacePlaceholder: true);
        $this->assertSame('source_write_outcome_unknown', $result['reason_code']);
        $this->assertNull(DB::table('task_custody_records')->sole()->released_at);
        $this->assertSame('hold', $service->retainMissingOriginal('abcdefghijk', $this->noteId, 7, replacePlaceholder: true)['status']);
        $this->expectException(InferenceAdmissionException::class);
        $service->placeholderSource(['id' => $this->noteId, 'type' => 1, 'title' => 'Operator title',
            'content' => '**Video:** https://youtu.be/abcdefghijk', 'raw' => $before['body']]);
    }

    public function test_edited_or_duplicate_keypoint_sections_cannot_authorize_replacement(): void
    {
        foreach (["## Key Points\n\nOperator points.\n\n---", "## Key Points\n\n*Key points will be extracted*\n\n---\n\n## Key Points\n\nOperator points."] as $section) {
            $before = $this->snapshot("\n\n".$section."\n\nExact full original source.");
            $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($before);
            $result = app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal('abcdefghijk', $this->noteId, 7,
                keyPoints: ['One source point.', 'Two source points.', 'Three source points.'], replacePlaceholder: true);
            $this->assertSame('placeholder_source_binding_unqualified', $result['reason_code']);
        }
        $this->writer->shouldNotReceive('reviewPut');
        $this->assertSame(0, DB::table('task_custody_records')->count());
    }

    public function test_missing_original_preview_is_read_only_and_returns_only_provenance(): void
    {
        $before = $this->snapshot();
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($before);
        $this->writer->shouldNotReceive('reviewPut');
        $this->locks->shouldNotReceive('acquireSyncLock');
        $result = app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal('abcdefghijk', $this->noteId, 7);
        $this->assertSame('missing_original_append', $result['reason_code']);
        $this->assertSame($before['hash'], $result['wire_hash']);
        $this->assertArrayHasKey('desired_wire_hash', $result);
        $this->assertStringNotContainsString('Operator edits', json_encode($result));
        $this->assertDirectoryDoesNotExist($this->storage);
    }

    public function test_differing_existing_transcript_holds_without_a_second_source_section(): void
    {
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($this->snapshot("\n\n## Full Transcript\n\nEdited shorter source."));
        $this->writer->shouldNotReceive('reviewPut');
        $result = app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal('abcdefghijk', $this->noteId, 7);
        $this->assertSame('hold', $result['status']);
        $this->assertSame('existing_transcript_requires_review', $result['reason_code']);
        $this->assertArrayNotHasKey('desired_wire_hash', $result);
    }

    public function test_apply_backs_up_exact_wire_and_source_before_conditional_write(): void
    {
        $before = $this->snapshot();
        $this->locks->shouldReceive('acquireSyncLock')->once()->andReturn(['id' => 'fixture']);
        $this->locks->shouldReceive('releaseLock')->once()->with(['id' => 'fixture']);
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($before);
        $this->writer->shouldReceive('reviewPut')->once()->with($this->noteId.'.md', Mockery::on(function ($wire) use ($before): bool {
            $this->assertSame(1, substr_count($wire, '## Full Transcript'));
            $this->assertStringContainsString('created_time: 1', $wire);
            $this->assertStringContainsString('custom: keep', $wire);
            $this->assertStringNotContainsString("updated_time: 2\n", $wire);
            $this->assertStringContainsString('Exact full original source.', $wire);
            $parts = explode("\n---\n\n## Full Transcript", $wire, 2);
            $this->assertStringStartsWith(substr($before['body'], 0, strpos($before['body'], 'id: ')), $parts[0]);
            $backups = glob($this->storage.'/app/private/youtube/backups/*/abcdefghijk/'.$this->noteId.'.md');
            $this->assertCount(1, $backups);
            $this->assertSame($before['body'], file_get_contents($backups[0]));
            $this->assertSame('Exact full original source.', file_get_contents(dirname($backups[0]).'/source.txt'));

            return true;
        }), $before)->andReturnUsing(fn ($relative, $wire) => ['hash' => hash('sha256', $wire)]);
        $result = app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal('abcdefghijk', $this->noteId, 7, true, $before['hash'], hash('sha256', 'Exact full original source.'));
        $this->assertSame('retained', $result['status']);
    }

    public function test_original_already_present_is_an_idempotent_no_op(): void
    {
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($this->snapshot("\n\nExact full original source."));
        $this->writer->shouldNotReceive('reviewPut');
        $result = app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal('abcdefghijk', $this->noteId, 7);
        $this->assertSame('already_retained', $result['status']);
        $this->assertArrayNotHasKey('desired_wire_hash', $result);
    }

    public function test_changed_source_hash_is_held_before_any_remote_write(): void
    {
        $this->locks->shouldReceive('acquireSyncLock')->once()->andReturn(['id' => 'fixture']);
        $this->locks->shouldReceive('releaseLock')->once();
        $this->writer->shouldNotReceive('reviewSnapshot');
        $this->writer->shouldNotReceive('reviewPut');
        $this->expectExceptionMessage('Stored transcript changed since reviewed preview');
        app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal('abcdefghijk', $this->noteId, 7, true, str_repeat('a', 64), str_repeat('0', 64));
    }

    public function test_changed_preview_is_held_and_sync_lock_is_released_without_write(): void
    {
        $this->locks->shouldReceive('acquireSyncLock')->once()->andReturn(['id' => 'fixture']);
        $this->locks->shouldReceive('releaseLock')->once();
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($this->snapshot());
        $this->writer->shouldNotReceive('reviewPut');
        $this->expectExceptionMessage('Source note changed since reviewed preview');
        app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal('abcdefghijk', $this->noteId, 7, true, str_repeat('0', 64), hash('sha256', 'Exact full original source.'));
    }

    public function test_reused_missing_sections_append_validated_points_and_original_with_exact_guard(): void
    {
        $before = $this->snapshot();
        $this->locks->shouldReceive('acquireSyncLock')->once()->andReturn(['id' => 'fixture']);
        $this->locks->shouldReceive('releaseLock')->once();
        $this->writer->shouldReceive('reviewSnapshot')->twice()->andReturn($before);
        $this->writer->shouldReceive('reviewPut')->once()->with($this->noteId.'.md', Mockery::on(function ($wire) use ($before): bool {
            $this->assertStringStartsWith(substr($before['body'], 0, strpos($before['body'], 'id: ')), $wire);
            $this->assertSame(1, substr_count($wire, '## Key Points'));
            $this->assertSame(1, substr_count($wire, 'Exact full original source.'));
            $this->assertStringContainsString('- Third concrete source point.', $wire);

            return true;
        }), $before)->andReturnUsing(fn ($relative, $wire) => ['hash' => hash('sha256', $wire)]);
        $result = app(YouTubeJoplinDeliveryService::class)->reconcileReusedVideo([
            'video_id' => 'abcdefghijk', 'transcript_language' => 'en', 'transcript_full_text' => 'Exact full original source.',
            'transcript_formatted' => true, 'key_points' => ['First concrete source point.', 'Second concrete source point.', 'Third concrete source point.'],
        ], $this->noteId);
        $this->assertSame('retained', $result['status']);
        $this->assertSame('validated_append', $result['key_points_disposition']);
    }

    public function test_reused_changed_stored_source_is_held_without_snapshot_or_write(): void
    {
        $this->writer->shouldNotReceive('reviewSnapshot');
        $this->writer->shouldNotReceive('reviewPut');
        $this->locks->shouldNotReceive('acquireSyncLock');
        $result = app(YouTubeJoplinDeliveryService::class)->reconcileReusedVideo([
            'video_id' => 'abcdefghijk', 'transcript_full_text' => 'A differing incoming source.',
        ], $this->noteId);
        $this->assertSame('exact_stored_source_unavailable', $result['reason_code']);
    }

    public function test_invalid_or_unformatted_points_are_not_published(): void
    {
        foreach ([['One', 'Two'], ['One', 'One', 'Three'], ['One', ' one ', 'Three'], ['One', "Two\n## Metadata", 'Three'], ['One', 'pending', 'Three'], array_fill(0, 9, 'Too many')] as $points) {
            $before = $this->snapshot();
            $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($before);
            $result = app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal('abcdefghijk', $this->noteId, 7, keyPoints: $points);
            $this->assertSame('required_key_points_missing', $result['key_points_disposition']);
        }
        $this->writer->shouldNotReceive('reviewPut');
    }

    public function test_original_present_only_appends_missing_points_and_never_duplicates_source(): void
    {
        $before = $this->snapshot("\n\nExact full original source.");
        $this->locks->shouldReceive('acquireSyncLock')->once()->andReturn(['id' => 'fixture']);
        $this->locks->shouldReceive('releaseLock')->once();
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($before);
        $this->writer->shouldReceive('reviewPut')->once()->with($this->noteId.'.md', Mockery::on(function ($wire): bool {
            $this->assertSame(1, substr_count($wire, 'Exact full original source.'));
            $this->assertStringNotContainsString('## Full Transcript', $wire);
            $this->assertSame(1, substr_count($wire, '## Key Points'));

            return true;
        }), $before)->andReturnUsing(fn ($relative, $wire) => ['hash' => hash('sha256', $wire)]);
        $result = app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal('abcdefghijk', $this->noteId, 7,
            true, $before['hash'], hash('sha256', 'Exact full original source.'), ['One source point.', 'Two source points.', 'Three source points.']);
        $this->assertSame('missing_key_points_append', $result['reason_code']);
    }

    public function test_explicit_edited_source_review_appends_archive_and_preserves_all_old_text(): void
    {
        $before = $this->snapshot("\n\n## Full Transcript\n\nOperator-edited source.");
        $this->locks->shouldReceive('acquireSyncLock')->once()->andReturn(['id' => 'fixture']);
        $this->locks->shouldReceive('releaseLock')->once();
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($before);
        $this->writer->shouldReceive('reviewPut')->once()->with($this->noteId.'.md', Mockery::on(function ($wire) use ($before): bool {
            $this->assertStringStartsWith(substr($before['body'], 0, strpos($before['body'], 'id: ')), $wire);
            $this->assertSame(1, substr_count($wire, '## Full Transcript'));
            $this->assertSame(1, substr_count($wire, '## Archived Original Transcript'));
            $this->assertStringContainsString('Operator-edited source.', $wire);

            return true;
        }), $before)->andReturnUsing(fn ($relative, $wire) => ['hash' => hash('sha256', $wire)]);
        $result = app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal('abcdefghijk', $this->noteId, 7,
            true, $before['hash'], hash('sha256', 'Exact full original source.'), preserveEditedTranscript: true);
        $this->assertSame('retained', $result['status']);
    }

    public function test_existing_key_points_are_preserved_and_complete_source_is_a_no_op(): void
    {
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($this->snapshot("\n\n## Key Points\n\nOperator points.\n\nExact full original source."));
        $this->writer->shouldNotReceive('reviewPut');
        $this->locks->shouldNotReceive('acquireSyncLock');
        $result = app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal('abcdefghijk', $this->noteId, 7,
            keyPoints: ['New one.', 'New two.', 'New three.']);
        $this->assertSame('already_retained', $result['status']);
        $this->assertSame('existing_section_preserved', $result['key_points_disposition']);
    }

    public function test_dispatched_put_with_failed_readback_remains_durable_and_cannot_replay(): void
    {
        $before = $this->snapshot();
        $this->locks->shouldReceive('acquireSyncLock')->once()->andReturn(['id' => 'fixture']);
        $this->locks->shouldReceive('releaseLock')->once();
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($before);
        $transport = new class extends JoplinWriteService
        {
            public function __construct()
            {
                $this->baseUrl = 'https://fixture.invalid';
                $this->username = 'fixture';
                $this->password = 'synthetic';
            }

            protected function http(): PendingRequest
            {
                return Http::withOptions([]);
            }
        };
        Http::preventStrayRequests();
        $wire = null;
        Http::fake(function ($request) use (&$wire) {
            if ($request->method() === 'PUT') {
                $row = DB::table('task_custody_records')->whereNull('released_at')->sole();
                $intent = json_decode($row->result_envelope, true);
                $wire = $request->body();
                $this->assertSame($this->noteId, $row->surface_ref);
                $this->assertSame(hash('sha256', $wire), $intent['desired_wire_hash']);
                $this->assertSame('revision', $request->header('If-Match')[0]);

                return Http::response('', 204);
            }

            return Http::response('', 503);
        });
        $this->writer->shouldReceive('reviewPut')->once()->andReturnUsing(fn ($relative, $body, $snapshot) => $transport->reviewPut($relative, $body, $snapshot));
        $service = app(YouTubeJoplinDeliveryService::class);
        $result = $service->retainMissingOriginal('abcdefghijk', $this->noteId, 7, true, $before['hash'], hash('sha256', 'Exact full original source.'));
        $this->assertSame('source_write_outcome_unknown', $result['reason_code']);
        $this->assertNull(DB::table('task_custody_records')->sole()->released_at);
        $this->assertSame('hold', $service->retainMissingOriginal('abcdefghijk', $this->noteId, 7)['status']);
        $this->assertSame([], $service->pendingItems('list', [['video_id' => 'abcdefghijk', 'added_to_playlist_at' => '2026-10-12']]));
        Http::assertSentCount(2);
    }

    private function uncertainCustody(string $desiredWire): int
    {
        return DB::table('task_custody_records')->insertGetId([
            'surface' => TaskCustodyService::SURFACE_YOUTUBE_SOURCE_WRITE,
            'surface_ref' => $this->noteId, 'owner_token' => 'original-owner',
            'result_envelope' => json_encode(['schema' => 'plos.youtube_source_write.v1', 'phase' => 'dispatch_intent',
                'video_id' => 'abcdefghijk', 'note_id' => $this->noteId, 'source_id' => 7,
                'source_hash' => hash('sha256', 'Exact full original source.'), 'desired_wire_hash' => hash('sha256', $desiredWire)]),
            'acquired_at' => now(), 'expires_at' => '2000-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_explicit_exact_readback_reconciliation_releases_original_owner_without_note_write(): void
    {
        $before = $this->snapshot(' Exact full original source.');
        $id = $this->uncertainCustody($before['body']);
        $this->writer->shouldReceive('reviewSnapshot')->twice()->andReturn($before);
        $this->writer->shouldNotReceive('reviewPut');
        $this->locks->shouldReceive('acquireSyncLock')->once()->andReturn(['id' => 'fixture']);
        $this->locks->shouldReceive('releaseLock')->once();
        $service = app(YouTubeJoplinDeliveryService::class);
        $preview = $service->reconcileSourceWrite($id, 'abcdefghijk', $this->noteId, 7);
        $this->assertSame('preview_verified', $preview['status']);
        $this->assertNull(DB::table('task_custody_records')->sole()->released_at);
        $result = $service->reconcileSourceWrite($id, 'abcdefghijk', $this->noteId, 7, true, $preview['custody_hash']);
        $this->assertSame('reconciled', $result['status']);
        $row = DB::table('task_custody_records')->sole();
        $this->assertSame('original-owner', $row->owner_token);
        $this->assertSame('success', $row->outcome);
        $this->assertNotNull($row->released_at);
    }

    public function test_reconciliation_metadata_drift_and_changed_source_remain_held(): void
    {
        $before = $this->snapshot(' Exact full original source.');
        $id = $this->uncertainCustody($before['body']);
        $drift = $before;
        $drift['hash'] = hash('sha256', $before['body'].'changed metadata');
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($drift);
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($before);
        $this->writer->shouldNotReceive('reviewPut');
        $service = app(YouTubeJoplinDeliveryService::class);
        $this->assertSame('hold', $service->reconcileSourceWrite($id, 'abcdefghijk', $this->noteId, 7)['status']);
        DB::table('youtube_transcripts')->where('id', 7)->update(['content' => 'Changed source.']);
        $this->assertSame('hold', $service->reconcileSourceWrite($id, 'abcdefghijk', $this->noteId, 7)['status']);
        $this->assertNull(DB::table('task_custody_records')->sole()->released_at);
    }

    public function test_reconciliation_changed_original_owner_cannot_release(): void
    {
        $before = $this->snapshot();
        $id = $this->uncertainCustody($before['body']);
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturnUsing(function () use ($id, $before) {
            DB::table('task_custody_records')->where('id', $id)->update(['owner_token' => 'different-owner']);

            return $before;
        });
        $this->writer->shouldNotReceive('reviewPut');
        $this->locks->shouldReceive('acquireSyncLock')->once()->andReturn(['id' => 'fixture']);
        $this->locks->shouldReceive('releaseLock')->once();
        $row = DB::table('task_custody_records')->sole();
        $hash = hash('sha256', $row->owner_token."\n".$row->result_envelope);
        try {
            app(YouTubeJoplinDeliveryService::class)->reconcileSourceWrite($id, 'abcdefghijk', $this->noteId, 7, true, $hash);
            $this->fail('Changed owner must retain custody.');
        } catch (\RuntimeException $failure) {
            $this->assertSame('Exact source write reconciliation owner changed', $failure->getMessage());
            $this->assertNull(DB::table('task_custody_records')->sole()->released_at);
        }
    }

    public function test_expired_source_attempt_cannot_be_released_by_generic_custody_service(): void
    {
        $id = $this->uncertainCustody($this->snapshot()['body']);
        $service = app(TaskCustodyService::class);
        $this->assertTrue($service->hasLiveOwner(DB::table('task_custody_records')->sole()));
        $this->expectExceptionMessage('Source write release requires exact read-back and terminal owner CAS.');
        $service->release($id, 'success');
    }

    public function test_apply_accepts_database_reordered_json_intent_without_weakening_types(): void
    {
        $this->applyWithReorderedIntent(null);
    }

    public function test_apply_rejects_database_intent_scalar_type_drift_before_put(): void
    {
        $this->applyWithReorderedIntent('type');
    }

    public function test_apply_rejects_database_intent_value_drift_before_put(): void
    {
        $this->applyWithReorderedIntent('value');
    }

    private function applyWithReorderedIntent(?string $drift): void
    {
        $before = $this->snapshot();
        $this->locks->shouldReceive('acquireSyncLock')->once()->andReturn(['id' => 'fixture']);
        $this->locks->shouldReceive('releaseLock')->once();
        $this->writer->shouldReceive('reviewSnapshot')->once()->andReturn($before);
        if ($drift === null) {
            $this->writer->shouldReceive('reviewPut')->once()->andReturnUsing(function ($relative, $wire): array {
                $this->assertSame($this->noteId.'.md', $relative);
                $intent = json_decode(DB::table('task_custody_records')->sole()->result_envelope, true);
                $this->assertSame(7, $intent['source_id']);
                $this->assertSame(hash('sha256', $wire), $intent['desired_wire_hash']);

                return ['hash' => hash('sha256', $wire)];
            });
        } else {
            $this->writer->shouldNotReceive('reviewPut');
        }
        $mutated = false;
        // Simulate MySQL's stored JSON ordering at the actual database read-back boundary.
        DB::connection()->beforeExecuting(function ($query, $bindings, $connection) use (&$mutated, $drift): void {
            if ($mutated || ! str_starts_with($query, 'select * from "task_custody_records"')) {
                return;
            }
            $row = $connection->getPdo()->query('SELECT id,result_envelope FROM task_custody_records WHERE released_at IS NULL')->fetch(\PDO::FETCH_ASSOC);
            if (! $row) {
                return;
            }
            $mutated = true;
            $intent = array_reverse(json_decode($row['result_envelope'], true), true);
            if ($drift === 'type') {
                $intent['source_id'] = (string) $intent['source_id'];
            } elseif ($drift === 'value') {
                $intent['desired_wire_hash'] = str_repeat('0', 64);
            }
            $statement = $connection->getPdo()->prepare('UPDATE task_custody_records SET result_envelope = ? WHERE id = ?');
            $statement->execute([json_encode($intent, JSON_THROW_ON_ERROR), $row['id']]);
        });
        try {
            $result = app(YouTubeJoplinDeliveryService::class)->retainMissingOriginal('abcdefghijk', $this->noteId, 7,
                true, $before['hash'], hash('sha256', 'Exact full original source.'));
            $this->assertNull($drift, 'A changed publication must stop before PUT.');
            $this->assertSame('retained', $result['status']);
            $this->assertSame('success', DB::table('task_custody_records')->sole()->outcome);
        } catch (\RuntimeException $failure) {
            $this->assertNotNull($drift);
            $this->assertSame('Exact source write intent publication failed; dispatch held', $failure->getMessage());
            $this->assertNull(DB::table('task_custody_records')->sole()->released_at);
        }
        $this->assertTrue($mutated, 'The actual stored-intent read-back must exercise reordering.');
    }
}
