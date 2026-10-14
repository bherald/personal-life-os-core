<?php

namespace Tests\Unit\Services;

use App\Services\OfflineAuditService;
use App\Services\OfflinePolicyService;
use App\Services\SystemConfigService;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpstreamMetadataNetworkScopeTest extends TestCase
{
    private function policy(array $overrides = []): OfflinePolicyService
    {
        $values = $overrides + ['routing.active_profile' => 'resilient_local_first',
            'routing.internet_access' => 'enabled', 'routing.offline_mode' => 'disabled', 'cli.network' => 'online'];
        $settings = Mockery::mock(SystemConfigService::class);
        $settings->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => $values[$key] ?? $default);
        $audit = Mockery::mock(OfflineAuditService::class);
        $audit->shouldReceive('recordDecision')->zeroOrMoreTimes();

        return new OfflinePolicyService($audit, $settings);
    }

    #[DataProvider('requests')]
    public function test_exact_public_request_boundary(string $url, array $query, bool $expected, string $method = 'GET', string $task = 'whiteroom_monitor_due_report'): void
    {
        $decision = $this->policy()->evaluateTaskMetadataGet($task, $url, $query, 'upstream_public_metadata', $method);
        $this->assertSame($expected, $decision->allowed);
    }

    public static function requests(): array
    {
        return [
            ['https://api.github.com/repos/JustVugg/colibri/commits', ['per_page' => 1], true],
            ['https://api.github.com/repos/JustVugg/colibri/license', ['ref' => str_repeat('a', 40)], true],
            ['https://api.github.com/repos/JustVugg/colibri/releases/latest', [], true],
            ['https://api.github.com/repos/JustVugg/colibri', [], true],
            ['https://huggingface.co/api/models/Aleph-Alpha/Kolibri-1', ['expand' => ['sha', 'lastModified', 'cardData']], true],
            ['https://export.arxiv.org/api/query', ['id_list' => '2601.12345v2,cs/0601001', 'max_results' => 2], true],
            ['https://api.github.com/repos/JustVugg/colibri/issues', [], false],
            ['https://api.github.com/repos/JustVugg/colibri/git/blobs/abc', [], false],
            ['https://huggingface.co/Aleph-Alpha/Kolibri-1/resolve/main/model.safetensors', [], false],
            ['https://export.arxiv.org/pdf/2601.12345', [], false],
            ['http://api.github.com/repos/JustVugg/colibri/commits', ['per_page' => 1], false],
            ['https://api.github.com.evil.test/repos/JustVugg/colibri/commits', ['per_page' => 1], false],
            ['https://user:secret@api.github.com/repos/JustVugg/colibri/commits', ['per_page' => 1], false],
            ['https://api.github.com:443/repos/JustVugg/colibri/commits', ['per_page' => 1], false],
            ['https://api.github.com/repos/JustVugg/colibri/commits?private=content', ['per_page' => 1], false],
            ['https://api.github.com/repos/JustVugg/colibri/commits#fragment', ['per_page' => 1], false],
            ['https://api.github.com/repos/JustVugg/colibri/commits', ['per_page' => 100], false],
            ['https://api.github.com/repos/JustVugg/colibri/license', ['ref' => 'main', 'private' => 'prompt'], false],
            ['https://export.arxiv.org/api/query', ['id_list' => 'private prompt', 'max_results' => 1], false],
            ['https://export.arxiv.org/api/query', ['id_list' => '2601.12345', 'max_results' => 2], false],
            ['https://api.github.com/repos/JustVugg/colibri/releases/latest', [], false, 'POST'],
            ['https://api.github.com/repos/JustVugg/colibri/releases/latest', [], false, 'GET', 'another_job'],
        ];
    }

    #[DataProvider('restrictedParents')]
    public function test_task_permission_cannot_relax_existing_restrictions(array $settings): void
    {
        $this->assertFalse($this->policy($settings)->evaluateTaskMetadataGet('whiteroom_monitor_due_report',
            'https://api.github.com/repos/JustVugg/colibri/commits', ['per_page' => 1], 'upstream_public_metadata')->allowed);
    }

    public static function restrictedParents(): array
    {
        return [
            [['routing.internet_access' => 'disabled']],
            [['routing.offline_mode' => 'enabled']],
            [['cli.network' => 'lan']],
            [['cli.network' => 'offline']],
            [['routing.active_profile' => 'offline_review']],
            [['routing.active_profile' => 'private_genealogy_research']],
            [['routing.active_profile' => 'upstream_public_metadata']],
        ];
    }

    public function test_registered_task_profile_never_grants_generic_domain_access(): void
    {
        $policy = $this->policy();
        $this->assertFalse($policy->evaluateRemoteDomain('api.github.com', 'upstream_public_metadata')->allowed);
        $this->assertFalse($policy->evaluateRemoteDomain('example.com', 'upstream_public_metadata')->allowed);
        $this->assertFalse($policy->evaluateTaskMetadataGet('whiteroom_monitor_due_report',
            'https://api.github.com/repos/JustVugg/colibri', [], 'default')->allowed);
    }
}
