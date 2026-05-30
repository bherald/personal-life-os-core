<?php

namespace App\Services;

use App\Services\Genealogy\FaceLinkBridgeService;
use App\Support\PgVector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * FaceEmbeddingService - AI Face Detection and Embedding
 *
 * Detects faces in images, generates embeddings, finds matches,
 * and clusters unknown faces for human review.
 *
 * Uses:
 * - Python face_recognition library for detection/embeddings (CPU, no GPU lock needed)
 * - pgvector for similarity search
 * - Claude AI for face verification when confidence is borderline (via AIService)
 * - Ollama vision for additional face context (via AIService with GPU locks)
 *
 * AIService Integration:
 * - Uses AIService.processImage() for vision tasks (handles Ollama lock + Claude fallback)
 * - Python face_recognition runs on CPU (no contention with GPU workloads)
 * - All AI calls use the standard AIService resilience pipeline
 */
class FaceEmbeddingService
{
    private const VECTOR_LITERAL_PRECISION = 6;

    private const PYTHON_SCRIPT = 'scripts/face_detector.py';

    private const FACE_CROPS_DIR = 'storage/app/face_crops';

    private const MATCH_TOLERANCE = 0.6;  // dlib default

    private const HIGH_CONFIDENCE = 0.92;  // Tightened from 0.88 — dlib 128-dim needs stricter threshold

    private const LOW_CONFIDENCE = 0.65;

    private const CLUSTER_SOFT_CAP = 20;  // After this many faces, require stricter confidence

    private const CLUSTER_CAP_BOOST = 0.03; // Extra confidence required above soft cap (0.92+0.03=0.95)

    // Cache keys for tracking face detection availability
    private const CACHE_AVAILABILITY_KEY = 'face_recognition_available';

    private const CACHE_AVAILABILITY_TTL = 3600;

    // Lock for preventing concurrent heavy face detection batches
    private const FACE_BATCH_LOCK_KEY = 'face_detection_batch_lock';

    private const FACE_BATCH_LOCK_TTL = 600;  // Fallback — config/lock_ttls.php is primary (SC-2.3)

    public function __construct(
        private AIService $aiService,
        private ?string $pythonPath = null
    ) {
        $this->pythonPath = $pythonPath ?? '/usr/bin/python3';
    }

    /**
     * Detect faces in an image and generate embeddings
     *
     * @param  string  $imagePath  Local file path
     * @param  bool  $saveCrops  Save cropped face images
     * @return array Detection result with faces and embeddings
     */
    public function detectFaces(string $imagePath, bool $saveCrops = true): array
    {
        if (! file_exists($imagePath)) {
            return ['success' => false, 'error' => 'File not found: '.$imagePath];
        }

        $scriptPath = base_path(self::PYTHON_SCRIPT);
        if (! file_exists($scriptPath)) {
            return ['success' => false, 'error' => 'Python script not found'];
        }

        $outputDir = storage_path('app/face_crops');
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $outputFile = tempnam(sys_get_temp_dir(), 'face_detect_');

        $command = [
            $this->pythonPath,
            $scriptPath,
            '--image',
            $imagePath,
            '--output',
            $outputFile,
        ];

        if ($saveCrops) {
            $command[] = '--save-crops';
            $command[] = '--output-dir';
            $command[] = $outputDir;
        }

        $output = Process::timeout(120)->run($command)->output();

        if (file_exists($outputFile)) {
            $result = json_decode(file_get_contents($outputFile), true);
            @unlink($outputFile);

            if ($result && $result['success']) {
                return $result;
            }
        }

        // Parse output if JSON file failed
        $jsonResult = json_decode($output, true);
        if ($jsonResult) {
            return $jsonResult;
        }

        return ['success' => false, 'error' => 'Face detection failed', 'output' => $output];
    }

