<?php

namespace App\Services;

class OfflineRerankService
{
    private ?AIService $aiService = null;

    /**
     * @param  array<int, mixed>  $documents
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function rerank(string $query, array $documents, array $options = []): array
    {
        $limit = max(1, min((int) ($options['limit'] ?? count($documents) ?: 1), 50));
        $useEmbeddings = (bool) ($options['use_embeddings'] ?? false);
        $normalized = $this->normalizeDocuments($documents);

        if (trim($query) === '' || empty($normalized)) {
            return [
                'status' => 'invalid_input',
                'mode' => 'none',
                'embedding_status' => 'skipped',
                'results' => [],
            ];
        }

        $queryTokens = $this->tokenCounts($query);
        foreach ($normalized as $index => $document) {
            $normalized[$index]['lexical_score'] = $this->lexicalScore($query, $queryTokens, $document);
            $normalized[$index]['embedding_score'] = null;
            $normalized[$index]['score'] = $normalized[$index]['lexical_score'];
        }

        $embeddingStatus = 'skipped';
        $mode = 'lexical';
        $embeddingMetadata = $this->emptyEmbeddingMetadata();

        if ($useEmbeddings) {
            $embeddingResult = $this->applyEmbeddingScores(
                $query,
                $normalized,
                trim((string) ($options['query_instruction'] ?? '')),
            );
            $normalized = $embeddingResult['documents'];
            $embeddingStatus = $embeddingResult['status'];
            $embeddingMetadata = $embeddingResult['metadata'];
            $mode = $embeddingStatus === 'pass' ? 'embedding_blend' : 'lexical_fallback';
        }

        usort($normalized, static function (array $a, array $b): int {
            $scoreCompare = $b['score'] <=> $a['score'];

            return $scoreCompare !== 0 ? $scoreCompare : strcmp((string) $a['id'], (string) $b['id']);
        });

        $rank = 1;
        $results = [];
        foreach (array_slice($normalized, 0, $limit) as $document) {
            $results[] = [
                'rank' => $rank++,
                'id' => $document['id'],
                'title' => $document['title'],
                'score' => round((float) $document['score'], 6),
                'lexical_score' => round((float) $document['lexical_score'], 6),
                'embedding_score' => $document['embedding_score'] === null ? null : round((float) $document['embedding_score'], 6),
                'source_score' => $document['source_score'],
            ];
        }

        return [
            'status' => 'pass',
            'mode' => $mode,
            'embedding_status' => $embeddingStatus,
            ...$embeddingMetadata,
            'document_count' => count($normalized),
            'result_count' => count($results),
            'results' => $results,
        ];
    }

    /**
     * @param  array<int, mixed>  $documents
     * @return list<array{id:string,title:?string,text:string,source_score:?float}>
     */
    private function normalizeDocuments(array $documents): array
    {
        $normalized = [];

        foreach ($documents as $index => $document) {
            if (is_string($document)) {
                $text = trim($document);
                $normalized[] = [
                    'id' => 'doc_'.($index + 1),
                    'title' => null,
                    'text' => $text,
                    'source_score' => null,
                ];

                continue;
            }

            if (! is_array($document)) {
                continue;
            }

            $text = trim((string) (
                $document['text']
                ?? $document['content']
                ?? $document['body']
                ?? $document['description']
                ?? ''
            ));

            if ($text === '') {
                continue;
            }

            $normalized[] = [
                'id' => (string) ($document['id'] ?? $document['source_id'] ?? 'doc_'.($index + 1)),
                'title' => isset($document['title']) ? trim((string) $document['title']) : null,
                'text' => $text,
                'source_score' => isset($document['score']) || isset($document['similarity'])
                    ? (float) ($document['score'] ?? $document['similarity'])
                    : null,
            ];
        }

        return array_values(array_filter(
            $normalized,
            static fn (array $document): bool => trim($document['text']) !== '',
        ));
    }

    /**
     * @param  array<string, int>  $queryTokens
     * @param  array{id:string,title:?string,text:string,source_score:?float}  $document
     */
    private function lexicalScore(string $query, array $queryTokens, array $document): float
    {
        if (empty($queryTokens)) {
            return 0.0;
        }

        $text = trim(((string) ($document['title'] ?? '')).' '.$document['text']);
        $documentTokens = $this->tokenCounts($text);
        if (empty($documentTokens)) {
            return 0.0;
        }

        $overlap = 0;
        foreach ($queryTokens as $token => $count) {
            $overlap += min($count, $documentTokens[$token] ?? 0);
        }

        $queryTotal = max(1, array_sum($queryTokens));
        $documentTotal = max(1, array_sum($documentTokens));
        $recall = $overlap / $queryTotal;
        $precision = $overlap / $documentTotal;
        $score = (0.78 * $recall) + (0.22 * $precision);

        $lowerQuery = mb_strtolower(trim($query));
        $lowerText = mb_strtolower($text);
        if ($lowerQuery !== '' && str_contains($lowerText, $lowerQuery)) {
            $score += 0.20;
        }

        if (($document['title'] ?? null) !== null && $lowerQuery !== '' && str_contains(mb_strtolower((string) $document['title']), $lowerQuery)) {
            $score += 0.10;
        }

        if ($document['source_score'] !== null) {
            $score += max(0.0, min(1.0, (float) $document['source_score'])) * 0.05;
        }

        return $score;
    }

