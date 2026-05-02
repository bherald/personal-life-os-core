<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AIService;
use App\Services\RAGService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ResearchTopicController - API for Research Topics (scheduled research)
 *
 * Refactored to use raw SQL per project standards - NO Eloquent/Query Builder
 */
class ResearchTopicController extends Controller
{
    private RAGService $ragService;
    private string $connection = 'pgsql_rag';

    // Frequency labels for display
    private const FREQUENCIES = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'biannually' => 'Twice a Year',
    ];

    public function __construct(RAGService $ragService)
    {
        $this->ragService = $ragService;
    }

    /**
     * List all research topics with pending results count
     */
    public function index(Request $request): JsonResponse
    {
        $topics = DB::connection($this->connection)->select("
            SELECT
                rt.*,
                (SELECT COUNT(*) FROM research_results rr
                 WHERE rr.research_topic_id = rt.id AND rr.status = 'pending') as pending_results_count
            FROM research_topics rt
            WHERE rt.source = 'human'
            ORDER BY rt.created_at DESC
        ");

        $mappedTopics = array_map(function ($topic) {
            return [
                'id' => $topic->id,
                'description' => $topic->description,
                'topic_content' => $topic->topic_content,
                'frequency' => $topic->frequency,
                'frequency_label' => self::FREQUENCIES[$topic->frequency] ?? $topic->frequency,
                'last_ran_at' => $topic->last_ran_at,
                'is_active' => (bool)$topic->is_active,
                'rag_category' => $topic->rag_category,
                'source' => $topic->source ?? 'auto',
                'pending_results_count' => (int)$topic->pending_results_count,
                'is_due' => $this->isDueForResearch($topic),
                'search_depth' => $topic->search_depth,
                'max_sources' => $topic->max_sources,
                'max_results_per_source' => $topic->max_results_per_source,
                'date_filter_days' => $topic->date_filter_days,
                'preferred_categories' => json_decode($topic->preferred_categories ?? '[]', true),
                'excluded_domains' => json_decode($topic->excluded_domains ?? '[]', true),
                'require_recent_only' => (bool)$topic->require_recent_only,
                'created_at' => $topic->created_at,
                'updated_at' => $topic->updated_at,
            ];
        }, $topics);

        return response()->json([
            'topics' => $mappedTopics,
            'frequencies' => self::FREQUENCIES,
        ]);
    }

    /**
     * Get a single research topic with its pending results
     */
    public function show(int $id): JsonResponse
    {
        $topic = DB::connection($this->connection)->select("
            SELECT * FROM research_topics WHERE id = ?
        ", [$id]);

        if (empty($topic)) {
            return response()->json(['error' => 'Topic not found'], 404);
        }

        $topic = $topic[0];

        $pendingResults = DB::connection($this->connection)->select("
            SELECT id, ai_output, status, created_at
            FROM research_results
            WHERE research_topic_id = ? AND status = 'pending'
            ORDER BY created_at DESC
        ", [$id]);

        return response()->json([
            'topic' => [
                'id' => $topic->id,
                'description' => $topic->description,
                'topic_content' => $topic->topic_content,
                'frequency' => $topic->frequency,
                'frequency_label' => self::FREQUENCIES[$topic->frequency] ?? $topic->frequency,
                'last_ran_at' => $topic->last_ran_at,
                'is_active' => (bool)$topic->is_active,
                'rag_category' => $topic->rag_category,
                'is_due' => $this->isDueForResearch($topic),
                'created_at' => $topic->created_at,
                'updated_at' => $topic->updated_at,
            ],
            'pending_results' => array_map(function ($result) {
                return [
                    'id' => $result->id,
                    'ai_output' => $result->ai_output,
                    'status' => $result->status,
                    'created_at' => $result->created_at,
                ];
            }, $pendingResults),
        ]);
    }

    /**
     * Create a new research topic
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'topic_content' => 'required|string',
            'frequency' => 'required|string|in:daily,weekly,monthly,quarterly,biannually',
            'is_active' => 'boolean',
            'rag_category' => 'nullable|string|max:100',
            'search_depth' => 'nullable|integer|min:1|max:10',
            'max_sources' => 'nullable|integer|min:1|max:50',
            'max_results_per_source' => 'nullable|integer|min:1|max:20',
            'date_filter_days' => 'nullable|integer|min:1|max:365',
            'preferred_categories' => 'nullable|array',
            'preferred_categories.*' => 'string|max:100',
            'excluded_domains' => 'nullable|array',
            'excluded_domains.*' => 'string|max:255',
            'require_recent_only' => 'nullable|boolean',
        ]);

        $id = DB::connection($this->connection)->table('research_topics')->insertGetId([
            'description' => $validated['description'],
            'topic_content' => $validated['topic_content'],
            'frequency' => $validated['frequency'],
            'is_active' => $validated['is_active'] ?? true,
            'rag_category' => $validated['rag_category'] ?? null,
            'last_ran_at' => null,
            'search_depth' => $validated['search_depth'] ?? null,
            'max_sources' => $validated['max_sources'] ?? null,
            'max_results_per_source' => $validated['max_results_per_source'] ?? null,
            'date_filter_days' => $validated['date_filter_days'] ?? null,
            'preferred_categories' => json_encode($validated['preferred_categories'] ?? []),
            'excluded_domains' => json_encode($validated['excluded_domains'] ?? []),
            'require_recent_only' => $validated['require_recent_only'] ?? true,
            'source' => 'human', // Manual creation via API/UI
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Research topic created successfully',
            'topic' => [
                'id' => $id,
                'description' => $validated['description'],
                'frequency' => $validated['frequency'],
            ],
        ], 201);
    }

    /**
     * Update a research topic
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $topic = DB::connection($this->connection)->select("
            SELECT id FROM research_topics WHERE id = ?
        ", [$id]);

        if (empty($topic)) {
            return response()->json(['error' => 'Topic not found'], 404);
        }

        $validated = $request->validate([
            'description' => 'sometimes|required|string|max:255',
            'topic_content' => 'sometimes|required|string',
            'frequency' => 'sometimes|required|string|in:daily,weekly,monthly,quarterly,biannually',
            'is_active' => 'sometimes|boolean',
            'rag_category' => 'nullable|string|max:100',
            'search_depth' => 'nullable|integer|min:1|max:10',
            'max_sources' => 'nullable|integer|min:1|max:50',
            'max_results_per_source' => 'nullable|integer|min:1|max:20',
            'date_filter_days' => 'nullable|integer|min:1|max:365',
            'preferred_categories' => 'nullable|array',
            'preferred_categories.*' => 'string|max:100',
            'excluded_domains' => 'nullable|array',
            'excluded_domains.*' => 'string|max:255',
            'require_recent_only' => 'nullable|boolean',
        ]);

        // Build update data
        $updateData = ['updated_at' => now()];

        foreach (['description', 'topic_content', 'frequency', 'is_active', 'rag_category',
                  'search_depth', 'max_sources', 'max_results_per_source', 'date_filter_days',
                  'require_recent_only'] as $field) {
            if (array_key_exists($field, $validated)) {
                $updateData[$field] = $validated[$field];
            }
        }

        // Handle array fields
        if (array_key_exists('preferred_categories', $validated)) {
            $updateData['preferred_categories'] = json_encode($validated['preferred_categories'] ?? []);
        }
        if (array_key_exists('excluded_domains', $validated)) {
            $updateData['excluded_domains'] = json_encode($validated['excluded_domains'] ?? []);
        }

        DB::connection($this->connection)->table('research_topics')
            ->where('id', $id)
            ->update($updateData);

        return response()->json([
            'message' => 'Research topic updated successfully',
            'topic' => [
                'id' => $id,
                'description' => $validated['description'] ?? null,
                'frequency' => $validated['frequency'] ?? null,
                'is_active' => $validated['is_active'] ?? null,
            ],
        ]);
    }

    /**
     * Delete a research topic (cascade deletes results)
     */
    public function destroy(int $id): JsonResponse
    {
        $topic = DB::connection($this->connection)->select("
            SELECT id, description FROM research_topics WHERE id = ?
        ", [$id]);

        if (empty($topic)) {
            return response()->json(['error' => 'Topic not found'], 404);
        }

        $description = $topic[0]->description;

        // Results will be cascade deleted due to FK constraint
        DB::connection($this->connection)->delete("
            DELETE FROM research_topics WHERE id = ?
        ", [$id]);

        return response()->json([
            'message' => "Research topic '{$description}' deleted successfully",
        ]);
    }

    /**
     * Toggle active status of a topic
     */
    public function toggle(int $id): JsonResponse
    {
        $topic = DB::connection($this->connection)->select("
            SELECT id, is_active FROM research_topics WHERE id = ?
        ", [$id]);

        if (empty($topic)) {
            return response()->json(['error' => 'Topic not found'], 404);
        }

        $newStatus = !$topic[0]->is_active;

        DB::connection($this->connection)->update("
            UPDATE research_topics SET is_active = ?, updated_at = NOW() WHERE id = ?
        ", [$newStatus, $id]);

        return response()->json([
            'message' => $newStatus ? 'Topic activated' : 'Topic deactivated',
            'is_active' => $newStatus,
        ]);
    }

    /**
     * Get all pending research results across all topics
     * Excludes deferred/skipped results and filters by AI recommendation
     *
     * Query params:
     * - include_rejected: if true, include AI-rejected results (default: false)
     * - include_needs_research: if true, include needs_research results (default: false)
     */
    public function pendingResults(Request $request): JsonResponse
    {
        $includeRejected = $request->boolean('include_rejected', false);
        $includeNeedsResearch = $request->boolean('include_needs_research', false);

        // Build recommendation filter - by default only show 'index' and 'review' results
        $recommendationFilter = "'index', 'review'";
        if ($includeRejected) {
            $recommendationFilter .= ", 'reject'";
        }
        if ($includeNeedsResearch) {
            $recommendationFilter .= ", 'needs_research'";
        }

        $results = DB::connection($this->connection)->select("
            SELECT
                rr.id,
                rr.research_topic_id as topic_id,
                rt.description as topic_description,
                rt.rag_category,
                COALESCE(rt.source, 'auto') as topic_source,
                rr.ai_output,
                rr.status,
                rr.quality_score,
                rr.ai_quality_score,
                rr.ai_has_findings,
                rr.ai_recommendation,
                rr.created_at
            FROM research_results rr
            JOIN research_topics rt ON rt.id = rr.research_topic_id
            WHERE rr.status = 'pending'
            AND (rr.ai_recommendation IS NULL OR rr.ai_recommendation IN ({$recommendationFilter}))
            ORDER BY
                CASE rr.ai_recommendation
                    WHEN 'index' THEN 1
                    WHEN 'review' THEN 2
                    WHEN 'needs_research' THEN 3
                    WHEN 'reject' THEN 4
                    ELSE 5
                END,
                rr.ai_quality_score DESC NULLS LAST,
                rr.created_at DESC
        ");

        return response()->json([
            'results' => array_map(function ($result) {
                return [
                    'id' => $result->id,
                    'topic_id' => $result->topic_id,
                    'topic_description' => $result->topic_description,
                    'rag_category' => $result->rag_category,
                    'topic_source' => $result->topic_source,
                    'ai_output' => $result->ai_output,
                    'status' => $result->status,
                    'quality_score' => $result->quality_score,
                    'ai_quality_score' => $result->ai_quality_score,
                    'ai_has_findings' => (bool)$result->ai_has_findings,
                    'ai_recommendation' => $result->ai_recommendation,
                    'created_at' => $result->created_at,
                ];
            }, $results),
            'count' => count($results),
        ]);
    }

    /**
     * Get deferred research results (auto-topics with no useful results)
     * These are not shown to the user by default but can be reviewed
     */
    public function deferredResults(): JsonResponse
    {
        $results = DB::connection($this->connection)->select("
            SELECT
                rr.id,
                rr.research_topic_id as topic_id,
                rt.description as topic_description,
                rt.rag_category,
                COALESCE(rt.source, 'auto') as topic_source,
                rr.ai_output,
                rr.status,
                rr.quality_score,
                rr.ai_quality_score,
                rr.ai_has_findings,
                rr.ai_recommendation,
                rr.created_at
            FROM research_results rr
            JOIN research_topics rt ON rt.id = rr.research_topic_id
            WHERE rr.status = 'deferred'
            ORDER BY rr.created_at DESC
        ");

        return response()->json([
            'results' => array_map(function ($result) {
                return [
                    'id' => $result->id,
                    'topic_id' => $result->topic_id,
                    'topic_description' => $result->topic_description,
                    'rag_category' => $result->rag_category,
                    'topic_source' => $result->topic_source,
                    'ai_output' => $result->ai_output,
                    'status' => $result->status,
                    'quality_score' => $result->quality_score,
                    'ai_quality_score' => $result->ai_quality_score,
                    'ai_has_findings' => (bool)$result->ai_has_findings,
                    'ai_recommendation' => $result->ai_recommendation,
                    'created_at' => $result->created_at,
                ];
            }, $results),
            'count' => count($results),
        ]);
    }

    /**
     * Get skipped research results (auto-skipped due to no findings)
     * Useful for auditing what the AI filtered out
     */
    public function skippedResults(): JsonResponse
    {
        $results = DB::connection($this->connection)->select("
            SELECT
                rr.id,
                rr.research_topic_id as topic_id,
                rt.description as topic_description,
                rt.rag_category,
                COALESCE(rt.source, 'auto') as topic_source,
                rr.ai_output,
                rr.status,
                rr.quality_score,
                rr.ai_quality_score,
                rr.ai_has_findings,
                rr.ai_recommendation,
                rr.created_at
            FROM research_results rr
            JOIN research_topics rt ON rt.id = rr.research_topic_id
            WHERE rr.status = 'skipped'
            ORDER BY rr.created_at DESC
            LIMIT 100
        ");

        return response()->json([
            'results' => array_map(function ($result) {
                return [
                    'id' => $result->id,
                    'topic_id' => $result->topic_id,
                    'topic_description' => $result->topic_description,
                    'rag_category' => $result->rag_category,
                    'topic_source' => $result->topic_source,
                    'ai_output' => $result->ai_output,
                    'status' => $result->status,
                    'quality_score' => $result->quality_score,
                    'ai_quality_score' => $result->ai_quality_score,
                    'ai_has_findings' => (bool)$result->ai_has_findings,
                    'ai_recommendation' => $result->ai_recommendation,
                    'created_at' => $result->created_at,
                ];
            }, $results),
            'count' => count($results),
        ]);
    }

    /**
     * Restore a skipped/deferred result back to pending for human review
     */
    public function restoreResult(int $resultId): JsonResponse
    {
        $result = DB::connection($this->connection)->select("
            SELECT id, status FROM research_results WHERE id = ?
        ", [$resultId]);

        if (empty($result)) {
            return response()->json(['error' => 'Result not found'], 404);
        }

        $status = $result[0]->status;
        if (!in_array($status, ['skipped', 'deferred'])) {
            return response()->json(['error' => 'Only skipped or deferred results can be restored'], 400);
        }

        DB::connection($this->connection)->update("
            UPDATE research_results
            SET status = 'pending', updated_at = NOW()
            WHERE id = ?
        ", [$resultId]);

        Log::info('Research result restored to pending', ['result_id' => $resultId, 'previous_status' => $status]);

        return response()->json([
            'message' => 'Result restored to pending for human review',
            'previous_status' => $status,
        ]);
    }

    /**
     * Approve a research result - save to RAG and delete
     */
    public function approveResult(int $resultId): JsonResponse
    {
        $result = DB::connection($this->connection)->select("
            SELECT rr.*, rt.description as topic_description, rt.rag_category
            FROM research_results rr
            JOIN research_topics rt ON rt.id = rr.research_topic_id
            WHERE rr.id = ?
        ", [$resultId]);

        if (empty($result)) {
            return response()->json(['error' => 'Result not found'], 404);
        }

        $result = $result[0];

        if ($result->status !== 'pending') {
            return response()->json(['error' => 'Result has already been reviewed'], 400);
        }

        try {
            DB::connection($this->connection)->beginTransaction();

            // Get the RAG category from the topic (or generate from description)
            $category = $result->rag_category;
            if (!$category) {
                $category = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $result->topic_description));
                $category = trim($category, '_');
            }

            // Index the research result in RAG
            $ragDocument = $this->ragService->indexDocument(
                documentType: 'research_topic',
                content: $result->ai_output,
                title: $result->topic_description . ' - Research ' . date('Y-m-d', strtotime($result->created_at)),
                metadata: [
                    'research_topic_id' => $result->research_topic_id,
                    'topic_description' => $result->topic_description,
                    'researched_at' => $result->created_at,
                    'approved_at' => now()->toISOString(),
                ],
                sourceId: $result->id,
                sourceType: 'research_result',
                designation: $category
            );

            // Delete the result after successful RAG indexing
            DB::connection($this->connection)->delete("
                DELETE FROM research_results WHERE id = ?
            ", [$resultId]);

            DB::connection($this->connection)->commit();

            Log::info('Research result approved and indexed to RAG', [
                'result_id' => $resultId,
                'rag_document_id' => $ragDocument->id,
                'category' => $category,
            ]);

            return response()->json([
                'message' => 'Research approved and saved to knowledge base',
                'rag_document_id' => $ragDocument->id,
                'category' => $category,
            ]);

        } catch (\Exception $e) {
            DB::connection($this->connection)->rollBack();

            Log::error('Failed to approve research result', [
                'result_id' => $resultId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to save to knowledge base: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Skip/reject a research result - stores rejection for deduplication, then deletes
     */
    public function skipResult(int $resultId): JsonResponse
    {
        $result = DB::connection($this->connection)->select("
            SELECT id, research_topic_id, status, content_hash, extracted_facts
            FROM research_results WHERE id = ?
        ", [$resultId]);

        if (empty($result)) {
            return response()->json(['error' => 'Result not found'], 404);
        }

        $row = $result[0];

        if ($row->status !== 'pending') {
            return response()->json(['error' => 'Result has already been reviewed'], 400);
        }

        // Store rejection for deduplication (Layer 3 - prevents re-showing rejected content)
        if ($row->content_hash) {
            $factHashes = [];
            if ($row->extracted_facts) {
                $facts = json_decode($row->extracted_facts, true) ?? [];
                $factHashes = array_map(fn($f) => hash('sha256', strtolower(json_encode($f))), $facts);
            }

            // Insert into research_rejections (ignore if already exists)
            DB::connection($this->connection)->statement("
                INSERT INTO research_rejections (
                    research_topic_id, content_hash, fact_hashes,
                    rejection_reason, rejected_by, original_result_id, created_at
                )
                VALUES (?, ?, ?::jsonb, ?, 'human', ?, NOW())
                ON CONFLICT (research_topic_id, content_hash) DO NOTHING
            ", [
                $row->research_topic_id,
                $row->content_hash,
                json_encode($factHashes),
                'User skipped/rejected',
                $resultId
            ]);

            Log::info('Research rejection tracked for deduplication', [
                'result_id' => $resultId,
                'topic_id' => $row->research_topic_id,
                'content_hash' => substr($row->content_hash, 0, 16) . '...',
            ]);
        }

        // Delete the result
        DB::connection($this->connection)->delete("
            DELETE FROM research_results WHERE id = ?
        ", [$resultId]);

        Log::info('Research result skipped', ['result_id' => $resultId]);

        return response()->json([
            'message' => 'Research result skipped and tracked for deduplication',
        ]);
    }

    /**
     * Get RAG categories for dropdown (combines document types + topic categories)
     * Allows selection from existing categories or adding new ones
     */
    public function ragCategories(): JsonResponse
    {
        // Get distinct document_type values from rag_documents
        $documentTypes = DB::connection($this->connection)->select("
            SELECT DISTINCT document_type
            FROM rag_documents
            WHERE document_type IS NOT NULL AND document_type != ''
            ORDER BY document_type
        ");

        // Get distinct rag_category values from research_topics
        $topicCategories = DB::connection($this->connection)->select("
            SELECT DISTINCT rag_category
            FROM research_topics
            WHERE rag_category IS NOT NULL AND rag_category != ''
            ORDER BY rag_category
        ");

        // Predefined system categories (commonly used)
        $predefined = [
            'general' => 'General',
            'genealogy' => 'Genealogy',
            'health' => 'Health',
            'finance' => 'Finance',
            'news' => 'News',
            'technology' => 'Technology',
            'security' => 'Security',
            'legal' => 'Legal',
            'research' => 'Research',
        ];

        // Merge all categories
        $allCategories = $predefined;

        foreach ($documentTypes as $dt) {
            $key = strtolower($dt->document_type);
            if (!isset($allCategories[$key])) {
                $allCategories[$key] = ucfirst(str_replace('_', ' ', $dt->document_type));
            }
        }

        foreach ($topicCategories as $tc) {
            $key = strtolower($tc->rag_category);
            if (!isset($allCategories[$key])) {
                $allCategories[$key] = ucfirst(str_replace('_', ' ', $tc->rag_category));
            }
        }

        // Sort alphabetically by label
        asort($allCategories);

        return response()->json([
            'success' => true,
            'categories' => $allCategories,
            'predefined' => array_keys($predefined),
        ]);
    }

    /**
     * Get statistics about research topics
     */
    public function stats(): JsonResponse
    {
        $stats = DB::connection($this->connection)->select("
            SELECT
                (SELECT COUNT(*) FROM research_topics) as total_topics,
                (SELECT COUNT(*) FROM research_topics WHERE is_active = true) as active_topics,
                (SELECT COUNT(*) FROM research_results WHERE status = 'pending') as pending_results,
                (SELECT COUNT(*) FROM research_results WHERE status = 'deferred') as deferred_results,
                (SELECT COUNT(*) FROM research_results WHERE status = 'skipped') as skipped_results,
                (SELECT COUNT(*) FROM research_results WHERE status = 'pending' AND ai_recommendation = 'index') as ready_to_index,
                (SELECT COUNT(*) FROM research_results WHERE status = 'pending' AND ai_recommendation = 'review') as needs_review,
                (SELECT COUNT(*) FROM research_results WHERE status = 'pending' AND ai_recommendation = 'reject') as ai_rejected,
                (SELECT COUNT(*) FROM research_results WHERE status = 'pending' AND ai_has_findings = false) as no_findings
        ");

        $dueCount = DB::connection($this->connection)->select("
            SELECT COUNT(*) as count FROM research_topics
            WHERE is_active = true
            AND (
                last_ran_at IS NULL
                OR (frequency = 'daily' AND last_ran_at < NOW() - INTERVAL '1 day')
                OR (frequency = 'weekly' AND last_ran_at < NOW() - INTERVAL '7 days')
                OR (frequency = 'monthly' AND last_ran_at < NOW() - INTERVAL '30 days')
                OR (frequency = 'quarterly' AND last_ran_at < NOW() - INTERVAL '90 days')
                OR (frequency = 'biannually' AND last_ran_at < NOW() - INTERVAL '180 days')
            )
        ");

        $byFrequency = DB::connection($this->connection)->select("
            SELECT frequency, COUNT(*) as count
            FROM research_topics
            GROUP BY frequency
        ");

        $frequencyMap = [];
        foreach ($byFrequency as $row) {
            $frequencyMap[$row->frequency] = (int)$row->count;
        }

        $bySource = DB::connection($this->connection)->select("
            SELECT COALESCE(source, 'auto') as source, COUNT(*) as count
            FROM research_topics
            GROUP BY COALESCE(source, 'auto')
        ");

        $sourceMap = [];
        foreach ($bySource as $row) {
            $sourceMap[$row->source] = (int)$row->count;
        }

        return response()->json([
            'total_topics' => (int)$stats[0]->total_topics,
            'active_topics' => (int)$stats[0]->active_topics,
            'due_for_research' => (int)$dueCount[0]->count,
            'pending_results' => (int)$stats[0]->pending_results,
            'deferred_results' => (int)$stats[0]->deferred_results,
            'skipped_results' => (int)$stats[0]->skipped_results,
            'by_frequency' => $frequencyMap,
            'by_source' => $sourceMap,
            'ai_triage' => [
                'ready_to_index' => (int)$stats[0]->ready_to_index,
                'needs_review' => (int)$stats[0]->needs_review,
                'ai_rejected' => (int)$stats[0]->ai_rejected,
                'no_findings' => (int)$stats[0]->no_findings,
            ],
        ]);
    }

    /**
     * Check if a topic is due for research
     */
    /**
     * AI-guided topic refinement — conversational flow
     *
     * Accepts a raw idea + conversation history, returns AI's next response.
     * AI asks ONE question per turn. Human can say "save it" anytime to get the proposal.
     */
    public function refine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'raw_idea' => 'required|string|max:5000',
            'conversation' => 'nullable|array',
            'conversation.*.role' => 'required|string|in:user,assistant',
            'conversation.*.content' => 'required|string',
            'existing_topic' => 'nullable|array',
        ]);

        try {
            $aiService = app(AIService::class);
            $rawIdea = $validated['raw_idea'];
            $conversation = $validated['conversation'] ?? [];
            $existingTopic = $validated['existing_topic'] ?? null;

            // Detect if user wants to finalize
            $lastUserMsg = '';
            for ($i = count($conversation) - 1; $i >= 0; $i--) {
                if ($conversation[$i]['role'] === 'user') {
                    $lastUserMsg = strtolower(trim($conversation[$i]['content']));
                    break;
                }
            }

            $wantsSave = preg_match('/\b(save|done|enough|that\'?s? (?:it|all|enough|good)|finalize|generate|create it|just save|go ahead)\b/i', $lastUserMsg);

            // Force save if conversation has gone on too long (4+ user messages beyond initial)
            $userMsgCount = count(array_filter($conversation, fn($m) => $m['role'] === 'user'));
            if ($userMsgCount >= 5 && !$wantsSave) {
                $wantsSave = true;
                Log::info('ResearchTopicController: Auto-forcing proposal after ' . $userMsgCount . ' user messages');
            }

            // Build prompt
            $systemPrompt = $this->buildRefineSystemPrompt($wantsSave, $existingTopic);
            $userPrompt = $this->buildRefineUserPrompt($rawIdea, $conversation, $wantsSave, $existingTopic);

            $result = $aiService->process($systemPrompt . "\n\n" . $userPrompt, [
                'temperature' => 0.4,
                'max_tokens' => 3000,
                'ai_timeout' => 60,
                'use_cache' => false,
            ]);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => 'AI processing failed: ' . ($result['error'] ?? 'unknown'),
                ], 500);
            }

            $response = $result['response'];

            // Try to extract a proposal JSON from the response
            $proposal = $this->extractProposal($response);

            // Clean the message: remove the JSON block for display
            $displayMessage = $response;
            if ($proposal) {
                $displayMessage = preg_replace('/```json[\s\S]*?```/', '', $displayMessage);
                $displayMessage = trim($displayMessage);
            }

            return response()->json([
                'success' => true,
                'message' => $displayMessage,
                'proposal' => $proposal,
                'provider' => $result['provider'] ?? null,
            ]);

        } catch (\Throwable $e) {
            Log::error('ResearchTopicController: Refine failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to process: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function buildRefineSystemPrompt(bool $wantsSave, ?array $existingTopic): string
    {
        $editContext = $existingTopic ? "\nYou are EDITING an existing research topic. The current configuration is provided. Focus on improving and refining it." : '';

        if ($wantsSave) {
            return <<<PROMPT
You are a professional research assistant helping to structure a research topic.{$editContext}

The human wants to finalize. Generate a structured research brief as a JSON proposal.

You MUST include a JSON code block with these fields:
```json
{
  "description": "Concise title (max 255 chars)",
  "topic_content": "Detailed research brief in markdown with ## Primary Question, ## Sub-questions (numbered), ## Context & Constraints, ## Success Criteria",
  "frequency": "daily|weekly|monthly|quarterly|biannually",
  "rag_category": "general|genealogy|health|finance|technology|security|legal|news|research",
  "search_depth": 3,
  "max_sources": 15,
  "date_filter_days": 30,
  "require_recent_only": true
}
```

RULES:
1. topic_content must have clear sub-questions that the research engine can address individually
2. Frequency: daily for fast-moving (news, markets), weekly for moderate (health, tech), monthly for slow (genealogy, legal)
3. search_depth: 3-5 for focused topics, 5-7 for broad topics
4. date_filter_days: 7 for breaking news, 30 for current events, 90-365 for evergreen
5. Use ALL context from the conversation to make the brief as specific and actionable as possible
6. After the JSON block, add a brief summary of what you've configured and why
PROMPT;
        }

        return <<<PROMPT
You are a professional research assistant helping a human define a research topic.{$editContext}

Your job is to understand what they want to research and help them scope it properly by asking ONE clarifying question at a time.

RULES:
1. Ask exactly ONE question per response — never batch multiple questions
2. Each question should be specific and help narrow the research scope
3. Briefly acknowledge what you understand so far before asking
4. Focus questions on: specific aspects of interest, goals/decisions this research supports, time horizon, depth needed
5. MAXIMUM 4 questions total. After asking 3-4 questions, you MUST offer to generate the brief. Do NOT keep asking questions beyond 4 rounds.
6. If the human provides detailed context in their initial message, you may need only 1-2 questions before generating.
7. If the human says "save", "done", "enough", "that's it", "generate", or similar — IMMEDIATELY generate the JSON proposal below. Do NOT ask another question.
8. Keep responses concise — no filler, no repeating what you already acknowledged

CRITICAL: When generating a proposal, you MUST use exactly three backticks for the JSON code fence. The rag_category should be a short lowercase keyword describing the domain (e.g., health, legal, finance, genealogy, technology, legislative, insurance, education — any relevant category)

```json
{
  "description": "Concise title (max 255 chars)",
  "topic_content": "Detailed markdown with ## Primary Question, ## Sub-questions, ## Context, ## Success Criteria",
  "frequency": "daily|weekly|monthly|quarterly|biannually",
  "rag_category": "general",
  "search_depth": 3,
  "max_sources": 15,
  "date_filter_days": 30,
  "require_recent_only": true
}
```
PROMPT;
    }

    private function buildRefineUserPrompt(string $rawIdea, array $conversation, bool $wantsSave, ?array $existingTopic): string
    {
        $prompt = "RESEARCH IDEA: {$rawIdea}\n\n";

        if ($existingTopic) {
            $prompt .= "EXISTING TOPIC CONFIGURATION:\n";
            $prompt .= "- Description: " . ($existingTopic['description'] ?? '') . "\n";
            $prompt .= "- Current Brief: " . ($existingTopic['topic_content'] ?? '') . "\n";
            $prompt .= "- Frequency: " . ($existingTopic['frequency'] ?? 'weekly') . "\n";
            $prompt .= "- Category: " . ($existingTopic['rag_category'] ?? 'general') . "\n";
            $prompt .= "- Search Depth: " . ($existingTopic['search_depth'] ?? 3) . "\n";
            $prompt .= "- Max Sources: " . ($existingTopic['max_sources'] ?? 10) . "\n";
            $prompt .= "- Date Filter: " . ($existingTopic['date_filter_days'] ?? 30) . " days\n\n";
        }

        if (!empty($conversation)) {
            $prompt .= "CONVERSATION SO FAR:\n";
            foreach ($conversation as $msg) {
                $role = $msg['role'] === 'user' ? 'Human' : 'Assistant';
                $prompt .= "{$role}: {$msg['content']}\n\n";
            }
        }

        if ($wantsSave) {
            $prompt .= "\nThe human wants to finalize now. Generate the structured research brief JSON proposal based on everything discussed.";
        } elseif (empty($conversation)) {
            $prompt .= "\nThis is the initial idea. Analyze it and ask your first clarifying question.";
        } else {
            $prompt .= "\nContinue the conversation. Ask your next clarifying question, or if you have enough context, offer to generate the brief.";
        }

        return $prompt;
    }

    private function extractProposal(string $aiResponse): ?array
    {
        // Try backtick-fenced json blocks (``json, ```json, ````json — AI varies)
        if (preg_match('/`{2,}json\s*([\s\S]*?)`{2,}/', $aiResponse, $matches)) {
            $parsed = json_decode(trim($matches[1]), true);
            if ($parsed && isset($parsed['description'], $parsed['topic_content'])) {
                return $this->normalizeProposal($parsed);
            }
        }

        // Fallback: find JSON object containing required keys
        if (preg_match('/\{[\s\S]*"description"[\s\S]*"topic_content"[\s\S]*\}/', $aiResponse, $matches)) {
            $parsed = json_decode($matches[0], true);
            if ($parsed && isset($parsed['description'], $parsed['topic_content'])) {
                return $this->normalizeProposal($parsed);
            }
        }

        return null;
    }

    private function normalizeProposal(array $proposal): array
    {
        $validFreqs = ['daily', 'weekly', 'monthly', 'quarterly', 'biannually'];

        // Sanitize rag_category: lowercase, underscores, strip pipes/special chars
        $rawCat = strtolower(trim($proposal['rag_category'] ?? 'general'));
        $rawCat = preg_replace('/[^a-z0-9_]/', '_', $rawCat);
        $rawCat = preg_replace('/_+/', '_', trim($rawCat, '_'));
        if (empty($rawCat)) $rawCat = 'general';

        return [
            'description' => substr($proposal['description'] ?? '', 0, 255),
            'topic_content' => $proposal['topic_content'] ?? '',
            'frequency' => in_array($proposal['frequency'] ?? '', $validFreqs) ? $proposal['frequency'] : 'weekly',
            'rag_category' => $rawCat,
            'search_depth' => max(1, min(10, (int) ($proposal['search_depth'] ?? 3))),
            'max_sources' => max(1, min(50, (int) ($proposal['max_sources'] ?? 15))),
            'date_filter_days' => max(1, min(365, (int) ($proposal['date_filter_days'] ?? 30))),
            'require_recent_only' => (bool) ($proposal['require_recent_only'] ?? true),
            'preferred_categories' => $proposal['preferred_categories'] ?? [],
            'excluded_domains' => $proposal['excluded_domains'] ?? [],
            'is_active' => true,
        ];
    }

    private function isDueForResearch($topic): bool
    {
        if (!$topic->is_active) {
            return false;
        }

        if ($topic->last_ran_at === null) {
            return true;
        }

        $lastRan = strtotime($topic->last_ran_at);
        $now = time();
        $daysSince = ($now - $lastRan) / 86400;

        return match ($topic->frequency) {
            'daily' => $daysSince >= 1,
            'weekly' => $daysSince >= 7,
            'monthly' => $daysSince >= 30,
            'quarterly' => $daysSince >= 90,
            'biannually' => $daysSince >= 180,
            default => false,
        };
    }
}
