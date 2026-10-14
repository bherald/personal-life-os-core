<?php

namespace Tests\Unit\Services;

use App\DTOs\PolicyDecision;
use App\Services\OfflinePolicyService;
use App\Services\UpstreamSourceDriftService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class UpstreamSourceDriftServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $network = Mockery::mock(OfflinePolicyService::class);
        $network->shouldReceive('isInternetAccessEnabled')->andReturn(true);
        $network->shouldReceive('evaluateRemoteDomain')->andReturn(PolicyDecision::allow('Fixture public metadata', 'test'));
        $this->app->instance(OfflinePolicyService::class, $network);
    }

    public function test_compares_github_commits_and_arxiv_versions_without_retaining_source_content(): void
    {
        config()->set('whiteroom_projects.source_drift', [
            'github_api_version' => '2026-03-10',
            'user_agent' => 'PLOS-Test/1.0',
        ]);

        Http::fake([
            'https://api.github.com/repos/example/repo/commits*' => Http::response([[
                'sha' => str_repeat('b', 40),
                'commit' => [
                    'committer' => ['date' => '2026-07-14T12:00:00Z'],
                    'message' => 'private commit message must not escape',
                ],
            ]]),
            'https://export.arxiv.org/api/query*' => Http::response($this->arxivFeed([
                ['id' => '2602.11988', 'version' => 'v3', 'updated_at' => '2026-07-14T08:00:00Z', 'title' => 'private paper title'],
            ]), 200, ['Content-Type' => 'application/atom+xml']),
        ]);

        $results = app(UpstreamSourceDriftService::class)->inspect([
            [
                'key' => 'repo',
                'registry' => 'monitor_watchlist',
                'source_type' => 'repository',
                'identifier_scheme' => 'git_commit',
                'source_version' => str_repeat('a', 40),
                'source_updated_at' => '2026-07-13',
                'repo' => 'https://github.com/example/repo',
            ],
            [
                'key' => 'paper',
                'registry' => 'research_watchlist',
                'source_type' => 'paper',
                'identifier_scheme' => 'arxiv',
                'identifier' => '2602.11988',
                'source_version' => 'v2',
                'source_updated_at' => '2026-06-23',
            ],
        ], 2, 5, 262144);

        $this->assertSame(['repo', 'paper'], array_column($results, 'project_key'));
        $this->assertSame(['source_version', 'source_updated_at'], $results[0]['drift_fields']);
        $this->assertSame(str_repeat('b', 40), $results[0]['observed_version']);
        $this->assertSame('v3', $results[1]['observed_version']);
        $this->assertSame('2026-07-14', $results[1]['observed_updated_at']);
        $this->assertSame('drift', $results[0]['state']);
        $this->assertSame('drift', $results[1]['state']);

        $encoded = json_encode($results);
        $this->assertStringNotContainsString('private commit message', $encoded);
        $this->assertStringNotContainsString('private paper title', $encoded);
        $this->assertStringNotContainsString('summary', $encoded);
    }

    public function test_stops_further_github_requests_after_rate_limit_response(): void
    {
        config()->set('whiteroom_projects.source_drift', [
            'github_api_version' => '2026-03-10',
            'user_agent' => 'PLOS-Test/1.0',
        ]);

        Http::fake([
            'https://api.github.com/repos/example/first/commits*' => Http::response([], 429, [
                'Retry-After' => '60',
                'X-RateLimit-Remaining' => '0',
            ]),
            '*' => Http::response([], 500),
        ]);

        $results = app(UpstreamSourceDriftService::class)->inspect([
            $this->repositorySource('first'),
            $this->repositorySource('second'),
        ], 2, 5, 262144);

        $this->assertSame('rate_limited', $results[0]['state']);
        $this->assertSame(60, $results[0]['retry_after_seconds']);
        $this->assertSame('skipped_rate_limited', $results[1]['state']);

        Http::assertSentCount(1);
    }

    /** @return array<string, mixed> */
    private function repositorySource(string $name): array
    {
        return [
            'key' => $name,
            'registry' => 'monitor_watchlist',
            'source_type' => 'repository',
            'identifier_scheme' => 'git_commit',
            'source_version' => str_repeat('a', 40),
            'source_updated_at' => '2026-07-13',
            'repo' => "https://github.com/example/{$name}",
        ];
    }

    /** @param array<int, array{id:string,version:string,updated_at:string,title:string}> $entries */
    private function arxivFeed(array $entries): string
    {
        $body = '';
        foreach ($entries as $entry) {
            $body .= <<<XML
              <entry>
                <id>http://arxiv.org/abs/{$entry['id']}{$entry['version']}</id>
                <updated>{$entry['updated_at']}</updated>
                <title>{$entry['title']}</title>
                <summary>private abstract must not escape</summary>
              </entry>
            XML;
        }

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom">
          {$body}
        </feed>
        XML;
    }
}
