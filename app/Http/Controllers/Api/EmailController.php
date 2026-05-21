<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteEmailSuggestionScan;
use App\Services\EmailService;
use App\Services\EmailSuggestionService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Email API Controller (EA2)
 *
 * Provides REST API for email operations:
 * - Status and statistics
 * - Email reading (folders, mailboxes, search, recent)
 * - Draft queue management (list, approve, reject, update)
 * - Classification operations
 * - Settings management
 *
 * All endpoints return JSON with consistent error handling.
 * Thunderbird MCP is the email backend (D1 decision).
 */
class EmailController extends Controller
{
    private EmailService $emailService;

    private EmailSuggestionService $suggestionService;

    public function __construct(
        EmailService $emailService,
        EmailSuggestionService $suggestionService
    ) {
        $this->emailService = $emailService;
        $this->suggestionService = $suggestionService;
    }

    // =========================================================================
    // STATUS & DIAGNOSTICS
    // =========================================================================

    /**
     * Get email service status
     *
     * GET /api/email/status
     */
    public function status(): JsonResponse
    {
        try {
            $status = $this->emailService->getStatus();

            return response()->json([
                'success' => true,
                'data' => $status,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get status', $e);
        }
    }

    /**
     * Get email statistics
     *
     * GET /api/email/stats
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = $this->emailService->getStats();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get statistics', $e);
        }
    }

    // =========================================================================
    // EMAIL READING
    // =========================================================================

    /**
     * List available folders
     *
     * GET /api/email/folders
     */
    public function folders(): JsonResponse
    {
        try {
            if (! $this->emailService->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Thunderbird MCP not available',
                    'available' => false,
                ], 503);
            }

            $folders = $this->emailService->listFolders();

            return response()->json([
                'success' => true,
                'data' => $folders,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to list folders', $e);
        }
    }

    /**
     * List configured mailboxes
     *
     * GET /api/email/mailboxes
     */
    public function mailboxes(): JsonResponse
    {
        try {
            if (! $this->emailService->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Thunderbird MCP not available',
                    'available' => false,
                ], 503);
            }

            $mailboxes = $this->emailService->listMailboxes();

            return response()->json([
                'success' => true,
                'data' => $mailboxes,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to list mailboxes', $e);
        }
    }

    /**
     * Search emails
     *
     * GET /api/email/search?query=...&folder=...
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $query = $request->get('query');

            if (empty($query)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Query parameter is required',
                ], 400);
            }

            if (! $this->emailService->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Thunderbird MCP not available',
                ], 503);
            }

            $folder = $request->get('folder');
            $emails = $this->emailService->searchEmails($query, $folder);

            return response()->json([
                'success' => true,
                'query' => $query,
                'folder' => $folder,
                'count' => count($emails),
                'data' => $emails,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Search failed', $e);
        }
    }

    /**
     * Get recent emails from a folder
     *
     * GET /api/email/recent?folder=Inbox&limit=10
     */
    public function recent(Request $request): JsonResponse
    {
        try {
            if (! $this->emailService->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Thunderbird MCP not available',
                ], 503);
            }

            $folder = $request->get('folder', 'Inbox');
            $limit = min((int) $request->get('limit', 10), 50);

            $emails = $this->emailService->getRecentEmails($folder, $limit);

            return response()->json([
                'success' => true,
                'folder' => $folder,
                'count' => count($emails),
                'data' => $emails,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get recent emails', $e);
        }
    }

    // =========================================================================
    // DRAFT QUEUE MANAGEMENT
    // =========================================================================

    /**
     * List pending drafts
     *
     * GET /api/email/queue?source=...&priority=...&limit=...
     */
    public function queue(Request $request): JsonResponse
    {
        try {
            $filters = [
                'source' => $request->get('source'),
                'priority' => $request->get('priority'),
                'limit' => min((int) $request->get('limit', 50), 100),
            ];

            $drafts = $this->emailService->getPendingDrafts($filters);
            $stats = $this->emailService->getDraftQueueStats();

            return response()->json([
                'success' => true,
                'count' => count($drafts),
                'stats' => $stats,
                'data' => $drafts,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get draft queue', $e);
        }
    }

    /**
     * Get a specific draft
     *
     * GET /api/email/queue/{id}
     */
    public function getDraft(int $id): JsonResponse
    {
        try {
            $draft = $this->emailService->getDraft($id);

            if (! $draft) {
                return response()->json([
                    'success' => false,
                    'error' => "Draft not found: {$id}",
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $draft,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get draft', $e);
        }
    }

    /**
     * Update a draft
     *
     * PUT /api/email/queue/{id}
     */
    public function updateDraft(Request $request, int $id): JsonResponse
    {
        try {
            $draft = $this->emailService->getDraft($id);

            if (! $draft) {
                return response()->json([
                    'success' => false,
                    'error' => "Draft not found: {$id}",
                ], 404);
            }

            if ($draft->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'error' => "Cannot update draft with status: {$draft->status}",
                ], 400);
            }

            $updates = $request->only(['to', 'from_address', 'cc', 'bcc', 'subject', 'body', 'priority']);

            if (empty($updates)) {
                return response()->json([
                    'success' => false,
                    'error' => 'No valid fields to update',
                ], 400);
            }

            $success = $this->emailService->updateDraft($id, $updates);

            return response()->json([
                'success' => $success,
                'message' => $success ? 'Draft updated' : 'Update failed',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update draft', $e);
        }
    }

    /**
     * Approve and send a draft
     *
     * POST /api/email/queue/{id}/approve
     */
    public function approveDraft(int $id): JsonResponse
    {
        try {
            $result = $this->emailService->approveDraft($id);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to approve draft', $e);
        }
    }

    /**
     * Reject a draft
     *
     * POST /api/email/queue/{id}/reject
     */
    public function rejectDraft(Request $request, int $id): JsonResponse
    {
        try {
            $reason = $request->get('reason');
            $success = $this->emailService->rejectDraft($id, $reason);

            if (! $success) {
                return response()->json([
                    'success' => false,
                    'error' => 'Draft not found or already processed',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Draft rejected',
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to reject draft', $e);
        }
    }

    /**
     * Create a new draft
     *
     * POST /api/email/queue
     */
    public function createDraft(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'to' => 'required|email',
                'subject' => 'required|string',
                'body' => 'required|string',
                'source' => 'string',
                'priority' => 'string|in:low,normal,high,urgent',
            ]);

            $email = [
                'to' => $request->get('to'),
                'subject' => $request->get('subject'),
                'body' => $request->get('body'),
                'from' => $request->get('from'),
                'cc' => $request->get('cc'),
                'bcc' => $request->get('bcc'),
            ];

            $options = [
                'priority' => $request->get('priority', 'normal'),
            ];

            $source = $request->get('source', 'workflow');
            $draftId = $this->emailService->queueDraft($email, $source, $options);

            return response()->json([
                'success' => true,
                'draft_id' => $draftId,
                'message' => 'Draft queued for approval',
            ], 201);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create draft', $e);
        }
    }

    // =========================================================================
    // CLASSIFICATION
    // =========================================================================

    /**
     * Classify email(s)
     *
     * POST /api/email/classify
     */
    public function classify(Request $request): JsonResponse
    {
        try {
            // Can classify a single email or batch from folder
            if ($request->has('email')) {
                $email = $request->get('email');
                $result = $this->emailService->classifyEmail($email);

                return response()->json([
                    'success' => $result['success'],
                    'data' => $result,
                ]);
            }

            if ($request->has('folder')) {
                $folder = $request->get('folder', 'Inbox');
                $limit = min((int) $request->get('limit', 10), 20);

                $result = $this->emailService->classifyRecentEmails($folder, $limit);

                return response()->json([
                    'success' => true,
                    'data' => $result,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Provide either "email" object or "folder" parameter',
            ], 400);
        } catch (Exception $e) {
            return $this->errorResponse('Classification failed', $e);
        }
    }

    /**
     * Get classification statistics
     *
     * GET /api/email/classification/stats
     */
    public function classificationStats(): JsonResponse
    {
        try {
            $stats = $this->emailService->getClassificationStats();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get classification stats', $e);
        }
    }

    // =========================================================================
    // SETTINGS
    // =========================================================================

    /**
     * Get email settings
     *
     * GET /api/email/settings
     */
    public function settings(): JsonResponse
    {
        try {
            $settings = $this->emailService->getSettings();

            return response()->json([
                'success' => true,
                'data' => $settings,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get settings', $e);
        }
    }

    /**
     * Update email settings
     *
     * PUT /api/email/settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        try {
            $allowedKeys = [
                'monitored_folders',
                'default_mailbox',
                'auto_classify',
                'classification_batch_size',
            ];

            $updates = $request->only($allowedKeys);

            foreach ($updates as $key => $value) {
                $this->emailService->saveSetting($key, $value);
            }

            return response()->json([
                'success' => true,
                'message' => 'Settings updated',
                'data' => $this->emailService->getSettings(),
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update settings', $e);
        }
    }

    // =========================================================================
    // CIRCUIT BREAKER
    // =========================================================================

    /**
     * Reset Thunderbird circuit breaker
     *
     * POST /api/email/reset-circuit
     */
    public function resetCircuit(): JsonResponse
    {
        try {
            $this->emailService->resetThunderbirdCircuit();

            return response()->json([
                'success' => true,
                'message' => 'Thunderbird circuit breaker reset',
                'status' => $this->emailService->getStatus(),
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to reset circuit', $e);
        }
    }

    // =========================================================================
    // SHIPMENT TRACKING COMPATIBILITY
    // =========================================================================

    /**
     * Shipment tracking storage was removed in D1. Keep a stable empty API
     * contract so legacy widgets degrade without 404 console noise.
     *
     * GET /api/email/v2/shipments
     */
    public function shipments(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [],
            'count' => 0,
            'disabled' => true,
            'unavailable_reason' => 'shipment_tracking_removed_d1_thunderbird_mcp',
        ]);
    }

    /**
     * GET /api/email/v2/shipments/stats
     */
    public function shipmentStats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'active' => 0,
                'delivered' => 0,
                'archived' => 0,
                'disabled' => true,
                'unavailable_reason' => 'shipment_tracking_removed_d1_thunderbird_mcp',
            ],
        ]);
    }

    /**
     * POST /api/email/v2/shipments/scan
     */
    public function scanShipments(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'queued' => false,
                'scanned' => 0,
                'new' => 0,
                'updated' => 0,
                'disabled' => true,
                'unavailable_reason' => 'shipment_tracking_removed_d1_thunderbird_mcp',
            ],
            'message' => 'Shipment tracking is disabled; Thunderbird MCP is the email backend.',
        ]);
    }

    /**
     * POST /api/email/v2/shipments/{id}/received
     */
    public function markShipmentReceived(int $id): JsonResponse
    {
        return $this->shipmentWriteDisabledResponse($id);
    }

    /**
     * POST /api/email/v2/shipments/{id}/archive
     */
    public function archiveShipment(int $id): JsonResponse
    {
        return $this->shipmentWriteDisabledResponse($id);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Standard error response
     */
    private function errorResponse(string $message, Exception $e, int $status = 500): JsonResponse
    {
        $response = [
            'success' => false,
            'error' => $message,
            'details' => $e->getMessage(),
        ];

        // Add errors from service if available
        $errors = $this->emailService->getErrors();
        if (! empty($errors)) {
            $response['service_errors'] = $errors;
        }

        return response()->json($response, $status);
    }

    private function shipmentWriteDisabledResponse(int $id): JsonResponse
    {
        return response()->json([
            'success' => false,
            'id' => $id,
            'disabled' => true,
            'unavailable_reason' => 'shipment_tracking_removed_d1_thunderbird_mcp',
            'message' => 'Shipment tracking is disabled; Thunderbird MCP is the email backend.',
        ]);
    }

    // =========================================================================
    // EA2: AI SUGGESTIONS (contacts, calendar, bills)
    // =========================================================================

    /**
     * Get pending suggestions
     *
     * GET /api/email/v2/suggestions
     */
    public function suggestions(Request $request): JsonResponse
    {
        try {
            $type = $request->input('type'); // contact, calendar, bill
            $suggestions = $this->suggestionService->getPendingSuggestions($type);

            return response()->json([
                'success' => true,
                'data' => $suggestions,
                'count' => count($suggestions),
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to fetch suggestions', $e);
        }
    }

    /**
     * Get suggestion statistics
     *
     * GET /api/email/v2/suggestions/stats
     */
    public function suggestionStats(): JsonResponse
    {
        try {
            $stats = $this->suggestionService->getStats();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to fetch suggestion stats', $e);
        }
    }

    /**
     * Scan emails for suggestions
     *
     * POST /api/email/v2/suggestions/scan
     */
    public function scanSuggestions(Request $request): JsonResponse
    {
        try {
            $folder = $request->input('folder', 'Inbox');
            $limit = (int) $request->input('limit', 50);

            ExecuteEmailSuggestionScan::dispatch($folder, $limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'queued' => true,
                    'queue' => 'long-running',
                    'folder' => $folder,
                    'limit' => $limit,
                ],
                'message' => 'Suggestion scan queued',
            ], 202);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to scan for suggestions', $e);
        }
    }

    /**
     * Approve a suggestion (execute the action)
     *
     * POST /api/email/v2/suggestions/{id}/approve
     */
    public function approveSuggestion(int $id): JsonResponse
    {
        try {
            $result = $this->suggestionService->approveSuggestion($id);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $result['message'] ?? 'Suggestion approved',
                    'type' => $result['type'] ?? null,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to approve suggestion',
            ], 400);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to approve suggestion', $e);
        }
    }

    /**
     * Reject a suggestion
     *
     * POST /api/email/v2/suggestions/{id}/reject
     */
    public function rejectSuggestion(Request $request, int $id): JsonResponse
    {
        try {
            $reason = $request->input('reason');
            $result = $this->suggestionService->rejectSuggestion($id, $reason);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'Suggestion rejected',
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Failed to reject suggestion',
            ], 400);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to reject suggestion', $e);
        }
    }

