<?php

namespace App\Console\Commands;

use App\Services\BrowserAutomationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Browser Server Management Command
 *
 * Manages the persistent Puppeteer and Playwright browser servers
 * used for data broker scraping.
 *
 * E06: Personal Data Removal System
 */
class BrowserServerCommand extends Command
{
    protected $signature = 'browser:server
                            {action=status : Action: status, start, stop, restart}
                            {--engine= : Specific engine (puppeteer, playwright, or all)}
                            {--json : Emit machine-readable status output}
                            {--compact : Emit one-line status output}
                            {--fail-on-unsafe : Fail when a reachable sidecar lacks the required security contract}';

    protected $description = 'Manage persistent browser automation servers';

    private BrowserAutomationService $browserService;

    public function __construct(BrowserAutomationService $browserService)
    {
        parent::__construct();
        $this->browserService = $browserService;
    }

    public function handle(): int
    {
        $action = $this->argument('action');
        $engine = $this->option('engine') ?? 'all';

        return match ($action) {
            'status' => $this->showStatus(),
            'start' => $this->startServers($engine),
            'stop' => $this->stopServers($engine),
            'restart' => $this->restartServers($engine),
            default => $this->invalidAction($action),
        };
    }

    private function showStatus(): int
    {
        $status = $this->browserService->getStatus();
        $audit = $this->securityAuditPayload($status);

        if ($this->option('json')) {
            $flags = JSON_UNESCAPED_SLASHES;
            if (! $this->option('compact')) {
                $flags |= JSON_PRETTY_PRINT;
            }
            $this->line(json_encode($audit, $flags | JSON_THROW_ON_ERROR));

            return $this->statusExitCode($audit);
        }

        if ($this->option('compact')) {
            $this->line(sprintf(
                'Browser security: %s secure=%d unsafe=%d stopped=%d required=navigation_scope,no_credential_api,content_windows',
                $audit['status'],
                $audit['summary']['secure'],
                $audit['summary']['unsafe'],
                $audit['summary']['stopped'],
            ));

            return $this->statusExitCode($audit);
        }

        $this->info('Browser Server Status');
        $this->line('');

        $tableData = [];

        foreach (['puppeteer', 'playwright'] as $engine) {
            $engineStatus = $status[$engine] ?? [];
            $available = $engineStatus['available'] ?? false;
            $reachable = $engineStatus['reachable'] ?? $available;
            $port = $engineStatus['port'] ?? 'N/A';
            $health = $engineStatus['health'] ?? [];

            $tableData[] = [
                ucfirst($engine),
                $available
                    ? '<info>Running</info>'
                    : ($reachable ? '<error>Rejected</error>' : '<comment>Stopped</comment>'),
                $port,
                $health['browser'] ?? 'N/A',
                $health['page'] ?? 'N/A',
                isset($health['uptime']) ? round($health['uptime']).'s' : 'N/A',
            ];
        }

        $this->table(
            ['Engine', 'Status', 'Port', 'Browser', 'Page', 'Uptime'],
            $tableData
        );

        if ($status['active_engine']) {
            $this->line('');
            $this->info("Active engine: {$status['active_engine']}");
        }

        $securityRows = [];
        foreach (['puppeteer', 'playwright'] as $engine) {
            $engineStatus = (array) ($status[$engine] ?? []);
            if (! ($engineStatus['available'] ?? false) && ! ($engineStatus['reachable'] ?? false)) {
                continue;
            }

            $security = (array) ($engineStatus['health']['security'] ?? []);
            $controlPlane = ($security['authRequired'] ?? false)
                && ($security['hostProtection'] ?? false)
                && ($security['originProtection'] ?? false);
            $isolation = $engine === 'playwright'
                ? (($security['freshContextPerNavigation'] ?? false) ? 'fresh context' : 'shared context')
                : 'bounded sessions';
            $downloadsBlocked = $engine === 'playwright'
                ? ! ($security['downloadsAccepted'] ?? true)
                : (bool) ($security['downloadBlocking'] ?? false);
            $navigationScoped = ($security['topLevelNavigationAllowlist'] ?? false)
                && ($security['documentRequestsScoped'] ?? false)
                && ($security['popupsBlocked'] ?? false);
            $ipTargetsBlocked = (bool) ($security['ipLiteralAndLocalhostTargetsBlocked'] ?? false);
            $dnsTargetsBlocked = ($security['topLevelDnsPublicAddressesRequired'] ?? false)
                && ($security['dnsResolutionFailClosed'] ?? false);
            $credentialApiDisabled = ($security['credentialApiEnabled'] ?? true) === false;
            $contentWindowed = (bool) ($security['contentWindowing'] ?? false);

            $securityRows[] = [
                ucfirst($engine),
                $controlPlane ? '<info>Protected</info>' : '<error>Incomplete</error>',
                ($security['sandboxEnabled'] ?? false) ? '<info>Enabled</info>' : '<comment>Root fallback</comment>',
                (string) ($security['domainAllowlist'] ?? 0),
                $isolation,
                $downloadsBlocked ? '<info>Blocked</info>' : '<error>Allowed</error>',
                $engine === 'playwright'
                    ? (($security['arbitraryEvaluateEnabled'] ?? true) ? '<error>Enabled</error>' : '<info>Disabled</info>')
                    : 'Safe mode',
                $navigationScoped ? '<info>Scoped</info>' : '<error>Open</error>',
                $ipTargetsBlocked ? '<info>Blocked</info>' : '<error>Allowed</error>',
                $dnsTargetsBlocked ? '<info>Public only</info>' : '<error>Unchecked</error>',
                $credentialApiDisabled ? '<info>No API</info>' : '<error>Exposed</error>',
                $contentWindowed ? '<info>Windowed</info>' : '<error>Unbounded</error>',
            ];
        }

        if ($securityRows !== []) {
            $this->line('');
            $this->info('Browser Control-Plane Security');
            $this->table(
                [
                    'Engine',
                    'Auth/Host/Origin',
                    'Sandbox',
                    'Allowlist',
                    'Isolation',
                    'Downloads',
                    'Evaluate',
                    'Navigation',
                    'IP Targets',
                    'DNS Targets',
                    'Credentials',
                    'Content',
                ],
                $securityRows,
            );
        }

        return $this->statusExitCode($audit);
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function securityAuditPayload(array $status): array
    {
        $engines = [];
        $summary = ['secure' => 0, 'unsafe' => 0, 'stopped' => 0];

        foreach (['puppeteer', 'playwright'] as $engine) {
            $engineStatus = (array) ($status[$engine] ?? []);
            $security = (array) ($engineStatus['health']['security'] ?? []);
            $available = (bool) ($engineStatus['available'] ?? false);
            $reachable = (bool) ($engineStatus['reachable'] ?? $available);
            $state = $available ? 'secure' : ($reachable ? 'unsafe' : 'stopped');
            $summary[$state]++;

            $engines[$engine] = [
                'state' => $state,
                'reachable' => $reachable,
                'security_contract_complete' => $available,
                'checks' => [
                    'authenticated_loopback_control_plane' => ($security['authRequired'] ?? false)
                        && ($security['hostProtection'] ?? false)
                        && ($security['originProtection'] ?? false),
                    'top_level_navigation_scoped' => ($security['topLevelNavigationAllowlist'] ?? false)
                        && ($security['documentRequestsScoped'] ?? false)
                        && ($security['popupsBlocked'] ?? false),
                    'ip_literal_and_localhost_targets_blocked' => (bool) ($security['ipLiteralAndLocalhostTargetsBlocked'] ?? false),
                    'top_level_dns_public_addresses_required' => ($security['topLevelDnsPublicAddressesRequired'] ?? false)
                        && ($security['dnsResolutionFailClosed'] ?? false),
                    'credential_api_disabled' => ($security['credentialApiEnabled'] ?? true) === false,
                    'content_windowed' => (bool) ($security['contentWindowing'] ?? false),
                    'downloads_blocked' => $engine === 'playwright'
                        ? ($security['downloadsAccepted'] ?? true) === false
                        : (bool) ($security['downloadBlocking'] ?? false),
                    'fresh_session' => $engine === 'playwright'
                        ? (bool) ($security['freshContextPerNavigation'] ?? false)
                        : (bool) ($security['freshSessionPerNavigation'] ?? false),
                    'arbitrary_evaluate_disabled' => $engine === 'playwright'
                        ? ($security['arbitraryEvaluateEnabled'] ?? true) === false
                        : true,
                ],
            ];
        }

        return [
            'schema' => 'plos_browser_security_audit.v1',
            'mode' => 'observe',
            'read_only' => true,
            'status' => $summary['unsafe'] > 0
                ? 'unsafe'
                : ($summary['secure'] > 0 ? 'secure' : 'stopped'),
            'summary' => $summary,
            'engines' => $engines,
        ];
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    private function statusExitCode(array $audit): int
    {
        return $this->option('fail-on-unsafe') && ($audit['status'] ?? null) === 'unsafe'
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    private function startServers(string $engine): int
    {
        $this->info('Starting browser server(s)...');

        $started = 0;

        if ($engine === 'all' || $engine === 'puppeteer') {
            if ($this->browserService->startPuppeteer()) {
                $this->info('Puppeteer server started on port 9222');
                $started++;
            } else {
                $this->error('Failed to start Puppeteer server');
            }
        }

        if ($engine === 'all' || $engine === 'playwright') {
            if ($this->browserService->startPlaywright()) {
                $this->info('Playwright server started on port 9223');
                $started++;
            } else {
                $this->error('Failed to start Playwright server');
            }
        }

        return $started > 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function stopServers(string $engine): int
    {
        $this->info('Stopping browser server(s)...');

        if ($engine === 'all') {
            $this->browserService->shutdownAll();
            $this->info('All browser servers stopped');
        } else {
            // Kill specific server by port
            $port = $engine === 'puppeteer' ? 9222 : 9223;
            Process::timeout(10)->run(['fuser', '-k', "{$port}/tcp"]);
            $this->info(ucfirst($engine).' server stopped');
        }

        return Command::SUCCESS;
    }

    private function restartServers(string $engine): int
    {
        $this->stopServers($engine);
        sleep(2);

        return $this->startServers($engine);
    }

    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->line('');
        $this->line('Available actions:');
        $this->line('  status  - Show server status');
        $this->line('  start   - Start server(s)');
        $this->line('  stop    - Stop server(s)');
        $this->line('  restart - Restart server(s)');

        return Command::FAILURE;
    }
}
