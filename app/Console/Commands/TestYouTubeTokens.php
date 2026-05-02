<?php

namespace App\Console\Commands;

use App\Services\YouTubeApiService;
use Illuminate\Console\Command;

class TestYouTubeTokens extends Command
{
    protected $signature = 'youtube:test-tokens';
    protected $description = 'Test YouTube OAuth token storage and retrieval';

    public function handle(YouTubeApiService $youtubeService): int
    {
        $this->info('YouTube OAuth Token Test');
        $this->info('=======================');
        $this->newLine();

        // Get token status
        $status = $youtubeService->getTokenStatus();

        if (!$status) {
            $this->error('❌ No YouTube OAuth tokens found in database');
            $this->newLine();
            $this->comment('Please authenticate first using the OAuth flow.');
            return self::FAILURE;
        }

        // Display token status
        $this->info('✅ Token Status:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Provider', $status['provider']],
                ['Has Refresh Token', $status['has_refresh_token'] ? '✅ Yes' : '❌ No'],
                ['Has Access Token', $status['has_access_token'] ? '✅ Yes' : '❌ No'],
                ['Access Token Expires At', $status['access_token_expires_at'] ?? 'N/A'],
                ['Is Expired', $status['is_expired'] === null ? 'N/A' : ($status['is_expired'] ? '⚠️ Yes' : '✅ No')],
                ['Created At', $status['created_at']],
                ['Updated At', $status['updated_at']],
            ]
        );

        $this->newLine();

        // Test API call (if not expired or can refresh)
        if ($status['has_refresh_token']) {
            $this->info('Testing API access...');

            try {
                // This will automatically refresh the token if needed
                $subscriptions = $youtubeService->getSubscriptions(1, null, false);

                $totalSubs = $subscriptions['pageInfo']['totalResults'] ?? 0;
                $this->info("✅ API access working! Total subscriptions: {$totalSubs}");
            } catch (\Exception $e) {
                $this->error('❌ API access failed: ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('✅ All tests passed!');

        return self::SUCCESS;
    }
}
