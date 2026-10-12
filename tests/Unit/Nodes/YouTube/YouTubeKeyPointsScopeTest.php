<?php

namespace Tests\Unit\Nodes\YouTube;

use App\Exceptions\AI\InferenceAdmissionException;
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

class YouTubeKeyPointsScopeTest extends TestCase
{
    use CreatesYouTubeSourceCustodySchema;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'points_scope_test', 'database.connections.points_scope_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('points_scope_test');
        $this->createSourceCustodySchema();
        $this->createKeyPointTranscriptSchema([['id' => 7, 'video_id' => 'abcdefghijk', 'content' => 'Full original source transcript content.']]);
        Schema::create('joplin_review_items', function (Blueprint $table): void {
            $table->string('note_id');
        });
        DB::table('joplin_review_items')->insert(['note_id' => 'mirror']);
    }

    protected function tearDown(): void
    {
        DB::purge('points_scope_test');
        Mockery::close();
        parent::tearDown();
    }

    public function test_backfills_moved_sources_with_conditional_write_and_excludes_mirror_and_incidental_links(): void
    {
        $result = $this->runProcessor(false);
        $this->assertSame(1, $result['data']['key_points_stats']['found']);
        $this->assertSame(1, $result['data']['key_points_stats']['updated']);
    }

    public function test_concurrent_source_edit_is_preserved(): void
    {
        $result = $this->runProcessor(true);
        $this->assertSame(1, $result['data']['key_points_stats']['failed']);
        $this->assertSame(0, $result['data']['key_points_stats']['updated']);
    }

    public function test_typed_controller_failure_reaches_workflow_boundary(): void
    {
        try {
            $this->runProcessor(false, true);
            $this->fail('Terminal AI failure must stop the workflow.');
        } catch (InferenceAdmissionException $stop) {
            $this->assertSame(['wait_reason' => 'wait_deadline', 'dispatched' => false], $stop->controllerFailure);
            $this->assertStringNotContainsString('private text', $stop->getMessage());
        }
    }

    public function test_original_wrapped_admission_stop_reaches_workflow_without_another_ai_or_write(): void
    {
        $stop = new \RuntimeException('Original wrapper', 0, new InferenceAdmissionException('Held'));
        try {
            $this->runProcessor(false, false, $stop);
            $this->fail('The original wrapped stop must reach the workflow.');
        } catch (\RuntimeException $observed) {
            $this->assertSame($stop, $observed);
        }
    }

    public function test_unknown_write_outcome_stops_workflow_before_next_eligible_note(): void
    {
        $this->expectException(InferenceAdmissionException::class);
        $this->runProcessor(false, unknownWrite: true);
    }

    public function test_original_wrapped_write_stop_reaches_workflow_before_next_eligible_note(): void
    {
        $stop = new \RuntimeException('Original wrapper', 0, new InferenceAdmissionException('Held'));
        try {
            $this->runProcessor(false, repairFailure: $stop);
            $this->fail('Original write admission stop must propagate.');
        } catch (\RuntimeException $observed) {
            $this->assertSame($stop, $observed);
        }
    }

    public function test_intent_publication_exception_stops_before_next_eligible_note(): void
    {
        $this->expectException(InferenceAdmissionException::class);
        $this->runProcessor(false, publicationFailure: true);
    }

    private function runProcessor(bool $changed, bool $controllerFailure = false, ?\Throwable $failure = null, bool $unknownWrite = false, ?\Throwable $repairFailure = null, bool $publicationFailure = false): array
    {
        $body = "**Video:** https://youtu.be/abcdefghijk\n\n## Key Points\n\n*Key points will be extracted*\n\n---\n\n## Full Transcript\n\nFull original source transcript content.\n\n---\n\n## Metadata\n";
        $raw = 'Source title'."\n\n".$body."\nid: ".str_repeat('a', 32)."\nparent_id: moved-topic\ntype_: 1\n";
        $source = ['type' => 1, 'id' => str_repeat('a', 32), 'title' => 'Source title', 'parent_id' => 'moved-topic', 'content' => $body, 'raw' => $raw];
        $organizer = Mockery::mock(JoplinYouTubeOrganizer::class);
        $organizer->shouldReceive('getJoplinPath')->andReturn('sync/');
        $contents = ['source.md' => $raw, 'mirror.md' => 'mirror', 'incidental.md' => 'incidental'];
        if ($unknownWrite || $repairFailure !== null || $publicationFailure) {
            $contents['later.md'] = 'later';
            $organizer->shouldReceive('parseNote')->with('later')->andReturn(array_replace($source, ['id' => str_repeat('b', 32), 'raw' => 'later']));
        }
        $organizer->shouldReceive('listDirectory')->andReturn(array_keys($contents));
        $organizer->shouldReceive('getFileContentsBatch')->andReturn($contents);
        $organizer->shouldReceive('parseNote')->with($raw)->andReturn($source);
        $organizer->shouldReceive('parseNote')->with('mirror')->andReturn(array_merge($source, ['id' => 'mirror']));
        $organizer->shouldReceive('parseNote')->with('incidental')->andReturn(array_merge($source, ['id' => 'incidental', 'content' => "## Discussion\nhttps://youtu.be/abcdefghijk"]));
        $organizer->shouldReceive('putFileContent')->never();
        $this->app->instance(JoplinYouTubeOrganizer::class, $organizer);
        $this->app->instance(JoplinFilesService::class, Mockery::mock(JoplinFilesService::class)->makePartial());
        $formatter = Mockery::mock(TrustBoundaryFormatterService::class);
        $formatter->shouldReceive('format')->andReturn('Full original source transcript content.');
        $this->app->instance(TrustBoundaryFormatterService::class, $formatter);
        $ai = Mockery::mock(AIService::class);
        if ($failure !== null) {
            $ai->shouldReceive('process')->once()->andThrow($failure);
        } elseif ($controllerFailure) {
            $ai->shouldReceive('process')->once()->andReturn(['success' => false, 'error' => 'private text',
                'controller_failure' => ['wait_reason' => 'wait_deadline', 'dispatched' => false, 'prompt' => 'private']]);
        } else {
            $ai->shouldReceive('process')->once()->andReturn(['success' => true, 'response' => "- A concrete source point.\n- A second concrete point.\n- A third concrete point."]);
        }
        $this->app->instance(AIService::class, $ai);
        $writer = Mockery::mock(JoplinWriteService::class);
        $writer->shouldNotReceive('reviewSnapshot');
        $writer->shouldNotReceive('reviewPut');
        $delivery = Mockery::mock(YouTubeJoplinDeliveryService::class, [app(JoplinFilesService::class)])->makePartial();
        if ($controllerFailure || $failure !== null) {
            $delivery->shouldNotReceive('retainMissingOriginal');
        } else {
            $repair = $delivery->shouldReceive('retainMissingOriginal')->once()
                ->with('abcdefghijk', str_repeat('a', 32), 7, true, hash('sha256', $raw),
                    hash('sha256', 'Full original source transcript content.'),
                    ['A concrete source point.', 'A second concrete point.', 'A third concrete point.'], false, true);
            if ($publicationFailure) {
                $repair->andReturnUsing(function (): never {
                    DB::table('task_custody_records')->insert([
                        'surface' => TaskCustodyService::SURFACE_YOUTUBE_SOURCE_WRITE,
                        'surface_ref' => str_repeat('a', 32), 'owner_token' => 'original-owner',
                        'acquired_at' => now(), 'expires_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    throw new \RuntimeException('Intent publication read failed');
                });
            } elseif ($repairFailure !== null) {
                $repair->andThrow($repairFailure);
            } elseif ($unknownWrite) {
                $repair->andReturn(['status' => 'hold', 'reason_code' => 'source_write_outcome_unknown']);
            } elseif ($changed) {
                $repair->andThrow(new \RuntimeException('Concurrent operator edit preserved'));
            } else {
                $repair->andReturn(['status' => 'retained']);
            }
        }
        $this->app->instance(YouTubeJoplinDeliveryService::class, $delivery);
        $this->app->instance(JoplinWriteService::class, $writer);

        return (new YouTubeKeyPointsPostProcessor([
            'all_youtube_sources' => true, 'limit' => 10, 'dry_run' => false, 'file_batch_size' => 10,
            'max_seconds' => 30, 'title_preview_chars' => 50, 'transcript_min_chars' => 1,
            'transcript_max_chars' => 12000, 'ai_max_tokens' => 1000, 'ai_temperature' => 0.0,
        ]))->execute(['data' => []]);
    }
}
