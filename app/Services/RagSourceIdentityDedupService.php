<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RagSourceIdentityDedupService
{
    private const DOCUMENT_TYPE = 'file_catalog';

    private const SOURCE_TYPE = 'file_registry';

    /**
     * @return array<string,mixed>
     */
    public function run(int $limit, bool $execute, int $lockTimeoutSeconds): array
    {
        $connection = DB::connection('pgsql_rag');
        $before = $this->summary($connection);
        $sourceIds = $this->eligibleSourceIds($connection, $limit);
        $totals = [
            'groups_consolidated' => 0,
            'documents_removed' => 0,
            'dependencies_repointed' => 0,
            'sentence_duplicates_removed' => 0,
            'source_triples_verified' => 0,
        ];

        if ($execute) {
            foreach ($sourceIds as $sourceId) {
                $group = $this->consolidateGroup($connection, $sourceId, $lockTimeoutSeconds);
                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + (int) ($group[$key] ?? 0);
                }
            }
        }

        $after = $execute ? $this->summary($connection) : $before;

        return [
            'schema' => 'plos.rag_source_identity_dedup.v1',
            'status' => 'ok',
            'dry_run' => ! $execute,
            'scope' => [
                'document_type' => self::DOCUMENT_TYPE,
                'source_type' => self::SOURCE_TYPE,
                'complete_content_hash_required' => true,
                'exact_stored_content_only' => true,
                'canonical' => 'newest_updated_at_then_id',
            ],
            'selection' => [
                'limit_groups' => $limit,
                'selected_groups' => count($sourceIds),
            ],
            'before' => $before,
            'actions' => $totals,
            'after' => $after,
            'divergent_versions_mutated' => 0,
        ];
    }

    /** @return array<string,int> */
    private function summary(ConnectionInterface $connection): array
    {
        $row = $connection->selectOne(
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
             )
             SELECT COUNT(*) AS duplicate_groups,
                    COALESCE(SUM(document_count - 1), 0) AS extra_documents,
                    COUNT(*) FILTER (
                        WHERE hashed_count = document_count
                          AND hash_count = 1
                          AND content_count = 1
                    ) AS exact_content_groups,
                    COALESCE(SUM(document_count - 1) FILTER (
                        WHERE hashed_count = document_count
                          AND hash_count = 1
                          AND content_count = 1
                    ), 0) AS exact_content_extra_documents,
                    COUNT(*) FILTER (
                        WHERE hashed_count = document_count
                          AND hash_count = 1
                          AND content_count > 1
                    ) AS normalized_hash_only_groups,
                    COALESCE(SUM(document_count - 1) FILTER (
                        WHERE hashed_count = document_count
                          AND hash_count = 1
                          AND content_count > 1
                    ), 0) AS normalized_hash_only_extra_documents,
                    COUNT(*) FILTER (
                        WHERE hashed_count <> document_count
                           OR hash_count <> 1
                    ) AS divergent_or_unproven_groups,
                    COALESCE(SUM(document_count - 1) FILTER (
                        WHERE hashed_count <> document_count
                           OR hash_count <> 1
                    ), 0) AS divergent_or_unproven_extra_documents
             FROM grouped",
            [self::DOCUMENT_TYPE, self::SOURCE_TYPE],
        );

        return [
            'duplicate_groups' => (int) ($row->duplicate_groups ?? 0),
            'extra_documents' => (int) ($row->extra_documents ?? 0),
            'exact_content_groups' => (int) ($row->exact_content_groups ?? 0),
            'exact_content_extra_documents' => (int) ($row->exact_content_extra_documents ?? 0),
            'normalized_hash_only_groups' => (int) ($row->normalized_hash_only_groups ?? 0),
            'normalized_hash_only_extra_documents' => (int) ($row->normalized_hash_only_extra_documents ?? 0),
            'divergent_or_unproven_groups' => (int) ($row->divergent_or_unproven_groups ?? 0),
            'divergent_or_unproven_extra_documents' => (int) ($row->divergent_or_unproven_extra_documents ?? 0),
        ];
    }

    /** @return list<string> */
    private function eligibleSourceIds(ConnectionInterface $connection, int $limit): array
    {
        $rows = $connection->select(
            "SELECT source_id
             FROM rag_documents
             WHERE document_type = ?
               AND source_type = ?
               AND source_id IS NOT NULL
               AND source_id <> ''
             GROUP BY source_id
             HAVING COUNT(*) > 1
                AND COUNT(content_hash) = COUNT(*)
                AND COUNT(DISTINCT content_hash) = 1
                AND COUNT(DISTINCT content) = 1
             ORDER BY MIN(created_at) NULLS FIRST, MIN(id)
             LIMIT ?",
            [self::DOCUMENT_TYPE, self::SOURCE_TYPE, $limit],
        );

        return array_values(array_map(fn (object $row): string => (string) $row->source_id, $rows));
    }

    /** @return array<string,int> */
    private function consolidateGroup(
        ConnectionInterface $connection,
        string $sourceId,
        int $lockTimeoutSeconds,
    ): array {
        return $connection->transaction(function () use ($connection, $sourceId, $lockTimeoutSeconds): array {
            $identity = implode("\x1f", [self::DOCUMENT_TYPE, self::SOURCE_TYPE, $sourceId]);
            $connection->selectOne("SELECT set_config('lock_timeout', ?, true)", ["{$lockTimeoutSeconds}s"]);
            $connection->selectOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0)) AS locked',
                [$identity],
            );

            $documents = $connection->select(
                'SELECT id, content_hash, content
                 FROM rag_documents
                 WHERE document_type = ? AND source_type = ? AND source_id = ?
                 ORDER BY updated_at DESC NULLS LAST, id DESC
                 FOR UPDATE',
                [self::DOCUMENT_TYPE, self::SOURCE_TYPE, $sourceId],
            );
            if (count($documents) < 2) {
                return $this->emptyActions();
            }

            $hashes = array_values(array_unique(array_map(
                fn (object $row): string => (string) ($row->content_hash ?? ''),
                $documents,
            )));
            if (count($hashes) !== 1 || $hashes[0] === '') {
                throw new RuntimeException('RAG source-identity group changed or is not proven exact-content.');
            }
            $contents = array_values(array_unique(array_map(
                fn (object $row): string => (string) $row->content,
                $documents,
            ), SORT_STRING));
            if (count($contents) !== 1) {
                throw new RuntimeException('RAG source-identity group has normalized-hash-only content differences.');
            }

            $canonicalId = (int) $documents[0]->id;
            $duplicateIds = array_map(fn (object $row): int => (int) $row->id, array_slice($documents, 1));
            $allIds = [$canonicalId, ...$duplicateIds];
            $effects = $this->emptyActions();
            $sourceTripleIds = $this->captureSourceTripleIds($connection, $duplicateIds);

            $effects['sentence_duplicates_removed'] = $this->deduplicateSentenceRows(
                $connection,
                $canonicalId,
                $allIds,
            );

            foreach ([
                ['rag_sentence_embeddings', 'document_id'],
                ['rag_chunk_hypotheticals', 'document_id'],
                ['rag_propositions', 'document_id'],
                ['raptor_summaries', 'document_id'],
                ['knowledge_graph_hyperedges', 'source_document_id'],
                ['knowledge_graph', 'source_document_id'],
                ['claims', 'source_document_id'],
                ['research_facts', 'rag_document_id'],
                ['rag_dedup_log', 'matched_document_id'],
                ['rag_documents', 'parent_id'],
                ['rag_documents', 'dedup_matched_id'],
                ['rag_documents', 'near_duplicate_of'],
            ] as [$table, $column]) {
                $effects['dependencies_repointed'] += $this->repoint(
                    $connection,
                    $table,
                    $column,
                    $canonicalId,
                    $duplicateIds,
                );
            }

            $this->preserveProcessingStamps($connection, $canonicalId, $allIds, $hashes[0]);
            $effects['documents_removed'] = $this->deleteDocuments($connection, $duplicateIds);
            if ($effects['documents_removed'] !== count($duplicateIds)) {
                throw new RuntimeException('RAG source-identity consolidation removed an unexpected document count.');
            }
            $this->assertSourceTriplesRepointed($connection, $sourceTripleIds, $canonicalId);
            $effects['source_triples_verified'] = count($sourceTripleIds);
            $effects['groups_consolidated'] = 1;

            return $effects;
        });
    }

    /** @param list<int> $allIds */
    private function deduplicateSentenceRows(
        ConnectionInterface $connection,
        int $canonicalId,
        array $allIds,
    ): int {
        [$in, $bindings] = $this->inBindings($allIds);

        return $connection->delete(
            "WITH ranked AS (
                SELECT id,
                       ROW_NUMBER() OVER (
                           PARTITION BY sentence_index
                           ORDER BY CASE WHEN document_id = ? THEN 0 ELSE 1 END,
                                    document_id DESC,
                                    id DESC
                       ) AS row_rank
                FROM rag_sentence_embeddings
                WHERE document_id IN ({$in})
             )
             DELETE FROM rag_sentence_embeddings
             WHERE id IN (SELECT id FROM ranked WHERE row_rank > 1)",
            [$canonicalId, ...$bindings],
        );
    }

    /** @param list<int> $duplicateIds */
    private function repoint(
        ConnectionInterface $connection,
        string $table,
        string $column,
        int $canonicalId,
        array $duplicateIds,
    ): int {
        [$in, $bindings] = $this->inBindings($duplicateIds);

        return $connection->update(
            "UPDATE {$table} SET {$column} = ? WHERE {$column} IN ({$in})",
            [$canonicalId, ...$bindings],
        );
    }

    /** @param list<int> $allIds */
    private function preserveProcessingStamps(
        ConnectionInterface $connection,
        int $canonicalId,
        array $allIds,
        string $contentHash,
    ): void {
        [$in, $bindings] = $this->inBindings($allIds);
        $connection->update(
            "UPDATE rag_documents AS canonical
             SET sentence_indexed_at = COALESCE(canonical.sentence_indexed_at, grouped.sentence_indexed_at),
                 raptor_indexed_at = COALESCE(canonical.raptor_indexed_at, grouped.raptor_indexed_at),
                 hype_indexed_at = COALESCE(canonical.hype_indexed_at, grouped.hype_indexed_at),
                 kg_extracted_at = COALESCE(canonical.kg_extracted_at, grouped.kg_extracted_at),
                 kg_content_hash = CASE
                     WHEN COALESCE(canonical.kg_extracted_at, grouped.kg_extracted_at) IS NOT NULL
                     THEN ? ELSE canonical.kg_content_hash END,
                 dedup_status = 'unique',
                 dedup_matched_id = NULL,
                 dedup_similarity = NULL
             FROM (
                 SELECT MAX(sentence_indexed_at) AS sentence_indexed_at,
                        MAX(raptor_indexed_at) AS raptor_indexed_at,
                        MAX(hype_indexed_at) AS hype_indexed_at,
                        MAX(kg_extracted_at) FILTER (WHERE kg_content_hash = content_hash) AS kg_extracted_at
                 FROM rag_documents
                 WHERE id IN ({$in})
             ) AS grouped
             WHERE canonical.id = ?",
            [$contentHash, ...$bindings, $canonicalId],
        );
    }

    /** @param list<int> $documentIds */
    private function deleteDocuments(ConnectionInterface $connection, array $documentIds): int
    {
        [$in, $bindings] = $this->inBindings($documentIds);

        return $connection->delete("DELETE FROM rag_documents WHERE id IN ({$in})", $bindings);
    }

    /** @param list<int> $documentIds @return list<int> */
    private function captureSourceTripleIds(ConnectionInterface $connection, array $documentIds): array
    {
        [$in, $bindings] = $this->inBindings($documentIds);
        $rows = $connection->select(
            "SELECT id
             FROM knowledge_graph
             WHERE source_document_id IN ({$in})
             ORDER BY id
             FOR UPDATE",
            $bindings,
        );

        return array_values(array_map(fn (object $row): int => (int) $row->id, $rows));
    }

    /** @param list<int> $tripleIds */
    private function assertSourceTriplesRepointed(
        ConnectionInterface $connection,
        array $tripleIds,
        int $canonicalId,
    ): void {
        if ($tripleIds === []) {
            return;
        }

        [$in, $bindings] = $this->inBindings($tripleIds);
        $row = $connection->selectOne(
            "SELECT COUNT(*) AS mismatch_count
             FROM knowledge_graph
             WHERE id IN ({$in})
               AND source_document_id IS DISTINCT FROM ?",
            [...$bindings, $canonicalId],
        );
        if ((int) ($row->mismatch_count ?? 0) !== 0) {
            throw new RuntimeException('RAG source-identity consolidation failed its graph provenance invariant.');
        }
    }

    /** @param list<int> $values @return array{0:string,1:list<int>} */
    private function inBindings(array $values): array
    {
        if ($values === []) {
            throw new RuntimeException('RAG source-identity consolidation received an empty ID set.');
        }

        return [implode(',', array_fill(0, count($values), '?')), array_values($values)];
    }

    /** @return array<string,int> */
    private function emptyActions(): array
    {
        return [
            'groups_consolidated' => 0,
            'documents_removed' => 0,
            'dependencies_repointed' => 0,
            'sentence_duplicates_removed' => 0,
            'source_triples_verified' => 0,
        ];
    }
}
