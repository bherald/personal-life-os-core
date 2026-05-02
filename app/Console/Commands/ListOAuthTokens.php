<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ListOAuthTokens extends Command
{
    protected $signature = 'oauth:tokens';
    protected $description = 'List all OAuth access tokens';

    public function handle()
    {
        $sql = "SELECT id, name, created_at, revoked FROM oauth_access_tokens ORDER BY created_at DESC";
        $tokens = DB::select($sql);

        if (empty($tokens)) {
            $this->info('No OAuth tokens found in database.');
            return;
        }

        $this->info('OAuth Access Tokens:');
        $this->newLine();

        foreach ($tokens as $token) {
            $this->line('ID: ' . substr($token->id, 0, 20) . '...');
            $this->line('Name: ' . ($token->name ?? '[No Name]'));
            $this->line('Created: ' . $token->created_at);
            $this->line('Revoked: ' . ($token->revoked ? 'Yes' : 'No'));
            $this->line('---');
        }

        $this->newLine();
        $this->info('Total: ' . count($tokens) . ' tokens');
    }
}
