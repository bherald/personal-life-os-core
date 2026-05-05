<?php

namespace App\Console\Commands;

use App\Services\RAGService;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RagRetrievalEvidenceCommand extends Command
{
    private const EVIDENCE_CONTRACT_VERSION = 1;

    private const QUERY_SET_HASH_BASIS = 'ordered_query_hashes_and_type_filters';

    protected $signature = 'rag:retrieval-evidence
        {--query=* : Query text; repeat for multiple observe-only searches}
        {--queries-file= : JSON file with strings or objects containing query, label, and optional type}
        {--limit=5 : Results per query, capped at 20}
        {--type= : Default document type filter}
        {--max-queries=25 : Maximum queries accepted in one batch}
        {--include-results : Include result titles/previews in output}
        {--json : Emit machine-readable retrieval evidence}
        {--markdown : Emit redacted Markdown retrieval evidence}';

    protected $description = 'Run an observe-only RAG retrieval evidence batch without exposing query text by default';

    public function handle(RAGService $ragService): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return Command::FAILURE;
        }

        $started = microtime(true);
        $limit = max(1, min((int) $this->option('limit'), 20));
        $defaultType = $this->normaliseType($this->option('type'));
        $includeResults = (bool) $this->option('include-results');
        $maxQueries = max(1, min((int) $this->option('max-queries'), 50));

        try {
            $queries = $this->loadQueries($defaultType);
        } catch (\Throwable $e) {
            return $this->emitFailure($e->getMessage(), $started);
        }

        if ($queries === []) {
            return $this->emitFailure('No queries supplied. Use --query or --queries-file.', $started);
        }

        if (count($queries) > $maxQueries) {
            return $this->emitFailure('Query count '.count($queries).' exceeds max-queries '.$maxQueries.'.', $started);
        }

        $entries = [];
        $topSimilarities = [];
        $resultCounts = [];
        $durations = [];
        $failedCount = 0;
        $emptyCount = 0;

        foreach ($queries as $querySpec) {
            $queryStarted = microtime(true);
            $query = $querySpec['query'];

            try {
                $results = $ragService->search($query, $limit, $querySpec['type']);
                $durationMs = $this->durationMs($queryStarted);
                $durations[] = $durationMs;
                $resultCount = count($results);
                $similarities = array_map(
                    fn (array $result): float => round((float) ($result['similarity'] ?? 0), 4),
                    $results
                );
                $topSimilarity = $similarities !== [] ? max($similarities) : null;
                $avgSimilarity = $similarities !== []
                    ? round(array_sum($similarities) / count($similarities), 4)
                    : null;

                if ($topSimilarity !== null) {
                    $topSimilarities[] = $topSimilarity;
                }

                $resultCounts[] = $resultCount;
                if ($resultCount === 0) {
                    $emptyCount++;
                }

                $entry = [
                    'status' => $resultCount > 0 ? 'observe_ok' : 'observe_empty',
                    'label' => $querySpec['label'],
                    'query_hash' => $this->queryHash($query),
                    'type' => $querySpec['type'],
                    'duration_ms' => $durationMs,
                    'result_count' => $resultCount,
                    'top_similarity' => $topSimilarity,
                    'avg_similarity' => $avgSimilarity,
                ];

                if ($includeResults) {
                    $entry['results'] = array_map(fn (array $result): array => $this->jsonResult($result), $results);
                }

                $entries[] = $entry;
            } catch (\Throwable $e) {
                $durationMs = $this->durationMs($queryStarted);
                $durations[] = $durationMs;
                $failedCount++;
                $entries[] = [
                    'status' => 'failed',
                    'label' => $querySpec['label'],
                    'query_hash' => $this->queryHash($query),
                    'type' => $querySpec['type'],
                    'duration_ms' => $durationMs,
                    'result_count' => 0,
                    'top_similarity' => null,
                    'avg_similarity' => null,
                    'error_type' => class_basename($e),
                ];
            }
        }

        $payload = [
            'status' => $failedCount > 0 ? 'observe_warning' : 'observe_ok',
            'duration_ms' => $this->durationMs($started),
            'evidence_contract' => $this->evidenceContract($includeResults),
            'query_set_hash' => $this->querySetHash($queries),
            'query_count' => count($entries),
            'successful_count' => count($entries) - $failedCount,
            'empty_count' => $emptyCount,
            'failed_count' => $failedCount,
            'limit' => $limit,
            'default_type' => $defaultType,
            'include_results' => $includeResults,
            'score_summary' => [
                'top_similarity_min' => $topSimilarities !== [] ? round(min($topSimilarities), 4) : null,
                'top_similarity_max' => $topSimilarities !== [] ? round(max($topSimilarities), 4) : null,
                'top_similarity_avg' => $topSimilarities !== [] ? round(array_sum($topSimilarities) / count($topSimilarities), 4) : null,
                'avg_result_count' => $resultCounts !== [] ? round(array_sum($resultCounts) / count($resultCounts), 2) : 0,
            ],
            'latency_summary' => $this->latencySummary($durations),
            'queries' => $entries,
        ];

        $this->emitPayload($payload);

        return $failedCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array<int, array{label: ?string, query: string, type: ?string}>
     */
    private function loadQueries(?string $defaultType): array
    {
        $queries = [];

        foreach ((array) $this->option('query') as $query) {
            $query = trim((string) $query);
            if ($query !== '') {
                $queries[] = [
                    'label' => null,
                    'query' => $query,
                    'type' => $defaultType,
                ];
            }
        }

        $file = trim((string) $this->option('queries-file'));
        if ($file === '') {
            return $queries;
        }

        $path = $this->resolvePath($file);
        if (! is_file($path)) {
            throw new \InvalidArgumentException('Query file not found. Check --queries-file path.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Query file must be a JSON array.');
        }

        foreach ($decoded as $index => $item) {
            if (is_string($item)) {
                $query = trim($item);
                $label = null;
                $type = $defaultType;
            } elseif (is_array($item)) {
                $query = trim((string) ($item['query'] ?? ''));
                $label = isset($item['label']) && trim((string) $item['label']) !== ''
                    ? trim((string) $item['label'])
                    : null;
                $type = $this->normaliseType($item['type'] ?? $defaultType);
            } else {
                throw new \InvalidArgumentException('Query file item '.($index + 1).' must be a string or object.');
            }

            if ($query === '') {
                throw new \InvalidArgumentException('Query file item '.($index + 1).' has an empty query.');
            }

            $queries[] = [
                'label' => $label,
                'query' => $query,
                'type' => $type,
            ];
        }

        return $queries;
    }

    private function normaliseType(mixed $value): ?string
    {
        $type = trim((string) $value);

        return $type !== '' ? $type : null;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return rtrim((string) getenv('HOME'), '/').substr($path, 1);
        }

        return str_starts_with($path, '/')
            ? $path
            : base_path($path);
    }

    private function queryHash(string $query): string
    {
        return substr(hash('sha256', $query), 0, 16);
    }

    /**
     * @param  array<int, array{label: ?string, query: string, type: ?string}>  $queries
     */
    private function querySetHash(array $queries): string
    {
        $fingerprint = array_map(
            fn (array $query): array => [
                'query_hash' => $this->queryHash($query['query']),
                'type' => $query['type'],
            ],
            $queries
        );

        return substr(hash('sha256', json_encode($fingerprint, JSON_UNESCAPED_SLASHES)), 0, 16);
    }

    private function emitFailure(string $message, float $started): int
    {
        $payload = [
            'status' => 'failed',
            'duration_ms' => $this->durationMs($started),
            'evidence_contract' => $this->evidenceContract(false),
            'error' => $message,
        ];

        $this->emitPayload($payload);

        return Command::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitPayload(array $payload): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        if ($this->option('markdown')) {
            $this->line($this->payloadToMarkdown($payload));

            return;
        }

        $this->info('RAG retrieval evidence: '.$payload['status']);
        $this->line('Queries: '.($payload['query_count'] ?? 0));
        $this->line('Failed: '.($payload['failed_count'] ?? 0));
        $this->line('Empty: '.($payload['empty_count'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadToMarkdown(array $payload): string
    {
        $lines = [
            '# RAG Retrieval Evidence',
            '',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Query set hash: `'.($payload['query_set_hash'] ?? 'n/a').'`',
            '- Query count: `'.(int) ($payload['query_count'] ?? 0).'`',
            '- Successful: `'.(int) ($payload['successful_count'] ?? 0).'`',
            '- Empty: `'.(int) ($payload['empty_count'] ?? 0).'`',
            '- Failed: `'.(int) ($payload['failed_count'] ?? 0).'`',
            '- Limit: `'.(int) ($payload['limit'] ?? 0).'`',
            '',
        ];

        $contract = is_array($payload['evidence_contract'] ?? null) ? $payload['evidence_contract'] : [];
        if ($contract !== []) {
            $lines[] = '## Evidence Contract';
            $lines[] = '';
            $lines[] = '- Version: `'.(int) ($contract['version'] ?? 0).'`';
            $lines[] = '- Mode: `'.($contract['mode'] ?? 'unknown').'`';
            $lines[] = '- Query set hash basis: `'.($contract['query_set_hash_basis'] ?? 'unknown').'`';
            $lines[] = '- Query text: `'.($contract['query_text'] ?? 'unknown').'`';
            $lines[] = '- Results: `'.($contract['results'] ?? 'unknown').'`';
            $lines[] = '';
        }

        $lines[] = '## Score Summary';
        $lines[] = '';

        $summary = is_array($payload['score_summary'] ?? null) ? $payload['score_summary'] : [];
        foreach (['top_similarity_min', 'top_similarity_max', 'top_similarity_avg', 'avg_result_count'] as $key) {
            $lines[] = '- '.$key.': `'.($summary[$key] ?? 'n/a').'`';
        }

        $lines[] = '';
        $lines[] = '## Latency Summary';
        $lines[] = '';

        $latency = is_array($payload['latency_summary'] ?? null) ? $payload['latency_summary'] : [];
        foreach (['query_duration_min_ms', 'query_duration_max_ms', 'query_duration_avg_ms', 'query_duration_p95_ms'] as $key) {
            $lines[] = '- '.$key.': `'.($latency[$key] ?? 'n/a').'`';
        }

        $lines[] = '';
        $lines[] = '## Queries';
        $lines[] = '';
        $lines[] = '| Label | Query Hash | Type | Status | Results | Top Similarity | Avg Similarity | Duration ms |';
        $lines[] = '|---|---|---|---|---:|---:|---:|---:|';

        $queries = is_array($payload['queries'] ?? null) ? $payload['queries'] : [];
        if ($queries === []) {
            $lines[] = '| n/a | n/a | n/a | '.($payload['status'] ?? 'unknown').' | 0 | n/a | n/a | '.(int) ($payload['duration_ms'] ?? 0).' |';
        }

        foreach ($queries as $query) {
            if (! is_array($query)) {
                continue;
            }

            $lines[] = sprintf(
                '| `%s` | `%s` | `%s` | `%s` | %d | `%s` | `%s` | %d |',
                $this->markdownCell($query['label'] ?? null, 'unlabeled'),
                $this->markdownCell($query['query_hash'] ?? null),
                $this->markdownCell($query['type'] ?? null, 'all'),
                $this->markdownCell($query['status'] ?? null, 'unknown'),
                (int) ($query['result_count'] ?? 0),
                $this->markdownCell($query['top_similarity'] ?? null, 'n/a'),
                $this->markdownCell($query['avg_similarity'] ?? null, 'n/a'),
                (int) ($query['duration_ms'] ?? 0),
            );
        }

        return implode("\n", $lines)."\n";
    }

    private function markdownCell(mixed $value, string $empty = ''): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            $text = $empty;
        }

        return str_replace('|', '\\|', $text);
    }

    /**
     * @return array<string, int|string>
     */
    private function evidenceContract(bool $includeResults): array
    {
        return [
            'version' => self::EVIDENCE_CONTRACT_VERSION,
            'mode' => 'observe_only',
            'query_set_hash_basis' => self::QUERY_SET_HASH_BASIS,
            'query_text' => 'redacted_by_default',
            'labels' => 'operator_supplied_public_handles',
            'results' => $includeResults ? 'included_by_operator_option' : 'redacted_by_default',
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function jsonResult(array $result): array
    {
        $doc = $result['document'] ?? null;

        return [
            'title' => is_object($doc) ? $this->documentTitle($doc) : 'Untitled',
            'document_type' => is_object($doc) ? (string) ($doc->document_type ?? 'unknown') : 'unknown',
            'created_at' => is_object($doc) ? $this->formatDate($doc->created_at ?? null) : null,
            'similarity' => round((float) ($result['similarity'] ?? 0), 4),
            'preview' => is_object($doc) ? $this->preview($doc->content ?? '') : '',
        ];
    }

    private function documentTitle(object $doc): string
    {
        $title = trim((string) ($doc->title ?? ''));

        return $title !== '' ? $title : 'Untitled';
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function preview(mixed $value): string
    {
        $content = trim((string) $value);
        if ($content === '') {
            return '';
        }

        return mb_strlen($content) > 100
            ? mb_substr($content, 0, 100).'...'
            : $content;
    }

    private function durationMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * @param  list<int>  $durations
     * @return array<string, float|int|null>
     */
    private function latencySummary(array $durations): array
    {
        if ($durations === []) {
            return [
                'query_duration_min_ms' => null,
                'query_duration_max_ms' => null,
                'query_duration_avg_ms' => null,
                'query_duration_p95_ms' => null,
            ];
        }

        sort($durations);
        $p95Index = max(0, (int) ceil(count($durations) * 0.95) - 1);

        return [
            'query_duration_min_ms' => min($durations),
            'query_duration_max_ms' => max($durations),
            'query_duration_avg_ms' => round(array_sum($durations) / count($durations), 2),
            'query_duration_p95_ms' => $durations[$p95Index],
        ];
    }
}
