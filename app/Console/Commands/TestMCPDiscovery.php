<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Engine\MCPRouter;
use Exception;

class TestMCPDiscovery extends Command
{
    protected $signature = 'mcp:test-discovery';
    protected $description = 'Test MCP tool discovery';

    public function handle(): int
    {
        $router = app(MCPRouter::class);

        // First show configured servers
        $this->info('Configured MCP servers:');
        $servers = config('mcp.servers');
        foreach ($servers as $name => $server) {
            $enabled = $server['enabled'] ?? false;
            $type = $server['type'] ?? 'external';
            $this->line(sprintf(
                '  %s: %s (%s) - %d tools',
                $name,
                $enabled ? 'enabled' : 'disabled',
                $type,
                $server['tools'] ?? 0
            ));
        }
        $this->newLine();

        try {
            $this->info('Discovering MCP tools...');
            $allTools = $router->getAvailableTools();

            $byServer = [];
            foreach ($allTools as $tool) {
                $server = $tool['server'] ?? 'unknown';
                if (!isset($byServer[$server])) {
                    $byServer[$server] = [];
                }
                $byServer[$server][] = $tool['name'];
            }

            $this->info('Total tools: ' . count($allTools));
            $this->newLine();

            foreach ($byServer as $server => $tools) {
                $this->line($server . ': ' . count($tools) . ' tools');
                foreach ($tools as $tool) {
                    $this->line('  - ' . $tool);
                }
            }

            return 0;
        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
