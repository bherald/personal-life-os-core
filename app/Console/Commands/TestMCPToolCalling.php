<?php

namespace App\Console\Commands;

use App\Engine\OllamaToolCaller;
use App\Engine\MCPRouter;
use Illuminate\Console\Command;

class TestMCPToolCalling extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mcp:test
                            {request? : Natural language request to test}
                            {--list : List available tools}
                            {--status : Show MCP status}
                            {--servers : Show server status}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test MCP tool calling with Ollama';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listTools();
        }

        if ($this->option('status')) {
            return $this->showStatus();
        }

        if ($this->option('servers')) {
            return $this->showServers();
        }

        $request = $this->argument('request');

        if (!$request) {
            $this->showUsage();
            return 0;
        }

        return $this->testToolCall($request);
    }

    /**
     * Test a tool call with natural language request
     */
    private function testToolCall(string $request): int
    {
        $this->info("Testing MCP tool calling with Ollama...\n");
        $this->line("Request: {$request}\n");

        $toolCaller = new OllamaToolCaller();

        if (!$toolCaller->isAvailable()) {
            $this->error('✗ MCP tool calling not available');
            $this->line('  - Check Ollama connectivity');
            $this->line('  - Ensure MCP servers are enabled');
            return 1;
        }

        $this->line("Processing...\n");

        try {
            $start = microtime(true);
            $response = $toolCaller->process($request);
            $duration = round((microtime(true) - $start) * 1000);

            $this->info("✓ Success ({$duration}ms)\n");
            $this->line("Response:");
            $this->line(str_repeat('-', 60));
            $this->line($response);
            $this->line(str_repeat('-', 60));

            return 0;
        } catch (\Exception $e) {
            $this->error("✗ Tool calling failed");
            $this->line("Error: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * List available tools
     */
    private function listTools(): int
    {
        $this->info("Available MCP Tools:\n");

        $mcpRouter = new MCPRouter();
        $tools = $mcpRouter->getAvailableTools();

        if (empty($tools)) {
            $this->warn('No tools available');
            $this->line('Enable MCP servers in config/mcp.php');
            return 0;
        }

        // Group by server
        $grouped = [];
        foreach ($tools as $tool) {
            $server = $tool['server'] ?? 'unknown';
            if (!isset($grouped[$server])) {
                $grouped[$server] = [];
            }
            $grouped[$server][] = $tool;
        }

        foreach ($grouped as $server => $serverTools) {
            $count = count($serverTools);
            $this->line("Server: <fg=cyan>{$server}</> ({$count} tools)");

            foreach ($serverTools as $tool) {
                $name = $tool['name'] ?? 'unknown';
                $description = $tool['description'] ?? 'No description';
                $this->line("  • {$name}");
                $this->line("    {$description}");
            }

            $this->newLine();
        }

        $this->info("Total: " . count($tools) . " tools");

        return 0;
    }

    /**
     * Show MCP status
     */
    private function showStatus(): int
    {
        $this->info("MCP Tool Calling Status:\n");

        $toolCaller = new OllamaToolCaller();
        $status = $toolCaller->getStatus();

        $available = $status['available'] ? '<fg=green>✓ Available</>' : '<fg=red>✗ Not Available</>';
        $this->line("Status: {$available}");

        $ollamaStatus = $status['ollama_available'] ? '<fg=green>✓ Online</>' : '<fg=red>✗ Offline</>';
        $this->line("Ollama: {$ollamaStatus}");

        $this->line("Total Tools: {$status['total_tools']}");

        $this->newLine();
        $this->line("Tools by Server:");

        foreach ($status['tools_by_server'] as $server => $tools) {
            $this->line("  • {$server}: " . count($tools) . " tools");
        }

        return 0;
    }

    /**
     * Show server status
     */
    private function showServers(): int
    {
        $this->info("MCP Servers Status:\n");

        $mcpRouter = new MCPRouter();
        $servers = $mcpRouter->getAllServersStatus();

        foreach ($servers as $name => $status) {
            $available = $status['available'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $type = $status['type'] ?? 'unknown';
            $tools = $status['tools'] ?? 0;

            $this->line("{$available} <fg=cyan>{$name}</> ({$type}) - {$tools} tools");

            if (!$status['available'] && isset($status['error'])) {
                $this->line("    Error: {$status['error']}");
            }
        }

        return 0;
    }

    /**
     * Show usage examples
     */
    private function showUsage(): void
    {
        $this->info("MCP Tool Calling Test\n");

        $this->line("Usage:");
        $this->line("  php artisan mcp:test \"your natural language request\"\n");

        $this->line("Options:");
        $this->line("  --list      List all available tools");
        $this->line("  --status    Show MCP tool calling status");
        $this->line("  --servers   Show MCP server status\n");

        $this->line("Examples:");
        $this->line("  php artisan mcp:test \"List all workflows\"");
        $this->line("  php artisan mcp:test \"Search for workflows about weather\"");
        $this->line("  php artisan mcp:test --list");
        $this->line("  php artisan mcp:test --status");
    }
}
