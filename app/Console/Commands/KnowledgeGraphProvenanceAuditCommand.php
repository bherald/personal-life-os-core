<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class KnowledgeGraphProvenanceAuditCommand extends Command
{
    protected $signature = 'graph:audit-provenance
                            {--strict : Exit non-zero when provenance issues are present}
                            {--samples=5 : Number of sample rows per issue to include}
                            {--json : Output machine-readable JSON}
                            {--compact : Omit sample rows and raw source details from output}';

    protected $description = 'Read-only audit of knowledge graph provenance links and stale source evidence';

    public function handle(): int
    {
        $samples = (int) $this->option('samples');
        if ($samples < 0 || $samples > 50) {
            $this->error('--samples must be between 0 and 50');

            return self::FAILURE;
        }

        $counts = $this->counts();
        $issues = $this->issues($counts);
        $strict = (bool) $this->option('strict');
        $blockingIssues = array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['severity'] ?? 'warning') !== 'info'
        );
        $status = empty($blockingIssues) ? 'pass' : ($strict ? 'fail' : 'warn');

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'status' => $status,
            'strict' => $strict,
            'counts' => $counts,
            'issues' => $issues,
        ];

        $compact = (bool) $this->option('compact');
        if ($samples > 0 && ! $compact) {
            $payload['samples'] = $this->samples($samples);
        }

        if ($compact) {
            $payload = $this->compactPayload($payload, $samples);
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $status === 'fail' ? self::FAILURE : self::SUCCESS;
        }

        return $this->renderTable($payload);
    }

    private function compactPayload(array $payload, int $samplesRequested): array
    {
        $issues = $payload['issues'] ?? [];

        return [
            'generated_at' => $payload['generated_at'] ?? now()->toIso8601String(),
            'status' => $payload['status'] ?? 'unknown',
            'strict' => (bool) ($payload['strict'] ?? false),
            'compact' => true,
            'counts' => $payload['counts'] ?? [],
            'issues' => $issues,
            'summary' => [
                'issue_count' => count($issues),
                'warning_issue_count' => count(array_filter(
                    $issues,
                    static fn (array $issue): bool => ($issue['severity'] ?? 'warning') !== 'info'
                )),
                'info_issue_count' => count(array_filter(
                    $issues,
                    static fn (array $issue): bool => ($issue['severity'] ?? 'warning') === 'info'
                )),
                'samples_requested' => $samplesRequested,
            ],
            'posture' => [
                'read_only' => true,
                'aggregate_only' => true,
                'samples_included' => false,
                'raw_graph_rows_included' => false,
                'raw_document_ids_included' => false,
                'raw_titles_included' => false,
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $columns = $this->ragDocumentColumns();
        $includeKgExtractedAt = in_array('kg_extracted_at', $columns, true);
        $includeHashChecks = $includeKgExtractedAt
            && in_array('content_hash', $columns, true)
            && in_array('kg_content_hash', $columns, true);

        return $this->countsFromSql($this->countsSql($includeHashChecks, $includeKgExtractedAt));
    }

    private function countsSql(bool $includeHashChecks, bool $includeKgExtractedAt): string
    {
        $activeStaleSourceHash = $includeHashChecks
            ? <<<'SQL'
                (SELECT COUNT(*)
                   FROM knowledge_graph kg
                   JOIN rag_documents rd ON rd.id = kg.source_document_id
                  WHERE kg.t_expired IS NULL
                    AND rd.content_hash IS NOT NULL
                    AND rd.kg_content_hash IS NOT NULL
                    AND rd.content_hash IS DISTINCT FROM rd.kg_content_hash) AS active_triples_stale_source_hash,
            SQL
            : '0 AS active_triples_stale_source_hash,';

        $staleDocuments = $includeHashChecks
            ? <<<'SQL'
                (SELECT COUNT(*)
                   FROM rag_documents
                  WHERE kg_extracted_at IS NOT NULL
                    AND content_hash IS NOT NULL
                    AND kg_content_hash IS NOT NULL
                    AND content_hash IS DISTINCT FROM kg_content_hash) AS stale_documents,
            SQL
            : '0 AS stale_documents,';

        $hashCheckAvailable = $includeHashChecks ? '1' : '0';
        $kgExtractedAtAvailable = $includeKgExtractedAt ? '1' : '0';

        $extractedDocsWithoutTriples = $includeKgExtractedAt
            ? <<<'SQL'
                (SELECT COUNT(*)
                   FROM rag_documents rd
                  WHERE rd.kg_extracted_at IS NOT NULL
                    AND LENGTH(rd.content) >= 50
                    AND NOT EXISTS (
                        SELECT 1 FROM knowledge_graph kg WHERE kg.source_document_id = rd.id
                    )) AS extracted_documents_without_triples,
            SQL
            : '0 AS extracted_documents_without_triples,';

        $pendingFreshDocuments = $includeKgExtractedAt
            ? <<<'SQL'
                (SELECT COUNT(*)
                   FROM rag_documents
                  WHERE kg_extracted_at IS NULL
                    AND LENGTH(content) >= 50) AS pending_fresh_documents,
            SQL
            : '0 AS pending_fresh_documents,';

        return <<<SQL
            SELECT
                (SELECT COUNT(*) FROM knowledge_graph) AS total_triples,
                (SELECT COUNT(*) FROM knowledge_graph WHERE t_expired IS NULL) AS active_triples,
                (SELECT COUNT(*) FROM knowledge_graph WHERE source_document_id IS NULL) AS triples_missing_source_document_id,
                (SELECT COUNT(*)
                   FROM knowledge_graph kg
                   LEFT JOIN rag_documents rd ON rd.id = kg.source_document_id
                  WHERE kg.source_document_id IS NOT NULL
                    AND rd.id IS NULL) AS triples_orphan_source_document,
                (SELECT COUNT(*)
                   FROM knowledge_graph
                  WHERE t_expired IS NULL
                    AND (subject_entity_id IS NULL OR object_entity_id IS NULL)) AS active_triples_missing_entity_links,
                {$activeStaleSourceHash}
                {$extractedDocsWithoutTriples}
                {$pendingFreshDocuments}
                {$staleDocuments}
                {$kgExtractedAtAvailable} AS kg_extracted_at_available,
                {$hashCheckAvailable} AS stale_hash_checks_available,
                (SELECT COUNT(*) FROM knowledge_graph_hyperedges) AS total_hyperedges,
                (SELECT COUNT(*)
                   FROM knowledge_graph_hyperedges kh
                   LEFT JOIN rag_documents rd ON rd.id = kh.source_document_id
                  WHERE kh.source_document_id IS NOT NULL
                    AND rd.id IS NULL) AS hyperedges_orphan_source_document
            SQL;
    }

    /**
     * @return array<string, int>
     */
    private function countsFromSql(string $sql): array
    {
        $row = DB::connection('pgsql_rag')->selectOne($sql);

        return array_map('intval', (array) $row);
    }

    /**
     * @return array<int, string>
     */
    private function ragDocumentColumns(): array
    {
        $rows = DB::connection('pgsql_rag')->select(
            "SELECT column_name
               FROM information_schema.columns
              WHERE table_schema = 'public'
                AND table_name = 'rag_documents'"
        );

        return array_map(
            static fn (object $row): string => (string) $row->column_name,
            $rows
        );
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<int, array<string, string|int>>
     */
    private function issues(array $counts): array
    {
        $issueMap = [
            'triples_missing_source_document_id' => 'Triples have no source_document_id.',
            'triples_orphan_source_document' => 'Triples point at missing rag_documents rows.',
            'active_triples_missing_entity_links' => 'Active triples are missing subject or object entity links.',
            'active_triples_stale_source_hash' => 'Active triples were extracted from documents whose content hash changed.',
            'hyperedges_orphan_source_document' => 'Hyperedges point at missing rag_documents rows.',
        ];

        $issues = [];
        foreach ($issueMap as $code => $message) {
            $count = (int) ($counts[$code] ?? 0);
            if ($count <= 0) {
                continue;
            }

            $issues[] = [
                'code' => $code,
                'severity' => 'warning',
                'count' => $count,
                'message' => $message,
            ];
        }

        if ((int) ($counts['stale_hash_checks_available'] ?? 1) === 0) {
            $issues[] = [
                'code' => 'stale_hash_checks_unavailable',
                'severity' => 'info',
                'count' => 1,
                'message' => 'rag_documents.content_hash is unavailable, so stale KG source-hash checks cannot run.',
            ];
        }

        if ((int) ($counts['kg_extracted_at_available'] ?? 1) === 0) {
            $issues[] = [
                'code' => 'kg_extracted_at_unavailable',
                'severity' => 'info',
                'count' => 1,
                'message' => 'rag_documents.kg_extracted_at is unavailable, so KG extraction backlog and empty-extraction checks cannot run.',
            ];
        }

        $emptyExtractions = (int) ($counts['extracted_documents_without_triples'] ?? 0);
        if ($emptyExtractions > 0) {
            $issues[] = [
                'code' => 'extracted_documents_without_triples',
                'severity' => 'info',
                'count' => $emptyExtractions,
                'message' => 'Documents are stamped kg_extracted_at but have no triples; review as re-extraction candidates, not automatic provenance failures.',
            ];
        }

        return $issues;
    }

    /**
     * @return array<string, array<int, object>>
     */
    private function samples(int $limit): array
    {
        $db = DB::connection('pgsql_rag');

        return [
            'triples_missing_source_document_id' => $db->select(
                'SELECT id, subject, predicate, object, created_at
                   FROM knowledge_graph
                  WHERE source_document_id IS NULL
                  ORDER BY created_at DESC
                  LIMIT ?',
                [$limit]
            ),
            'triples_orphan_source_document' => $db->select(
                'SELECT kg.id, kg.source_document_id, kg.subject, kg.predicate, kg.object
                   FROM knowledge_graph kg
                   LEFT JOIN rag_documents rd ON rd.id = kg.source_document_id
                  WHERE kg.source_document_id IS NOT NULL
                    AND rd.id IS NULL
                  ORDER BY kg.created_at DESC
                  LIMIT ?',
                [$limit]
            ),
            'active_triples_stale_source_hash' => $this->staleSourceHashSamples($limit),
            'extracted_documents_without_triples' => $this->extractedDocumentsWithoutTriplesSamples($limit),
            'hyperedges_orphan_source_document' => $db->select(
                'SELECT kh.id, kh.source_document_id, kh.predicate, kh.created_at
                   FROM knowledge_graph_hyperedges kh
                   LEFT JOIN rag_documents rd ON rd.id = kh.source_document_id
                  WHERE kh.source_document_id IS NOT NULL
                    AND rd.id IS NULL
                  ORDER BY kh.created_at DESC
                  LIMIT ?',
                [$limit]
            ),
        ];
    }

    /**
     * @return array<int, object>
     */
    private function staleSourceHashSamples(int $limit): array
    {
        $columns = $this->ragDocumentColumns();
        if (
            ! in_array('kg_extracted_at', $columns, true)
            || ! in_array('content_hash', $columns, true)
            || ! in_array('kg_content_hash', $columns, true)
        ) {
            return [];
        }

        $kgExtractedAt = 'kg_extracted_at';
        $contentHash = 'content_hash';
        $kgContentHash = 'kg_content_hash';

        return DB::connection('pgsql_rag')->select(
            'SELECT DISTINCT rd.id, rd.title, rd.document_type, rd.'.$kgExtractedAt.' AS kg_extracted_at
               FROM knowledge_graph kg
               JOIN rag_documents rd ON rd.id = kg.source_document_id
              WHERE kg.t_expired IS NULL
                AND rd.'.$contentHash.' IS NOT NULL
                AND rd.'.$kgContentHash.' IS NOT NULL
                AND rd.'.$contentHash.' IS DISTINCT FROM rd.'.$kgContentHash.'
              ORDER BY rd.'.$kgExtractedAt.' DESC NULLS LAST
              LIMIT ?',
            [$limit]
        );
    }

    /**
     * @return array<int, object>
     */
    private function extractedDocumentsWithoutTriplesSamples(int $limit): array
    {
        if (! in_array('kg_extracted_at', $this->ragDocumentColumns(), true)) {
            return [];
        }

        $kgExtractedAt = 'kg_extracted_at';

        return DB::connection('pgsql_rag')->select(
            'SELECT rd.id, rd.title, rd.document_type, rd.'.$kgExtractedAt.' AS kg_extracted_at
               FROM rag_documents rd
              WHERE rd.'.$kgExtractedAt.' IS NOT NULL
                AND LENGTH(rd.content) >= 50
                AND NOT EXISTS (
                    SELECT 1 FROM knowledge_graph kg WHERE kg.source_document_id = rd.id
                )
              ORDER BY rd.'.$kgExtractedAt.' DESC
              LIMIT ?',
            [$limit]
        );
    }

    private function renderTable(array $payload): int
    {
        $this->line('Knowledge graph provenance audit: '.strtoupper((string) $payload['status']));

        $counts = $payload['counts'];
        $this->table(['Metric', 'Count'], array_map(
            fn (string $key, int $value): array => [$key, number_format($value)],
            array_keys($counts),
            array_values($counts)
        ));

        foreach ($payload['issues'] as $issue) {
            $this->warn(sprintf('%s: %s', $issue['code'], $issue['message']));
        }

        return $payload['status'] === 'fail' ? self::FAILURE : self::SUCCESS;
    }
}
