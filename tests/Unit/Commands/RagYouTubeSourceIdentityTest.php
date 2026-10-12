<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\RagBatchIndexCommand;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\DomainRegistryService;
use App\Services\RAGService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class RagYouTubeSourceIdentityTest extends TestCase
{
    private RAGService $rag;

    private string $content = "  Exact José source\r\n\t tail  ";

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'youtube_rag_fixture', 'database.connections.youtube_rag_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('youtube_rag_fixture');
        Schema::create('youtube_transcripts', function (Blueprint $table): void {
            $table->id();
            $table->string('video_id');
            $table->string('language');
            $table->text('content');
            $table->timestamp('rag_indexed_at')->nullable();
        });
        DB::table('youtube_transcripts')->insert(['id' => 797, 'video_id' => 'abcdefghijk', 'language' => 'en', 'content' => $this->content]);
        $registry = Mockery::mock(DomainRegistryService::class);
        $registry->shouldReceive('getDomain')->with('youtube')->andReturn(array_merge(config('domains.domains.youtube'), ['connection' => 'youtube_rag_fixture']));
        $this->app->instance(DomainRegistryService::class, $registry);
        $this->rag = Mockery::mock(RAGService::class);
        $this->app->instance(RAGService::class, $this->rag);
    }

    protected function tearDown(): void
    {
        DB::purge('youtube_rag_fixture');
        Mockery::close();
        parent::tearDown();
    }

    private function document(): object
    {
        return (object) ['id' => 17, 'document_type' => 'youtube_video', 'source_type' => 'youtube_transcript',
            'source_id' => '797', 'content' => $this->content, 'content_hash' => hash('sha256', $this->content)];
    }

    public function test_exact_youtube_selection_dry_run_performs_no_index_or_stamp(): void
    {
        $this->rag->shouldNotReceive('indexDocument');
        $this->artisan('rag:batch-index', ['--domain' => 'youtube', '--source-id' => ['797'], '--limit' => 1, '--dry-run' => true])
            ->expectsOutputToContain('Would index 1 records')->assertExitCode(0);
        $this->assertNull(DB::table('youtube_transcripts')->value('rag_indexed_at'));
    }

    public function test_validated_verbatim_source_identity_read_back_precedes_stamp(): void
    {
        $this->rag->shouldReceive('indexDocument')->once()->andReturnUsing(function (...$args): object {
            $this->assertSame($this->content, $args[1]);
            $this->assertSame(797, $args[4]);
            $this->assertSame('youtube_transcript', $args[5]);
            $this->assertSame(['preserve_content' => true, 'source_identity_upsert' => true], $args[8]);
            $this->assertSame(hash('sha256', $this->content), $args[3]['source_hash']);
            $this->assertNull(DB::table('youtube_transcripts')->value('rag_indexed_at'));

            return $args[9](fn () => $this->document());
        });
        $this->artisan('rag:batch-index', ['--domain' => 'youtube', '--source-id' => ['797'], '--limit' => 1])
            ->expectsOutputToContain('Total indexed: 1 records')->assertExitCode(0);
        $this->assertNotNull(DB::table('youtube_transcripts')->value('rag_indexed_at'));
        $this->assertSame($this->content, DB::table('youtube_transcripts')->value('content'));
    }

    public function test_changed_source_blocks_publication_and_leaves_pending(): void
    {
        $published = false;
        $this->rag->shouldReceive('indexDocument')->once()->andReturnUsing(function (...$args) use (&$published): object {
            DB::table('youtube_transcripts')->where('id', 797)->update(['content' => strtolower($this->content)]);

            return $args[9](function () use (&$published): object {
                $published = true;

                return $this->document();
            });
        });
        $this->artisan('rag:batch-index', ['--domain' => 'youtube', '--source-id' => ['797'], '--limit' => 1])->assertExitCode(1);
        $this->assertFalse($published);
        $this->assertNull(DB::table('youtube_transcripts')->value('rag_indexed_at'));
    }

    public function test_changed_source_after_publication_cannot_receive_completion_stamp(): void
    {
        $this->rag->shouldReceive('indexDocument')->once()->andReturnUsing(function (...$args): object {
            $document = $args[9](fn () => $this->document());
            DB::table('youtube_transcripts')->where('id', 797)->update(['content' => 'Later source version']);

            return $document;
        });
        $this->artisan('rag:batch-index', ['--domain' => 'youtube', '--source-id' => ['797'], '--limit' => 1])->assertExitCode(1);
        $this->assertNull(DB::table('youtube_transcripts')->value('rag_indexed_at'));
    }

    public function test_unverified_or_wrong_source_document_never_receives_a_stamp(): void
    {
        $document = $this->document();
        $document->source_id = '798';
        $this->rag->shouldReceive('indexDocument')->once()->andReturn($document);
        $this->artisan('rag:batch-index', ['--domain' => 'youtube', '--source-id' => ['797'], '--limit' => 1])->assertExitCode(1);
        $this->assertNull(DB::table('youtube_transcripts')->value('rag_indexed_at'));
    }

    public function test_incomplete_exact_selection_is_a_hold_before_embedding(): void
    {
        $this->rag->shouldNotReceive('indexDocument');
        $this->artisan('rag:batch-index', ['--domain' => 'youtube', '--source-id' => ['797', '798'], '--limit' => 2])->assertExitCode(1);
        $this->assertNull(DB::table('youtube_transcripts')->value('rag_indexed_at'));
    }

    public function test_invalid_or_over_limit_selection_cannot_start_indexing(): void
    {
        $this->rag->shouldNotReceive('indexDocument');
        foreach ([['797;SQL'], ['0'], ['797', '798']] as $ids) {
            $this->artisan('rag:batch-index', ['--domain' => 'youtube', '--source-id' => $ids, '--limit' => 1])->assertExitCode(1);
        }
    }

    public function test_a_later_read_only_invocation_does_not_inherit_a_prior_batch_failure(): void
    {
        $this->rag->shouldNotReceive('indexDocument');
        $this->artisan('rag:batch-index', ['--domain' => 'youtube', '--source-id' => ['999'], '--limit' => 1])->assertExitCode(1);
        $this->artisan('rag:batch-index', ['--domain' => 'youtube', '--source-id' => ['797'], '--limit' => 1, '--dry-run' => true])->assertExitCode(0);
        $this->assertNull(DB::table('youtube_transcripts')->value('rag_indexed_at'));
    }

    public function test_original_admission_stop_prevents_another_source_attempt(): void
    {
        DB::table('youtube_transcripts')->insert(['id' => 798, 'video_id' => 'lmnopqrstuv', 'language' => 'en', 'content' => 'Other exact source']);
        $stop = new \RuntimeException('Original wrapped stop', 0, new InferenceAdmissionException('Held'));
        $this->rag->shouldReceive('indexDocument')->once()->andThrow($stop);
        $command = new RagBatchIndexCommand;
        $command->setLaravel($this->app);
        try {
            $command->run(new ArrayInput(['--domain' => 'youtube', '--source-id' => ['797', '798'], '--limit' => 2], $command->getDefinition()), new BufferedOutput);
            $this->fail('An admission stop must reach the command boundary.');
        } catch (\RuntimeException $observed) {
            $this->assertSame($stop, $observed);
        }
        $this->assertSame(0, DB::table('youtube_transcripts')->whereNotNull('rag_indexed_at')->count());
    }
}