    /**
     * @return array<string, int>
     */
    private function tokenCounts(string $text): array
    {
        preg_match_all('/[a-z0-9][a-z0-9_-]*/i', mb_strtolower($text), $matches);
        $tokens = array_filter(
            $matches[0] ?? [],
            static fn (string $token): bool => strlen($token) > 1,
        );

        return array_count_values($tokens);
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return array{status:string,documents:list<array<string, mixed>>,metadata:array<string,mixed>}
     */
    private function applyEmbeddingScores(string $query, array $documents, string $queryInstruction = ''): array
    {
        $aiService = $this->resolveAiService();
        if (! $aiService) {
            return ['status' => 'unavailable', 'documents' => $documents, 'metadata' => $this->emptyEmbeddingMetadata()];
        }

        $embeddingQuery = $queryInstruction !== ''
            ? "Instruct: {$queryInstruction}\nQuery: {$query}"
            : $query;
        $queryEmbedding = $aiService->generateEmbedding($embeddingQuery, ['allow_cpu_fallback' => false]);
        if (! ($queryEmbedding['success'] ?? false) || empty($queryEmbedding['embedding']) || ! is_array($queryEmbedding['embedding'])) {
            return ['status' => 'query_embedding_failed', 'documents' => $documents, 'metadata' => $this->emptyEmbeddingMetadata()];
        }

        $queryIdentity = $this->embeddingIdentity($queryEmbedding);
        if ($queryIdentity === null) {
            return ['status' => 'embedding_identity_missing', 'documents' => $documents, 'metadata' => $this->emptyEmbeddingMetadata()];
        }

        $scoredDocuments = $documents;
        $embeddedCount = 0;
        $dimensionMismatch = false;
        $identityMismatch = false;
        $identityMissing = false;
        $instanceIds = [$queryIdentity['instance_id']];
        foreach ($documents as $index => $document) {
            $documentEmbedding = $aiService->generateEmbedding((string) $document['text'], ['allow_cpu_fallback' => false]);
            if (! ($documentEmbedding['success'] ?? false) || empty($documentEmbedding['embedding']) || ! is_array($documentEmbedding['embedding'])) {
                continue;
            }

            if (count($documentEmbedding['embedding']) !== count($queryEmbedding['embedding'])) {
                $dimensionMismatch = true;

                continue;
            }

            $documentIdentity = $this->embeddingIdentity($documentEmbedding);
            if ($documentIdentity === null) {
                $identityMissing = true;

                continue;
            }
            if (! hash_equals($queryIdentity['sha256'], $documentIdentity['sha256'])) {
                $identityMismatch = true;

                continue;
            }
            $instanceIds[] = $documentIdentity['instance_id'];

            $cosine = $this->cosine($queryEmbedding['embedding'], $documentEmbedding['embedding']);
            $embeddingScore = ($cosine + 1.0) / 2.0;
            $scoredDocuments[$index]['embedding_score'] = $embeddingScore;
            $scoredDocuments[$index]['score'] = (0.70 * $embeddingScore) + (0.30 * (float) $document['lexical_score']);
            $embeddedCount++;
        }

        if ($dimensionMismatch) {
            return ['status' => 'embedding_dimension_mismatch', 'documents' => $documents, 'metadata' => $this->emptyEmbeddingMetadata()];
        }

        if ($identityMissing) {
            return ['status' => 'embedding_identity_missing', 'documents' => $documents, 'metadata' => $this->emptyEmbeddingMetadata()];
        }

        if ($identityMismatch) {
            return ['status' => 'embedding_space_mismatch', 'documents' => $documents, 'metadata' => $this->emptyEmbeddingMetadata()];
        }

        if ($embeddedCount !== count($documents)) {
            return [
                'status' => $embeddedCount === 0 ? 'document_embedding_failed' : 'partial_document_embedding_failed',
                'documents' => $documents,
                'metadata' => $this->emptyEmbeddingMetadata(),
            ];
        }

        $instanceIds = array_values(array_unique(array_filter($instanceIds)));
        sort($instanceIds);

        return [
            'status' => 'pass',
            'documents' => $scoredDocuments,
            'metadata' => [
                'embedding_provider' => $queryIdentity['provider'],
                'embedding_model' => $queryIdentity['model'],
                'embedding_revision' => $queryIdentity['revision'],
                'embedding_dimension' => $queryIdentity['dimension'],
                'embedding_identity_sha256' => $queryIdentity['sha256'],
                'embedding_instance_ids' => $instanceIds,
            ],
        ];
    }

    /** @return array{provider:string,model:string,revision:string,dimension:int,instance_id:string,sha256:string}|null */
    private function embeddingIdentity(array $result): ?array
    {
        $provider = trim((string) ($result['provider'] ?? ''));
        $model = trim((string) ($result['model'] ?? ''));
        $revision = trim((string) ($result['revision'] ?? ''));
        $embedding = $result['embedding'] ?? null;
        if ($provider === '' || $model === '' || $revision === '' || ! is_array($embedding) || $embedding === []) {
            return null;
        }

        $dimension = count($embedding);
        $identity = [
            'provider' => $provider,
            'model' => $model,
            'revision' => $revision,
            'dimension' => $dimension,
        ];

        return [
            ...$identity,
            'instance_id' => trim((string) ($result['instance_id'] ?? '')),
            'sha256' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /** @return array<string,mixed> */
    private function emptyEmbeddingMetadata(): array
    {
        return [
            'embedding_provider' => null,
            'embedding_model' => null,
            'embedding_revision' => null,
            'embedding_dimension' => null,
            'embedding_identity_sha256' => null,
            'embedding_instance_ids' => [],
        ];
    }

    private function resolveAiService(): ?AIService
    {
        if ($this->aiService) {
            return $this->aiService;
        }

        try {
            $this->aiService = app(AIService::class);

            return $this->aiService;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<int|float>  $a
     * @param  list<int|float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $count = min(count($a), count($b));
        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $left = (float) $a[$i];
            $right = (float) $b[$i];
            $dot += $left * $right;
            $normA += $left * $left;
            $normB += $right * $right;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
