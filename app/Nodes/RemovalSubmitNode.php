<?php

namespace App\Nodes;

use App\Services\DataRemovalService;
use App\Services\BrokerScraperService;
use App\Services\ThunderbirdService;
use Exception;

/**
 * Removal Submit Node
 *
 * Submits removal requests to data brokers.
 * Handles web forms, email submissions, and API calls.
 * Supports CAPTCHA detection and solving.
 */
class RemovalSubmitNode extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $dataService = app(DataRemovalService::class);
            $scraperService = app(BrokerScraperService::class);

            $requestId = $this->getConfigValue('request_id', null);
            $processAll = $this->getConfigValue('process_all', false);
            $tierFilter = $this->getConfigValue('tier_filter', null);
            $limit = (int) $this->getConfigValue('limit', 5);
            $skipReview = $this->getConfigValue('skip_review', false);

            // Get requests to process
            if ($requestId) {
                $request = $dataService->getRequest($requestId);
                $requests = $request ? [$request] : [];
            } else {
                // Get pending requests ready for submission
                $requests = $dataService->getRequestsForSubmission($limit, $tierFilter, !$skipReview);
            }

            if (empty($requests)) {
                return $this->standardOutput([
                    'processed' => 0,
                    'submitted' => 0,
                    'failed' => 0,
                    'needs_captcha' => 0,
                    'message' => 'No requests ready for submission',
                ]);
            }

            $processed = 0;
            $submitted = 0;
            $failed = 0;
            $needsCaptcha = 0;
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

                    // Check rate limit
                    if (!$scraperService->checkRateLimit($broker)) {
                        continue;
                    }

                    $processed++;

                    // Select submission method
                    $method = $broker->removal_method;

                    switch ($method) {
                        case 'web_form':
                            $result = $this->submitWebForm($scraperService, $broker, $subject, $request);
                            break;

                        case 'email':
                            $result = $this->submitEmail($dataService, $broker, $subject, $request);
                            break;

                        case 'api':
                            $result = $this->submitApi($scraperService, $broker, $subject, $request);
                            break;

                        default:
                            $result = [
                                'success' => false,
                                'error' => "Unsupported removal method: {$method}",
                                'needs_manual' => true,
                            ];
                    }

                    if ($result['success']) {
                        $submitted++;
                        $dataService->updateRequestStatus($request->id, 'submitted', [
                            'submission_method' => $method,
                            'confirmation_code' => $result['confirmation_code'] ?? null,
                            'submitted_at' => now(),
                        ]);
                        $dataService->logActivity($request->id, 'submitted', [
                            'method' => $method,
                            'confirmation_code' => $result['confirmation_code'] ?? null,
                        ]);
                    } elseif ($result['needs_captcha'] ?? false) {
                        $needsCaptcha++;
                        $dataService->logActivity($request->id, 'captcha_detected', [
                            'captcha_type' => $result['captcha_type'] ?? 'unknown',
                        ]);
                    } else {
                        $failed++;
                        $dataService->updateRequest($request->id, [
                            'last_error' => $result['error'] ?? 'Unknown error',
                            'error_count' => $request->error_count + 1,
                        ]);
                        $dataService->logActivity($request->id, 'failed', [
                            'error' => $result['error'] ?? 'Unknown error',
                        ]);
                        $errors[] = [
                            'request_id' => $request->id,
                            'broker' => $broker->domain,
                            'error' => $result['error'] ?? 'Unknown error',
                        ];
                    }

                    $results[] = [
                        'request_id' => $request->id,
                        'broker' => $broker->domain,
                        'subject' => $subject->name,
                        'method' => $method,
                        'success' => $result['success'],
                    ];

                    // Update rate limit tracking
                    $scraperService->recordScan($broker);

                } catch (Exception $e) {
                    $failed++;
                    $errors[] = [
                        'request_id' => $request->id,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return $this->standardOutput([
                'processed' => $processed,
                'submitted' => $submitted,
                'failed' => $failed,
                'needs_captcha' => $needsCaptcha,
                'results' => $results,
                'errors' => $errors,
                'message' => "Processed {$processed} request(s): {$submitted} submitted, {$failed} failed, {$needsCaptcha} need CAPTCHA",
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    private function submitWebForm(BrokerScraperService $scraper, object $broker, object $subject, object $request): array
    {
        if (empty($broker->removal_url)) {
            return ['success' => false, 'error' => 'No removal URL configured'];
        }

        try {
            // Use appropriate engine
            $engine = $scraper->selectEngine($broker);

            // Attempt to submit the form
            $result = $scraper->submitRemovalForm($broker, $subject, $engine);

            return $result;

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function submitEmail(DataRemovalService $dataService, object $broker, object $subject, object $request): array
    {
        if (empty($broker->removal_email)) {
            return ['success' => false, 'error' => 'No removal email configured'];
        }

        try {
            // Generate removal email content
            $emailBody = $this->generateRemovalEmail($broker, $subject);
            $emailSubject = "Data Removal Request - {$subject->name}";

            // Try to send via Thunderbird MCP
            $thunderbird = app(ThunderbirdService::class);

            if ($thunderbird->isAvailable()) {
                $result = $thunderbird->sendMail([
                    'to' => $broker->removal_email,
                    'subject' => $emailSubject,
                    'body' => $emailBody,
                ]);

                $dataService->logActivity(
                    $request->id,
                    'email_sent',
                    "Removal email sent to {$broker->removal_email}",
                    [
                        'to' => $broker->removal_email,
                        'subject' => $emailSubject,
                        'thunderbird_result' => $result,
                    ]
                );

                return [
                    'success' => true,
                    'method' => 'email',
                    'confirmation_code' => 'EMAIL_SENT_' . date('YmdHis'),
                    'note' => 'Email sent via Thunderbird',
                ];
            } else {
                // Thunderbird not available - mark for manual review
                $dataService->logActivity(
                    $request->id,
                    'manual_action',
                    "Email prepared but Thunderbird unavailable - requires manual send to {$broker->removal_email}",
                    [
                        'to' => $broker->removal_email,
                        'subject' => $emailSubject,
                        'body' => $emailBody,
                        'status' => 'pending_manual_send',
                    ]
                );

                return [
                    'success' => true,
                    'method' => 'email',
                    'confirmation_code' => 'EMAIL_PENDING_' . date('YmdHis'),
                    'note' => 'Email prepared - Thunderbird unavailable, requires manual send',
                    'requires_manual' => true,
                ];
            }

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function submitApi(BrokerScraperService $scraper, object $broker, object $subject, object $request): array
    {
        // API submissions would be broker-specific
        return [
            'success' => false,
            'error' => 'API submission not yet implemented for this broker',
            'needs_manual' => true,
        ];
    }

    private function generateRemovalEmail(object $broker, object $subject): string
    {
        $template = <<<EOT
To Whom It May Concern,

I am writing to request the immediate removal of my personal information from your database and website ({$broker->domain}).

Personal Information to Remove:
- Name: {$subject->name}
EOT;

        if ($subject->email) {
            $template .= "\n- Email: {$subject->email}";
        }
        if ($subject->phone) {
            $template .= "\n- Phone: {$subject->phone}";
        }
        if ($subject->city && $subject->state) {
            $template .= "\n- Location: {$subject->city}, {$subject->state}";
        }

        $template .= <<<EOT


Under applicable data protection laws (including CCPA, GDPR, and state privacy laws), I have the right to request deletion of my personal data. Please confirm the removal within 30 days.

If you require any verification of my identity, please contact me at the email address associated with this request.

Thank you for your prompt attention to this matter.

Sincerely,
{$subject->name}
EOT;

        return $template;
    }

    public static function getDefinition(): array
    {
        return [
            'type' => 'removal_submit',
            'name' => 'Removal Submit',
            'description' => 'Submit removal requests to data brokers via web form, email, or API',
            'category' => 'Privacy',
            'icon' => '📤',
            'config' => [
                'request_id' => [
                    'type' => 'integer',
                    'label' => 'Request ID',
                    'description' => 'Specific request to submit (leave empty for batch processing)',
                    'required' => false,
                ],
                'tier_filter' => [
                    'type' => 'select',
                    'label' => 'Automation Tier',
                    'description' => 'Only process requests for brokers of this tier',
                    'required' => false,
                    'options' => [
                        '' => 'All Tiers',
                        '1' => 'Tier 1 - Fully Automated',
                        '2' => 'Tier 2 - AI Review',
                    ],
                ],
                'limit' => [
                    'type' => 'integer',
                    'label' => 'Batch Limit',
                    'description' => 'Maximum requests to process in this run',
                    'required' => false,
                    'default' => 5,
                ],
                'skip_review' => [
                    'type' => 'boolean',
                    'label' => 'Skip Review Check',
                    'description' => 'Process requests even if they require review',
                    'required' => false,
                    'default' => false,
                ],
            ],
            'outputs' => [
                'processed' => 'Number of requests processed',
                'submitted' => 'Number of successful submissions',
                'failed' => 'Number of failed submissions',
                'needs_captcha' => 'Number blocked by CAPTCHA',
                'results' => 'Detailed results for each request',
                'errors' => 'Array of any errors',
                'message' => 'Status summary message',
            ],
        ];
    }
}
