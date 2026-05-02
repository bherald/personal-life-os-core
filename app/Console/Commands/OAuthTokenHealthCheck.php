<?php

namespace App\Console\Commands;

use App\Controllers\NotificationController;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OAuth Token Health Check Command
 *
 * Monitors OAuth tokens for expiry and sends alerts before they expire.
 * Specifically checks YouTube tokens which expire after 7 days in testing mode.
 *
 * Usage: php artisan oauth:health-check [--alert]
 */
class OAuthTokenHealthCheck extends Command
{
    protected $signature = 'oauth:health-check
                            {--alert : Send Pushover alerts for issues}
                            {--warn-days=5 : Days before expiry to warn}';

    protected $description = 'Check OAuth token health and alert before expiry';

    // Token created date is what matters for Google "testing" mode (7-day expiry)
    private const GOOGLE_TESTING_MODE_DAYS = 7;

    private function getYouTubeAuthUrl(): string
    {
        return rtrim(config('app.url', 'http://localhost'), '/').'/api/youtube/auth';
    }

    public function handle()
    {
        $this->info('Running OAuth token health check...');
        $issues = [];
        $warnDays = (int) $this->option('warn-days');

        // Check YouTube token
        $youtubeResult = $this->checkYouTubeToken($warnDays);
        if ($youtubeResult) {
            $issues[] = $youtubeResult;
        }

        // Future: Check other OAuth providers here
        // $googleResult = $this->checkGoogleToken($warnDays);
        // $microsoftResult = $this->checkMicrosoftToken($warnDays);

        // Report results
        if (empty($issues)) {
            $this->info('✓ All OAuth tokens are healthy');

            return 0;
        }

        // Display issues
        foreach ($issues as $issue) {
            if ($issue['severity'] === 'error') {
                $this->error($issue['message']);
            } else {
                $this->warn($issue['message']);
            }
        }

        // Send alert if requested
        if ($this->option('alert')) {
            $this->sendAlert($issues);
        }

        return count($issues);
    }

    /**
     * Check YouTube OAuth token health
     */
    private function checkYouTubeToken(int $warnDays): ?array
    {
        $token = DB::selectOne('SELECT * FROM oauth_tokens WHERE provider = ?', ['youtube']);

        if (! $token) {
            return [
                'provider' => 'youtube',
                'severity' => 'error',
                'status' => 'missing',
                'message' => '❌ YouTube: No OAuth token found. Re-authenticate at '.$this->getYouTubeAuthUrl(),
            ];
        }

        // Check if refresh token exists
        if (empty($token->refresh_token)) {
            return [
                'provider' => 'youtube',
                'severity' => 'error',
                'status' => 'no_refresh_token',
                'message' => '❌ YouTube: No refresh token. Re-authenticate at '.$this->getYouTubeAuthUrl(),
            ];
        }

        // Calculate token age (Google testing mode = 7 day expiry from creation)
        $createdAt = Carbon::parse($token->created_at);
        $tokenAgeDays = (int) $createdAt->diffInDays(now());
        $daysUntilExpiry = self::GOOGLE_TESTING_MODE_DAYS - $tokenAgeDays;

        // Token already expired (past 7 days in testing mode)
        if ($daysUntilExpiry <= 0) {
            // Verify by actually trying to refresh
            $refreshResult = $this->testTokenRefresh($token->refresh_token);

            if (! $refreshResult['success']) {
                return [
                    'provider' => 'youtube',
                    'severity' => 'error',
                    'status' => 'expired',
                    'message' => "❌ YouTube: Token EXPIRED/REVOKED ({$tokenAgeDays} days old)\n".
                                 "   Error: {$refreshResult['error']}\n".
                                 "   Fix: Visit {$this->getYouTubeAuthUrl()} to re-authenticate",
                    'days_old' => $tokenAgeDays,
                    'error' => $refreshResult['error'],
                ];
            }

            // Token still works! App might be in production mode
            $this->info("✓ YouTube: Token is {$tokenAgeDays} days old but still valid (app likely in production mode)");

            return null;
        }

        // Token expiring soon (within warning threshold)
        if ($daysUntilExpiry <= $warnDays) {
            return [
                'provider' => 'youtube',
                'severity' => 'warning',
                'status' => 'expiring_soon',
                'message' => "⚠️ YouTube: Token expires in ~{$daysUntilExpiry} days (created {$createdAt->format('Y-m-d')})\n".
                             "   Action: Re-authenticate before expiry OR publish app in Google Cloud Console\n".
                             "   Re-auth URL: {$this->getYouTubeAuthUrl()}",
                'days_until_expiry' => $daysUntilExpiry,
                'created_at' => $createdAt->toDateTimeString(),
            ];
        }

        // Token is healthy
        $this->info("✓ YouTube: Token healthy ({$daysUntilExpiry} days until potential expiry)");

        return null;
    }

    /**
     * Test if a refresh token actually works
     */
    private function testTokenRefresh(string $refreshToken): array
    {
        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(30)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('youtube.client_id'),
                'client_secret' => config('youtube.client_secret'),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Update the access token in database
                DB::update(
                    'UPDATE oauth_tokens SET access_token = ?, access_token_expires_at = ?, updated_at = ? WHERE provider = ?',
                    [$data['access_token'], now()->addSeconds($data['expires_in'] ?? 3600), now(), 'youtube']
                );

                return ['success' => true];
            }

            $error = $response->json();

            return [
                'success' => false,
                'error' => $error['error_description'] ?? $error['error'] ?? 'Unknown error',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send Pushover alert for token issues
     */
    private function sendAlert(array $issues): void
    {
        $errorCount = count(array_filter($issues, fn ($i) => $i['severity'] === 'error'));
        $warningCount = count(array_filter($issues, fn ($i) => $i['severity'] === 'warning'));

        $title = $errorCount > 0
            ? '🔴 OAuth Token Alert'
            : '⚠️ OAuth Token Warning';

        $messages = array_map(fn ($i) => $i['message'], $issues);
        $body = implode("\n\n", $messages);

        try {
            $controller = new NotificationController;
            $controller->send('pushover', [
                'title' => $title,
                'message' => $body,
                'priority' => $errorCount > 0 ? 1 : 0,
                'sound' => $errorCount > 0 ? 'siren' : 'intermission',
                'source_group' => 'auth_token_alerts',
            ]);

            $this->info('✓ Alert sent via Pushover');
            Log::info('OAuth health check alert sent', [
                'errors' => $errorCount,
                'warnings' => $warningCount,
            ]);

        } catch (\Exception $e) {
            $this->error('Failed to send Pushover alert: '.$e->getMessage());
            Log::error('OAuth health check alert failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
