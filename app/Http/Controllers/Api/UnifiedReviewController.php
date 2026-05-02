<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UnifiedReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UnifiedReviewController - Single API for all human review operations
 *
 * Provides consistent endpoints for:
 * - Listing pending items across all sources
 * - Approving/rejecting items by unified ID
 * - Batch operations
 * - Stats and filtering
 *
 * Routes should be registered under /api/reviews/*
 */
class UnifiedReviewController extends Controller
{
    private UnifiedReviewService $reviewService;

    public function __construct(UnifiedReviewService $reviewService)
    {
        $this->reviewService = $reviewService;
    }

    /**
     * GET /api/reviews
     *
     * List all pending review items with optional filtering
     */
    public function index(Request $request): JsonResponse
    {
        $category = $request->query('category'); // agent, research, genealogy, privacy, files
        $search = $request->query('search');
        $sortBy = $request->query('sort', 'priority');
        $sortDir = $request->query('dir', 'desc');
        $limit = min((int) $request->query('limit', 50), 200);
        $offset = max((int) $request->query('offset', 0), 0);
        $includeExpired = filter_var($request->query('include_expired', false), FILTER_VALIDATE_BOOLEAN);

        $result = $this->reviewService->getPendingItems(
            $category,
            $search,
            $sortBy,
            $sortDir,
            $limit,
            $offset,
            $includeExpired
        );

        return response()->json([
            'success' => true,
            'items' => $result['items'],
            'total' => $result['total'],
            'has_more' => count($result['items']) >= $limit,
            'stats' => $result['stats'],
            'categories' => UnifiedReviewService::CATEGORIES,
        ]);
    }

    /**
     * GET /api/reviews/stats
     *
     * Quick stats without fetching all items
     */
    public function stats(): JsonResponse
    {
        $stats = $this->reviewService->getStats();

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'categories' => UnifiedReviewService::CATEGORIES,
        ]);
    }

    /**
     * GET /api/reviews/{unifiedId}
     *
     * Get single item details
     */
    public function show(string $unifiedId): JsonResponse
    {
        $item = $this->reviewService->getItem($unifiedId);

        if (!$item) {
            return response()->json([
                'success' => false,
                'error' => 'Item not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'item' => $item,
        ]);
    }

    /**
     * POST /api/reviews/{unifiedId}/approve
     *
     * Approve a single item
     */
    public function approve(Request $request, string $unifiedId): JsonResponse
    {
        $notes = $request->input('notes');

        $result = $this->reviewService->approveItem($unifiedId, $notes);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * POST /api/reviews/{unifiedId}/reject
     *
     * Reject a single item
     */
    public function reject(Request $request, string $unifiedId): JsonResponse
    {
        $reason = $request->input('reason');

        $result = $this->reviewService->rejectItem($unifiedId, $reason);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * POST /api/reviews/batch/approve
     *
     * Batch approve multiple items
     */
    public function batchApprove(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'required|string',
            'notes' => 'nullable|string|max:1000',
        ]);

        $ids = $request->input('ids');
        $notes = $request->input('notes');

        $result = $this->reviewService->batchApprove($ids, $notes);

        return response()->json([
            'success' => true,
            'approved' => $result['success'],
            'failed' => $result['failed'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * POST /api/reviews/batch/reject
     *
     * Batch reject multiple items
     */
    public function batchReject(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'required|string',
            'reason' => 'nullable|string|max:1000',
        ]);

        $ids = $request->input('ids');
        $reason = $request->input('reason');

        $result = $this->reviewService->batchReject($ids, $reason);

        return response()->json([
            'success' => true,
            'rejected' => $result['success'],
            'failed' => $result['failed'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * GET /api/reviews/quick/{unifiedId}
     *
     * Quick action for Pushover/mobile - returns HTML confirmation
     */
    public function quickAction(Request $request, string $unifiedId): \Illuminate\Http\Response
    {
        $action = $request->query('action', 'view');

        if ($action === 'view') {
            $item = $this->reviewService->getItem($unifiedId);
            if (!$item) {
                return response("<html><body style='font-family:system-ui;padding:20px;'><h2>Item Not Found</h2></body></html>", 404);
            }

            $safeTitle = htmlspecialchars($item['title'] ?? '', ENT_QUOTES, 'UTF-8');
            $safeSummary = htmlspecialchars($item['summary'] ?? '', ENT_QUOTES, 'UTF-8');
            return response("<html><body style='font-family:system-ui;padding:20px;background:#1a1a2e;color:#eee;'>
                <h2 style='color:#f59e0b;'>{$safeTitle}</h2>
                <p>{$safeSummary}</p>
                <div style='margin-top:20px;'>
                    <a href='?action=approve' style='display:inline-block;padding:12px 24px;background:#22c55e;color:white;text-decoration:none;border-radius:8px;margin-right:10px;'>Approve</a>
                    <a href='?action=reject' style='display:inline-block;padding:12px 24px;background:#ef4444;color:white;text-decoration:none;border-radius:8px;'>Reject</a>
                </div>
            </body></html>");
        }

        $result = $action === 'approve'
            ? $this->reviewService->approveItem($unifiedId)
            : $this->reviewService->rejectItem($unifiedId);

        $statusColor = $result['success'] ? '#22c55e' : '#ef4444';
        $statusText = $result['success']
            ? ($action === 'approve' ? 'Approved' : 'Rejected')
            : 'Error: ' . htmlspecialchars($result['error'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');

        return response("<html><body style='font-family:system-ui;padding:20px;background:#1a1a2e;color:#eee;text-align:center;'>
            <h2 style='color:{$statusColor};'>{$statusText}</h2>
            <p style='color:#888;'>You can close this window.</p>
        </body></html>");
    }
}
