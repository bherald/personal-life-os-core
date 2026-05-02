<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RAGService;
use App\Services\AIService;
use App\Services\KnowledgeGraphService;
use App\Services\MultimodalEmbeddingService;
use App\Services\QueryRouterService;
use App\Support\PgVector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RAGController extends Controller
{
    private RAGService $ragService;
    private AIService $aiService;
    private KnowledgeGraphService $knowledgeGraphService;
    private MultimodalEmbeddingService $multimodalService;

    public function __construct(
        RAGService $ragService,
        AIService $aiService,
        KnowledgeGraphService $knowledgeGraphService,
        MultimodalEmbeddingService $multimodalService
    ) {
        $this->ragService = $ragService;
        $this->aiService = $aiService;
        $this->knowledgeGraphService = $knowledgeGraphService;
        $this->multimodalService = $multimodalService;
    }

    /**
     * Search indexed documents
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|max:1000',
            'limit' => 'integer|min:1|max:20',
            'document_type' => 'nullable|string',
            'use_graph' => 'nullable|boolean',
            'graph_mode' => 'nullable|in:local,global,drift',
            'use_raptor' => 'nullable|boolean',
            // GR-10: All deepSearch feature toggles
            'use_hype' => 'nullable|boolean',
            'use_crag' => 'nullable|boolean',
            'use_temporal' => 'nullable|boolean',
            'use_relevance_gating' => 'nullable|boolean',
            'use_colbert' => 'nullable|boolean',
            'use_iterative' => 'nullable|boolean',
            'use_auto_strategy' => 'nullable|boolean',
            'security_audit' => 'nullable|boolean',
            'use_lazy_graph' => 'nullable|boolean',
            'use_long_context_rerank' => 'nullable|boolean',
            'use_multimodal' => 'nullable|boolean',
        ]);

        $useGraph  = $validated['use_graph'] ?? false;
        $useRaptor = $validated['use_raptor'] ?? false;
        $limit = $validated['limit'] ?? 5;
        $documentType = $validated['document_type'] ?? null;

        // RAG-1: Adaptive retrieval routing — auto-select strategy when user hasn't explicitly toggled
        $routeInfo = null;
        if (!$useGraph && !$useRaptor) {
            $router = new QueryRouterService();
            $routeInfo = $router->classify($validated['query'], [
                'document_type' => $documentType,
            ]);

            if ($routeInfo['route'] === QueryRouterService::ROUTE_NO_RETRIEVAL) {
                return response()->json([
                    'query' => $validated['query'],
                    'results' => [],
                    'count' => 0,
                    'mode' => 'no_retrieval',
                    'route_reason' => $routeInfo['reason'],
                ]);
            }

            if ($routeInfo['route'] === QueryRouterService::ROUTE_MULTI_STEP) {
                $useRaptor = $routeInfo['params']['use_raptor'] ?? true;
                $useGraph = $routeInfo['params']['use_graph'] ?? true;
            }
        }

        // GR-10: Collect all feature flags for deepSearch
        $useHype             = $validated['use_hype'] ?? false;
        $useCrag             = $validated['use_crag'] ?? false;
        $useTemporal         = $validated['use_temporal'] ?? false;
        $useRelevanceGating  = $validated['use_relevance_gating'] ?? false;
        $useColbert          = $validated['use_colbert'] ?? false;
        $useIterative        = $validated['use_iterative'] ?? false;
        $useAutoStrategy     = $validated['use_auto_strategy'] ?? false;
        $securityAudit       = $validated['security_audit'] ?? false;
        $useLazyGraph        = $validated['use_lazy_graph'] ?? false;
        $useLongContextRerank = $validated['use_long_context_rerank'] ?? false;
        $useMultimodal       = $validated['use_multimodal'] ?? false;

        // Use deepSearch when any advanced feature is toggled
        $anyAdvanced = $useGraph || $useRaptor || $useHype || $useCrag || $useTemporal
            || $useRelevanceGating || $useColbert || $useIterative || $useAutoStrategy
            || $securityAudit || $useLazyGraph || $useLongContextRerank || $useMultimodal;

        if ($anyAdvanced) {
            $deepResults = $this->ragService->deepSearch(
                query: $validated['query'],
                topN: $limit,
                documentType: $documentType,
                useRaptor: $useRaptor,
                useGraph: $useGraph,
                graphMode: $validated['graph_mode'] ?? 'local',
                useHype: $useHype,
                useCrag: $useCrag,
                useTemporal: $useTemporal,
                useRelevanceGating: $useRelevanceGating,
                useColbert: $useColbert,
                useIterative: $useIterative,
                useAutoStrategy: $useAutoStrategy,
                securityAudit: $securityAudit,
                useLazyGraph: $useLazyGraph,
                useLongContextRerank: $useLongContextRerank,
                useMultimodal: $useMultimodal,
            );

            $results = array_map(function ($result) {
                $doc = $result['document'] ?? $result;
                return [
                    'id' => $doc->id ?? $doc['id'] ?? null,
                    'title' => $doc->title ?? $doc['title'] ?? '',
                    'content' => mb_substr($doc->content ?? $doc['content'] ?? '', 0, 200) . '...',
                    'document_type' => $doc->document_type ?? $doc['document_type'] ?? null,
                    'similarity' => round($result['similarity'] ?? $result['score'] ?? 0, 4),
                    'created_at' => $doc->created_at ?? null,
                    'metadata' => $doc->metadata ?? null,
                    'media_url' => $doc->media_url ?? null,
                ];
            }, $deepResults['results'] ?? []);

            return response()->json([
                'query' => $validated['query'],
                'results' => $results,
                'count' => count($results),
                'raptor_results' => $deepResults['raptor_results'] ?? [],
                'graph_results' => $deepResults['graph_results'] ?? [],
                'mode' => $useGraph ? ($validated['graph_mode'] ?? 'local') : 'vector',
            ]);
        }

        // Standard vector search (single_pass or keyword_boost)
        $useHyde = $routeInfo['params']['use_hyde'] ?? false;
        $results = $this->ragService->search(
            $validated['query'],
            $limit,
            $documentType,
            $useHyde
        );

        $mode = $routeInfo ? $routeInfo['route'] : 'vector';

        return response()->json([
            'query' => $validated['query'],
            'results' => array_map(function ($result) {
                return [
                    'id' => $result['document']->id,
                    'title' => $result['document']->title,
                    'content' => substr($result['document']->content, 0, 200) . '...',
                    'document_type' => $result['document']->document_type,
                    'similarity' => round($result['similarity'], 4),
                    'created_at' => $result['document']->created_at,
                    'metadata' => $result['document']->metadata,
                    'media_url' => $result['document']->media_url ?? null,
                ];
            }, $results),
            'count' => count($results),
            'mode' => $mode,
            'route_reason' => $routeInfo['reason'] ?? null,
        ]);
    }

    /**
     * Get RAG statistics
     */
    public function stats(): JsonResponse
    {
        $stats = $this->ragService->getStats();

        return response()->json($stats);
    }

    /**
     * Index a new document
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => 'required|string|max:50',
            'content' => 'required|string',
            'title' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'source_id' => 'nullable|integer',
            'source_type' => 'nullable|string',
        ]);

        $document = $this->ragService->indexDocument(
            $validated['document_type'],
            $validated['content'],
            $validated['title'] ?? null,
            $validated['metadata'] ?? null,
            $validated['source_id'] ?? null,
            $validated['source_type'] ?? null
        );

        return response()->json([
            'message' => 'Document indexed successfully',
            'document' => [
                'id' => $document->id,
                'type' => $document->document_type,
                'title' => $document->title,
            ],
        ], 201);
    }

    /**
     * Find similar documents
     */
    public function similar(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'integer|min:1|max:20',
        ]);

        $results = $this->ragService->findSimilar($id, $validated['limit'] ?? 5);

        return response()->json([
            'source_id' => $id,
            'results' => array_map(function ($result) {
                return [
                    'id' => $result['document']->id,
                    'title' => $result['document']->title,
                    'content' => substr($result['document']->content, 0, 200) . '...',
                    'similarity' => round($result['similarity'], 4),
                    'created_at' => $result['document']->created_at, // Already a string from raw SQL
                ];
            }, $results),
            'count' => count($results),
        ]);
    }

    /**
     * List all documents
     */
    public function list(Request $request): JsonResponse
    {
        // Build WHERE conditions and parameters
        $conditions = [];
        $params = [];

        if ($type = $request->query('document_type')) {
            $conditions[] = 'document_type = ?';
            $params[] = $type;
        }

        if ($designation = $request->query('designation')) {
            $conditions[] = 'designation = ?';
            $params[] = $designation;
        }

        if ($search = $request->query('search')) {
            $conditions[] = "(title ILIKE ? OR content ILIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        // Build WHERE clause
        $whereClause = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

        // Get pagination params
        $perPage = $request->query('per_page', 20);
        $page = $request->query('page', 1);
        $offset = ($page - 1) * $perPage;

        // Get total count using raw SQL (PostgreSQL)
        $sql = "SELECT COUNT(*) as count FROM rag_documents {$whereClause}";
        $total = DB::connection('pgsql_rag')->select($sql, $params)[0]->count ?? 0;

        // Get documents using raw SQL (PostgreSQL)
        // Exclude embedding field to reduce payload size
        $sql = "SELECT id, document_type, title, content, metadata, source_id, source_type,
                       created_at, updated_at, designation, parent_id, content_hash,
                       last_synced_at, media_url
                FROM rag_documents
                {$whereClause}
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;
        $documents = DB::connection('pgsql_rag')->select($sql, $params);

        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return response()->json([
            'documents' => $documents,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
            ],
        ]);
    }

    /**
     * Get single document
     */
    public function show(int $id): JsonResponse
    {
        // Get document using raw SQL (PostgreSQL)
        $sql = "SELECT * FROM rag_documents WHERE id = ? LIMIT 1";
        $documents = DB::connection('pgsql_rag')->select($sql, [$id]);
        $document = $documents[0] ?? null;

        if (!$document) {
            return response()->json([
                'error' => 'Document not found',
            ], 404);
        }

        return response()->json([
            'document' => $document,
        ]);
    }

    /**
     * Update document
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // Check if document exists using raw SQL (PostgreSQL)
        $sql = "SELECT * FROM rag_documents WHERE id = ? LIMIT 1";
        $documents = DB::connection('pgsql_rag')->select($sql, [$id]);
        $document = $documents[0] ?? null;

        if (!$document) {
            return response()->json([
                'error' => 'Document not found',
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        // Build update fields and parameters
        $updateFields = [];
        $params = [];

        if (isset($validated['title'])) {
            $updateFields[] = 'title = ?';
            $params[] = $validated['title'];
        }

        if (isset($validated['content'])) {
            $updateFields[] = 'content = ?';
            $params[] = $validated['content'];

            // Regenerate embedding if content changed
            $embeddingResult = $this->aiService->generateEmbedding($validated['content']);
            if ($embeddingResult['success'] && !empty($embeddingResult['embedding'])) {
                $embeddingStr = PgVector::literal($embeddingResult['embedding']);
                $updateFields[] = 'embedding = ?::vector';
                $params[] = $embeddingStr;
            }
        }

        if (isset($validated['metadata'])) {
            $updateFields[] = 'metadata = ?';
            $params[] = json_encode($validated['metadata']);
        }

        if (!empty($updateFields)) {
            $updateFields[] = 'updated_at = NOW()';
            $params[] = $id;

            $sql = "UPDATE rag_documents SET " . implode(', ', $updateFields) . " WHERE id = ?";
            DB::connection('pgsql_rag')->update($sql, $params);
        }

        // Fetch updated document
        $sql = "SELECT * FROM rag_documents WHERE id = ? LIMIT 1";
        $updatedDocument = DB::connection('pgsql_rag')->select($sql, [$id])[0] ?? null;

        return response()->json([
            'message' => 'Document updated successfully',
            'document' => $updatedDocument,
        ]);
    }

    /**
     * Delete document
     */
    public function destroy(int $id): JsonResponse
    {
        // Check if document exists using raw SQL (PostgreSQL)
        $sql = "SELECT id FROM rag_documents WHERE id = ? LIMIT 1";
        $documents = DB::connection('pgsql_rag')->select($sql, [$id]);

        if (empty($documents)) {
            return response()->json([
                'error' => 'Document not found',
            ], 404);
        }

        // Delete document using raw SQL (PostgreSQL)
        $sql = "DELETE FROM rag_documents WHERE id = ?";
        DB::connection('pgsql_rag')->delete($sql, [$id]);

        return response()->json([
            'message' => 'Document deleted successfully',
        ]);
    }

    /**
     * Bulk delete documents
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        // Delete documents using raw SQL (PostgreSQL) with IN clause
        $placeholders = implode(',', array_fill(0, count($validated['ids']), '?'));
        $sql = "DELETE FROM rag_documents WHERE id IN ({$placeholders})";
        $deleted = DB::connection('pgsql_rag')->delete($sql, $validated['ids']);

        return response()->json([
            'message' => "{$deleted} documents deleted successfully",
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Reindex document (regenerate embedding)
     */
    public function reindex(int $id): JsonResponse
    {
        // Get document using raw SQL (PostgreSQL)
        $sql = "SELECT * FROM rag_documents WHERE id = ? LIMIT 1";
        $documents = DB::connection('pgsql_rag')->select($sql, [$id]);
        $document = $documents[0] ?? null;

        if (!$document) {
            return response()->json([
                'error' => 'Document not found',
            ], 404);
        }

        // Generate new embedding
        $embeddingResult = $this->aiService->generateEmbedding($document->content);

        if (!$embeddingResult['success'] || empty($embeddingResult['embedding'])) {
            return response()->json([
                'error' => 'Failed to generate embedding: ' . ($embeddingResult['error'] ?? 'unknown error'),
            ], 500);
        }

        // Format embedding for PostgreSQL vector type
        $embeddingStr = PgVector::literal($embeddingResult['embedding']);

        // Update embedding using raw SQL (PostgreSQL)
        $sql = "UPDATE rag_documents SET embedding = ?::vector, updated_at = NOW() WHERE id = ?";
        DB::connection('pgsql_rag')->update($sql, [$embeddingStr, $id]);

        // Fetch updated document
        $sql = "SELECT * FROM rag_documents WHERE id = ? LIMIT 1";
        $updatedDocument = DB::connection('pgsql_rag')->select($sql, [$id])[0] ?? null;

        return response()->json([
            'message' => 'Document reindexed successfully',
            'document' => $updatedDocument,
        ]);
    }

    // =========================================================================
    // KNOWLEDGE GRAPH
    // =========================================================================

    /**
     * Get knowledge graph statistics
     */
    public function graphStats(): JsonResponse
    {
        try {
            $stats = $this->knowledgeGraphService->getStatistics();

            // Return stats directly for simpler Vue component consumption
            return response()->json($stats);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get knowledge graph stats',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extract entities from text
     */
    public function extractEntities(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'text' => 'required|string|max:50000',
                'source_document_id' => 'nullable|integer',
                'persist' => 'nullable|boolean',
                'min_confidence' => 'nullable|numeric|min:0|max:1',
            ]);

            $result = $this->knowledgeGraphService->extractEntities(
                $validated['text'],
                [
                    'source_document_id' => $validated['source_document_id'] ?? null,
                    'persist' => $validated['persist'] ?? true,
                    'min_confidence' => $validated['min_confidence'] ?? 0.5,
                ]
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to extract entities',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add a triple to the knowledge graph
     */
    public function addTriple(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'subject' => 'required|string|max:255',
                'predicate' => 'required|string|max:100',
                'object' => 'required|string|max:255',
                'subject_type' => 'nullable|string|max:50',
                'object_type' => 'nullable|string|max:50',
                'confidence' => 'nullable|numeric|min:0|max:1',
                'extracted_from' => 'nullable|string|max:500',
                'source_document_id' => 'nullable|integer',
            ]);

            $tripleId = $this->knowledgeGraphService->addTriple(
                $validated['subject'],
                $validated['predicate'],
                $validated['object'],
                [
                    'subject_type' => $validated['subject_type'] ?? 'other',
                    'object_type' => $validated['object_type'] ?? 'other',
                    'confidence' => $validated['confidence'] ?? 1.0,
                    'extracted_from' => $validated['extracted_from'] ?? null,
                    'source_document_id' => $validated['source_document_id'] ?? null,
                ]
            );

            return response()->json([
                'success' => true,
                'triple_id' => $tripleId,
                'message' => 'Triple added successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to add triple',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Find relationships for an entity
     */
    public function findRelationships(Request $request, string $entity): JsonResponse
    {
        try {
            $validated = $request->validate([
                'direction' => 'nullable|string|in:outgoing,incoming,both',
                'predicates' => 'nullable|array',
                'predicates.*' => 'string',
                'min_confidence' => 'nullable|numeric|min:0|max:1',
                'limit' => 'nullable|integer|min:1|max:500',
            ]);

            $relationships = $this->knowledgeGraphService->findRelationships(
                urldecode($entity),
                [
                    'direction' => $validated['direction'] ?? 'both',
                    'predicates' => $validated['predicates'] ?? [],
                    'min_confidence' => $validated['min_confidence'] ?? 0.0,
                    'limit' => $validated['limit'] ?? 100,
                ]
            );

            return response()->json([
                'success' => true,
                'entity' => urldecode($entity),
                'relationships' => $relationships,
                'count' => count($relationships),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to find relationships',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get entity graph with multi-hop traversal
     */
    public function getEntityGraph(Request $request, string $entity): JsonResponse
    {
        try {
            $validated = $request->validate([
                'depth' => 'nullable|integer|min:1|max:5',
                'min_confidence' => 'nullable|numeric|min:0|max:1',
                'max_nodes' => 'nullable|integer|min:1|max:200',
                'exclude_predicates' => 'nullable|array',
            ]);

            $graph = $this->knowledgeGraphService->getEntityGraph(
                urldecode($entity),
                $validated['depth'] ?? 2,
                [
                    'min_confidence' => $validated['min_confidence'] ?? 0.5,
                    'max_nodes' => $validated['max_nodes'] ?? 50,
                    'exclude_predicates' => $validated['exclude_predicates'] ?? [],
                ]
            );

            return response()->json($graph);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get entity graph',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search entities by name
     */
    public function searchEntities(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'query' => 'required|string|max:255',
                'types' => 'nullable|array',
                'types.*' => 'string',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            $entities = $this->knowledgeGraphService->searchEntities(
                $validated['query'],
                [
                    'types' => $validated['types'] ?? [],
                    'limit' => $validated['limit'] ?? 20,
                ]
            );

            return response()->json([
                'success' => true,
                'query' => $validated['query'],
                'entities' => $entities,
                'count' => count($entities),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to search entities',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search by relationship type
     */
    public function searchByRelationship(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'predicate' => 'required|string|max:100',
                'object' => 'nullable|string|max:255',
                'subject_type' => 'nullable|string|max:50',
                'object_type' => 'nullable|string|max:50',
                'min_confidence' => 'nullable|numeric|min:0|max:1',
                'limit' => 'nullable|integer|min:1|max:500',
            ]);

            $triples = $this->knowledgeGraphService->searchByRelationship(
                $validated['predicate'],
                $validated['object'] ?? null,
                [
                    'subject_type' => $validated['subject_type'] ?? null,
                    'object_type' => $validated['object_type'] ?? null,
                    'min_confidence' => $validated['min_confidence'] ?? 0.0,
                    'limit' => $validated['limit'] ?? 100,
                ]
            );

            return response()->json([
                'success' => true,
                'predicate' => $validated['predicate'],
                'count' => count($triples),
                'data' => $triples,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to search by relationship',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Merge two entities
     */
    public function mergeEntities(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'source_id' => 'required|integer',
                'target_id' => 'required|integer|different:source_id',
            ]);

            $success = $this->knowledgeGraphService->mergeEntities(
                $validated['source_id'],
                $validated['target_id']
            );

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to merge entities',
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'Entities merged successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to merge entities',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a triple
     */
    public function deleteTriple(int $id): JsonResponse
    {
        try {
            $success = $this->knowledgeGraphService->deleteTriple($id);

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'error' => 'Triple not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Triple deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete triple',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an entity and its triples
     */
    public function deleteEntity(int $id): JsonResponse
    {
        try {
            $success = $this->knowledgeGraphService->deleteEntity($id);

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'error' => 'Entity not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Entity deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete entity',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get community detection statistics
     */
    public function communityStats(): JsonResponse
    {
        try {
            $service = app(\App\Services\CommunityDetectionService::class);
            return response()->json($service->getStatistics());
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get community stats',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get full graph data for visualization (nodes + edges + communities)
     */
    public function getFullGraph(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'min_confidence' => 'nullable|numeric|min:0|max:1',
                'max_nodes' => 'nullable|integer|min:1|max:500',
                'entity_type' => 'nullable|string',
            ]);

            $minConf = $validated['min_confidence'] ?? 0.3;
            $maxNodes = $validated['max_nodes'] ?? 200;
            $entityType = $validated['entity_type'] ?? null;

            $conn = 'pgsql_rag';

            // Get entities
            $typeFilter = $entityType ? "AND entity_type = ?" : "";
            $entityParams = [$maxNodes];
            if ($entityType) {
                $entityParams = [$entityType, $maxNodes];
            }

            $entities = DB::connection($conn)->select("
                SELECT id, canonical_name, entity_type, aliases, degree, pagerank, primary_community_id
                FROM knowledge_graph_entities
                WHERE 1=1 {$typeFilter}
                ORDER BY degree DESC, pagerank DESC
                LIMIT ?
            ", $entityParams);

            $entityIds = array_map(fn($e) => $e->id, $entities);

            // Get edges between these entities
            $edges = [];
            if (!empty($entityIds)) {
                $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
                $edges = DB::connection($conn)->select("
                    SELECT subject_entity_id, object_entity_id, predicate, confidence
                    FROM knowledge_graph
                    WHERE subject_entity_id IN ({$placeholders})
                      AND object_entity_id IN ({$placeholders})
                      AND confidence >= ?
                ", array_merge($entityIds, $entityIds, [$minConf]));
            }

            // Get communities
            $communities = DB::connection($conn)->select("
                SELECT c.id, c.community_id, c.level, c.entity_count, c.entity_ids,
                       cr.title, cr.summary, cr.rating
                FROM knowledge_graph_communities c
                LEFT JOIN knowledge_graph_community_reports cr ON cr.community_id = c.id
                WHERE c.level = 0
                ORDER BY c.entity_count DESC
            ");

            // Build node list
            $nodes = array_map(function ($e) {
                return [
                    'id' => $e->id,
                    'label' => $e->canonical_name,
                    'type' => $e->entity_type,
                    'degree' => $e->degree ?? 0,
                    'pagerank' => round((float) ($e->pagerank ?? 0), 6),
                    'community_id' => $e->primary_community_id,
                ];
            }, $entities);

            // Build edge list
            $links = array_map(function ($e) {
                return [
                    'source' => $e->subject_entity_id,
                    'target' => $e->object_entity_id,
                    'predicate' => $e->predicate,
                    'confidence' => round((float) $e->confidence, 3),
                ];
            }, $edges);

            // Build community list
            $communityList = array_map(function ($c) {
                return [
                    'id' => $c->id,
                    'community_id' => $c->community_id,
                    'entity_count' => $c->entity_count,
                    'entity_ids' => json_decode($c->entity_ids ?? '[]', true),
                    'title' => $c->title,
                    'summary' => $c->summary,
                    'rating' => $c->rating,
                ];
            }, $communities);

            return response()->json([
                'nodes' => $nodes,
                'links' => $links,
                'communities' => $communityList,
                'total_nodes' => count($nodes),
                'total_links' => count($links),
                'total_communities' => count($communityList),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get graph data',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // MULTIMODAL EMBEDDINGS
    // =========================================================================

    /**
     * Get multimodal/visual embedding statistics
     */
    public function visualStats(): JsonResponse
    {
        try {
            $stats = $this->multimodalService->getStats();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get visual stats',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search using visual/image content
     */
    public function visualSearch(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'query' => 'required|string|max:1000',
                'limit' => 'nullable|integer|min:1|max:50',
                'document_type' => 'nullable|string',
                'hybrid' => 'nullable|boolean',
            ]);

            $result = $this->multimodalService->searchVisual(
                $validated['query'],
                $validated['limit'] ?? 10,
                [
                    'document_type' => $validated['document_type'] ?? null,
                    'hybrid' => $validated['hybrid'] ?? true,
                ]
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Visual search failed',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Hybrid text + visual search
     */
    public function hybridSearch(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'query' => 'required|string|max:1000',
                'limit' => 'nullable|integer|min:1|max:50',
                'document_type' => 'nullable|string',
                'text_weight' => 'nullable|numeric|min:0|max:1',
                'visual_weight' => 'nullable|numeric|min:0|max:1',
            ]);

            $result = $this->multimodalService->hybridTextVisualSearch(
                $validated['query'],
                $validated['limit'] ?? 10,
                [
                    'document_type' => $validated['document_type'] ?? null,
                    'text_weight' => $validated['text_weight'] ?? 0.6,
                    'visual_weight' => $validated['visual_weight'] ?? 0.4,
                ]
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Hybrid search failed',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get documents with visual content
     */
    public function visualDocuments(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:500',
                'document_type' => 'nullable|string',
                'analyzed_only' => 'nullable|boolean',
                'pending_only' => 'nullable|boolean',
            ]);

            $result = $this->multimodalService->getVisualDocuments(
                $validated['limit'] ?? 100,
                [
                    'document_type' => $validated['document_type'] ?? null,
                    'analyzed_only' => $validated['analyzed_only'] ?? true,
                    'pending_only' => $validated['pending_only'] ?? false,
                ]
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get visual documents',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Analyze and embed visual content for a document
     */
    public function analyzeVisual(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'image_path' => 'nullable|string',
                'force' => 'nullable|boolean',
            ]);

            $result = $this->multimodalService->analyzeAndEmbed($id, [
                'image_path' => $validated['image_path'] ?? null,
                'force' => $validated['force'] ?? false,
            ]);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to analyze visual content',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Batch analyze visual content
     */
    public function batchAnalyzeVisual(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'document_ids' => 'required|array|min:1|max:50',
                'document_ids.*' => 'integer',
                'force' => 'nullable|boolean',
            ]);

            $result = $this->multimodalService->batchAnalyze(
                $validated['document_ids'],
                ['force' => $validated['force'] ?? false]
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Batch analysis failed',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate embedding for an image (without storing)
     */
    public function generateImageEmbedding(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'image_path' => 'required|string',
                'detail_level' => 'nullable|string|in:brief,detailed,comprehensive',
                'focus' => 'nullable|string|max:200',
            ]);

            $result = $this->multimodalService->generateImageEmbedding(
                $validated['image_path'],
                [
                    'detail_level' => $validated['detail_level'] ?? 'detailed',
                    'focus' => $validated['focus'] ?? null,
                ]
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate image embedding',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // QUERY TRACING
    // =========================================================================

    public function recentTraces(Request $request): JsonResponse
    {
        try {
            $service = app(\App\Services\RAGTracingService::class);
            $limit = (int) ($request->query('limit', 20));
            $traces = $service->getRecentTraces(min($limit, 100));

            return response()->json([
                'success' => true,
                'data' => $traces,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch traces',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
