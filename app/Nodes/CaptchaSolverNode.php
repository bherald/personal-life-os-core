<?php

namespace App\Nodes;

use App\Services\CaptchaSolverService;
use App\Services\DataRemovalService;
use Exception;

/**
 * CAPTCHA Solver Node
 *
 * Processes pending CAPTCHAs for data removal requests.
 * Attempts automatic solving via AI vision or 2Captcha.
 * Queues unsolvable CAPTCHAs for manual resolution.
 */
class CaptchaSolverNode extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $captchaService = app(CaptchaSolverService::class);
            $dataService = app(DataRemovalService::class);

            $requestId = $this->getConfigValue('request_id', null);
            $queueId = $this->getConfigValue('queue_id', null);
            $processQueue = $this->getConfigValue('process_queue', false);
            $limit = (int) $this->getConfigValue('limit', 5);

            $solved = 0;
            $failed = 0;
            $queued = 0;
            $results = [];

            // Process specific queue item
            if ($queueId) {
                $result = $this->processQueueItem($captchaService, $queueId);
                $results[] = $result;
                if ($result['solved']) $solved++;
                else $failed++;

                return $this->standardOutput([
                    'processed' => 1,
                    'solved' => $solved,
                    'failed' => $failed,
                    'queued' => 0,
                    'results' => $results,
                    'message' => $result['solved'] ? 'CAPTCHA solved' : 'CAPTCHA could not be solved',
                ]);
            }

            // Process pending queue
            if ($processQueue) {
                $pending = $captchaService->getPendingQueue($limit);

                foreach ($pending as $item) {
                    $result = $this->processQueueItem($captchaService, $item->id);
                    $results[] = $result;

                    if ($result['solved']) {
                        $solved++;
                    } else {
                        $failed++;
                    }
                }

                return $this->standardOutput([
                    'processed' => count($pending),
                    'solved' => $solved,
                    'failed' => $failed,
                    'queued' => 0,
                    'results' => $results,
                    'message' => "Processed " . count($pending) . " queued CAPTCHAs: {$solved} solved, {$failed} failed",
                ]);
            }

            // Process CAPTCHAs for specific request or all requests needing CAPTCHA
            $requests = $requestId
                ? [$dataService->getRequest($requestId)]
                : $this->getRequestsNeedingCaptcha($dataService, $limit);

            foreach ($requests as $request) {
                if (!$request) continue;

                try {
                    $broker = $dataService->getBroker($request->broker_id);

                    // Check if there's CAPTCHA info in the request
                    $captchaInfo = json_decode($request->captcha_info ?? '{}', true);

                    if (empty($captchaInfo['type'])) {
                        continue;
                    }

                    $solveResult = $captchaService->solve($captchaInfo['type'], [
                        'request_id' => $request->id,
                        'broker_id' => $request->broker_id,
                        'page_url' => $captchaInfo['page_url'] ?? $broker->removal_url ?? null,
                        'sitekey' => $captchaInfo['sitekey'] ?? null,
                        'image_url' => $captchaInfo['image_url'] ?? null,
                        'screenshot_path' => $captchaInfo['screenshot_path'] ?? null,
                    ]);

                    if ($solveResult['success']) {
                        $solved++;

                        // Update request with solution
                        $dataService->updateRequest($request->id, [
                            'captcha_solution' => $solveResult['solution'],
                            'captcha_solved_at' => now(),
                        ]);

                        $dataService->logActivity($request->id, 'captcha_solved', [
                            'type' => $captchaInfo['type'],
                            'method' => $solveResult['method'],
                        ]);
                    } elseif ($solveResult['method'] === 'manual_queue') {
                        $queued++;

                        $dataService->logActivity($request->id, 'captcha_queued', [
                            'type' => $captchaInfo['type'],
                            'queue_id' => $solveResult['queue_id'] ?? null,
                        ]);
                    } else {
                        $failed++;

                        $dataService->logActivity($request->id, 'captcha_failed', [
                            'type' => $captchaInfo['type'],
                            'error' => $solveResult['error'] ?? 'Unknown error',
                        ]);
                    }

                    $results[] = [
                        'request_id' => $request->id,
                        'broker' => $broker->domain ?? 'unknown',
                        'type' => $captchaInfo['type'],
                        'solved' => $solveResult['success'],
                        'method' => $solveResult['method'],
                    ];

                } catch (Exception $e) {
                    $failed++;
                    $results[] = [
                        'request_id' => $request->id,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return $this->standardOutput([
                'processed' => count($results),
                'solved' => $solved,
                'failed' => $failed,
                'queued' => $queued,
                'results' => $results,
                'message' => "Processed " . count($results) . ": {$solved} solved, {$queued} queued, {$failed} failed",
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    /**
     * Process a specific queue item
     */
    private function processQueueItem(CaptchaSolverService $service, int $queueId): array
    {
        try {
            // Get queue item from DB
            $items = \DB::select(
                "SELECT * FROM data_removal_captcha_queue WHERE id = ? LIMIT 1",
                [$queueId]
            );
            $item = $items[0] ?? null;

            if (!$item) {
                return ['queue_id' => $queueId, 'solved' => false, 'error' => 'Queue item not found'];
            }

            if ($item->status === 'solved') {
                return [
                    'queue_id' => $queueId,
                    'solved' => true,
                    'solution' => $item->solution,
                    'method' => 'previously_solved',
                ];
            }

            // Attempt to solve
            $result = $service->solve($item->type, [
                'request_id' => $item->request_id,
                'broker_id' => $item->broker_id,
                'page_url' => $item->page_url,
                'sitekey' => $item->sitekey,
                'screenshot_path' => $item->screenshot_path,
            ]);

            if ($result['success']) {
                // Update queue item
                \DB::update(
                    "UPDATE data_removal_captcha_queue SET status = ?, solution = ?, solved_at = ?, updated_at = ? WHERE id = ?",
                    ['solved', $result['solution'], now(), now(), $queueId]
                );
            } else {
                \DB::update(
                    "UPDATE data_removal_captcha_queue SET status = ?, updated_at = ? WHERE id = ?",
                    ['failed', now(), $queueId]
                );
            }

            return [
                'queue_id' => $queueId,
                'type' => $item->type,
                'solved' => $result['success'],
                'method' => $result['method'],
                'error' => $result['error'] ?? null,
            ];

        } catch (Exception $e) {
            return ['queue_id' => $queueId, 'solved' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get requests that need CAPTCHA solving
     */
    private function getRequestsNeedingCaptcha(DataRemovalService $dataService, int $limit): array
    {
        $sql = "SELECT * FROM removal_requests
                WHERE captcha_info IS NOT NULL
                AND captcha_solution IS NULL
                AND status NOT IN ('verified_removed', 'failed', 'cancelled')
                ORDER BY created_at ASC
                LIMIT ?";

        return \DB::select($sql, [$limit]);
    }

    public static function getDefinition(): array
    {
        return [
            'type' => 'captcha_solver',
            'name' => 'CAPTCHA Solver',
            'description' => 'Solve CAPTCHAs for data removal requests using AI vision or solving services',
            'category' => 'Privacy',
            'icon' => '🔐',
            'config' => [
                'request_id' => [
                    'type' => 'integer',
                    'label' => 'Request ID',
                    'description' => 'Specific request to solve CAPTCHA for',
                    'required' => false,
                ],
                'queue_id' => [
                    'type' => 'integer',
                    'label' => 'Queue ID',
                    'description' => 'Specific CAPTCHA queue item to process',
                    'required' => false,
                ],
                'process_queue' => [
                    'type' => 'boolean',
                    'label' => 'Process Queue',
                    'description' => 'Process pending items from CAPTCHA queue',
                    'required' => false,
                    'default' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'label' => 'Batch Limit',
                    'description' => 'Maximum CAPTCHAs to process',
                    'required' => false,
                    'default' => 5,
                ],
            ],
            'outputs' => [
                'processed' => 'Number of CAPTCHAs processed',
                'solved' => 'Number successfully solved',
                'failed' => 'Number that failed to solve',
                'queued' => 'Number queued for manual solving',
                'results' => 'Detailed results for each CAPTCHA',
                'message' => 'Status summary message',
            ],
        ];
    }
}
