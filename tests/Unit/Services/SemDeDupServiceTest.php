<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\AIService;
use App\Services\SemDeDupService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SemDeDupServiceTest extends TestCase
{
    public function test_skip_dedup_bypasses_recursive_path(): void
    {
        $aiService = $this->createMock(AIService::class);

        $service = new class($aiService) extends SemDeDupService
        {
            public bool $recursiveAttempted = false;

            protected function tryRecursive(
                string $serviceName,
                string $strategy,
                array $context,
                callable $processFn,
                array $parentBudget = []
            ): ?array {
                $this->recursiveAttempted = true;

                return null;
            }
        };

        $result = $service->checkDuplicate(' Example content ', 'Title', 'file_registry', '123', [
            'skip_dedup' => true,
        ]);

        $this->assertFalse($service->recursiveAttempted);
        $this->assertFalse($result['is_duplicate']);
        $this->assertSame('allow', $result['action']);
        $this->assertNull($result['strategy']);
        $this->assertSame(hash('sha256', 'example content'), $result['content_hash']);
    }

    public function test_source_identity_content_change_invalidates_derived_indexes_transactionally(): void
    {
        $ai = Mockery::mock(AIService::class);
        $connection = Mockery::mock();

        $ai->shouldReceive('generateEmbedding')->once()->with('new content')->andReturn([
            'success' => true,
            'embedding' => [0.1, 0.2],
            'provider' => 'ollama',
            'model' => 'nomic-embed-text',
            'chunked' => false,
        ]);
        DB::shouldReceive('connection')->once()->with('pgsql_rag')->andReturn($connection);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
        $connection->shouldReceive('selectOne')
            ->once()
            ->with('SELECT content_hash, content FROM rag_documents WHERE id = ? FOR UPDATE', [17])
            ->andReturn((object) [
                // Normalized hash matches, but exact stored content changed.
                'content_hash' => hash('sha256', 'new content'),
                'content' => 'New content!',
            ]);
        $connection->shouldReceive('update')
            ->once()
            ->withArgs(fn (string $sql, array $params): bool => str_contains($sql, 'SET content = ?')
                && end($params) === 17)
            ->andReturn(1);
        $connection->shouldReceive('insert')
            ->once()
            ->withArgs(fn (string $sql, array $params): bool => str_contains($sql, 'knowledge_graph_edge_history')
                && $params === [17])
            ->andReturnTrue();
        $connection->shouldReceive('update')
            ->once()
            ->withArgs(fn (string $sql, array $params): bool => str_contains($sql, 'UPDATE knowledge_graph')
                && $params === [17])
            ->andReturn(3);
        $connection->shouldReceive('delete')
            ->times(5)
            ->withArgs(fn (string $sql, array $params): bool => str_starts_with($sql, 'DELETE FROM ')
                && $params === [17])
            ->andReturn(1);
        $connection->shouldReceive('update')
            ->once()
            ->withArgs(fn (string $sql, array $params): bool => str_contains($sql, 'sentence_indexed_at = NULL')
                && str_contains($sql, 'kg_content_hash = NULL')
                && $params === [17])
            ->andReturn(1);

        $updated = (new SemDeDupService($ai))->updateExisting(
            17,
            'new content',
            'New title',
            ['revision' => 2],
            true,
        );

        $this->assertTrue($updated);
    }

    public function test_source_identity_same_content_preserves_derived_indexes(): void
    {
        $ai = Mockery::mock(AIService::class);
        $connection = Mockery::mock();

        $ai->shouldReceive('generateEmbedding')->once()->andReturn([
            'success' => true,
            'embedding' => [0.1, 0.2],
            'provider' => 'ollama',
            'model' => 'nomic-embed-text',
            'chunked' => false,
        ]);
        DB::shouldReceive('connection')->once()->with('pgsql_rag')->andReturn($connection);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(fn (callable $callback) => $callback());
        $connection->shouldReceive('selectOne')->once()->andReturn((object) [
            'content_hash' => hash('sha256', 'same content'),
            'content' => 'same content',
        ]);
        $connection->shouldReceive('update')->once()->andReturn(1);
        $connection->shouldReceive('insert')->never();
        $connection->shouldReceive('delete')->never();

        $updated = (new SemDeDupService($ai))->updateExisting(
            18,
            'same content',
            null,
            null,
            true,
        );

        $this->assertTrue($updated);
    }

    public function test_blind_duplicate_removal_is_disabled_before_database_access(): void
    {
        $ai = Mockery::mock(AIService::class);
        DB::shouldReceive('connection')->never();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Blind content-hash deletion is disabled');

        (new SemDeDupService($ai))->removeDuplicates(false, 'file_catalog');
    }

    public function test_verbatim_update_embeds_and_publishes_exact_bytes_without_normalized_hash(): void
    {
        $text = "  Exact José “source”—text…\r\n\t tail  ";
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateEmbedding')->once()->with($text)->andReturn([
            'success' => true, 'embedding' => [0.1, 0.2],
        ]);
        $connection = Mockery::mock();
        DB::shouldReceive('connection')->once()->with('pgsql_rag')->andReturn($connection);
        $connection->shouldReceive('transaction')->once()->andReturnUsing(fn ($publish) => $publish());
        $connection->shouldReceive('selectOne')->once()->with('SELECT content_hash, content FROM rag_documents WHERE id = ? FOR UPDATE', [18])
            ->andReturn((object) ['content_hash' => hash('sha256', $text), 'content' => $text]);
        $connection->shouldReceive('update')->once()->withArgs(fn ($sql, $params) => $params[0] === $text
            && $params[1] === hash('sha256', $text) && end($params) === 18)->andReturn(1);
        $connection->shouldNotReceive('insert');
        $connection->shouldNotReceive('delete');
        $this->assertTrue((new SemDeDupService($ai))->updateExisting(18, $text, null, null, true, preserveContent: true));
    }

    public function test_invalid_verbatim_update_holds_before_embedding_or_storage(): void
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('generateEmbedding');
        DB::shouldReceive('connection')->never();
        $this->expectExceptionMessage('valid UTF-8 text without NUL');
        (new SemDeDupService($ai))->updateExisting(18, "source\0tail", null, null, preserveContent: true);
    }

    public function test_embedding_controller_refusal_stops_verbatim_update_before_any_storage(): void
    {
        $cause = ['reason_code' => 'controller_request_rejected', 'dispatched' => false];
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateEmbedding')->once()->andReturn(['success' => false, 'controller_failure' => $cause]);
        DB::shouldReceive('connection')->never();
        try {
            (new SemDeDupService($ai))->updateExisting(18, 'source', null, null, preserveContent: true);
            $this->fail('The controller refusal must survive.');
        } catch (InferenceAdmissionException $observed) {
            $this->assertSame($cause, $observed->controllerFailure);
        }
    }
}
