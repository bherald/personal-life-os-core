<?php

namespace Tests\Unit\Services;

use App\Services\AIService;
use App\Services\FileCategorizationRAGService;
use App\Services\FileRegistryService;
use App\Services\RAGService;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FileCategorizationRAGPublicationReturnTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public static function publications(): array
    {
        return ['existing document update' => [true], 'new document insertion' => [false]];
    }

    #[DataProvider('publications')]
    public function test_actual_guard_accepts_existing_boolean_or_new_object_publication(bool $existing): void
    {
        [$service, $rag, $registry, $file, $proof] = $this->fixture();
        $doc = (object) ['id' => 99];
        $publicationResult = $existing ? true : $doc;
        $registry->shouldReceive('publishCurrentSourceProjection')->once()->withArgs(function (array $given, callable $publish, callable $complete) use ($proof, $publicationResult): bool {
            $this->assertSame($proof, $given);
            $this->assertSame($publicationResult, $publish());
            $complete();

            return true;
        })->andReturn($publicationResult);
        DB::shouldReceive('update')->once()->withArgs(fn (string $sql, array $args): bool => str_contains($sql, 'rag_indexed_at = ?') && $args[1] === $file->id)->andReturn(1);
        $rag->shouldReceive('indexDocument')->once()->andReturnUsing(function (...$args) use ($publicationResult, $doc): object {
            $this->assertTrue($args[8]['source_identity_upsert']);
            $this->assertSame($publicationResult, $args[9](static fn () => $publicationResult));

            return $doc;
        });
        $this->assertSame(['success' => true, 'rag_id' => 99], $service->indexFile($file->asset_uuid, options: ['bulk_mode' => true]));
    }

    public function test_original_publication_refusal_remains_failed_without_completion_stamp(): void
    {
        [$service, $rag, $registry, $file] = $this->fixture();
        $registry->shouldReceive('publishCurrentSourceProjection')->once()->andThrow(new RuntimeException('projection_publication_failed'));
        DB::shouldReceive('update')->never();
        $rag->shouldReceive('indexDocument')->once()->andReturnUsing(function (...$args) {
            return $args[9](static fn (): bool => false);
        });
        $result = $service->indexFile($file->asset_uuid, options: ['bulk_mode' => true]);
        $this->assertFalse($result['success']);
        $this->assertSame('projection_publication_failed', $result['error']);
    }

    private function fixture(): array
    {
        $file = (object) ['id' => 1, 'asset_uuid' => 'synthetic-asset', 'current_path' => '/fixture.txt',
            'extension' => 'txt', 'category' => null, 'file_size' => 16, 'mime_type' => 'text/plain',
            'nextcloud_fileid' => 1, 'content_extraction_metadata' => null];
        $proof = ['source_sha256' => str_repeat('a', 64)];
        DB::shouldReceive('selectOne')->once()->andReturn($file);
        DB::shouldReceive('select')->never();
        $registry = Mockery::mock(FileRegistryService::class);
        $registry->shouldReceive('currentSourcePublicationProof')->once()->with($file)->andReturn($proof);
        $this->app->instance(FileRegistryService::class, $registry);
        $rag = Mockery::mock(RAGService::class);

        return [new FileCategorizationRAGService($rag, Mockery::mock(AIService::class)), $rag, $registry, $file, $proof];
    }
}
