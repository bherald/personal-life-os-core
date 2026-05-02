<?php

namespace App\Nodes;

use App\Services\DataRemovalService;
use App\Services\DataRemovalNotificationService;
use Exception;

/**
 * Removal Alert Node
 *
 * Sends notifications for data removal events.
 * Can send individual alerts or daily digests.
 */
class RemovalAlertNode extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $notifyService = app(DataRemovalNotificationService::class);
            $dataService = app(DataRemovalService::class);

            $alertType = $this->getConfigValue('alert_type', 'digest');
            $requestId = $this->getConfigValue('request_id', null);

            // Handle digest
            if ($alertType === 'digest') {
                $success = $notifyService->sendDailyDigest();
                return $this->standardOutput([
                    'sent' => $success ? 1 : 0,
                    'type' => 'digest',
                    'message' => $success ? 'Daily digest sent' : 'Failed to send digest',
                ]);
            }

            // Handle specific request alerts
            if (!$requestId) {
                return $this->standardOutput([
                    'sent' => 0,
                    'error' => 'Request ID required for individual alerts',
                ]);
            }

            $request = $dataService->getRequest($requestId);
            if (!$request) {
                return $this->standardOutput([
                    'sent' => 0,
                    'error' => "Request {$requestId} not found",
                ]);
            }

            $broker = $dataService->getBroker($request->broker_id);
            $subject = $dataService->getSubject($request->subject_id);

            if (!$broker || !$subject) {
                return $this->standardOutput([
                    'sent' => 0,
                    'error' => 'Missing broker or subject data',
                ]);
            }

            $success = match ($alertType) {
                'discovered' => $notifyService->alertDataDiscovered($request, $broker, $subject),
                'verified' => $notifyService->alertRemovalVerified($request, $broker, $subject),
                'failed' => $notifyService->alertRemovalFailed(
                    $request,
                    $broker,
                    $subject,
                    $this->getConfigValue('reason', $request->last_error ?? 'Unknown')
                ),
                'reappeared' => $notifyService->alertDataReappeared($request, $broker, $subject),
                'captcha' => $notifyService->alertCaptchaQueued(
                    $request,
                    $broker,
                    $this->getConfigValue('captcha_type', 'unknown')
                ),
                'escalated' => $notifyService->alertRequestEscalated(
                    $request,
                    $broker,
                    $subject,
                    $request->followup_count ?? 0
                ),
                default => false,
            };

            // Log the notification
            if ($success) {
                $dataService->logActivity($requestId, 'manual_action', [
                    'action' => 'notification_sent',
                    'alert_type' => $alertType,
                ]);
            }

            return $this->standardOutput([
                'sent' => $success ? 1 : 0,
                'type' => $alertType,
                'request_id' => $requestId,
                'broker' => $broker->domain,
                'subject' => $subject->name,
                'message' => $success ? "Alert '{$alertType}' sent" : "Failed to send alert",
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    public static function getDefinition(): array
    {
        return [
            'type' => 'removal_alert',
            'name' => 'Removal Alert',
            'description' => 'Send notifications for data removal events via Pushover',
            'category' => 'Privacy',
            'icon' => '🔔',
            'config' => [
                'alert_type' => [
                    'type' => 'select',
                    'label' => 'Alert Type',
                    'description' => 'Type of notification to send',
                    'required' => true,
                    'options' => [
                        'digest' => 'Daily Digest',
                        'discovered' => 'Data Discovered',
                        'verified' => 'Removal Verified',
                        'failed' => 'Removal Failed',
                        'reappeared' => 'Data Reappeared',
                        'captcha' => 'CAPTCHA Required',
                        'escalated' => 'Request Escalated',
                    ],
                    'default' => 'digest',
                ],
                'request_id' => [
                    'type' => 'integer',
                    'label' => 'Request ID',
                    'description' => 'Specific request for individual alerts (not needed for digest)',
                    'required' => false,
                ],
                'reason' => [
                    'type' => 'string',
                    'label' => 'Failure Reason',
                    'description' => 'Reason for failure (for failed alerts)',
                    'required' => false,
                ],
                'captcha_type' => [
                    'type' => 'string',
                    'label' => 'CAPTCHA Type',
                    'description' => 'Type of CAPTCHA (for captcha alerts)',
                    'required' => false,
                    'default' => 'unknown',
                ],
            ],
            'outputs' => [
                'sent' => 'Number of notifications sent',
                'type' => 'Alert type that was sent',
                'request_id' => 'Request ID (if applicable)',
                'message' => 'Status message',
            ],
        ];
    }
}
