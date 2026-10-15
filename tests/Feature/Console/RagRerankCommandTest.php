<?php

namespace Tests\Feature\Console;

use App\Services\RerankerSidecarService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class RagRerankCommandTest extends TestCase
{
    public function test_rerank_command_accepts_base64_documents_and_returns_scores(): void
    {
        $documents = [
            ['id' => 'ops', 'title' => 'Ops', 'text' => 'Queue status and scheduler report.'],
            ['id' => 'genea', 'title' => 'Genealogy', 'text' => 'Offline genealogy media transcription and source review.'],
            ['id' => 'dev', 'title' => 'Dev', 'text' => 'Laravel command implementation.'],
        ];

        $exit = Artisan::call('rag:rerank', [
            '--json' => true,
            '--query-b64' => base64_encode('genealogy transcription'),
            '--documents-json-b64' => base64_encode(json_encode($documents, JSON_THROW_ON_ERROR)),
            '--limit' => 2,
            '--no-embeddings' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('plos_rag_rerank.v1', $payload['schema']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame('lexical', $payload['mode']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame('genea', $payload['results'][0]['id']);
        $this->assertCount(2, $payload['results']);
    }

    public function test_controlled_rerank_command_preserves_base64_source_and_exact_selection(): void
    {
        $query = "  query\r\nTail  ";
        $documents = [['id' => 'source', 'text' => "  José\r\nCritical tail  "]];
        $service = Mockery::mock(RerankerSidecarService::class);
        $service->shouldReceive('rerankControlled')->once()->with($query, $documents)
            ->andReturn(['status' => 'pass', 'results' => [['id' => 'source', 'score' => 0.9]]]);
        app()->instance(RerankerSidecarService::class, $service);
        $exit = Artisan::call('rag:rerank', ['--json' => true, '--cross-encoder' => true,
            '--query-b64' => base64_encode($query), '--documents-json-b64' => base64_encode(json_encode($documents))]);
        $output = json_decode(Artisan::output(), true);
        $this->assertSame(0, $exit);
        $this->assertSame('cross_encoder', $output['mode']);
        $this->assertSame(hash('sha256', $query), $output['query_sha256']);
        $this->assertSame('source', $output['results'][0]['id']);
    }

    public function test_rerank_example_is_safe_smoke(): void
    {
        $exit = Artisan::call('rag:rerank', [
            '--json' => true,
            '--example' => true,
            '--no-embeddings' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame('lexical', $payload['mode']);
        $this->assertNotEmpty($payload['results']);
    }
}
