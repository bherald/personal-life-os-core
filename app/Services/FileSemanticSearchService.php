<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class FileSemanticSearchService
{
    private ?AIService $aiService = null;
    private ?RAGService $ragService = null;

    private function getAIService(): AIService
    {
        if ($this->aiService === null) {
            $this->aiService = app(AIService::class);
        }
        return $this->aiService;
    }

    private function getRAGService(): RAGService
    {
        if ($this->ragService === null) {
            $this->ragService = app(RAGService::class);
        }
        return $this->ragService;
    }

    public function generateDescription(int $fileRegistryId): array
    {
        $file = DB::selectOne(
            "SELECT id, current_path, filename, mime_type, file_size FROM file_registry WHERE id = ?",
            [$fileRegistryId]
        );

        if (!$file) {
            return ['success' => false, 'error' => 'File not found'];
        }

        $prompt = "Describe this file in 1-2 sentences for search indexing. File: {$file->filename}, Type: {$file->mime_type}, Path: {$file->current_path}";

        try {
            $result = $this->getAIService()->process($prompt, [
                'max_tokens' => 200,
                'system' => 'You are a file cataloging assistant. Provide brief, searchable descriptions.',
            ]);

            $description = $result['response'] ?? $result['content'] ?? '';

            if ($description) {
                DB::update(
                    "UPDATE file_registry SET ai_description = ?, ai_analyzed_at = NOW() WHERE id = ?",
                    [$description, $fileRegistryId]
                );
            }

            return ['success' => true, 'description' => $description];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function generateKeywords(int $fileRegistryId): array
    {
        $file = DB::selectOne(
            "SELECT id, current_path, filename, mime_type, ai_description FROM file_registry WHERE id = ?",
            [$fileRegistryId]
        );

        if (!$file) {
            return ['success' => false, 'error' => 'File not found'];
        }

        $context = "File: {$file->filename}\nPath: {$file->current_path}\nType: {$file->mime_type}";
        if ($file->ai_description) {
            $context .= "\nDescription: {$file->ai_description}";
        }

        $prompt = "Extract 5-10 search keywords for this file. Return only comma-separated keywords, no explanation.\n\n{$context}";

        try {
            $result = $this->getAIService()->process($prompt, [
                'max_tokens' => 100,
                'system' => 'You extract search keywords. Return only comma-separated keywords.',
            ]);

            $keywords = $result['response'] ?? $result['content'] ?? '';

            if ($keywords) {
                DB::update(
                    "UPDATE file_registry SET search_keywords = ? WHERE id = ?",
                    [$keywords, $fileRegistryId]
                );
            }

            return ['success' => true, 'keywords' => $keywords];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function searchFiles(string $query, int $limit = 20): array
    {
        // Search across filename, path, ai_description, and keywords
        $searchTerm = '%' . $query . '%';

        $results = DB::select(
            "SELECT id, asset_uuid, current_path, filename, mime_type, file_size,
                    ai_description, search_keywords,
                    CASE
                        WHEN filename LIKE ? THEN 3
                        WHEN search_keywords LIKE ? THEN 2
                        WHEN ai_description LIKE ? THEN 1
                        ELSE 0
                    END as relevance
             FROM file_registry
             WHERE filename LIKE ? OR search_keywords LIKE ? OR ai_description LIKE ? OR current_path LIKE ?
             ORDER BY relevance DESC, filename ASC
             LIMIT ?",
            [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]
        );

        return $results;
    }

    public function batchGenerateDescriptions(?string $pathPrefix = null, int $limit = 10): array
    {
        $params = [];
        $where = "WHERE ai_description IS NULL";
        if ($pathPrefix) {
            $where .= " AND current_path LIKE ?";
            $params[] = $pathPrefix . '%';
        }
        $params[] = $limit;

        $files = DB::select(
            "SELECT id, filename FROM file_registry {$where} ORDER BY id ASC LIMIT ?",
            $params
        );

        $results = ['processed' => 0, 'success' => 0, 'failed' => 0];

        foreach ($files as $file) {
            $results['processed']++;
            $result = $this->generateDescription($file->id);
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    public function indexToRAG(int $fileRegistryId): array
    {
        $file = DB::selectOne(
            "SELECT id, asset_uuid, current_path, filename, mime_type, ai_description, search_keywords
             FROM file_registry WHERE id = ?",
            [$fileRegistryId]
        );

        if (!$file || !$file->ai_description) {
            return ['success' => false, 'error' => 'File not found or no description'];
        }

        $content = "File: {$file->filename}\nPath: {$file->current_path}\nType: {$file->mime_type}\n\n{$file->ai_description}";
        if ($file->search_keywords) {
            $content .= "\n\nKeywords: {$file->search_keywords}";
        }

        try {
            $result = $this->getRAGService()->indexDocument(
                $content,
                'file_registry',
                [
                    'file_registry_id' => $file->id,
                    'asset_uuid' => $file->asset_uuid,
                    'filename' => $file->filename,
                    'mime_type' => $file->mime_type,
                ],
                "file_registry_{$file->id}"
            );

            return ['success' => true, 'indexed' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
