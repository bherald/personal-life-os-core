<?php

namespace Tests\Unit\Commands;

use App\Services\RAGService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class RagRetrievalEvidenceCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_json_batch_redacts_query_text_and_reports_aggregate_evidence(): void
    {
        $service = Mockery::mock(RAGService::class);
        $service->shouldReceive('search')
            ->once()
            ->with('family photos', 3, null)
            ->andReturn([
                ['document' => (object) ['title' => 'Album', 'document_type' => 'file', 'content' => 'Photo note'], 'similarity' => 0.8],
                ['document' => (object) ['title' => 'Folder', 'document_type' => 'file', 'content' => 'Folder note'], 'similarity' => 0.5],
            ]);
        $service->shouldReceive('search')
            ->once()
            ->with('scheduler evidence', 3, null)
            ->andReturn([]);
        $this->app->instance(RAGService::class, $service);

        $exit = Artisan::call('rag:retrieval-evidence', [
            '--query' => ['family photos', 'scheduler evidence'],
            '--limit' => 3,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('family photos', $output);
        $this->assertStringNotContainsString('scheduler evidence', $output);
        $this->assertSame('observe_ok', $payload['status']);
        $this->assertSame(1, $payload['evidence_contract']['version']);
        $this->assertSame('observe_only', $payload['evidence_contract']['mode']);
        $this->assertSame('ordered_query_hashes_and_type_filters', $payload['evidence_contract']['query_set_hash_basis']);
        $this->assertSame('redacted_by_default', $payload['evidence_contract']['query_text']);
        $this->assertSame('redacted_by_default', $payload['evidence_contract']['results']);
        $this->assertSame($this->querySetHash([
            ['query' => 'family photos', 'type' => null],
            ['query' => 'scheduler evidence', 'type' => null],
        ]), $payload['query_set_hash']);
        $this->assertSame(2, $payload['query_count']);
        $this->assertSame(2, $payload['successful_count']);
        $this->assertSame(1, $payload['empty_count']);
        $this->assertSame(0, $payload['failed_count']);
        $this->assertSame(3, $payload['limit']);
        $this->assertSame(0.8, $payload['score_summary']['top_similarity_min']);
        $this->assertSame(0.8, $payload['score_summary']['top_similarity_max']);
        $this->assertEquals(1.0, $payload['score_summary']['avg_result_count']);
        $this->assertIsInt($payload['latency_summary']['query_duration_min_ms']);
        $this->assertIsInt($payload['latency_summary']['query_duration_max_ms']);
        $this->assertIsNumeric($payload['latency_summary']['query_duration_avg_ms']);
        $this->assertIsInt($payload['latency_summary']['query_duration_p95_ms']);
        $this->assertSame(substr(hash('sha256', 'family photos'), 0, 16), $payload['queries'][0]['query_hash']);
        $this->assertArrayNotHasKey('results', $payload['queries'][0]);
        $this->assertSame('observe_empty', $payload['queries'][1]['status']);
    }

    public function test_query_set_hash_is_stable_across_repeated_runs_when_labels_change(): void
    {
        $firstPath = tempnam(sys_get_temp_dir(), 'rag-evidence-first-');
        $secondPath = tempnam(sys_get_temp_dir(), 'rag-evidence-second-');
        file_put_contents($firstPath, json_encode([
            [
                'label' => 'baseline-a',
                'query' => 'private repeated query',
                'type' => 'file',
            ],
            [
                'label' => 'baseline-b',
                'query' => 'another private query',
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($secondPath, json_encode([
            [
                'label' => 'renamed-a',
                'query' => 'private repeated query',
                'type' => 'file',
            ],
            [
                'label' => 'renamed-b',
                'query' => 'another private query',
            ],
        ], JSON_THROW_ON_ERROR));

        $service = Mockery::mock(RAGService::class);
        $service->shouldReceive('search')
            ->twice()
            ->with('private repeated query', 1, 'file')
            ->andReturn([]);
        $service->shouldReceive('search')
            ->twice()
            ->with('another private query', 1, null)
            ->andReturn([]);
        $this->app->instance(RAGService::class, $service);

        try {
            $firstExit = Artisan::call('rag:retrieval-evidence', [
                '--queries-file' => $firstPath,
                '--limit' => 1,
                '--json' => true,
            ]);
            $firstOutput = Artisan::output();
            $firstPayload = json_decode($firstOutput, true);

            $secondExit = Artisan::call('rag:retrieval-evidence', [
                '--queries-file' => $secondPath,
                '--limit' => 1,
                '--json' => true,
            ]);
            $secondOutput = Artisan::output();
            $secondPayload = json_decode($secondOutput, true);
        } finally {
            @unlink($firstPath);
            @unlink($secondPath);
        }

        $expectedHash = $this->querySetHash([
            ['query' => 'private repeated query', 'type' => 'file'],
            ['query' => 'another private query', 'type' => null],
        ]);

        $this->assertSame(0, $firstExit);
        $this->assertSame(0, $secondExit);
        $this->assertSame($expectedHash, $firstPayload['query_set_hash']);
        $this->assertSame($expectedHash, $secondPayload['query_set_hash']);
        $this->assertSame($firstPayload['query_set_hash'], $secondPayload['query_set_hash']);
        $this->assertSame('baseline-a', $firstPayload['queries'][0]['label']);
        $this->assertSame('renamed-a', $secondPayload['queries'][0]['label']);
        $this->assertStringNotContainsString('private repeated query', $firstOutput);
        $this->assertStringNotContainsString('another private query', $firstOutput);
        $this->assertStringNotContainsString('private repeated query', $secondOutput);
        $this->assertStringNotContainsString('another private query', $secondOutput);
    }

    public function test_queries_file_can_include_labels_type_override_and_opt_in_results(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rag-evidence-');
        file_put_contents($path, json_encode([
            [
                'label' => 'genealogy-source',
                'query' => 'source duplicate',
                'type' => 'genealogy_person',
            ],
        ], JSON_THROW_ON_ERROR));

        $service = Mockery::mock(RAGService::class);
        $service->shouldReceive('search')
            ->once()
            ->with('source duplicate', 2, 'genealogy_person')
            ->andReturn([
                [
                    'document' => (object) [
                        'title' => 'Research Note',
                        'document_type' => 'genealogy_person',
                        'created_at' => '2026-05-02 18:55:00',
                        'content' => str_repeat('evidence ', 25),
                    ],
                    'similarity' => 0.91234,
                ],
            ]);
        $this->app->instance(RAGService::class, $service);

        try {
            $exit = Artisan::call('rag:retrieval-evidence', [
                '--queries-file' => $path,
                '--limit' => 2,
                '--include-results' => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);
        } finally {
            @unlink($path);
        }

        $this->assertSame(0, $exit);
        $this->assertSame('genealogy-source', $payload['queries'][0]['label']);
        $this->assertSame('included_by_operator_option', $payload['evidence_contract']['results']);
        $this->assertSame('genealogy_person', $payload['queries'][0]['type']);
        $this->assertSame($this->querySetHash([
            ['query' => 'source duplicate', 'type' => 'genealogy_person'],
        ]), $payload['query_set_hash']);
        $this->assertSame(0.9123, $payload['queries'][0]['top_similarity']);
        $this->assertSame('Research Note', $payload['queries'][0]['results'][0]['title']);
        $this->assertSame('2026-05-02 18:55:00', $payload['queries'][0]['results'][0]['created_at']);
        $this->assertStringEndsWith('...', $payload['queries'][0]['results'][0]['preview']);
    }

    public function test_search_failures_return_warning_without_exposing_query_or_error_message(): void
    {
        $service = Mockery::mock(RAGService::class);
        $service->shouldReceive('search')
            ->once()
            ->with('private query text', 5, null)
            ->andThrow(new \RuntimeException('private query text failed'));
        $this->app->instance(RAGService::class, $service);

        $exit = Artisan::call('rag:retrieval-evidence', [
            '--query' => ['private query text'],
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('private query text', $output);
        $this->assertStringNotContainsString('private query text failed', $output);
        $this->assertSame('observe_warning', $payload['status']);
        $this->assertSame($this->querySetHash([
            ['query' => 'private query text', 'type' => null],
        ]), $payload['query_set_hash']);
        $this->assertSame(1, $payload['failed_count']);
        $this->assertSame('RuntimeException', $payload['queries'][0]['error_type']);
    }

    public function test_markdown_output_redacts_query_text_and_reports_query_set_hash(): void
    {
        $service = Mockery::mock(RAGService::class);
        $service->shouldReceive('search')
            ->once()
            ->with('private family query', 4, 'genealogy_person')
            ->andReturn([
                ['document' => (object) ['title' => 'Family Note', 'document_type' => 'genealogy_person'], 'similarity' => 0.72],
            ]);
        $this->app->instance(RAGService::class, $service);

        $exit = Artisan::call('rag:retrieval-evidence', [
            '--query' => ['private family query'],
            '--limit' => 4,
            '--type' => 'genealogy_person',
            '--markdown' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('# RAG Retrieval Evidence', $output);
        $this->assertStringContainsString('Query set hash: `'.$this->querySetHash([
            ['query' => 'private family query', 'type' => 'genealogy_person'],
        ]).'`', $output);
        $this->assertStringContainsString('## Latency Summary', $output);
        $this->assertStringContainsString('## Evidence Contract', $output);
        $this->assertStringContainsString('Query set hash basis: `ordered_query_hashes_and_type_filters`', $output);
        $this->assertStringContainsString('Query text: `redacted_by_default`', $output);
        $this->assertStringContainsString('Results: `redacted_by_default`', $output);
        $this->assertStringContainsString('query_duration_p95_ms', $output);
        $this->assertStringContainsString('| Label | Query Hash | Type | Status | Results |', $output);
        $this->assertStringContainsString('`genealogy_person`', $output);
        $this->assertStringContainsString('`observe_ok`', $output);
        $this->assertStringNotContainsString('private family query', $output);
        $this->assertStringNotContainsString('Family Note', $output);
    }

    public function test_json_and_markdown_options_are_mutually_exclusive(): void
    {
        $service = Mockery::mock(RAGService::class);
        $service->shouldNotReceive('search');
        $this->app->instance(RAGService::class, $service);

        $exit = Artisan::call('rag:retrieval-evidence', [
            '--query' => ['ignored query'],
            '--json' => true,
            '--markdown' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Choose either --json or --markdown, not both.', Artisan::output());
    }

    public function test_missing_queries_file_error_does_not_expose_raw_path(): void
    {
        $missingPath = '/tmp/private-query-sets/family-source-questions.json';
        $service = Mockery::mock(RAGService::class);
        $service->shouldNotReceive('search');
        $this->app->instance(RAGService::class, $service);

        $exit = Artisan::call('rag:retrieval-evidence', [
            '--queries-file' => $missingPath,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertSame(1, $exit);
        $this->assertSame('failed', $payload['status']);
        $this->assertSame('Query file not found. Check --queries-file path.', $payload['error']);
        $this->assertStringNotContainsString($missingPath, $output);
        $this->assertStringNotContainsString('family-source-questions.json', $output);
    }

    /**
     * @param  list<array{query: string, type: ?string}>  $queries
     */
    private function querySetHash(array $queries): string
    {
        $fingerprint = array_map(
            fn (array $query): array => [
                'query_hash' => substr(hash('sha256', $query['query']), 0, 16),
                'type' => $query['type'],
            ],
            $queries
        );

        return substr(hash('sha256', json_encode($fingerprint, JSON_UNESCAPED_SLASHES)), 0, 16);
    }
}
