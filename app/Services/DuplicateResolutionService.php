<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DuplicateResolutionService
{
    public function __construct(
        private PerceptualHashService $phashService,
        private FileRegistryService $fileRegistryService,
    ) {}

    /**
     * Get pending duplicate pairs where BOTH files match the folder filter.
     */
    public function getScopedPendingPairs(string $folderFilter): array
    {
        $like = '%' . $folderFilter . '%';

        return DB::select("
            SELECT d.id as pair_id, d.content_hash,
                   f1.id as file_id_a, f1.asset_uuid as uuid_a, f1.filename as filename_a,
                   f1.current_path as path_a, f1.file_size as size_a, f1.mime_type as mime_type_a,
                   f1.ai_tags as tags_a, f1.ai_document_type as type_a,
                   f1.category as category_a, f1.updated_at as updated_a,
                   f2.id as file_id_b, f2.asset_uuid as uuid_b, f2.filename as filename_b,
                   f2.current_path as path_b, f2.file_size as size_b, f2.mime_type as mime_type_b,
                   f2.ai_tags as tags_b, f2.ai_document_type as type_b,
                   f2.category as category_b, f2.updated_at as updated_b,
                   ph1.dhash_hex as dhash_a, ph2.dhash_hex as dhash_b
            FROM file_registry_duplicates d
            JOIN file_registry f1 ON d.canonical_file_id = f1.id
            JOIN file_registry f2 ON d.duplicate_file_id = f2.id
            LEFT JOIN file_registry_perceptual_hashes ph1 ON ph1.file_registry_id = f1.id
            LEFT JOIN file_registry_perceptual_hashes ph2 ON ph2.file_registry_id = f2.id
            WHERE d.status = 'pending_review'
              AND f1.status = 'active' AND f2.status = 'active'
              AND f1.current_path LIKE ? AND f2.current_path LIKE ?
            ORDER BY f1.file_size DESC
        ", [$like, $like]);
    }

    /**
     * Random-sample audit of duplicate pairs for verification.
     */
    public function auditSample(array $pairs, int $sampleSize = 30): array
    {
        $sampleSize = min($sampleSize, count($pairs));
        $indices = array_rand($pairs, $sampleSize);
        if (!is_array($indices)) {
            $indices = [$indices];
        }

        $passed = 0;
        $failed = 0;
        $failures = [];

        foreach ($indices as $idx) {
            $result = $this->verifyPair($pairs[$idx]);
            if ($result['verified']) {
                $passed++;
            } else {
                $failed++;
                $failures[] = [
                    'pair_id' => $pairs[$idx]->pair_id,
                    'path_a' => $pairs[$idx]->path_a,
                    'path_b' => $pairs[$idx]->path_b,
                    'reason' => $result['reasoning'],
                ];
            }
        }

        return [
            'total_sampled' => $sampleSize,
            'passed' => $passed,
            'failed' => $failed,
            'accuracy' => $sampleSize > 0 ? $passed / $sampleSize : 0.0,
            'failures' => $failures,
        ];
    }

    /**
     * Verify a single duplicate pair.
     *
     * Layer 1: Content hash (byte-identical by definition in this table).
     * Layer 2: Perceptual hash hamming distance for images (defense-in-depth).
     */
    public function verifyPair(object $pair): array
    {
        // Layer 1: Content hash match — these pairs exist because content_hash matched
        if (!empty($pair->content_hash)) {
            $result = [
                'verified' => true,
                'method' => 'content_hash',
                'hamming_distance' => null,
                'reasoning' => 'Byte-identical content hash match',
            ];

            // Layer 2: Phash cross-check for images (defense-in-depth)
            if ($this->isImage($pair->mime_type_a) && $pair->dhash_a && $pair->dhash_b) {
                $distance = $this->phashService->hammingDistance($pair->dhash_a, $pair->dhash_b);
                $result['hamming_distance'] = $distance;

                if ($distance > 5) {
                    // Byte-identical files with different phash = suspicious
                    return [
                        'verified' => false,
                        'method' => 'phash_mismatch',
                        'hamming_distance' => $distance,
                        'reasoning' => "Content hash matches but phash hamming distance is {$distance} (>5). Possible hash collision or corrupt file.",
                    ];
                }

                $result['reasoning'] .= " + phash confirmed (distance: {$distance})";
            }

            return $result;
        }

        return [
            'verified' => false,
            'method' => 'no_content_hash',
            'hamming_distance' => null,
            'reasoning' => 'No content hash available for verification',
        ];
    }

    /**
     * Resolve all pairs: pick keeper, update status to merged.
     */
    public function resolveAll(array $pairs, bool $dryRun = false): array
    {
        $resolved = 0;
        $errors = 0;
        $totalBytesReclaimable = 0;

        foreach ($pairs as $pair) {
            try {
                $scoreA = $this->fileRegistryService->scoreDuplicateCandidate($pair, 'a');
                $scoreB = $this->fileRegistryService->scoreDuplicateCandidate($pair, 'b');

                $keepSide = $scoreA >= $scoreB ? 'a' : 'b';
                $keepUuid = $keepSide === 'a' ? $pair->uuid_a : $pair->uuid_b;
                $removeUuid = $keepSide === 'a' ? $pair->uuid_b : $pair->uuid_a;
                $reclaimable = $keepSide === 'a' ? ($pair->size_b ?? 0) : ($pair->size_a ?? 0);

                $auditTrail = [
                    'keep_uuid' => $keepUuid,
                    'remove_uuid' => $removeUuid,
                    'score_a' => $scoreA,
                    'score_b' => $scoreB,
                    'keep_side' => $keepSide,
                    'content_hash' => $pair->content_hash,
                    'resolved_by' => 'ai_audit',
                    'resolved_at' => now()->toDateTimeString(),
                ];

                if (!$dryRun) {
                    DB::update(
                        "UPDATE file_registry_duplicates
                         SET status = 'merged', reviewed_by = 'ai', reviewed_at = NOW(),
                             notes = ?
                         WHERE id = ?",
                        [json_encode($auditTrail), $pair->pair_id]
                    );
                }

                $resolved++;
                $totalBytesReclaimable += $reclaimable;
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('DuplicateResolution: Failed to resolve pair', [
                    'pair_id' => $pair->pair_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'resolved' => $resolved,
            'errors' => $errors,
            'total_bytes_reclaimable' => $totalBytesReclaimable,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Get statistics for scoped duplicates.
     */
    public function getStatistics(string $folderFilter): array
    {
        $like = '%' . $folderFilter . '%';

        $total = DB::selectOne("
            SELECT COUNT(*) as cnt FROM file_registry_duplicates d
            JOIN file_registry f1 ON d.canonical_file_id = f1.id
            JOIN file_registry f2 ON d.duplicate_file_id = f2.id
            WHERE d.status = 'pending_review'
              AND f1.current_path LIKE ? AND f2.current_path LIKE ?
        ", [$like, $like]);

        $byMimeType = DB::select("
            SELECT f1.mime_type, COUNT(*) as cnt FROM file_registry_duplicates d
            JOIN file_registry f1 ON d.canonical_file_id = f1.id
            JOIN file_registry f2 ON d.duplicate_file_id = f2.id
            WHERE d.status = 'pending_review'
              AND f1.current_path LIKE ? AND f2.current_path LIKE ?
            GROUP BY f1.mime_type ORDER BY cnt DESC
        ", [$like, $like]);

        $withPhash = DB::selectOne("
            SELECT COUNT(*) as cnt FROM file_registry_duplicates d
            JOIN file_registry f1 ON d.canonical_file_id = f1.id
            JOIN file_registry f2 ON d.duplicate_file_id = f2.id
            JOIN file_registry_perceptual_hashes ph1 ON ph1.file_registry_id = f1.id
            JOIN file_registry_perceptual_hashes ph2 ON ph2.file_registry_id = f2.id
            WHERE d.status = 'pending_review'
              AND f1.current_path LIKE ? AND f2.current_path LIKE ?
        ", [$like, $like]);

        $uniqueHashes = DB::selectOne("
            SELECT COUNT(DISTINCT d.content_hash) as cnt FROM file_registry_duplicates d
            JOIN file_registry f1 ON d.canonical_file_id = f1.id
            JOIN file_registry f2 ON d.duplicate_file_id = f2.id
            WHERE d.status = 'pending_review'
              AND f1.current_path LIKE ? AND f2.current_path LIKE ?
        ", [$like, $like]);

        $totalSize = DB::selectOne("
            SELECT SUM(f2.file_size) as bytes FROM file_registry_duplicates d
            JOIN file_registry f1 ON d.canonical_file_id = f1.id
            JOIN file_registry f2 ON d.duplicate_file_id = f2.id
            WHERE d.status = 'pending_review'
              AND f1.current_path LIKE ? AND f2.current_path LIKE ?
        ", [$like, $like]);

        return [
            'pending_pairs' => (int) ($total->cnt ?? 0),
            'unique_content_hashes' => (int) ($uniqueHashes->cnt ?? 0),
            'pairs_with_phash' => (int) ($withPhash->cnt ?? 0),
            'reclaimable_bytes' => (int) ($totalSize->bytes ?? 0),
            'by_mime_type' => array_map(fn($r) => ['type' => $r->mime_type, 'count' => (int) $r->cnt], $byMimeType),
        ];
    }

    private function isImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }
}
