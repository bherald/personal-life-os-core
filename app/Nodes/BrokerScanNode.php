<?php

namespace App\Nodes;

use App\Services\DataRemovalService;
use App\Services\BrokerScraperService;
use Exception;

/**
 * Broker Scan Node
 *
 * Scans data broker websites for subject PII.
 * Creates removal requests for any data found.
 * Supports tier-based automation routing.
 */
class BrokerScanNode extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $dataService = app(DataRemovalService::class);
            $scraperService = app(BrokerScraperService::class);

            $subjectId = $this->getConfigValue('subject_id', null);
            $brokerId = $this->getConfigValue('broker_id', null);
            $scanAll = $this->getConfigValue('scan_all', false);
            $tierFilter = $this->getConfigValue('tier_filter', null);
            $limit = (int) $this->getConfigValue('limit', 10);

            // Get subjects to scan
            if ($subjectId) {
                $subjects = [$dataService->getSubject($subjectId)];
            } else {
                $subjects = $dataService->getSubjects(true);
            }

            if (empty($subjects)) {
                return $this->standardOutput([
                    'scanned' => 0,
                    'found' => 0,
                    'requests_created' => 0,
                    'message' => 'No subjects to scan',
                ]);
            }

            // Get brokers to check
            if ($brokerId) {
                $brokers = [$dataService->getBroker($brokerId)];
            } else {
                $brokers = $dataService->getBrokers(true);
            }

            // Filter by tier if specified
            if ($tierFilter !== null) {
                $brokers = array_filter($brokers, fn($b) => $b->automation_tier == $tierFilter);
            }

            if (empty($brokers)) {
                return $this->standardOutput([
                    'scanned' => 0,
                    'found' => 0,
                    'requests_created' => 0,
                    'message' => 'No brokers to scan',
                ]);
            }

            $scannedCount = 0;
            $foundCount = 0;
            $requestsCreated = 0;
            $errors = [];

            // Limit total scans
            $totalScans = 0;

            foreach ($subjects as $subject) {
                if ($totalScans >= $limit) break;

                foreach ($brokers as $broker) {
                    if ($totalScans >= $limit) break;

                    // Check rate limit
                    if (!$scraperService->checkRateLimit($broker)) {
                        continue;
                    }

                    try {
                        // Select appropriate engine
                        $engine = $scraperService->selectEngine($broker);

                        // Perform the scan
                        $result = $scraperService->scanBroker($broker, $subject, $engine);
                        $scannedCount++;
                        $totalScans++;

                        if ($result['found']) {
                            $foundCount++;

                            // Create or update removal request
                            $existingRequest = $dataService->getRequestBySubjectAndBroker(
                                $subject->id,
                                $broker->id
                            );

                            if (!$existingRequest) {
                                $requestId = $dataService->createRequest([
                                    'subject_id' => $subject->id,
                                    'broker_id' => $broker->id,
                                    'automation_tier' => $broker->automation_tier,
                                    'data_found' => $result['data_found'] ?? null,
                                    'profile_url' => $result['profile_url'] ?? null,
                                    'ai_confidence' => $result['confidence'] ?? null,
                                    'first_discovered_at' => now(),
                                    'requires_review' => $broker->automation_tier >= 2,
                                ]);

                                if ($requestId) {
                                    $requestsCreated++;
                                    $dataService->logActivity($requestId, 'discovered', [
                                        'engine' => $engine,
                                        'data_found' => $result['data_found'] ?? null,
                                    ]);
                                }
                            } else {
                                // Update existing request if it reappeared
                                if ($existingRequest->status === 'verified_removed') {
                                    $dataService->updateRequestStatus($existingRequest->id, 'reappeared');
                                    $dataService->logActivity($existingRequest->id, 'reappeared', [
                                        'engine' => $engine,
                                        'previous_status' => 'verified_removed',
                                    ]);
                                }
                            }
                        }

                        // Update rate limit tracking
                        $scraperService->recordScan($broker);

                    } catch (Exception $e) {
                        $errors[] = [
                            'subject' => $subject->name,
                            'broker' => $broker->domain,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            return $this->standardOutput([
                'scanned' => $scannedCount,
                'found' => $foundCount,
                'requests_created' => $requestsCreated,
                'errors' => $errors,
                'message' => "Scanned {$scannedCount} broker(s), found {$foundCount} listing(s), created {$requestsCreated} request(s)",
            ], [
                'subjects_count' => count($subjects),
                'brokers_count' => count($brokers),
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    public static function getDefinition(): array
    {
        return [
            'type' => 'broker_scan',
            'name' => 'Broker Scan',
            'description' => 'Scan data broker websites for subject PII and create removal requests',
            'category' => 'Privacy',
            'icon' => '🔍',
            'config' => [
                'subject_id' => [
                    'type' => 'integer',
                    'label' => 'Subject ID',
                    'description' => 'Specific subject to scan (leave empty for all)',
                    'required' => false,
                ],
                'broker_id' => [
                    'type' => 'integer',
                    'label' => 'Broker ID',
                    'description' => 'Specific broker to check (leave empty for all)',
                    'required' => false,
                ],
                'tier_filter' => [
                    'type' => 'select',
                    'label' => 'Automation Tier',
                    'description' => 'Only scan brokers of this tier',
                    'required' => false,
                    'options' => [
                        '' => 'All Tiers',
                        '1' => 'Tier 1 - Fully Automated',
                        '2' => 'Tier 2 - AI Review',
                        '3' => 'Tier 3 - Manual',
                    ],
                ],
                'limit' => [
                    'type' => 'integer',
                    'label' => 'Scan Limit',
                    'description' => 'Maximum number of broker scans to perform',
                    'required' => false,
                    'default' => 10,
                ],
            ],
            'outputs' => [
                'scanned' => 'Number of brokers scanned',
                'found' => 'Number of listings found',
                'requests_created' => 'Number of new removal requests created',
                'errors' => 'Array of any errors encountered',
                'message' => 'Status summary message',
            ],
        ];
    }
}
