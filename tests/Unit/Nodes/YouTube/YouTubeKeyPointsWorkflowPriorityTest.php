<?php

namespace Tests\Unit\Nodes\YouTube;

use App\Nodes\YouTube\YouTubeKeyPointsPostProcessor;
use App\Services\AIService;
use App\Services\Custody\TaskCustodyService;
use App\Services\JoplinFilesService;
use App\Services\JoplinWriteService;
use App\Services\JoplinYouTubeOrganizer;
use App\Services\TrustBoundaryFormatterService;
use App\Services\YouTubeJoplinDeliveryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\CreatesYouTubeSourceCustodySchema;
use Tests\TestCase;

class YouTubeKeyPointsWorkflowPriorityTest extends TestCase
{
    use CreatesYouTubeSourceCustodySchema;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'priority_test', 'database.connections.priority_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('priority_test');
        $this->createSourceCustodySchema();
        $this->createKeyPointTranscriptSchema(array_map(fn ($letter) => ['video_id' => str_repeat($letter, 11), 'content' => 'Exact original source content.'], ['a', 'b', 'c']));
        Schema::create('joplin_review_items', function (Blueprint $table): void {
            $table->string('note_id');
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        DB::purge('priority_test');
        parent::tearDown();
    }

    public function test_workflow_targets_precede_backlog_in_workflow_order_with_same_total_limit(): void
    {
        $result = $this->runSelection([$this->target('c'), $this->target('b')], ['c']);
        $this->assertSame([str_repeat('c', 32)], $result['data']['key_points_stats']['selected_note_ids']);
        $this->assertSame(1, $result['meta']['limit']);
        $this->assertSame(30, $result['meta']['max_seconds']);
        $this->assertSame(1, $result['meta']['notes_updated']);
    }

    public function test_existing_direct_route_keeps_global_directory_order(): void
    {
        $result = $this->runSelection([], ['a']);
        $this->assertSame([str_repeat('a', 32)], $result['data']['key_points_stats']['selected_note_ids']);
    }

    public function test_wrong_video_identity_is_not_repaired_as_a_workflow_target(): void
    {
        $result = $this->runSelection([$this->target('b', 'xxxxxxxxxxx')], ['a']);
        $this->assertSame([str_repeat('a', 32)], $result['data']['key_points_stats']['selected_note_ids']);
    }

    public function test_priority_does_not_expand_existing_folder_scope(): void
    {
        $result = $this->runSelection([$this->target('b')], ['a'], ['b' => 'outside'], allSources: false);
        $this->assertSame([str_repeat('a', 32)], $result['data']['key_points_stats']['selected_note_ids']);
    }

    public function test_malformed_target_does_not_change_existing_backlog_order(): void
    {
        $result = $this->runSelection([['joplin_note_id' => '../b', 'video_id' => 'bbbbbbbbbbb']], ['a']);
        $this->assertSame([str_repeat('a', 32)], $result['data']['key_points_stats']['selected_note_ids']);
    }

    public function test_preferred_review_mirror_remains_excluded(): void
    {
        DB::table('joplin_review_items')->insert(['note_id' => str_repeat('b', 32)]);
        $result = $this->runSelection([$this->target('b')], ['a']);
        $this->assertSame([str_repeat('a', 32)], $result['data']['key_points_stats']['selected_note_ids']);
    }

    public function test_exhausted_existing_deadline_prevents_ai_and_write(): void
    {
        $result = $this->runSelection([$this->target('b')], [], [], 0);
        $this->assertSame([str_repeat('b', 32)], $result['data']['key_points_stats']['selected_note_ids']);
        $this->assertSame(0, $result['meta']['notes_updated']);
    }

    public function test_input_hold_and_durable_hold_exclude_notes_before_ai_without_starving_eligible_note(): void
    {
        DB::table('task_custody_records')->insert([
            'surface' => TaskCustodyService::SURFACE_YOUTUBE_SOURCE_WRITE,
            'surface_ref' => str_repeat('a', 32), 'owner_token' => 'dead-owner',
            'result_envelope' => json_encode(['schema' => 'plos.youtube_source_write.v1', 'note_id' => str_repeat('a', 32), 'video_id' => str_repeat('a', 11)]),
            'acquired_at' => now(), 'expires_at' => '2000-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $target = $this->target('b') + ['delivery_repair' => ['status' => 'hold']];
        $result = $this->runSelection([$target], ['c']);
        $this->assertSame([str_repeat('c', 32)], $result['data']['key_points_stats']['selected_note_ids']);
        $this->assertNull(DB::table('task_custody_records')->sole()->released_at);
    }

    private function target(string $letter, ?string $video = null): array
    {
        return ['joplin_note_id' => str_repeat($letter, 32), 'video_id' => $video ?? str_repeat($letter, 11)];
    }

    private function runSelection(array $targets, array $expected, array $parents = [], int $deadline = 30, bool $allSources = true): array
    {
        $contents = [];
        $parsed = [];
        foreach (['a', 'b', 'c'] as $letter) {
            $id = str_repeat($letter, 32);
            $body = '**Video:** https://youtu.be/'.str_repeat($letter, 11)
                ."\n\n## Key Points\n\n*Key points will be extracted*\n\n---\n\n## Full Transcript\n\nExact original source content.\n\n---\n\n## Metadata\n";
            $raw = 'Synthetic title'."\n\n".$body."\nid: ".$id."\nparent_id: ".($parents[$letter] ?? 'watch-later')."\ntype_: 1\n";
            $contents[$id.'.md'] = $raw;
            $parsed[$raw] = ['type' => 1, 'id' => $id, 'title' => 'Synthetic title',
                'parent_id' => $parents[$letter] ?? 'watch-later', 'content' => $body, 'raw' => $raw];
        }
        $organizer = Mockery::mock(JoplinYouTubeOrganizer::class);
        $organizer->shouldReceive('getJoplinPath')->andReturn('sync/');
        $organizer->shouldReceive('getWatchLaterFolderId')->andReturn('watch-later');
        $organizer->shouldReceive('listDirectory')->andReturn(array_keys($contents));
        $organizer->shouldReceive('getFileContentsBatch')->with(array_keys($contents), 10)->andReturn($contents);
        $organizer->shouldReceive('parseNote')->andReturnUsing(fn ($raw) => $parsed[$raw]);
        $this->app->instance(JoplinYouTubeOrganizer::class, $organizer);
        $this->app->instance(JoplinFilesService::class, Mockery::mock(JoplinFilesService::class)->makePartial());
        $formatter = Mockery::mock(TrustBoundaryFormatterService::class);
        $ai = Mockery::mock(AIService::class);
        $writer = Mockery::mock(JoplinWriteService::class);
        $delivery = Mockery::mock(YouTubeJoplinDeliveryService::class, [app(JoplinFilesService::class)])->makePartial();
        if ($expected === []) {
            $formatter->shouldNotReceive('format');
            $ai->shouldNotReceive('process');
            $writer->shouldNotReceive('reviewSnapshot');
            $writer->shouldNotReceive('reviewPut');
        }
        foreach ($expected as $letter) {
            $path = str_repeat($letter, 32).'.md';
            $raw = $contents[$path];
            $snapshot = ['body' => $raw, 'etag' => 'current', 'hash' => hash('sha256', $raw)];
            $formatter->shouldReceive('format')->once()->andReturn('Exact original source content.');
            $ai->shouldReceive('process')->once()->andReturn(['success' => true, 'response' => "- A generated factual point.\n- A second generated point.\n- A third generated point."]);
            $delivery->shouldReceive('retainMissingOriginal')->once()
                ->with(str_repeat($letter, 11), str_repeat($letter, 32), array_search($letter, ['a', 'b', 'c']) + 1,
                    true, hash('sha256', $raw), hash('sha256', 'Exact original source content.'),
                    ['A generated factual point.', 'A second generated point.', 'A third generated point.'], false, true)
                ->andReturn(['status' => 'retained']);
        }
        $organizer->shouldNotReceive('putFileContent');
        $writer->shouldNotReceive('reviewSnapshot');
        $writer->shouldNotReceive('reviewPut');
        $this->app->instance(YouTubeJoplinDeliveryService::class, $delivery);
        $this->app->instance(TrustBoundaryFormatterService::class, $formatter);
        $this->app->instance(AIService::class, $ai);
        $this->app->instance(JoplinWriteService::class, $writer);

        return (new YouTubeKeyPointsPostProcessor([
            'all_youtube_sources' => $allSources, 'limit' => 1, 'dry_run' => false, 'file_batch_size' => 10,
            'max_seconds' => $deadline, 'title_preview_chars' => 50, 'transcript_min_chars' => 1,
            'transcript_max_chars' => 12000, 'ai_max_tokens' => 1000, 'ai_temperature' => 0.0,
        ]))->execute(['data' => ['videos' => $targets]]);
    }
}
