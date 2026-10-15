<?php

namespace App\Console\Commands;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\OfflineRerankService;
use App\Services\RerankerSidecarService;
use Illuminate\Console\Command;

class RagRerankCommand extends Command
{
    protected $signature = 'rag:rerank
        {--query= : Query text}
        {--query-b64= : Query text encoded as base64}
        {--documents-json= : JSON array of strings or objects with id/title/text/content}
        {--documents-json-b64= : Base64-encoded JSON document array}
        {--limit=10 : Number of ranked results to return}
        {--use-embeddings : Blend lexical ranking with PLOS AIService embeddings}
        {--cross-encoder : Use the exact admitted native CPU reranker; no lexical or chat fallback}
        {--no-embeddings : Force lexical-only ranking}
        {--example : Run a built-in lexical smoke example}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Rerank arbitrary documents locally with lexical scoring and optional table-routed embeddings';

    public function handle(OfflineRerankService $reranker): int
    {
        $input = $this->inputPayload();
        if ($input['error'] !== null) {
            return $this->finish([
                'schema' => 'plos_rag_rerank.v1',
                'status' => 'invalid_input',
                'mode' => 'none',
                'read_only' => true,
                'error' => $input['error'],
            ], self::FAILURE);
        }

        if ((bool) $this->option('cross-encoder')) {
            return $this->controlledRerank($input);
        }

        $limit = max(1, min((int) $this->option('limit'), 50));
        $useEmbeddings = (bool) $this->option('use-embeddings') && ! (bool) $this->option('no-embeddings');
        $started = microtime(true);

        $result = $reranker->rerank(
            (string) $input['query'],
            $input['documents'],
            [
                'limit' => $limit,
                'use_embeddings' => $useEmbeddings,
            ],
        );

        $payload = [
            'schema' => 'plos_rag_rerank.v1',
            'status' => $result['status'],
            'mode' => $result['mode'],
            'read_only' => true,
            'query_sha256' => hash('sha256', (string) $input['query']),
            'document_count' => count($input['documents']),
            'limit' => $limit,
            'use_embeddings' => $useEmbeddings,
            'embedding_status' => $result['embedding_status'],
            'embedding_provider' => $result['embedding_provider'] ?? null,
            'embedding_model' => $result['embedding_model'] ?? null,
            'embedding_revision' => $result['embedding_revision'] ?? null,
            'embedding_dimension' => $result['embedding_dimension'] ?? null,
            'embedding_identity_sha256' => $result['embedding_identity_sha256'] ?? null,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'results' => $result['results'],
        ];

        return $this->finish($payload, $result['status'] === 'pass' ? self::SUCCESS : self::FAILURE);
    }

    /**
     * @return array{query:string,documents:array<int, mixed>,error:?string}
     */
    private function inputPayload(): array
    {
        if ((bool) $this->option('example')) {
            return [
                'query' => 'offline genealogy media transcription',
                'documents' => [
                    ['id' => 'dev', 'title' => 'Developer notes', 'text' => 'Small offline coding model for Laravel maintenance.'],
                    ['id' => 'media', 'title' => 'Media transcript plan', 'text' => 'Offline genealogy media transcription and local whisper readiness.'],
                    ['id' => 'weather', 'title' => 'Weather report', 'text' => 'Daily weather summary and notification formatting.'],
                ],
                'error' => null,
            ];
        }

        $query = (string) ($this->option('query') ?: '');
        if (! $this->option('cross-encoder')) {
            $query = trim($query);
        }
        $queryB64 = trim((string) ($this->option('query-b64') ?: ''));
        if ($queryB64 !== '') {
            $decoded = base64_decode($queryB64, true);
            if ($decoded === false) {
                return ['query' => '', 'documents' => [], 'error' => '--query-b64 is not valid base64.'];
            }
            $query = $this->option('cross-encoder') ? $decoded : trim($decoded);
        }

        $json = trim((string) ($this->option('documents-json') ?: ''));
        $jsonB64 = trim((string) ($this->option('documents-json-b64') ?: ''));
        if ($jsonB64 !== '') {
            $decoded = base64_decode($jsonB64, true);
            if ($decoded === false) {
                return ['query' => '', 'documents' => [], 'error' => '--documents-json-b64 is not valid base64.'];
            }
            $json = $decoded;
        }

        if (trim($query) === '') {
            return ['query' => '', 'documents' => [], 'error' => 'A query is required.'];
        }

        if (trim($json) === '') {
            return ['query' => '', 'documents' => [], 'error' => 'Documents JSON is required.'];
        }

        try {
            $documents = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['query' => '', 'documents' => [], 'error' => 'Documents JSON is invalid: '.$e->getMessage()];
        }

        if (! is_array($documents) || array_is_list($documents) === false || empty($documents)) {
            return ['query' => '', 'documents' => [], 'error' => 'Documents JSON must be a non-empty array.'];
        }

        return ['query' => $query, 'documents' => $documents, 'error' => null];
    }

    private function controlledRerank(array $input): int
    {
        $documents = [];
        foreach ($input['documents'] as $index => $document) {
            $text = is_string($document) ? $document : ($document['text'] ?? $document['content'] ?? null);
            $id = is_array($document) ? ($document['id'] ?? 'doc_'.($index + 1)) : 'doc_'.($index + 1);
            if (! is_string($text) || ! is_string($id)) {
                return $this->finish(['schema' => 'plos_rag_rerank.v1', 'status' => 'invalid_input',
                    'mode' => 'cross_encoder', 'read_only' => true, 'results' => []], self::FAILURE);
            }
            $documents[] = ['id' => $id, 'text' => $text];
        }
        try {
            $result = app(RerankerSidecarService::class)->rerankControlled($input['query'], $documents);
        } catch (InferenceAdmissionException) {
            return $this->finish(['schema' => 'plos_rag_rerank.v1', 'status' => 'held', 'mode' => 'cross_encoder',
                'read_only' => true, 'results' => [], 'error' => 'Native admission or outcome requires reconciliation; no replay.'], self::FAILURE);
        }
        $scores = $result['results'] ?? [];
        usort($scores, fn ($left, $right) => $right['score'] <=> $left['score']);
        $scores = array_slice($scores, 0, max(1, min((int) $this->option('limit'), 50)));
        foreach ($scores as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);

        return $this->finish(['schema' => 'plos_rag_rerank.v1', 'status' => $result['status'], 'mode' => 'cross_encoder',
            'read_only' => true, 'query_sha256' => hash('sha256', $input['query']),
            'document_count' => count($documents), 'results' => $scores,
            'model' => $result['model'] ?? null, 'revision' => $result['revision'] ?? null,
            'instance_id' => $result['instance_id'] ?? null, 'engine' => $result['engine'] ?? null,
            'request_sha256' => $result['request_sha256'] ?? null, 'runtime_receipt_sha256' => $result['runtime_receipt_sha256'] ?? null,
            'complete_input' => $result['complete_input'] ?? false], $result['status'] === 'pass' ? self::SUCCESS : self::FAILURE);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, int $exit = self::SUCCESS): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $this->line(($payload['schema'] ?? 'rag:rerank').': '.($payload['status'] ?? 'unknown'));
        foreach ($payload['results'] ?? [] as $result) {
            $this->line(sprintf('%d. %s %.4f', $result['rank'], $result['id'], $result['score']));
        }
        if (isset($payload['error'])) {
            $this->error((string) $payload['error']);
        }

        return $exit;
    }
}
