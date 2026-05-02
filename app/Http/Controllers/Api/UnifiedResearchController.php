<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteResearchMission;
use App\Services\Research\ResearchReviewService;
use App\Services\Research\UniversalResearchOrchestrator;
use App\Services\Research\DynamicSourceDiscoveryService;
use App\Services\RAGService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * UnifiedResearchController - Consolidated API for Research Topics & Missions
 *
 * Provides a unified interface for all research operations:
 * - Create/manage research items (one-time or recurring)
 * - Review queue for human approval of facts
 * - Deduplication tracking and statistics
 *
 * This replaces the separate ResearchTopicController and ResearchMissionController
 * endpoints with a single, unified API.
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class UnifiedResearchController extends Controller
{
    private ResearchReviewService $reviewService;
    private UniversalResearchOrchestrator $orchestrator;
    private DynamicSourceDiscoveryService $discoveryService;
    private string $connection = 'pgsql_rag';

    public function __construct(
        ResearchReviewService $reviewService,
        UniversalResearchOrchestrator $orchestrator,
        DynamicSourceDiscoveryService $discoveryService
    ) {
        $this->reviewService = $reviewService;
        $this->orchestrator = $orchestrator;
        $this->discoveryService = $discoveryService;
    }

    // =========================================================================
    // UNIFIED RESEARCH ITEMS (formerly Topics + Missions)
    // =========================================================================

    /**
     * List all research items (unified view of topics and missions)
     *
     * Returns both legacy topics and new missions in a unified format.
     * Filter by frequency, category, or status.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'frequency' => $request->input('frequency'), // once, daily, weekly, monthly, etc.
            'category' => $request->input('category'),
            'status' => $request->input('status'),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : null,
            'limit' => min((int) $request->input('limit', 50), 100),
            'offset' => (int) $request->input('offset', 0),
        ];

        $where = "WHERE 1=1";
        $params = [];

        if ($filters['frequency']) {
            $where .= " AND frequency = ?";
            $params[] = $filters['frequency'];
        }

        if ($filters['category']) {
            $where .= " AND (domain_category = ? OR rag_category = ?)";
            $params[] = $filters['category'];
            $params[] = $filters['category'];
        }

        if ($filters['status']) {
            $where .= " AND status = ?";
            $params[] = $filters['status'];
        }

        if ($filters['is_active'] !== null) {
            $where .= " AND is_active = ?";
            $params[] = $filters['is_active'];
        }

        $params[] = $filters['limit'];
        $params[] = $filters['offset'];

        $research = DB::connection($this->connection)->select("
            SELECT
                id,
                title,
                description,
                query_template,
                domain_category,
                rag_category,
                frequency,
                is_active,
                status,
                verification_level,
                depth_level,
                max_sources,
                last_ran_at,
                next_run_at,
                facts_discovered,
                facts_verified,
                created_at,
                updated_at
            FROM research_missions
            {$where}
            ORDER BY
                CASE WHEN is_active THEN 0 ELSE 1 END,
                CASE WHEN next_run_at IS NOT NULL AND next_run_at <= NOW() THEN 0 ELSE 1 END,
                created_at DESC
            LIMIT ? OFFSET ?
        ", $params);

        // Get pending facts count for each mission
        $researchWithCounts = array_map(function ($item) {
            $result = (array) $item;

            // Get pending facts count
            $pending = DB::connection($this->connection)->select("
                SELECT COUNT(*) as count FROM research_facts
                WHERE mission_id = ? AND review_status = 'pending'
            ", [$item->id]);
            $result['pending_facts_count'] = (int) ($pending[0]->count ?? 0);

            // Check if due
            $result['is_due'] = $item->frequency !== 'once' &&
                               $item->is_active &&
                               ($item->next_run_at === null || strtotime($item->next_run_at) <= time());

            return $result;
        }, $research);

        // Get total count
        $countParams = array_slice($params, 0, -2);
        $countWhere = str_replace("WHERE 1=1", "", $where);
        $total = DB::connection($this->connection)->select(
            "SELECT COUNT(*) as total FROM research_missions WHERE 1=1 {$countWhere}",
            $countParams
        );

        return response()->json([
            'success' => true,
            'research' => $researchWithCounts,
            'total' => (int) ($total[0]->total ?? 0),
            'limit' => $filters['limit'],
            'offset' => $filters['offset'],
        ]);
    }

    /**
     * Create a new research item (supports one-time and recurring)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'query' => 'required|string',
            'domain_category' => 'nullable|string|max:100',
            'rag_category' => 'nullable|string|max:100',
            'frequency' => 'nullable|string|in:once,daily,weekly,monthly,quarterly,biannually',
            'verification_level' => 'nullable|string|in:strict,standard,relaxed',
            'depth_level' => 'nullable|integer|min:1|max:10',
            'max_sources' => 'nullable|integer|min:1|max:200',
            'time_limit_minutes' => 'nullable|integer|min:5|max:120',
            'is_active' => 'nullable|boolean',
        ]);

        try {
            $missionId = $this->generateUuid();
            $frequency = $validated['frequency'] ?? 'once';

            // Calculate next_run_at for recurring research
            $nextRunAt = null;
            if ($frequency !== 'once') {
                $interval = match ($frequency) {
                    'daily' => '1 day',
                    'weekly' => '7 days',
                    'monthly' => '1 month',
                    'quarterly' => '3 months',
                    'biannually' => '6 months',
                    default => '1 day',
                };
                $nextRunAt = now()->toDateTimeString();
            }

            DB::connection($this->connection)->insert("
                INSERT INTO research_missions (
                    id, title, description, query_template, domain_category, rag_category,
                    frequency, verification_level, depth_level, max_sources, time_limit_minutes,
                    is_active, require_human_approval, next_run_at, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, true, ?, 'user')
            ", [
                $missionId,
                $validated['title'],
                $validated['description'] ?? null,
                $validated['query'],
                $validated['domain_category'] ?? 'general',
                $validated['rag_category'] ?? $validated['domain_category'] ?? 'general',
                $frequency,
                $validated['verification_level'] ?? 'standard',
                $validated['depth_level'] ?? 3,
                $validated['max_sources'] ?? 10,
                $validated['time_limit_minutes'] ?? 30,
                $validated['is_active'] ?? true,
                $nextRunAt,
            ]);

            Log::info('Unified research created', [
                'id' => $missionId,
                'title' => $validated['title'],
                'frequency' => $frequency,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Research created successfully',
                'id' => $missionId,
            ], 201);

        } catch (Exception $e) {
            Log::error('Failed to create research', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to create research: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single research item with full details
     */
    public function show(string $id): JsonResponse
    {
        $research = DB::connection($this->connection)->select("
            SELECT * FROM research_missions WHERE id = ?
        ", [$id]);

        if (empty($research)) {
            return response()->json(['success' => false, 'error' => 'Research not found'], 404);
        }

        $result = (array) $research[0];
        $result['constraints'] = json_decode($result['constraints'] ?? '{}', true);
        $result['phase_details'] = json_decode($result['phase_details'] ?? '{}', true);

        // Get related facts
        $facts = DB::connection($this->connection)->select("
            SELECT id, fact_statement, confidence_score, review_status, created_at
            FROM research_facts
            WHERE mission_id = ?
            ORDER BY created_at DESC
            LIMIT 20
        ", [$id]);
        $result['recent_facts'] = array_map(fn($f) => (array) $f, $facts);

        // Get pending count
        $pending = DB::connection($this->connection)->select("
            SELECT COUNT(*) as count FROM research_facts
            WHERE mission_id = ? AND review_status = 'pending'
        ", [$id]);
        $result['pending_facts_count'] = (int) ($pending[0]->count ?? 0);

        return response()->json(['success' => true, 'research' => $result]);
    }

    /**
     * Update a research item
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'query_template' => 'nullable|string',
            'domain_category' => 'nullable|string|max:100',
            'rag_category' => 'nullable|string|max:100',
            'frequency' => 'nullable|string|in:once,daily,weekly,monthly,quarterly,biannually',
            'verification_level' => 'nullable|string|in:strict,standard,relaxed',
            'depth_level' => 'nullable|integer|min:1|max:10',
            'max_sources' => 'nullable|integer|min:1|max:200',
            'time_limit_minutes' => 'nullable|integer|min:5|max:120',
            'is_active' => 'nullable|boolean',
        ]);

        // Check if research exists
        $existing = DB::connection($this->connection)->select("
            SELECT id FROM research_missions WHERE id = ?
        ", [$id]);

        if (empty($existing)) {
            return response()->json(['success' => false, 'error' => 'Research not found'], 404);
        }

        try {
            $sets = ['updated_at = CURRENT_TIMESTAMP'];
            $params = [];

            foreach ($validated as $field => $value) {
                if ($value !== null) {
                    $sets[] = "{$field} = ?";
                    $params[] = $value;
                }
            }

            $params[] = $id;

            DB::connection($this->connection)->update(
                "UPDATE research_missions SET " . implode(', ', $sets) . " WHERE id = ?",
                $params
            );

            return response()->json(['success' => true, 'message' => 'Research updated']);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update research: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a research item
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            DB::connection($this->connection)->delete("
                DELETE FROM research_missions WHERE id = ?
            ", [$id]);

            return response()->json(['success' => true, 'message' => 'Research deleted']);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete research: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle active status
     */
    public function toggle(string $id): JsonResponse
    {
        try {
            DB::connection($this->connection)->update("
                UPDATE research_missions
                SET is_active = NOT is_active, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ", [$id]);

            $updated = DB::connection($this->connection)->select("
                SELECT is_active FROM research_missions WHERE id = ?
            ", [$id]);

            return response()->json([
                'success' => true,
                'is_active' => (bool) ($updated[0]->is_active ?? false),
            ]);

        } catch (Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Run a research item manually
     */
    public function run(string $id): JsonResponse
    {
        $research = DB::connection($this->connection)->select("
            SELECT id, status FROM research_missions WHERE id = ?
        ", [$id]);

        if (empty($research)) {
            return response()->json(['success' => false, 'error' => 'Research not found'], 404);
        }

        if ($research[0]->status === 'active') {
            return response()->json(['success' => false, 'error' => 'Research is already running'], 400);
        }

        ExecuteResearchMission::dispatch($id);

        return response()->json([
            'success' => true,
            'status' => 'queued',
            'message' => 'Research mission queued for execution',
            'id' => $id,
        ], 202);
    }

    // =========================================================================
    // REVIEW QUEUE (Facts pending human approval)
    // =========================================================================

    /**
     * Get pending facts for review with rich context
     *
     * Shows confidence %, source count, verification breakdown
     */
    public function pendingFacts(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 50), 100);
        $offset = (int) $request->input('offset', 0);
        $missionId = $request->input('mission_id');

        $result = $this->reviewService->getPendingFacts($limit, $offset, $missionId);
        $result['stats'] = $this->reviewService->getReviewQueueStats();

        return response()->json(array_merge(['success' => true], $result));
    }

    /**
     * Approve a fact - index to RAG
     */
    public function approveFact(string $factId): JsonResponse
    {
        $result = $this->reviewService->approveFact($factId);

        $statusCode = $result['success'] ? 200 : ($result['error'] === 'Fact not found' ? 404 : 400);
        return response()->json($result, $statusCode);
    }

    /**
     * Reject a fact - add to rejection tracking for deduplication
     */
    public function rejectFact(Request $request, string $factId): JsonResponse
    {
        $reason = $request->input('reason');
        $result = $this->reviewService->rejectFact($factId, $reason);

        $statusCode = $result['success'] ? 200 : ($result['error'] === 'Fact not found' ? 404 : 400);
        return response()->json($result, $statusCode);
    }

    // =========================================================================
    // STATISTICS & DEDUPLICATION TRACKING
    // =========================================================================

    /**
     * Get comprehensive research statistics
     *
     * Includes deduplication metrics for recurring research
     */
    public function stats(): JsonResponse
    {
        // Research counts
        $researchStats = DB::connection($this->connection)->select("
            SELECT
                COUNT(*) as total_research,
                COUNT(*) FILTER (WHERE frequency = 'once') as one_time,
                COUNT(*) FILTER (WHERE frequency != 'once') as recurring,
                COUNT(*) FILTER (WHERE is_active = true) as active,
                COUNT(*) FILTER (WHERE status = 'completed') as completed,
                COUNT(*) FILTER (WHERE status = 'failed') as failed
            FROM research_missions
        ");

        // Fact counts
        $factStats = DB::connection($this->connection)->select("
            SELECT
                COUNT(*) as total_facts,
                COUNT(*) FILTER (WHERE review_status = 'pending') as pending,
                COUNT(*) FILTER (WHERE review_status = 'approved') as approved,
                COUNT(*) FILTER (WHERE review_status = 'rejected') as rejected,
                COUNT(*) FILTER (WHERE review_status = 'auto_skipped') as auto_skipped
            FROM research_facts
        ");

        // Deduplication stats
        $deduplicationStats = $this->reviewService->getDeduplicationStats();

        // Review queue stats
        $reviewStats = $this->reviewService->getReviewQueueStats();

        // Last 24 hours activity
        $recent = DB::connection($this->connection)->select("
            SELECT
                COUNT(*) FILTER (WHERE created_at >= NOW() - INTERVAL '24 hours') as facts_extracted,
                COUNT(*) FILTER (WHERE review_status = 'auto_skipped' AND created_at >= NOW() - INTERVAL '24 hours') as facts_deduplicated,
                COUNT(*) FILTER (WHERE review_status = 'pending' AND created_at >= NOW() - INTERVAL '24 hours') as facts_for_review
            FROM research_facts
        ");

        return response()->json([
            'success' => true,
            'research' => [
                'total' => (int) ($researchStats[0]->total_research ?? 0),
                'one_time' => (int) ($researchStats[0]->one_time ?? 0),
                'recurring' => (int) ($researchStats[0]->recurring ?? 0),
                'active' => (int) ($researchStats[0]->active ?? 0),
                'completed' => (int) ($researchStats[0]->completed ?? 0),
                'failed' => (int) ($researchStats[0]->failed ?? 0),
            ],
            'facts' => [
                'total' => (int) ($factStats[0]->total_facts ?? 0),
                'pending' => (int) ($factStats[0]->pending ?? 0),
                'approved' => (int) ($factStats[0]->approved ?? 0),
                'rejected' => (int) ($factStats[0]->rejected ?? 0),
                'auto_skipped' => (int) ($factStats[0]->auto_skipped ?? 0),
            ],
            'deduplication' => $deduplicationStats,
            'review_queue' => $reviewStats,
            'last_24h' => [
                'facts_extracted' => (int) ($recent[0]->facts_extracted ?? 0),
                'facts_deduplicated' => (int) ($recent[0]->facts_deduplicated ?? 0),
                'facts_for_review' => (int) ($recent[0]->facts_for_review ?? 0),
            ],
        ]);
    }

    /**
     * Get rejected facts for audit trail
     */
    public function rejectedFacts(Request $request): JsonResponse
    {
        $limit = min((int) $request->input('limit', 50), 100);
        $offset = (int) $request->input('offset', 0);

        $result = $this->reviewService->getRejectedFacts($limit, $offset);
        return response()->json(array_merge(['success' => true], $result));
    }

    /**
     * Remove a fact from rejection tracking (allow re-evaluation)
     */
    public function unrejectFact(Request $request): JsonResponse
    {
        $factHash = $request->input('fact_hash');
        if (!$factHash) {
            return response()->json(['success' => false, 'error' => 'fact_hash required'], 400);
        }

        $result = $this->reviewService->unrejectFact($factHash);
        return response()->json($result);
    }

    // =========================================================================
    // CATEGORIES
    // =========================================================================

    /**
     * Get available domain categories
     */
    public function categories(): JsonResponse
    {
        $categories = [
            'general' => 'General',
            'academic' => 'Academic',
            'archive' => 'Archive',
            'finance' => 'Finance',
            'genealogy' => 'Genealogy',
            'government' => 'Government',
            'health' => 'Health',
            'legal' => 'Legal',
            'medical' => 'Medical',
            'news' => 'News',
            'reference' => 'Reference',
            'science' => 'Science',
            'technology' => 'Technology',
        ];

        return response()->json([
            'success' => true,
            'categories' => $categories,
        ]);
    }

    /**
     * Get available RAG categories (from existing documents)
     */
    public function ragCategories(): JsonResponse
    {
        // Get categories from existing RAG documents
        $existing = DB::connection($this->connection)->select("
            SELECT DISTINCT designation as category
            FROM rag_documents
            WHERE designation IS NOT NULL AND designation != ''
            ORDER BY designation
        ");

        $categories = [];
        foreach ($existing as $cat) {
            $key = strtolower(str_replace(' ', '_', $cat->category));
            $categories[$key] = ucfirst(str_replace('_', ' ', $cat->category));
        }

        // Add defaults if not present
        $defaults = ['general', 'research', 'genealogy', 'health', 'finance', 'news', 'technology'];
        foreach ($defaults as $default) {
            if (!isset($categories[$default])) {
                $categories[$default] = ucfirst($default);
            }
        }

        ksort($categories);

        return response()->json([
            'success' => true,
            'categories' => $categories,
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function generateUuid(): string
    {
        $result = DB::connection($this->connection)->select("SELECT gen_random_uuid() as uuid");
        return $result[0]->uuid;
    }
}
