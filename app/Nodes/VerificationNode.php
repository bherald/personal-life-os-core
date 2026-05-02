<?php

namespace App\Nodes;

use App\Services\DataRemovalService;
use App\Services\BrokerScraperService;
use Exception;

/**
 * Verification Node
 *
 * Verifies that data has been removed from broker sites.
 * Re-scans brokers after submission to confirm removal.
 * Updates request status based on findings.
 */
class VerificationNode extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $dataService = app(DataRemovalService::class);
            $scraperService = app(BrokerScraperService::class);

            $requestId = $this->getConfigValue('request_id', null);
            $daysAfterSubmission = (int) $this->getConfigValue('days_after_submission', 7);
            $limit = (int) $this->getConfigValue('limit', 10);

            // Get requests ready for verification
            if ($requestId) {
                $request = $dataService->getRequest($requestId);
                $requests = $request ? [$request] : [];
            } else {
                $requests = $dataService->getRequestsForVerification($daysAfterSubmission, $limit);
            }

            if (empty($requests)) {
                return $this->standardOutput([
                    'verified' => 0,
                    'still_present' => 0,
                    'errors' => 0,
                    'message' => 'No requests ready for verification',
                ]);
            }

            $verified = 0;
            $stillPresent = 0;
            $errorCount = 0;
            $errors = [];
            $results = [];

            foreach ($requests as $request) {
                try {
                    $broker = $dataService->getBroker($request->broker_id);
                    $subject = $dataService->getSubject($request->subject_id);

                    if (!$broker || !$subject) {
                        $errors[] = "Missing broker or subject for request {$request->id}";
                        $errorCount++;
                        continue;
                    }

                    // Check rate limit
                    if (!$scraperService->checkRateLimit($broker)) {
                        continue;
                    }

                    // Select engine and perform verification scan
                    $engine = $scraperService->selectEngine($broker);
                    $result = $scraperService->scanBroker($broker, $subject, $engine);

                    $dataService->logActivity($request->id, 'verification_started', [
                        'engine' => $engine,
                        'days_since_submission' => $this->daysSince($request->submitted_at),
                    ]);

                    if (!$result['found']) {
                        // Data has been removed!
                        $verified++;
                        $dataService->updateRequestStatus($request->id, 'verified_removed', [
                            'verified_removed_at' => now(),
                        ]);
                        $dataService->logActivity($request->id, 'verified', [
                            'message' => 'Data no longer found on broker site',
                        ]);

                        // Update broker success metrics
                        $dataService->updateBrokerMetrics($broker->id, true, $this->daysSince($request->submitted_at));

                    } else {
                        // Data still present
                        $stillPresent++;

                        // Check if we should schedule follow-up
                        $followupCount = $request->followup_count ?? 0;
                        $maxFollowups = $request->max_followups ?? 3;

                        if ($followupCount < $maxFollowups) {
                            // Schedule next follow-up
                            $nextFollowup = now()->addDays($this->getFollowupInterval($followupCount));
                            $dataService->updateRequest($request->id, [
                                'next_followup_at' => $nextFollowup,
                                'recheck_at' => $nextFollowup->addDays(7),
                            ]);
                            $dataService->logActivity($request->id, 'verification_failed', [
                                'message' => 'Data still present, scheduling follow-up',
                                'next_followup' => $nextFollowup->toDateString(),
                            ]);
                        } else {
                            // Max follow-ups reached, mark as failed
                            $dataService->updateRequestStatus($request->id, 'failed', [
                                'last_error' => 'Max follow-ups reached, data still present',
                            ]);
                            $dataService->logActivity($request->id, 'failed', [
                                'message' => 'Max follow-ups reached without successful removal',
                            ]);

                            // Update broker failure metrics
                            $dataService->updateBrokerMetrics($broker->id, false);
                        }
                    }

                    $results[] = [
                        'request_id' => $request->id,
                        'broker' => $broker->domain,
                        'subject' => $subject->name,
                        'removed' => !$result['found'],
                        'days_since_submission' => $this->daysSince($request->submitted_at),
                    ];

                    // Update rate limit tracking
                    $scraperService->recordScan($broker);

                } catch (Exception $e) {
                    $errorCount++;
                    $errors[] = [
                        'request_id' => $request->id,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return $this->standardOutput([
                'processed' => count($results),
                'verified' => $verified,
                'still_present' => $stillPresent,
                'errors' => $errorCount,
                'results' => $results,
                'error_details' => $errors,
                'message' => "Verified {$verified} removal(s), {$stillPresent} still present, {$errorCount} error(s)",
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    private function daysSince(?string $date): int
    {
        if (!$date) return 0;
        return now()->diffInDays($date);
    }

    private function getFollowupInterval(int $followupCount): int
    {
        // Exponential backoff: 7, 14, 30 days
        $intervals = [7, 14, 30];
        return $intervals[$followupCount] ?? 30;
    }

    public static function getDefinition(): array
    {
        return [
            'type' => 'verification',
            'name' => 'Removal Verification',
            'description' => 'Verify that data has been removed from broker sites after submission',
            'category' => 'Privacy',
            'icon' => '✅',
            'config' => [
                'request_id' => [
                    'type' => 'integer',
                    'label' => 'Request ID',
                    'description' => 'Specific request to verify (leave empty for batch processing)',
                    'required' => false,
                ],
                'days_after_submission' => [
                    'type' => 'integer',
                    'label' => 'Days After Submission',
                    'description' => 'Minimum days to wait after submission before verifying',
                    'required' => false,
                    'default' => 7,
                ],
                'limit' => [
                    'type' => 'integer',
                    'label' => 'Batch Limit',
                    'description' => 'Maximum verifications to perform in this run',
                    'required' => false,
                    'default' => 10,
                ],
            ],
            'outputs' => [
                'processed' => 'Number of requests checked',
                'verified' => 'Number confirmed removed',
                'still_present' => 'Number still showing data',
                'errors' => 'Number of verification errors',
                'results' => 'Detailed results for each request',
                'message' => 'Status summary message',
            ],
        ];
    }
}