    /**
     * Get suggestion notification settings
     *
     * GET /api/email/v2/suggestions/settings
     */
    public function suggestionSettings(): JsonResponse
    {
        try {
            $settings = $this->suggestionService->getAllSettings();

            return response()->json([
                'success' => true,
                'data' => $settings,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to fetch suggestion settings', $e);
        }
    }

    /**
     * Update suggestion notification settings
     *
     * PUT /api/email/v2/suggestions/settings
     */
    public function updateSuggestionSettings(Request $request): JsonResponse
    {
        try {
            $settings = $request->all();
            $result = $this->suggestionService->updateSettings($settings);

            return response()->json([
                'success' => true,
                'message' => 'Settings updated',
                'data' => $result,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update suggestion settings', $e);
        }
    }

    // Sentiment, unsubscribe, follow-up sections removed (D1: Thunderbird MCP handles these)
    // ~390 lines of dead controller methods removed 2026-03-16

    // =========================================================================
    // EMAIL ANALYTICS
    // =========================================================================

    /**
     * Get email analytics overview
     *
     * GET /api/email/v2/analytics
     */
    public function emailAnalytics(Request $request): JsonResponse
    {
        try {
            $days = (int) ($request->query('days', 30));

            $volumeByDay = DB::select(
                'SELECT DATE(created_at) as date, COUNT(*) as count
                 FROM email_reply_drafts
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY date',
                [$days]
            );

            $statusBreakdown = DB::select(
                'SELECT status, COUNT(*) as count
                 FROM email_reply_drafts
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY status',
                [$days]
            );

            $topRecipients = DB::select(
                'SELECT `to` as recipient_email, COUNT(*) as count
                 FROM email_reply_drafts
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY `to`
                 ORDER BY count DESC
                 LIMIT 10',
                [$days]
            );

            $approvalRate = DB::select(
                'SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected
                 FROM email_reply_drafts
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)',
                ['approved', 'rejected', $days]
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'volume_by_day' => $volumeByDay,
                    'status_breakdown' => $statusBreakdown,
                    'top_recipients' => $topRecipients,
                    'approval_rate' => $approvalRate[0] ?? null,
                    'period_days' => $days,
                ],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to fetch email analytics', $e);
        }
    }
}
