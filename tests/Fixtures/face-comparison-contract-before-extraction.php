<?php

use App\Services\FaceEmbeddingService;
use App\Services\NextcloudFileApiService;
use Illuminate\Support\Facades\DB;

/** Frozen pair-reader methods from source SHA 0dcdcbd2a32a449fca5c5aa1cd4581cf889ed1ee87c136fbf6c223b065e93684. */
return static function (FaceEmbeddingService $faces): object {
    return new class($faces)
    {
        public function __construct(private FaceEmbeddingService $faces) {}

        public function evidence(int $sourceId, int $candidateId): array
        {
            return $this->currentPair($sourceId, $candidateId);
        }

        private function currentPair(int $sourceId, int $candidateId): array
        {
            if ($sourceId <= 0 || $candidateId <= 0 || $sourceId === $candidateId) {
                throw new RuntimeException('comparison_request_invalid');
            }
            $rows = [];
            $pins = [];
            $proofs = [];
            $provenanceHashes = [];
            $sourcePathHashes = [];
            foreach ([$sourceId, $candidateId] as $id) {
                $row = DB::connection('pgsql_rag')->selectOne(
                    'SELECT id, person_cluster_id, file_registry_id, file_registry_face_id, source_sha256, face_region_convention, source_current, superseded_at, region_x, region_y, region_w, region_h, embedding, embedding_model FROM face_embeddings WHERE id = ?',
                    [$id],
                );
                $pin = $this->faces->comparisonEmbeddingEvidence($id, true);
                $provenance = $pin['generation_provenance'] ?? null;
                if (! $row || ! is_array($pin) || ! is_array($provenance)
                    || ! $this->faces->embeddingHasCurrentSourceEvidence($row)) {
                    throw new RuntimeException('comparison_source_unavailable');
                }
                unset($pin['generation_provenance']);
                if (! $this->faces->comparisonEvidenceMatchesStoredRow($row, $pin)) {
                    throw new RuntimeException('comparison_source_changed');
                }
                $this->validateProvenance($pin, $provenance);
                $file = DB::table('file_registry')->where('id', (int) $row->file_registry_id)->where('status', 'active')->first(['id', 'current_path']);
                if (! $file || ! is_string($file->current_path) || $file->current_path === '') {
                    throw new RuntimeException('comparison_source_unavailable');
                }
                $localPath = app(NextcloudFileApiService::class)->localPath($file->current_path);
                $resolvedPath = is_string($localPath) ? realpath($localPath) : false;
                if (! is_string($resolvedPath) || ! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
                    throw new RuntimeException('comparison_source_unavailable');
                }
                $rows[] = ['row' => $row, 'file' => $file, 'local_path' => $resolvedPath];
                $pins[] = $pin;
                $proofs[] = $provenance;
                $provenanceHashes[] = hash('sha256', $this->canonicalJson($provenance));
                $sourcePathHashes[] = hash('sha256', $file->current_path."\0".$resolvedPath);
            }
            $contract = [];
            foreach ([$pins[0], $pins[1]] as $index => $pin) {
                $proof = $proofs[$index];
                $engine = $proof['engine_provenance'] ?? null;
                $modelHash = $proof['model_sha256'] ?? null;
                if (! is_array($engine) || ! is_string($modelHash) || ! preg_match('/^[a-f0-9]{64}$/D', $modelHash)) {
                    throw new RuntimeException('comparison_model_proof_unavailable');
                }
                $contract[] = ['embedding_model' => $pin['embedding_model'], 'model_sha256' => $modelHash,
                    'dimension' => 128, 'engine_contract_sha256' => hash('sha256', $this->canonicalJson($engine))];
            }
            if ($contract[0] !== $contract[1]) {
                throw new RuntimeException('comparison_model_contract_incompatible');
            }
            $binding = ['schema' => 'plos.local_face_comparison.v1', 'source_embedding_id' => $sourceId,
                'candidate_embedding_id' => $candidateId, 'pins' => $pins, 'provenance_sha256' => $provenanceHashes,
                'source_path_sha256' => $sourcePathHashes,
                'contract' => $contract[0]];

            return ['pins' => $pins, 'provenance_sha256' => $provenanceHashes,
                'contract_sha256' => hash('sha256', $this->canonicalJson($contract[0])),
                'digest' => hash('sha256', $this->canonicalJson($binding)), 'source_file_id' => (int) $rows[0]['file']->id,
                'source_path' => $rows[0]['local_path'], 'source_vector' => is_string($rows[0]['row']->embedding)
                    ? json_decode($rows[0]['row']->embedding, true, 128, JSON_THROW_ON_ERROR) : $rows[0]['row']->embedding,
                'candidate_row' => $this->candidateArguments($rows[1]['row'])];
        }

        private function validateProvenance(array $pin, array $proof): void
        {
            $engine = $proof['engine_provenance'] ?? null;
            if (! is_array($engine) || ($engine['embedding_model'] ?? null) !== ($pin['embedding_model'] ?? null)
                || ($proof['proof_origin'] ?? null) === null || ! is_string($proof['model_sha256'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', $proof['model_sha256'])
                || ! is_string($proof['embedding_float32_sha256'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', $proof['embedding_float32_sha256'])) {
                throw new RuntimeException('comparison_model_proof_unavailable');
            }
            $dimension = $engine['embedding_dimension'] ?? $engine['embedding_dimensions'] ?? 128;
            if ($dimension !== 128) {
                throw new RuntimeException('comparison_model_contract_incompatible');
            }
        }

        private function candidateArguments(object $row): array
        {
            return ['face_id' => (int) $row->id, 'person_cluster_id' => $row->person_cluster_id === null ? null : (int) $row->person_cluster_id, 'file_registry_face_id' => (int) $row->file_registry_face_id,
                'file_registry_id' => (int) $row->file_registry_id, 'source_sha256' => $row->source_sha256,
                'face_region_convention' => $row->face_region_convention, 'source_current' => $row->source_current,
                'superseded_at' => $row->superseded_at, 'region_x' => $row->region_x, 'region_y' => $row->region_y,
                'region_w' => $row->region_w, 'region_h' => $row->region_h];
        }

        private function canonicalJson(array $value): string
        {
            $sort = function (mixed $item) use (&$sort): mixed {
                if (! is_array($item)) {
                    return $item;
                }
                if (! array_is_list($item)) {
                    ksort($item);
                }
                foreach ($item as $key => $child) {
                    $item[$key] = $sort($child);
                }

                return $item;
            };

            return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }
    };
};
