<?php

namespace Tests\Unit\Services;

use App\Services\RagSourceReferenceService;
use PHPUnit\Framework\TestCase;

class RagSourceReferenceServiceTest extends TestCase
{
    private RagSourceReferenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new RagSourceReferenceService;
    }

    public function test_source_bound_reference_is_stable_and_does_not_expose_scope_value(): void
    {
        $document = (object) [
            'id' => 42,
            'document_type' => 'joplin_note',
            'source_type' => 'joplin',
            'source_id' => 'private-note-id',
            'media_url' => 'https://example.com/note/42',
            'metadata' => json_encode(['agent_id' => 'private-agent-id'], JSON_THROW_ON_ERROR),
        ];

        $reference = $this->service->reference($document);

        $this->assertSame('plos.rag_source_reference.v1', $reference['schema']);
        $this->assertSame('source_bound', $reference['citation_status']);
        $this->assertTrue($reference['citation_ready']);
        $this->assertSame('joplin', $reference['source_type']);
        $this->assertTrue($reference['source_id_present']);
        $this->assertSame('agent', $reference['scope']);
        $this->assertSame('https://example.com/note/42', $reference['locator']);
        $this->assertMatchesRegularExpression('/^rag_src_[a-f0-9]{16}$/', $reference['source_ref']);
        $this->assertStringNotContainsString('private-note-id', json_encode($reference, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private-agent-id', json_encode($reference, JSON_THROW_ON_ERROR));
        $this->assertSame($reference, $this->service->reference($document));
    }

    public function test_locator_only_reference_rejects_unsafe_scheme(): void
    {
        $locator = $this->service->reference((object) [
            'id' => 8,
            'document_type' => 'file',
            'media_url' => '/api/media/8',
        ]);
        $unsafe = $this->service->reference((object) [
            'id' => 9,
            'document_type' => 'file',
            'media_url' => 'file:///home/example/private.txt',
        ]);

        $this->assertSame('locator_only', $locator['citation_status']);
        $this->assertTrue($locator['citation_ready']);
        $this->assertSame('/api/media/8', $locator['locator']);
        $this->assertSame('document_only', $unsafe['citation_status']);
        $this->assertFalse($unsafe['citation_ready']);
        $this->assertNull($unsafe['locator']);
    }

    public function test_source_reference_is_keyed_per_installation(): void
    {
        $document = (object) [
            'source_type' => 'joplin',
            'source_id' => '7',
        ];

        $first = (new RagSourceReferenceService('installation-a'))->reference($document);
        $second = (new RagSourceReferenceService('installation-b'))->reference($document);

        $this->assertNotSame($first['source_ref'], $second['source_ref']);
    }

    public function test_summary_counts_citation_readiness_and_scopes_without_content(): void
    {
        $summary = $this->service->summarizeResults([
            ['document' => (object) ['id' => 1, 'source_type' => 'mail', 'source_id' => '11', 'metadata' => ['user_id' => 7]]],
            ['document' => (object) ['id' => 2, 'media_url' => 'https://example.org/doc']],
            ['document' => (object) ['id' => 3, 'source_type' => 'file']],
            ['document' => (object) ['id' => 4, 'metadata' => ['workspace_id' => 'a']]],
        ]);

        $this->assertSame(4, $summary['result_count']);
        $this->assertSame(2, $summary['citation_ready_count']);
        $this->assertSame(0.5, $summary['citation_ready_rate']);
        $this->assertSame(1, $summary['source_bound_count']);
        $this->assertSame(1, $summary['locator_only_count']);
        $this->assertSame(1, $summary['source_type_only_count']);
        $this->assertSame(1, $summary['document_only_count']);
        $this->assertSame(2, $summary['scoped_count']);
        $this->assertSame(2, $summary['global_scope_count']);
        $this->assertSame(1, $summary['scope_counts']['user']);
        $this->assertSame(1, $summary['scope_counts']['workspace']);
    }

    public function test_merge_summaries_recomputes_rate(): void
    {
        $left = $this->service->summarizeResults([
            ['document' => (object) ['source_type' => 'mail', 'source_id' => '1']],
        ]);
        $right = $this->service->summarizeResults([
            ['document' => (object) ['id' => 2]],
            ['document' => (object) ['id' => 3]],
        ]);

        $merged = $this->service->mergeSummaries($left, $right);

        $this->assertSame(3, $merged['result_count']);
        $this->assertSame(1, $merged['citation_ready_count']);
        $this->assertSame(0.3333, $merged['citation_ready_rate']);
        $this->assertSame(3, $merged['global_scope_count']);
    }
}
