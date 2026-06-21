<?php

namespace App\Nodes;

use App\Services\RAGService;
use App\Services\SystemConfigService;
use Exception;

/**
 * RAG Search Node - Semantic search over indexed documents
 *
 * Uses RAGService via DI container for AIService resilience (circuit breaker, retry, fallback).
 */
class RAGSearch extends BaseNode
{
    private const RUNTIME_POLICY_CONFIG_KEY = 'rag.search_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'default_limit' => 5,
    ];

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function execute(array $input): array
    {
        try {
            // Get query from input or config
            $query = $input['query'] ?? $this->getConfigValue('query');

            if (!$query) {
                throw new Exception('Query is required for RAG search');
            }

            $limit = $this->configuredPositiveInt('limit') ?? $this->runtimePolicyInt('default_limit');
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
                'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    private function configuredPositiveInt(string $key): ?int
    {
        $value = $this->getConfigValue($key);
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        if ($this->runtimePolicy !== null) {
            return $this->runtimePolicy;
        }

        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return $this->runtimePolicy = array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }
}
