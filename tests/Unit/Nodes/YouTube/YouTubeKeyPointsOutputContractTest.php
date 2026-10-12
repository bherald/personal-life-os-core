<?php

namespace Tests\Unit\Nodes\YouTube;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Nodes\YouTube\YouTubeKeyPointsPostProcessor;
use App\Services\AIService;
use App\Services\Custody\TaskCustodyService;
use App\Services\JoplinWriteService;
use App\Services\JoplinYouTubeOrganizer;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesYouTubeSourceCustodySchema;
use Tests\TestCase;

class YouTubeKeyPointsOutputContractTest extends TestCase
{
    use CreatesYouTubeSourceCustodySchema;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'keypoint_output_test', 'database.connections.keypoint_output_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('keypoint_output_test');
        $this->createSourceCustodySchema();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        DB::purge('keypoint_output_test');
        parent::tearDown();
    }

    #[DataProvider('invalidReplies')]
    public function test_invalid_successful_ai_output_cannot_authorize_a_note_write_or_another_model_call(mixed $response): void
    {
        $result = $this->runProcessor($response, false);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame([], $result['notes']);
    }

    public static function invalidReplies(): array
    {
        $valid = "- The source reports three measured samples.\n- The source reports a 2025 trial.\n- The source reports the measured uncertainty.";

        return [
            'one bullet' => ['- Only one source point.'],
            'two bullets' => ["- First source point.\n- Second source point."],
            'duplicate bullets' => ["- First source point.\n- First source point.\n- Second source point."],
            'case variant duplicate' => ["- First source point.\n- FIRST SOURCE POINT.\n- Second source point."],
            'placeholder' => ["- First source point.\n- Key points will be extracted.\n- Third source point."],
            'extra prose' => ["Here are the results:\n".$valid],
            'continuation line' => [$valid."\nUnfinished continuation"],
            'nine bullets' => [implode("\n", array_map(fn ($i) => '- Source point '.$i.'.', range(1, 9)))],
            'wrong response type' => [['not a textual reply']],
            'invalid utf8' => [$valid."\xff"],
        ];
    }

    public function test_three_distinct_bullets_pass_with_complete_source_and_explicit_task_budget(): void
    {
        $response = "- The source reports three measured samples.\r\n\r\n- The source reports a 2025 trial.\r\n- The source reports the measured uncertainty.\r\n";
        $result = $this->runProcessor($response, true);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(3, $result['notes'][0]['key_points_count']);
    }

    public function test_missing_exact_stored_source_stops_before_model_and_note_write(): void
    {
        $result = $this->runProcessor(null, false, true);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['failed']);
    }

    public function test_unqualified_custody_envelope_stops_before_model_and_write(): void
    {
        DB::table('task_custody_records')->insert([
            'surface' => TaskCustodyService::SURFACE_YOUTUBE_SOURCE_WRITE, 'surface_ref' => str_repeat('a', 32),
            'owner_token' => 'original-owner', 'result_envelope' => 'invalid-json',
            'acquired_at' => now(), 'expires_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(InferenceAdmissionException::class);
        $this->runProcessor(null, false, true);
    }

    private function runProcessor(mixed $response, bool $dryRun, bool $missingSource = false): array
    {
        $source = str_repeat('Synthetic full source paragraph. ', 500)."\n---\n\nFinal source observation.";
        $this->createKeyPointTranscriptSchema($missingSource ? [] : [['id' => 7, 'video_id' => 'abcdefghijk', 'content' => $source]]);
        $raw = "Synthetic title\n\n**Video:** https://youtu.be/abcdefghijk\n\n## Key Points\n\n*Key points will be extracted*\n\n---\n\n## Full Transcript\n\n".$source."\n\n---\n\n## Metadata\n\nid: ".str_repeat('a', 32)."\nparent_id: watch\ntype_: 1\n";
        $note = ['id' => str_repeat('a', 32), 'type' => 1, 'parent_id' => 'watch', 'title' => 'Synthetic title', 'raw' => $raw, 'content' => $raw];
        $organizer = Mockery::mock(JoplinYouTubeOrganizer::class);
        $organizer->shouldReceive('getJoplinPath')->andReturn('sync/');
        $organizer->shouldReceive('getWatchLaterFolderId')->andReturn('watch');
        $organizer->shouldReceive('listDirectory')->andReturn(['source.md']);
        $organizer->shouldReceive('getFileContentsBatch')->andReturn(['source.md' => $raw]);
        $organizer->shouldReceive('parseNote')->with($raw)->andReturn($note);
        $organizer->shouldReceive('putFileContent')->never();
        $writer = Mockery::mock(JoplinWriteService::class);
        $writer->shouldReceive('reviewSnapshot')->never();
        $writer->shouldReceive('reviewPut')->never();
        $this->app->instance(JoplinWriteService::class, $writer);
        $ai = Mockery::mock(AIService::class);
        if ($missingSource) {
            $ai->shouldNotReceive('process');
        } else {
            $ai->shouldReceive('process')->once()->with(Mockery::on(function (string $prompt) use ($source): bool {
                $this->assertStringContainsString($source, $prompt);
                $this->assertStringNotContainsString('[truncated at', $prompt);

                return true;
            }), ['max_tokens' => 128, 'temperature' => 0.0])->andReturn(['success' => true, 'response' => $response]);
        }

        return (new YouTubeKeyPointsPostProcessor([
            'file_batch_size' => 10, 'max_seconds' => 30, 'title_preview_chars' => 50,
            'transcript_min_chars' => 1, 'transcript_max_chars' => 20,
            'ai_max_tokens' => 128, 'ai_temperature' => 0.0,
        ]))->processPlaceholderNotes($organizer, $ai, 1, $dryRun);
    }
}
