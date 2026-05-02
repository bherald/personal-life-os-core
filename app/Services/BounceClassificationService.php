<?php

namespace App\Services;

/**
 * Bounce Classification Service
 *
 * Classifies email bounces by provider (Postmark, SendGrid, Mailgun) and SMTP codes.
 * Determines whether bounces are retryable based on type and error code.
 */
class BounceClassificationService
{
    // SMTP codes indicating permanent failure
    private const HARD_BOUNCE_PREFIXES = ['5.1.1', '5.1.2', '5.1.3', '5.1.6', '5.7.1'];

    // SMTP codes indicating temporary failure
    private const SOFT_BOUNCE_PREFIXES = ['4.2.1', '4.2.2', '4.4.1', '4.4.2', '4.7.0'];

    /**
     * Classify a Postmark bounce webhook payload
     */
    public function classifyPostmark(array $payload): array
    {
        $type = $payload['Type'] ?? '';

        return match($type) {
            'HardBounce' => ['type' => 'hard', 'subtype' => $payload['Name'] ?? 'Unknown', 'retryable' => false],
            'SoftBounce', 'Transient' => ['type' => 'soft', 'subtype' => $payload['Name'] ?? 'Unknown', 'retryable' => true],
            'SpamComplaint' => ['type' => 'complaint', 'subtype' => 'spam', 'retryable' => false],
            'Unsubscribe' => ['type' => 'complaint', 'subtype' => 'unsubscribe', 'retryable' => false],
            default => ['type' => 'soft', 'subtype' => $type, 'retryable' => true],
        };
    }

    /**
     * Classify a SendGrid bounce webhook payload
     */
    public function classifySendGrid(array $payload): array
    {
        $event = $payload['event'] ?? '';
        $type = $payload['type'] ?? '';

        return match($event) {
            'bounce' => match($type) {
                'bounce' => ['type' => 'hard', 'subtype' => 'bounce', 'retryable' => false],
                'blocked' => ['type' => 'soft', 'subtype' => 'blocked', 'retryable' => true],
                default => ['type' => 'soft', 'subtype' => $type, 'retryable' => true],
            },
            'dropped' => ['type' => 'hard', 'subtype' => $payload['reason'] ?? 'dropped', 'retryable' => false],
            'spamreport' => ['type' => 'complaint', 'subtype' => 'spam', 'retryable' => false],
            default => ['type' => 'soft', 'subtype' => $event, 'retryable' => true],
        };
    }

    /**
     * Classify a Mailgun bounce webhook payload
     */
    public function classifyMailgun(array $payload): array
    {
        $eventData = $payload['event-data'] ?? [];
        $severity = $eventData['severity'] ?? 'temporary';

        return match($severity) {
            'permanent' => ['type' => 'hard', 'subtype' => 'permanent', 'retryable' => false],
            'temporary' => ['type' => 'soft', 'subtype' => 'temporary', 'retryable' => true],
            default => ['type' => 'soft', 'subtype' => $severity, 'retryable' => true],
        };
    }

    /**
     * Classify bounce by SMTP enhanced status code
     */
    public function classifyBySmtpCode(string $code): array
    {
        foreach (self::HARD_BOUNCE_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return ['type' => 'hard', 'subtype' => 'SMTP-' . $code, 'retryable' => false];
            }
        }

        foreach (self::SOFT_BOUNCE_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return ['type' => 'soft', 'subtype' => 'SMTP-' . $code, 'retryable' => true];
            }
        }

        // 5xx = hard, 4xx = soft
        if (str_starts_with($code, '5')) {
            return ['type' => 'hard', 'subtype' => 'SMTP-' . $code, 'retryable' => false];
        }

        return ['type' => 'soft', 'subtype' => 'SMTP-' . $code, 'retryable' => true];
    }
}
