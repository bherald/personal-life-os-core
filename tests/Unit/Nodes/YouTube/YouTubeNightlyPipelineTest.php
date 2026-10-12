<?php

namespace Tests\Unit\Nodes\YouTube;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Nodes\PushoverNotify;
use App\Nodes\YouTube\YouTubeJoplinCreate;
use App\Nodes\YouTube\YouTubeJoplinFinalize;
use App\Nodes\YouTube\YouTubeKeyPointsPostProcessor;
use App\Nodes\YouTube\YouTubePlaylist;
use App\Nodes\YouTube\YouTubeTranscript;
use App\Nodes\YouTube\YouTubeTranscriptFormatter;
use App\Nodes\YouTube\YouTubeWatchLaterOrganize;
use App\Services\AIService;
use App\Services\JoplinFilesService;
use App\Services\JoplinYouTubeKnowledgeService;
use App\Services\JoplinYouTubeOrganizer;
use App\Services\SystemConfigService;
use App\Services\YouTubeApiService;
use App\Services\YouTubeJoplinDeliveryService;
use App\Services\YouTubeJoplinService;
use App\Services\YouTubeTranscriptService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\Support\CreatesYouTubeSourceCustodySchema;
use Tests\TestCase;

class YouTubeNightlyPipelineTest extends TestCase
{
    use CreatesYouTubeSourceCustodySchema;

