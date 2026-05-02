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
                            {--engine= : Specific engine (puppeteer, playwright, or all)}';

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

        $this->info('Browser Server Status');
        $this->line('');

        $tableData = [];

        foreach (['puppeteer', 'playwright'] as $engine) {
            $engineStatus = $status[$engine] ?? [];
            $available = $engineStatus['available'] ?? false;
            $port = $engineStatus['port'] ?? 'N/A';
            $health = $engineStatus['health'] ?? [];

            $tableData[] = [
                ucfirst($engine),
                $available ? '<info>Running</info>' : '<comment>Stopped</comment>',
                $port,
                $health['browser'] ?? 'N/A',
                $health['page'] ?? 'N/A',
                isset($health['uptime']) ? round($health['uptime']) . 's' : 'N/A',
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

        // Show security status for Puppeteer
        $puppeteerStatus = $status['puppeteer'] ?? [];
        if ($puppeteerStatus['available'] ?? false) {
            $health = $puppeteerStatus['health'] ?? [];
            $security = $health['security'] ?? [];

            if (!empty($security)) {
                $this->line('');
                $this->info('🔒 Puppeteer Security Status:');
                $this->line('   Sandbox: ' . ($security['sandboxEnabled'] ? '<info>Enabled</info>' : '<comment>Disabled (running as root)</comment>'));
                $this->line('   Download Blocking: ' . ($security['downloadBlocking'] ? '<info>Enabled</info>' : '<comment>Disabled</comment>'));
                $this->line('   Domain Allowlist: ' . ($security['domainAllowlist'] ?? 0) . ' domains');
                $this->line('   Request Interception: ' . ($security['requestInterception'] ? '<info>Enabled</info>' : '<comment>Disabled</comment>'));
                $this->line('   Session Count: ' . ($security['sessionCount'] ?? 0) . '/' . ($security['maxSessionsBeforeRestart'] ?? 10));
            }
        }

        return Command::SUCCESS;
    }

    private function startServers(string $engine): int
    {
        $this->info("Starting browser server(s)...");

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
        $this->info("Stopping browser server(s)...");

        if ($engine === 'all') {
            $this->browserService->shutdownAll();
            $this->info('All browser servers stopped');
        } else {
            // Kill specific server by port
            $port = $engine === 'puppeteer' ? 9222 : 9223;
            Process::timeout(10)->run(['fuser', '-k', "{$port}/tcp"]);
            $this->info(ucfirst($engine) . ' server stopped');
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
