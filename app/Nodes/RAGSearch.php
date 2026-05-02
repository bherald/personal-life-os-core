<?php

namespace App\Nodes;

use App\Services\RAGService;
use Exception;

/**
 * RAG Search Node - Semantic search over indexed documents
 *
 * Uses RAGService via DI container for AIService resilience (circuit breaker, retry, fallback).
 */
class RAGSearch extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            // Get query from input or config
            $query = $input['query'] ?? $this->getConfigValue('query');

            if (!$query) {
                throw new Exception('Query is required for RAG search');
            }

            $limit = (int) $this->getConfigValue('limit', 5);
            $documentType = $this->getConfigValue('document_type');

            // Perform RAG search using DI container for proper AIService injection
            $ragService = app(RAGService::class);
            $results = $ragService->search($query, $limit, $documentType);

            // Format results for next node
            $formattedResults = array_map(function ($result) {
                $doc = $result['document'] ?? null;
                if (!$doc) {
                    return [
                        'id' => null,
                        'title' => 'Unknown',
                        'content' => $result['content'] ?? '',
                        'similarity' => $result['similarity'] ?? 0,
                        'metadata' => [],
                        'created_at' => null,
                    ];
                }
                return [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'content' => $doc->content,
                    'similarity' => $result['similarity'],
                    'metadata' => $doc->metadata,
                    'created_at' => $doc->created_at?->toIso8601String(),
                ];
            }, $results);

            return $this->standardOutput([
                'query' => $query,
                'results' => $formattedResults,
                'count' => count($formattedResults),
            ], [
                'search_type' => 'semantic',
                'limit' => $limit,
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }
}