    private JoplinFilesService $reader;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'nightly_test', 'database.connections.nightly_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ], 'youtube.enabled' => true]);
        DB::purge('nightly_test');
        $this->createSourceCustodySchema();
        Schema::create('workflows', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('schedule')->nullable();
            $table->boolean('active');
            $table->string('error_handling');
            $table->timestamps();
        });
        Schema::create('workflow_nodes', function (Blueprint $table): void {
            $table->id();
            $table->integer('workflow_id');
            $table->string('node_type');
            $table->integer('node_order');
            $table->unique(['workflow_id', 'node_order']);
        });
        Schema::create('workflow_node_configs', function (Blueprint $table): void {
            $table->id();
            $table->integer('workflow_node_id');
            $table->string('config_key');
            $table->text('config_value');
            $table->unique(['workflow_node_id', 'config_key']);
        });
        Schema::create('scheduled_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('job_type');
            $table->string('command');
            $table->string('cron_expression');
            $table->boolean('enabled');
            $table->boolean('without_overlapping');
            $table->integer('max_parallel');
            $table->timestamps();
        });
        Schema::create('system_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('section');
            $table->string('config_key');
            $table->text('config_value');
            $table->string('data_type');
            $table->text('description');
            $table->timestamps();
        });
        Schema::create('youtube_playlist_progress', function (Blueprint $table): void {
            $table->id();
            $table->string('playlist_id');
            $table->string('last_video_id');
            $table->string('last_video_added_at');
        });
        $this->migration()->up();
        $this->reader = Mockery::mock(JoplinFilesService::class)->makePartial();
        $this->app->instance(JoplinFilesService::class, $this->reader);
    }

    protected function tearDown(): void
    {
        DB::purge('nightly_test');
        Mockery::close();
        parent::tearDown();
    }

    public function test_discovery_keeps_videos_older_than_legacy_watermark_and_does_not_advance_it(): void
    {
        DB::table('youtube_playlist_progress')->insert(['playlist_id' => 'list', 'last_video_id' => 'newestvideo', 'last_video_added_at' => '2026-10-05']);
        $api = Mockery::mock(YouTubeApiService::class);
        $api->shouldReceive('getPlaylistItems')->once()->andReturn(['items' => [[
            'contentDetails' => ['videoId' => 'abcdefghijk'], 'snippet' => ['publishedAt' => '2026-10-01T00:00:00Z'],
        ]]]);
        $api->shouldReceive('getVideoDetails')->once()->andReturn(['items' => [[
            'snippet' => ['title' => 'Video', 'channelId' => 'channel', 'channelTitle' => 'Channel', 'publishedAt' => '2026-09-01'],
            'contentDetails' => ['duration' => 'PT1M'], 'statistics' => [],
        ]]]);
        $this->app->instance(YouTubeApiService::class, $api);
        $output = (new YouTubePlaylist(['playlist_id' => 'list']))->execute([]);
        $this->assertNull($output['error']);
        $this->assertSame('abcdefghijk', $output['data']['videos'][0]['video_id']);
        $this->assertSame('newestvideo', DB::table('youtube_playlist_progress')->value('last_video_id'));
        $this->assertNull(DB::table('youtube_joplin_deliveries')->value('verified_at'));
    }

    public function test_only_verified_complete_matching_notes_are_excluded_from_next_run(): void
    {
        $service = app(YouTubeJoplinDeliveryService::class);
        $videos = [$this->video('abcdefghijk'), $this->video('lmnopqrstuv')];
        $this->reader->shouldReceive('getNote')->with(str_repeat('a', 32), true)->twice()
            ->andReturn($this->note('abcdefghijk'));
        $result = $service->verify('list', [$videos[0] + ['joplin_note_id' => str_repeat('a', 32)], $videos[1]]);
        $this->assertSame(['verified' => 1, 'retry' => 1, 'reasons' => ['lmnopqrstuv' => 'delivery_not_verified']], $result);
        $this->assertSame('lmnopqrstuv', $service->pendingItems('list', $videos)[0]['video_id']);
        $service->verify('list', [$videos[0] + ['joplin_note_id' => str_repeat('a', 32)]]);
        $this->assertSame(2, DB::table('youtube_joplin_deliveries')->count());
    }

    public function test_placeholder_wrong_identity_and_unavailable_read_back_stay_pending(): void
    {
        $this->reader->shouldReceive('getNote')->with('placeholder', true)->andReturn($this->note('abcdefghijk', true));
        $this->reader->shouldReceive('getNote')->with('wrong', true)->andReturn($this->note('abcdefghijk'));
        $this->reader->shouldReceive('getNote')->with('unavailable', true)->andThrow(new RuntimeException('Transport unavailable'));
        $service = app(YouTubeJoplinDeliveryService::class);
        $result = $service->verify('list', [
            $this->video('abcdefghijk') + ['joplin_note_id' => 'placeholder'],
            $this->video('lmnopqrstuv') + ['joplin_note_id' => 'wrong'],
            $this->video('zzzzzzzzzzz') + ['joplin_note_id' => 'unavailable'],
        ]);
        $this->assertSame(3, $result['retry']);
        $this->assertSame('key_points_incomplete', $result['reasons']['abcdefghijk']);
        $this->assertSame('read_back_unavailable', $result['reasons']['zzzzzzzzzzz']);
        $this->assertSame(0, DB::table('youtube_joplin_deliveries')->whereNotNull('verified_at')->count());
    }

    public function test_failed_attempts_do_not_starve_unattempted_videos(): void
    {
        $service = app(YouTubeJoplinDeliveryService::class);
        $first = $this->video('abcdefghijk');
        $second = $this->video('lmnopqrstuv');
        $service->attempted('list', $first);
        $this->assertSame('lmnopqrstuv', $service->pendingItems('list', [$first, $second])[0]['video_id']);
    }

    public function test_read_back_of_shorter_existing_transcript_is_not_completed(): void
    {
        $this->reader->shouldReceive('getNote')->with('old-source', true)->once()->andReturn($this->note('abcdefghijk'));
        $result = app(YouTubeJoplinDeliveryService::class)->verify('list', [$this->video('abcdefghijk') + [
            'joplin_note_id' => 'old-source', 'transcript_full_text' => 'Full original transcript content. Plus previously omitted original evidence.',
        ]]);
        $this->assertSame('original_transcript_not_retained', $result['reasons']['abcdefghijk']);
        $this->assertNull(DB::table('youtube_joplin_deliveries')->value('verified_at'));
    }

    public function test_reviewed_archived_original_preserves_edited_transcript_and_can_verify(): void
    {
        $note = $this->note('abcdefghijk');
        $note['content'] = str_replace('Full original transcript content.', 'Operator-edited transcript.', $note['content'])
            ."\n\n## Archived Original Transcript\n\nFull original transcript content.\n\n---\n";
        $this->reader->shouldReceive('getNote')->with('reviewed', true)->once()->andReturn($note);
        $result = app(YouTubeJoplinDeliveryService::class)->verify('list', [$this->video('abcdefghijk') + [
            'joplin_note_id' => 'reviewed', 'transcript_full_text' => 'Full original transcript content.',
        ]]);
        $this->assertSame(1, $result['verified']);
        $this->assertStringContainsString('Operator-edited transcript.', $note['content']);
    }

    public function test_reused_source_hold_cannot_be_promoted_by_preexisting_complete_note(): void
    {
        $this->reader->shouldReceive('getNote')->with('held', true)->once()->andReturn($this->note('abcdefghijk'));
        $result = app(YouTubeJoplinDeliveryService::class)->verify('list', [$this->video('abcdefghijk') + [
            'joplin_note_id' => 'held', 'transcript_full_text' => 'Full original transcript content.',
            'delivery_repair' => ['status' => 'hold', 'reason_code' => 'exact_stored_source_unavailable'],
        ]]);
        $this->assertSame(0, $result['verified']);
        $this->assertSame('exact_stored_source_unavailable', $result['reasons']['abcdefghijk']);
        $this->assertNull(DB::table('youtube_joplin_deliveries')->value('verified_at'));
    }

    public function test_reused_create_routes_only_exact_returned_note_to_guarded_reconciliation(): void
    {
        $video = $this->video('abcdefghijk') + ['title' => 'Source', 'transcript_available' => true];
        $notes = Mockery::mock(YouTubeJoplinService::class);
        $notes->shouldReceive('createVideoNote')->once()->with($video, 'YouTube Research')->andReturn([
            'success' => true, 'reused' => true, 'note_id' => str_repeat('a', 32),
            'notebook_id' => 'book', 'notebook_name' => 'Book', 'joplin_url' => 'fixture',
        ]);
        $this->app->instance(YouTubeJoplinService::class, $notes);
        $delivery = Mockery::mock(YouTubeJoplinDeliveryService::class);
        $delivery->shouldReceive('reconcileReusedVideo')->once()->with(Mockery::on(fn ($item) => $item['video_id'] === 'abcdefghijk'
            && $item['joplin_note_id'] === str_repeat('a', 32)), str_repeat('a', 32))
            ->andReturn(['status' => 'hold', 'reason_code' => 'existing_transcript_requires_review']);
        $this->app->instance(YouTubeJoplinDeliveryService::class, $delivery);
        $result = (new YouTubeJoplinCreate)->execute(['data' => ['playlist_id' => 'list', 'videos' => [$video]]]);
        $this->assertSame('list', $result['data']['playlist_id']);
        $this->assertSame('hold', $result['data']['videos'][0]['delivery_repair']['status']);
    }

    public function test_durable_uncertain_retention_stops_current_workflow_before_later_video_creation(): void
    {
        $video = $this->video('abcdefghijk') + ['title' => 'Source', 'transcript_available' => true];
        $notes = Mockery::mock(YouTubeJoplinService::class);
        $notes->shouldReceive('createVideoNote')->once()->andReturn([
            'success' => true, 'reused' => true, 'note_id' => str_repeat('a', 32),
            'notebook_id' => 'book', 'notebook_name' => 'Book', 'joplin_url' => 'fixture',
        ]);
        $this->app->instance(YouTubeJoplinService::class, $notes);
        $delivery = Mockery::mock(YouTubeJoplinDeliveryService::class);
        $delivery->shouldReceive('reconcileReusedVideo')->once()->andReturn(['status' => 'hold', 'reason_code' => 'source_write_outcome_unknown']);
        $this->app->instance(YouTubeJoplinDeliveryService::class, $delivery);
        $this->expectException(InferenceAdmissionException::class);
        $this->expectExceptionMessage('YouTube source write outcome is unknown; automatic processing held');
        (new YouTubeJoplinCreate)->execute(['data' => ['playlist_id' => 'list', 'videos' => [$video, $video]]]);
    }

    public function test_uncertain_retention_write_does_not_fallback_or_retry_and_stays_held(): void
    {
        $video = $this->video('abcdefghijk') + ['title' => 'Source', 'transcript_available' => true];
        $notes = Mockery::mock(YouTubeJoplinService::class);
        $notes->shouldReceive('createVideoNote')->once()->andReturn([
            'success' => true, 'reused' => true, 'note_id' => str_repeat('a', 32),
            'notebook_id' => 'book', 'notebook_name' => 'Book', 'joplin_url' => 'fixture',
        ]);
        $this->app->instance(YouTubeJoplinService::class, $notes);
        $delivery = Mockery::mock(YouTubeJoplinDeliveryService::class);
        $delivery->shouldReceive('reconcileReusedVideo')->once()->andThrow(new RuntimeException('cURL error 28: uncertain private receipt'));
        $this->app->instance(YouTubeJoplinDeliveryService::class, $delivery);
        $result = (new YouTubeJoplinCreate)->execute(['data' => ['playlist_id' => 'list', 'videos' => [$video]]]);
        $this->assertSame('source_retention_unverified', $result['data']['videos'][0]['delivery_repair']['reason_code']);
        $this->assertSame(0, $result['meta']['joplin_notes_fallback']);
        $this->assertStringNotContainsString('uncertain private receipt', json_encode($result));
    }

    public function test_wrapped_admission_stop_from_retention_is_not_swallowed_or_replayed(): void
    {
        $video = $this->video('abcdefghijk') + ['title' => 'Source', 'transcript_available' => true];
        $notes = Mockery::mock(YouTubeJoplinService::class);
        $notes->shouldReceive('createVideoNote')->once()->andReturn([
            'success' => true, 'reused' => true, 'note_id' => str_repeat('a', 32),
            'notebook_id' => 'book', 'notebook_name' => 'Book', 'joplin_url' => 'fixture',
        ]);
        $this->app->instance(YouTubeJoplinService::class, $notes);
        $stop = new RuntimeException('Original wrapped stop', 0, new InferenceAdmissionException('Held'));
        $delivery = Mockery::mock(YouTubeJoplinDeliveryService::class);
        $delivery->shouldReceive('reconcileReusedVideo')->once()->andThrow($stop);
        $this->app->instance(YouTubeJoplinDeliveryService::class, $delivery);
        try {
            (new YouTubeJoplinCreate)->execute(['data' => ['playlist_id' => 'list', 'videos' => [$video]]]);
            $this->fail('The original stop must reach the workflow boundary.');
        } catch (RuntimeException $observed) {
            $this->assertSame($stop, $observed);
        }
    }

    public function test_partial_delivery_records_retry_and_fails_without_pushover(): void
    {
        $notify = Mockery::mock(PushoverNotify::class);
        $notify->shouldNotReceive('execute');
        $this->app->bind(PushoverNotify::class, fn () => $notify);
        Http::fake();
        try {
            (new YouTubeJoplinFinalize)->execute(['data' => [
                'playlist_id' => 'list', 'delivery_candidates' => [$this->video('abcdefghijk')],
                'videos' => [], 'organization_stats' => ['status' => 'verified'],
            ]]);
            $this->fail('Incomplete delivery must fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('Nightly YouTube pipeline needs attention; unfinished videos remain eligible for retry', $error->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame('delivery_not_verified', DB::table('youtube_joplin_deliveries')->value('last_error'));
        $this->assertNull(DB::table('youtube_joplin_deliveries')->value('verified_at'));
    }

    public function test_verified_delivery_sends_one_completion_notice(): void
    {
        $this->reader->shouldReceive('getNote')->with('complete', true)->once()->andReturn($this->note('abcdefghijk'));
        $notify = Mockery::mock(PushoverNotify::class);
        $notify->shouldReceive('execute')->once()->with(Mockery::on(fn ($output) => $output['data']['pipeline_status'] === 'complete'
            && $output['data']['delivery_verified'] === 1 && $output['data']['delivery_retry'] === 0))->andReturn(['error' => null]);
        $this->app->bind(PushoverNotify::class, fn () => $notify);
        $result = (new YouTubeJoplinFinalize)->execute(['data' => [
            'playlist_id' => 'list', 'videos' => [$this->video('abcdefghijk') + ['joplin_note_id' => 'complete']],
            'organization_stats' => ['status' => 'verified'],
        ]]);
        $this->assertSame('complete', $result['data']['pipeline_status']);
        $this->assertSame(1, DB::table('youtube_joplin_deliveries')->whereNotNull('verified_at')->count());
    }

    public function test_failed_organization_does_not_send_completion_notice(): void
    {
        $notify = Mockery::mock(PushoverNotify::class);
        $notify->shouldNotReceive('execute');
        $this->app->bind(PushoverNotify::class, fn () => $notify);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nightly YouTube pipeline needs attention');
        (new YouTubeJoplinFinalize)->execute(['data' => [
            'playlist_id' => 'list', 'videos' => [],
            'organization_stats' => ['status' => 'verified', 'failed_operations' => 1],
        ]]);
    }

    public function test_empty_playlist_still_finishes_organization_before_success(): void
    {
        $result = (new YouTubeJoplinFinalize(['notify' => false]))->execute(['data' => [
            'playlist_id' => 'list', 'videos' => [], 'organization_stats' => ['status' => 'verified'],
        ]]);
        $this->assertSame('complete', $result['data']['pipeline_status']);
        $this->assertSame(0, $result['data']['delivery_verified']);
    }

    public function test_empty_and_disabled_stages_preserve_playlist_and_retry_candidates(): void
    {
        $input = ['data' => ['playlist_id' => 'list', 'videos' => [], 'delivery_candidates' => [$this->video('abcdefghijk')]]];
        $input = (new YouTubeTranscript(['language' => 'en', 'include_timestamps' => false, 'batch_size' => 1]))->execute($input);
        $this->assertSame('list', $input['data']['playlist_id']);
        $input = (new YouTubeTranscriptFormatter(['enabled' => false, 'batch_size' => 1,
            'skip_if_no_transcript' => true, 'missing_key_points_retries' => 0, 'batch_delay_seconds' => 0, 'max_seconds' => 1]))->execute($input);
        $input = (new YouTubeJoplinCreate)->execute($input);
        $this->assertSame('list', $input['data']['playlist_id']);
        $this->assertSame('abcdefghijk', $input['data']['delivery_candidates'][0]['video_id']);
    }

    public function test_migration_unifies_schedule_and_order_and_rollback_retains_receipts(): void
    {
        $this->seedWorkflow();
        $before = DB::table('workflow_node_configs')->orderBy('id')->get()->toArray();
        $migration = $this->migration();
        $migration->up();
        $migration->up();
        $this->assertSame('0 1 * * *', DB::table('workflows')->value('schedule'));
        $this->assertSame('stop', DB::table('workflows')->value('error_handling'));
        $this->assertSame('App\\Nodes\\YouTube\\YouTubeJoplinFinalize', DB::table('workflow_nodes')->orderByDesc('node_order')->value('node_type'));
        $this->assertSame(0, DB::table('scheduled_jobs')->where('name', 'joplin_youtube_organize')->value('enabled'));
        app(YouTubeJoplinDeliveryService::class)->attempted('list', $this->video('abcdefghijk'));
        $migration->down();
        $this->assertSame('0 2 * * *', DB::table('workflows')->value('schedule'));
        $this->assertSame('continue', DB::table('workflows')->value('error_handling'));
        $this->assertEquals($before, DB::table('workflow_node_configs')->orderBy('id')->get()->toArray());
        $this->assertSame(1, DB::table('scheduled_jobs')->where('name', 'joplin_youtube_organize')->value('enabled'));
        $this->assertSame(1, DB::table('youtube_joplin_deliveries')->count());
    }

    public function test_migration_preserves_custom_weekly_command_and_disabled_nightly_schedule(): void
    {
        $this->seedWorkflow();
        DB::table('scheduled_jobs')->where('name', 'joplin_youtube_organize')->update(['command' => 'custom:maintenance']);
        DB::table('scheduled_jobs')->where('job_type', 'workflow')->update(['enabled' => false, 'cron_expression' => '0 4 * * *']);
        $this->migration()->up();
        $this->assertSame('0 4 * * *', DB::table('workflows')->value('schedule'));
        $this->assertSame(0, DB::table('scheduled_jobs')->where('job_type', 'workflow')->value('enabled'));
        $this->assertSame(1, DB::table('scheduled_jobs')->where('name', 'joplin_youtube_organize')->value('enabled'));
    }

    public function test_pipeline_preserves_original_and_reports_verified_delivery_after_organization(): void
    {
        $policy = Mockery::mock(SystemConfigService::class);
        $policy->shouldReceive('get')->andReturnUsing(fn ($key, $default) => $default);
        $this->app->instance(SystemConfigService::class, $policy);
        $transcript = Mockery::mock(YouTubeTranscriptService::class);
        $transcript->shouldReceive('getTranscriptWithBackoff')->once()->andReturn([
            'success' => true, 'full_text' => 'Full original transcript content.', 'language' => 'en',
            'word_count' => 4, 'caption_type' => 'manual', 'transcript' => [],
        ]);
        $this->app->instance(YouTubeTranscriptService::class, $transcript);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('process')->once()->andReturn(['success' => true,
            'response' => "## Key Points\n\n- A concrete point.\n- Another concrete point.\n- A third concrete point.\n\n---\n\nSupplemental formatted text."]);
        $this->app->instance(AIService::class, $ai);
        $writer = Mockery::mock(YouTubeJoplinService::class);
        $writer->shouldReceive('createVideoNote')->once()->with(Mockery::on(fn ($video) => $video['transcript_original'] === 'Full original transcript content.'
            && $video['key_points'] === ['A concrete point.', 'Another concrete point.', 'A third concrete point.']), Mockery::type('string'))
            ->andReturn(['success' => true, 'note_id' => str_repeat('a', 32), 'notebook_id' => 'topic',
                'notebook_name' => 'YouTube', 'joplin_url' => 'joplin://source']);
        $this->app->instance(YouTubeJoplinService::class, $writer);
        $organizer = Mockery::mock(JoplinYouTubeOrganizer::class);
        $organizer->shouldReceive('getJoplinPath')->andReturn('sync/');
        $organizer->shouldReceive('getWatchLaterFolderId')->andReturn('watch-later');
        $organizer->shouldReceive('listDirectory')->andReturn([]);
        $organizer->shouldReceive('getFileContentsBatch')->andReturn([]);
        $this->app->instance(JoplinYouTubeOrganizer::class, $organizer);
        $knowledge = Mockery::mock(JoplinYouTubeKnowledgeService::class);
        $knowledge->shouldReceive('run')->with(true, 0, false)->once()->andReturn([
            'status' => 'verified', 'failed_operations' => 0, 'applied_moves' => 1, 'managed_index_conflict_count' => 0,
        ]);
        $this->app->instance(JoplinYouTubeKnowledgeService::class, $knowledge);
        $this->reader->shouldReceive('getNote')->with(str_repeat('a', 32), true)->once()->andReturn(array_replace($this->note('abcdefghijk'), ['content' => str_replace('- A concrete point.', "- A concrete point.\n- Another concrete point.\n- A third concrete point.", $this->note('abcdefghijk')['content'])]));
        $input = ['data' => ['playlist_id' => 'list', 'delivery_candidates' => [$this->video('abcdefghijk')],
            'videos' => [$this->video('abcdefghijk') + ['title' => 'Source']]]];
        $stages = [new YouTubeTranscript(['language' => 'en', 'batch_size' => 1]),
            new YouTubeTranscriptFormatter(['min_formatted_chars' => 10, 'batch_delay_seconds' => 0]),
            new YouTubeJoplinCreate, new YouTubeKeyPointsPostProcessor(['dry_run' => true]),
            new YouTubeWatchLaterOrganize, new YouTubeJoplinFinalize(['notify' => false])];
        foreach ($stages as $stage) {
            $input = $stage->execute($input);
            $this->assertNull($input['error']);
        }
        $this->assertSame('complete', $input['data']['pipeline_status']);
        $this->assertSame(1, $input['data']['delivery_verified']);
        $this->assertSame(0, $input['data']['delivery_retry']);
        $this->assertSame(1, DB::table('youtube_joplin_deliveries')->whereNotNull('verified_at')->count());
    }

    private function video(string $id): array
    {
        return ['video_id' => $id, 'added_to_playlist_at' => '2026-10-01T00:00:00Z'];
    }

    private function note(string $id, bool $placeholder = false): array
    {
        $points = $placeholder ? '*Key points will be extracted*' : '- A concrete point.';

        return ['type' => 1, 'title' => 'Preserved source', 'metadata' => [], 'content' => "**Video:** https://youtu.be/{$id}\n\n---\n\n## Key Points\n\n{$points}\n\n---\n\n## Full Transcript\n\n**Transcript Stats:**\n- Word Count: 4\n- Caption Type: manual\n- Language: en\n\nFull original transcript content.\n\n---\n\n## Metadata\n"];
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_05_180000_unify_youtube_joplin_nightly_pipeline.php');
    }

    private function seedWorkflow(): void
    {
        DB::table('workflows')->insert(['id' => 14, 'name' => 'youtube_watch_later', 'schedule' => '0 2 * * *', 'active' => true, 'error_handling' => 'continue']);
        foreach (['YouTubePlaylist', 'YouTubeTranscript', 'YouTubeTranscriptFormatter', 'YouTubeJoplinCreate', 'YouTubeKeyPointsPostProcessor', 'YouTubeWatchLaterOrganize'] as $index => $type) {
            DB::table('workflow_nodes')->insert(['id' => $index + 1, 'workflow_id' => 14, 'node_type' => 'App\\Nodes\\YouTube\\'.$type, 'node_order' => $index + 1]);
        }
        DB::table('workflow_nodes')->insert(['id' => 7, 'workflow_id' => 14, 'node_type' => 'App\\Nodes\\PushoverNotify', 'node_order' => 7]);
        DB::table('workflow_node_configs')->insert(['workflow_node_id' => 7, 'config_key' => 'source_group', 'config_value' => 'workflow_routine_updates']);
        DB::table('scheduled_jobs')->insert([
            ['name' => 'workflow_youtube_watch_later', 'job_type' => 'workflow', 'command' => 'youtube_watch_later', 'cron_expression' => '0 1 * * *', 'enabled' => true, 'without_overlapping' => false, 'max_parallel' => 2],
            ['name' => 'joplin_youtube_organize', 'job_type' => 'command', 'command' => 'joplin:youtube-knowledge --apply --deduplicate --json', 'cron_expression' => '0 3 * * 0', 'enabled' => true, 'without_overlapping' => true, 'max_parallel' => 1],
        ]);
    }
}
