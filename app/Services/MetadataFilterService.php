<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

/**
 * Metadata Filter Service
 *
 * Extracts structured filters from natural language queries
 * and builds SQL WHERE clauses for RAG document metadata filtering.
 */
class MetadataFilterService
{
    private ?AIService $aiService = null;

    private function getAIService(): AIService
    {
        if ($this->aiService === null) {
            $this->aiService = app(AIService::class);
        }
        return $this->aiService;
    }

    public function extractFilters(string $query): array
    {
        $cacheKey = 'metadata_filters_' . md5($query);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $filters = [];

        // Heuristic extraction (fast path)
        // Source type: "from joplin", "in youtube", "from email"
        if (preg_match('/\b(from|in|source:?)\s+(joplin|youtube|email|research|file|workflow)\b/i', $query, $m)) {
            $typeMap = [
                'joplin' => 'joplin_note',
                'youtube' => 'youtube_transcript',
                'email' => 'email',
                'research' => 'research',
                'file' => 'file_registry',
                'workflow' => 'workflow',
            ];
            $filters['source_type'] = $typeMap[strtolower($m[2])] ?? strtolower($m[2]);
        }

        // Date range: "last week", "this month", "from January"
        if (preg_match('/\b(last|past)\s+(week|month|year|(\d+)\s+days?)\b/i', $query, $m)) {
            $unit = strtolower($m[2]);
            if (str_contains($unit, 'day')) {
                $days = (int)($m[3] ?? 7);
                $filters['date_after'] = date('Y-m-d', strtotime("-{$days} days"));
            } elseif ($unit === 'week') {
                $filters['date_after'] = date('Y-m-d', strtotime('-1 week'));
            } elseif ($unit === 'month') {
                $filters['date_after'] = date('Y-m-d', strtotime('-1 month'));
            } elseif ($unit === 'year') {
                $filters['date_after'] = date('Y-m-d', strtotime('-1 year'));
            }
        }

        if (preg_match('/\bthis\s+(week|month|year)\b/i', $query, $m)) {
            $unit = strtolower($m[1]);
            $filters['date_after'] = date('Y-m-d', strtotime("first day of this {$unit}"));
        }

        // Specific document type
        if (preg_match('/\b(pdf|video|audio|image|note|transcript)\b/i', $query, $m)) {
            $docTypeMap = [
                'pdf' => 'pdf',
                'video' => 'video',
                'audio' => 'audio',
                'image' => 'image',
                'note' => 'joplin_note',
                'transcript' => 'youtube_transcript',
            ];
            $filters['document_type'] = $docTypeMap[strtolower($m[1])] ?? strtolower($m[1]);
        }

        // Tag filter: "tagged as", "tag:"
        if (preg_match('/\b(?:tagged?\s+(?:as|with)|tag:)\s*"?(\w+)"?\b/i', $query, $m)) {
            $filters['tag'] = strtolower($m[1]);
        }

        Cache::put($cacheKey, $filters, 1800);
        return $filters;
    }

    public function buildFilterSQL(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['source_type'])) {
            $conditions[] = "document_type = ?";
            $params[] = $filters['source_type'];
        }

        if (!empty($filters['document_type'])) {
            $conditions[] = "document_type = ?";
            $params[] = $filters['document_type'];
        }

        if (!empty($filters['date_after'])) {
            $conditions[] = "created_at >= ?";
            $params[] = $filters['date_after'];
        }

        if (!empty($filters['date_before'])) {
            $conditions[] = "created_at <= ?";
            $params[] = $filters['date_before'];
        }

        if (!empty($filters['tag'])) {
            $conditions[] = "metadata->>'tags' ILIKE ?";
            $params[] = '%' . $filters['tag'] . '%';
        }

        if (!empty($filters['source_id'])) {
            $conditions[] = "source_id = ?";
            $params[] = $filters['source_id'];
        }

        return [
            'where' => !empty($conditions) ? 'AND ' . implode(' AND ', $conditions) : '',
            'params' => $params,
        ];
    }

    public function applyFilters(string $baseQuery, array $filters): array
    {
        $sql = $this->buildFilterSQL($filters);
        return $sql;
    }

    public function getSupportedFilters(): array
    {
        return [
            'source_type' => [
                'description' => 'Document source type',
                'values' => ['joplin_note', 'youtube_transcript', 'email', 'research', 'file_registry', 'workflow'],
                'examples' => ['from joplin', 'in youtube', 'source:email'],
            ],
            'document_type' => [
                'description' => 'Document content type',
                'values' => ['pdf', 'video', 'audio', 'image', 'note', 'transcript'],
                'examples' => ['pdf documents', 'video transcripts'],
            ],
            'date_range' => [
                'description' => 'Temporal filter',
                'examples' => ['last week', 'this month', 'past 30 days', 'last year'],
            ],
            'tag' => [
                'description' => 'Metadata tag filter',
                'examples' => ['tagged as important', 'tag:genealogy'],
            ],
        ];
    }

    public function cleanQuery(string $query, array $filters): string
    {
        $cleaned = $query;

        // Remove filter phrases from query for better semantic search
        $patterns = [
            '/\b(from|in|source:?)\s+(joplin|youtube|email|research|file|workflow)\b/i',
            '/\b(last|past)\s+(week|month|year|\d+\s+days?)\b/i',
            '/\bthis\s+(week|month|year)\b/i',
            '/\b(?:tagged?\s+(?:as|with)|tag:)\s*"?\w+"?\b/i',
        ];

        foreach ($patterns as $pattern) {
            $cleaned = preg_replace($pattern, '', $cleaned);
        }

        return trim(preg_replace('/\s+/', ' ', $cleaned));
    }
}
