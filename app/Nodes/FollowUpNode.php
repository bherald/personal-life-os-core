<?php

namespace App\Nodes;

use App\Services\DataRemovalService;
use App\Services\BrokerScraperService;
use App\Services\ThunderbirdService;
use Exception;

/**
 * Follow-Up Node
 *
 * Sends follow-up requests for unprocessed removals.
 * Re-submits requests that haven't been confirmed.
 * Escalates persistent issues.
 */
class FollowUpNode extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $dataService = app(DataRemovalService::class);
            $scraperService = app(BrokerScraperService::class);

            $requestId = $this->getConfigValue('request_id', null);
            $limit = (int) $this->getConfigValue('limit', 10);
            $escalateAfter = (int) $this->getConfigValue('escalate_after', 3);

            // Get requests due for follow-up
            if ($requestId) {
                $request = $dataService->getRequest($requestId);
                $requests = $request ? [$request] : [];
            } else {
                $requests = $dataService->getRequestsForFollowup($limit);
            }

            if (empty($requests)) {
                return $this->standardOutput([
                    'processed' => 0,
                    'followed_up' => 0,
                    'escalated' => 0,
                    'message' => 'No requests due for follow-up',
                ]);
            }

            $processed = 0;
            $followedUp = 0;
            $escalated = 0;
            $errors = [];
            $results = [];

            foreach ($requests as $request) {
                if ($processed >= $limit) break;

                try {
                    $broker = $dataService->getBroker($request->broker_id);
                    $subject = $dataService->getSubject($request->subject_id);

                    if (!$broker || !$subject) {
                        $errors[] = "Missing broker or subject for request {$request->id}";
                        continue;
                    }

                    $processed++;
                    $followupCount = ($request->followup_count ?? 0) + 1;

                    // Check if should escalate
                    if ($followupCount > $escalateAfter) {
                        $escalated++;
                        $dataService->updateRequest($request->id, [
                            'requires_review' => true,
                            'ai_notes' => "Auto-escalated after {$followupCount} follow-ups without resolution",
                        ]);
                        $dataService->logActivity(
                            $request->id,
                            'manual_action',
                            "Auto-escalated: No response after {$escalateAfter} follow-ups",
                            [
                                'action' => 'escalated',
                                'reason' => "No response after {$escalateAfter} follow-ups",
                                'followup_count' => $followupCount,
                            ]
                        );

                        $results[] = [
                            'request_id' => $request->id,
                            'broker' => $broker->domain,
                            'action' => 'escalated',
                            'followup_count' => $followupCount,
                        ];
                        continue;
                    }

                    // Send follow-up based on removal method
                    $method = $broker->removal_method;
                    $success = false;

                    switch ($method) {
                        case 'email':
                            $success = $this->sendFollowupEmail($dataService, $broker, $subject, $request, $followupCount);
                            break;

                        case 'web_form':
                            // Re-submit the form
                            if (!$scraperService->checkRateLimit($broker)) {
                                continue 2; // Skip this broker
                            }
                            $engine = $scraperService->selectEngine($broker);
                            $result = $scraperService->submitRemovalForm($broker, $subject, $engine);
                            $success = $result['success'] ?? false;
                            $scraperService->recordScan($broker);
                            break;

                        default:
                            // Mark for manual follow-up
                            $dataService->updateRequest($request->id, [
                                'requires_review' => true,
                                'ai_notes' => "Follow-up needed but method '{$method}' requires manual action",
                            ]);
                            $success = false;
                    }

                    if ($success) {
                        $followedUp++;
                        $nextFollowup = now()->addDays($this->getFollowupInterval($followupCount));

                        $dataService->updateRequest($request->id, [
                            'followup_count' => $followupCount,
                            'next_followup_at' => $nextFollowup,
                            'recheck_at' => $nextFollowup->addDays(7),
                        ]);

                        $dataService->logActivity(
                            $request->id,
                            'followup_sent',
                            "Follow-up #{$followupCount} sent via {$method}",
                            [
                                'followup_number' => $followupCount,
                                'method' => $method,
                                'next_followup' => $nextFollowup->toDateString(),
                            ]
                        );
                    }

                    $results[] = [
                        'request_id' => $request->id,
                        'broker' => $broker->domain,
                        'action' => $success ? 'followed_up' : 'failed',
                        'followup_count' => $followupCount,
                        'method' => $method,
                    ];

                } catch (Exception $e) {
                    $errors[] = [
                        'request_id' => $request->id,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return $this->standardOutput([
                'processed' => $processed,
                'followed_up' => $followedUp,
                'escalated' => $escalated,
                'failed' => $processed - $followedUp - $escalated,
                'results' => $results,
                'errors' => $errors,
                'message' => "Processed {$processed}: {$followedUp} followed up, {$escalated} escalated",
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    private function sendFollowupEmail(DataRemovalService $dataService, object $broker, object $subject, object $request, int $followupCount): bool
    {
        if (empty($broker->removal_email)) {
            return false;
        }

        $urgency = $this->getUrgencyLevel($followupCount);
        $emailSubject = "[Follow-up #{$followupCount}] Data Removal Request - {$subject->name}";

        // Generate follow-up email
        $emailBody = $this->generateFollowupEmail($broker, $subject, $request, $followupCount, $urgency);

        // Try to send via Thunderbird MCP
        $thunderbird = app(ThunderbirdService::class);

        if ($thunderbird->isAvailable()) {
            try {
                $result = $thunderbird->sendMail([
                    'to' => $broker->removal_email,
                    'subject' => $emailSubject,
                    'body' => $emailBody,
                ]);

                $dataService->logActivity(
                    $request->id,
                    'followup_sent',
                    "Follow-up #{$followupCount} email sent to {$broker->removal_email}",
                    [
                        'to' => $broker->removal_email,
                        'subject' => $emailSubject,
                        'urgency' => $urgency,
                        'thunderbird_result' => $result,
                    ]
                );

                return true;
            } catch (Exception $e) {
                $dataService->logActivity(
                    $request->id,
                    'failed',
                    "Failed to send follow-up email: {$e->getMessage()}",
                    [
                        'to' => $broker->removal_email,
                        'error' => $e->getMessage(),
                    ]
                );
                return false;
            }
        } else {
            // Thunderbird not available - mark for manual review
            $dataService->logActivity(
                $request->id,
                'manual_action',
                "Follow-up #{$followupCount} prepared but Thunderbird unavailable - requires manual send",
                [
                    'to' => $broker->removal_email,
                    'subject' => $emailSubject,
                    'body' => $emailBody,
                    'urgency' => $urgency,
                    'status' => 'pending_manual_send',
                ]
            );

            // Mark request for manual review
            $dataService->updateRequest($request->id, [
                'requires_review' => true,
                'ai_notes' => ($request->ai_notes ?? '') . "\nFollow-up #{$followupCount} requires manual email send",
            ]);

            return false;
        }
    }

    private function generateFollowupEmail(object $broker, object $subject, object $request, int $followupCount, string $urgency): string
    {
        $daysAgo = now()->diffInDays($request->submitted_at);
        $originalDate = date('F j, Y', strtotime($request->submitted_at));

        $urgencyText = match($urgency) {
            'high' => "This is my {$followupCount}rd follow-up request. I expect immediate action.",
            'critical' => "This is a FINAL NOTICE. I will be filing a formal complaint if this matter is not resolved within 7 days.",
            default => "I am following up on my previous removal request.",
        };

        $template = <<<EOT
To Whom It May Concern,

{$urgencyText}

I submitted a data removal request on {$originalDate} ({$daysAgo} days ago) and have not received confirmation that my personal information has been removed from {$broker->domain}.

Original Request Details:
- Name: {$subject->name}
EOT;

        if ($subject->email) {
            $template .= "\n- Email: {$subject->email}";
        }
        if ($request->confirmation_code) {
            $template .= "\n- Reference/Confirmation: {$request->confirmation_code}";
        }

        $template .= <<<EOT


Under applicable privacy laws (CCPA, GDPR, state privacy regulations), you are required to respond to data deletion requests within 30-45 days.

Please confirm the removal of my data immediately, or provide a valid reason for non-compliance.

EOT;

        if ($urgency === 'critical') {
            $template .= <<<EOT

Note: If I do not receive confirmation within 7 days, I will:
1. File a complaint with the FTC
2. File a complaint with my state Attorney General
3. Pursue other legal remedies as appropriate

EOT;
        }

        $template .= <<<EOT

Sincerely,
{$subject->name}
EOT;

        return $template;
    }

    private function getFollowupInterval(int $followupCount): int
    {
        // Increasing intervals: 7, 14, 21, 30 days
        $intervals = [7, 14, 21, 30];
        $index = min($followupCount - 1, count($intervals) - 1);
        return $intervals[$index];
    }

    private function getUrgencyLevel(int $followupCount): string
    {
        if ($followupCount >= 3) return 'critical';
        if ($followupCount >= 2) return 'high';
        return 'normal';
    }

    public static function getDefinition(): array
    {
        return [
            'type' => 'followup',
            'name' => 'Follow-Up',
            'description' => 'Send follow-up requests for unprocessed data removals',
            'category' => 'Privacy',
            'icon' => '📬',
            'config' => [
                'request_id' => [
                    'type' => 'integer',
                    'label' => 'Request ID',
                    'description' => 'Specific request to follow up on (leave empty for batch processing)',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'label' => 'Batch Limit',
                    'description' => 'Maximum follow-ups to send in this run',
                    'required' => false,
                    'default' => 10,
                ],
                'escalate_after' => [
                    'type' => 'integer',
                    'label' => 'Escalate After',
                    'description' => 'Number of follow-ups before escalating to manual review',
                    'required' => false,
                    'default' => 3,
                ],
            ],
            'outputs' => [
                'processed' => 'Number of requests processed',
                'followed_up' => 'Number of follow-ups sent',
                'escalated' => 'Number escalated to manual review',
                'failed' => 'Number that failed to follow up',
                'results' => 'Detailed results for each request',
                'message' => 'Status summary message',
            ],
        ];
    }
}
