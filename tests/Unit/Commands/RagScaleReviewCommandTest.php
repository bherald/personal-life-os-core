<?php

namespace Tests\Unit\Commands;

use App\Services\RagBacklogService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class RagScaleReviewCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_json_output_combines_scale_backlog_net_burn_and_redacted_retrieval_evidence(): void
    {
        $retrievalFile = $this->writeRetrievalEvidence([
            'status' => 'observe_ok',
            'duration_ms' => 1234,
            'query_set_hash' => 'abc123queryset',
            'query_count' => 2,
            'successful_count' => 2,
            'empty_count' => 0,
            'failed_count' => 0,
            'limit' => 5,
            'default_type' => 'genealogy',
            'include_results' => true,
            'latency_summary' => [
                'query_duration_min_ms' => 100,
                'query_duration_max_ms' => 900,
                'query_duration_avg_ms' => 500,
                'query_duration_p95_ms' => 900,
            ],
            'score_summary' => [
                'top_similarity_min' => 0.72,
                'top_similarity_max' => 0.91,
                'top_similarity_avg' => 0.815,
                'avg_result_count' => 3.5,
            ],
            'evidence_contract' => [
                'version' => 1,
                'mode' => 'observe_only',
                'query_set_hash_basis' => 'ordered_query_hashes_and_type_filters',
                'query_text' => 'redacted_by_default',
                'results' => 'included_by_operator_option',
            ],
            'queries' => [
                [
                    'query_hash' => 'private-query-hash',
                    'results' => [
                        ['title' => 'Private Result Title', 'preview' => 'Private result preview'],
                    ],
                ],
            ],
        ]);

        $this->mockRagService();

        $exit = Artisan::call('rag:scale-review', [
            '--retrieval-file' => $retrievalFile,
            '--json' => true,
        ]);

        $output = Artisan::output();
        $decoded = json_decode($output, true);

        $this->assertSame(0, $exit);
        $this->assertSame('observe_ok', $decoded['status']);
        $this->assertSame(100, $decoded['scale']['documents']);
        $this->assertSame(20, $decoded['backlog']['kg_pending']);
        $this->assertSame('shrinking', $decoded['net_burn']['kg_trend']);
        $this->assertSame(1234, $decoded['retrieval']['duration_ms']);
        $this->assertSame('abc123queryset', $decoded['retrieval']['query_set_hash']);
        $this->assertSame('ordered_query_hashes_and_type_filters', $decoded['retrieval']['query_set_hash_basis']);
        $this->assertSame(5, $decoded['retrieval']['limit']);
        $this->assertSame('genealogy', $decoded['retrieval']['default_type']);
        $this->assertSame('observe_only', $decoded['retrieval']['evidence_contract_mode']);
        $this->assertSame('redacted_by_default', $decoded['retrieval']['query_text_policy']);
        $this->assertSame('included_by_operator_option', $decoded['retrieval']['results_policy']);
        $this->assertSame(900, $decoded['retrieval']['latency_p95_ms']);
        $this->assertFalse($decoded['retrieval']['result_content_redacted']);
        $this->assertStringContainsString('result content', implode(' ', $decoded['recommendations']));
        $this->assertStringNotContainsString('Private Result Title', $output);
        $this->assertStringNotContainsString('Private result preview', $output);
        $this->assertArrayNotHasKey('queries', $decoded['retrieval']);
    }

    public function test_json_output_without_retrieval_file_keeps_review_observe_ok_but_recommends_query_set(): void
    {
        $this->mockRagService();

        $exit = Artisan::call('rag:scale-review', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('observe_ok', $decoded['status']);
        $this->assertSame('not_provided', $decoded['retrieval']['status']);
        $this->assertFalse($decoded['retrieval']['provided']);
        $this->assertNull($decoded['retrieval']['duration_ms']);
        $this->assertNull($decoded['retrieval']['limit']);
        $this->assertNull($decoded['retrieval']['query_set_hash_basis']);
        $this->assertNull($decoded['retrieval']['evidence_contract_mode']);
        $this->assertNull($decoded['retrieval']['query_text_policy']);
        $this->assertNull($decoded['retrieval']['results_policy']);
        $this->assertStringContainsString('Run rag:retrieval-evidence --json', implode(' ', $decoded['recommendations']));
    }

    public function test_compact_json_output_keeps_operator_headlines_without_verbose_payload(): void
    {
        $this->mockRagService();

        $exit = Artisan::call('rag:scale-review', [
            '--json' => true,
            '--compact' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($decoded['compact']);
        $this->assertSame('observe_ok', $decoded['status']);
        $this->assertSame(100, $decoded['scale']['documents']);
        $this->assertSame(20, $decoded['backlog']['kg_pending']);
        $this->assertSame(12, $decoded['backlog']['kg_fresh_pending']);
        $this->assertSame(8, $decoded['backlog']['kg_stale_pending']);
        $this->assertSame(4, $decoded['backlog']['kg_eta_days']);
        $this->assertSame(3.5, $decoded['net_burn']['kg_net_burn_per_day']);
        $this->assertSame('shrinking', $decoded['net_burn']['kg_trend']);
        $this->assertFalse($decoded['retrieval']['provided']);
        $this->assertSame('not_provided', $decoded['retrieval']['status']);
        $this->assertSame(0, $decoded['retrieval']['query_count']);
        $this->assertSame(0, $decoded['evidence_error_count']);
        $this->assertSame(4, $decoded['recommendation_count']);
        $this->assertArrayNotHasKey('recommendations', $decoded);
        $this->assertArrayNotHasKey('note', $decoded);
        $this->assertArrayNotHasKey('content_chars', $decoded['scale']);
        $this->assertArrayNotHasKey('kg_throughput_per_day', $decoded['backlog']);
    }

    public function test_compact_json_output_compares_previous_compact_artifact(): void
    {
        $retrievalFile = $this->writeRetrievalEvidence([
            'status' => 'observe_ok',
            'query_set_hash' => 'stable-query-set',
            'query_count' => 1,
            'successful_count' => 1,
            'empty_count' => 0,
            'failed_count' => 0,
            'include_results' => false,
            'latency_summary' => ['p95_ms' => 900],
            'score_summary' => ['top_similarity_avg' => 0.815],
            'evidence_contract' => ['version' => 1],
        ]);
        $previousFile = $this->writeRetrievalEvidence([
            'version' => 1,
            'compact' => true,
            'status' => 'observe_ok',
            'captured_at' => '2037-05-01T10:00:00Z',
            'scale' => [
                'documents' => 90,
                'evidence_error_count' => 0,
            ],
            'backlog' => [
                'documents' => 90,
                'kg_pending' => 25,
                'kg_fresh_pending' => 10,
                'kg_stale_pending' => 15,
                'raptor_pending' => 2,
                'sentence_pending' => 3,
                'evidence_error_count' => 0,
            ],
            'net_burn' => [
                'kg_net_burn_per_day' => 2.0,
                'evidence_error_count' => 0,
            ],
            'retrieval' => [
                'query_set_hash' => 'stable-query-set',
                'latency_p95_ms' => 1000,
                'top_similarity_avg' => 0.75,
            ],
            'evidence_error_count' => 1,
        ]);
        $this->mockRagService();

        $exit = Artisan::call('rag:scale-review', [
            '--retrieval-file' => $retrievalFile,
            '--previous-file' => $previousFile,
            '--json' => true,
            '--compact' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);
        $comparison = $decoded['comparison'];

        $this->assertSame(0, $exit);
        $this->assertSame('2037-05-01T10:00:00Z', $comparison['previous_captured_at']);
        $this->assertTrue($comparison['same_query_set']);
        $this->assertFalse($comparison['status_changed']);
        $this->assertSame(10, $comparison['documents_delta']);
        $this->assertSame(-5, $comparison['kg_pending_delta']);
        $this->assertSame(2, $comparison['kg_fresh_pending_delta']);
        $this->assertSame(-7, $comparison['kg_stale_pending_delta']);
        $this->assertSame(-2, $comparison['raptor_pending_delta']);
        $this->assertSame(-3, $comparison['sentence_pending_delta']);
        $this->assertSame(1.5, $comparison['kg_net_burn_per_day_delta']);
        $this->assertSame(-100, $comparison['retrieval_p95_ms_delta']);
        $this->assertSame(0.065, $comparison['top_similarity_avg_delta']);
        $this->assertSame(-1, $comparison['evidence_error_count_delta']);
    }

    public function test_previous_full_json_artifact_normalizes_without_leaking_raw_retrieval_content(): void
    {
        $previousFile = $this->writeRetrievalEvidence([
            'version' => 1,
            'status' => 'observe_warning',
            'captured_at' => '2037-05-01T12:00:00Z',
            'scale' => [
                'documents' => 80,
                'evidence_error_count' => 1,
            ],
            'backlog' => [
                'documents' => 80,
                'kg_pending' => 30,
                'kg_fresh_pending' => 20,
                'kg_stale_pending' => 10,
                'raptor_pending' => 4,
                'sentence_pending' => 5,
                'evidence_error_count' => 2,
            ],
            'net_burn' => [
                'kg_net_burn_per_day' => 1,
                'evidence_error_count' => 3,
            ],
            'retrieval' => [
                'query_set_hash' => null,
                'latency_p95_ms' => null,
                'top_similarity_avg' => null,
                'queries' => [
                    ['query' => 'Private previous query text'],
                ],
                'results' => [
                    ['title' => 'Private previous result title'],
                ],
            ],
        ]);
        $this->mockRagService();

        $exit = Artisan::call('rag:scale-review', [
            '--previous-file' => $previousFile,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $decoded = json_decode($output, true);
        $comparison = $decoded['comparison'];

        $this->assertSame(0, $exit);
        $this->assertSame('2037-05-01T12:00:00Z', $comparison['previous_captured_at']);
        $this->assertNull($comparison['same_query_set']);
        $this->assertTrue($comparison['status_changed']);
        $this->assertSame(20, $comparison['documents_delta']);
        $this->assertSame(-10, $comparison['kg_pending_delta']);
        $this->assertSame(-8, $comparison['kg_fresh_pending_delta']);
        $this->assertSame(-2, $comparison['kg_stale_pending_delta']);
        $this->assertSame(-4, $comparison['raptor_pending_delta']);
        $this->assertSame(-5, $comparison['sentence_pending_delta']);
        $this->assertSame(2.5, $comparison['kg_net_burn_per_day_delta']);
        $this->assertSame(-6, $comparison['evidence_error_count_delta']);
        $this->assertStringNotContainsString('Private previous query text', $output);
        $this->assertStringNotContainsString('Private previous result title', $output);
    }

    public function test_previous_file_comparison_redacts_raw_previous_query_and_result_text_in_all_outputs(): void
    {
        $previousFile = $this->writeRetrievalEvidence([
            'version' => 1,
            'status' => 'observe_ok',
            'captured_at' => '2037-05-01T12:00:00Z',
            'scale' => [
                'documents' => 80,
                'evidence_error_count' => 0,
            ],
            'backlog' => [
                'documents' => 80,
                'kg_pending' => 30,
                'kg_fresh_pending' => 20,
                'kg_stale_pending' => 10,
                'raptor_pending' => 4,
                'sentence_pending' => 5,
                'evidence_error_count' => 0,
            ],
            'net_burn' => [
                'kg_net_burn_per_day' => 1,
                'evidence_error_count' => 0,
            ],
            'retrieval' => [
                'query_set_hash' => 'previous-redaction-query-set',
                'latency_p95_ms' => 999,
                'top_similarity_avg' => 0.42,
                'queries' => [
                    [
                        'query' => 'TODO-018 raw previous query must stay private',
                        'results' => [
                            [
                                'title' => 'TODO-018 raw previous result title must stay private',
                                'preview' => 'TODO-018 raw previous result preview must stay private',
                            ],
                        ],
                    ],
                ],
                'results' => [
                    [
                        'title' => 'TODO-018 top-level previous result title must stay private',
                        'content' => 'TODO-018 top-level previous result body must stay private',
                    ],
                ],
            ],
        ]);

        foreach ($this->previousFileOutputOptions() as $label => $options) {
            $this->mockRagService();

            $exit = Artisan::call('rag:scale-review', [
                '--previous-file' => $previousFile,
                ...$options,
            ]);
            $output = Artisan::output();

            $this->assertSame(0, $exit, $label);
            $this->assertStringNotContainsString('TODO-018 raw previous query must stay private', $output, $label);
            $this->assertStringNotContainsString('TODO-018 raw previous result title must stay private', $output, $label);
            $this->assertStringNotContainsString('TODO-018 raw previous result preview must stay private', $output, $label);
            $this->assertStringNotContainsString('TODO-018 top-level previous result title must stay private', $output, $label);
            $this->assertStringNotContainsString('TODO-018 top-level previous result body must stay private', $output, $label);
        }
    }

    public function test_json_output_accepts_legacy_latency_keys_for_saved_draft_files(): void
    {
        $retrievalFile = $this->writeRetrievalEvidence([
            'status' => 'observe_ok',
            'query_set_hash' => 'legacy-latency',
            'query_count' => 1,
            'successful_count' => 1,
            'empty_count' => 0,
            'failed_count' => 0,
            'include_results' => false,
            'latency_summary' => [
                'min_ms' => 10,
                'max_ms' => 20,
                'avg_ms' => 15,
                'p95_ms' => 20,
            ],
            'score_summary' => [],
            'evidence_contract' => ['version' => 1],
        ]);
        $this->mockRagService();

        $exit = Artisan::call('rag:scale-review', [
            '--retrieval-file' => $retrievalFile,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(20, $decoded['retrieval']['latency_p95_ms']);
        $this->assertSame(15, $decoded['retrieval']['latency_avg_ms']);
    }

    public function test_compact_text_reports_redacted_saved_retrieval_metadata(): void
    {
        $retrievalFile = $this->writeRetrievalEvidence([
            'status' => 'observe_ok',
            'query_set_hash' => 'compact-retrieval-hash',
            'query_count' => 1,
            'successful_count' => 1,
            'empty_count' => 0,
            'failed_count' => 0,
            'limit' => 4,
            'include_results' => true,
            'latency_summary' => ['p95_ms' => 654],
            'score_summary' => ['top_similarity_avg' => 0.77],
            'evidence_contract' => [
                'version' => 1,
                'mode' => 'observe_only',
                'query_set_hash_basis' => 'ordered_query_hashes_and_type_filters',
                'query_text' => 'redacted_by_default',
                'results' => 'included_by_operator_option',
            ],
            'queries' => [
                [
                    'query_hash' => 'compact-private-query',
                    'query' => 'Private raw query text',
                    'results' => [
                        ['title' => 'Private Result Title', 'preview' => 'Private result preview'],
                    ],
                ],
            ],
        ]);
        $this->mockRagService();

        $exit = Artisan::call('rag:scale-review', [
            '--retrieval-file' => $retrievalFile,
            '--compact' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('RAG scale review compact: observe_ok', $output);
        $this->assertStringContainsString('retrieval=observe_ok', $output);
        $this->assertStringContainsString('query_set=compact-retrieval-hash', $output);
        $this->assertStringContainsString('query_count=1', $output);
        $this->assertStringContainsString('p95_ms=654', $output);
        $this->assertStringContainsString('retrieval_redacted=false', $output);
        $this->assertStringContainsString('query_policy=redacted_by_default', $output);
        $this->assertStringContainsString('results_policy=included_by_operator_option', $output);
        $this->assertStringNotContainsString('Private raw query text', $output);
        $this->assertStringNotContainsString('Private Result Title', $output);
        $this->assertStringNotContainsString('Private result preview', $output);
    }

    public function test_compact_markdown_reports_redacted_saved_retrieval_metadata(): void
    {
        $retrievalFile = $this->writeRetrievalEvidence([
            'status' => 'observe_ok',
            'query_set_hash' => 'compact-markdown-hash',
            'query_count' => 1,
            'successful_count' => 1,
            'empty_count' => 0,
            'failed_count' => 0,
            'limit' => 4,
            'default_type' => 'genealogy',
            'include_results' => false,
            'latency_summary' => ['p95_ms' => 654],
            'score_summary' => ['top_similarity_avg' => 0.77],
            'evidence_contract' => [
                'version' => 1,
                'mode' => 'observe_only',
                'query_set_hash_basis' => 'ordered_query_hashes_and_type_filters',
                'query_text' => 'redacted_by_default',
                'results' => 'redacted_by_default',
            ],
            'queries' => [
                [
                    'query_hash' => 'compact-markdown-private-query',
                    'query' => 'Private compact markdown raw query text',
                    'results' => [
                        ['title' => 'Private compact markdown title'],
                    ],
                ],
            ],
        ]);
        $this->mockRagService();

        $exit = Artisan::call('rag:scale-review', [
            '--retrieval-file' => $retrievalFile,
            '--compact' => true,
            '--markdown' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('# RAG Scale Review Compact', $output);
        $this->assertStringContainsString('- Retrieval status: `observe_ok`', $output);
        $this->assertStringContainsString('- Query set hash: `compact-markdown-hash`', $output);
        $this->assertStringContainsString('- Query set hash basis: `ordered_query_hashes_and_type_filters`', $output);
        $this->assertStringContainsString('- Query count: `1`', $output);
        $this->assertStringContainsString('- Result limit: `4`', $output);
        $this->assertStringContainsString('- Default type: `genealogy`', $output);
        $this->assertStringContainsString('- Evidence contract mode: `observe_only`', $output);
        $this->assertStringContainsString('- Query text policy: `redacted_by_default`', $output);
        $this->assertStringContainsString('- Results policy: `redacted_by_default`', $output);
        $this->assertStringContainsString('- Result content redacted: `true`', $output);
        $this->assertStringContainsString('- Retrieval p95 ms: `654`', $output);
        $this->assertStringNotContainsString('Private compact markdown raw query text', $output);
        $this->assertStringNotContainsString('Private compact markdown title', $output);
    }

    public function test_markdown_output_reports_redacted_review_summary(): void
    {
        $retrievalFile = $this->writeRetrievalEvidence([
            'status' => 'observe_ok',
            'query_set_hash' => 'hash-for-markdown',
            'query_count' => 1,
            'successful_count' => 1,
            'empty_count' => 0,
            'failed_count' => 0,
            'limit' => 3,
            'include_results' => false,
            'latency_summary' => ['p95_ms' => 321],
            'score_summary' => ['top_similarity_avg' => 0.88],
            'evidence_contract' => [
                'version' => 1,
                'mode' => 'observe_only',
                'query_set_hash_basis' => 'ordered_query_hashes_and_type_filters',
                'query_text' => 'redacted_by_default',
                'results' => 'redacted_by_default',
            ],
            'queries' => [
                ['query_hash' => 'abc', 'query' => 'Private raw query text'],
            ],
        ]);
        $this->mockRagService();

        $exit = Artisan::call('rag:scale-review', [
            '--retrieval-file' => $retrievalFile,
            '--markdown' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('# RAG Scale Review', $output);
        $this->assertStringContainsString('- Query set hash: `hash-for-markdown`', $output);
        $this->assertStringContainsString('- Query set hash basis: `ordered_query_hashes_and_type_filters`', $output);
        $this->assertStringContainsString('- Query count: `1`', $output);
        $this->assertStringContainsString('- Result limit: `3`', $output);
        $this->assertStringContainsString('- Query text policy: `redacted_by_default`', $output);
        $this->assertStringContainsString('- Results policy: `redacted_by_default`', $output);
        $this->assertStringContainsString('- Retrieval p95 ms: `321`', $output);
        $this->assertStringNotContainsString('Private raw query text', $output);
    }

    public function test_missing_retrieval_file_fails_without_collecting_evidence(): void
    {
        $service = Mockery::mock(RagBacklogService::class);
        $service->shouldNotReceive('getScaleBaseline');
        $service->shouldNotReceive('getDigestMetrics');
        $service->shouldNotReceive('getNetBurn');
        $this->app->instance(RagBacklogService::class, $service);

        $cases = [
            '/tmp/does-not-exist-rag-review.json' => [
                '/tmp/does-not-exist-rag-review.json',
                'does-not-exist-rag-review.json',
            ],
            'storage/app/private-rag-proof/missing-retrieval.json' => [
                'storage/app/private-rag-proof/missing-retrieval.json',
                'private-rag-proof',
                'missing-retrieval.json',
            ],
        ];

        foreach ($cases as $path => $redactedFragments) {
            $exit = Artisan::call('rag:scale-review', [
                '--retrieval-file' => $path,
            ]);

            $this->assertSame(1, $exit, $path);
            $output = Artisan::output();
            $this->assertStringContainsString('Retrieval evidence file not found. Check --retrieval-file path.', $output, $path);

            foreach ($redactedFragments as $fragment) {
                $this->assertStringNotContainsString($fragment, $output, $path);
            }
        }
    }

    public function test_missing_previous_file_fails_before_collecting_evidence(): void
    {
        $service = Mockery::mock(RagBacklogService::class);
        $service->shouldNotReceive('getScaleBaseline');
        $service->shouldNotReceive('getDigestMetrics');
        $service->shouldNotReceive('getNetBurn');
        $this->app->instance(RagBacklogService::class, $service);

        $cases = [
            '/tmp/does-not-exist-rag-scale-review.json' => [
                '/tmp/does-not-exist-rag-scale-review.json',
                'does-not-exist-rag-scale-review.json',
            ],
            '~/private-rag-proof/missing-scale-review.json' => [
                '~/private-rag-proof/missing-scale-review.json',
                'private-rag-proof',
                'missing-scale-review.json',
            ],
        ];

        foreach ($cases as $path => $redactedFragments) {
            $exit = Artisan::call('rag:scale-review', [
                '--previous-file' => $path,
                '--json' => true,
            ]);

            $this->assertSame(1, $exit, $path);
            $output = Artisan::output();
            $this->assertStringContainsString('Previous scale review file not found. Check --previous-file path.', $output, $path);

            foreach ($redactedFragments as $fragment) {
                $this->assertStringNotContainsString($fragment, $output, $path);
            }
        }
    }

    public function test_json_and_markdown_options_are_mutually_exclusive(): void
    {
        $service = Mockery::mock(RagBacklogService::class);
        $service->shouldNotReceive('getScaleBaseline');
        $this->app->instance(RagBacklogService::class, $service);

        $this->artisan('rag:scale-review --json --markdown')
            ->expectsOutputToContain('Choose either --json or --markdown, not both.')
            ->assertExitCode(1);
    }

    private function mockRagService(): void
    {
        $service = Mockery::mock(RagBacklogService::class);
        $service->shouldReceive('getScaleBaseline')->once()->andReturn($this->scalePayload());
        $service->shouldReceive('getDigestMetrics')->once()->andReturn($this->backlogPayload());
        $service->shouldReceive('getNetBurn')->once()->with(7)->andReturn($this->netBurnPayload());
        $this->app->instance(RagBacklogService::class, $service);
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function previousFileOutputOptions(): array
    {
        return [
            'full text' => [],
            'compact text' => ['--compact' => true],
            'markdown' => ['--markdown' => true],
            'full json' => ['--json' => true],
            'compact json' => ['--json' => true, '--compact' => true],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeRetrievalEvidence(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rag-review-');
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function scalePayload(): array
    {
        return [
            'status' => 'observe_ok',
            'summary' => [
                'documents' => 100,
                'content_chars' => 250000,
                'avg_content_chars' => 2500,
                'max_content_chars' => 120000,
                'compressed_ratio' => 0.25,
                'contextualized_ratio' => 0.4,
            ],
            'storage' => [
                'total_relation_mb' => 12.0,
                'total_bytes_per_document' => 125829.12,
                'total_bytes_per_content_char' => 50.3316,
            ],
            'postgres' => [
                'table_health' => [
                    'dead_tuples' => 42,
                    'dead_tuple_ratio' => 0.04,
                ],
                'index_summary' => [
                    'index_count' => 2,
                    'zero_scan_indexes' => 1,
                    'invalid_indexes' => 0,
                    'largest_index_mb' => 2.0,
                ],
            ],
            'evidence_errors' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function backlogPayload(): array
    {
        return [
            'documents' => 100,
            'kg' => [
                'pending' => 20,
                'fresh' => 12,
                'stale' => 8,
                'throughput_per_day' => 5,
                'eta_days' => 4,
            ],
            'raptor' => ['pending' => 0],
            'sentence' => ['pending' => 0],
            'evidence_errors' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function netBurnPayload(): array
    {
        return [
            'window_days' => 7,
            'lanes' => [
                'kg' => [
                    'net_burn_per_day' => 3.5,
                    'trend' => 'shrinking',
                    'samples' => 7,
                ],
            ],
            'evidence_errors' => [],
        ];
    }
}
