<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteResearchMaintenance;
use App\Jobs\ExecuteResearchCategoryRefresh;
use App\Services\Research\ResearchMissionService;
use App\Services\Research\DynamicSourceDiscoveryService;
use App\Services\Research\SourceOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ResearchMissionController - API for Universal Research Framework
 *
 * Handles research missions, source discovery, and fact verification.
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class ResearchMissionController extends Controller
{
    private ResearchMissionService $missionService;
    private DynamicSourceDiscoveryService $discoveryService;
    private SourceOptimizationService $optimizationService;
    private string $connection = 'pgsql_rag';

    public function __construct(
        ResearchMissionService $missionService,
        DynamicSourceDiscoveryService $discoveryService,
        SourceOptimizationService $optimizationService
    ) {
        $this->missionService = $missionService;
        $this->discoveryService = $discoveryService;
        $this->optimizationService = $optimizationService;
    }

    /**
     * List discovered sources
     */
    public function sources(Request $request): JsonResponse
    {
        $category = $request->input('category');
        $specialization = $request->input('specialization');
        $limit = min((int)$request->input('limit', 50), 100);

        if ($specialization) {
            $sources = $this->discoveryService->findSpecializedSources($specialization, $limit);
        } else {
            $where = "WHERE is_active = true AND is_blacklisted = false";
            $params = [];

            if ($category) {
                $where .= " AND domain_category = ?";
                $params[] = $category;
            }

            $params[] = $limit;

            $sources = DB::connection($this->connection)->select("
                SELECT
                    id, domain, display_name, source_type, domain_category,
                    specializations, safety_score, trust_score, is_whitelisted,
                    success_count, failure_count, avg_response_ms,
                    discovered_by, created_at
                FROM discovered_sources
                {$where}
                ORDER BY trust_score DESC, success_count DESC
                LIMIT ?
            ", $params);

            $sources = array_map(function ($s) {
                $source = (array)$s;
                $source['specializations'] = json_decode($source['specializations'] ?? '[]', true);
                return $source;
            }, $sources);
        }

        return response()->json([
            'success' => true,
            'sources' => $sources,
            'count' => count($sources),
        ]);
    }

    /**
     * Add a source manually
     */
    public function addSource(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'domain' => 'required|string|max:255',
            'url' => 'nullable|url',
            'display_name' => 'nullable|string|max:255',
            'source_type' => 'nullable|string|in:api,webpage,database,archive,wiki,news',
            'domain_category' => 'nullable|string|max:100',
            'specializations' => 'nullable|array',
            'trust_score' => 'nullable|numeric|min:0|max:1',
        ]);

        // Evaluate safety
        $evaluation = $this->discoveryService->evaluateSourceSafety(
            $validated['domain'],
            $validated['url'] ?? "https://{$validated['domain']}"
        );

        if ($evaluation['is_blacklisted']) {
            return response()->json([
                'success' => false,
                'error' => 'Domain is blacklisted',
            ], 400);
        }

        $sourceId = $this->discoveryService->registerSource([
            'domain' => $validated['domain'],
            'full_url' => $validated['url'] ?? "https://{$validated['domain']}",
            'display_name' => $validated['display_name'] ?? $validated['domain'],
            'source_type' => $validated['source_type'] ?? 'webpage',
            'domain_category' => $validated['domain_category'] ?? $evaluation['domain_category'],
            'specializations' => $validated['specializations'] ?? [],
            'safety_score' => $evaluation['safety_score'],
            'trust_score' => $validated['trust_score'] ?? $evaluation['trust_score'],
            'safety_evaluation' => $evaluation,
            'is_whitelisted' => $evaluation['is_whitelisted'],
            'requires_sandbox' => $evaluation['requires_sandbox'],
            'discovery_context' => 'Manually added via API',
        ]);

        if (!$sourceId) {
            return response()->json(['success' => false, 'error' => 'Failed to register source'], 500);
        }

        return response()->json([
            'success' => true,
            'source_id' => $sourceId,
            'evaluation' => $evaluation,
        ], 201);
    }

    /**
     * Discover sources for a topic
     */
    public function discoverSources(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'topic' => 'required|string',
            'category' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $result = $this->discoveryService->discoverSourcesForTopic(
            $validated['topic'],
            $validated['category'] ?? 'general',
            $validated['limit'] ?? 10
        );

        return response()->json($result);
    }

    // =========================================================================
    // DISCOVERY RULES MANAGEMENT
    // =========================================================================

    /**
     * List all discovery rules
     */
    public function listRules(Request $request): JsonResponse
    {
        $ruleType = $request->input('type');
        $includeInactive = $request->boolean('include_inactive', false);

        $rules = $this->discoveryService->getRules($ruleType, $includeInactive);

        return response()->json([
            'success' => true,
            'rules' => $rules,
            'count' => count($rules),
        ]);
    }

    /**
     * Get a specific rule
     */
    public function showRule(string $id): JsonResponse
    {
        $rule = DB::connection($this->connection)->select("
            SELECT * FROM discovery_rules WHERE id = ?
        ", [$id]);

        if (empty($rule)) {
            return response()->json(['success' => false, 'error' => 'Rule not found'], 404);
        }

        $ruleData = (array)$rule[0];
        $ruleData['suggested_specializations'] = json_decode($ruleData['suggested_specializations'] ?? '[]', true);
        $ruleData['suggested_content_types'] = json_decode($ruleData['suggested_content_types'] ?? '[]', true);

        return response()->json(['success' => true, 'rule' => $ruleData]);
    }

    /**
     * Create a new discovery rule
     */
    public function createRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rule_name' => 'required|string|max:255',
            'rule_type' => 'required|string|in:tld_trust,whitelist_pattern,blacklist_pattern,category_domain,category_pattern,safety_modifier',
            'match_pattern' => 'required|string',
            'pattern_type' => 'nullable|string|in:exact,regex,suffix,prefix,contains',
            'trust_score_value' => 'nullable|numeric|min:0|max:1',
            'trust_score_multiplier' => 'nullable|numeric|min:0|max:2',
            'safety_score_adjustment' => 'nullable|numeric|min:-1|max:1',
            'domain_category' => 'nullable|string|max:100',
            'suggested_specializations' => 'nullable|array',
            'suggested_content_types' => 'nullable|array',
            'auto_whitelist' => 'nullable|boolean',
            'auto_blacklist' => 'nullable|boolean',
            'requires_verification' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:1|max:1000',
            'notes' => 'nullable|string',
        ]);

        $ruleId = $this->discoveryService->addRule(array_merge($validated, [
            'created_by' => 'api',
        ]));

        if (!$ruleId) {
            return response()->json(['success' => false, 'error' => 'Failed to create rule'], 500);
        }

        return response()->json([
            'success' => true,
            'rule_id' => $ruleId,
        ], 201);
    }

    /**
     * Update a discovery rule
     */
    public function updateRule(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'rule_name' => 'nullable|string|max:255',
            'match_pattern' => 'nullable|string',
            'pattern_type' => 'nullable|string|in:exact,regex,suffix,prefix,contains',
            'trust_score_value' => 'nullable|numeric|min:0|max:1',
            'trust_score_multiplier' => 'nullable|numeric|min:0|max:2',
            'safety_score_adjustment' => 'nullable|numeric|min:-1|max:1',
            'domain_category' => 'nullable|string|max:100',
            'suggested_specializations' => 'nullable|array',
            'suggested_content_types' => 'nullable|array',
            'auto_whitelist' => 'nullable|boolean',
            'auto_blacklist' => 'nullable|boolean',
            'requires_verification' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:1|max:1000',
            'notes' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $success = $this->discoveryService->updateRule($id, $validated);

        if (!$success) {
            return response()->json(['success' => false, 'error' => 'Failed to update rule'], 500);
        }

        return response()->json(['success' => true, 'rule_id' => $id]);
    }

    /**
     * Delete a discovery rule (soft delete)
     */
    public function deleteRule(string $id): JsonResponse
    {
        $success = $this->discoveryService->deleteRule($id);

        if (!$success) {
            return response()->json(['success' => false, 'error' => 'Failed to delete rule'], 500);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Get rule type options and categories
     */
    public function ruleOptions(): JsonResponse
    {
        $ruleTypes = [
            'tld_trust' => 'TLD Trust Score - Base trust score for top-level domains',
            'whitelist_pattern' => 'Whitelist Pattern - Always allow these domains',
            'blacklist_pattern' => 'Blacklist Pattern - Always block these domains',
            'category_domain' => 'Category Domain - Known good domains for specific categories',
            'category_pattern' => 'Category Pattern - Patterns for category detection',
            'safety_modifier' => 'Safety Modifier - Adjust safety score based on URL patterns',
        ];

        $patternTypes = [
            'exact' => 'Exact match',
            'suffix' => 'Ends with pattern',
            'prefix' => 'Starts with pattern',
            'contains' => 'Contains pattern',
            'regex' => 'Regular expression',
        ];

        $categories = DB::connection($this->connection)->select("
            SELECT DISTINCT domain_category FROM discovery_rules
            WHERE domain_category IS NOT NULL AND is_active = true
            ORDER BY domain_category
        ");

        $specializations = DB::connection($this->connection)->select("
            SELECT DISTINCT jsonb_array_elements_text(suggested_specializations) as spec
            FROM discovery_rules
            WHERE suggested_specializations IS NOT NULL
            AND suggested_specializations != '[]'::jsonb
            ORDER BY spec
        ");

        return response()->json([
            'success' => true,
            'rule_types' => $ruleTypes,
            'pattern_types' => $patternTypes,
            'categories' => array_column($categories, 'domain_category'),
            'specializations' => array_column($specializations, 'spec'),
        ]);
    }

    // =========================================================================
    // SOURCE OPTIMIZATION
    // =========================================================================

    /**
     * Run self-healing on failing sources
     */
    public function runHealing(): JsonResponse
    {
        ExecuteResearchMaintenance::dispatch(['heal' => true]);

        return response()->json([
            'success' => true,
            'data' => ['queued' => true, 'queue' => 'long-running', 'operation' => 'heal'],
            'message' => 'Research self-healing queued',
        ], 202);
    }

    /**
     * Run rule optimization
     */
    public function runOptimization(): JsonResponse
    {
        ExecuteResearchMaintenance::dispatch(['optimize' => true]);

        return response()->json([
            'success' => true,
            'data' => ['queued' => true, 'queue' => 'long-running', 'operation' => 'optimize'],
            'message' => 'Research rule optimization queued',
        ], 202);
    }

    /**
     * Generate health report
     */
    public function healthReport(): JsonResponse
    {
        $report = $this->optimizationService->generateHealthReport();

        return response()->json([
            'success' => true,
            'report' => $report,
        ]);
    }

    /**
     * Get research engine health summary (from research_engine_health table).
     * Passive state populated by research-ops agent.
     */
    public function engineHealth(): JsonResponse
    {
        $summary = app(\App\Services\ResearchEngineHealthService::class)->getHealthSummary();

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * Get source suggestions for a category
     */
    public function sourceSuggestions(Request $request): JsonResponse
    {
        $category = $request->input('category', 'general');
        $limit = min((int)$request->input('limit', 10), 20);

        $suggestions = $this->optimizationService->suggestNewSources($category, $limit);

        return response()->json([
            'success' => true,
            'category' => $category,
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Record source feedback
     */
    public function recordSourceFeedback(Request $request, string $sourceId): JsonResponse
    {
        $validated = $request->validate([
            'accuracy_rating' => 'nullable|integer|min:1|max:5',
            'relevance_rating' => 'nullable|integer|min:1|max:5',
            'reliability_rating' => 'nullable|integer|min:1|max:5',
            'timeliness_rating' => 'nullable|integer|min:1|max:5',
            'feedback_type' => 'nullable|string|in:excellent,good,neutral,poor,unusable,false_positive,irrelevant,outdated,blocked,error',
            'notes' => 'nullable|string',
            'mission_id' => 'nullable|uuid',
            'topic' => 'nullable|string',
            'category' => 'nullable|string',
        ]);

        $success = $this->discoveryService->recordPerformanceFeedback(
            $sourceId,
            $validated,
            $validated['mission_id'] ?? null,
            $validated['topic'] ?? null,
            $validated['category'] ?? null
        );

        if (!$success) {
            return response()->json(['success' => false, 'error' => 'Failed to record feedback'], 500);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Get feedback history for a source
     */
    public function sourceFeedback(string $sourceId): JsonResponse
    {
        $feedback = DB::connection($this->connection)->select("
            SELECT
                id, accuracy_rating, relevance_rating, reliability_rating, timeliness_rating,
                overall_score, feedback_type, notes, research_topic, research_category,
                trust_score_before, trust_score_after,
                facts_extracted, facts_verified, facts_rejected,
                rated_by, rated_at
            FROM source_performance_feedback
            WHERE source_id = ?
            ORDER BY rated_at DESC
            LIMIT 50
        ", [$sourceId]);

        return response()->json([
            'success' => true,
            'feedback' => array_map(fn($f) => (array)$f, $feedback),
            'count' => count($feedback),
        ]);
    }

    /**
     * Get category health status
     */
    public function categoryHealth(): JsonResponse
    {
        $health = $this->optimizationService->getCategoryHealth();
        $needsAttention = $this->optimizationService->getCategoriesNeedingAttention();

        return response()->json([
            'success' => true,
            'categories' => $health,
            'needs_attention' => $needsAttention,
            'summary' => [
                'total_categories' => count($health),
                'healthy' => count(array_filter($health, fn($h) => $h['health_status'] === 'healthy')),
                'warning' => count(array_filter($health, fn($h) => $h['health_status'] === 'warning')),
                'degraded' => count(array_filter($health, fn($h) => $h['health_status'] === 'degraded')),
                'critical' => count(array_filter($health, fn($h) => $h['health_status'] === 'critical')),
            ],
        ]);
    }

    /**
     * Run comprehensive maintenance
     */
    public function runMaintenance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'heal' => 'nullable|boolean',
            'refresh' => 'nullable|boolean',
            'discover' => 'nullable|boolean',
            'optimize' => 'nullable|boolean',
            'report' => 'nullable|boolean',
            'category' => 'nullable|string',
        ]);

        $unsupported = [];
        foreach (['refresh', 'discover'] as $legacyOption) {
            if ($validated[$legacyOption] ?? false) {
                $unsupported[] = $legacyOption;
            }
        }

        if ($unsupported !== []) {
            return response()->json([
                'success' => false,
                'error' => 'Unsupported legacy maintenance options',
                'unsupported' => $unsupported,
            ], 422);
        }

        $runAll = empty(array_filter([
            $validated['heal'] ?? null,
            $validated['optimize'] ?? null,
            $validated['report'] ?? null,
            $validated['category'] ?? null,
        ], fn($v) => $v !== null && $v !== false && $v !== ''));

        $operations = [
            'heal' => $runAll || ($validated['heal'] ?? false),
            'optimize' => $runAll || ($validated['optimize'] ?? false),
            'report' => $runAll || ($validated['report'] ?? false),
            'category' => $validated['category'] ?? null,
        ];

        ExecuteResearchMaintenance::dispatch($operations);

        return response()->json([
            'success' => true,
            'data' => [
                'queued' => true,
                'queue' => 'long-running',
                'operations' => $operations,
            ],
            'message' => 'Research maintenance queued',
        ], 202);
    }

    /**
     * Refresh sources for a specific category
     */
    public function refreshCategory(string $category): JsonResponse
    {
        $validCategories = [
            'academic', 'finance', 'genealogy', 'general', 'government',
            'health', 'legal', 'medical', 'news', 'science', 'technology'
        ];

        if (!in_array($category, $validCategories)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid category. Valid options: ' . implode(', ', $validCategories),
            ], 400);
        }

        ExecuteResearchCategoryRefresh::dispatch($category);

        return response()->json([
            'success' => true,
            'data' => [
                'queued' => true,
                'queue' => 'long-running',
                'category' => $category,
            ],
            'message' => "Category refresh queued for {$category}",
        ], 202);
    }

    // =========================================================================
    // UNIFIED REVIEW QUEUE (combines Topic Results + Mission Facts)
    // =========================================================================

    /**
     * Get unified review queue - pending items from both Topics and Missions
     *
     * Returns items from:
     * - research_results (Topics system) with status='pending'
     * - research_facts (Missions system) with needs_human_review=true AND human_review_action IS NULL
     */
    public function reviewQueue(Request $request): JsonResponse
    {
        $limit = min((int)$request->input('limit', 50), 100);
        $offset = (int)$request->input('offset', 0);
        $sourceType = $request->input('source_type'); // 'topic_result', 'mission_fact', or null for both

        $items = [];

        // Get pending topic results (unless filtered to facts only)
        if (!$sourceType || $sourceType === 'topic_result') {
            $topicResults = DB::connection($this->connection)->select("
                SELECT
                    rr.id,
                    'topic_result' as source_type,
                    rt.description as parent_name,
                    rt.id as parent_id,
                    rt.rag_category as category,
                    rr.ai_output as content,
                    NULL as confidence_score,
                    NULL as source_urls,
                    rr.created_at
                FROM research_results rr
                JOIN research_topics rt ON rt.id = rr.research_topic_id
                WHERE rr.status = 'pending'
                AND (rt.rag_category IS NULL OR rt.rag_category != 'genealogy')
                ORDER BY rr.created_at DESC
            ");

            foreach ($topicResults as $result) {
                $items[] = [
                    'id' => $result->id,
                    'source_type' => 'topic_result',
                    'parent_name' => $result->parent_name,
                    'parent_id' => $result->parent_id,
                    'category' => $result->category,
                    'content' => $result->content,
                    'confidence_score' => null,
                    'source_urls' => [],
                    'created_at' => $result->created_at,
                ];
            }
        }

        // Get pending mission facts (unless filtered to topics only)
        if (!$sourceType || $sourceType === 'mission_fact') {
            $missionFacts = DB::connection($this->connection)->select("
                SELECT
                    rf.id,
                    'mission_fact' as source_type,
                    rm.title as parent_name,
                    rm.id as parent_id,
                    rm.domain_category as category,
                    rf.fact_statement as content,
                    rf.confidence_score,
                    rf.source_urls,
                    rf.context_snippet,
                    rf.fact_type,
                    rf.created_at
                FROM research_facts rf
                JOIN research_missions rm ON rm.id = rf.mission_id
                WHERE rf.needs_human_review = true
                AND rf.human_review_action IS NULL
                ORDER BY rf.created_at DESC
            ");

            foreach ($missionFacts as $fact) {
                $items[] = [
                    'id' => $fact->id,
                    'source_type' => 'mission_fact',
                    'parent_name' => $fact->parent_name,
                    'parent_id' => $fact->parent_id,
                    'category' => $fact->category,
                    'content' => $fact->content,
                    'confidence_score' => (float)$fact->confidence_score,
                    'source_urls' => json_decode($fact->source_urls ?? '[]', true),
                    'context_snippet' => $fact->context_snippet,
                    'fact_type' => $fact->fact_type,
                    'created_at' => $fact->created_at,
                ];
            }
        }

        // Sort combined results by created_at DESC
        usort($items, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        // Apply pagination
        $total = count($items);
        $items = array_slice($items, $offset, $limit);

        // Get stats
        $stats = $this->getReviewQueueStats();

        return response()->json([
            'success' => true,
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'stats' => $stats,
        ]);
    }

    /**
     * Get review queue statistics
     */
    public function reviewQueueStats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'stats' => $this->getReviewQueueStats(),
        ]);
    }

    /**
     * Helper to get review queue stats
     */
    private function getReviewQueueStats(): array
    {
        // Pending topic results
        $topicPending = DB::connection($this->connection)->select("
            SELECT COUNT(*) as count FROM research_results WHERE status = 'pending'
        ");

        // Pending mission facts
        $factsPending = DB::connection($this->connection)->select("
            SELECT COUNT(*) as count FROM research_facts
            WHERE needs_human_review = true AND human_review_action IS NULL
        ");

        // Approved today (topic results are deleted on approve, so check RAG)
        $approvedToday = DB::connection($this->connection)->select("
            SELECT COUNT(*) as count FROM research_facts
            WHERE human_review_action = 'approved'
            AND human_reviewed_at >= CURRENT_DATE
        ");

        // Skipped today
        $skippedToday = DB::connection($this->connection)->select("
            SELECT COUNT(*) as count FROM research_facts
            WHERE human_review_action = 'skipped'
            AND human_reviewed_at >= CURRENT_DATE
        ");

        return [
            'pending_topic_results' => (int)($topicPending[0]->count ?? 0),
            'pending_mission_facts' => (int)($factsPending[0]->count ?? 0),
            'total_pending' => (int)($topicPending[0]->count ?? 0) + (int)($factsPending[0]->count ?? 0),
            'approved_today' => (int)($approvedToday[0]->count ?? 0),
            'skipped_today' => (int)($skippedToday[0]->count ?? 0),
        ];
    }

}
