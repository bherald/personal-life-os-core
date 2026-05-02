<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Engine\MCPRouter;
use Exception;

class TestNextcloudContacts extends Command
{
    protected $signature = 'nextcloud:test-contacts';
    protected $description = 'Test Nextcloud Contacts MCP integration';

    public function handle(): int
    {
        $router = app(MCPRouter::class);

        $this->info('Testing Nextcloud Contacts MCP Integration...');
        $this->newLine();

        // Test 1: Get address books
        $this->info('Test 1: Getting address books...');
        try {
            $result = $router->callTool('nextcloud-contacts', 'get_address_books', []);
            $this->info('✓ Get address books successful!');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            // Store address book name for next tests
            $addressBooks = $result['addressBooks'] ?? [];
            $addressBookName = !empty($addressBooks) ? $addressBooks[0]['name'] : null;
        } catch (Exception $e) {
            $this->error('✗ Get address books failed:');
            $this->error($e->getMessage());
            return 1;
        }

        $this->newLine();

        // Test 2: Get contacts
        $this->info('Test 2: Getting contacts...');
        try {
            $params = $addressBookName ? ['address_book' => $addressBookName, 'limit' => 5] : ['limit' => 5];
            $result = $router->callTool('nextcloud-contacts', 'get_contacts', $params);
            $this->info('✓ Get contacts successful!');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            $this->error('✗ Get contacts failed:');
            $this->error($e->getMessage());
            return 1;
        }

        $this->newLine();

        // Test 3: Search contacts
        $this->info('Test 3: Searching contacts (query: "test")...');
        try {
            $params = ['query' => 'test'];
            if ($addressBookName) {
                $params['address_book'] = $addressBookName;
            }
            $result = $router->callTool('nextcloud-contacts', 'search_contacts', $params);
            $this->info('✓ Search contacts successful!');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            $this->error('✗ Search contacts failed:');
            $this->error($e->getMessage());
            return 1;
        }

        $this->newLine();

        // Test 4: Get contact stats
        $this->info('Test 4: Getting contact statistics...');
        try {
            $result = $router->callTool('nextcloud-contacts', 'get_contact_stats', []);
            $this->info('✓ Get contact stats successful!');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            $this->error('✗ Get contact stats failed:');
            $this->error($e->getMessage());
            return 1;
        }

        $this->newLine();
        $this->info('All tests passed!');
        return 0;
    }
}
