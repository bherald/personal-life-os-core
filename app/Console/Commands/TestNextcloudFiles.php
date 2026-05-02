<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Engine\MCPRouter;
use Exception;

class TestNextcloudFiles extends Command
{
    protected $signature = 'nextcloud:test-files';
    protected $description = 'Test Nextcloud Files MCP integration';

    public function handle(): int
    {
        $router = app(MCPRouter::class);

        $this->info('Testing Nextcloud Files MCP Integration...');
        $this->newLine();

        // Test 1: Test connection
        $this->info('Test 1: Testing connection...');
        try {
            $result = $router->callTool('nextcloud-files', 'test-connection', []);
            $this->info('✓ Connection successful!');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            $this->error('✗ Connection failed:');
            $this->error($e->getMessage());
            return 1;
        }

        $this->newLine();

        // Test 2: List files
        $this->info('Test 2: Listing files in root directory...');
        try {
            $result = $router->callTool('nextcloud-files', 'list-files', ['path' => '/']);
            $this->info('✓ List files successful!');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            $this->error('✗ List files failed:');
            $this->error($e->getMessage());
            return 1;
        }

        $this->newLine();
        $this->info('All tests passed!');
        return 0;
    }
}
