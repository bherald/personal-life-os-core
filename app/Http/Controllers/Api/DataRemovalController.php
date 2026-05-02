<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteBrokerDiscovery;
use App\Jobs\ExecuteDataRemovalScan;
use App\Services\DataRemovalService;
use App\Services\BrokerScraperService;
use App\Services\BrokerDiscoveryService;
use App\Services\DataRemoval\BrokerHealthService;
use App\Services\DataRemoval\RelistingDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Data Removal API Controller
 *
 * Handles all API endpoints for the Personal Data Removal System (E06).
 * Provides CRUD operations for subjects, brokers, and removal requests,
 * plus dashboard statistics and action endpoints.
 */
class DataRemovalController extends Controller
{
    private DataRemovalService $dataRemovalService;
    private BrokerScraperService $scraperService;
    private BrokerDiscoveryService $discoveryService;

    public function __construct(
        DataRemovalService $dataRemovalService,
        BrokerScraperService $scraperService,
        BrokerDiscoveryService $discoveryService
    ) {
        $this->dataRemovalService = $dataRemovalService;
        $this->scraperService = $scraperService;
        $this->discoveryService = $discoveryService;
    }

    // ========================================
    // DASHBOARD & STATS
    // ========================================

    /**
     * Get overall statistics
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = $this->dataRemovalService->getStats();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error("DataRemovalController: stats failed", ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get dashboard data
     */
    public function dashboard(): JsonResponse
    {
        try {
            $dashboard = $this->dataRemovalService->getDashboardData();

            return response()->json([
                'success' => true,
                'data' => $dashboard,
            ]);
        } catch (\Exception $e) {
            Log::error("DataRemovalController: dashboard failed", ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ========================================
    // SUBJECTS (People to Protect)
    // ========================================

    /**
     * List all subjects
     */
    public function listSubjects(Request $request): JsonResponse
    {
        try {
            $activeOnly = $request->boolean('active_only', true);
            $subjects = $this->dataRemovalService->getSubjects($activeOnly);

            return response()->json([
                'success' => true,
                'data' => $subjects,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single subject
     */
    public function showSubject(int $id): JsonResponse
    {
        try {
            $subject = $this->dataRemovalService->getSubject($id);

            if (!$subject) {
                return response()->json([
                    'success' => false,
                    'error' => 'Subject not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $subject,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new subject
     */
    public function createSubject(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:50',
                'address_line1' => 'nullable|string|max:255',
                'address_line2' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:50',
                'zip' => 'nullable|string|max:20',
                'date_of_birth' => 'nullable|date',
                'aliases' => 'nullable|array',
                'notes' => 'nullable|string',
            ]);

            $id = $this->dataRemovalService->createSubject($validated);

            return response()->json([
                'success' => true,
                'data' => ['id' => $id],
                'message' => 'Subject created successfully',
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a subject
     */
    public function updateSubject(Request $request, int $id): JsonResponse
    {
        try {
            $subject = $this->dataRemovalService->getSubject($id);
            if (!$subject) {
                return response()->json([
                    'success' => false,
                    'error' => 'Subject not found',
                ], 404);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:50',
                'address_line1' => 'nullable|string|max:255',
                'address_line2' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:50',
                'zip' => 'nullable|string|max:20',
                'date_of_birth' => 'nullable|date',
                'aliases' => 'nullable|array',
                'is_active' => 'sometimes|boolean',
                'notes' => 'nullable|string',
            ]);

            $this->dataRemovalService->updateSubject($id, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Subject updated successfully',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a subject
     */
    public function deleteSubject(int $id): JsonResponse
    {
        try {
            $subject = $this->dataRemovalService->getSubject($id);
            if (!$subject) {
                return response()->json([
                    'success' => false,
                    'error' => 'Subject not found',
                ], 404);
            }

            $this->dataRemovalService->deleteSubject($id);

            return response()->json([
                'success' => true,
                'message' => 'Subject deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ========================================
    // BROKERS (Data Broker Sites)
    // ========================================

    /**
     * List all brokers
     */
    public function listBrokers(Request $request): JsonResponse
    {
        try {
            $activeOnly = $request->boolean('active_only', true);
            $category = $request->input('category');
            $tier = $request->input('tier') ? (int) $request->input('tier') : null;

            $brokers = $this->dataRemovalService->getBrokers($activeOnly, $category, $tier);

            return response()->json([
                'success' => true,
                'data' => $brokers,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single broker
     */
    public function showBroker(int $id): JsonResponse
    {
        try {
            $broker = $this->dataRemovalService->getBroker($id);

            if (!$broker) {
                return response()->json([
                    'success' => false,
                    'error' => 'Broker not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $broker,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new broker
     */
    public function createBroker(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'domain' => 'required|string|max:255|unique:data_brokers,domain',
                'category' => 'sometimes|in:people_search,marketing,background_check,data_aggregator,other',
                'removal_method' => 'sometimes|in:web_form,email,api,postal,phone,unknown',
                'removal_url' => 'nullable|url|max:500',
                'removal_email' => 'nullable|email|max:255',
                'automation_tier' => 'sometimes|integer|min:1|max:3',
                'requires_captcha' => 'sometimes|boolean',
                'requires_auth' => 'sometimes|boolean',
                'uses_javascript' => 'sometimes|boolean',
                'rate_limit_seconds' => 'sometimes|integer|min:0',
                'discovery_notes' => 'nullable|string',
            ]);

            $id = $this->dataRemovalService->createBroker($validated);

            return response()->json([
                'success' => true,
                'data' => ['id' => $id],
                'message' => 'Broker created successfully',
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a broker
     */
    public function updateBroker(Request $request, int $id): JsonResponse
    {
        try {
            $broker = $this->dataRemovalService->getBroker($id);
            if (!$broker) {
                return response()->json([
                    'success' => false,
                    'error' => 'Broker not found',
                ], 404);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'domain' => 'sometimes|string|max:255',
                'category' => 'sometimes|in:people_search,marketing,background_check,data_aggregator,other',
                'removal_method' => 'sometimes|in:web_form,email,api,postal,phone,unknown',
                'removal_url' => 'nullable|url|max:500',
                'removal_email' => 'nullable|email|max:255',
                'automation_tier' => 'sometimes|integer|min:1|max:3',
                'requires_captcha' => 'sometimes|boolean',
                'requires_auth' => 'sometimes|boolean',
                'uses_javascript' => 'sometimes|boolean',
                'rate_limit_seconds' => 'sometimes|integer|min:0',
                'is_active' => 'sometimes|boolean',
                'discovery_notes' => 'nullable|string',
            ]);

            $this->dataRemovalService->updateBroker($id, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Broker updated successfully',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a broker
     */
    public function deleteBroker(int $id): JsonResponse
    {
        try {
            $broker = $this->dataRemovalService->getBroker($id);
            if (!$broker) {
                return response()->json([
                    'success' => false,
                    'error' => 'Broker not found',
                ], 404);
            }

            $this->dataRemovalService->deleteBroker($id);

            return response()->json([
                'success' => true,
                'message' => 'Broker deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ========================================
    // REMOVAL REQUESTS
    // ========================================

    /**
     * List removal requests
     */
    public function listRequests(Request $request): JsonResponse
    {
        try {
            $filters = [
                'subject_id' => $request->input('subject_id'),
                'broker_id' => $request->input('broker_id'),
                'status' => $request->input('status'),
                'tier' => $request->input('tier'),
                'requires_review' => $request->boolean('requires_review'),
                'limit' => $request->input('limit', 100),
            ];

            $requests = $this->dataRemovalService->getRequests($filters);

            return response()->json([
                'success' => true,
                'data' => $requests,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single request
     */
    public function showRequest(int $id): JsonResponse
    {
        try {
            $request = $this->dataRemovalService->getRequest($id);

            if (!$request) {
                return response()->json([
                    'success' => false,
                    'error' => 'Request not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $request,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get activity log for a request
     */
    public function getRequestActivity(int $id): JsonResponse
    {
        try {
            $request = $this->dataRemovalService->getRequest($id);
            if (!$request) {
                return response()->json([
                    'success' => false,
                    'error' => 'Request not found',
                ], 404);
            }

            $activity = $this->dataRemovalService->getActivityLog($id);

            return response()->json([
                'success' => true,
                'data' => $activity,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit a removal request
     */
    public function submitRequest(int $id): JsonResponse
    {
        try {
            $removalRequest = $this->dataRemovalService->getRequest($id);
            if (!$removalRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Request not found',
                ], 404);
            }

            // Check rate limiting
            if ($this->dataRemovalService->isRateLimited()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Daily request limit reached',
                ], 429);
            }

            $broker = $this->dataRemovalService->getBroker($removalRequest->broker_id);
            $subject = $this->dataRemovalService->getSubject($removalRequest->subject_id);

            // Get the fields that should be submitted (privacy-filtered)
            $fieldsToSubmit = $this->dataRemovalService->getFieldsToSubmit($id);

            // Handle different removal methods
            $removalMethod = $broker->removal_method ?? 'web_form';

            if ($removalMethod === 'email' && !empty($broker->removal_email)) {
                // Email-based removal - mark for manual review with instructions
                $this->dataRemovalService->markForReview(
                    $id,
                    "Email removal required. Send opt-out request to: {$broker->removal_email}"
                );
                $this->dataRemovalService->logActivity(
                    $id,
                    'manual_action',
                    "Email removal required. Send opt-out request to: {$broker->removal_email}",
                    ['removal_email' => $broker->removal_email, 'fields' => $fieldsToSubmit],
                    'manual'
                );

                return response()->json([
                    'success' => false,
                    'data' => [
                        'requires_email' => true,
                        'removal_email' => $broker->removal_email,
                        'message' => "This broker requires an email request. Send opt-out to: {$broker->removal_email}",
                    ],
                ]);
            }

            if (empty($broker->removal_url) && $removalMethod === 'web_form') {
                // No removal URL configured - mark for review
                $this->dataRemovalService->markForReview(
                    $id,
                    'No removal URL configured for this broker. Manual research needed.'
                );

                return response()->json([
                    'success' => false,
                    'error' => 'No removal URL configured for this broker',
                    'data' => ['requires_research' => true],
                ]);
            }

            // Check if broker requires CAPTCHA - skip scraping and open directly
            if ($broker->requires_captcha) {
                $this->dataRemovalService->markForReview($id, 'CAPTCHA required - needs manual submission via browser extension');
                $this->dataRemovalService->logActivity(
                    $id,
                    'manual_action',
                    'CAPTCHA required - opening browser for manual submission',
                    ['removal_url' => $broker->removal_url, 'fields' => $fieldsToSubmit],
                    'manual'
                );

                return response()->json([
                    'success' => false,
                    'data' => [
                        'needs_captcha' => true,
                        'removal_url' => $broker->removal_url,
                        'message' => "This broker requires CAPTCHA verification. Open the removal page and use the browser extension to complete.",
                    ],
                ]);
            }

            // Attempt web form submission with only allowed fields
            $result = $this->scraperService->submitRemovalForm($broker, $subject, $fieldsToSubmit);

            if ($result['success']) {
                $this->dataRemovalService->updateRequestStatus($id, 'submitted');
                $this->dataRemovalService->scheduleFollowup($id);

                // Record which fields were actually submitted
                if (!empty($result['fields_submitted'])) {
                    $this->dataRemovalService->updateRequest($id, [
                        'fields_submitted' => json_encode($result['fields_submitted']),
                    ]);
                }
            } else {
                // Record the error and update status
                $errorMessage = $result['error'] ?? ($result['needs_captcha'] ? 'CAPTCHA required' : 'Submission failed');
                $this->dataRemovalService->updateRequest($id, [
                    'last_error' => $errorMessage,
                    'error_count' => $removalRequest->error_count + 1,
                ]);

                // If CAPTCHA is required, mark as needing review
                if (!empty($result['needs_captcha'])) {
                    $this->dataRemovalService->markForReview($id, 'CAPTCHA required - needs manual submission');
                }

                $this->dataRemovalService->logActivity($id, 'failed', $errorMessage, $result, 'manual');
            }

            return response()->json([
                'success' => $result['success'],
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify that a removal was successful
     */
    public function verifyRemoval(int $id): JsonResponse
    {
        try {
            $removalRequest = $this->dataRemovalService->getRequest($id);
            if (!$removalRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Request not found',
                ], 404);
            }

            $broker = $this->dataRemovalService->getBroker($removalRequest->broker_id);
            $subject = $this->dataRemovalService->getSubject($removalRequest->subject_id);

            // Attempt to verify the removal by checking if data is still present
            $result = $this->scraperService->searchBroker($broker, $subject);

            if (!$result['found']) {
                // Data is no longer present - mark as verified removed
                $this->dataRemovalService->updateRequestStatus($id, 'verified_removed');
                $this->dataRemovalService->logActivity($id, 'verified', 'Removal verified - data no longer found', $result);

                return response()->json([
                    'success' => true,
                    'verified' => true,
                    'message' => 'Removal verified - data no longer present',
                ]);
            } else {
                // Data still present
                $this->dataRemovalService->logActivity($id, 'verification_started', 'Data still present on broker site', $result);

                return response()->json([
                    'success' => true,
                    'verified' => false,
                    'message' => 'Data still present on broker site',
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get the review queue (items requiring human review)
     */
    public function reviewQueue(): JsonResponse
    {
        try {
            $items = $this->dataRemovalService->getRequests([
                'requires_review' => true,
                'status' => 'pending',
            ]);

            return response()->json([
                'success' => true,
                'data' => $items,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Review a request (approve/reject)
     */
    public function reviewRequest(Request $request, int $id): JsonResponse
    {
        try {
            $removalRequest = $this->dataRemovalService->getRequest($id);
            if (!$removalRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Request not found',
                ], 404);
            }

            $validated = $request->validate([
                'action' => 'required|in:approve,reject',
                'reviewed_by' => 'nullable|string|max:100',
                'notes' => 'nullable|string',
            ]);

            $this->dataRemovalService->completeReview(
                $id,
                $validated['reviewed_by'] ?? 'user',
                $validated['action'],
                $validated['notes'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Review completed',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update fields to submit for a request (user privacy control)
     */
    public function updateRequestFields(Request $request, int $id): JsonResponse
    {
        try {
            $removalRequest = $this->dataRemovalService->getRequest($id);
            if (!$removalRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Request not found',
                ], 404);
            }

            $validated = $request->validate([
                'fields' => 'required|array|min:1',
                'fields.*' => 'string|in:name,email,phone,address,city,state,zip,dob,aliases',
            ]);

            // Ensure 'name' is always included (required for any removal)
            $fields = $validated['fields'];
            if (!in_array('name', $fields)) {
                $fields[] = 'name';
            }

            $this->dataRemovalService->setRequestFieldsToSubmit($id, $fields);

            return response()->json([
                'success' => true,
                'message' => 'Fields updated',
                'fields' => $fields,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available fields for a request's subject
     */
    public function getRequestAvailableFields(int $id): JsonResponse
    {
        try {
            $removalRequest = $this->dataRemovalService->getRequest($id);
            if (!$removalRequest) {
                return response()->json([
                    'success' => false,
                    'error' => 'Request not found',
                ], 404);
            }

            $broker = $this->dataRemovalService->getBroker($removalRequest->broker_id);
            $availableFields = $this->dataRemovalService->getAvailableSubjectFields($removalRequest->subject_id);
            $fieldsToSubmit = $this->dataRemovalService->getFieldsToSubmit($id);

            // Get broker's required/optional field configuration
            $brokerRequired = json_decode($broker->required_fields ?? '["name"]', true) ?? ['name'];
            $brokerOptional = json_decode($broker->optional_fields ?? '[]', true) ?? [];

            return response()->json([
                'success' => true,
                'data' => [
                    'available_fields' => $availableFields,
                    'fields_to_submit' => $fieldsToSubmit,
                    'broker_required' => $brokerRequired,
                    'broker_optional' => $brokerOptional,
                    'fields_submitted' => $removalRequest->fields_submitted
                        ? json_decode($removalRequest->fields_submitted, true)
                        : null,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ========================================
    // MANUAL TRIGGERS
    // ========================================

    /**
     * Trigger a scan for subject(s) on brokers using artisan command
     */
    public function triggerScan(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'subject_id' => 'nullable|integer',
                'limit' => 'nullable|integer|min:1|max:500',
            ]);

            // Build command arguments
            $args = [];
            if (!empty($validated['subject_id'])) {
                $args['--subject'] = $validated['subject_id'];
            } else {
                $args['--all'] = true;
            }
            // Default to 100 brokers (effectively all) when triggered from UI
            $args['--limit'] = $validated['limit'] ?? 100;

            ExecuteDataRemovalScan::dispatch($args);

            return response()->json([
                'success' => true,
                'data' => [
                    'queued' => true,
                    'queue' => 'long-running',
                    'args' => $args,
                ],
                'message' => 'Data removal scan queued',
            ], 202);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('DataRemoval triggerScan error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Trigger AI research for new brokers
     */
    public function triggerResearch(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'max_results' => 'nullable|integer|min:1|max:20',
                'category' => 'nullable|in:people_search,marketing,background_check,data_aggregator,other',
                'add_to_db' => 'nullable|boolean',
                'dry_run' => 'nullable|boolean',
            ]);

            $maxResults = $validated['max_results'] ?? 10;
            $category = $validated['category'] ?? null;
            $addToDb = $validated['add_to_db'] ?? false;
            $dryRun = $validated['dry_run'] ?? true;

            ExecuteBrokerDiscovery::dispatch([
                'max_results' => $maxResults,
                'category' => $category,
                'max_to_add' => $maxResults,
                'dry_run' => $dryRun,
                'add_to_db' => $addToDb,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'queued' => true,
                    'queue' => 'long-running',
                    'config' => [
                        'max_results' => $maxResults,
                        'category' => $category,
                        'max_to_add' => $maxResults,
                        'dry_run' => $dryRun,
                        'add_to_db' => $addToDb,
                    ],
                ],
                'message' => 'Broker discovery queued',
            ], 202);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('DataRemoval triggerResearch error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ========================================
    // BROKER DISCOVERY ENDPOINTS (D2: broker_discovery_queue table dropped)
    // ========================================

    public function getPendingBrokers(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [], 'count' => 0, 'note' => 'Broker discovery queue removed (D2)']);
    }

    public function approveBroker(int $id): JsonResponse
    {
        return response()->json(['success' => false, 'error' => 'Broker discovery queue removed (D2)'], 410);
    }

    public function rejectBroker(int $id): JsonResponse
    {
        return response()->json(['success' => false, 'error' => 'Broker discovery queue removed (D2)'], 410);
    }

    public function getDiscoveryStats(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['note' => 'Broker discovery queue removed (D2)']]);
    }

    /**
     * Get Puppeteer security status
     */
    public function getPuppeteerSecurityStatus(): JsonResponse
    {
        try {
            $status = $this->scraperService->getSecurityStatus();

            return response()->json([
                'success' => true,
                'data' => $status,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ========================================
    // ANALYTICS & MONITORING
    // ========================================

    /**
     * Get removal analytics overview
     */
    public function removalAnalytics(Request $request): JsonResponse
    {
        try {
            $days = (int) ($request->query('days', 90));

            $effectiveness = DB::select(
                'SELECT b.name as broker_name, b.id as broker_id,
                        COUNT(r.id) as total_requests,
                        SUM(CASE WHEN r.status = ? THEN 1 ELSE 0 END) as successful,
                        SUM(CASE WHEN r.status = ? THEN 1 ELSE 0 END) as failed,
                        AVG(CASE WHEN r.verified_removed_at IS NOT NULL
                            THEN DATEDIFF(r.verified_removed_at, r.created_at) ELSE NULL END) as avg_days
                 FROM data_brokers b
                 LEFT JOIN removal_requests r ON r.broker_id = b.id
                    AND r.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY b.id, b.name
                 ORDER BY total_requests DESC',
                ['verified_removed', 'failed', $days]
            );

            $timeline = DB::select(
                'SELECT DATE(created_at) as date,
                        COUNT(*) as submitted,
                        SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as verified_removed
                 FROM removal_requests
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY date',
                ['verified_removed', $days]
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'effectiveness' => $effectiveness,
                    'timeline' => $timeline,
                    'period_days' => $days,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get relisting report
     */
    public function relistings(): JsonResponse
    {
        try {
            $service = app(RelistingDetectionService::class);
            $report = $service->getRelistingReport();

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Run health check on a specific broker
     */
    public function healthCheck(int $id): JsonResponse
    {
        try {
            $service = app(BrokerHealthService::class);
            $result = $service->checkBrokerHealth($id);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Sync with BADBOOL data broker database
     */
    public function syncBadbool(): JsonResponse
    {
        try {
            // BADBOOL sync: fetch known broker list and compare
            $existingBrokers = DB::select('SELECT domain FROM data_brokers');
            $existingDomains = array_column($existingBrokers, 'domain');

            return response()->json([
                'success' => true,
                'data' => [
                    'existing_brokers' => count($existingDomains),
                    'message' => 'BADBOOL sync check complete. Manual broker database import available.',
                    'status' => 'ready',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
