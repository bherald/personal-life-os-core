<?php

namespace App\Nodes;

use App\Controllers\NotificationController;
use App\Services\EmailService;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NotificationHubNode - Unified Multi-Channel Notification System
 *
 * Provides a single node for sending notifications across multiple channels
 * with fallback support, rate limiting, and delivery tracking.
 *
 * Supported Channels:
 * - pushover: Via existing PushoverNotify infrastructure
 * - email: Via EmailService (Thunderbird MCP)
 * - slack: Webhook-based
 * - discord: Webhook-based
 * - ntfy: Open source push notifications (https://ntfy.sh)
 * - webhook: Generic HTTP webhook
 *
 * Configuration:
 * - channel: Primary channel to use
 * - credentials_key: Key for looking up credentials from notification_credentials table
 * - message_template: Message with {{data.field}} placeholders
 * - title_template: Title with {{data.field}} placeholders
 * - priority: Notification priority (low, normal, high, urgent)
 * - fallback_channels: Array of channels to try if primary fails
 * - rate_limit_key: Optional key for rate limiting (defaults to channel)
 * - rate_limit_per_minute: Max notifications per minute (default: 10)
 */
class NotificationHubNode extends BaseNode
{
    // Channel rate limits (per minute)
    private const DEFAULT_RATE_LIMITS = [
        'pushover' => 7,    // Pushover has strict limits
        'email' => 5,       // Be conservative with email
        'slack' => 30,
        'discord' => 30,
        'ntfy' => 60,
        'webhook' => 60,
    ];

    // Cache TTL for rate limiting (60 seconds)
    private const RATE_LIMIT_TTL = 60;

    // Supported channels
    private const SUPPORTED_CHANNELS = ['pushover', 'email', 'slack', 'discord', 'ntfy', 'webhook'];

    public function execute(array $input): array
    {
        $startTime = microtime(true);
        $deliveryResults = [];
        $successfulChannel = null;
        $suppressedChannel = null;

        try {
            // Get configuration
            $primaryChannel = $this->getConfigValue('channel', 'pushover');
            $fallbackChannels = $this->getConfigValue('fallback_channels', []);
            $credentialsKey = $this->getConfigValue('credentials_key');

            // Build message from templates
            $title = $this->substituteVariables(
                $this->getConfigValue('title_template', $this->getConfigValue('title', 'Notification')),
                $input
            );
            $message = $this->substituteVariables(
                $this->getConfigValue('message_template', $this->getConfigValue('message', '')),
                $input
            );

            // If no message from templates, extract from input
            if (empty($message)) {
                $message = $this->extractMessage($input);
            }

            if (empty($message)) {
                throw new Exception('No message content found in templates or input');
            }

            $priority = $this->getConfigValue('priority', 'normal');

            // Build channel list: primary + fallbacks
            $channelsToTry = array_merge([$primaryChannel], $fallbackChannels);
            $channelsToTry = array_unique($channelsToTry);

            Log::info('NotificationHubNode: Starting notification delivery', [
                'primary_channel' => $primaryChannel,
                'fallback_channels' => $fallbackChannels,
                'title' => $title,
                'message_length' => strlen($message),
                'priority' => $priority,
            ]);

            // Try each channel until one succeeds
            foreach ($channelsToTry as $channel) {
                if (! in_array($channel, self::SUPPORTED_CHANNELS)) {
                    $deliveryResults[$channel] = [
                        'success' => false,
                        'error' => "Unsupported channel: {$channel}",
                        'attempted_at' => now()->toIso8601String(),
                    ];

                    continue;
                }

                // Check rate limit
                if (! $this->checkRateLimit($channel)) {
                    $deliveryResults[$channel] = [
                        'success' => false,
                        'error' => 'Rate limit exceeded',
                        'attempted_at' => now()->toIso8601String(),
                    ];
                    Log::warning("NotificationHubNode: Rate limit exceeded for channel {$channel}");

                    continue;
                }

                // Attempt delivery
                $result = $this->deliverToChannel($channel, $title, $message, $priority, $credentialsKey, $input);
                $deliveryResults[$channel] = $result;

                if (! empty($result['suppressed'])) {
                    $suppressedChannel = $channel;
                    Log::warning("NotificationHubNode: Delivery suppressed via {$channel}", [
                        'source_group' => $result['source_group'] ?? null,
                    ]);

                    continue;
                }

                if ($result['success']) {
                    $successfulChannel = $channel;
                    $this->incrementRateLimit($channel);
                    Log::info("NotificationHubNode: Delivery successful via {$channel}");
                    break;
                } else {
                    Log::warning("NotificationHubNode: Delivery failed via {$channel}", [
                        'error' => $result['error'] ?? 'Unknown error',
                    ]);
                }
            }

            $elapsedMs = round((microtime(true) - $startTime) * 1000);

            // Determine overall success
            $overallSuccess = $successfulChannel !== null;

            return $this->standardOutput([
                'notification_sent' => $overallSuccess,
                'notification_suppressed' => $suppressedChannel !== null,
                'successful_channel' => $successfulChannel,
                'suppressed_channel' => $suppressedChannel,
                'title' => $title,
                'message_length' => strlen($message),
                'priority' => $priority,
                'delivery_results' => $deliveryResults,
                'channels_attempted' => count($deliveryResults),
            ], [
                'elapsed_ms' => $elapsedMs,
                'primary_channel' => $primaryChannel,
                'fallback_count' => count($fallbackChannels),
            ]);

        } catch (Exception $e) {
            Log::error('NotificationHubNode: Fatal error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->standardOutput(
                [
                    'notification_sent' => false,
                    'delivery_results' => $deliveryResults,
                ],
                [],
                $e->getMessage()
            );
        }
    }

    /**
     * Deliver notification to a specific channel
     */
    private function deliverToChannel(
        string $channel,
        string $title,
        string $message,
        string $priority,
        ?string $credentialsKey,
        array $input
    ): array {
        $startTime = microtime(true);

        try {
            $credentials = $this->getCredentials($channel, $credentialsKey);

            $result = match ($channel) {
                'pushover' => $this->sendPushover($title, $message, $priority, $credentials),
                'email' => $this->sendEmail($title, $message, $priority, $credentials, $input),
                'slack' => $this->sendSlack($title, $message, $priority, $credentials),
                'discord' => $this->sendDiscord($title, $message, $priority, $credentials),
                'ntfy' => $this->sendNtfy($title, $message, $priority, $credentials),
                'webhook' => $this->sendWebhook($title, $message, $priority, $credentials, $input),
                default => throw new Exception("Unsupported channel: {$channel}"),
            };

            $elapsedMs = round((microtime(true) - $startTime) * 1000);

            return array_merge($result, [
                'attempted_at' => now()->toIso8601String(),
                'elapsed_ms' => $elapsedMs,
            ]);

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'attempted_at' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * Send via Pushover (uses existing NotificationController)
     */
    private function sendPushover(string $title, string $message, string $priority, array $credentials): array
    {
        $priorityMap = [
            'low' => -1,
            'normal' => 0,
            'high' => 1,
            'urgent' => 2,
        ];

        $controller = app(NotificationController::class);
        $sourceGroup = (string) $this->getConfigValue('source_group', 'workflow_node_notifications');

        $payload = [
            'title' => $title,
            'message' => $message,
            'priority' => $priorityMap[$priority] ?? 0,
            'source_group' => $sourceGroup,
        ];

        // Add emergency priority parameters
        if (($priorityMap[$priority] ?? 0) == 2) {
            $payload['retry'] = $this->getConfigValue('pushover_retry', 60);
            $payload['expire'] = $this->getConfigValue('pushover_expire', 3600);
        }

        // Optional sound
        $sound = $this->getConfigValue('pushover_sound');
        if ($sound) {
            $payload['sound'] = $sound;
        }

        // Optional URL
        $url = $this->getConfigValue('url');
        if ($url) {
            $payload['url'] = $url;
            $payload['url_title'] = $this->getConfigValue('url_title');
        }

        $result = $controller->send('pushover', $payload);

        return [
            'success' => $result['success'] ?? false,
            'suppressed' => $result['suppressed'] ?? false,
            'source_group' => $result['source_group'] ?? $sourceGroup,
            'provider' => 'pushover',
        ];
    }

    /**
     * Send via Email (uses EmailService)
     */
    private function sendEmail(string $title, string $message, string $priority, array $credentials, array $input): array
    {
        $to = $credentials['to'] ?? $this->getConfigValue('email_to') ?? config('mail.from.address');
        $from = $credentials['from'] ?? $this->getConfigValue('email_from');

        if (empty($to)) {
            return [
                'success' => false,
                'error' => 'No recipient email address configured',
            ];
        }

        try {
            $emailService = app(EmailService::class);

            $result = $emailService->sendEmail($to, $title, $message, $from);

            return [
                'success' => $result['success'] ?? false,
                'provider' => 'email',
                'recipient' => $to,
                'error' => $result['error'] ?? null,
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => 'email',
            ];
        }
    }

    /**
     * Send via Slack webhook
     */
    private function sendSlack(string $title, string $message, string $priority, array $credentials): array
    {
        $webhookUrl = $credentials['webhook_url'] ?? $this->getConfigValue('slack_webhook_url');

        if (empty($webhookUrl)) {
            return [
                'success' => false,
                'error' => 'No Slack webhook URL configured',
            ];
        }

        // Build Slack message with blocks for better formatting
        $priorityEmoji = match ($priority) {
            'urgent' => ':rotating_light:',
            'high' => ':warning:',
            'low' => ':information_source:',
            default => ':bell:',
        };

        $payload = [
            'text' => "{$priorityEmoji} {$title}",
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => $title,
                        'emoji' => true,
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $message,
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "Priority: *{$priority}* | Sent via PLOS NotificationHub",
                        ],
                    ],
                ],
            ],
        ];

        // Add channel override if specified
        $channel = $credentials['channel'] ?? $this->getConfigValue('slack_channel');
        if ($channel) {
            $payload['channel'] = $channel;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(30)->post($webhookUrl, $payload);

            return [
                'success' => $response->successful(),
                'provider' => 'slack',
                'status_code' => $response->status(),
                'error' => $response->successful() ? null : $response->body(),
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => 'slack',
            ];
        }
    }

    /**
     * Send via Discord webhook
     */
    private function sendDiscord(string $title, string $message, string $priority, array $credentials): array
    {
        $webhookUrl = $credentials['webhook_url'] ?? $this->getConfigValue('discord_webhook_url');

        if (empty($webhookUrl)) {
            return [
                'success' => false,
                'error' => 'No Discord webhook URL configured',
            ];
        }

        // Map priority to embed color
        $colorMap = [
            'urgent' => 0xFF0000,  // Red
            'high' => 0xFFA500,    // Orange
            'normal' => 0x0099FF,  // Blue
            'low' => 0x808080,     // Gray
        ];

        $payload = [
            'embeds' => [
                [
                    'title' => $title,
                    'description' => $message,
                    'color' => $colorMap[$priority] ?? $colorMap['normal'],
                    'footer' => [
                        'text' => "Priority: {$priority} | PLOS NotificationHub",
                    ],
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
        ];

        // Add username override if specified
        $username = $credentials['username'] ?? $this->getConfigValue('discord_username', 'PLOS Notifications');
        if ($username) {
            $payload['username'] = $username;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(30)->post($webhookUrl, $payload);

            // Discord returns 204 No Content on success
            $success = in_array($response->status(), [200, 204]);

            return [
                'success' => $success,
                'provider' => 'discord',
                'status_code' => $response->status(),
                'error' => $success ? null : $response->body(),
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => 'discord',
            ];
        }
    }

    /**
     * Send via ntfy (https://ntfy.sh)
     */
    private function sendNtfy(string $title, string $message, string $priority, array $credentials): array
    {
        $serverUrl = $credentials['server_url'] ?? $this->getConfigValue('ntfy_server_url', 'https://ntfy.sh');
        $topic = $credentials['topic'] ?? $this->getConfigValue('ntfy_topic');

        if (empty($topic)) {
            return [
                'success' => false,
                'error' => 'No ntfy topic configured',
            ];
        }

        // Map priority to ntfy priority (1-5, 3 is default)
        $priorityMap = [
            'low' => 2,
            'normal' => 3,
            'high' => 4,
            'urgent' => 5,
        ];

        $url = rtrim($serverUrl, '/').'/'.$topic;

        $headers = [
            'Title' => $title,
            'Priority' => (string) ($priorityMap[$priority] ?? 3),
            'Tags' => $this->getConfigValue('ntfy_tags', 'plos,notification'),
        ];

        // Add optional click action
        $clickUrl = $this->getConfigValue('url');
        if ($clickUrl) {
            $headers['Click'] = $clickUrl;
        }

        // Add optional auth
        $authToken = $credentials['auth_token'] ?? $this->getConfigValue('ntfy_auth_token');
        if ($authToken) {
            $headers['Authorization'] = "Bearer {$authToken}";
        }

        try {
            $response = Http::connectTimeout(5)->timeout(30)
                ->withHeaders($headers)
                ->withBody($message, 'text/plain')
                ->post($url);

            return [
                'success' => $response->successful(),
                'provider' => 'ntfy',
                'status_code' => $response->status(),
                'topic' => $topic,
                'error' => $response->successful() ? null : $response->body(),
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => 'ntfy',
            ];
        }
    }

    /**
     * Send via generic webhook
     */
    private function sendWebhook(string $title, string $message, string $priority, array $credentials, array $input): array
    {
        $webhookUrl = $credentials['webhook_url'] ?? $this->getConfigValue('webhook_url');

        if (empty($webhookUrl)) {
            return [
                'success' => false,
                'error' => 'No webhook URL configured',
            ];
        }

        // Build payload - customizable via config
        $payloadTemplate = $this->getConfigValue('webhook_payload_template');

        if ($payloadTemplate) {
            // Custom payload template with variable substitution
            $payloadJson = $this->substituteVariables($payloadTemplate, array_merge($input, [
                'notification' => [
                    'title' => $title,
                    'message' => $message,
                    'priority' => $priority,
                    'timestamp' => now()->toIso8601String(),
                ],
            ]));
            $payload = json_decode($payloadJson, true) ?? [];
        } else {
            // Default payload structure
            $payload = [
                'title' => $title,
                'message' => $message,
                'priority' => $priority,
                'timestamp' => now()->toIso8601String(),
                'source' => 'plos_notification_hub',
                'data' => $input['data'] ?? null,
            ];
        }

        // Custom headers
        $headers = $credentials['headers'] ?? $this->getConfigValue('webhook_headers', []);

        // Method (default POST)
        $method = strtoupper($credentials['method'] ?? $this->getConfigValue('webhook_method', 'POST'));

        try {
            $request = Http::connectTimeout(5)->timeout(30);

            if (! empty($headers)) {
                $request = $request->withHeaders($headers);
            }

            $response = match ($method) {
                'GET' => $request->get($webhookUrl, $payload),
                'PUT' => $request->put($webhookUrl, $payload),
                'PATCH' => $request->patch($webhookUrl, $payload),
                default => $request->post($webhookUrl, $payload),
            };

            // Custom success status codes
            $successCodes = $this->getConfigValue('webhook_success_codes', [200, 201, 202, 204]);

            return [
                'success' => in_array($response->status(), $successCodes),
                'provider' => 'webhook',
                'status_code' => $response->status(),
                'error' => in_array($response->status(), $successCodes) ? null : $response->body(),
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'provider' => 'webhook',
            ];
        }
    }

    /**
     * Get credentials for a channel
     * Checks notification_credentials table first, then falls back to config
     */
    private function getCredentials(string $channel, ?string $credentialsKey): array
    {
        // If specific credentials key provided, look it up
        if ($credentialsKey) {
            $row = DB::selectOne(
                'SELECT credentials FROM notification_credentials WHERE credentials_key = ? AND channel = ? AND is_active = 1',
                [$credentialsKey, $channel]
            );

            if ($row && $row->credentials) {
                return json_decode($row->credentials, true) ?? [];
            }
        }

        // Check for channel-specific default credentials
        $row = DB::selectOne(
            'SELECT credentials FROM notification_credentials WHERE channel = ? AND is_default = 1 AND is_active = 1',
            [$channel]
        );

        if ($row && $row->credentials) {
            return json_decode($row->credentials, true) ?? [];
        }

        // Return empty array - channel handlers will use config values
        return [];
    }

    /**
     * Check if we're within rate limit for a channel
     */
    private function checkRateLimit(string $channel): bool
    {
        $rateLimitKey = $this->getConfigValue('rate_limit_key', $channel);
        $maxPerMinute = $this->getConfigValue('rate_limit_per_minute', self::DEFAULT_RATE_LIMITS[$channel] ?? 10);

        $cacheKey = "notification_hub_rate_limit:{$rateLimitKey}";
        $currentCount = Cache::get($cacheKey, 0);

        return $currentCount < $maxPerMinute;
    }

    /**
     * Increment rate limit counter for a channel
     */
    private function incrementRateLimit(string $channel): void
    {
        $rateLimitKey = $this->getConfigValue('rate_limit_key', $channel);
        $cacheKey = "notification_hub_rate_limit:{$rateLimitKey}";

        $currentCount = Cache::get($cacheKey, 0);
        Cache::put($cacheKey, $currentCount + 1, self::RATE_LIMIT_TTL);
    }

    /**
     * Substitute variables in string with values from input data
     * Supports {{data.field}} and {{field}} syntax with nested paths
     *
     * @param  string  $text  Text containing variables to substitute
     * @param  array  $input  Input data containing values
     * @return string Text with variables substituted
     */
    private function substituteVariables(string $text, array $input): string
    {
        // Find all variables in {{double_braces}} - supports dot notation
        return preg_replace_callback('/\{\{([a-zA-Z0-9_\.]+)\}\}/', function ($matches) use ($input) {
            $variablePath = $matches[1];

            // Split path by dots for nested access
            $pathParts = explode('.', $variablePath);

            // Navigate through the input array
            $value = $input;
            foreach ($pathParts as $part) {
                if (is_array($value) && array_key_exists($part, $value)) {
                    $value = $value[$part];
                } else {
                    // Variable not found, return as-is
                    return $matches[0];
                }
            }

            return $this->formatValue($value);
        }, $text);
    }

    /**
     * Extract message from input data
     */
    private function extractMessage(array $input): ?string
    {
        // Check for formatted_text (from AIFormatter)
        if (isset($input['data']['formatted_text'])) {
            return $input['data']['formatted_text'];
        }

        // Check for data string
        if (isset($input['data']) && is_string($input['data'])) {
            return $input['data'];
        }

        // Check for message key
        if (isset($input['message'])) {
            return $input['message'];
        }

        // Check for data array
        if (isset($input['data']) && is_array($input['data'])) {
            return json_encode($input['data'], JSON_PRETTY_PRINT);
        }

        return null;
    }

    /**
     * Format a value for display in notification
     *
     * @param  mixed  $value  Value to format
     * @return string Formatted value
     */
    private function formatValue($value): string
    {
        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return '';
        }

        return (string) $value;
    }
}
