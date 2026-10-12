<?php

namespace Tests\Unit\Nodes;

use App\Nodes\ResearchQuery;
use App\Services\ResearchService;
use Mockery;
use Tests\TestCase;

class ResearchQueryOutputControlTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_research_query_caps_and_dedupes_accumulated_articles(): void
    {
        $service = Mockery::mock(ResearchService::class);
        $service->shouldReceive('research')
            ->once()
            ->andReturn([
                'query' => 'cybersecurity',
                'sources_queried' => ['mock'],
                'total_results' => 2,
                'duration_ms' => 12,
                'ai_analysis' => null,
                'results' => [
                    'articles' => [
                        [
                            'title' => 'Newest',
                            'url' => 'https://example.com/newest',
                            'description' => 'n',
                            'published' => '2026-04-03T12:00:00Z',
                            'source' => 'Mock News',
                        ],
                        [
                            'title' => 'Duplicate API',
                            'url' => 'https://example.com/shared',
                            'description' => 'd',
                            'published' => '2026-04-02T12:00:00Z',
                            'source' => 'Mock News',
                        ],
                    ],
                ],
            ]);

        $this->app->instance(ResearchService::class, $service);

        $node = new ResearchQuery([
            'query' => 'cybersecurity',
            'max_total_articles' => 2,
            'max_formatted_chars' => 120,
        ]);

        $result = $node->execute([
            'data' => [
                'formatted_text' => str_repeat('P', 150),
                'articles' => [
                    [
                        'title' => 'Oldest',
                        'url' => 'https://example.com/oldest',
                        'pubDate' => '2026-04-01T12:00:00Z',
                        'source' => 'RSS',
                    ],
                    [
                        'title' => 'Duplicate RSS',
                        'url' => 'https://example.com/shared',
                        'pubDate' => '2026-04-01T10:00:00Z',
                        'source' => 'RSS',
                    ],
                ],
            ],
        ]);

        $articles = $result['data']['articles'];

        $this->assertCount(2, $articles);
        $this->assertSame('Newest', $articles[0]['title']);
        $this->assertSame('Oldest', $articles[1]['title']);
        $this->assertLessThanOrEqual(120, mb_strlen($result['data']['formatted_text']));
        $this->assertStringContainsString('[truncated to 120 chars]', $result['data']['formatted_text']);
    }

    public function test_research_query_skips_research_service_for_rss_only_non_ai_runs(): void
    {
        $service = Mockery::mock(ResearchService::class);
        $service->shouldNotReceive('research');
        $this->app->instance(ResearchService::class, $service);

        $node = new ResearchQuery([
            'query' => 'daily news',
            'sources' => '',
            'use_ai' => false,
            'max_total_articles' => 3,
        ]);

        $result = $node->execute([
            'data' => [
                'formatted_text' => 'RSS summary',
                'articles' => [
                    ['title' => 'A', 'url' => 'https://example.com/a'],
                    ['title' => 'B', 'url' => 'https://example.com/b'],
                ],
            ],
        ]);

        $this->assertNull($result['error']);
        $this->assertSame('rss_only', $result['meta']['sources']);
        $this->assertTrue($result['meta']['lightweight_rss_only']);
        $this->assertCount(2, $result['data']['articles']);
    }

    public function test_research_query_treats_string_false_as_boolean_false(): void
    {
        $service = Mockery::mock(ResearchService::class);
        $service->shouldNotReceive('research');
        $this->app->instance(ResearchService::class, $service);

        $node = new ResearchQuery([
            'query' => 'daily news',
            'sources' => '',
            'use_ai' => 'false',
            'parallel' => 'true',
        ]);

        $result = $node->execute([
            'data' => [
                'formatted_text' => 'RSS summary',
                'articles' => [
                    ['title' => 'A', 'url' => 'https://example.com/a'],
                ],
            ],
        ]);

        $this->assertNull($result['error']);
        $this->assertTrue($result['meta']['lightweight_rss_only']);
    }
}
