<?php

namespace App\Console\Commands;

use App\Services\ThunderbirdService;
use Illuminate\Console\Command;

class TestThunderbird extends Command
{
    protected $signature = 'thunderbird:test';
    protected $description = 'Test Thunderbird MCP connection';

    public function handle(ThunderbirdService $service)
    {
        $this->info('Testing Thunderbird MCP connection...');

        if (!$service->isAvailable()) {
            $this->error('❌ Thunderbird MCP not available');
            $this->line('');
            $this->line('Troubleshooting:');
            $this->line('1. Check Thunderbird is running');
            $this->line('2. Check extension installed: Ctrl+Shift+A in Thunderbird');
            $this->line('3. Check port 8765: ss -tlnp | grep 8765');
            $this->line('4. Check console: Ctrl+Shift+J in Thunderbird (look for "MCP server listening")');
            return 1;
        }

        $this->info('✅ Connected to Thunderbird MCP');

        try {
            $tools = $service->listTools();
            $this->line('');
            $this->info('Available tools:');
            foreach ($tools['tools'] ?? [] as $tool) {
                $this->line("  • {$tool['name']}: {$tool['description']}");
            }

            $this->line('');
            $this->info('✅ Thunderbird MCP is fully operational');
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }
    }
}
