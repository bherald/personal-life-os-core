<?php

namespace Tests\Unit\Services;

use App\Services\AIService;
use App\Services\RAGService;
use App\Services\SemDeDupService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class RAGPublicationGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_source_identity_insert_prepares_embedding_then_guard_can_hold_without_any_write(): void
    {
        $this->assertGuardedSourceIdentity(false);
    }

    public function test_source_identity_update_prepares_embedding_then_guard_can_hold_without_any_write(): void
    {
        $this->assertGuardedSourceIdentity(true);
    }

    public function test_verbatim_source_identity_insert_and_update_keep_original_publication_hold(): void
    {
        $this->assertGuardedVerbatimSource(false);
    }

    public function test_verbatim_source_identity_update_keeps_original_publication_hold(): void
    {
        $this->assertGuardedVerbatimSource(true);
    }

    private function assertGuardedVerbatimSource(bool $existing): void
    {
        $prepared = false;
        $text = "  Exact source\r\n\tbytes…  ";
        $stop = new \RuntimeException('original_source_changed');
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateEmbedding')->once()->withArgs(fn ($input, $options = []) => $input === $text
            && ($options['preserve_embedding_input'] ?? false) === true)
            ->andReturnUsing(function () use (&$prepared): array {
                $prepared = true;

                return ['success' => true, 'embedding' => [0.1, 0.2]];
            });
        $connection = Mockery::mock();
        DB::shouldReceive('connection')->with('pgsql_rag')->andReturn($connection);
        $connection->shouldReceive('selectOne')->withArgs(fn ($sql) => str_contains($sql, 'pg_advisory_lock'))->once()->andReturn((object) []);
        $connection->shouldReceive('selectOne')->withArgs(fn ($sql) => str_contains($sql, 'ORDER BY updated_at DESC'))->once()->andReturn($existing ? (object) ['id' => 90] : null);
        $connection->shouldReceive('selectOne')->withArgs(fn ($sql) => str_contains($sql, 'pg_advisory_unlock'))->once()->andReturn((object) []);
        $connection->shouldNotReceive('select');
        $connection->shouldNotReceive('update');
        $connection->shouldNotReceive('transaction');
        $guard = function () use (&$prepared, $stop): never {
            $this->assertTrue($prepared);
            throw $stop;
        };
        try {
            (new RAGService($ai, dedupService: new SemDeDupService($ai)))->indexDocument('youtube_video', $text,
                sourceId: 797, sourceType: 'youtube_transcript',
                options: ['preserve_content' => true, 'source_identity_upsert' => true], publicationGuard: $guard);
            $this->fail('A held verbatim publication must not succeed.');
        } catch (\RuntimeException $observed) {
            $this->assertSame($stop, $observed);
        }
    }

    private function assertGuardedSourceIdentity(bool $existing): void
    {
        $prepared = false;
        $guardCalled = false;
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateEmbedding')->once()->andReturnUsing(function () use (&$prepared): array {
            $prepared = true;

            return ['success' => true, 'embedding' => [0.1, 0.2], 'provider' => 'fixture', 'model' => 'fixture'];
        });
        $connection = Mockery::mock();
        DB::shouldReceive('connection')->with('pgsql_rag')->andReturn($connection);
        $connection->shouldReceive('selectOne')->withArgs(fn ($sql) => str_contains($sql, 'pg_advisory_lock'))->once()->andReturn((object) []);
        $connection->shouldReceive('selectOne')->withArgs(fn ($sql) => str_contains($sql, 'ORDER BY updated_at DESC'))->once()->andReturn($existing ? (object) ['id' => 90] : null);
        $connection->shouldReceive('selectOne')->withArgs(fn ($sql) => str_contains($sql, 'pg_advisory_unlock'))->once()->andReturn((object) []);
        $connection->shouldReceive('select')->never();
        $connection->shouldReceive('update')->never();
        $connection->shouldReceive('transaction')->never();
        $guard = function (callable $publish) use (&$prepared, &$guardCalled): never {
            $this->assertTrue($prepared, 'Embedding must precede registry custody');
            $guardCalled = true;
            throw new \RuntimeException('projection_catalog_changed');
        };
        try {
            (new RAGService($ai, null, null, new SemDeDupService($ai)))->indexDocument(
                'file_catalog', 'cached historical content', sourceId: 1, sourceType: 'file_registry',
                options: ['source_identity_upsert' => true], publicationGuard: $guard,
            );
            $this->fail('Held publication unexpectedly succeeded');
        } catch (\Exception $error) {
            $this->assertSame($existing ? 'RAG source document 90 could not be updated idempotently.' : 'projection_catalog_changed', $error->getMessage());
        }
        $this->assertTrue($guardCalled);
    }
}
