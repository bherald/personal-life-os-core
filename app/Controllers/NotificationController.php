<?php

namespace App\Controllers;

use App\Exceptions\NodeTimeoutException;
use App\Services\PushoverRateLimitPolicy;
use App\Services\SystemConfigService;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationController
{
    private const RUNTIME_POLICY_CONFIG_KEY = 'notifications.pushover_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'emergency_retry_seconds' => 60,
        'emergency_expire_seconds' => 3600,
        'send_connect_timeout_seconds' => 5,
        'send_request_timeout_seconds' => 30,
        'receipt_connect_timeout_seconds' => 5,
        'receipt_request_timeout_seconds' => 10,
    ];

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function send(string $provider, array $data): array
    {
        switch ($provider) {
            case 'pushover':
                return $this->sendPushover($data);
            case 'email':
                $sent = $this->sendEmail($data);

                return ['success' => $sent];
            default:
                throw new Exception("Unknown notification provider: {$provider}");
        }
    }

    /**
     * Send with receipt — returns receipt token for emergency priority messages
     */
    public function sendPushoverWithReceipt(array $data): array
    {
        $result = $this->doSendPushover($data);

        return $result;
    }

    private function sendPushover(array $data): array
    {
        return $this->doSendPushover($data);
    }

    private function doSendPushover(array $data): array
    {
        $apiUrl = config('services.pushover.api_url', 'https://api.pushover.net/1/messages.json');
        $apiToken = config('services.pushover.token');
        $userKey = config('services.pushover.user_key');
        $sourceGroup = $this->resolvePushoverSourceGroup($data);

        if (! $apiToken || ! $userKey) {
            Log::warning('Pushover configuration missing, skipping notification');

            return ['success' => false, 'error' => 'Configuration missing'];
        }

        if (! PushoverRateLimitPolicy::isAllowed($sourceGroup)) {
            Log::info('Pushover notification suppressed by source-group policy', [
                'title' => $data['title'] ?? 'Notification',
                'source_group' => $sourceGroup,
            ]);

            return [
                'success' => true,
                'suppressed' => true,
                'source_group' => $sourceGroup,
            ];
        }

        // Rate limiting — shared across all callers (NotificationHubNode, AgentLoop, etc.)
        $rateLimit = PushoverRateLimitPolicy::limitFor($sourceGroup);
        $currentCount = PushoverRateLimitPolicy::currentCount($sourceGroup);
        if ($currentCount >= $rateLimit) {
            Log::warning('Pushover rate limit exceeded, skipping notification', [
                'title' => $data['title'] ?? 'unknown',
                'count' => $currentCount,
                'limit' => $rateLimit,
                'source_group' => $sourceGroup,
            ]);

            return ['success' => false, 'error' => "Rate limit exceeded ({$rateLimit}/min)"];
        }

        try {
            $payload = [
                'token' => $apiToken,
                'user' => $userKey,
                'title' => $data['title'] ?? 'Notification',
                'message' => $data['message'] ?? '',
                'priority' => $data['priority'] ?? 0,
            ];

            if (isset($data['sound'])) {
                $payload['sound'] = $data['sound'];
            }

            $formatType = $data['format_type'] ?? 'plain';
            if ($formatType === 'html') {
                $payload['html'] = 1;
            } elseif ($formatType === 'monospace') {
                $payload['monospace'] = 1;
            }

            if (isset($data['url'])) {
                $payload['url'] = $data['url'];
                if (isset($data['url_title'])) {
                    $payload['url_title'] = $data['url_title'];
                }
            }

            if (isset($data['timestamp'])) {
                $payload['timestamp'] = $data['timestamp'];
            }

            if (isset($data['ttl'])) {
                $payload['ttl'] = $data['ttl'];
            }

            // Supplementary action buttons (Pushover 3.0+)
            // Format: "action=label,url;action=label,url" (up to 3)
            if (! empty($data['actions'])) {
                $actionParts = [];
                foreach (array_slice($data['actions'], 0, 3) as $action) {
                    $label = $action['label'] ?? 'Action';
                    $url = $action['url'] ?? '';
                    if ($url) {
                        $actionParts[] = "action={$label},{$url}";
                    }
                }
                if (! empty($actionParts)) {
                    $payload['actions'] = implode(';', $actionParts);
                }
            }

            // Emergency priority requires retry and expire
            if (($data['priority'] ?? 0) == 2) {
                $payload['retry'] = $data['retry'] ?? $this->runtimePolicyInt('emergency_retry_seconds');
                $payload['expire'] = $data['expire'] ?? $this->runtimePolicyInt('emergency_expire_seconds');
            }

            if (isset($data['attachment'])) {
                $payload['attachment_base64'] = $data['attachment'];
                $payload['attachment_type'] = $data['attachment_type'] ?? 'image/jpeg';
            }

            $response = Http::connectTimeout($this->runtimePolicyInt('send_connect_timeout_seconds'))
                ->timeout($this->runtimePolicyInt('send_request_timeout_seconds'))
                ->asForm()
                ->post($apiUrl, $payload);

            if (! $response->successful()) {
                Log::error('Pushover notification failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['success' => false, 'error' => 'HTTP '.$response->status()];
            }

            $body = $response->json();
            $pushoverStatus = $body['status'] ?? null;
            if ($pushoverStatus !== 1) {
                $errors = $body['errors'] ?? null;
                $errorMessage = is_array($errors) && $errors !== []
                    ? implode('; ', array_map(static fn ($error): string => (string) $error, $errors))
                    : 'Pushover API returned unsuccessful status.';

                Log::error('Pushover notification failed', [
                    'status' => $response->status(),
                    'pushover_status' => $pushoverStatus,
                    'errors' => $errors,
                    'request' => $body['request'] ?? null,
                ]);

                return ['success' => false, 'error' => $errorMessage];
            }

            // Increment rate limit counter on successful send
            PushoverRateLimitPolicy::recordSent($sourceGroup);

            Log::info('Pushover notification sent successfully', [
                'title' => $data['title'] ?? 'Notification',
                'source_group' => $sourceGroup,
                'format_type' => $formatType,
                'priority' => $data['priority'] ?? 0,
                'has_url' => isset($data['url']),
                'has_actions' => isset($payload['actions']),
                'actions_payload' => $payload['actions'] ?? null,
                'base_url_used' => parse_url($data['url'] ?? '', PHP_URL_HOST),
                'receipt' => $body['receipt'] ?? null,
            ]);

            return [
                'success' => true,
                'receipt' => $body['receipt'] ?? null,
                'request' => $body['request'] ?? null,
                'source_group' => $sourceGroup,
            ];

        } catch (Exception $e) {
            if ($e instanceof NodeTimeoutException || str_contains($e->getMessage(), 'Node timeout:')) {
                throw $e;
            }

            Log::error('Pushover notification exception', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        if ($this->runtimePolicy !== null) {
            return $this->runtimePolicy;
        }

        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return $this->runtimePolicy = array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }

    private function resolvePushoverSourceGroup(array $data): string
    {
        $explicit = $data['source_group'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);

        foreach ($trace as $frame) {
            $class = $frame['class'] ?? '';
            $file = $frame['file'] ?? '';

            if (str_contains($class, 'MorningDigestCommand') || str_contains($class, 'NightlyOpsCommand')) {
                return 'daily_digests';
            }

            if (
                str_contains($class, 'AgentLoopService')
                || str_contains($class, 'WorkflowApprovalService')
                || str_ends_with($file, '/routes/api.php')
            ) {
                return 'agent_approval_review';
            }

            if (
                str_contains($class, 'PushoverNotify')
                || str_contains($class, 'NotificationHubNode')
            ) {
                return 'workflow_node_notifications';
            }

            if (str_contains($class, 'OAuthTokenHealthCheck')) {
                return 'auth_token_alerts';
            }

            if (str_contains($class, 'TestConsoleNotification')) {
                return 'test_dev_only';
            }
        }

        return 'unknown';
    }

    /**
     * Check Pushover receipt status — returns whether emergency message was acknowledged
     */
    public function checkPushoverReceipt(string $receipt): array
    {
        $apiToken = config('services.pushover.token');
        if (! $apiToken) {
            return ['success' => false, 'error' => 'Pushover token not configured'];
        }

        try {
            $response = Http::connectTimeout($this->runtimePolicyInt('receipt_connect_timeout_seconds'))
                ->timeout($this->runtimePolicyInt('receipt_request_timeout_seconds'))
                ->get(
                    "https://api.pushover.net/1/receipts/{$receipt}.json",
                    ['token' => $apiToken]
                );

            if (! $response->successful()) {
                return ['success' => false, 'error' => 'HTTP '.$response->status()];
            }

            $data = $response->json();

            return [
                'success' => true,
                'acknowledged' => (bool) ($data['acknowledged'] ?? false),
                'acknowledged_at' => $data['acknowledged_at'] ?? null,
                'expired' => (bool) ($data['expired'] ?? false),
                'called_back' => (bool) ($data['called_back'] ?? false),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function sendEmail(array $data): bool
    {
        try {
            Mail::raw($data['message'] ?? '', function ($message) use ($data) {
                $message->to($data['to'] ?? config('mail.from.address'))
                    ->subject($data['subject'] ?? $data['title'] ?? 'Notification');
            });

            Log::info('Email notification sent successfully', [
                'to' => $data['to'] ?? config('mail.from.address'),
                'subject' => $data['subject'] ?? $data['title'] ?? 'Notification',
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Email notification failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