    /**
     * Process an image: detect faces, store embeddings, find matches
     *
     * @param  int  $fileRegistryId  file_registry.id
     * @param  string  $imagePath  Local file path
     * @return array Processing result
     */
    public function processImage(int $fileRegistryId, string $imagePath): array
    {
        $detection = $this->detectFaces($imagePath, true);

        if (! $detection['success']) {
            return $detection;
        }

        if (empty($detection['faces'])) {
            // Update file_registry to mark as scanned (no faces)
            DB::update('
                UPDATE file_registry
                SET face_count = 0, face_scan_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ', [$fileRegistryId]);

            return [
                'success' => true,
                'faces_detected' => 0,
                'faces_matched' => 0,
                'faces_new' => 0,
            ];
        }

        $facesMatched = 0;
        $facesNew = 0;

        foreach ($detection['faces'] as $face) {
            $embedding = $face['embedding'];
            $normalized = $face['normalized'];
            $cropPath = $face['crop_path'] ?? null;

            // Search for matching faces in database (single best match for reference)
            $matches = $this->findMatchingFaces($embedding);

            $personClusterId = null;
            $matchedFaceId = null;
            $confidence = 0;

            if (! empty($matches)) {
                $bestMatch = $matches[0];
                $confidence = $bestMatch['confidence'];
                $candidateClusterId = $bestMatch['person_cluster_id'];

                // Validate against cluster average similarity (prevents transitive chaining)
                $clusterAvgSim = $this->getClusterAverageSimilarity($embedding, $candidateClusterId);

                // Determine confidence threshold (stricter for large clusters)
                $clusterSize = (int) ($bestMatch['face_count'] ?? $this->getClusterFaceCount($candidateClusterId));
                $threshold = self::HIGH_CONFIDENCE;
                if ($clusterSize >= self::CLUSTER_SOFT_CAP) {
                    $threshold += self::CLUSTER_CAP_BOOST;
                }

                // Use cluster average as the authoritative confidence for auto-assignment
                $effectiveConfidence = $clusterAvgSim ?? $confidence;

                // High confidence against cluster average: auto-assign
                if ($effectiveConfidence >= $threshold) {
                    $personClusterId = $candidateClusterId;
                    $matchedFaceId = $bestMatch['face_id'];
                    $confidence = $effectiveConfidence;
                    $facesMatched++;
                }
                // Medium confidence: verify with AI using best single-face match
                elseif ($confidence >= self::LOW_CONFIDENCE && $cropPath && $bestMatch['crop_path']) {
                    $verified = $this->verifyFaceMatchWithAI($cropPath, $bestMatch['crop_path']);
                    if ($verified) {
                        $personClusterId = $candidateClusterId;
                        $matchedFaceId = $bestMatch['face_id'];
                        $facesMatched++;
                    }
                }
            }

            // Create new cluster if no match
            if (! $personClusterId) {
                $personClusterId = $this->createPersonCluster();
                $facesNew++;
            }

            // Store face embedding
            $this->storeFaceEmbedding(
                $fileRegistryId,
                $embedding,
                $normalized,
                $personClusterId,
                $cropPath,
                $matchedFaceId,
                $confidence
            );
        }

        // Update file_registry
        DB::update('
            UPDATE file_registry
            SET face_count = ?, face_scan_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ', [count($detection['faces']), $fileRegistryId]);

        return [
            'success' => true,
            'faces_detected' => count($detection['faces']),
            'faces_matched' => $facesMatched,
            'faces_new' => $facesNew,
        ];
    }

    /**
     * Find faces matching the given embedding using pgvector
     *
     * @param  array  $embedding  128-dim face embedding
     * @param  float  $tolerance  Match tolerance (lower = stricter)
     * @param  int  $limit  Max results
     * @return array Matching faces with confidence scores
     */
    public function findMatchingFaces(array $embedding, float $tolerance = self::MATCH_TOLERANCE, int $limit = 5): array
    {
        $embeddingStr = PgVector::literal($embedding, self::VECTOR_LITERAL_PRECISION);

        try {
            // Use pgvector cosine distance for similarity search
            $matches = DB::connection('pgsql_rag')->select('
                SELECT
                    fe.id as face_id,
                    fe.person_cluster_id,
                    fe.crop_path,
                    fe.file_registry_id,
                    pc.name as cluster_name,
                    pc.genealogy_person_id,
                    pc.face_count,
                    1 - (fe.embedding <=> ?::vector) as confidence
                FROM face_embeddings fe
                LEFT JOIN person_clusters pc ON pc.id = fe.person_cluster_id
                WHERE 1 - (fe.embedding <=> ?::vector) >= ?
                ORDER BY fe.embedding <=> ?::vector
                LIMIT ?
            ', [$embeddingStr, $embeddingStr, 1 - $tolerance, $embeddingStr, $limit]);

            return array_map(fn ($m) => (array) $m, $matches);
        } catch (\Exception $e) {
            Log::warning('FaceEmbedding: pgvector search failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Get average cosine similarity of an embedding against all faces in a cluster.
     * Prevents transitive chaining by requiring overall cluster affinity, not just one match.
     */
    private function getClusterAverageSimilarity(array $embedding, int $clusterId): ?float
    {
        $embeddingStr = PgVector::literal($embedding, self::VECTOR_LITERAL_PRECISION);

        try {
            $result = DB::connection('pgsql_rag')->selectOne('
                SELECT AVG(1 - (fe.embedding <=> ?::vector)) as avg_similarity
                FROM face_embeddings fe
                WHERE fe.person_cluster_id = ?
            ', [$embeddingStr, $clusterId]);

            return $result ? (float) $result->avg_similarity : null;
        } catch (\Exception $e) {
            Log::warning('FaceEmbedding: cluster avg similarity failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get current face count for a cluster.
     */
    private function getClusterFaceCount(int $clusterId): int
    {
        $result = DB::connection('pgsql_rag')->selectOne('
            SELECT face_count FROM person_clusters WHERE id = ?
        ', [$clusterId]);

        return $result ? (int) $result->face_count : 0;
    }

    /**
     * Multi-centroid cluster matching - compare embedding against ALL faces in confirmed clusters
     *
     * This is the key enhancement for age-variant face recognition:
     * - A baby photo can match a cluster containing adult photos if ANY face in that cluster is similar
     * - Returns best match per cluster (not per face)
     *
     * @param  array  $embedding  128-dim face embedding
     * @param  float  $tolerance  Match tolerance
     * @param  int  $limit  Max clusters to return
     * @return array Matching clusters with best confidence per cluster
     */
    public function findMatchingClusters(array $embedding, float $tolerance = self::MATCH_TOLERANCE, int $limit = 10): array
    {
        $embeddingStr = PgVector::literal($embedding, self::VECTOR_LITERAL_PRECISION);

        try {
            // Multi-centroid: get BEST match per cluster (max confidence across all faces in cluster)
            $matches = DB::connection('pgsql_rag')->select("
                SELECT
                    pc.id as cluster_id,
                    pc.name as cluster_name,
                    pc.status,
                    pc.face_count,
                    pc.genealogy_person_id,
                    MAX(1 - (fe.embedding <=> ?::vector)) as confidence,
                    (SELECT fe2.crop_path FROM face_embeddings fe2
                     WHERE fe2.person_cluster_id = pc.id
                     ORDER BY fe2.embedding <=> ?::vector LIMIT 1) as best_match_crop
                FROM person_clusters pc
                INNER JOIN face_embeddings fe ON fe.person_cluster_id = pc.id
                WHERE pc.status IN ('confirmed', 'unreviewed')
                GROUP BY pc.id, pc.name, pc.status, pc.face_count, pc.genealogy_person_id
                HAVING MAX(1 - (fe.embedding <=> ?::vector)) >= ?
                ORDER BY MAX(1 - (fe.embedding <=> ?::vector)) DESC
                LIMIT ?
            ", [$embeddingStr, $embeddingStr, $embeddingStr, 1 - $tolerance, $embeddingStr, $limit]);

            return array_map(fn ($m) => (array) $m, $matches);
        } catch (\Exception $e) {
            Log::warning('FaceEmbedding: multi-centroid search failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Find unreviewed clusters that might be the same person as a confirmed cluster
     *
     * Called after confirming a cluster to suggest other clusters to merge.
     * Uses multi-centroid matching: compares ALL faces in unreviewed clusters against ALL faces in target.
     *
     * @param  int  $confirmedClusterId  The newly confirmed cluster
     * @param  float  $tolerance  Match tolerance
     * @param  int  $limit  Max suggestions
     * @return array Similar unreviewed clusters with confidence scores
     */
    public function suggestSimilarClusters(int $confirmedClusterId, float $tolerance = 0.5, int $limit = 20): array
    {
        try {
            // Get all embeddings from the confirmed cluster
            $clusterEmbeddings = DB::connection('pgsql_rag')->select('
                SELECT id, embedding::text as embedding_str
                FROM face_embeddings
                WHERE person_cluster_id = ?
            ', [$confirmedClusterId]);

            if (empty($clusterEmbeddings)) {
                return [];
            }

            // For each embedding in the confirmed cluster, find similar faces in OTHER unreviewed clusters
            // Then aggregate to find clusters with the most/best matches
            $similarities = [];

            foreach ($clusterEmbeddings as $ce) {
                $matches = DB::connection('pgsql_rag')->select("
                    SELECT
                        pc.id as cluster_id,
                        pc.name,
                        pc.face_count,
                        MAX(1 - (fe.embedding <=> ?::vector)) as max_confidence,
                        (SELECT fe2.crop_path FROM face_embeddings fe2
                         WHERE fe2.person_cluster_id = pc.id
                         ORDER BY fe2.embedding <=> ?::vector LIMIT 1) as sample_crop
                    FROM face_embeddings fe
                    INNER JOIN person_clusters pc ON pc.id = fe.person_cluster_id
                    WHERE pc.status = 'unreviewed'
                    AND pc.id != ?
                    GROUP BY pc.id, pc.name, pc.face_count
                    HAVING MAX(1 - (fe.embedding <=> ?::vector)) >= ?
                ", [$ce->embedding_str, $ce->embedding_str, $confirmedClusterId, $ce->embedding_str, 1 - $tolerance]);

                foreach ($matches as $m) {
                    $cid = $m->cluster_id;
                    if (! isset($similarities[$cid])) {
                        $similarities[$cid] = [
                            'cluster_id' => $cid,
                            'name' => $m->name,
                            'face_count' => $m->face_count,
                            'sample_crop' => $m->sample_crop,
                            'max_confidence' => 0,
                            'match_count' => 0,
                        ];
                    }
                    $similarities[$cid]['max_confidence'] = max($similarities[$cid]['max_confidence'], $m->max_confidence);
                    $similarities[$cid]['match_count']++;
                }
            }

            // Sort by confidence and return top results
            usort($similarities, fn ($a, $b) => $b['max_confidence'] <=> $a['max_confidence']);

            return array_slice(array_values($similarities), 0, $limit);
        } catch (\Exception $e) {
            Log::warning('FaceEmbedding: suggestSimilarClusters failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Auto-propagate: After confirming a cluster, find and auto-merge high-confidence matches.
     *
     * Uses centroid-to-centroid similarity (not single best face match) to prevent
     * transitive chaining where one outlier face in an unreviewed cluster matches
     * a confirmed cluster face, causing the entire unreviewed cluster to be absorbed.
     *
     * @param  int  $confirmedClusterId  The confirmed cluster
     * @return array Results of propagation
     */
    public function propagateClusterMatches(int $confirmedClusterId): array
    {
        $autoMerged = [];
        $suggested = [];

        try {
            // Ensure confirmed cluster has up-to-date centroid
            $this->updateClusterCentroid($confirmedClusterId);

            $confirmed = DB::connection('pgsql_rag')->selectOne('
                SELECT id, centroid::text as centroid_str, face_count
                FROM person_clusters WHERE id = ? AND centroid IS NOT NULL
            ', [$confirmedClusterId]);

            if (! $confirmed || ! $confirmed->centroid_str) {
                return ['auto_merged' => [], 'suggested' => [], 'total_found' => 0];
            }

            // Find unreviewed clusters with similar centroids
            $candidates = DB::connection('pgsql_rag')->select("
                SELECT pc.id as cluster_id, pc.name, pc.face_count,
                       1 - (pc.centroid <=> ?::vector) as centroid_similarity,
                       pc.merge_retry
                FROM person_clusters pc
                WHERE pc.status = 'unreviewed'
                AND pc.centroid IS NOT NULL
                AND pc.face_count > 0
                AND (pc.merge_retry IS NULL OR pc.merge_retry < 3)
                AND 1 - (pc.centroid <=> ?::vector) >= 0.70
                ORDER BY pc.centroid <=> ?::vector
                LIMIT 50
            ", [$confirmed->centroid_str, $confirmed->centroid_str, $confirmed->centroid_str]);

            foreach ($candidates as $c) {
                $similarity = (float) $c->centroid_similarity;

                // Skip merging into already-large clusters
                $targetSize = (int) $confirmed->face_count;
                if ($targetSize >= self::CLUSTER_SOFT_CAP * 5) {
                    // Very large confirmed cluster — require higher confidence
                    $threshold = self::HIGH_CONFIDENCE + self::CLUSTER_CAP_BOOST;
                } else {
                    $threshold = self::HIGH_CONFIDENCE;
                }

                if ($similarity >= $threshold) {
                    $this->mergeClusters($confirmedClusterId, [$c->cluster_id], 'propagation');
                    $autoMerged[] = [
                        'cluster_id' => $c->cluster_id,
                        'face_count' => $c->face_count,
                        'centroid_similarity' => $similarity,
                    ];
                    Log::info('FaceEmbedding: Auto-merged cluster via centroid', [
                        'target' => $confirmedClusterId,
                        'source' => $c->cluster_id,
                        'centroid_similarity' => $similarity,
                    ]);
                } elseif ($similarity >= 0.75) {
                    $suggested[] = [
                        'cluster_id' => $c->cluster_id,
                        'name' => $c->name,
                        'face_count' => $c->face_count,
                        'centroid_similarity' => $similarity,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('FaceEmbedding: propagateClusterMatches failed', ['error' => $e->getMessage()]);
        }

        return [
            'auto_merged' => $autoMerged,
            'suggested' => $suggested,
            'total_found' => count($autoMerged) + count($suggested),
        ];
    }

    /**
     * Store face embedding in pgvector database
     */
    private function storeFaceEmbedding(
        int $fileRegistryId,
        array $embedding,
        array $normalized,
        int $personClusterId,
        ?string $cropPath,
        ?int $matchedFaceId,
        float $confidence
    ): int {
        $embeddingStr = PgVector::literal($embedding, self::VECTOR_LITERAL_PRECISION);

        $result = DB::connection('pgsql_rag')->select('
            INSERT INTO face_embeddings (
                file_registry_id, person_cluster_id, embedding,
                region_x, region_y, region_w, region_h,
                crop_path, matched_face_id, match_confidence,
                embedding_model, created_at, updated_at
            ) VALUES (?, ?, ?::vector, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            RETURNING id
        ', [
            $fileRegistryId,
            $personClusterId,
            $embeddingStr,
            $normalized['x'],
            $normalized['y'],
            $normalized['w'],
            $normalized['h'],
            $cropPath,
            $matchedFaceId,
            $confidence,
            'dlib_face_recognition_resnet_model_v1',
        ]);

        $id = $result[0]->id;

        // Update cluster face count
        DB::connection('pgsql_rag')->update('
            UPDATE person_clusters
            SET face_count = face_count + 1, updated_at = NOW()
            WHERE id = ?
        ', [$personClusterId]);

        return $id;
    }

    /**
     * Create a new person cluster (unknown person)
     */
    private function createPersonCluster(?string $name = null): int
    {
        return DB::connection('pgsql_rag')->table('person_clusters')->insertGetId([
            'name' => $name,
            'status' => 'unreviewed',
            'face_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Verify face match using AI vision (via AIService)
     *
     * Uses AIService's full resilience pipeline:
     * - Ollama (with GPU lock) → Claude CLI fallback
     * - Circuit breaker protection
     * - Automatic retry with backoff
     *
     * @param  string  $cropPath1  Path to first face crop
     * @param  string  $cropPath2  Path to second face crop
     * @return bool True if AI confirms same person
     */
    private function verifyFaceMatchWithAI(string $cropPath1, string $cropPath2): bool
    {
        if (! file_exists($cropPath1) || ! file_exists($cropPath2)) {
            return false;
        }

        try {
            // Create composite image for single AI call (more efficient than multiple images)
            $compositeImage = $this->createCompositeFaceImage($cropPath1, $cropPath2);

            if (! $compositeImage) {
                Log::warning('FaceEmbedding: Failed to create composite image');

                return false;
            }

            $prompt = <<<'PROMPT'
This image shows two faces side by side for comparison. Determine if they show the SAME PERSON.

Consider:
- Facial structure (bone structure, face shape)
- Eye shape and spacing
- Nose shape
- Mouth shape
- Overall facial proportions

Ignore:
- Age differences (same person at different ages)
- Lighting/photo quality differences
- Facial expression differences
- Hairstyle/facial hair changes

Respond with ONLY valid JSON:
{"same_person": true, "confidence": 0.85} or {"same_person": false, "confidence": 0.90}
PROMPT;

            // Use AIService.processImage() - handles Ollama lock + Claude fallback automatically
            $result = $this->aiService->processImage(
                $compositeImage,
                $prompt,
                [
                    'max_tokens' => 100,
                    'suppressAlert' => true,  // Don't alert on failure, we have fallback logic
                ]
            );

            // Clean up temp composite
            @unlink($compositeImage);

            if ($result['success']) {
                $response = $result['response'] ?? '';
                if (preg_match('/"same_person"\s*:\s*(true|false)/i', $response, $matches)) {
                    $samePerson = strtolower($matches[1]) === 'true';

                    Log::info('FaceEmbedding: AI verification completed', [
                        'same_person' => $samePerson,
                        'provider' => $result['provider'] ?? 'unknown',
                    ]);

                    return $samePerson;
                }
            }

            Log::warning('FaceEmbedding: AI verification returned unexpected format', [
                'response' => substr($result['response'] ?? '', 0, 200),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::warning('FaceEmbedding: AI verification failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Create a side-by-side composite image of two faces
     * More efficient for AI comparison than sending multiple images
     */
    private function createCompositeFaceImage(string $path1, string $path2): ?string
    {
        try {
            $img1 = imagecreatefromstring(file_get_contents($path1));
            $img2 = imagecreatefromstring(file_get_contents($path2));

            if (! $img1 || ! $img2) {
                return null;
            }

            $w1 = imagesx($img1);
            $h1 = imagesy($img1);
            $w2 = imagesx($img2);
            $h2 = imagesy($img2);

            // Normalize heights
            $targetHeight = max($h1, $h2);
            if ($h1 !== $targetHeight) {
                $newW1 = (int) ($w1 * ($targetHeight / $h1));
                $resized1 = imagecreatetruecolor($newW1, $targetHeight);
                imagecopyresampled($resized1, $img1, 0, 0, 0, 0, $newW1, $targetHeight, $w1, $h1);
                imagedestroy($img1);
                $img1 = $resized1;
                $w1 = $newW1;
            }
            if ($h2 !== $targetHeight) {
                $newW2 = (int) ($w2 * ($targetHeight / $h2));
                $resized2 = imagecreatetruecolor($newW2, $targetHeight);
                imagecopyresampled($resized2, $img2, 0, 0, 0, 0, $newW2, $targetHeight, $w2, $h2);
                imagedestroy($img2);
                $img2 = $resized2;
                $w2 = $newW2;
            }

            // Create composite with small gap
            $gap = 10;
            $composite = imagecreatetruecolor($w1 + $w2 + $gap, $targetHeight);
            $white = imagecolorallocate($composite, 255, 255, 255);
            imagefill($composite, 0, 0, $white);

            imagecopy($composite, $img1, 0, 0, 0, 0, $w1, $targetHeight);
            imagecopy($composite, $img2, $w1 + $gap, 0, 0, 0, $w2, $targetHeight);

            imagedestroy($img1);
            imagedestroy($img2);

            // Save to temp file
            $tempPath = sys_get_temp_dir().'/face_composite_'.uniqid().'.jpg';
            imagejpeg($composite, $tempPath, 90);
            imagedestroy($composite);

            return $tempPath;
        } catch (\Exception $e) {
            Log::warning('FaceEmbedding: Failed to create composite', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get person clusters for review
     *
     * @param  string  $status  Filter by status (unreviewed, confirmed, merged)
     * @param  int  $minFaces  Minimum faces in cluster
     * @param  int  $limit  Max results
     * @return array Clusters with sample faces
     */
    public function getClustersForReview(string $status = 'unreviewed', int $minFaces = 2, int $limit = 50, int $offset = 0): array
    {
        try {
            $clusters = DB::connection('pgsql_rag')->select("
                SELECT
                    pc.id,
                    pc.name,
                    pc.status,
                    pc.face_count,
                    pc.genealogy_person_id,
                    pc.created_at,
                    (
                        SELECT json_agg(json_build_object(
                            'id', fe.id,
                            'crop_path', fe.crop_path,
                            'file_registry_id', fe.file_registry_id,
                            'match_confidence', fe.match_confidence
                        ))
                        FROM (
                            SELECT * FROM face_embeddings
                            WHERE person_cluster_id = pc.id
                            ORDER BY match_confidence DESC NULLS LAST
                            LIMIT 6
                        ) fe
                    ) as sample_faces
                FROM person_clusters pc
                WHERE pc.status = ?
                AND pc.face_count >= ?
                ORDER BY pc.face_count DESC
                LIMIT ? OFFSET ?
            ", [$status, $minFaces, $limit, $offset]);

            return array_map(function ($c) {
                $cluster = (array) $c;
                try {
                    $cluster['sample_faces'] = json_decode($cluster['sample_faces'] ?? '[]', true) ?? [];
                } catch (\Exception $e) {
                    Log::debug('FaceEmbeddingService: sample_faces JSON decode failed', ['error' => $e->getMessage()]);
                    $cluster['sample_faces'] = [];
                }

                return $cluster;
            }, $clusters);
        } catch (\Exception $e) {
            Log::error('FaceEmbedding: getClustersForReview failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Get face counts per genealogy person from confirmed/linked clusters.
     * Returns associative array: [genealogy_person_id => total_face_count]
     */
    public function getFaceCountsByGenealogyPerson(): array
    {
        try {
            $rows = DB::connection('pgsql_rag')->select("
                SELECT genealogy_person_id, SUM(face_count) as total_faces
                FROM person_clusters
                WHERE genealogy_person_id IS NOT NULL
                AND status IN ('confirmed', 'unreviewed')
                GROUP BY genealogy_person_id
            ");

            $counts = [];
            foreach ($rows as $row) {
                $counts[$row->genealogy_person_id] = (int) $row->total_faces;
            }

            return $counts;
        } catch (\Exception $e) {
            Log::error('FaceEmbedding: getFaceCountsByGenealogyPerson failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Confirm a cluster and optionally link to genealogy person
     *
     * IMPORTANT: This also writes face metadata back to the original image files
     * because media files are the source of truth.
     *
     * @param  int  $clusterId  Cluster ID
     * @param  string|null  $name  Person name
     * @param  int|null  $genealogyPersonId  Link to genealogy_persons
     * @param  bool  $writeToMedia  Write face regions to image files (default true)
     */
    public function confirmCluster(
        int $clusterId,
        ?string $name = null,
        ?int $genealogyPersonId = null,
        bool $writeToMedia = true
    ): bool {
        try {
            DB::connection('pgsql_rag')->update("
                UPDATE person_clusters
                SET status = 'confirmed',
                    name = COALESCE(?, name),
                    genealogy_person_id = COALESCE(?, genealogy_person_id),
                    merge_retry = 0,
                    merge_notes = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ", [$name, $genealogyPersonId, $clusterId]);

            // Write face regions back to image files (media is source of truth)
            if ($writeToMedia && $name) {
                $syncResult = $this->syncClusterToMediaFiles($clusterId);
                Log::info('FaceEmbedding: Synced confirmed cluster to media', [
                    'cluster_id' => $clusterId,
                    'name' => $name,
                    'sync_result' => $syncResult,
                ]);
            }

            // Compute centroid for newly confirmed cluster
            $this->updateClusterCentroid($clusterId);

            // Keep genealogy_person_media aligned via the shared bridge.
            // Drives off the persisted genealogy_person_id so callers that omit it
            // (or update the cluster separately) are still covered.
            $this->syncBridgeForCluster($clusterId);

            return true;
        } catch (\Exception $e) {
            Log::error('FaceEmbedding: confirmCluster failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Merge multiple clusters into one
     *
     * @param  int  $targetClusterId  Keep this cluster
     * @param  array  $sourceClusterIds  Merge these into target
     */
    public function mergeClusters(int $targetClusterId, array $sourceClusterIds, string $mergedBy = 'user'): bool
    {
        if (empty($sourceClusterIds)) {
            return false;
        }

        try {
            DB::connection('pgsql_rag')->beginTransaction();

            // Move all faces to target cluster
            $placeholders = implode(',', array_fill(0, count($sourceClusterIds), '?'));
            DB::connection('pgsql_rag')->update("
                UPDATE face_embeddings
                SET person_cluster_id = ?, updated_at = NOW()
                WHERE person_cluster_id IN ({$placeholders})
            ", array_merge([$targetClusterId], $sourceClusterIds));

            // Update target cluster face count
            DB::connection('pgsql_rag')->update('
                UPDATE person_clusters
                SET face_count = (SELECT COUNT(*) FROM face_embeddings WHERE person_cluster_id = ?),
                    updated_at = NOW()
                WHERE id = ?
            ', [$targetClusterId, $targetClusterId]);

            // Record merge history for each source (enables undo)
            foreach ($sourceClusterIds as $srcId) {
                $srcCount = DB::connection('pgsql_rag')->selectOne('
                    SELECT face_count FROM person_clusters WHERE id = ?
                ', [$srcId]);
                $this->recordMergeHistory((int) $srcId, $targetClusterId, (int) ($srcCount->face_count ?? 0), $mergedBy);
            }

            // Mark source clusters as merged
            DB::connection('pgsql_rag')->update("
                UPDATE person_clusters
                SET status = 'merged', merged_into_id = ?, face_count = 0, updated_at = NOW()
                WHERE id IN ({$placeholders})
            ", array_merge([$targetClusterId], $sourceClusterIds));

            DB::connection('pgsql_rag')->commit();

            // Re-route mysql face rows so file_registry_faces.cluster_id stays aligned
            // with face_embeddings.person_cluster_id. Without this the bridge can't
            // find the merged-in faces (they'd still point at the now-merged source).
            try {
                DB::update(
                    "UPDATE file_registry_faces SET cluster_id = ?, updated_at = NOW() WHERE cluster_id IN ({$placeholders})",
                    array_merge([$targetClusterId], $sourceClusterIds)
                );
            } catch (\Throwable $e) {
                Log::warning('FaceEmbedding: failed to realign merged MySQL face rows', [
                    'target_cluster_id' => $targetClusterId,
                    'source_cluster_ids' => $sourceClusterIds,
                    'error' => $e->getMessage(),
                ]);
            }

            // Recompute centroid for target cluster (membership changed)
            $this->updateClusterCentroid($targetClusterId);

            // Keep genealogy_person_media aligned for the (possibly larger) target.
            $this->syncBridgeForCluster($targetClusterId);

            return true;
        } catch (\Exception $e) {
            DB::connection('pgsql_rag')->rollBack();
            Log::error('FaceEmbedding: mergeClusters failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Ignore a cluster (mark as not needing identification)
     *
     * Use for background people, crowds, or faces that don't need naming.
     * Ignored clusters won't appear in review queue or be matched during propagation.
     *
     * @param  int  $clusterId  Cluster ID to ignore
     * @param  string|null  $reason  Optional reason for ignoring
     */
    public function ignoreCluster(int $clusterId, ?string $reason = null): bool
    {
        try {
            $notes = $reason ? "Ignored: {$reason}" : 'Ignored by user';

            DB::connection('pgsql_rag')->update("
                UPDATE person_clusters
                SET status = 'ignored',
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ", [$notes, $clusterId]);

            Log::info('FaceEmbedding: Cluster ignored', [
                'cluster_id' => $clusterId,
                'reason' => $reason,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('FaceEmbedding: ignoreCluster failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Route the cluster through FaceLinkBridgeService so genealogy_person_media
     * stays aligned. No-op when the cluster has no genealogy_person_id. Failures
     * are logged but never fail the cluster operation.
     */
    private function syncBridgeForCluster(int $clusterId): void
    {
        try {
            $cluster = DB::connection('pgsql_rag')->selectOne(
                'SELECT genealogy_person_id FROM person_clusters WHERE id = ?',
                [$clusterId]
            );
            $personId = $cluster->genealogy_person_id ?? null;
            if (! $personId) {
                return;
            }

            $this->linkClusterToGenealogy($clusterId, (int) $personId);
            app(FaceLinkBridgeService::class)->syncClusterLinks($clusterId, (int) $personId);
        } catch (\Throwable $e) {
            Log::warning('FaceEmbedding: bridge sync failed', [
                'cluster_id' => $clusterId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Link cluster faces to genealogy person in MySQL
     */
    private function linkClusterToGenealogy(int $clusterId, int $genealogyPersonId): void
    {
        // Get all face embeddings in this cluster
        $faces = DB::connection('pgsql_rag')->select('
            SELECT id, file_registry_id, file_registry_face_id, region_x, region_y, region_w, region_h
            FROM face_embeddings
            WHERE person_cluster_id = ?
        ', [$clusterId]);

        // Get person name from genealogy
        $person = DB::selectOne('SELECT given_name, surname FROM genealogy_persons WHERE id = ?', [$genealogyPersonId]);
        $personName = $person ? trim($person->given_name.' '.$person->surname) : null;

        foreach ($faces as $face) {
            // Check if face already exists in file_registry_faces
            $existing = null;

            if (! empty($face->file_registry_face_id)) {
                $existing = DB::selectOne(
                    'SELECT id FROM file_registry_faces WHERE id = ?',
                    [$face->file_registry_face_id]
                );
            }

            if (! $existing) {
                $existing = DB::selectOne('
                    SELECT id FROM file_registry_faces
                    WHERE file_registry_id = ?
                    AND ABS(region_x - ?) < 0.01
                    AND ABS(region_y - ?) < 0.01
                ', [$face->file_registry_id, $face->region_x, $face->region_y]);
            }

            if ($existing) {
                // Update existing
                DB::update("
                    UPDATE file_registry_faces
                    SET genealogy_person_id = ?,
                        person_name = COALESCE(NULLIF(person_name, ''), ?),
                        cluster_id = ?,
                        verified = 1,
                        updated_at = NOW()
                    WHERE id = ?
                ", [$genealogyPersonId, $personName, $clusterId, $existing->id]);
            } else {
                // Insert new
                DB::insert("
                    INSERT INTO file_registry_faces
                    (file_registry_id, person_name, genealogy_person_id, region_x, region_y, region_w, region_h, source, verified, cluster_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'ai_detection', 1, ?, NOW(), NOW())
                ", [
                    $face->file_registry_id,
                    $personName,
                    $genealogyPersonId,
                    $face->region_x,
                    $face->region_y,
                    $face->region_w,
                    $face->region_h,
                    $clusterId,
                ]);
            }
        }
    }

    /**
     * Write verified face regions back to image XMP/EXIF metadata
     *
     * IMPORTANT: Media files are the source of truth. When faces are confirmed,
     * we write MWG-rs face regions back to the image file.
     *
     * @param  string  $imagePath  Path to image file
     * @param  array  $faces  Array of faces with regions and names
     * @return array Result of metadata write operation
     */
    public function writeFaceMetadataToFile(string $imagePath, array $faces): array
    {
        if (! file_exists($imagePath) || empty($faces)) {
            return ['success' => false, 'error' => 'Invalid input'];
        }

        try {
            // First clear existing regions, then add new ones
            Process::timeout(30)->run([
                'exiftool',
                '-overwrite_original',
                '-XMP-mwg-rs:RegionInfo=',
                $imagePath,
            ]);

            // Add regions using exiftool's struct notation
            $exiftoolStruct = [];
            foreach ($faces as $i => $face) {
                $name = $face['name'] ?? 'Unknown';
                $x = $face['region_x'] ?? 0;
                $y = $face['region_y'] ?? 0;
                $w = $face['region_w'] ?? 0;
                $h = $face['region_h'] ?? 0;

                $centerX = $x + ($w / 2);
                $centerY = $y + ($h / 2);

                $exiftoolStruct[] = sprintf(
                    '{Area={X=%f,Y=%f,W=%f,H=%f,Unit=normalized},Name=%s,Type=Face}',
                    $centerX, $centerY, $w, $h, $name
                );
            }

            $structArg = implode(',', $exiftoolStruct);
            $output = Process::timeout(30)->run([
                'exiftool',
                '-overwrite_original',
                "-XMP-mwg-rs:RegionInfo={AppliedToDimensions={W=0,H=0,Unit=pixel},RegionList=[{$structArg}]}",
                $imagePath,
            ])->output();
            $success = strpos($output, '1 image files updated') !== false;

            Log::info('FaceEmbedding: Wrote face metadata to file', [
                'path' => $imagePath,
                'face_count' => count($faces),
                'success' => $success,
            ]);

            return [
                'success' => $success,
                'faces_written' => count($faces),
                'output' => $output,
            ];
        } catch (\Exception $e) {
            Log::error('FaceEmbedding: Failed to write face metadata', [
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Sync confirmed cluster faces back to image files
     *
     * Called when a cluster is confirmed with a name. Updates all
     * associated image files with the face region metadata.
     *
     * @param  int  $clusterId  The confirmed cluster
     * @return array Sync results
     */
    public function syncClusterToMediaFiles(int $clusterId): array
    {
        try {
            // Get cluster info
            $cluster = DB::connection('pgsql_rag')->selectOne("
                SELECT id, name, genealogy_person_id
                FROM person_clusters
                WHERE id = ? AND status = 'confirmed'
            ", [$clusterId]);

            if (! $cluster || ! $cluster->name) {
                return ['success' => false, 'error' => 'Cluster not confirmed or has no name'];
            }

            // Get distinct files affected by this cluster
            $affectedFiles = DB::connection('pgsql_rag')->select('
                SELECT DISTINCT file_registry_id
                FROM face_embeddings
                WHERE person_cluster_id = ?
            ', [$clusterId]);

            $updated = 0;
            $errors = 0;

            foreach ($affectedFiles as $af) {
                $file = DB::selectOne('
                    SELECT current_path FROM file_registry WHERE id = ?
                ', [$af->file_registry_id]);

                if (! $file || ! file_exists($file->current_path)) {
                    $errors++;

                    continue;
                }

                // Gather ALL confirmed faces for this file across ALL clusters
                // This prevents clobbering faces from other confirmed clusters
                $allConfirmedFaces = DB::connection('pgsql_rag')->select("
                    SELECT fe.region_x, fe.region_y, fe.region_w, fe.region_h,
                           pc.name AS cluster_name
                    FROM face_embeddings fe
                    JOIN person_clusters pc ON pc.id = fe.person_cluster_id
                    WHERE fe.file_registry_id = ? AND pc.status = 'confirmed' AND pc.name IS NOT NULL
                ", [$af->file_registry_id]);

                if (empty($allConfirmedFaces)) {
                    continue;
                }

                $facesWithNames = array_map(function ($f) {
                    return [
                        'name' => $f->cluster_name,
                        'region_x' => $f->region_x,
                        'region_y' => $f->region_y,
                        'region_w' => $f->region_w,
                        'region_h' => $f->region_h,
                    ];
                }, $allConfirmedFaces);

                $result = $this->writeFaceMetadataToFile($file->current_path, $facesWithNames);
                if ($result['success']) {
                    $updated++;
                } else {
                    $errors++;
                }
            }

            Log::info('FaceEmbedding: Synced cluster to media files', [
                'cluster_id' => $clusterId,
                'name' => $cluster->name,
                'files_updated' => $updated,
                'errors' => $errors,
            ]);

            return [
                'success' => true,
                'cluster_name' => $cluster->name,
                'files_updated' => $updated,
                'errors' => $errors,
            ];
        } catch (\Exception $e) {
            Log::error('FaceEmbedding: Cluster sync failed', [
                'cluster_id' => $clusterId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get statistics about face embeddings
     */
    public function getStats(): array
    {
        try {
            $stats = DB::connection('pgsql_rag')->selectOne('
                SELECT
                    COUNT(*) as total_faces,
                    COUNT(DISTINCT person_cluster_id) as total_clusters,
                    COUNT(DISTINCT file_registry_id) as files_with_faces
                FROM face_embeddings
            ');

            $clusterStats = DB::connection('pgsql_rag')->selectOne("
                SELECT
                    COUNT(*) FILTER (WHERE status = 'unreviewed') as unreviewed,
                    COUNT(*) FILTER (WHERE status = 'confirmed') as confirmed,
                    COUNT(*) FILTER (WHERE status = 'merged') as merged,
                    COUNT(*) FILTER (WHERE status = 'ignored') as ignored,
                    COUNT(*) FILTER (WHERE genealogy_person_id IS NOT NULL) as linked_to_genealogy
                FROM person_clusters
            ");

            return [
                'total_faces' => (int) ($stats->total_faces ?? 0),
                'total_clusters' => (int) ($stats->total_clusters ?? 0),
                'files_with_faces' => (int) ($stats->files_with_faces ?? 0),
                'clusters_unreviewed' => (int) ($clusterStats->unreviewed ?? 0),
                'clusters_confirmed' => (int) ($clusterStats->confirmed ?? 0),
                'clusters_merged' => (int) ($clusterStats->merged ?? 0),
                'clusters_ignored' => (int) ($clusterStats->ignored ?? 0),
                'clusters_linked' => (int) ($clusterStats->linked_to_genealogy ?? 0),
            ];
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'total_faces' => 0,
                'total_clusters' => 0,
            ];
        }
    }

    /**
     * Revert a cluster to 'unreviewed' status
     *
     * Used by the undo stack to reverse confirm/ignore actions.
     * Restores the cluster to its previous state.
     *
     * @param  int  $clusterId  Cluster ID to revert
     * @param  string  $previousStatus  Status to revert to (default: unreviewed)
     * @param  string|null  $previousName  Previous name to restore
     * @param  int|null  $previousGenealogyPersonId  Previous genealogy link to restore
     */
    public function revertCluster(
        int $clusterId,
        string $previousStatus = 'unreviewed',
        ?string $previousName = null,
        ?int $previousGenealogyPersonId = null
    ): bool {
        try {
            DB::connection('pgsql_rag')->update('
                UPDATE person_clusters
                SET status = ?,
                    name = ?,
                    genealogy_person_id = ?,
                    notes = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ', [$previousStatus, $previousName, $previousGenealogyPersonId, $clusterId]);

            Log::info('FaceEmbedding: Cluster reverted', [
                'cluster_id' => $clusterId,
                'to_status' => $previousStatus,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('FaceEmbedding: revertCluster failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Unmerge: reverse a merge by moving faces back to a restored source cluster
     *
     * @param  int  $sourceClusterId  The cluster that was merged away (will be restored)
     * @param  int  $targetClusterId  The cluster faces were merged into
     */
    public function unmergeCluster(int $sourceClusterId, int $targetClusterId): bool
    {
        try {
            DB::connection('pgsql_rag')->beginTransaction();

            // Restore source cluster status
            DB::connection('pgsql_rag')->update("
                UPDATE person_clusters
                SET status = 'unreviewed', merged_into_id = NULL, updated_at = NOW()
                WHERE id = ?
            ", [$sourceClusterId]);

            // Move faces back: faces that were originally in the source cluster
            // We identify them by checking cluster_merge_history or by face creation time
            // Since we don't track per-face origin, restore from merge history
            $history = DB::connection('pgsql_rag')->selectOne('
                SELECT faces_moved FROM cluster_merge_history
                WHERE source_cluster_id = ? AND target_cluster_id = ?
                ORDER BY merged_at DESC LIMIT 1
            ', [$sourceClusterId, $targetClusterId]);

            if ($history && $history->faces_moved > 0) {
                // Move the most recently updated faces (the ones that were merged in)
                DB::connection('pgsql_rag')->update('
                    UPDATE face_embeddings
                    SET person_cluster_id = ?, updated_at = NOW()
                    WHERE id IN (
                        SELECT id FROM face_embeddings
                        WHERE person_cluster_id = ?
                        ORDER BY updated_at DESC
                        LIMIT ?
                    )
                ', [$sourceClusterId, $targetClusterId, $history->faces_moved]);
            }

            // Recount both clusters
            foreach ([$sourceClusterId, $targetClusterId] as $cid) {
                DB::connection('pgsql_rag')->update('
                    UPDATE person_clusters
                    SET face_count = (SELECT COUNT(*) FROM face_embeddings WHERE person_cluster_id = ?),
                        updated_at = NOW()
                    WHERE id = ?
                ', [$cid, $cid]);
            }

            DB::connection('pgsql_rag')->commit();

            Log::info('FaceEmbedding: Unmerge completed', [
                'source' => $sourceClusterId,
                'target' => $targetClusterId,
            ]);

            return true;
        } catch (\Exception $e) {
            DB::connection('pgsql_rag')->rollBack();
            Log::error('FaceEmbedding: unmergeCluster failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Split faces out of a cluster into a new cluster
     *
     * @param  int  $sourceClusterId  Source cluster to split from
     * @param  array  $faceIds  Face IDs to move to the new cluster
     * @return array New cluster info or error
     */
    public function splitCluster(int $sourceClusterId, array $faceIds): array
    {
        if (empty($faceIds)) {
            return ['success' => false, 'error' => 'No face IDs provided'];
        }

        try {
            DB::connection('pgsql_rag')->beginTransaction();

            // Verify all faces belong to the source cluster
            $placeholders = implode(',', array_fill(0, count($faceIds), '?'));
            $validCount = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as cnt FROM face_embeddings
                WHERE id IN ({$placeholders}) AND person_cluster_id = ?
            ", array_merge($faceIds, [$sourceClusterId]));

            if ((int) $validCount->cnt !== count($faceIds)) {
                DB::connection('pgsql_rag')->rollBack();

                return ['success' => false, 'error' => 'Some faces do not belong to this cluster'];
            }

            // Create new cluster
            $newClusterId = $this->createPersonCluster();

            // Move faces to new cluster
            DB::connection('pgsql_rag')->update("
                UPDATE face_embeddings
                SET person_cluster_id = ?, updated_at = NOW()
                WHERE id IN ({$placeholders})
            ", array_merge([$newClusterId], $faceIds));

            // Recount both clusters
            foreach ([$sourceClusterId, $newClusterId] as $cid) {
                DB::connection('pgsql_rag')->update('
                    UPDATE person_clusters
                    SET face_count = (SELECT COUNT(*) FROM face_embeddings WHERE person_cluster_id = ?),
                        updated_at = NOW()
                    WHERE id = ?
                ', [$cid, $cid]);
            }

            DB::connection('pgsql_rag')->commit();

            // Recompute centroids for both clusters
            $this->updateClusterCentroid($sourceClusterId);
            $this->updateClusterCentroid($newClusterId);

            Log::info('FaceEmbedding: Cluster split', [
                'source' => $sourceClusterId,
                'new_cluster' => $newClusterId,
                'faces_moved' => count($faceIds),
            ]);

            // Re-evaluate split orphan: check if the new cluster matches a confirmed person
            $suggestion = $this->evaluateSplitOrphan($newClusterId);

            return [
                'success' => true,
                'new_cluster_id' => $newClusterId,
                'faces_moved' => count($faceIds),
                'suggestion' => $suggestion,
            ];
        } catch (\Exception $e) {
            DB::connection('pgsql_rag')->rollBack();
            Log::error('FaceEmbedding: splitCluster failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Evaluate a split-off cluster against confirmed clusters.
     * Returns a suggestion if the orphan matches a confirmed person at high confidence.
     * Does NOT auto-merge — the user split for a reason.
     */
    private function evaluateSplitOrphan(int $clusterId): ?array
    {
        try {
            // Get the centroid of the new cluster
            $faces = DB::connection('pgsql_rag')->select('
                SELECT embedding::text as embedding_str
                FROM face_embeddings
                WHERE person_cluster_id = ?
            ', [$clusterId]);

            if (empty($faces)) {
                return null;
            }

            // Compute mean embedding
            $sum = array_fill(0, 128, 0.0);
            foreach ($faces as $f) {
                $vec = array_map('floatval', explode(',', trim($f->embedding_str, '[]')));
                if (count($vec) === 128) {
                    for ($i = 0; $i < 128; $i++) {
                        $sum[$i] += $vec[$i];
                    }
                }
            }
            $count = count($faces);
            $mean = array_map(fn ($v) => $v / $count, $sum);

            // L2-normalize
            $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $mean)));
            if ($norm > 0) {
                $mean = array_map(fn ($v) => $v / $norm, $mean);
            }

            // Find best matching confirmed cluster
            $embStr = PgVector::literal($mean, self::VECTOR_LITERAL_PRECISION);
            $match = DB::connection('pgsql_rag')->selectOne("
                SELECT pc.id, pc.name, pc.face_count,
                       MAX(1 - (fe.embedding <=> ?::vector)) as confidence
                FROM person_clusters pc
                INNER JOIN face_embeddings fe ON fe.person_cluster_id = pc.id
                WHERE pc.status = 'confirmed' AND pc.id != ?
                GROUP BY pc.id, pc.name, pc.face_count
                HAVING MAX(1 - (fe.embedding <=> ?::vector)) >= ?
                ORDER BY MAX(1 - (fe.embedding <=> ?::vector)) DESC
                LIMIT 1
            ", [$embStr, $clusterId, $embStr, self::HIGH_CONFIDENCE, $embStr]);

            if ($match) {
                Log::info('FaceEmbedding: Split orphan matches confirmed cluster', [
                    'orphan_cluster' => $clusterId,
                    'suggested_cluster' => $match->id,
                    'suggested_name' => $match->name,
                    'confidence' => $match->confidence,
                ]);

                return [
                    'cluster_id' => $match->id,
                    'name' => $match->name,
                    'confidence' => (float) $match->confidence,
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('FaceEmbedding: evaluateSplitOrphan failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get all faces in a cluster (for split UI)
     *
     * @return array Face details with crop info
     */
    public function getClusterFaces(int $clusterId): array
    {
        try {
            $faces = DB::connection('pgsql_rag')->select('
                SELECT fe.id, fe.file_registry_id, fe.crop_path,
                       fe.region_x, fe.region_y, fe.region_w, fe.region_h,
                       fe.match_confidence, fe.created_at
                FROM face_embeddings fe
                WHERE fe.person_cluster_id = ?
                ORDER BY fe.match_confidence DESC NULLS LAST
            ', [$clusterId]);

            return array_map(fn ($f) => (array) $f, $faces);
        } catch (\Exception $e) {
            Log::error('FaceEmbedding: getClusterFaces failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Record a merge in cluster_merge_history for undo support
     */
    public function recordMergeHistory(int $sourceClusterId, int $targetClusterId, int $facesMoved, string $mergedBy = 'user'): void
    {
        try {
            DB::connection('pgsql_rag')->insert('
                INSERT INTO cluster_merge_history (source_cluster_id, target_cluster_id, faces_moved, merged_by, merged_at)
                VALUES (?, ?, ?, ?, NOW())
            ', [$sourceClusterId, $targetClusterId, $facesMoved, $mergedBy]);
        } catch (\Exception $e) {
            Log::warning('FaceEmbedding: recordMergeHistory failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Pre-cluster named faces: create one confirmed person_cluster per unique person_name.
     * Sets cluster_id on MySQL file_registry_faces + person_cluster_id on pgvector face_embeddings.
     *
     * @return array Stats about clusters created
     */
    public function clusterNamedFaces(): array
    {
        $created = 0;
        $linked = 0;
        $errors = 0;

        // Get unique named people from MySQL
        $people = DB::select("
            SELECT person_name, genealogy_person_id,
                   COUNT(*) as face_count
            FROM file_registry_faces
            WHERE person_name != '' AND hidden = 0
            GROUP BY person_name, genealogy_person_id
            ORDER BY face_count DESC
        ");

        foreach ($people as $person) {
            try {
                // Check if cluster already exists for this name
                $existing = DB::connection('pgsql_rag')->selectOne("
                    SELECT id FROM person_clusters
                    WHERE name = ? AND status = 'confirmed'
                ", [$person->person_name]);

                if ($existing) {
                    $clusterId = $existing->id;
                } else {
                    // Create confirmed cluster
                    $clusterId = DB::connection('pgsql_rag')->table('person_clusters')->insertGetId([
                        'name' => $person->person_name,
                        'status' => 'confirmed',
                        'face_count' => 0,
                        'genealogy_person_id' => $person->genealogy_person_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $created++;
                }

                // Set cluster_id on MySQL faces
                DB::update('
                    UPDATE file_registry_faces
                    SET cluster_id = ?
                    WHERE person_name = ? AND hidden = 0
                ', [$clusterId, $person->person_name]);

                // Set person_cluster_id on pgvector embeddings (via file_registry_face_id link)
                $faceIds = DB::select('
                    SELECT id FROM file_registry_faces
                    WHERE person_name = ? AND hidden = 0
                ', [$person->person_name]);

                if (! empty($faceIds)) {
                    $ids = array_map(fn ($f) => $f->id, $faceIds);
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));

                    DB::connection('pgsql_rag')->update("
                        UPDATE face_embeddings
                        SET person_cluster_id = ?, updated_at = NOW()
                        WHERE file_registry_face_id IN ({$placeholders})
                    ", array_merge([$clusterId], $ids));

                    $linked += count($ids);
                }

                // Update cluster face count
                DB::connection('pgsql_rag')->update('
                    UPDATE person_clusters
                    SET face_count = (SELECT COUNT(*) FROM face_embeddings WHERE person_cluster_id = ?),
                        updated_at = NOW()
                    WHERE id = ?
                ', [$clusterId, $clusterId]);

                // Set representative face (highest quality)
                $rep = DB::connection('pgsql_rag')->selectOne('
                    SELECT id FROM face_embeddings
                    WHERE person_cluster_id = ?
                    ORDER BY quality_score DESC NULLS LAST, match_confidence DESC NULLS LAST
                    LIMIT 1
                ', [$clusterId]);

                if ($rep) {
                    DB::connection('pgsql_rag')->update('
                        UPDATE person_clusters SET representative_face_id = ? WHERE id = ?
                    ', [$rep->id, $clusterId]);
                }
            } catch (\Exception $e) {
                $errors++;
                Log::warning('FaceEmbedding: clusterNamedFaces error', [
                    'person' => $person->person_name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'people_found' => count($people),
            'clusters_created' => $created,
            'faces_linked' => $linked,
            'errors' => $errors,
        ];
    }

    /**
     * Import HDBSCAN clustering results from Python.
     * Creates person_clusters for unnamed faces and sets cluster_id on MySQL.
     *
     * @param  array  $clusterAssignments  {face_embedding_id: cluster_label, ...}
     * @return array Import stats
     */
    public function importClusterAssignments(array $clusterAssignments): array
    {
        $clustersCreated = 0;
        $facesAssigned = 0;
        $errors = 0;

        // Group by cluster label
        $grouped = [];
        foreach ($clusterAssignments as $feId => $label) {
            $grouped[$label][] = (int) $feId;
        }

        foreach ($grouped as $label => $faceEmbeddingIds) {
            try {
                // Create cluster (unnamed, unreviewed)
                $clusterId = DB::connection('pgsql_rag')->table('person_clusters')->insertGetId([
                    'name' => null,
                    'status' => 'unreviewed',
                    'face_count' => count($faceEmbeddingIds),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $clustersCreated++;

                // Assign face embeddings to cluster
                $placeholders = implode(',', array_fill(0, count($faceEmbeddingIds), '?'));
                DB::connection('pgsql_rag')->update("
                    UPDATE face_embeddings
                    SET person_cluster_id = ?, updated_at = NOW()
                    WHERE id IN ({$placeholders})
                ", array_merge([$clusterId], $faceEmbeddingIds));

                // Set representative (pick most central embedding)
                $rep = DB::connection('pgsql_rag')->selectOne('
                    SELECT id FROM face_embeddings
                    WHERE person_cluster_id = ?
                    ORDER BY quality_score DESC NULLS LAST
                    LIMIT 1
                ', [$clusterId]);

                if ($rep) {
                    DB::connection('pgsql_rag')->update('
                        UPDATE person_clusters SET representative_face_id = ? WHERE id = ?
                    ', [$rep->id, $clusterId]);
                }

                // Set cluster_id on MySQL faces (via file_registry_face_id link)
                $feRows = DB::connection('pgsql_rag')->select('
                    SELECT file_registry_face_id FROM face_embeddings
                    WHERE person_cluster_id = ? AND file_registry_face_id IS NOT NULL
                ', [$clusterId]);

                if (! empty($feRows)) {
                    $mysqlIds = array_map(fn ($r) => $r->file_registry_face_id, $feRows);
                    $ph = implode(',', array_fill(0, count($mysqlIds), '?'));
                    DB::update("
                        UPDATE file_registry_faces SET cluster_id = ? WHERE id IN ({$ph})
                    ", array_merge([$clusterId], $mysqlIds));
                    $facesAssigned += count($mysqlIds);
                }
            } catch (\Exception $e) {
                $errors++;
                Log::warning('FaceEmbedding: importClusterAssignments error', [
                    'label' => $label,
                    'count' => count($faceEmbeddingIds),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'clusters_created' => $clustersCreated,
            'faces_assigned' => $facesAssigned,
            'errors' => $errors,
        ];
    }

    /**
     * Assign a single face to the best matching cluster, or create a singleton.
     * Used for inline clustering during face detection pipeline.
     *
     * @param  int  $faceEmbeddingId  pgvector face_embeddings.id
     * @param  array  $embedding  128-dim vector
     * @return array Assignment result
     */
    public function assignToCluster(int $faceEmbeddingId, array $embedding): array
    {
        $matches = $this->findMatchingClustersCentroid($embedding, self::MATCH_TOLERANCE, 5);

        foreach ($matches as $match) {
            $clusterAvg = $this->getClusterAverageSimilarity($embedding, $match['cluster_id']);
            $effectiveConf = $clusterAvg ?? $match['confidence'];

            $clusterSize = $match['face_count'] ?? 0;
            $threshold = self::HIGH_CONFIDENCE;
            if ($clusterSize >= self::CLUSTER_SOFT_CAP) {
                $threshold += self::CLUSTER_CAP_BOOST;
            }

            if ($effectiveConf >= $threshold) {
                // Assign to existing cluster
                DB::connection('pgsql_rag')->update('
                    UPDATE face_embeddings
                    SET person_cluster_id = ?, match_confidence = ?, updated_at = NOW()
                    WHERE id = ?
                ', [$match['cluster_id'], $effectiveConf, $faceEmbeddingId]);

                DB::connection('pgsql_rag')->update('
                    UPDATE person_clusters
                    SET face_count = face_count + 1, updated_at = NOW()
                    WHERE id = ?
                ', [$match['cluster_id']]);

                // Update MySQL cluster_id via file_registry_face_id link
                $fe = DB::connection('pgsql_rag')->selectOne('
                    SELECT file_registry_face_id FROM face_embeddings WHERE id = ?
                ', [$faceEmbeddingId]);

                if ($fe && $fe->file_registry_face_id) {
                    DB::update('
                        UPDATE file_registry_faces SET cluster_id = ? WHERE id = ?
                    ', [$match['cluster_id'], $fe->file_registry_face_id]);
                }

                return [
                    'action' => 'assigned',
                    'cluster_id' => $match['cluster_id'],
                    'confidence' => $effectiveConf,
                ];
            }
        }

        // No good match — create singleton cluster
        $clusterId = $this->createPersonCluster();

        DB::connection('pgsql_rag')->update('
            UPDATE face_embeddings
            SET person_cluster_id = ?, updated_at = NOW()
            WHERE id = ?
        ', [$clusterId, $faceEmbeddingId]);

        DB::connection('pgsql_rag')->update('
            UPDATE person_clusters SET face_count = 1 WHERE id = ?
        ', [$clusterId]);

        $fe = DB::connection('pgsql_rag')->selectOne('
            SELECT file_registry_face_id FROM face_embeddings WHERE id = ?
        ', [$faceEmbeddingId]);

        if ($fe && $fe->file_registry_face_id) {
            DB::update('
                UPDATE file_registry_faces SET cluster_id = ? WHERE id = ?
            ', [$clusterId, $fe->file_registry_face_id]);
        }

        return [
            'action' => 'singleton',
            'cluster_id' => $clusterId,
        ];
    }

    /**
     * Get clusters with stats and sample faces for the unified UI.
     * Queries MySQL for cluster membership, pgvector for cluster metadata.
     *
     * @param  string  $filter  'all', 'unidentified', 'identified', 'hidden', 'mixed'
     * @param  string  $sort  'size_desc', 'size_asc', 'recent', 'name'
     * @return array Clusters with face samples
     */
    public function getUnifiedClusters(
        string $filter = 'all',
        string $sort = 'size_desc',
        int $limit = 50,
        int $offset = 0,
        int $minFaces = 1
    ): array {
        $where = "WHERE pc.status NOT IN ('merged')";
        $params = [];
        $mixedClusterSummaries = [];

        switch ($filter) {
            case 'unidentified':
                $where .= " AND pc.status = 'unreviewed' AND pc.name IS NULL";
                break;
            case 'identified':
                $where .= " AND pc.status = 'confirmed'";
                break;
            case 'hidden':
                $where .= " AND pc.status = 'ignored'";
                break;
            case 'mixed':
                $mixedClusterSummaries = $this->getMixedNameClusterSummaries();
                $mixedClusterIds = array_keys($mixedClusterSummaries);

                if ($mixedClusterIds === []) {
                    $where .= ' AND 1 = 0';
                    break;
                }

                $where .= ' AND pc.id IN ('.implode(',', array_fill(0, count($mixedClusterIds), '?')).')';
                array_push($params, ...$mixedClusterIds);
                break;
        }

        if ($minFaces > 1) {
            $where .= ' AND pc.face_count >= ?';
            $params[] = $minFaces;
        }

        $orderBy = match ($sort) {
            'size_asc' => 'pc.face_count ASC',
            'recent' => 'pc.updated_at DESC',
            'name' => 'pc.name ASC NULLS LAST, pc.face_count DESC',
            default => 'pc.face_count DESC',
        };

        $params[] = $limit;
        $params[] = $offset;

        try {
            $clusters = DB::connection('pgsql_rag')->select("
                SELECT
                    pc.id,
                    pc.name,
                    pc.status,
                    pc.face_count,
                    pc.genealogy_person_id,
                    pc.representative_face_id,
                    pc.created_at,
                    pc.updated_at,
                    (
                        SELECT json_agg(sub)
                        FROM (
                            SELECT fe.id, fe.file_registry_id, fe.file_registry_face_id,
                                   fe.crop_path, fe.match_confidence, fe.quality_score
                            FROM face_embeddings fe
                            WHERE fe.person_cluster_id = pc.id
                            ORDER BY fe.quality_score DESC NULLS LAST, fe.match_confidence DESC NULLS LAST
                            LIMIT 6
                        ) sub
                    ) as sample_faces
                FROM person_clusters pc
                {$where}
                ORDER BY {$orderBy}
                LIMIT ? OFFSET ?
            ", $params);

            $total = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as cnt FROM person_clusters pc {$where}
            ", array_slice($params, 0, -2))->cnt;

            return [
                'clusters' => array_map(function ($c) use ($mixedClusterSummaries) {
                    $cluster = (array) $c;
                    $cluster['sample_faces'] = json_decode($cluster['sample_faces'] ?? '[]', true) ?? [];

                    $clusterId = (int) $cluster['id'];
                    if (isset($mixedClusterSummaries[$clusterId])) {
                        $cluster['mixed_names'] = $mixedClusterSummaries[$clusterId]['names'];
                        $cluster['mixed_name_count'] = $mixedClusterSummaries[$clusterId]['distinct_names'];
                        $cluster['mixed_named_face_count'] = $mixedClusterSummaries[$clusterId]['named_faces'];
                    }

                    return $cluster;
                }, $clusters),
                'total' => (int) $total,
            ];
        } catch (\Exception $e) {
            Log::error('FaceEmbedding: getUnifiedClusters failed', ['error' => $e->getMessage()]);

            return ['clusters' => [], 'total' => 0];
        }
    }

    /**
     * Find active face clusters whose visible named faces disagree on person name.
     *
     * @return array<int, array{names: array<int, string>, distinct_names: int, named_faces: int}>
     */
    private function getMixedNameClusterSummaries(): array
    {
        $rows = DB::select("
            SELECT
                frf.cluster_id,
                COUNT(*) AS named_faces,
                COUNT(DISTINCT frf.person_name) AS distinct_names,
                GROUP_CONCAT(DISTINCT frf.person_name ORDER BY frf.person_name SEPARATOR ' | ') AS names
            FROM file_registry_faces frf
            INNER JOIN file_registry fr ON fr.id = frf.file_registry_id
            WHERE fr.status = 'active'
              AND frf.hidden = 0
              AND frf.cluster_id IS NOT NULL
              AND TRIM(COALESCE(frf.person_name, '')) <> ''
            GROUP BY frf.cluster_id
            HAVING distinct_names > 1
        ");

        $summaries = [];
        foreach ($rows as $row) {
            $clusterId = (int) $row->cluster_id;
            $summaries[$clusterId] = [
                'names' => array_values(array_filter(
                    array_map('trim', explode('|', (string) $row->names)),
                    fn (string $name): bool => $name !== ''
                )),
                'distinct_names' => (int) $row->distinct_names,
                'named_faces' => (int) $row->named_faces,
            ];
        }

        return $summaries;
    }

    /**
     * Identify a cluster: set name, confirm status, update all MySQL faces.
     * If name matches an existing confirmed cluster, auto-merge.
     *
     * @param  int  $clusterId  Cluster to identify
     * @param  string  $name  Person name
     * @param  int|null  $genealogyPersonId  Optional genealogy link
     * @param  bool  $writeToMedia  Write face regions to image files
     * @return array Result with possible merge info
     */
    public function identifyCluster(
        int $clusterId,
        string $name,
        ?int $genealogyPersonId = null,
        bool $writeToMedia = true
    ): array {
        // Check if name matches an existing confirmed cluster (merge-on-rename)
        $existingCluster = DB::connection('pgsql_rag')->selectOne("
            SELECT id, face_count FROM person_clusters
            WHERE name = ? AND status = 'confirmed' AND id != ?
        ", [$name, $clusterId]);

        if ($existingCluster) {
            // Backfill the existing target's genealogy link before merging so the
            // bridge call inside mergeClusters picks up the new person if the
            // existing cluster wasn't yet linked.
            if ($genealogyPersonId !== null) {
                DB::connection('pgsql_rag')->update('
                    UPDATE person_clusters
                    SET genealogy_person_id = COALESCE(genealogy_person_id, ?), updated_at = NOW()
                    WHERE id = ?
                ', [$genealogyPersonId, $existingCluster->id]);
            }

            // Merge into existing named cluster
            $this->mergeClusters($existingCluster->id, [$clusterId]);

            // Update MySQL faces — set person_name and cluster_id
            $feRows = DB::connection('pgsql_rag')->select('
                SELECT file_registry_face_id FROM face_embeddings
                WHERE person_cluster_id = ? AND file_registry_face_id IS NOT NULL
            ', [$existingCluster->id]);

            $mysqlIds = array_map(fn ($r) => $r->file_registry_face_id, $feRows);
            if (! empty($mysqlIds)) {
                $ph = implode(',', array_fill(0, count($mysqlIds), '?'));
                DB::update("
                    UPDATE file_registry_faces
                    SET person_name = ?, cluster_id = ?, genealogy_person_id = COALESCE(?, genealogy_person_id)
                    WHERE id IN ({$ph})
                ", array_merge([$name, $existingCluster->id, $genealogyPersonId], $mysqlIds));
            }

            if ($writeToMedia) {
                $this->syncClusterToMediaFiles($existingCluster->id);
            }

            // Propagate: merged cluster has more faces → centroid shifted → may match more unreviewed clusters
            $propagation = $this->propagateClusterMatches($existingCluster->id);

            return [
                'action' => 'merged',
                'target_cluster_id' => $existingCluster->id,
                'source_cluster_id' => $clusterId,
                'name' => $name,
                'propagation' => $propagation,
            ];
        }

        // Normal identify — confirm this cluster
        $this->confirmCluster($clusterId, $name, $genealogyPersonId, $writeToMedia);

        // Update MySQL faces
        $feRows = DB::connection('pgsql_rag')->select('
            SELECT file_registry_face_id FROM face_embeddings
            WHERE person_cluster_id = ? AND file_registry_face_id IS NOT NULL
        ', [$clusterId]);

        $mysqlIds = array_map(fn ($r) => $r->file_registry_face_id, $feRows);
        if (! empty($mysqlIds)) {
            $ph = implode(',', array_fill(0, count($mysqlIds), '?'));
            DB::update("
                UPDATE file_registry_faces
                SET person_name = ?, cluster_id = ?, genealogy_person_id = COALESCE(?, genealogy_person_id)
                WHERE id IN ({$ph})
            ", array_merge([$name, $clusterId, $genealogyPersonId], $mysqlIds));
        }

        // Propagate: newly confirmed cluster acts as anchor for matching unreviewed clusters
        $propagation = $this->propagateClusterMatches($clusterId);

        return [
            'action' => 'identified',
            'cluster_id' => $clusterId,
            'name' => $name,
            'propagation' => $propagation,
        ];
    }

    /**
     * Hide a cluster: marks as ignored and sets hidden flag on MySQL faces.
     */
    public function hideCluster(int $clusterId): bool
    {
        $result = $this->ignoreCluster($clusterId, 'Hidden via UI');

        if ($result) {
            // Set hidden on MySQL faces
            $feRows = DB::connection('pgsql_rag')->select('
                SELECT file_registry_face_id FROM face_embeddings
                WHERE person_cluster_id = ? AND file_registry_face_id IS NOT NULL
            ', [$clusterId]);

            if (! empty($feRows)) {
                $mysqlIds = array_map(fn ($r) => $r->file_registry_face_id, $feRows);
                $ph = implode(',', array_fill(0, count($mysqlIds), '?'));
                DB::update("UPDATE file_registry_faces SET hidden = 1 WHERE id IN ({$ph})", $mysqlIds);
            }
        }

        return $result;
    }

    /**
     * Restore a hidden cluster back to unreviewed.
     */
    public function restoreCluster(int $clusterId): bool
    {
        $result = $this->revertCluster($clusterId, 'unreviewed');

        if ($result) {
            $feRows = DB::connection('pgsql_rag')->select('
                SELECT file_registry_face_id FROM face_embeddings
                WHERE person_cluster_id = ? AND file_registry_face_id IS NOT NULL
            ', [$clusterId]);

            if (! empty($feRows)) {
                $mysqlIds = array_map(fn ($r) => $r->file_registry_face_id, $feRows);
                $ph = implode(',', array_fill(0, count($mysqlIds), '?'));
                DB::update("UPDATE file_registry_faces SET hidden = 0 WHERE id IN ({$ph})", $mysqlIds);
            }
        }

        return $result;
    }

    /**
     * Optimize clusters: merge similar unreviewed, match against confirmed anchors,
     * recalculate stale centroids, purge empty clusters.
     *
     * Periodic face-cluster optimization job. Run periodically (every 6h).
     *
     * @param  bool  $dryRun  Report what would happen without making changes
     * @return array Optimization results
     */
    public function optimizeClusters(bool $dryRun = false): array
    {
        $results = [
            'unreviewed_merges' => 0,
            'anchor_merges' => 0,
            'anchor_suggestions' => [],
            'centroids_updated' => 0,
            'empty_purged' => 0,
            'skipped_retry_limit' => 0,
        ];

        try {
            // Step 1: Merge similar unreviewed clusters
            $unreviewedClusters = DB::connection('pgsql_rag')->select("
                SELECT id, face_count, centroid::text as centroid_str, merge_retry
                FROM person_clusters
                WHERE status = 'unreviewed'
                AND centroid IS NOT NULL
                AND face_count > 0
                AND (merge_retry IS NULL OR merge_retry < 3)
                ORDER BY face_count DESC
            ");

            $mergedIds = [];
            foreach ($unreviewedClusters as $cluster) {
                if (in_array($cluster->id, $mergedIds)) {
                    continue;
                }

                // Find similar unreviewed clusters via centroid
                $similar = DB::connection('pgsql_rag')->select("
                    SELECT pc.id, pc.face_count
                    FROM person_clusters pc
                    WHERE pc.status = 'unreviewed'
                    AND pc.centroid IS NOT NULL
                    AND pc.id != ?
                    AND pc.face_count > 0
                    AND (pc.merge_retry IS NULL OR pc.merge_retry < 3)
                    AND 1 - (pc.centroid <=> ?::vector) >= 0.90
                    AND pc.face_count < ?
                    ORDER BY pc.centroid <=> ?::vector
                ", [$cluster->id, $cluster->centroid_str, self::CLUSTER_SOFT_CAP, $cluster->centroid_str]);

                foreach ($similar as $s) {
                    if (in_array($s->id, $mergedIds)) {
                        continue;
                    }
                    // Both must be small for unreviewed-vs-unreviewed merge
                    if ($cluster->face_count >= self::CLUSTER_SOFT_CAP) {
                        continue;
                    }

                    if ($dryRun) {
                        $results['unreviewed_merges']++;
                    } else {
                        $this->mergeClusters($cluster->id, [$s->id], 'optimization');
                        $mergedIds[] = $s->id;
                        $results['unreviewed_merges']++;
                    }
                }
            }

            // Step 2: Match unreviewed clusters against confirmed anchors
            $unreviewedForAnchors = DB::connection('pgsql_rag')->select("
                SELECT id, face_count, centroid::text as centroid_str, merge_retry
                FROM person_clusters
                WHERE status = 'unreviewed'
                AND centroid IS NOT NULL
                AND face_count > 0
                AND (merge_retry IS NULL OR merge_retry < 3)
                AND id NOT IN (".(empty($mergedIds) ? '0' : implode(',', $mergedIds)).')
                ORDER BY face_count DESC
            ');

            foreach ($unreviewedForAnchors as $cluster) {
                $bestAnchor = DB::connection('pgsql_rag')->selectOne("
                    SELECT pc.id, pc.name,
                           1 - (pc.centroid <=> ?::vector) as centroid_similarity
                    FROM person_clusters pc
                    WHERE pc.status = 'confirmed'
                    AND pc.centroid IS NOT NULL
                    AND pc.face_count > 0
                    ORDER BY pc.centroid <=> ?::vector
                    LIMIT 1
                ", [$cluster->centroid_str, $cluster->centroid_str]);

                if (! $bestAnchor) {
                    continue;
                }

                $similarity = (float) $bestAnchor->centroid_similarity;

                if ($similarity >= self::HIGH_CONFIDENCE) {
                    // High confidence — auto-merge into confirmed cluster
                    if ($dryRun) {
                        $results['anchor_merges']++;
                    } else {
                        $this->mergeClusters($bestAnchor->id, [$cluster->id], 'optimization');
                        $results['anchor_merges']++;
                    }
                } elseif ($similarity >= 0.75) {
                    // Medium confidence — suggest for human review
                    $results['anchor_suggestions'][] = [
                        'unreviewed_cluster_id' => $cluster->id,
                        'unreviewed_face_count' => $cluster->face_count,
                        'suggested_anchor_id' => $bestAnchor->id,
                        'suggested_name' => $bestAnchor->name,
                        'similarity' => $similarity,
                    ];

                    // Increment merge_retry so we don't keep suggesting the same clusters
                    if (! $dryRun) {
                        DB::connection('pgsql_rag')->update('
                            UPDATE person_clusters
                            SET merge_retry = COALESCE(merge_retry, 0) + 1,
                                merge_notes = ?
                            WHERE id = ?
                        ', ["Suggested match to '{$bestAnchor->name}' (sim={$similarity})", $cluster->id]);
                    }
                }
            }

            // Count clusters skipped due to retry limit
            $results['skipped_retry_limit'] = (int) DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as cnt FROM person_clusters
                WHERE status = 'unreviewed' AND merge_retry >= 3
            ")->cnt;

            // Step 3: Recalculate stale centroids
            if (! $dryRun) {
                $results['centroids_updated'] = $this->updateStaleCentroids(500);
            } else {
                $results['centroids_updated'] = (int) DB::connection('pgsql_rag')->selectOne("
                    SELECT COUNT(*) as cnt FROM person_clusters
                    WHERE status IN ('confirmed', 'unreviewed')
                    AND face_count > 0
                    AND (centroid IS NULL OR last_optimized_at IS NULL OR last_optimized_at < updated_at)
                ")->cnt;
            }

            // Step 4: Purge empty clusters (orphans from splits/moves)
            if ($dryRun) {
                $results['empty_purged'] = (int) DB::connection('pgsql_rag')->selectOne("
                    SELECT COUNT(*) as cnt FROM person_clusters
                    WHERE face_count = 0 AND status NOT IN ('merged')
                ")->cnt;
            } else {
                $results['empty_purged'] = DB::connection('pgsql_rag')->delete("
                    DELETE FROM person_clusters
                    WHERE face_count = 0 AND status NOT IN ('merged')
                ");
            }

            Log::info('FaceEmbedding: optimizeClusters complete', $results);
        } catch (\Exception $e) {
            Log::error('FaceEmbedding: optimizeClusters failed', ['error' => $e->getMessage()]);
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Purge bloated confirmed clusters by removing faces that don't match the
     * authoritative centroid (computed from only XMP-named faces).
     *
     * Confirmed clusters seeded from few XMP-tagged faces can absorb thousands of
     * similar-looking strangers via auto-matching. This method computes a centroid
     * from ONLY the authoritative (XMP-named) faces, then evicts any embedding
     * below the similarity threshold. Evicted faces become unclustered singletons.
     *
     * @param  float  $bloatThreshold  Min ratio of cluster_count/named_count to trigger (default 10)
     * @param  float  $similarityThreshold  Min similarity to authoritative centroid to keep (default 0.92)
     * @param  bool  $dryRun  Report without making changes
     * @return array Purge results
     */
    public function purgeConfirmedBloat(
        float $bloatThreshold = 10.0,
        float $similarityThreshold = 0.92,
        bool $dryRun = false
    ): array {
        $results = ['clusters_checked' => 0, 'clusters_purged' => 0, 'faces_evicted' => 0, 'details' => []];

        try {
            // Get all confirmed clusters with their face counts
            $confirmed = DB::connection('pgsql_rag')->select("
                SELECT id, name, face_count FROM person_clusters
                WHERE status = 'confirmed' AND face_count > 0 AND name IS NOT NULL
                ORDER BY face_count DESC
            ");

            foreach ($confirmed as $cluster) {
                $results['clusters_checked']++;

                // Count XMP-named faces in MySQL for this person
                $namedCount = (int) DB::selectOne('
                    SELECT COUNT(*) as cnt FROM file_registry_faces
                    WHERE person_name = ? AND hidden = 0
                ', [$cluster->name])->cnt;

                if ($namedCount < 2) {
                    continue; // Not enough authoritative faces to compute meaningful centroid
                }

                $ratio = $cluster->face_count / max($namedCount, 1);
                if ($ratio < $bloatThreshold) {
                    continue; // Cluster isn't bloated
                }

                // Get pgvector IDs for XMP-named faces (cross-DB via face_registry_face_id)
                $namedFaceIds = DB::select('
                    SELECT id FROM file_registry_faces
                    WHERE person_name = ? AND hidden = 0
                ', [$cluster->name]);
                $mysqlIds = array_map(fn ($f) => $f->id, $namedFaceIds);
                $ph = implode(',', $mysqlIds);

                $authEmbeddings = DB::connection('pgsql_rag')->select("
                    SELECT embedding::text as emb_str FROM face_embeddings
                    WHERE file_registry_face_id IN ({$ph})
                ");

                if (count($authEmbeddings) < 2) {
                    continue; // Not enough authoritative embeddings
                }

                // Compute authoritative centroid from ONLY named faces
                $sum = array_fill(0, 128, 0.0);
                $n = 0;
                foreach ($authEmbeddings as $ae) {
                    $vec = array_map('floatval', explode(',', trim($ae->emb_str, '[]')));
                    if (count($vec) === 128) {
                        for ($i = 0; $i < 128; $i++) {
                            $sum[$i] += $vec[$i];
                        }
                        $n++;
                    }
                }
                if ($n < 2) {
                    continue;
                }

                $mean = array_map(fn ($v) => $v / $n, $sum);
                $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $mean)));
                if ($norm > 0) {
                    $mean = array_map(fn ($v) => $v / $norm, $mean);
                }
                $centroidStr = PgVector::literal($mean, self::VECTOR_LITERAL_PRECISION);

                // Find faces below threshold relative to authoritative centroid
                $evictable = DB::connection('pgsql_rag')->select('
                    SELECT id, file_registry_face_id
                    FROM face_embeddings
                    WHERE person_cluster_id = ?
                    AND 1 - (embedding <=> ?::vector) < ?
                ', [$cluster->id, $centroidStr, $similarityThreshold]);

                if (empty($evictable)) {
                    continue;
                }

                $evictCount = count($evictable);

                $results['details'][] = [
                    'cluster_id' => $cluster->id,
                    'name' => $cluster->name,
                    'cluster_faces' => $cluster->face_count,
                    'named_faces' => $namedCount,
                    'authoritative_embeddings' => $n,
                    'ratio' => round($ratio, 1),
                    'evictable' => $evictCount,
                ];

                if (! $dryRun) {
                    $evictIds = array_map(fn ($e) => $e->id, $evictable);
                    $evictPh = implode(',', $evictIds);

                    // Create singleton clusters for evicted faces
                    foreach ($evictIds as $eId) {
                        $newCluster = DB::connection('pgsql_rag')->table('person_clusters')->insertGetId([
                            'name' => null,
                            'status' => 'unreviewed',
                            'face_count' => 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        DB::connection('pgsql_rag')->update('
                            UPDATE face_embeddings SET person_cluster_id = ?, updated_at = NOW() WHERE id = ?
                        ', [$newCluster, $eId]);
                    }

                    // Clear cluster_id on evicted MySQL faces
                    $evictMysqlIds = array_filter(array_map(fn ($e) => $e->file_registry_face_id, $evictable));
                    if (! empty($evictMysqlIds)) {
                        $mysqlPh = implode(',', $evictMysqlIds);
                        DB::update("UPDATE file_registry_faces SET cluster_id = NULL WHERE id IN ({$mysqlPh})");
                    }

                    // Update cluster face count
                    DB::connection('pgsql_rag')->update('
                        UPDATE person_clusters
                        SET face_count = (SELECT COUNT(*) FROM face_embeddings WHERE person_cluster_id = ?),
                            updated_at = NOW()
                        WHERE id = ?
                    ', [$cluster->id, $cluster->id]);

                    // Recompute centroid from remaining (now cleaner) faces
                    $this->updateClusterCentroid($cluster->id);

                    $results['clusters_purged']++;
                    $results['faces_evicted'] += $evictCount;
                }
            }
        } catch (\Throwable $e) {
            Log::error('FaceEmbedding: purgeConfirmedBloat failed', ['error' => $e->getMessage()]);
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Reset merge_retry counter for a cluster.
     * Called when user manually confirms or identifies a cluster.
     */
    public function resetMergeRetry(int $clusterId): void
    {
        try {
            DB::connection('pgsql_rag')->update('
                UPDATE person_clusters
                SET merge_retry = 0, merge_notes = NULL
                WHERE id = ?
            ', [$clusterId]);
        } catch (\Exception $e) {
            // Non-critical, just log
            Log::warning('FaceEmbedding: resetMergeRetry failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check if Python face_recognition is available
     */
    public function isAvailable(): bool
    {
        $cacheKey = 'face_recognition_available';

        return Cache::remember($cacheKey, 3600, function () {
            $result = Process::timeout(15)->run([
                $this->pythonPath,
                '-c',
                "import face_recognition; print('OK')",
            ]);

            return trim($result->output()) === 'OK';
        });
    }

    /**
     * Recompute centroid (mean embedding) and radius for a cluster.
     * Called after identify, merge, split, import assignments.
     *
     * @return bool Success
     */
    public function updateClusterCentroid(int $clusterId): bool
    {
        try {
            $faces = DB::connection('pgsql_rag')->select('
                SELECT embedding::text as embedding_str
                FROM face_embeddings
                WHERE person_cluster_id = ?
            ', [$clusterId]);

            if (empty($faces)) {
                return false;
            }

            // Compute mean embedding
            $sum = array_fill(0, 128, 0.0);
            $count = 0;
            $vectors = [];

            foreach ($faces as $f) {
                $vec = array_map('floatval', explode(',', trim($f->embedding_str, '[]')));
                if (count($vec) === 128) {
                    $vectors[] = $vec;
                    for ($i = 0; $i < 128; $i++) {
                        $sum[$i] += $vec[$i];
                    }
                    $count++;
                }
            }

            if ($count === 0) {
                return false;
            }

            $mean = array_map(fn ($v) => $v / $count, $sum);

            // L2-normalize
            $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $mean)));
            if ($norm > 0) {
                $mean = array_map(fn ($v) => $v / $norm, $mean);
            }

            // Compute max cosine distance from centroid to any member
            $maxDistance = 0.0;
            $centroidStr = PgVector::literal($mean, self::VECTOR_LITERAL_PRECISION);

            foreach ($vectors as $vec) {
                $vecStr = PgVector::literal($vec, self::VECTOR_LITERAL_PRECISION);
                $dist = DB::connection('pgsql_rag')->selectOne('
                    SELECT ?::vector <=> ?::vector as distance
                ', [$centroidStr, $vecStr]);
                if ($dist && (float) $dist->distance > $maxDistance) {
                    $maxDistance = (float) $dist->distance;
                }
            }

            // Store centroid, radius, and timestamp
            DB::connection('pgsql_rag')->update('
                UPDATE person_clusters
                SET centroid = ?::vector,
                    centroid_radius = ?,
                    last_optimized_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ', [$centroidStr, $maxDistance, $clusterId]);

            return true;
        } catch (\Exception $e) {
            Log::warning('FaceEmbedding: updateClusterCentroid failed', [
                'cluster_id' => $clusterId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Batch-update centroids for all clusters that need it.
     * Finds clusters where centroid is NULL or stale (last_optimized_at < updated_at).
     *
     * @param  int  $limit  Max clusters to update per run
     * @return int Number of clusters updated
     */
    public function updateStaleCentroids(int $limit = 500): int
    {
        try {
            $stale = DB::connection('pgsql_rag')->select("
                SELECT id FROM person_clusters
                WHERE status IN ('confirmed', 'unreviewed')
                AND face_count > 0
                AND (centroid IS NULL OR last_optimized_at IS NULL OR last_optimized_at < updated_at)
                ORDER BY face_count DESC
                LIMIT ?
            ", [$limit]);

            $updated = 0;
            foreach ($stale as $c) {
                if ($this->updateClusterCentroid($c->id)) {
                    $updated++;
                }
            }

            return $updated;
        } catch (\Exception $e) {
            Log::warning('FaceEmbedding: updateStaleCentroids failed', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Centroid-first cluster matching — coarse filter via cluster centroids,
     * then refine with per-face distances for top candidates.
     *
     * ~98% fewer distance evaluations than scanning all faces in all clusters.
     * Falls back to full scan if no centroids are populated yet.
     *
     * @param  array  $embedding  128-dim face embedding
     * @param  float  $tolerance  Match tolerance
     * @param  int  $limit  Max clusters to return
     * @return array Matching clusters with best confidence per cluster
     */
    public function findMatchingClustersCentroid(array $embedding, float $tolerance = self::MATCH_TOLERANCE, int $limit = 10): array
    {
        $embeddingStr = PgVector::literal($embedding, self::VECTOR_LITERAL_PRECISION);

        try {
            // Check if centroids are populated
            $centroidCount = DB::connection('pgsql_rag')->selectOne("
                SELECT COUNT(*) as cnt FROM person_clusters
                WHERE centroid IS NOT NULL AND status IN ('confirmed', 'unreviewed')
            ")->cnt;

            if ($centroidCount === 0) {
                // No centroids yet — fall back to full scan
                return $this->findMatchingClusters($embedding, $tolerance, $limit);
            }

            // Phase 1: Coarse filter — find top candidate clusters via centroid distance
            // Use a loose threshold (tolerance + centroid_radius margin) to avoid false negatives
            $coarseLimit = min($limit * 5, 50);
            $candidates = DB::connection('pgsql_rag')->select("
                SELECT
                    pc.id as cluster_id,
                    pc.name as cluster_name,
                    pc.status,
                    pc.face_count,
                    pc.genealogy_person_id,
                    1 - (pc.centroid <=> ?::vector) as centroid_confidence,
                    pc.centroid_radius
                FROM person_clusters pc
                WHERE pc.status IN ('confirmed', 'unreviewed')
                AND pc.centroid IS NOT NULL
                AND 1 - (pc.centroid <=> ?::vector) >= ?
                ORDER BY pc.centroid <=> ?::vector
                LIMIT ?
            ", [$embeddingStr, $embeddingStr, 1 - $tolerance - 0.1, $embeddingStr, $coarseLimit]);

            if (empty($candidates)) {
                return [];
            }

            // Phase 2: Refine — for each candidate, check best per-face match
            $results = [];
            foreach ($candidates as $c) {
                $best = DB::connection('pgsql_rag')->selectOne('
                    SELECT
                        MAX(1 - (fe.embedding <=> ?::vector)) as confidence,
                        (SELECT fe2.crop_path FROM face_embeddings fe2
                         WHERE fe2.person_cluster_id = ?
                         ORDER BY fe2.embedding <=> ?::vector LIMIT 1) as best_match_crop
                    FROM face_embeddings fe
                    WHERE fe.person_cluster_id = ?
                    HAVING MAX(1 - (fe.embedding <=> ?::vector)) >= ?
                ', [$embeddingStr, $c->cluster_id, $embeddingStr, $c->cluster_id, $embeddingStr, 1 - $tolerance]);

                if ($best && $best->confidence !== null) {
                    $results[] = [
                        'cluster_id' => $c->cluster_id,
                        'cluster_name' => $c->cluster_name,
                        'status' => $c->status,
                        'face_count' => $c->face_count,
                        'genealogy_person_id' => $c->genealogy_person_id,
                        'confidence' => (float) $best->confidence,
                        'best_match_crop' => $best->best_match_crop,
                    ];
                }
            }

            // Sort by confidence descending
            usort($results, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

            return array_slice($results, 0, $limit);
        } catch (\Exception $e) {
            Log::warning('FaceEmbedding: findMatchingClustersCentroid failed, falling back', ['error' => $e->getMessage()]);

            return $this->findMatchingClusters($embedding, $tolerance, $limit);
        }
    }
}
