<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class RagSourceVersionLineageReportService
{
    private const DOCUMENT_TYPE = 'file_catalog';

    private const SOURCE_TYPE = 'file_registry';

    private const CLASSIFICATIONS = [
        'exact_content',
        'normalized_hash_only',
        'different_hash',
        'incomplete_hash',
        'unclassified',
    ];

    private const DEPENDENCIES = [
        'sentence_embeddings',
        'chunk_hypotheticals',
        'propositions',
        'raptor_summaries',
        'graph_hyperedges',
        'graph_triples',
        'claims',
        'research_facts',
    ];

    /** @return array<string,mixed> */
    public function report(): array
    {
        $connection = DB::connection('pgsql_rag');
        $classes = $this->classificationCounts($connection);
        $dependencies = $this->dependencyCounts($connection);
        $nonExactGroups = array_sum(array_map(
            fn (string $classification): int => (int) $classes[$classification]['groups'],
            ['normalized_hash_only', 'different_hash', 'incomplete_hash', 'unclassified'],
        ));
        $nonExactExtraDocuments = array_sum(array_map(
            fn (string $classification): int => (int) $classes[$classification]['extra_documents'],
            ['normalized_hash_only', 'different_hash', 'incomplete_hash', 'unclassified'],
        ));

        return [
            'schema' => 'plos.rag_source_version_lineage_report.v1',
            'status' => $classes['unclassified']['groups'] > 0
                ? 'observe_warning'
                : ($nonExactGroups > 0 ? 'review_required' : 'clear'),
            'mode' => 'observe',
            'scope' => [
                'document_type' => self::DOCUMENT_TYPE,
                'source_type' => self::SOURCE_TYPE,
                'duplicate_source_identities_only' => true,
                'aggregate_only' => true,
            ],
            'totals' => [
                'duplicate_groups' => array_sum(array_column($classes, 'groups')),
                'extra_documents' => array_sum(array_column($classes, 'extra_documents')),
                'non_exact_groups' => $nonExactGroups,
                'non_exact_extra_documents' => $nonExactExtraDocuments,
            ],
            'classifications' => $classes,
            'dependency_references' => $dependencies,
            'decision_boundary' => [
                'write_capability' => false,
                'automatic_disposition' => 'none',
                'proven_by_report' => [
                    'exact_stored_content',
                    'normalized_hash_with_stored_content_difference',
                    'multiple_complete_hashes',
                    'missing_or_incomplete_hashes',
                ],
                'requires_source_specific_review' => [
                    'superseded_content',
                    'legitimate_parallel_version',
                    'source_identity_error',
                ],
            ],
        ];
    }

    /** @return array<string,array{groups:int,documents:int,extra_documents:int}> */
    private function classificationCounts(ConnectionInterface $connection): array
    {
        $rows = $connection->select(
            "WITH grouped AS (
                SELECT source_id,
                       COUNT(*) AS document_count,
                       COUNT(content_hash) AS hashed_count,
                       COUNT(DISTINCT content_hash) AS hash_count,
                       COUNT(DISTINCT content) AS content_count
                FROM rag_documents
                WHERE document_type = ?
                  AND source_type = ?
                  AND source_id IS NOT NULL
                  AND source_id <> ''
                GROUP BY source_id
                HAVING COUNT(*) > 1
             ), classified AS (
                SELECT document_count,
                       CASE
                           WHEN hashed_count <> document_count THEN 'incomplete_hash'
                           WHEN hash_count > 1 THEN 'different_hash'
                           WHEN hash_count = 1 AND content_count > 1 THEN 'normalized_hash_only'
                           WHEN hash_count = 1 AND content_count = 1 THEN 'exact_content'
                           ELSE 'unclassified'
                       END AS classification
                FROM grouped
             )
             SELECT classification,
                    COUNT(*) AS group_count,
                    SUM(document_count) AS document_count,
                    SUM(document_count - 1) AS extra_document_count
             FROM classified
             GROUP BY classification
             ORDER BY classification",
            [self::DOCUMENT_TYPE, self::SOURCE_TYPE],
        );

        $result = [];
        foreach (self::CLASSIFICATIONS as $classification) {
            $result[$classification] = [
                'groups' => 0,
                'documents' => 0,
                'extra_documents' => 0,
            ];
        }
        foreach ($rows as $row) {
            $classification = (string) $row->classification;
            if (! array_key_exists($classification, $result)) {
                $classification = 'unclassified';
            }
            $result[$classification]['groups'] += (int) $row->group_count;
            $result[$classification]['documents'] += (int) $row->document_count;
            $result[$classification]['extra_documents'] += (int) $row->extra_document_count;
        }

        return $result;
    }

    /** @return array<string,array<string,int>> */
    private function dependencyCounts(ConnectionInterface $connection): array
    {
        $rows = $connection->select(
            "WITH grouped AS (
                SELECT source_id,
                       COUNT(*) AS document_count,
                       COUNT(content_hash) AS hashed_count,
                       COUNT(DISTINCT content_hash) AS hash_count,
                       COUNT(DISTINCT content) AS content_count
                FROM rag_documents
                WHERE document_type = ?
                  AND source_type = ?
                  AND source_id IS NOT NULL
                  AND source_id <> ''
                GROUP BY source_id
                HAVING COUNT(*) > 1
             ), classified_documents AS (
                SELECT d.id,
                       CASE
                           WHEN g.hashed_count <> g.document_count THEN 'incomplete_hash'
                           WHEN g.hash_count > 1 THEN 'different_hash'
                           WHEN g.hash_count = 1 AND g.content_count > 1 THEN 'normalized_hash_only'
                           WHEN g.hash_count = 1 AND g.content_count = 1 THEN 'exact_content'
                           ELSE 'unclassified'
                       END AS classification
                FROM rag_documents d
                JOIN grouped g ON g.source_id = d.source_id
                WHERE d.document_type = ? AND d.source_type = ?
             ), dependency_counts AS (
                SELECT c.classification, 'sentence_embeddings' AS dependency, COUNT(*) AS reference_count
                FROM classified_documents c JOIN rag_sentence_embeddings r ON r.document_id = c.id GROUP BY c.classification
                UNION ALL
                SELECT c.classification, 'chunk_hypotheticals', COUNT(*)
                FROM classified_documents c JOIN rag_chunk_hypotheticals r ON r.document_id = c.id GROUP BY c.classification
                UNION ALL
                SELECT c.classification, 'propositions', COUNT(*)
                FROM classified_documents c JOIN rag_propositions r ON r.document_id = c.id GROUP BY c.classification
                UNION ALL
                SELECT c.classification, 'raptor_summaries', COUNT(*)
                FROM classified_documents c JOIN raptor_summaries r ON r.document_id = c.id GROUP BY c.classification
                UNION ALL
                SELECT c.classification, 'graph_hyperedges', COUNT(*)
                FROM classified_documents c JOIN knowledge_graph_hyperedges r ON r.source_document_id = c.id GROUP BY c.classification
                UNION ALL
                SELECT c.classification, 'graph_triples', COUNT(*)
                FROM classified_documents c JOIN knowledge_graph r ON r.source_document_id = c.id GROUP BY c.classification
                UNION ALL
                SELECT c.classification, 'claims', COUNT(*)
                FROM classified_documents c JOIN claims r ON r.source_document_id = c.id GROUP BY c.classification
                UNION ALL
                SELECT c.classification, 'research_facts', COUNT(*)
                FROM classified_documents c JOIN research_facts r ON r.rag_document_id = c.id GROUP BY c.classification
             )
             SELECT classification, dependency, reference_count
             FROM dependency_counts
             ORDER BY classification, dependency",
            [self::DOCUMENT_TYPE, self::SOURCE_TYPE, self::DOCUMENT_TYPE, self::SOURCE_TYPE],
        );

        $result = [];
        foreach (self::CLASSIFICATIONS as $classification) {
            $result[$classification] = array_fill_keys(self::DEPENDENCIES, 0);
        }
        foreach ($rows as $row) {
            $classification = (string) $row->classification;
            $dependency = (string) $row->dependency;
            if (isset($result[$classification]) && array_key_exists($dependency, $result[$classification])) {
                $result[$classification][$dependency] = (int) $row->reference_count;
            }
        }

        return $result;
    }
}
