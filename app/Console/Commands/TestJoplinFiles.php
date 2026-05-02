<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Engine\MCPRouter;
use Exception;

class TestJoplinFiles extends Command
{
    protected $signature = 'joplin:test-files';
    protected $description = 'Test Joplin Files MCP integration';

    public function handle(): int
    {
        $router = app(MCPRouter::class);

        $this->info('Testing Joplin Files MCP Integration...');
        $this->newLine();

        // Test 1: Get status
        $this->info('Test 1: Getting Joplin status...');
        try {
            $result = $router->callTool('joplin-files', 'joplin_status', []);
            $this->info('✓ Get status successful!');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            $this->error('✗ Get status failed:');
            $this->error($e->getMessage());
            return 1;
        }

        $this->newLine();

        // Test 2: List notebooks
        $this->info('Test 2: Listing notebooks...');
        try {
            $result = $router->callTool('joplin-files', 'joplin_list_notebooks', []);
            $this->info('✓ List notebooks successful!');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            // Store first notebook for next test
            $notebooks = $result['notebooks'] ?? [];
            $notebookId = !empty($notebooks) ? $notebooks[0]['id'] : null;
        } catch (Exception $e) {
            $this->error('✗ List notebooks failed:');
            $this->error($e->getMessage());
            return 1;
        }

        $this->newLine();

        // Test 3: Search notes
        $this->info('Test 3: Searching notes (query: "test")...');
        try {
            $result = $router->callTool('joplin-files', 'joplin_search', [
                'query' => 'test',
                'limit' => 3,
            ]);
            $this->info('✓ Search notes successful!');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            $this->error('✗ Search notes failed:');
            $this->error($e->getMessage());
            return 1;
        }

        $this->newLine();

        // Test 4: Get notes in notebook (if we have a notebook)
        if (isset($notebookId)) {
            $this->info("Test 4: Getting notes in notebook {$notebookId}...");
            try {
                $result = $router->callTool('joplin-files', 'joplin_get_notebook', [
                    'notebook_id' => $notebookId,
                ]);
                $this->info('✓ Get notebook notes successful!');
                $this->line(json_encode($result, JSON_PRETTY_PRINT));
            } catch (Exception $e) {
                $this->error('✗ Get notebook notes failed:');
                $this->error($e->getMessage());
                return 1;
            }
        } else {
            $this->warn('Skipping Test 4: No notebooks found');
        }

        $this->newLine();
        $this->info('All tests passed!');
        return 0;
    }
}
