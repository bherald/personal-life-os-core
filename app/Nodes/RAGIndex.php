<?php

namespace App\Nodes;

use App\Services\RAGService;
use Exception;

/**
 * RAG Index Node - Index workflow outputs for semantic search
 *
 * Uses DI container for proper AIService resilience (circuit breaker, retry, fallback).
 */
class RAGIndex extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $documentType = $this->getConfigValue('document_type', 'workflow_output');
            $title = $this->getConfigValue('title') ?? $input['title'] ?? null;

            // Extract content from input
            $content = $this->extractContent($input);

            if (!$content) {
                throw new Exception('No content to index');
            }

            // Index the document using DI for proper AIService injection
            $ragService = app(RAGService::class);
            $document = $ragService->indexDocument(
                $documentType,
                $content,
                $title,
                $input['metadata'] ?? null,
                $input['source_id'] ?? null,
                $input['source_type'] ?? null
            );

            // Pass through the input data to next node
            return $this->standardOutput([
                'indexed' => true,
                'document_id' => $document->id,
                'original_data' => $input,
            ], [
                'document_type' => $documentType,
                'content_length' => strlen($content),
            ]);

        } catch (Exception $e) {
            return $this->standardOutput($input, ['indexed' => false], $e->getMessage());
        }
    }

    private function extractContent(array $input): ?string
    {
        // Try to extract meaningful content
        if (isset($input['content'])) {
            return is_string($input['content']) ? $input['content'] : json_encode($input['content']);
        }

        if (isset($input['data'])) {
            return is_string($input['data']) ? $input['data'] : json_encode($input['data']);
        }

        // Fall back to entire input
        return json_encode($input);
    }
}
