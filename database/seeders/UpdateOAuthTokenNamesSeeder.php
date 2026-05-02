<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UpdateOAuthTokenNamesSeeder extends Seeder
{
    /**
     * Update existing OAuth access tokens with clear, descriptive names
     */
    public function run(): void
    {
        // Get all tokens with generic names that need updating
        $tokens = DB::table('oauth_access_tokens')
            ->where(function ($query) {
                $query->whereNull('name')
                    ->orWhere('name', '')
                    ->orWhere('name', 'like', 'Token%')
                    ->orWhere('name', 'like', 'Personal Access Token%')
                    ->orWhere('name', '=', 'web-ui-token'); // All have same name
            })
            ->orderBy('created_at', 'asc')
            ->get();

        if ($tokens->isEmpty()) {
            $this->command->info('✅ All tokens already have descriptive names!');
            return;
        }

        $this->command->info("Found {$tokens->count()} token(s) with generic/missing names");
        $this->command->newLine();

        // Update each token with a descriptive name based on creation order
        foreach ($tokens as $index => $token) {
            $newName = $this->generateDescriptiveName($token, $index);

            DB::table('oauth_access_tokens')
                ->where('id', $token->id)
                ->update(['name' => $newName]);

            $this->command->line("✓ Updated token #{$index + 1}");
            $this->command->line("  Old: " . ($token->name ?: '[No Name]'));
            $this->command->line("  New: {$newName}");
            $this->command->line("  Created: {$token->created_at}");
            $this->command->newLine();
        }

        $this->command->info('✅ All OAuth token names updated successfully!');
    }

    /**
     * Generate a descriptive name for a token based on its attributes
     */
    private function generateDescriptiveName($token, int $index): string
    {
        $createdDate = date('M d, Y', strtotime($token->created_at));
        $createdTime = date('g:i A', strtotime($token->created_at));

        // If it was originally "web-ui-token", make it clear these are web UI sessions
        if ($token->name === 'web-ui-token') {
            $descriptiveNames = [
                "Web UI Session #1 - {$createdDate} at {$createdTime}",
                "Web UI Session #2 - {$createdDate} at {$createdTime}",
                "Web UI Session #3 - {$createdDate} at {$createdTime}",
                "Web UI Session #4 - {$createdDate} at {$createdTime}",
                "Web UI Session #5 - {$createdDate} at {$createdTime}",
                "Web UI Session #6 - {$createdDate} at {$createdTime}",
            ];
        } else {
            // For other generic tokens, assume API usage
            $descriptiveNames = [
                "Initial Setup Token - {$createdDate}",
                "Development/Testing Token - {$createdDate}",
                "API Integration Token - {$createdDate}",
                "Mobile App Access Token - {$createdDate}",
                "Third-Party Service Token - {$createdDate}",
                "Automation Script Token - {$createdDate}",
            ];
        }

        // Return appropriate name based on index, or generic with date
        if ($index < count($descriptiveNames)) {
            return $descriptiveNames[$index];
        }

        return "Access Token #{$index + 1} - {$createdDate} at {$createdTime}";
    }
}
