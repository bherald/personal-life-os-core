<?php

namespace Tests\Feature\Console;

use App\Services\Ops\McpHealthReportService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class OpsMcpHealthCommandTest extends TestCase
{
    private string $fixtureEntry;

    private string $disabledFixtureEntry;

    private ?string $originalPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureEntry = storage_path('app/testing-mcp-health/server.js');
        $this->disabledFixtureEntry = storage_path('app/testing-mcp-health/disabled-server.js');
        File::ensureDirectoryExists(dirname($this->fixtureEntry));
        File::put($this->fixtureEntry, 'console.log("fixture");');
        File::put($this->disabledFixtureEntry, 'console.log("disabled fixture");');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        if ($this->originalPath !== null) {
            putenv('PATH='.$this->originalPath);
            $_ENV['PATH'] = $this->originalPath;
            $_SERVER['PATH'] = $this->originalPath;
        }

        File::deleteDirectory(storage_path('app/testing-mcp-health'));

        parent::tearDown();
    }

    public function test_service_reports_configured_servers_without_env_values_or_process_lines(): void
    {
        config()->set('mcp.servers', [
            'internal' => [
                'enabled' => true,
                'type' => 'internal',
                'transport' => 'internal_service',
                'tools' => 2,
                'env' => ['TOKEN' => 'secret-token'],
            ],
            'external-ok' => [
                'enabled' => true,
                'type' => 'external',
                'transport' => 'external_process',
                'command' => 'node',
                'args' => [$this->fixtureEntry],
                'tools' => 7,
                'env' => ['TOKEN' => 'another-secret'],
            ],
            'external-missing-entry' => [
                'enabled' => true,
                'type' => 'external',
                'transport' => 'external_process',
                'command' => 'node',
                'args' => [base_path('missing-mcp-entry.js')],
            ],
            'external-directory-arg' => [
                'enabled' => true,
                'type' => 'external',
                'transport' => 'external_process',
                'command' => 'serena',
                'args' => [dirname($this->fixtureEntry)],
            ],
            'external-unmatchable' => [
                'enabled' => true,
                'type' => 'external',
                'transport' => 'external_process',
                'command' => 'node',
                'args' => [dirname($this->fixtureEntry), '--token=secret-token'],
            ],
            'disabled-running' => [
                'enabled' => false,
                'type' => 'external',
                'transport' => 'external_process',
                'command' => 'node',
                'args' => [$this->disabledFixtureEntry],
            ],
            'disabled-missing-entry' => [
                'enabled' => false,
                'type' => 'external',
                'transport' => 'external_process',
                'command' => 'node',
                'args' => [base_path('missing-disabled-mcp-entry.js')],
            ],
        ]);

        $service = app(McpHealthReportService::class);
        $payload = $service->collect(implode("\n", [
            '123 1 Sl node '.$this->fixtureEntry,
            '124 1 Sl node '.$this->disabledFixtureEntry,
        ]));
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('critical', $payload['status']);
        $this->assertSame(7, $payload['summary']['total']);
        $this->assertSame(5, $payload['summary']['enabled']);
        $this->assertSame(1, $payload['summary']['critical']);
        $this->assertSame(3, $payload['summary']['watch']);
        $this->assertSame(2, $payload['summary']['missing_entries']);
        $this->assertSame(1, $payload['summary']['enabled_missing_entries']);
        $this->assertSame(1, $payload['summary']['disabled_missing_entries']);
        $this->assertSame(3, $payload['summary']['external_not_running']);
        $this->assertSame(1, $payload['summary']['disabled_external_running']);

        $servers = collect($payload['servers'])->keyBy('name');
        $this->assertSame('ok', $servers['internal']['status']);
        $this->assertSame('ok', $servers['external-ok']['status']);
        $this->assertSame('critical', $servers['external-missing-entry']['status']);
        $this->assertSame('watch', $servers['external-directory-arg']['status']);
        $this->assertSame('watch', $servers['external-unmatchable']['status']);
        $this->assertSame('watch', $servers['disabled-running']['status']);
        $this->assertSame('disabled', $servers['disabled-missing-entry']['status']);
        $this->assertSame('storage/app/testing-mcp-health/server.js', $servers['external-ok']['local_entries'][0]['path']);
        $this->assertTrue($servers['external-ok']['process']['running']);
        $this->assertTrue($servers['external-ok']['process']['matchable']);
        $this->assertSame(1, $servers['external-ok']['process']['marker_count']);
        $this->assertFalse($servers['external-missing-entry']['local_entries'][0]['exists']);
        $this->assertSame('directory', $servers['external-directory-arg']['local_entries'][0]['kind']);
        $this->assertSame(0, $servers['external-directory-arg']['missing_entries']);
        $this->assertFalse($servers['external-unmatchable']['process']['matchable']);
        $this->assertSame(0, $servers['external-unmatchable']['process']['marker_count']);

        $attention = collect($service->compactPayload($payload)['attention'])->keyBy('name');
        $this->assertFalse($attention['external-unmatchable']['process_matchable']);
        $this->assertSame(0, $attention['external-unmatchable']['process_marker_count']);

        $this->assertStringNotContainsString('secret-token', $encoded);
        $this->assertStringNotContainsString('another-secret', $encoded);
        $this->assertStringNotContainsString('123 1 Sl', $encoded);
        $this->assertStringNotContainsString('124 1 Sl', $encoded);
    }

    public function test_text_summaries_include_disabled_external_running_from_fixture_processes(): void
    {
        config()->set('mcp.servers', [
            'external-ok' => [
                'enabled' => true,
                'type' => 'external',
                'transport' => 'external_process',
                'command' => 'node',
                'args' => [$this->fixtureEntry],
            ],
            'disabled-running' => [
                'enabled' => false,
                'type' => 'external',
                'transport' => 'external_process',
                'command' => 'node',
                'args' => [$this->disabledFixtureEntry],
            ],
        ]);
        $this->useFixturePs([
            '123 1 Sl node '.$this->fixtureEntry,
            '124 1 Sl node '.$this->disabledFixtureEntry,
        ]);

        $exit = Artisan::call('ops:mcp-health');

        $this->assertSame(0, $exit);
        $output = (string) Artisan::output();
        $this->assertStringContainsString('MCP health: WARNING', $output);
        $this->assertStringContainsString('external_not_running=0  disabled_external_running=1', $output);
        $this->assertStringContainsString('server=disabled-running status=watch enabled=false transport=external_process process_expected=true process_running=true missing_entries=0', $output);

        $exit = Artisan::call('ops:mcp-health', ['--compact' => true]);

        $this->assertSame(0, $exit);
        $output = (string) Artisan::output();
        $this->assertStringContainsString('MCP health compact: WARNING', $output);
        $this->assertStringContainsString('external_not_running=0  disabled_external_running=1', $output);
    }

    public function test_json_option_emits_service_payload(): void
    {
        $payload = $this->payload();

        $service = Mockery::mock(McpHealthReportService::class);
        $service->shouldReceive('collect')->once()->andReturn($payload);
        $this->app->instance(McpHealthReportService::class, $service);

        $exit = Artisan::call('ops:mcp-health', ['--json' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame($payload, json_decode((string) Artisan::output(), true));
    }

    public function test_compact_json_option_uses_compact_payload(): void
    {
        $payload = $this->payload();
        $compact = [
            'generated_at' => '2026-05-04T18:00:00Z',
            'compact' => true,
            'status' => 'warning',
            'summary' => ['total' => 2, 'enabled' => 1, 'external' => 1],
            'attention' => [['name' => 'prompt-compressor', 'status' => 'watch']],
        ];

        $service = Mockery::mock(McpHealthReportService::class);
        $service->shouldReceive('collect')->once()->andReturn($payload);
        $service->shouldReceive('compactPayload')->once()->with($payload)->andReturn($compact);
        $this->app->instance(McpHealthReportService::class, $service);

        $exit = Artisan::call('ops:mcp-health', ['--json' => true, '--compact' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame($compact, json_decode((string) Artisan::output(), true));
    }

    public function test_compact_text_omits_ok_server_details(): void
    {
        $payload = $this->payload();
        $compact = [
            'status' => 'warning',
            'summary' => [
                'total' => 2,
                'enabled' => 1,
                'external' => 1,
                'watch' => 1,
                'critical' => 0,
                'missing_entries' => 1,
                'enabled_missing_entries' => 0,
                'disabled_missing_entries' => 1,
                'external_not_running' => 1,
                'disabled_external_running' => 1,
            ],
            'attention' => [
                [
                    'name' => 'prompt-compressor',
                    'status' => 'watch',
                    'enabled' => true,
                    'process_matchable' => true,
                    'process_running' => false,
                    'process_marker_count' => 1,
                    'missing_entries' => 0,
                ],
            ],
        ];

        $service = Mockery::mock(McpHealthReportService::class);
        $service->shouldReceive('collect')->once()->andReturn($payload);
        $service->shouldReceive('compactPayload')->once()->with($payload)->andReturn($compact);
        $this->app->instance(McpHealthReportService::class, $service);

        $exit = Artisan::call('ops:mcp-health', ['--compact' => true]);

        $this->assertSame(0, $exit);
        $output = (string) Artisan::output();
        $this->assertStringContainsString('MCP health compact: WARNING', $output);
        $this->assertStringContainsString('missing_entries=1  enabled_missing_entries=0  disabled_missing_entries=1', $output);
        $this->assertStringContainsString('external_not_running=1  disabled_external_running=1', $output);
        $this->assertStringContainsString('attention=prompt-compressor status=watch', $output);
        $this->assertStringContainsString('process_matchable=true process_running=false process_marker_count=1', $output);
        $this->assertStringNotContainsString('secret', $output);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'generated_at' => '2026-05-04T18:00:00Z',
            'status' => 'healthy',
            'process_check' => ['available' => true, 'source' => 'provided'],
            'summary' => [
                'total' => 1,
                'enabled' => 1,
                'external' => 0,
                'watch' => 0,
                'warning' => 0,
                'critical' => 0,
                'missing_entries' => 0,
                'enabled_missing_entries' => 0,
                'disabled_missing_entries' => 0,
                'external_not_running' => 0,
                'disabled_external_running' => 0,
            ],
            'servers' => [
                [
                    'name' => 'plos',
                    'enabled' => true,
                    'transport' => 'internal_service',
                    'status' => 'ok',
                    'process' => ['expected' => false, 'running' => false],
                    'missing_entries' => 0,
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $processLines
     */
    private function useFixturePs(array $processLines): void
    {
        if ($this->originalPath === null) {
            $path = getenv('PATH');
            $this->originalPath = $path === false ? '' : $path;
        }

        $bin = storage_path('app/testing-mcp-health/bin');
        File::ensureDirectoryExists($bin);
        File::put($bin.'/ps', "#!/usr/bin/env sh\ncat <<'MCP_HEALTH_PS'\n".implode("\n", $processLines)."\nMCP_HEALTH_PS\n");
        chmod($bin.'/ps', 0755);

        $path = $bin.PATH_SEPARATOR.$this->originalPath;
        putenv('PATH='.$path);
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;
    }
}
