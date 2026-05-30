<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FileRegistryTombstonePurgeService
{
    private const HISTORY_REASON = 'file_hard_purged_batch';

    public function preview(int $limit = 1000, bool $force = false): array
    {
        return $this->buildPayload($limit, $force, false);
    }

    public function purge(int $limit = 1000, bool $force = false, bool $countReferences = false): array
    {
        return $this->buildPayload($limit, $force, true, $countReferences);
    }

    public function previewOrphanRagFileDocuments(int $limit = 1000): array
    {
        return $this->buildOrphanRagPayload($limit, false);
    }

    public function purgeOrphanRagFileDocuments(int $limit = 1000): array
    {
        return $this->buildOrphanRagPayload($limit, true);
    }

    private function buildPayload(int $limit, bool $force, bool $execute, bool $countReferences = true): array
    {
        $limit = max(1, min($limit, 5000));
        $retentionDays = $this->retentionDays();
        $cutoff = $this->retentionCutoff($retentionDays, $force);
        $selected = $this->candidateRows($limit, $force, $cutoff);
        $ids = $this->candidateIds($selected);
        $assetUuids = $this->candidateAssetUuids($selected);
        $paths = $this->candidatePaths($selected);

        $payload = [
            'version' => 1,
            'mode' => $execute ? 'execute' : 'dry_run',
            'status' => $selected === [] ? 'nothing_to_purge' : ($execute ? 'planned' : 'preview'),
            'captured_at' => now()->toIso8601String(),
            'retention_days' => $retentionDays,
            'force' => $force,
            'limit' => $limit,
            'eligible_total' => $this->eligibleTotal($force, $cutoff),
            'selected' => count($selected),
            'cutoff' => $cutoff?->toIso8601String(),
            'mysql' => [
                'references' => $countReferences
                    ? $this->mysqlReferenceCounts($ids, $assetUuids, $paths)
                    : ['skipped' => true],
                'deleted' => [],
                'updated' => [],
            ],
            'rag' => [
                'references' => $countReferences
                    ? $this->ragReferenceCounts($ids, $assetUuids, $paths)
                    : ['skipped' => true],
                'deleted' => [],
                'updated' => [],
            ],
            'errors' => [],
        ];

        if (! $execute || $selected === []) {
            return $payload;
        }

        try {
            $payload['rag'] = array_merge($payload['rag'], $this->purgeRagReferences($ids, $assetUuids, $paths));
            $payload['mysql'] = array_merge($payload['mysql'], $this->purgeMysqlReferences($ids, $assetUuids, $paths));
            $payload['status'] = 'purged';
        } catch (Exception $e) {
            $payload['status'] = 'failed';
            $payload['errors'][] = $e->getMessage();

            Log::error('FileRegistryTombstonePurge: batch purge failed', [
                'selected' => count($selected),
                'force' => $force,
                'error' => $e->getMessage(),
            ]);
        }

        return $payload;
    }

    private function buildOrphanRagPayload(int $limit, bool $execute): array
    {
        $limit = max(1, min($limit, 5000));
        $orphans = $this->orphanRagFileDocuments($limit);
        $ragDocumentIds = $orphans['ids'];

        $payload = [
            'version' => 1,
            'mode' => $execute ? 'execute' : 'dry_run',
            'status' => $ragDocumentIds === [] ? 'nothing_to_purge' : ($execute ? 'planned' : 'preview'),
            'captured_at' => now()->toIso8601String(),
            'limit' => $limit,
            'orphan_total' => $orphans['total'],
            'selected' => count($ragDocumentIds),
            'samples' => $orphans['samples'],
            'rag' => [
                'references' => $this->ragDocumentReferenceCounts($ragDocumentIds),
                'deleted' => [],
                'updated' => [],
            ],
            'errors' => [],
        ];

        if (! $execute || $ragDocumentIds === []) {
            return $payload;
        }

        try {
            $payload['rag'] = array_merge($payload['rag'], $this->purgeRagDocumentIds($ragDocumentIds));
            $payload['status'] = 'purged';
        } catch (Exception $e) {
            $payload['status'] = 'failed';
            $payload['errors'][] = $e->getMessage();

            Log::error('FileRegistryTombstonePurge: orphan file RAG purge failed', [
                'selected' => count($ragDocumentIds),
                'error' => $e->getMessage(),
            ]);
        }

        return $payload;
    }

    private function eligibleTotal(bool $force, ?Carbon $cutoff): int
    {
        $query = DB::table('file_registry')->where('status', 'deleted');

        if (! $force && $cutoff) {
            $query->where('updated_at', '<=', $cutoff);
        }

        return (int) $query->count();
    }

    private function candidateRows(int $limit, bool $force, ?Carbon $cutoff): array
    {
        $query = DB::table('file_registry')
            ->select(['id', 'asset_uuid', 'current_path', 'updated_at'])
            ->where('status', 'deleted')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit);

        if (! $force && $cutoff) {
            $query->where('updated_at', '<=', $cutoff);
        }

        return $query->get()->all();
    }

    private function purgeRagReferences(array $ids, array $assetUuids, array $paths): array
    {
        if (! $this->pgTableExists('rag_documents')) {
            throw new RuntimeException('pgsql_rag.rag_documents is unavailable; refusing to hard-purge file tombstones with possible stale RAG references.');
        }

        $deleted = [
            'face_embeddings' => 0,
            'file_semantic_embeddings' => 0,
            'knowledge_graph_hyperedges' => 0,
            'rag_chunk_hypotheticals' => 0,
            'rag_propositions' => 0,
            'rag_dedup_log' => 0,
            'rag_sentence_embeddings' => 0,
            'raptor_summaries' => 0,
            'rag_documents' => 0,
        ];
        $updated = [
            'rag_documents_dedup_backrefs' => 0,
            'rag_documents_near_duplicate_backrefs' => 0,
            'claims_source_document_refs' => 0,
            'research_facts_rag_document_refs' => 0,
            'knowledge_graph_active_triples' => 0,
            'knowledge_graph_edge_history' => 0,
        ];

        $rag = DB::connection('pgsql_rag');

        $rag->transaction(function () use ($ids, $assetUuids, $paths, &$deleted, &$updated): void {
            $deleted['face_embeddings'] = $this->deletePgIn('face_embeddings', 'file_registry_id', $ids);
            $deleted['file_semantic_embeddings'] = $this->deletePgIn('file_semantic_embeddings', 'file_id', $ids);

            $ragDocumentIds = $this->ragDocumentIds($ids, $assetUuids, $paths);
            if ($ragDocumentIds === []) {
                return;
            }

            $ragPurge = $this->purgeRagDocumentReferences($ragDocumentIds);
            $deleted = array_merge($deleted, $ragPurge['deleted']);
            $updated = array_merge($updated, $ragPurge['updated']);
        });

        return [
            'deleted' => $deleted,
            'updated' => $updated,
        ];
    }

    private function purgeMysqlReferences(array $ids, array $assetUuids, array $paths): array
    {
        $deleted = [
            'file_registry_faces' => 0,
            'file_registry_tags' => 0,
            'file_registry_perceptual_hashes' => 0,
            'file_registry_video_hashes' => 0,
            'file_registry_duplicates' => 0,
            'file_registry_similar_images' => 0,
            'file_registry_similar_videos' => 0,
            'file_bundle_members' => 0,
            'file_collection_items' => 0,
            'file_versions' => 0,
            'file_registry_path_history' => 0,
            'file_quarantine' => 0,
            'genealogy_media_scan_log' => 0,
            'genealogy_face_match_queue' => 0,
            'genealogy_person_media' => 0,
            'genealogy_family_media' => 0,
            'genealogy_media_files' => 0,
            'genealogy_media_crops' => 0,
            'genealogy_media' => 0,
            'file_registry' => 0,
        ];
        $updated = [
            'file_bundles' => 0,
            'file_collections' => 0,
            'genealogy_persons' => 0,
            'genealogy_citations' => 0,
        ];

        DB::transaction(function () use ($ids, $assetUuids, $paths, &$deleted, &$updated): void {
            $videoHashIds = $this->mysqlIdsFor('file_registry_video_hashes', 'id', 'file_registry_id', $ids);
            $faceIds = $this->mysqlIdsFor('file_registry_faces', 'id', 'file_registry_id', $ids);
            $mediaIds = $this->mysqlIdsFor('genealogy_media', 'id', 'nextcloud_path', $paths);

            $deleted['file_registry_similar_videos'] = $this->deleteMysqlAnyIn(
                'file_registry_similar_videos',
                ['video_hash_id_1', 'video_hash_id_2'],
                $videoHashIds
            );
            $deleted['file_registry_video_hashes'] = $this->deleteMysqlIn('file_registry_video_hashes', 'file_registry_id', $ids);
            $deleted['file_registry_similar_images'] = $this->deleteMysqlAnyIn(
                'file_registry_similar_images',
                ['file_id_a', 'file_id_b'],
                $ids
            );
            $deleted['file_registry_duplicates'] = $this->deleteMysqlAnyIn(
                'file_registry_duplicates',
                ['canonical_file_id', 'duplicate_file_id'],
                $ids
            );

            $deleted['genealogy_face_match_queue'] += $this->deleteMysqlIn('genealogy_face_match_queue', 'file_registry_face_id', $faceIds);
            $deleted['file_registry_faces'] = $this->deleteMysqlIn('file_registry_faces', 'file_registry_id', $ids);
            $deleted['file_registry_tags'] = $this->deleteMysqlIn('file_registry_tags', 'file_registry_id', $ids);
            $deleted['file_registry_perceptual_hashes'] = $this->deleteMysqlIn('file_registry_perceptual_hashes', 'file_registry_id', $ids);
            $deleted['file_bundle_members'] = $this->deleteMysqlIn('file_bundle_members', 'file_registry_id', $ids);
            $deleted['file_collection_items'] = $this->deleteMysqlIn('file_collection_items', 'file_registry_id', $ids);
            $deleted['file_versions'] = $this->deleteMysqlIn('file_versions', 'file_registry_id', $ids);
            $deleted['file_registry_path_history'] = $this->deleteMysqlIn('file_registry_path_history', 'file_registry_id', $ids);
            $deleted['file_quarantine'] = $this->deleteMysqlQuarantineRows($ids, $assetUuids);

            $updated['file_bundles'] = $this->updateMysqlIn('file_bundles', 'primary_file_id', $ids, ['primary_file_id' => null]);
            $updated['file_collections'] = $this->updateMysqlIn('file_collections', 'cover_image_uuid', $assetUuids, [
                'cover_image_uuid' => null,
                'updated_at' => now(),
            ]);

            $updated['genealogy_persons'] = $this->updateMysqlIn('genealogy_persons', 'primary_photo_id', $mediaIds, ['primary_photo_id' => null]);
            $updated['genealogy_citations'] = $this->updateMysqlIn('genealogy_citations', 'media_id', $mediaIds, ['media_id' => null]);
            $deleted['genealogy_face_match_queue'] += $this->deleteMysqlIn('genealogy_face_match_queue', 'media_id', $mediaIds);
            $deleted['genealogy_person_media'] = $this->deleteMysqlIn('genealogy_person_media', 'media_id', $mediaIds);
            $deleted['genealogy_family_media'] = $this->deleteMysqlIn('genealogy_family_media', 'media_id', $mediaIds);
            $deleted['genealogy_media_files'] = $this->deleteMysqlIn('genealogy_media_files', 'media_id', $mediaIds);
            $deleted['genealogy_media_crops'] = $this->deleteMysqlIn('genealogy_media_crops', 'media_id', $mediaIds);
            $deleted['genealogy_media_scan_log'] = $this->deleteMysqlIn('genealogy_media_scan_log', 'nextcloud_path', $paths);
            $deleted['genealogy_media'] = $this->deleteMysqlIn('genealogy_media', 'id', $mediaIds);
            $deleted['file_registry'] = $this->deleteRegistryRows($ids);
        });

        return [
            'deleted' => $deleted,
            'updated' => $updated,
        ];
    }

    private function mysqlReferenceCounts(array $ids, array $assetUuids, array $paths): array
    {
        $videoHashIds = $this->mysqlIdsFor('file_registry_video_hashes', 'id', 'file_registry_id', $ids);
        $faceIds = $this->mysqlIdsFor('file_registry_faces', 'id', 'file_registry_id', $ids);
        $mediaIds = $this->mysqlIdsFor('genealogy_media', 'id', 'nextcloud_path', $paths);

        return [
            'file_registry_faces' => $this->countMysqlIn('file_registry_faces', 'file_registry_id', $ids),
            'file_registry_tags' => $this->countMysqlIn('file_registry_tags', 'file_registry_id', $ids),
            'file_registry_perceptual_hashes' => $this->countMysqlIn('file_registry_perceptual_hashes', 'file_registry_id', $ids),
            'file_registry_video_hashes' => count($videoHashIds),
            'file_registry_duplicates' => $this->countMysqlAnyIn('file_registry_duplicates', ['canonical_file_id', 'duplicate_file_id'], $ids),
            'file_registry_similar_images' => $this->countMysqlAnyIn('file_registry_similar_images', ['file_id_a', 'file_id_b'], $ids),
            'file_registry_similar_videos' => $this->countMysqlAnyIn('file_registry_similar_videos', ['video_hash_id_1', 'video_hash_id_2'], $videoHashIds),
            'file_bundle_members' => $this->countMysqlIn('file_bundle_members', 'file_registry_id', $ids),
            'file_collection_items' => $this->countMysqlIn('file_collection_items', 'file_registry_id', $ids),
            'file_versions' => $this->countMysqlIn('file_versions', 'file_registry_id', $ids),
            'file_registry_path_history' => $this->countMysqlIn('file_registry_path_history', 'file_registry_id', $ids),
            'file_quarantine' => $this->countMysqlQuarantineRows($ids, $assetUuids),
            'file_bundles' => $this->countMysqlIn('file_bundles', 'primary_file_id', $ids),
            'file_collections' => $this->countMysqlIn('file_collections', 'cover_image_uuid', $assetUuids),
            'genealogy_media_scan_log' => $this->countMysqlIn('genealogy_media_scan_log', 'nextcloud_path', $paths),
            'genealogy_media' => count($mediaIds),
            'genealogy_face_match_queue' => $this->countMysqlIn('genealogy_face_match_queue', 'media_id', $mediaIds)
                + $this->countMysqlIn('genealogy_face_match_queue', 'file_registry_face_id', $faceIds),
            'genealogy_person_media' => $this->countMysqlIn('genealogy_person_media', 'media_id', $mediaIds),
            'genealogy_family_media' => $this->countMysqlIn('genealogy_family_media', 'media_id', $mediaIds),
            'genealogy_media_files' => $this->countMysqlIn('genealogy_media_files', 'media_id', $mediaIds),
            'genealogy_media_crops' => $this->countMysqlIn('genealogy_media_crops', 'media_id', $mediaIds),
            'genealogy_persons' => $this->countMysqlIn('genealogy_persons', 'primary_photo_id', $mediaIds),
            'genealogy_citations' => $this->countMysqlIn('genealogy_citations', 'media_id', $mediaIds),
        ];
    }

    private function ragReferenceCounts(array $ids, array $assetUuids, array $paths): array
    {
        $ragDocumentIds = $this->ragDocumentIds($ids, $assetUuids, $paths);

        return array_merge(
            [
                'face_embeddings' => $this->countPgIn('face_embeddings', 'file_registry_id', $ids),
                'file_semantic_embeddings' => $this->countPgIn('file_semantic_embeddings', 'file_id', $ids),
            ],
            $this->ragDocumentReferenceCounts($ragDocumentIds)
        );
    }

    private function ragDocumentReferenceCounts(array $ragDocumentIds): array
    {
        return [
            'rag_documents' => count($ragDocumentIds),
            'rag_documents_dedup_backrefs' => $this->countPgIn('rag_documents', 'dedup_matched_id', $ragDocumentIds),
            'rag_documents_near_duplicate_backrefs' => $this->countPgIn('rag_documents', 'near_duplicate_of', $ragDocumentIds),
            'claims_source_document_refs' => $this->countPgIn('claims', 'source_document_id', $ragDocumentIds),
            'research_facts_rag_document_refs' => $this->countPgIn('research_facts', 'rag_document_id', $ragDocumentIds),
            'knowledge_graph_active_triples' => $this->countPgActiveTriples($ragDocumentIds),
            'knowledge_graph_hyperedges' => $this->countPgIn('knowledge_graph_hyperedges', 'source_document_id', $ragDocumentIds),
            'rag_chunk_hypotheticals' => $this->countPgIn('rag_chunk_hypotheticals', 'document_id', $ragDocumentIds),
            'rag_propositions' => $this->countPgIn('rag_propositions', 'document_id', $ragDocumentIds),
            'rag_dedup_log' => $this->countPgIn('rag_dedup_log', 'matched_document_id', $ragDocumentIds),
            'rag_sentence_embeddings' => $this->countPgIn('rag_sentence_embeddings', 'document_id', $ragDocumentIds),
            'raptor_summaries' => $this->countPgIn('raptor_summaries', 'document_id', $ragDocumentIds),
        ];
    }

    private function purgeRagDocumentIds(array $ragDocumentIds): array
    {
        if (! $this->pgTableExists('rag_documents')) {
            throw new RuntimeException('pgsql_rag.rag_documents is unavailable; refusing to purge orphan file RAG documents.');
        }

        return DB::connection('pgsql_rag')->transaction(fn () => $this->purgeRagDocumentReferences($ragDocumentIds));
    }

    private function purgeRagDocumentReferences(array $ragDocumentIds): array
    {
        $deleted = [
            'knowledge_graph_hyperedges' => 0,
            'rag_chunk_hypotheticals' => 0,
            'rag_propositions' => 0,
            'rag_dedup_log' => 0,
            'rag_sentence_embeddings' => 0,
            'raptor_summaries' => 0,
            'rag_documents' => 0,
        ];
        $updated = [
            'rag_documents_dedup_backrefs' => 0,
            'rag_documents_near_duplicate_backrefs' => 0,
            'claims_source_document_refs' => 0,
            'research_facts_rag_document_refs' => 0,
            'knowledge_graph_active_triples' => 0,
            'knowledge_graph_edge_history' => 0,
        ];

        if ($ragDocumentIds === []) {
            return [
                'deleted' => $deleted,
                'updated' => $updated,
            ];
        }

        $updated = array_merge($updated, $this->clearRagBackReferences($ragDocumentIds));
        $updated = array_merge($updated, $this->expireKnowledgeGraphTriples($ragDocumentIds));
        $deleted['knowledge_graph_hyperedges'] = $this->deletePgIn('knowledge_graph_hyperedges', 'source_document_id', $ragDocumentIds);
        $deleted['rag_chunk_hypotheticals'] = $this->deletePgIn('rag_chunk_hypotheticals', 'document_id', $ragDocumentIds);
        $deleted['rag_propositions'] = $this->deletePgIn('rag_propositions', 'document_id', $ragDocumentIds);
        $deleted['rag_dedup_log'] = $this->deletePgIn('rag_dedup_log', 'matched_document_id', $ragDocumentIds);
        $deleted['rag_sentence_embeddings'] = $this->deletePgIn('rag_sentence_embeddings', 'document_id', $ragDocumentIds);
        $deleted['raptor_summaries'] = $this->deletePgIn('raptor_summaries', 'document_id', $ragDocumentIds);
        $deleted['rag_documents'] = $this->deletePgIn('rag_documents', 'id', $ragDocumentIds);

        return [
            'deleted' => $deleted,
            'updated' => $updated,
        ];
    }

    private function clearRagBackReferences(array $ragDocumentIds): array
    {
        return [
            'rag_documents_dedup_backrefs' => $this->updatePgIn('rag_documents', 'dedup_matched_id', $ragDocumentIds, [
                'dedup_status' => 'unique',
                'dedup_checked_at' => null,
                'dedup_matched_id' => null,
                'dedup_similarity' => null,
                'updated_at' => now(),
            ]),
            'rag_documents_near_duplicate_backrefs' => $this->updatePgIn('rag_documents', 'near_duplicate_of', $ragDocumentIds, [
                'near_duplicate_of' => null,
                'similarity_score' => null,
                'updated_at' => now(),
            ]),
            'claims_source_document_refs' => $this->updatePgIn('claims', 'source_document_id', $ragDocumentIds, [
                'source_document_id' => null,
                'updated_at' => now(),
            ]),
            'research_facts_rag_document_refs' => $this->updatePgIn('research_facts', 'rag_document_id', $ragDocumentIds, [
                'rag_document_id' => null,
                'rag_cross_referenced' => false,
                'rag_match_score' => null,
                'updated_at' => now(),
            ]),
        ];
    }

    private function expireKnowledgeGraphTriples(array $ragDocumentIds): array
    {
        $result = [
            'knowledge_graph_active_triples' => 0,
            'knowledge_graph_edge_history' => 0,
        ];

        if (! $this->pgTableExists('knowledge_graph')) {
            return $result;
        }

        $tripleIds = [];
        foreach (array_chunk($ragDocumentIds, 1000) as $chunk) {
            $tripleIds = array_merge($tripleIds, DB::connection('pgsql_rag')
                ->table('knowledge_graph')
                ->whereIn('source_document_id', $chunk)
                ->whereNull('t_expired')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all());
        }

        $tripleIds = array_values(array_unique($tripleIds));
        if ($tripleIds === []) {
            return $result;
        }

        foreach (array_chunk($tripleIds, 1000) as $chunk) {
            $result['knowledge_graph_active_triples'] += DB::connection('pgsql_rag')
                ->table('knowledge_graph')
                ->whereIn('id', $chunk)
                ->update([
                    't_expired' => now(),
                    'updated_at' => now(),
                ]);
        }

        if ($this->pgTableExists('knowledge_graph_edge_history')) {
            foreach (array_chunk($tripleIds, 250) as $chunk) {
                $values = implode(', ', array_fill(0, count($chunk), "(?, 'invalidated', ?::jsonb, ?, NULL, 'file_lifecycle', NOW())"));
                $params = [];

                foreach ($chunk as $tripleId) {
                    $params[] = $tripleId;
                    $params[] = json_encode(['t_expired' => null, 'superseded_by' => null]);
                    $params[] = self::HISTORY_REASON;
                }

                DB::connection('pgsql_rag')->insert("
                    INSERT INTO knowledge_graph_edge_history (
                        triple_id, action, old_values, reason, caused_by_triple_id, actor, created_at
                    ) VALUES {$values}
                ", $params);

                $result['knowledge_graph_edge_history'] += count($chunk);
            }
        }

        return $result;
    }

    private function ragDocumentIds(array $ids, array $assetUuids, array $paths): array
    {
        if (! $this->pgTableExists('rag_documents')) {
            return [];
        }

        $idStrings = array_map('strval', $ids);
        $sourceIds = $this->strings(array_merge(
            $assetUuids,
            $paths,
            $idStrings,
            array_map(fn (int $id): string => "file_registry_{$id}", $ids)
        ));

        $clauses = [];
        $params = [];
        $this->appendPgInClause($clauses, $params, "metadata->>'asset_uuid'", $assetUuids);
        $this->appendPgInClause($clauses, $params, "metadata->>'file_path'", $paths);
        $this->appendPgInClause($clauses, $params, "metadata->>'path'", $paths);
        $this->appendPgInClause($clauses, $params, "metadata->>'current_path'", $paths);
        $this->appendPgInClause($clauses, $params, "metadata->>'file_registry_id'", $idStrings);
        $this->appendPgInClause($clauses, $params, "metadata->>'file_id'", $idStrings);
        $this->appendPgInClause($clauses, $params, 'source_id', $sourceIds);

        if ($clauses === []) {
            return [];
        }

        $sql = sprintf("
            SELECT id
            FROM rag_documents
            WHERE (source_type = 'file_registry' OR document_type = 'file_catalog')
              AND (%s)
        ", implode(' OR ', $clauses));

        $rows = DB::connection('pgsql_rag')->select($sql, $params);

        return array_values(array_unique(array_map(fn ($row) => (int) $row->id, $rows)));
    }

    private function orphanRagFileDocuments(int $limit): array
    {
        if (! $this->pgTableExists('rag_documents')) {
            return [
                'total' => 0,
                'ids' => [],
                'samples' => [],
            ];
        }

        $activeIds = DB::table('file_registry')
            ->where('status', 'active')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->flip();
        $activeUuids = DB::table('file_registry')
            ->where('status', 'active')
            ->pluck('asset_uuid')
            ->filter()
            ->flip();
        $activePaths = DB::table('file_registry')
            ->where('status', 'active')
            ->pluck('current_path')
            ->filter()
            ->flip();

        $total = 0;
        $ids = [];
        $samples = [];

        $rows = DB::connection('pgsql_rag')->table('rag_documents')
            ->select(['id', 'source_id', 'metadata', 'source_type', 'document_type'])
            ->where(function ($query): void {
                $query->where('source_type', 'file_registry')
                    ->orWhere('document_type', 'file_catalog');
            })
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            $metadata = $this->decodeMetadata($row->metadata ?? null);
            $sourceId = trim((string) ($row->source_id ?? ''));
            $uuid = trim((string) ($metadata['asset_uuid'] ?? ''));
            $path = trim((string) ($metadata['file_path'] ?? $metadata['path'] ?? $metadata['current_path'] ?? ''));
            $fileId = trim((string) ($metadata['file_registry_id'] ?? $metadata['file_id'] ?? ''));
            if ($fileId === '' && preg_match('/^file_registry_(\d+)$/', $sourceId, $matches)) {
                $fileId = $matches[1];
            }

            $linked = ($uuid !== '' && isset($activeUuids[$uuid]))
                || ($path !== '' && isset($activePaths[$path]))
                || ($fileId !== '' && isset($activeIds[$fileId]))
                || ($sourceId !== '' && (
                    isset($activeUuids[$sourceId])
                    || isset($activePaths[$sourceId])
                    || isset($activeIds[$sourceId])
                ));

            if ($linked) {
                continue;
            }

            $total++;
            if (count($ids) < $limit) {
                $ids[] = (int) $row->id;
            }
            if (count($samples) < 5) {
                $samples[] = [
                    'id' => (int) $row->id,
                    'source_id' => $sourceId,
                    'asset_uuid' => $uuid,
                    'path' => $path,
                    'file_registry_id' => $fileId,
                ];
            }
        }

        return [
            'total' => $total,
            'ids' => $ids,
            'samples' => $samples,
        ];
    }

    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function appendPgInClause(array &$clauses, array &$params, string $expression, array $values): void
    {
        $values = $this->strings($values);
        if ($values === []) {
            return;
        }

        $clauses[] = $expression.' IN ('.implode(', ', array_fill(0, count($values), '?')).')';
        array_push($params, ...$values);
    }

    private function countMysqlIn(string $table, string $column, array $values): int
    {
        if (! $this->mysqlTableExists($table) || $values === []) {
            return 0;
        }

        $total = 0;
        foreach (array_chunk($values, 1000) as $chunk) {
            $total += (int) DB::table($table)->whereIn($column, $chunk)->count();
        }

        return $total;
    }

    private function countMysqlAnyIn(string $table, array $columns, array $values): int
    {
        if (! $this->mysqlTableExists($table) || $values === []) {
            return 0;
        }

        return (int) DB::table($table)
            ->where(function ($query) use ($columns, $values): void {
                foreach ($columns as $index => $column) {
                    $method = $index === 0 ? 'whereIn' : 'orWhereIn';
                    $query->{$method}($column, $values);
                }
            })
            ->count();
    }

    private function countMysqlQuarantineRows(array $ids, array $assetUuids): int
    {
        if (! $this->mysqlTableExists('file_quarantine') || ($ids === [] && $assetUuids === [])) {
            return 0;
        }

        return (int) DB::table('file_quarantine')
            ->where(function ($query) use ($ids, $assetUuids): void {
                if ($ids !== []) {
                    $query->whereIn('file_registry_id', $ids);
                }
                if ($assetUuids !== []) {
                    $ids === []
                        ? $query->whereIn('asset_uuid', $assetUuids)
                        : $query->orWhereIn('asset_uuid', $assetUuids);
                }
            })
            ->count();
    }

    private function countPgIn(string $table, string $column, array $values): int
    {
        if (! $this->pgTableExists($table) || $values === []) {
            return 0;
        }

        $total = 0;
        foreach (array_chunk($values, 1000) as $chunk) {
            $total += (int) DB::connection('pgsql_rag')->table($table)->whereIn($column, $chunk)->count();
        }

        return $total;
    }

    private function countPgActiveTriples(array $ragDocumentIds): int
    {
        if (! $this->pgTableExists('knowledge_graph') || $ragDocumentIds === []) {
            return 0;
        }

        $total = 0;
        foreach (array_chunk($ragDocumentIds, 1000) as $chunk) {
            $total += (int) DB::connection('pgsql_rag')
                ->table('knowledge_graph')
                ->whereIn('source_document_id', $chunk)
                ->whereNull('t_expired')
                ->count();
        }

        return $total;
    }

    private function deleteMysqlIn(string $table, string $column, array $values): int
    {
        if (! $this->mysqlTableExists($table) || $values === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($values, 1000) as $chunk) {
            $deleted += DB::table($table)->whereIn($column, $chunk)->delete();
        }

        return $deleted;
    }

    private function deleteMysqlAnyIn(string $table, array $columns, array $values): int
    {
        if (! $this->mysqlTableExists($table) || $values === []) {
            return 0;
        }

        return DB::table($table)
            ->where(function ($query) use ($columns, $values): void {
                foreach ($columns as $index => $column) {
                    $method = $index === 0 ? 'whereIn' : 'orWhereIn';
                    $query->{$method}($column, $values);
                }
            })
            ->delete();
    }

    private function deleteMysqlQuarantineRows(array $ids, array $assetUuids): int
    {
        if (! $this->mysqlTableExists('file_quarantine') || ($ids === [] && $assetUuids === [])) {
            return 0;
        }

        return DB::table('file_quarantine')
            ->where(function ($query) use ($ids, $assetUuids): void {
                if ($ids !== []) {
                    $query->whereIn('file_registry_id', $ids);
                }
                if ($assetUuids !== []) {
                    $ids === []
                        ? $query->whereIn('asset_uuid', $assetUuids)
                        : $query->orWhereIn('asset_uuid', $assetUuids);
                }
            })
            ->delete();
    }

    private function deletePgIn(string $table, string $column, array $values): int
    {
        if (! $this->pgTableExists($table) || $values === []) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($values, 1000) as $chunk) {
            $deleted += DB::connection('pgsql_rag')->table($table)->whereIn($column, $chunk)->delete();
        }

        return $deleted;
    }

    private function updateMysqlIn(string $table, string $column, array $values, array $updates): int
    {
        if (! $this->mysqlTableExists($table) || $values === []) {
            return 0;
        }

        $updated = 0;
        foreach (array_chunk($values, 1000) as $chunk) {
            $updated += DB::table($table)->whereIn($column, $chunk)->update($updates);
        }

        return $updated;
    }

    private function updatePgIn(string $table, string $column, array $values, array $updates): int
    {
        if (! $this->pgTableExists($table) || $values === []) {
            return 0;
        }

        $updated = 0;
        foreach (array_chunk($values, 1000) as $chunk) {
            $updated += DB::connection('pgsql_rag')->table($table)->whereIn($column, $chunk)->update($updates);
        }

        return $updated;
    }

    private function deleteRegistryRows(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $deleted = 0;

        // Child rows are explicitly purged above; skipping session FK checks avoids
        // expensive repeated cascade scans when removing old tombstone batches.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $deleted += DB::table('file_registry')
                    ->where('status', 'deleted')
                    ->whereIn('id', $chunk)
                    ->delete();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $deleted;
    }

    private function mysqlIdsFor(string $table, string $selectColumn, string $whereColumn, array $values): array
    {
        if (! $this->mysqlTableExists($table) || $values === []) {
            return [];
        }

        $ids = [];
        foreach (array_chunk($values, 1000) as $chunk) {
            $ids = array_merge($ids, DB::table($table)
                ->whereIn($whereColumn, $chunk)
                ->pluck($selectColumn)
                ->map(fn ($id) => (int) $id)
                ->all());
        }

        return array_values(array_unique($ids));
    }

    private function candidateIds(array $rows): array
    {
        return array_values(array_unique(array_map(fn ($row) => (int) $row->id, $rows)));
    }

    private function candidateAssetUuids(array $rows): array
    {
        return $this->strings(array_map(fn ($row) => $row->asset_uuid ?? null, $rows));
    }

    private function candidatePaths(array $rows): array
    {
        return $this->strings(array_map(fn ($row) => $row->current_path ?? null, $rows));
    }

    private function strings(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($value) => is_scalar($value) ? trim((string) $value) : '', $values),
            fn (string $value) => $value !== ''
        )));
    }

    private function retentionDays(): int
    {
        return max(0, (int) config('file_lifecycle.hard_purge_retention_days', 7));
    }

    private function retentionCutoff(int $retentionDays, bool $force): ?Carbon
    {
        if ($force || $retentionDays <= 0) {
            return null;
        }

        return now()->subDays($retentionDays);
    }

    private function mysqlTableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Exception) {
            return false;
        }
    }

    private function pgTableExists(string $table): bool
    {
        try {
            return Schema::connection('pgsql_rag')->hasTable($table);
        } catch (Exception) {
            return false;
        }
    }
}
