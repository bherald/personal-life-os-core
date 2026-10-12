<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\Genealogy\FaceLinkBridgeService;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use App\Support\PgVector;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * FaceEmbeddingService - AI Face Detection and Embedding
 *
 * Detects faces in images, generates embeddings, finds matches,
 * and clusters unknown faces for human review.
 *
 * Uses:
 * - Python face_recognition library for detection/embeddings (CPU, no GPU lock needed)
 * - pgvector for similarity search
 * - AI-assisted evidence staging when confidence is borderline
 * - Ollama vision for additional face context (via AIService with GPU locks)
 *
 * AIService Integration:
 * - Uses AIService.processImage() for vision tasks
 * - Python face_recognition runs on CPU (no contention with GPU workloads)
 * - All AI calls use the standard AIService resilience pipeline
 */
class FaceEmbeddingService
{
    private const AUTOMATIC_ASSIGNMENT_ACTION = 'auto_face_cluster_assignment';

    private const AUTOMATIC_ASSIGNMENT_SCHEMA = 'plos.auto-face-cluster-assignment.v1';

    // MySQL face regions persist as DECIMAL(10,8); half a final stored unit is 5e-9.
    private const MYSQL_FACE_REGION_HALF_UNIT = 0.000000005;

    private const MYSQL_FACE_REGION_SCALE = 100000000;

    // DECIMAL storage plus half the largest PostgreSQL REAL ULP for [0,1] values.
    private const FACE_REGION_STORAGE_BOUND = 0.000000005 + (1 / 33554432);

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
    public function detectFaces(string $imagePath, bool $saveCrops = true, ?string $privateOutputDir = null, bool $detectOnly = false, bool $sourcePreview = false, bool $sourceBoundGeneration = false, ?array $selectedRegion = null): array
    {
        if (! file_exists($imagePath)) {
            return ['success' => false, 'error' => 'File not found: '.$imagePath];
        }

        $scriptPath = base_path(self::PYTHON_SCRIPT);
        if (! file_exists($scriptPath)) {
            return ['success' => false, 'error' => 'Python script not found'];
        }

        $outputDir = $privateOutputDir ?? storage_path('app/face_crops');
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $outputFile = tempnam($privateOutputDir ?? sys_get_temp_dir(), 'face_detect_');

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
        if ($detectOnly || $sourceBoundGeneration) {
            if ($detectOnly) {
                $command[] = '--detect-only';
            }
            if ($sourceBoundGeneration) {
                $command[] = '--source-bound-generation';
            }
            array_push($command, '--private-output', '--max-source-bytes',
                (string) config('file_registry.face_preview.max_source_bytes'),
                '--max-pixels', (string) config('file_registry.face_preview.max_pixels'),
                '--max-header-pixels', (string) config('metadata_writeback.preview_max_source_pixels'));
            if ($sourcePreview) {
                array_push($command, '--source-preview', '--output-dir', $outputDir,
                    '--max-preview-dimension', (string) config('metadata_writeback.preview_max_dimension'));
            } else {
                array_push($command, '--max-detector-dimension', (string) config('file_registry.face_preview.max_detector_dimension'));
            }
            $limiter = (string) config('file_registry.face_preview.resource_limiter');
            $memoryMb = (int) config('file_registry.face_preview.process_memory_mb');
            if (! is_executable($limiter) || $memoryMb < 1) {
                throw new \RuntimeException('Preview memory limit unavailable');
            }
            $command = [$limiter, '--as='.($memoryMb * 1024 * 1024), '--', ...$command];
        }

        if ($selectedRegion !== null) {
            if (! $sourceBoundGeneration || $detectOnly || $sourcePreview) {
                throw new \RuntimeException('selected_region_mode_invalid');
            }
            array_push($command, '--selected-region-json', json_encode($selectedRegion, JSON_THROW_ON_ERROR));
        }
        $bounded = $detectOnly || $sourceBoundGeneration;
        $environment = $this->engineProofEnvironment();
        if ($bounded) {
            $environment += ['OPENBLAS_NUM_THREADS' => '1', 'OMP_NUM_THREADS' => '1', 'MKL_NUM_THREADS' => '1'];
        }
        $pending = Process::timeout($bounded ? config('file_registry.face_preview.timeout_seconds', 120) : 120)->env($environment);
        try {
            $process = $pending->run($command);
            if (! $process instanceof ProcessResult
                || $process->exitCode() !== 0 || ! $process->successful()) {
                // Never accept a complete-looking packet from an unsuccessful
                // worker. Retain its private output file as failure evidence.
                return ['success' => false, 'error_code' => 'detector_process_failed'];
            }
            $output = $process->output();
        } catch (\Throwable $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }

            // A throwing runner may already have started a worker. Neither a
            // timeout nor its direct-child result proves descendant cleanup.
            // Keep the packet and let callers retain their owned snapshots.
            throw new InferenceAdmissionException('Face detector execution state UNKNOWN; evidence retained and execution must not be replayed.', 0, $error);
        }

        if (! file_exists($outputFile)) {
            return ['success' => false, 'error_code' => 'detector_packet_read_held'];
        }
        $limit = config($sourceBoundGeneration
            ? 'file_registry.face_generation.max_packet_bytes'
            : 'file_registry.face_preview.max_packet_bytes');
        clearstatcache(true, $outputFile);
        $preOpen = @lstat($outputFile);
        $keys = array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime']);
        // Reject FIFO/devices/symlinks before fopen can block or follow them.
        $handle = is_int($limit) && $limit > 0 && $limit < PHP_INT_MAX
            && is_array($preOpen) && ($preOpen['mode'] & 0170000) === 0100000
            && $preOpen['size'] >= 0 && $preOpen['size'] <= $limit
            ? @fopen($outputFile, 'rb') : false;
        $bytes = false;
        $stable = false;
        if (is_resource($handle)) {
            try {
                $before = fstat($handle);
                if (is_array($before) && array_intersect_key($preOpen, $keys) === array_intersect_key($before, $keys)
                    && ($before['mode'] & 0170000) === 0100000
                    && $before['size'] >= 0 && $before['size'] <= $limit) {
                    // The bounded read remains safe if the worker file grows after stat.
                    $bytes = @stream_get_contents($handle, $limit + 1);
                    $after = fstat($handle);
                    clearstatcache(true, $outputFile);
                    $current = @lstat($outputFile);
                    $stable = is_array($after) && is_array($current)
                        && array_intersect_key($before, $keys) === array_intersect_key($after, $keys)
                        && array_intersect_key($before, $keys) === array_intersect_key($current, $keys)
                        && is_string($bytes) && strlen($bytes) === $before['size'];
                }
            } finally {
                fclose($handle);
            }
        }
        if (! $stable || ! is_string($bytes) || strlen($bytes) > $limit) {
            // Preserve held evidence; neither truncate nor fall back to stdout.
            return ['success' => false, 'error_code' => 'detector_packet_read_held'];
        }
        $packetConfig = 'file_registry.'.($sourceBoundGeneration ? 'face_generation' : 'face_preview');
        if (! $this->detectorJsonResourceAdmitted($bytes, $packetConfig)) {
            return ['success' => false, 'error_code' => 'detector_packet_resource_held'];
        }
        $result = json_decode($bytes, true, config($packetConfig.'.max_json_depth'));
        @unlink($outputFile);

        if (is_array($result) && (($result['success'] ?? false) === true || $bounded)) {
            return $result;
        }

        // Parse output if JSON file failed
        if (! $this->detectorJsonResourceAdmitted($output, $packetConfig)) {
            return ['success' => false, 'error_code' => 'detector_packet_resource_held'];
        }
        $jsonResult = json_decode($output, true, config($packetConfig.'.max_json_depth'));
        if ($jsonResult) {
            return $jsonResult;
        }

        return ['success' => false, 'error' => 'Face detection failed', 'output' => $output];
    }

    /** Resource preflight only; json_decode still owns syntax and domain validators own proof. */
    private function detectorJsonResourceAdmitted(string $bytes, string $configPrefix): bool
    {
        $byteLimit = config($configPrefix.'.max_packet_bytes');
        $tokenLimit = config($configPrefix.'.max_json_structural_tokens');
        $depthLimit = config($configPrefix.'.max_json_depth');
        if (! is_int($byteLimit) || $byteLimit < 1 || ! is_int($tokenLimit) || $tokenLimit < 1
            || ! is_int($depthLimit) || $depthLimit < 1 || $depthLimit > 512 || strlen($bytes) > $byteLimit) {
            return false;
        }
        $tokens = 0;
        $depth = 0;
        $quoted = false;
        $escaped = false;
        for ($index = 0, $length = strlen($bytes); $index < $length; $index++) {
            $character = $bytes[$index];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $quoted = false;
                }

                continue;
            }
            if ($character === '"') {
                $quoted = true;

                continue;
            }
            if (str_contains('{}[],:', $character) && ++$tokens > $tokenLimit) {
                return false;
            }
            if ($character === '{' || $character === '[') {
                if (++$depth > $depthLimit) {
                    return false;
                }
            } elseif ($character === '}' || $character === ']') {
                $depth = max(0, $depth - 1);
            }
        }

        return true;
    }

    /** Decode a bounded source preview without running face detection or creating records. */
    public function renderSourcePreview(string $sourceBytes): array
    {
        $directory = null;
        $retainArtifacts = false;
        try {
            $dimensions = @getimagesizefromstring($sourceBytes);
            $maxBytes = (int) config('file_registry.face_preview.max_source_bytes');
            if (strlen($sourceBytes) > $maxBytes || ! $dimensions || $dimensions[2] !== IMAGETYPE_JPEG
                || (int) config('metadata_writeback.preview_max_source_pixels') < $dimensions[0] * $dimensions[1]) {
                return ['success' => false, 'error_code' => 'source_resource_budget'];
            }
            $directory = sys_get_temp_dir().'/'.Str::uuid()->toString();
            if (! mkdir($directory, 0700)) {
                throw new \RuntimeException('Private preview directory unavailable');
            }
            $snapshot = $directory.'/source-image';
            if (file_put_contents($snapshot, $sourceBytes) !== strlen($sourceBytes) || ! chmod($snapshot, 0600)) {
                throw new \RuntimeException('Private snapshot unavailable');
            }
            $hash = hash('sha256', $sourceBytes);
            $result = $this->detectFaces($snapshot, false, $directory, true, true);
            $path = is_string($result['preview_path'] ?? null) ? realpath($result['preview_path']) : false;
            if (($result['success'] ?? false) !== true || ($result['source_sha256'] ?? null) !== $hash
                || ($result['image_width'] ?? null) !== $dimensions[0] || ($result['image_height'] ?? null) !== $dimensions[1]
                || ($result['coordinate_basis'] ?? null) !== 'encoded_pixels'
                || ($result['face_region_convention'] ?? null) !== 'normalized_top_left'
                || ! $this->validPreviewDecode($result['decode_provenance'] ?? null, [$dimensions[0], $dimensions[1]])
                || ! is_array($result['orientation_provenance'] ?? null)
                || $path === false || ! str_starts_with($path, $directory.DIRECTORY_SEPARATOR) || ! is_file($path)) {
                throw new \RuntimeException('Preview proof invalid');
            }
            $bytes = @file_get_contents($path, false, null, 0, $maxBytes + 1);
            $preview = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            $maxDimension = (int) config('metadata_writeback.preview_max_dimension');
            if (! $preview || strlen($bytes) > $maxBytes || $preview[2] !== IMAGETYPE_JPEG
                || max($preview[0], $preview[1]) > $maxDimension || [$preview[0], $preview[1]] !== ($result['preview_dimensions'] ?? null)) {
                throw new \RuntimeException('Preview raster invalid');
            }

            return ['success' => true, 'bytes' => $bytes, 'preview_dimensions' => [$preview[0], $preview[1]],
                'orientation_provenance' => $result['orientation_provenance'], 'decode_provenance' => $result['decode_provenance']];
        } catch (\Throwable $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                $retainArtifacts = true;
                throw $error;
            }

            return ['success' => false, 'error_code' => 'source_resource_budget'];
        } finally {
            if (! $retainArtifacts && $directory !== null) {
                File::deleteDirectory($directory);
            }
        }
    }

    private function validPreviewDecode(mixed $proof, array $sourceDimensions): bool
    {
        if (! is_array($proof) || ($proof['source_dimensions'] ?? null) !== $sourceDimensions
            || ! is_array($proof['decoded_dimensions'] ?? null) || count($proof['decoded_dimensions']) !== 2
            || ! is_array($proof['draft_box'] ?? null) || count($proof['draft_box']) !== 4
            || ! is_array($proof['original_scale'] ?? null) || count($proof['original_scale']) !== 2) {
            return false;
        }
        [$width, $height] = $proof['decoded_dimensions'];
        if (! is_int($width) || ! is_int($height) || min($width, $height) < 1
            || $width * $height > (int) config('file_registry.face_preview.max_pixels')
            || $proof['draft_box'][0] !== 0 || $proof['draft_box'][1] !== 0) {
            return false;
        }
        foreach ([0 => $width, 1 => $height] as $axis => $decoded) {
            $extent = $proof['draft_box'][$axis + 2];
            $scale = $proof['original_scale'][$axis];
            if (! is_numeric($extent) || ! is_numeric($scale) || ! is_finite((float) $extent) || ! is_finite((float) $scale)
                || $extent <= 0 || $extent > $decoded || abs($sourceDimensions[$axis] / $extent - $scale) > 0.000001) {
                return false;
            }
        }

        if (($proof['method'] ?? null) === 'full') {
            return ($proof['reduced'] ?? null) === false && [$width, $height] === $sourceDimensions
                && $proof['draft_box'][2] == $width && $proof['draft_box'][3] == $height;
        }
        $scale = $proof['original_scale'][0];

        return ($proof['method'] ?? null) === 'jpeg_draft' && ($proof['reduced'] ?? null) === true
            && in_array((float) $scale, [2.0, 4.0, 8.0], true) && $proof['original_scale'][1] == $scale
            && $width === (int) ceil($sourceDimensions[0] / $scale) && $height === (int) ceil($sourceDimensions[1] / $scale);
    }

    private function validPreviewDetector(mixed $proof, array $decode, array $dimensions, string $hash): bool
    {
        $limit = (int) config('file_registry.face_preview.max_detector_dimension');
        if ($limit < 1 || ! is_array($proof) || ($proof['source_sha256'] ?? null) !== $hash
            || ($proof['source_dimensions'] ?? null) !== $dimensions
            || ($proof['decoded_dimensions'] ?? null) !== $decode['decoded_dimensions']
            || ($proof['draft_box'] ?? null) !== $decode['draft_box'] || ($proof['resize_box'] ?? null) !== $decode['draft_box']
            || ($proof['model'] ?? null) !== 'hog' || ($proof['upsample'] ?? null) !== 1) {
            return false;
        }
        $ratio = min(1, $limit / $dimensions[0], $limit / $dimensions[1]);
        $expected = [max(1, (int) round($dimensions[0] * $ratio, 0, PHP_ROUND_HALF_EVEN)), max(1, (int) round($dimensions[1] * $ratio, 0, PHP_ROUND_HALF_EVEN))];
        if (($proof['detector_dimensions'] ?? null) !== $expected
            || ($proof['reduced'] ?? null) !== ($expected !== $dimensions)
            || ! is_array($proof['original_scale'] ?? null) || count($proof['original_scale']) !== 2) {
            return false;
        }
        $resampled = $decode['decoded_dimensions'] !== $expected
            || $decode['draft_box'][2] != $expected[0] || $decode['draft_box'][3] != $expected[1];
        foreach ([0, 1] as $axis) {
            $scale = $proof['original_scale'][$axis];
            if (! is_numeric($scale) || ! is_finite((float) $scale) || abs($scale - $dimensions[$axis] / $expected[$axis]) > 0.000001) {
                return false;
            }
        }

        return ($proof['method'] ?? null) === ($resampled ? 'lanczos' : 'identity');
    }

    /** Prepare anonymous evidence only; this path never publishes face or identity rows. */
    public function previewFaces(array $selector, bool $dryRun = false, ?array $scheduledCoverage = null, ?array $nativeCoverage = null): array
    {
        if ($scheduledCoverage !== null && $nativeCoverage !== null) {
            return ['success' => false, 'selector' => $selector, 'error' => 'coverage_preview_authority_invalid'];
        }
        if (($scheduledCoverage['native_cli'] ?? false) === true
            || ($nativeCoverage !== null && ($nativeCoverage['native_cli'] ?? false) !== true)) {
            return ['success' => false, 'selector' => $selector, 'error' => 'coverage_preview_authority_invalid'];
        }
        $coverage = $scheduledCoverage ?? $nativeCoverage;
        $directory = null;
        $snapshotPath = null;
        $retainArtifacts = false;
        $failureCode = 'preview_unavailable';
        try {
            $source = $this->facePreviewSource($selector);
            if ($source === null) {
                return ['success' => false, 'selector' => $selector, 'error' => 'current_source_unavailable'];
            }
            if ($coverage !== null) {
                $this->assertCoveragePreviewAuthority($coverage, $source, $selector);
            }
            $path = $this->facePreviewLocalPath($source['current_path']);
            if ($path === null) {
                return ['success' => false, 'selector' => $selector, 'error' => 'local_source_unavailable'];
            }
            $maxBytes = max(1, (int) config('file_registry.face_preview.max_source_bytes'));
            $maxPixels = max(1, (int) config('file_registry.face_preview.max_pixels'));
            $size = @filesize($path);
            if (! is_int($size) || $size < 1 || $size > $maxBytes) {
                return ['success' => false, 'selector' => $selector, 'error' => 'source_byte_limit'];
            }
            $bytes = @file_get_contents($path, false, null, 0, $maxBytes + 1);
            $dimensions = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
            if (! is_string($bytes) || strlen($bytes) > $maxBytes || ! is_array($dimensions)
                || $dimensions[0] < 1 || $dimensions[1] < 1) {
                return ['success' => false, 'selector' => $selector, 'error' => 'source_decode_invalid'];
            }
            if ($maxPixels < $dimensions[0] * $dimensions[1] && ($dimensions[2] !== IMAGETYPE_JPEG
                || (int) config('metadata_writeback.preview_max_source_pixels') < $dimensions[0] * $dimensions[1])) {
                return ['success' => false, 'selector' => $selector, 'error' => 'source_pixel_limit',
                    'source_pixels' => $dimensions[0] * $dimensions[1], 'max_pixels' => $maxPixels];
            }
            $sourceHash = hash('sha256', $bytes);
            if ($coverage !== null && (($coverage['source_sha256'] ?? null) !== $sourceHash
                || ($coverage['asset_uuid'] ?? null) !== strtolower((string) ($source['registry']['asset_uuid'] ?? ''))
                || ! is_array($coverage['engine_provenance'] ?? null)
                || ! is_string($coverage['detector_version'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', $coverage['detector_version']))) {
                return ['success' => false, 'selector' => $selector, 'error' => 'coverage_source_binding_changed'];
            }
            if ($dryRun) {
                return ['success' => true, 'selector' => $selector, 'mode' => 'dry_run', 'detector_run' => false];
            }
            $relative = (string) config('file_registry.face_preview.private_directory');
            $failureCode = 'private_storage_unavailable';
            if (! str_starts_with($relative, 'app/private/') || str_contains($relative, '\\')
                || in_array('..', explode('/', $relative), true) || str_contains($relative, "\0")) {
                throw new \RuntimeException('Invalid private directory');
            }
            $root = storage_path($relative);
            $component = storage_path('app');
            foreach (array_slice(explode('/', $relative), 1) as $part) {
                $component .= DIRECTORY_SEPARATOR.$part;
                if (is_link($component)) {
                    throw new \RuntimeException('Private directory symlink');
                }
            }
            if (! is_dir($root) && ! mkdir($root, 0700, true)) {
                throw new \RuntimeException('Private directory unavailable');
            }
            $privateStorage = realpath(storage_path('app/private'));
            $storageApp = realpath(storage_path('app'));
            $root = realpath($root);
            if ($privateStorage === false || $storageApp === false || $privateStorage !== $storageApp.'/private'
                || $root === false || ! str_starts_with($root, $privateStorage.DIRECTORY_SEPARATOR)
                || ! chmod($root, 0700)) {
                throw new \RuntimeException('Private directory outside storage');
            }
            $directory = $root.DIRECTORY_SEPARATOR.Str::uuid()->toString();
            if (! mkdir($directory, 0700)) {
                throw new \RuntimeException('Private run unavailable');
            }
            // The detector decodes the admitted byte snapshot, never a mutable original path.
            $snapshotPath = $directory.'/source-image';
            if (file_put_contents($snapshotPath, $bytes) !== strlen($bytes) || ! chmod($snapshotPath, 0600)) {
                throw new \RuntimeException('Snapshot unavailable');
            }
            unset($bytes);
            $failureCode = 'detector_failed_or_resource_held';
            $detection = $this->detectFaces($snapshotPath, true, $directory, true);
            if (($detection['success'] ?? false) !== true) {
                $code = $detection['error_code'] ?? null;
                if (in_array($code, ['detector_timeout', 'detector_memory_limit', 'source_decode_invalid', 'source_byte_limit', 'source_pixel_limit', 'decoded_pixel_limit'], true)) {
                    $failureCode = $code;
                }
                throw new \RuntimeException('Detector held');
            }
            $failureCode = 'detector_proof_invalid';
            if (($detection['success'] ?? false) !== true || ($detection['source_sha256'] ?? null) !== $sourceHash
                || ($detection['coordinate_basis'] ?? null) !== 'encoded_pixels'
                || ($detection['face_region_convention'] ?? null) !== 'normalized_top_left'
                || ($detection['image_width'] ?? null) !== $dimensions[0] || ($detection['image_height'] ?? null) !== $dimensions[1]
                || ! is_array($detection['faces'] ?? null) || ! is_array($detection['orientation_provenance'] ?? null)) {
                throw new \RuntimeException('Detector evidence invalid');
            }
            if (! $this->validPreviewDecode($detection['decode_provenance'] ?? null, [$dimensions[0], $dimensions[1]])
                || ! $this->validPreviewDetector($detection['detector_provenance'] ?? null, $detection['decode_provenance'], [$dimensions[0], $dimensions[1]], $sourceHash)
                || ($detection['orientation_provenance']['transform'] ?? null) !== 'none'
                || ($detection['orientation_provenance']['coordinate_basis'] ?? null) !== 'encoded_pixels'
                || ! array_key_exists('exif_orientation', $detection['orientation_provenance'])
                || ! in_array($detection['orientation_provenance']['exif_orientation'] ?? null, [null, 1, 2, 3, 4, 5, 6, 7, 8], true)) {
                throw new \RuntimeException('Reduced detector proof invalid');
            }
            if ($coverage !== null) {
                $reportedEngine = $coverage['engine_provenance'];
                unset($reportedEngine['script_sha256']);
                $engineAfter = $this->anonymousGenerationEngineVersion();
                if (($detection['engine_provenance'] ?? null) !== $reportedEngine
                    || $engineAfter !== $coverage['engine_provenance']
                    || $this->anonymousPreviewDetectorVersion($engineAfter) !== $coverage['detector_version']) {
                    throw new \RuntimeException('coverage_detector_version_changed');
                }
                $this->assertCoveragePreviewAuthority($coverage, $source, $selector);
            }
            if ($dimensions[2] === IMAGETYPE_JPEG) {
                $exif = @exif_read_data($snapshotPath);
                $orientation = $exif['Orientation'] ?? 1;
                if (($detection['orientation_provenance']['exif_orientation'] ?? null) !== (in_array($orientation, range(1, 8), true) ? $orientation : null)) {
                    throw new \RuntimeException('Detector orientation proof invalid');
                }
            }
            $faces = [];
            foreach ($detection['faces'] as $index => $face) {
                if (isset($face['embedding']) || ($face['source_sha256'] ?? null) !== $sourceHash
                    || ($face['face_region_convention'] ?? null) !== 'normalized_top_left'
                    || ($face['crop_provenance']['source_sha256'] ?? null) !== $sourceHash
                    || ($face['crop_provenance']['coordinate_basis'] ?? null) !== 'detector_encoded_pixels'
                    || ($face['crop_provenance']['detector_dimensions'] ?? null) !== $detection['detector_provenance']['detector_dimensions']
                    || ($face['crop_provenance']['decoded_dimensions'] ?? null) !== $detection['decode_provenance']['decoded_dimensions']
                    || ($face['crop_provenance']['reduced'] ?? null) !== ($detection['decode_provenance']['reduced'] || $detection['detector_provenance']['reduced'])) {
                    throw new \RuntimeException('Detector provenance invalid');
                }
                $box = $face['normalized'] ?? [];
                foreach (['x', 'y', 'w', 'h'] as $axis) {
                    if (! is_numeric($box[$axis] ?? null) || ! is_finite((float) $box[$axis])) {
                        throw new \RuntimeException('Detector region invalid');
                    }
                    $box[$axis] = (float) $box[$axis];
                }
                if ($box['x'] < 0 || $box['y'] < 0 || $box['w'] <= 0 || $box['h'] <= 0
                    || $box['x'] + $box['w'] > 1 || $box['y'] + $box['h'] > 1) {
                    throw new \RuntimeException('Detector region invalid');
                }
                $location = $face['location'] ?? [];
                foreach (['left' => $box['x'] * $dimensions[0], 'top' => $box['y'] * $dimensions[1],
                    'right' => ($box['x'] + $box['w']) * $dimensions[0], 'bottom' => ($box['y'] + $box['h']) * $dimensions[1]] as $axis => $expected) {
                    if (! is_numeric($location[$axis] ?? null) || ! is_finite((float) $location[$axis]) || abs($location[$axis] - $expected) > 0.000001) {
                        throw new \RuntimeException('Detector box transform invalid');
                    }
                }
                $crop = is_string($face['crop_path'] ?? null) ? realpath($face['crop_path']) : false;
                $cropDimensions = $crop !== false ? @getimagesize($crop) : false;
                if ($crop === false || ! str_starts_with($crop, $directory.DIRECTORY_SEPARATOR)
                    || ! is_file($crop) || ! is_array($cropDimensions) || $cropDimensions[2] !== IMAGETYPE_JPEG
                    || $cropDimensions[0] < 1 || $cropDimensions[1] < 1
                    || $cropDimensions[0] > $detection['detector_provenance']['detector_dimensions'][0]
                    || $cropDimensions[1] > $detection['detector_provenance']['detector_dimensions'][1]
                    || ! chmod($crop, 0600)) {
                    throw new \RuntimeException('Private crop unavailable');
                }
                $faces[] = ['face_index' => $index, 'face_region' => array_intersect_key($box, array_flip(['x', 'y', 'w', 'h'])),
                    'face_region_convention' => 'normalized_top_left', 'coordinate_basis' => 'encoded_pixels',
                    'crop_path' => $crop, 'crop_provenance' => $face['crop_provenance'] ?? null];
            }
            clearstatcache(true, $path);
            $failureCode = 'source_changed_during_detection';
            if ($this->facePreviewSource($selector) !== $source || $this->facePreviewLocalPath($source['current_path']) !== $path
                || @hash_file('sha256', $path) !== $sourceHash) {
                throw new \RuntimeException('Source changed during detection');
            }
            $packet = [
                'schema' => $coverage === null ? 'plos.anonymous_face_preview.v1' : 'plos.anonymous_face_coverage_preview.v2',
                'operator_identity_decision_required' => true,
                'selector' => $selector, 'queue_id' => $source['queue_id'], 'media_id' => $source['media_id'],
                'tree_id' => $source['tree_id'], 'asset_uuid' => $source['registry']['asset_uuid'] ?? null,
                'current_source_verified' => true,
                'source_sha256' => $sourceHash, 'source_dimensions' => [$dimensions[0], $dimensions[1]],
                'face_region_convention' => 'normalized_top_left', 'coordinate_basis' => 'encoded_pixels',
                'orientation_provenance' => $detection['orientation_provenance'],
                'decode_provenance' => $detection['decode_provenance'] ?? null, 'faces' => $faces,
                'detector_provenance' => $detection['detector_provenance'],
                'recorded_at' => now()->utc()->toIso8601String(),
            ];
            if ($coverage !== null) {
                $packet['detector_version'] = $coverage['detector_version'];
                $packet['engine_provenance'] = $coverage['engine_provenance'];
                $packet['asset_uuid'] = strtolower((string) $coverage['asset_uuid']);
            }
            $packetPath = $directory.'/preview.json';
            $failureCode = 'private_packet_unavailable';
            if (file_put_contents($packetPath, json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false
                || ! chmod($packetPath, 0600)) {
                throw new \RuntimeException('Private packet unavailable');
            }
            @unlink($snapshotPath);
            $snapshotPath = null;

            if ($coverage !== null) {
                $this->assertCoveragePreviewAuthority($coverage, $source, $selector);
            }
            $publication = $coverage === null
                ? $this->registerAnonymousPreview($selector, $packetPath, false)
                : $this->registerEngineBoundCoveragePreview($selector, $packetPath, $source, $coverage);
            if ($nativeCoverage !== null && ($publication['success'] ?? false) !== true) {
                $failureCode = 'coverage_preview_index_not_published';
                throw new \RuntimeException('coverage_preview_index_not_published');
            }

            return ['success' => true, 'selector' => $selector, 'face_count' => count($faces), 'preview_path' => $packetPath,
                'suggestions_published' => $publication['success'], 'preview_index_key' => $publication['binding_key'] ?? null,
                'preview_reused' => $publication['reused'] ?? false];
        } catch (\Throwable $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                $retainArtifacts = true;
                throw $e;
            }
            if ($directory !== null) {
                File::deleteDirectory($directory);
            }

            return ['success' => false, 'selector' => $selector, 'error' => $failureCode];
        } finally {
            if (! $retainArtifacts && $snapshotPath !== null) {
                @unlink($snapshotPath);
            }
        }
    }

    /** Read-only exact-byte plan for one already-scanned asset. */
    public function planAnonymousFaceGeneration(string $assetUuid, ?int $selectedFaceId = null): array
    {
        try {
            $plan = $this->anonymousGenerationPlan($assetUuid, $selectedFaceId);
            if ($selectedFaceId === null) {
                $protection = $this->scheduledCoveragePreviewHoldReason($plan['file_registry_id'], $plan['generation_key']);
                if ($protection !== null && ! in_array($protection, ['prior_current_generation_reconciliation_required', 'legacy_current_generation_unknown'], true)) {
                    $plan['preflight_hold'] = $protection;
                }
            }

            return ['success' => ($plan['preflight_hold'] ?? null) === null, 'mode' => 'dry_run', 'detector_run' => false,
                'eligible' => ($plan['preflight_hold'] ?? null) === null, 'hold_reason' => $plan['preflight_hold'] ?? null,
                'asset_uuid' => $plan['asset_uuid'], 'file_registry_id' => $plan['file_registry_id'],
                'source_sha256' => $plan['source_sha256'],
                'binding_sha256' => $plan['binding_sha256'], 'detector_version' => $plan['detector_version'],
                'engine_provenance' => $plan['engine'],
                'coverage_preview_detector_version' => $this->anonymousPreviewDetectorVersion($plan['engine']),
                'source_bytes' => $plan['source_bytes'], 'source_dimensions' => $plan['source_dimensions'],
                'registry_revision' => $plan['registry_revision'], 'generation_key' => $plan['generation_key'],
                'mode_scope' => isset($plan['selected_face']) ? 'operator_selected_region' : 'whole_asset',
                'selected_face_id' => $selectedFaceId, 'selected_region' => $plan['selected_face']['region'] ?? null];
        } catch (\Throwable $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }

            return ['success' => false, 'error' => $this->anonymousGenerationError($e)];
        }
    }

    /** A scheduler may preview held photos, but never publish replacement face rows. */
    public function scheduledCoveragePreviewHoldReason(int $fileRegistryId, string $generationKey, bool $lockSourceMedia = false): ?string
    {
        if ($this->hasAnonymousSourceMediaReview($fileRegistryId, $lockSourceMedia)) {
            return 'protected_reviewed_face_present';
        }
        $faces = DB::table('file_registry_faces')->where('file_registry_id', $fileRegistryId)
            ->where('source_current', 1)->whereNull('superseded_at')->get(['id', 'source', 'source_generation_key', 'genealogy_person_id', 'person_name', 'verified', 'global_identity_uuid', 'global_identity_approval_receipt_id']);
        $hasLegacyUnknown = false;
        $hasPriorGeneration = false;
        foreach ($faces as $face) {
            if ($face->genealogy_person_id !== null || trim((string) $face->person_name) !== '' || ! empty($face->verified)
                || $face->global_identity_uuid !== null || $face->global_identity_approval_receipt_id !== null
                || in_array($face->source, ['manual', 'xmp'], true)) {
                return 'protected_named_face_present';
            }
            if ($this->hasAnonymousGenerationReview((int) $face->id)) {
                return 'protected_reviewed_face_present';
            }
            if ($face->source === 'ai_detection' && ($face->source_generation_key === null
                || $face->source_generation_key === '' || $face->source_generation_key === 'legacy')) {
                $hasLegacyUnknown = true;
            }
            if ($face->source === 'ai_detection' && $face->source_generation_key !== $generationKey) {
                $hasPriorGeneration = true;
            }
        }

        try {
            $this->assertAutomaticAssignmentSourceResolved($fileRegistryId);
        } catch (\Throwable) {
            return 'automatic_assignment_custody_unresolved';
        }

        if ($hasLegacyUnknown) {
            return 'legacy_current_generation_unknown';
        }
        if ($hasPriorGeneration) {
            return 'prior_current_generation_reconciliation_required';
        }

        return null;
    }

    /** Exact stored source aliases; lock media before its review ranges at writer boundaries. */
    public function anonymousSourceMediaIds(int $fileRegistryId, bool $lock = false): array
    {
        $registry = DB::table('file_registry')->where('id', $fileRegistryId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($registry === null) {
            throw new \RuntimeException('current_source_unavailable');
        }
        $paths = array_values(array_unique(array_filter([$registry->current_path ?? null, $registry->original_path ?? null],
            fn ($path) => is_string($path) && $path !== '')));
        if ($paths === []) {
            throw new \RuntimeException('current_source_unavailable');
        }
        $limit = max(1, (int) config('file_registry.face_generation.max_faces_per_asset'));
        $media = DB::table('genealogy_media')->where(fn ($query) => $query->whereIn('nextcloud_path', $paths)
            ->orWhereIn('original_path', $paths))->orderBy('id')->limit($limit + 1)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get(['id']);
        if ($media->count() > $limit) {
            throw new \RuntimeException('generation_source_media_resource_limit');
        }

        return $media->pluck('id')->all();
    }

    private function hasAnonymousSourceMediaReview(int $fileRegistryId, bool $lock = false): bool
    {
        $mediaIds = $this->anonymousSourceMediaIds($fileRegistryId, $lock);

        // Any queue state reserves the source; a null face FK cannot erase a rejection.
        return DB::table('genealogy_face_match_queue')->whereIn('media_id', $mediaIds)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first(['media_id']) !== null;
    }

    /** Hold legacy face publication when rows being retired carry protected or unknown source links. */
    public function legacySourceDriftHoldReason(int $fileRegistryId, string $newSourceSha256, bool $lockRegistry = false): ?string
    {
        if ($fileRegistryId < 1 || ! preg_match('/^[a-f0-9]{64}$/D', $newSourceSha256)) {
            return 'source_drift_binding_invalid';
        }

        $limit = max(1, (int) config('file_registry.profile_refresh.max_source_faces', 10000));

        try {
            $this->assertAutomaticAssignmentSourceResolved($fileRegistryId, $lockRegistry);
        } catch (\Throwable) {
            return 'automatic_assignment_custody_unresolved';
        }

        try {
            $driftingFaces = DB::table('file_registry_faces')->where('file_registry_id', $fileRegistryId)
                ->where('source_current', 1)
                ->where(function ($query) use ($newSourceSha256): void {
                    $query->whereNull('source_sha256')->orWhere('source_sha256', '<>', $newSourceSha256);
                })->orderBy('id')->limit($limit + 1)->lockForUpdate()
                ->get(['id', 'source', 'source_sha256', 'source_generation_key', 'genealogy_person_id', 'person_name',
                    'verified', 'hidden', 'favorite', 'cluster_id', 'global_identity_uuid', 'global_identity_approval_receipt_id']);
            if ($driftingFaces->count() > $limit) {
                return 'source_drift_scope_too_large';
            }

            $driftingFaceIds = [];
            foreach ($driftingFaces as $face) {
                if (! is_string($face->source_sha256) || ! preg_match('/^[a-f0-9]{64}$/D', $face->source_sha256)
                    || $this->legacyDriftFaceSourceUnknown($face)) {
                    return 'source_drift_source_unknown';
                }
                $driftingFaceIds[] = (int) $face->id;
                if ($this->legacyDriftFaceIsProtected($face) || $this->legacyDriftFaceHasProtectedLink((int) $face->id)) {
                    return 'source_drift_protected_face';
                }
            }

            $vectors = DB::connection('pgsql_rag')->table('face_embeddings')
                ->where('source_current', true)
                ->where(function ($query) use ($fileRegistryId, $driftingFaceIds): void {
                    $query->where('file_registry_id', $fileRegistryId);
                    if ($driftingFaceIds !== []) {
                        $query->orWhereIn('file_registry_face_id', $driftingFaceIds);
                    }
                })->where(function ($query) use ($newSourceSha256): void {
                    $query->whereNull('source_sha256')->orWhere('source_sha256', '<>', $newSourceSha256);
                })->orderBy('id')->limit($limit + 1)
                ->get(['id', 'file_registry_id', 'file_registry_face_id', 'person_cluster_id', 'source_sha256']);
            if ($vectors->count() > $limit) {
                return 'source_drift_scope_too_large';
            }

            foreach ($vectors as $vector) {
                if (! is_string($vector->source_sha256) || ! preg_match('/^[a-f0-9]{64}$/D', $vector->source_sha256)) {
                    return 'source_drift_source_unknown';
                }
                if ($vector->person_cluster_id !== null || $vector->file_registry_face_id === null) {
                    return 'source_drift_protected_vector';
                }
                $linkedFace = DB::table('file_registry_faces')->where('id', $vector->file_registry_face_id)
                    ->first(['id', 'file_registry_id', 'source', 'source_sha256', 'source_generation_key', 'genealogy_person_id', 'person_name', 'verified', 'hidden', 'favorite',
                        'cluster_id', 'global_identity_uuid', 'global_identity_approval_receipt_id']);
                if ($linkedFace !== null && (int) $linkedFace->file_registry_id !== $fileRegistryId) {
                    return 'source_drift_vector_face_binding_mismatch';
                }
                if ($linkedFace === null || ! is_string($linkedFace->source_sha256)
                    || ! preg_match('/^[a-f0-9]{64}$/D', $linkedFace->source_sha256)
                    || $this->legacyDriftFaceSourceUnknown($linkedFace)
                    || $this->legacyDriftFaceIsProtected($linkedFace)
                    || $this->legacyDriftFaceHasProtectedLink((int) $linkedFace->id)) {
                    return $linkedFace === null || $this->legacyDriftFaceSourceUnknown($linkedFace)
                        ? 'source_drift_source_unknown' : 'source_drift_protected_vector';
                }
            }
        } catch (\Throwable) {
            return 'source_drift_protection_unavailable';
        }

        return null;
    }

    private function legacyDriftFaceIsProtected(object $face): bool
    {
        return $face->genealogy_person_id !== null || trim((string) $face->person_name) !== ''
            || ! empty($face->verified) || ! empty($face->hidden) || ! empty($face->favorite)
            || $face->cluster_id !== null || $face->global_identity_uuid !== null
            || $face->global_identity_approval_receipt_id !== null
            || $face->source !== 'ai_detection';
    }

    private function legacyDriftFaceSourceUnknown(object $face): bool
    {
        return $face->source === 'ai_detection'
            && (! is_string($face->source_generation_key) || ! preg_match('/^[a-f0-9]{64}$/D', $face->source_generation_key));
    }

    private function legacyDriftFaceHasProtectedLink(int $faceId): bool
    {
        return DB::table('face_identity_provenance')->where('file_registry_face_id', $faceId)->exists()
            || DB::table('person_face_profile_faces')->where('file_registry_face_id', $faceId)->exists()
            || (Schema::hasTable('person_face_appearance_profiles')
                && DB::table('person_face_appearance_profiles')->where('representative_file_registry_face_id', $faceId)->exists())
            || DB::table('genealogy_face_match_queue')->where('file_registry_face_id', $faceId)->exists()
            || DB::table('agent_review_queue')->where('review_type', 'global_face_identity')
                ->where('details->global_identity_decision->face_id', $faceId)->exists()
            || $this->hasAnonymousGenerationReview($faceId);
    }

    /** Return an exact source+engine index without probing Python or mutating it. */
    public function findScheduledCoveragePreview(string $assetUuid, string $sourceSha256, string $detectorVersion): ?array
    {
        if (! Str::isUuid($assetUuid) || ! preg_match('/^[a-f0-9]{64}$/D', $sourceSha256)
            || ! preg_match('/^[a-f0-9]{64}$/D', $detectorVersion)) {
            return null;
        }
        $bindingKey = $this->scheduledCoveragePreviewIndexKey($assetUuid, $sourceSha256, $detectorVersion);
        try {
            $row = DB::table('anonymous_face_preview_indexes')->where('binding_key', $bindingKey)->first();
        } catch (\Throwable) {
            return null;
        }
        if ($row === null || strtolower((string) $row->asset_uuid) !== strtolower($assetUuid)
            || $row->source_sha256 !== $sourceSha256 || $row->detector_version !== $detectorVersion
            || $row->selector_json !== json_encode(['asset_uuid' => strtolower($assetUuid)], JSON_THROW_ON_ERROR)
            || ! is_string($row->engine_provenance_json) || ! is_string($row->projection_json)
            || ! preg_match('/^[a-f0-9]{64}$/D', (string) $row->projection_sha256)
            || hash('sha256', $row->projection_json) !== $row->projection_sha256) {
            return null;
        }
        $projection = json_decode($row->projection_json, true);
        $engine = json_decode($row->engine_provenance_json, true);
        if (! $this->validAnonymousPreviewProjection($projection, $sourceSha256, $projection['source_dimensions'] ?? null)
            || ! is_array($engine) || $this->anonymousPreviewDetectorVersion($engine) !== $detectorVersion) {
            return null;
        }

        return ['binding_key' => $bindingKey, 'detector_version' => $detectorVersion,
            'face_count' => count($projection['regions']), 'source_sha256' => $sourceSha256];
    }

    /** Scheduler-only boundary. The supplied authority is revalidated throughout preview and index publication. */
    public function previewScheduledCoverage(array $selector, array $plan, array $authority): array
    {
        $engine = $plan['engine_provenance'] ?? null;
        $version = $plan['coverage_preview_detector_version'] ?? null;
        if (! is_array($engine) || ! is_string($version)) {
            return ['success' => false, 'error' => 'coverage_detector_proof_unavailable'];
        }

        return $this->previewFaces($selector, false, [
            'authority' => $authority, 'asset_uuid' => strtolower((string) ($plan['asset_uuid'] ?? '')),
            'source_sha256' => $plan['source_sha256'] ?? null, 'engine_provenance' => $engine,
            'detector_version' => $version,
        ]);
    }

    /** Read-only native CLI plan for one unprotected current registry asset. */
    public function planAnonymousCoveragePreview(string $assetUuid): array
    {
        try {
            $plan = $this->anonymousCoveragePreviewPlan($assetUuid);

            return $plan;
        } catch (\Throwable $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }

            return ['success' => false, 'eligible' => false, 'mode' => 'dry_run', 'detector_run' => false,
                'identity_writes' => false, 'source_sha256' => null, 'error' => $this->anonymousGenerationError($error)];
        }
    }

    /** Execute an exact native CLI-approved anonymous preview plan. */
    public function prepareAnonymousCoveragePreview(array $plan): array
    {
        try {
            $this->assertNativeCoveragePreviewPlan($plan);
            $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
            $this->assertNativeCoveragePreviewCaller($caller, $plan);
            $current = $this->anonymousCoveragePreviewPlan($plan['asset_uuid']);
            if ($current !== $plan) {
                throw new \RuntimeException('coverage_preview_plan_changed');
            }
            $context = ['native_cli' => true, 'caller' => $caller, 'plan' => $plan,
                'asset_uuid' => $plan['asset_uuid'], 'source_sha256' => $plan['source_sha256'],
                'engine_provenance' => $plan['engine_provenance'],
                'detector_version' => $plan['coverage_preview_detector_version']];

            $receipt = $this->previewFaces(['asset_uuid' => $plan['asset_uuid']], false, null, $context);
            $expectedKey = $this->scheduledCoveragePreviewIndexKey($plan['asset_uuid'], $plan['source_sha256'],
                $plan['coverage_preview_detector_version']);
            $stored = $this->findScheduledCoveragePreview($plan['asset_uuid'], $plan['source_sha256'],
                $plan['coverage_preview_detector_version']);
            $published = ($receipt['success'] ?? false) === true && ($receipt['suggestions_published'] ?? false) === true
                && ($receipt['preview_index_key'] ?? null) === $expectedKey
                && ($stored['binding_key'] ?? null) === $expectedKey;

            return ['success' => $published,
                'state' => $published ? 'complete' : 'held',
                'source_sha256' => $plan['source_sha256'],
                'detector_version' => $plan['coverage_preview_detector_version'],
                'face_count' => is_int($receipt['face_count'] ?? null) ? $receipt['face_count'] : null,
                'preview_index_key' => $published ? $expectedKey : null,
                'preview_reused' => ($receipt['preview_reused'] ?? false) === true,
                'suggestions_published' => $published,
                'identity_writes' => false, 'generation_rows_written' => false,
                ...($published ? [] : ['error' => $receipt['error'] ?? 'coverage_preview_index_unavailable'])];
        } catch (\Throwable $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }

            return ['success' => false, 'state' => 'held', 'error' => $this->anonymousGenerationError($error),
                'identity_writes' => false, 'generation_rows_written' => false];
        }
    }

    private function anonymousCoveragePreviewPlan(string $assetUuid): array
    {
        $generation = $this->anonymousGenerationPlan($assetUuid);
        if (isset($generation['selected_face']) || ! in_array($generation['preflight_hold'], [null, 'prior_current_generation_reconciliation_required'], true)) {
            throw new \RuntimeException($generation['preflight_hold'] ?? 'coverage_preview_scope_invalid');
        }
        $protection = $this->scheduledCoveragePreviewHoldReason($generation['file_registry_id'], $generation['generation_key']);
        if ($protection !== null && $protection !== 'prior_current_generation_reconciliation_required') {
            throw new \RuntimeException($protection);
        }
        $engine = $generation['engine'];
        $plan = ['schema' => 'plos.anonymous_face_native_coverage_preview.v1',
            'asset_uuid' => $generation['asset_uuid'], 'file_registry_id' => $generation['file_registry_id'],
            'source_sha256' => $generation['source_sha256'], 'binding_sha256' => $generation['binding_sha256'],
            'generation_key' => $generation['generation_key'], 'detector_version' => $generation['detector_version'],
            'coverage_preview_detector_version' => $this->anonymousPreviewDetectorVersion($engine),
            'engine_provenance' => $engine, 'success' => true, 'eligible' => true, 'mode' => 'dry_run',
            'detector_run' => false, 'source_originals_mutated' => false, 'identity_writes' => false];
        $plan['plan_sha256'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $plan;
    }

    private function assertNativeCoveragePreviewPlan(array $plan): void
    {
        $required = ['schema', 'asset_uuid', 'file_registry_id', 'source_sha256', 'binding_sha256', 'generation_key',
            'detector_version', 'coverage_preview_detector_version', 'engine_provenance', 'success', 'eligible', 'mode',
            'detector_run', 'source_originals_mutated', 'identity_writes', 'plan_sha256'];
        $unsigned = $plan;
        unset($unsigned['plan_sha256']);
        if (array_diff(array_keys($plan), $required) !== [] || array_diff($required, array_keys($plan)) !== []
            || ($plan['schema'] ?? null) !== 'plos.anonymous_face_native_coverage_preview.v1'
            || ! Str::isUuid($plan['asset_uuid'] ?? '') || strtolower($plan['asset_uuid']) !== $plan['asset_uuid']
            || ! is_int($plan['file_registry_id'] ?? null) || $plan['file_registry_id'] < 1
            || ! is_bool($plan['success'] ?? null) || $plan['success'] !== true
            || ! is_bool($plan['eligible'] ?? null) || $plan['eligible'] !== true
            || ($plan['mode'] ?? null) !== 'dry_run' || ($plan['detector_run'] ?? null) !== false
            || ($plan['source_originals_mutated'] ?? null) !== false || ($plan['identity_writes'] ?? null) !== false
            || ! is_array($plan['engine_provenance'] ?? null)
            || ! $this->validAnonymousGenerationEngine($plan['engine_provenance'])
            || ! hash_equals((string) ($plan['plan_sha256'] ?? ''), hash('sha256', json_encode($unsigned, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))) {
            throw new \RuntimeException('coverage_preview_plan_invalid');
        }
        foreach (['source_sha256', 'binding_sha256', 'generation_key', 'detector_version', 'coverage_preview_detector_version', 'plan_sha256'] as $hash) {
            if (! is_string($plan[$hash] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $plan[$hash])) {
                throw new \RuntimeException('coverage_preview_plan_invalid');
            }
        }
        if ($this->anonymousPreviewDetectorVersion($plan['engine_provenance']) !== $plan['coverage_preview_detector_version']) {
            throw new \RuntimeException('coverage_preview_plan_invalid');
        }
    }

    private function assertNativeCoveragePreviewCaller(mixed $caller, array $plan): void
    {
        if (! $this->validAnonymousGenerationCaller($caller)
            || ! hash_equals($caller['arguments_sha256'], hash('sha256', json_encode(['plan' => $plan], JSON_THROW_ON_ERROR)))) {
            throw new \RuntimeException('trusted_framework_caller_required');
        }
    }

    /**
     * Generate source-bound anonymous rows after the registered CLI caller has
     * approved this exact action. No person, name, FT link, or cluster is written.
     */
    public function restoreAnonymousFaceGeneration(
        string $assetUuid,
        string $sourceSha256,
        string $bindingSha256,
        string $idempotencyKey,
        ?int $selectedFaceId = null,
        ?int $minimumFaceCount = null,
    ): array {
        return $this->executeAnonymousFaceGeneration($assetUuid, $sourceSha256, $bindingSha256, $idempotencyKey,
            null, null, $selectedFaceId, $minimumFaceCount);
    }

    /** Fresh CLI approval for the unchanged packet under a renewed registry revision. */
    public function resumeAnonymousFaceGeneration(string $assetUuid, string $sourceSha256, string $bindingSha256, string $idempotencyKey, string $originalRunUuid, ?int $selectedFaceId = null): array
    {
        return $this->executeAnonymousFaceGeneration($assetUuid, $sourceSha256, $bindingSha256, $idempotencyKey, null, $originalRunUuid, $selectedFaceId);
    }

    /** Separate, lease-fenced scheduled authority; never manufactures CLI approval. */
    public function restoreScheduledAnonymousFaceGeneration(string $assetUuid, string $sourceSha256, string $bindingSha256, string $idempotencyKey, array $authority): array
    {
        return $this->executeAnonymousFaceGeneration($assetUuid, $sourceSha256, $bindingSha256, $idempotencyKey, $authority);
    }

    private function executeAnonymousFaceGeneration(string $assetUuid, string $sourceSha256, string $bindingSha256, string $idempotencyKey, ?array $authority = null, ?string $resumeRunUuid = null, ?int $selectedFaceId = null, ?int $minimumFaceCount = null): array
    {
        $run = null;
        $leaseToken = null;
        $directory = null;
        $intentAttempted = false;
        $detectedAttempted = false;
        $assertAuthority = function () use ($authority): void {
            if ($authority !== null) {
                app(FileRegistryFaceCoverageService::class)->assertAuthority($authority);
            }
        };
        try {
            $assertAuthority();
            if ($authority !== null && (($authority['generation']['asset_uuid'] ?? null) !== $assetUuid
                || ($authority['generation']['source_sha256'] ?? null) !== $sourceSha256
                || ($authority['generation']['binding_sha256'] ?? null) !== $bindingSha256)) {
                throw new \RuntimeException('scheduled_generation_binding_invalid');
            }
            if (! Str::isUuid($assetUuid) || ! preg_match('/^[a-f0-9]{64}$/D', $sourceSha256)
                || ! preg_match('/^[a-f0-9]{64}$/D', $bindingSha256) || ! Str::isUuid($idempotencyKey)) {
                throw new \RuntimeException('restore_request_invalid');
            }
            if ($minimumFaceCount !== null && ($minimumFaceCount < 0
                || $minimumFaceCount > max(1, (int) config('file_registry.face_generation.max_faces_per_asset')))) {
                throw new \RuntimeException('restore_request_invalid');
            }
            $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
            if ($authority === null && ! $this->validAnonymousGenerationCaller($caller)) {
                throw new \RuntimeException('trusted_framework_caller_required');
            }
            $callerHash = hash('sha256', json_encode($authority ?? [
                $caller['session_id'], $caller['turn_id'], $caller['action_id'],
                $caller['arguments_sha256'], $caller['approval_scope_sha256'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $unknown = DB::table('file_registry_face_generation_runs')->where('asset_uuid', strtolower($assetUuid))
                ->where(fn ($query) => $query->where('stage', 'detector_running')->orWhere('failure_code', 'generation_terminal_unknown'))
                ->exists();
            if ($unknown) {
                throw new InferenceAdmissionException('Generation detector terminal state is UNKNOWN; engine planning and dispatch held.');
            }
            $plan = $this->anonymousGenerationPlan($assetUuid, $selectedFaceId);
            if (! hash_equals($sourceSha256, $plan['source_sha256'])
                || ! hash_equals($bindingSha256, $plan['binding_sha256'])) {
                if ($selectedFaceId === null && $resumeRunUuid === null && ! hash_equals($sourceSha256, $plan['source_sha256'])) {
                    $this->holdStaleAnonymousGeneration($idempotencyKey);
                }
                throw new \RuntimeException('source_binding_changed');
            }
            if (($plan['preflight_hold'] ?? null) !== null) {
                throw new \RuntimeException($plan['preflight_hold']);
            }
            $generationKey = hash('sha256', json_encode([
                'schema' => 'plos.anonymous_face_generation.v1', 'asset_uuid' => $assetUuid,
                'source_sha256' => $sourceSha256,
                'detector_version' => $plan['detector_version'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            [$run, $leaseToken, $replayed] = $resumeRunUuid === null
                ? $this->claimAnonymousGeneration($plan, $generationKey, $idempotencyKey, $callerHash)
                : $this->claimAnonymousGenerationResume($plan, $idempotencyKey, $resumeRunUuid, $callerHash, $caller);
            if ($replayed) {
                return ['success' => true, 'replayed' => true, 'state' => 'complete',
                    'run_uuid' => $run->run_uuid, 'asset_uuid' => $assetUuid,
                    'source_sha256' => $sourceSha256, 'face_count' => (int) $run->face_count];
            }

            $root = $this->anonymousPreviewPrivateRoot();
            if ($root === null) {
                throw new \RuntimeException('private_storage_unavailable');
            }
            $directory = $root.DIRECTORY_SEPARATOR.$run->run_uuid;
            if (! is_dir($directory) && ! mkdir($directory, 0700)) {
                throw new \RuntimeException('private_storage_unavailable');
            }
            if (! $this->anonymousPreviewPrivatePath($directory, $root) || ! is_dir($directory)
                || ! chmod($directory, 0700)) {
                throw new \RuntimeException('private_storage_unavailable');
            }
            $packetPath = $directory.'/generation.json';
            $packet = $this->loadAnonymousGenerationPacket($packetPath, $root, $run);
            if ($packet === null) {
                if ($resumeRunUuid !== null) {
                    throw new \RuntimeException('resume_packet_invalid');
                }
                if (($run->stage ?? null) !== 'authorized') {
                    throw new \RuntimeException('staged_generation_packet_unavailable');
                }
                if (! $this->anonymousGenerationSourceCurrent($plan, true)) {
                    throw new \RuntimeException('source_binding_changed');
                }
                $snapshotPath = $directory.'/source-image';
                if (file_put_contents($snapshotPath, $plan['source_bytes_raw'], LOCK_EX) !== strlen($plan['source_bytes_raw'])
                    || ! chmod($snapshotPath, 0600)) {
                    throw new \RuntimeException('private_snapshot_unavailable');
                }
                $this->assertAnonymousGenerationScriptCurrent($plan);
                $assertAuthority();
                $intentAttempted = true;
                try {
                    $intent = DB::table('file_registry_face_generation_runs')->where('id', $run->id)
                        ->where('lease_token', $leaseToken)->where('stage', 'authorized')
                        ->update(['stage' => 'detector_running', 'failure_code' => 'generation_terminal_unknown', 'updated_at' => now()]);
                } catch (\Throwable $error) {
                    if (InferenceAdmissionException::isAdmissionFailure($error)) {
                        throw $error;
                    }
                    throw new InferenceAdmissionException('Generation detector intent publication is unproven; lease and evidence retained.', 0, $error);
                }
                if ($intent !== 1) {
                    $intentAttempted = false;
                    throw new \RuntimeException('generation_detector_intent_unavailable');
                }
                $run->stage = 'detector_running';
                $detection = $this->detectFaces($snapshotPath, false, $directory, false, false, true, $plan['selected_face']['region'] ?? null);
                @unlink($snapshotPath);
                $packet = $this->validatedAnonymousGenerationPacket($detection, $plan, $run, $generationKey);
                $packetBytes = json_encode($packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $maxPacketBytes = max(1, (int) config('file_registry.face_generation.max_packet_bytes'));
                if (strlen($packetBytes) > $maxPacketBytes
                    || file_put_contents($packetPath, $packetBytes, LOCK_EX) !== strlen($packetBytes)
                    || ! chmod($packetPath, 0600)) {
                    throw new \RuntimeException('generation_packet_unavailable');
                }
                $packetSha256 = hash('sha256', $packetBytes);
                $detectedAttempted = true;
                try {
                    $detected = DB::table('file_registry_face_generation_runs')->where('id', $run->id)
                        ->where('lease_token', $leaseToken)->where('stage', 'detector_running')
                        ->update(['stage' => 'detected', 'packet_sha256' => $packetSha256,
                            'face_count' => count($packet['faces']), 'updated_at' => now()]);
                } catch (\Throwable $error) {
                    if (InferenceAdmissionException::isAdmissionFailure($error)) {
                        throw $error;
                    }
                    throw new InferenceAdmissionException('Generation detector completion publication is unproven; evidence and lease retained.', 0, $error);
                }
                if ($detected !== 1) {
                    throw new InferenceAdmissionException('Generation detector completion publication is unproven; evidence and lease retained.');
                }
                $run->stage = 'detected';
                $run->packet_sha256 = $packetSha256;
                $run->face_count = count($packet['faces']);
            }
            if (! $this->anonymousGenerationSourceCurrent($plan)) {
                throw new \RuntimeException('source_binding_changed');
            }
            if ($minimumFaceCount !== null && count($packet['faces']) < $minimumFaceCount) {
                throw new \RuntimeException('generation_quality_count_drop');
            }
            // Only the bounded proof packet survives successful publication.
            $artifactCount = 0;
            foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $artifact) {
                if (++$artifactCount > max(1, (int) config('file_registry.face_generation.max_faces_per_asset')) + 1) {
                    throw new \RuntimeException('generation_packet_unavailable');
                }
                if ($artifact->getPathname() === $packetPath) {
                    continue;
                }
                $removed = $artifact->isDir() && ! $artifact->isLink()
                    ? File::deleteDirectory($artifact->getPathname()) : @unlink($artifact->getPathname());
                if (! $removed) {
                    throw new \RuntimeException('generation_packet_unavailable');
                }
            }
            if (! isset($plan['selected_face'])) {
                $this->assertNoAnonymousGenerationReviewConflict((int) $plan['file_registry_id'], $packet['faces'], $sourceSha256, $generationKey);
            }
            $assertAuthority();
            $faceIds = $this->publishAnonymousGenerationRows($plan, $run, $packet, $generationKey, $leaseToken, $assertAuthority, $resumeRunUuid !== null);
            $run->stage = 'mysql_published';
            if (! $this->anonymousGenerationSourceCurrent($plan)) {
                if (! isset($plan['selected_face']) && ($resumeRunUuid === null || @hash_file('sha256', $plan['local_path']) !== $plan['source_sha256'])) {
                    $this->retireAnonymousGenerationRows($run, $faceIds);
                }
                throw new \RuntimeException('source_binding_changed');
            }
            $assertAuthority();
            $this->publishAnonymousGenerationVectors($plan, $packet, $faceIds, $assertAuthority);
            $completed = DB::transaction(function () use ($plan, $run, $leaseToken, $faceIds, $assertAuthority): bool {
                $assertAuthority();
                $registry = DB::table('file_registry')->where('id', $plan['file_registry_id'])->lockForUpdate()->first();
                if (! $this->anonymousGenerationSourceCurrent($plan, false, $registry)) {
                    return false;
                }
                if (! isset($plan['selected_face']) && $this->hasAnonymousSourceMediaReview((int) $plan['file_registry_id'], true)) {
                    throw new \RuntimeException('protected_reviewed_face_present');
                }

                return DB::table('file_registry_face_generation_runs')->where('id', $run->id)
                    ->where('lease_token', $leaseToken)->where('stage', 'mysql_published')
                    ->update(['stage' => 'complete', 'mysql_face_ids' => json_encode($faceIds, JSON_THROW_ON_ERROR),
                        'failure_code' => null, 'lease_token' => null, 'lease_expires_at' => null,
                        'completed_at' => now(), 'updated_at' => now()]) === 1;
            });
            if (! $completed) {
                if (! isset($plan['selected_face']) && ($resumeRunUuid === null || @hash_file('sha256', $plan['local_path']) !== $plan['source_sha256'])) {
                    $this->retireAnonymousGenerationRows($run, $faceIds);
                }
                throw new \RuntimeException('source_changed_after_vector_publication');
            }
            // Retain the bounded private packet: its exact engine/limits commitment
            // is the future vector's provenance. The source snapshot was removed.

            return ['success' => true, 'replayed' => false, 'state' => 'complete',
                'run_uuid' => $run->run_uuid, 'asset_uuid' => $assetUuid,
                'source_sha256' => $sourceSha256, 'face_count' => count($faceIds)];
        } catch (\Throwable $e) {
            $terminalHold = InferenceAdmissionException::isAdmissionFailure($e);
            if ($terminalHold || ($run->stage ?? null) === 'detector_running') {
                if ($run !== null && $leaseToken !== null && ! ($intentAttempted && $run->stage === 'authorized')) {
                    try {
                        DB::table('file_registry_face_generation_runs')->where('id', $run->id)
                            ->where('lease_token', $leaseToken)->whereIn('stage', $detectedAttempted && $run->stage === 'detector_running'
                                ? ['detector_running', 'detected'] : [$run->stage])
                            ->update(['stage' => 'held', 'failure_code' => 'generation_terminal_unknown', 'updated_at' => now()]);
                    } catch (\Throwable) {
                        // Persisted detector intent remains non-replayable even if hold publication fails.
                    }
                }
                if ($terminalHold) {
                    throw $e;
                }

                return ['success' => false, 'state' => 'held', 'run_uuid' => $run->run_uuid,
                    'error' => $this->anonymousGenerationError($e)];
            }
            $error = $this->anonymousGenerationError($e);
            if ($run !== null && $leaseToken !== null) {
                $held = $error === 'source_binding_changed' || $error === 'source_changed_after_vector_publication'
                    || $error === 'protected_reviewed_face_present'
                    || str_starts_with($error, 'review_conflict')
                    || $error === 'prior_current_generation_reconciliation_required'
                    || $error === 'generation_quality_count_drop';
                $persistedError = DB::table('file_registry_face_generation_runs')->where('id', $run->id)->value('failure_code');
                if ($persistedError === 'cross_store_retirement_pending') {
                    $error = $persistedError;
                    $held = true;
                }
                DB::table('file_registry_face_generation_runs')->where('id', $run->id)
                    ->where('lease_token', $leaseToken)->where('stage', $run->stage)->update([
                        'stage' => $held ? 'held' : $run->stage,
                        'failure_code' => $error, 'lease_token' => null, 'lease_expires_at' => null,
                        'updated_at' => now(),
                    ]);
            }

            return ['success' => false, 'state' => $held ?? false ? 'held' : ($run->stage ?? 'held'),
                'run_uuid' => $run->run_uuid ?? null, 'error' => $error];
        }
    }

    /** Return the exact current source binding; this performs no detector or database writes. */
    private function anonymousGenerationPlan(string $assetUuid, ?int $selectedFaceId = null): array
    {
        if (! Str::isUuid($assetUuid)) {
            throw new \RuntimeException('asset_uuid_invalid');
        }
        $source = $this->facePreviewSource(['asset_uuid' => strtolower($assetUuid)]);
        if ($source === null || ! is_array($source['registry'] ?? null)) {
            throw new \RuntimeException('current_source_unavailable');
        }
        $path = $this->facePreviewLocalPath($source['current_path'] ?? null);
        if ($path === null) {
            throw new \RuntimeException('local_source_unavailable');
        }
        $maxBytes = max(1, (int) config('file_registry.face_preview.max_source_bytes'));
        $size = @filesize($path);
        $bytes = @file_get_contents($path, false, null, 0, $maxBytes + 1);
        $dimensions = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        if (! is_int($size) || $size < 1 || $size > $maxBytes || ! is_string($bytes)
            || strlen($bytes) !== $size || strlen($bytes) > $maxBytes || ! is_array($dimensions)
            || $dimensions[0] < 1 || $dimensions[1] < 1) {
            throw new \RuntimeException('source_resource_limit');
        }
        $pixels = $dimensions[0] * $dimensions[1];
        if ($pixels > max(1, (int) config('metadata_writeback.preview_max_source_pixels'))
            || ($pixels > max(1, (int) config('file_registry.face_preview.max_pixels')) && $dimensions[2] !== IMAGETYPE_JPEG)) {
            throw new \RuntimeException('source_resource_limit');
        }
        $engine = $this->anonymousGenerationEngineVersion();
        $sourceHash = hash('sha256', $bytes);
        $registry = $source['registry'];
        $registryRevision = DB::table('file_registry')->where('id', $registry['id'])->value('updated_at');
        if (! is_string($registryRevision) || $registryRevision === '') {
            throw new \RuntimeException('source_binding_unavailable');
        }
        $registry['updated_at'] = $registryRevision;
        $resourceLimits = ['bytes' => $maxBytes, 'pixels' => (int) config('file_registry.face_preview.max_pixels'),
            'detector_dimension' => (int) config('file_registry.face_preview.max_detector_dimension'),
            'max_faces' => (int) config('file_registry.face_generation.max_faces_per_asset')];
        $selected = $selectedFaceId === null ? null : $this->selectedGenerationFace((int) $registry['id'], $selectedFaceId, $sourceHash);
        if ($selected !== null) {
            $reviewProof = $selected['review_source'];
            if (($reviewProof['source_path'] ?? null) !== (string) $source['current_path']
                || ($reviewProof['source_dimensions'] ?? null) !== [(int) $dimensions[0], (int) $dimensions[1]]) {
                throw new \RuntimeException('selected_face_review_source_mismatch');
            }
            $engine['detector'] = 'operator_selected_region';
        }
        $detectorBinding = ['schema' => 'plos.anonymous_face_detector.v1', 'engine' => $engine, 'limits' => $resourceLimits];
        if ($selected !== null) {
            $detectorBinding['selected_face'] = $selected;
        }
        $detectorVersion = hash('sha256', json_encode($detectorBinding, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $generationKey = hash('sha256', json_encode([
            'schema' => 'plos.anonymous_face_generation.v1', 'asset_uuid' => strtolower($assetUuid),
            'source_sha256' => $sourceHash, 'detector_version' => $detectorVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $preflightHold = DB::table('file_registry_faces')->where('file_registry_id', $registry['id'])
            ->where('source', 'ai_detection')->where('source_current', 1)->whereNull('superseded_at')
            ->where('source_generation_key', '!=', $generationKey)->exists()
                    ? 'prior_current_generation_reconciliation_required' : null;
        if ($selected !== null) {
            $preflightHold = null; // One reviewed observation; no cohort replacement.
        }
        if ($this->anonymousReconciliationPending((int) $registry['id'])) {
            $preflightHold = 'anonymous_generation_reconciliation_pending';
        }
        if ($selected === null && $this->generationTileResourceLimit(
            (int) $dimensions[0], (int) $dimensions[1],
            (int) $resourceLimits['detector_dimension'], (int) $resourceLimits['pixels'])) {
            $preflightHold = 'generation_tile_resource_limit';
        }
        $binding = ['schema' => 'plos.anonymous_face_generation_binding.v1',
            'file_registry_id' => (int) $registry['id'], 'asset_uuid' => strtolower($assetUuid),
            'registry_revision' => (string) ($registry['updated_at'] ?? ''),
            'path_sha256' => hash('sha256', (string) $source['current_path']),
            'content_hash' => $registry['content_hash'] ?? null,
            'nextcloud_modified_at' => $registry['nextcloud_modified_at'] ?? null,
            'source_sha256' => $sourceHash, 'detector_version' => $detectorVersion];
        $bindingJson = json_encode($binding, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return ['asset_uuid' => strtolower($assetUuid), 'file_registry_id' => (int) $registry['id'],
            'current_path' => (string) $source['current_path'], 'local_path' => $path,
            'registry_revision' => $binding['registry_revision'], 'source_sha256' => $sourceHash,
            'source_bytes' => strlen($bytes), 'source_bytes_raw' => $bytes,
            'source_dimensions' => [(int) $dimensions[0], (int) $dimensions[1]],
            'detector_version' => $detectorVersion, 'engine' => $engine, 'detector_limits' => $resourceLimits,
            'binding_sha256' => hash('sha256', $bindingJson), 'generation_key' => $generationKey, 'preflight_hold' => $preflightHold,
            ...($selected === null ? [] : ['selected_face' => $selected])];
    }

    /** Mirror the detector's pure tile admission calculation before any old row can be retired. */
    private function generationTileResourceLimit(int $width, int $height, int $limit, int $maxPixels): bool
    {
        if ($width < 1 || $height < 1 || $limit < 1 || $maxPixels < 1) {
            return true;
        }
        if ($width <= $limit && $height <= $limit) {
            return false;
        }

        $xIntervals = $this->generationTileIntervals($width, $limit);
        $yIntervals = $this->generationTileIntervals($height, $limit);
        $maximumTiles = max(1, intdiv($maxPixels + ($limit * $limit) - 1, $limit * $limit) * 4);
        $tileCount = count($xIntervals) * count($yIntervals);
        if ($width * $height > $maxPixels || $tileCount > $maximumTiles) {
            return true;
        }

        $scale = min(1, $limit / $width, $limit / $height);
        $detectorPixels = max(1, (int) round($width * $scale, 0, PHP_ROUND_HALF_EVEN))
            * max(1, (int) round($height * $scale, 0, PHP_ROUND_HALF_EVEN));
        $tilePixels = array_sum($xIntervals) * array_sum($yIntervals);

        return $detectorPixels + $tilePixels > $maxPixels;
    }

    /** Match scripts/face_detector.py::_tile_intervals without materializing detector tiles. */
    private function generationTileIntervals(int $length, int $limit): array
    {
        if ($length <= $limit) {
            return [$length];
        }

        $stride = max(1, (int) floor($limit * 0.75));
        $count = (int) ceil($length / $stride);
        $tileLength = min($limit, (int) ceil($length / ($count - 0.25 * ($count - 1))));

        return array_fill(0, $count, $tileLength);
    }

    /** Select only the anonymous manual observation created by an owned region review. */
    private function selectedGenerationFace(int $registryId, int $faceId, string $hash, bool $lock = false): array
    {
        $face = DB::table('file_registry_faces')->where('id', $faceId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($faceId < 1 || ! $face || (int) $face->file_registry_id !== $registryId || $face->source !== 'manual'
            || empty($face->source_current) || $face->superseded_at !== null || $face->source_sha256 !== $hash
            || $face->face_region_convention !== 'normalized_top_left' || trim((string) $face->person_name) !== '') {
            throw new \RuntimeException('selected_face_source_invalid');
        }
        foreach (['verified', 'hidden', 'favorite'] as $field) {
            if (! in_array($face->$field ?? null, [0, '0', false], true)) {
                throw new \RuntimeException('selected_face_protected');
            }
        }
        foreach (['genealogy_person_id', 'global_identity_uuid', 'global_identity_approval_receipt_id', 'cluster_id'] as $field) {
            if (! property_exists($face, $field) || $face->$field !== null) {
                throw new \RuntimeException('selected_face_protected');
            }
        }
        if (DB::table('face_identity_provenance')->where('file_registry_face_id', $faceId)->exists()) {
            throw new \RuntimeException('selected_face_protected');
        }
        $globalReviews = DB::table('agent_review_queue')->where('review_type', 'global_face_identity')
            ->where('details->global_identity_decision->face_id', $faceId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->orderBy('id')->limit((int) config('file_registry.face_preview.max_suggestion_regions', 100) + 1)->get(['details']);
        if ($globalReviews->count() > (int) config('file_registry.face_preview.max_suggestion_regions', 100)) {
            throw new \RuntimeException('selected_face_review_history_limit');
        }
        foreach ($globalReviews as $globalReview) {
            $details = $this->selectedGenerationReviewJson($globalReview->details);
            if ((int) ($details['global_identity_decision']['face_id'] ?? 0) === $faceId) {
                throw new \RuntimeException('selected_face_protected');
            }
        }
        $region = [];
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if (! is_numeric($face->{'region_'.$axis}) || ! is_finite((float) $face->{'region_'.$axis})) {
                throw new \RuntimeException('selected_face_region_invalid');
            }
            $region[$axis] = (float) $face->{'region_'.$axis};
        }
        if ($region['x'] < 0 || $region['y'] < 0 || $region['w'] <= 0 || $region['h'] <= 0
            || $region['x'] + $region['w'] > 1 || $region['y'] + $region['h'] > 1) {
            throw new \RuntimeException('selected_face_region_invalid');
        }
        $queues = DB::table('genealogy_face_match_queue')->where('file_registry_face_id', $faceId)
            ->orderBy('id')->limit(2)->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($queues->count() !== 1 || $queues[0]->status !== 'pending' || trim((string) $queues[0]->face_name) !== ''
            || $queues[0]->suggested_person_id !== null || $queues[0]->match_type !== 'no_match') {
            throw new \RuntimeException('selected_face_review_required');
        }
        $queue = $queues[0];
        $details = $this->selectedGenerationReviewJson($queue->match_details ?: '{}');
        $reviews = $details['region_reviews'] ?? null;
        if (! is_array($reviews) || ! array_is_list($reviews) || $reviews === []) {
            throw new \RuntimeException('selected_face_review_required');
        }
        foreach (['operator_confirmations', 'candidate_decisions', 'latest_candidate_decision', 'rejected_candidate_ids', 'candidate_decision_version'] as $key) {
            if (array_key_exists($key, $details) && $details[$key] !== null) {
                throw new \RuntimeException('selected_face_protected');
            }
        }
        $review = $reviews[array_key_last($reviews)];
        $proof = $review['current'] ?? [];
        if (($review['current_face_record_id'] ?? null) !== $faceId
            || ($review['decision_scope'] ?? null) !== 'review_current_face_region'
            || ($review['identity_decision_changed'] ?? null) !== false
            || ($proof['source_sha256'] ?? null) !== $hash || ($proof['region'] ?? null) != $region
            || ($proof['coordinate_basis'] ?? null) !== 'encoded_pixels'
            || ($proof['face_region_convention'] ?? null) !== 'normalized_top_left'
            || ! Str::isUuid($review['receipt_id'] ?? '')) {
            throw new \RuntimeException('selected_face_review_binding_invalid');
        }
        $receipt = DB::table('review_desk_receipts')->where('id', $review['receipt_id'])
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $payload = $receipt ? $this->selectedGenerationReviewJson($receipt->payload) : [];
        $decision = $payload['current_face_region_decision'] ?? [];
        if (! $receipt || $receipt->action !== 'refresh_region' || $receipt->state !== 'completed'
            || ($payload['effect_status'] ?? null) !== 'evidence_refreshed'
            || ($payload['action'] ?? null) !== 'refresh_region'
            || ($payload['reference'] ?? null) !== $receipt->reference
            || ($payload['decision_scope'] ?? null) !== 'review_current_face_region'
            || ! is_string($payload['actor'] ?? null) || trim($payload['actor']) === ''
            || ($decision['tree_id'] ?? null) !== (int) $queue->tree_id
            || ($decision['media_id'] ?? null) !== (int) $queue->media_id
            || ($decision['coordinate_basis'] ?? null) !== 'encoded_pixels'
            || ($decision['face_region_convention'] ?? null) !== 'normalized_top_left'
            || ! $this->selectedGenerationReceiptRegionMatches($decision['region'] ?? null, $region)
            || ($decision['queue_id'] ?? null) !== (int) $queue->id || ($decision['source_sha256'] ?? null) !== $hash) {
            throw new \RuntimeException('selected_face_review_receipt_invalid');
        }
        app(FaceLinkBridgeService::class)->assertFaceRegionNotDismissed($face, (int) $queue->tree_id, (int) $queue->media_id);
        $run = null;
        if ($face->source_generation_key !== $hash) {
            $run = DB::table('file_registry_face_generation_runs')->where('file_registry_id', $registryId)
                ->where('source_sha256', $hash)->where('generation_key', $face->source_generation_key)->first();
            if (! $run || json_decode($run->mysql_face_ids ?? 'null', true) !== [$faceId]
                || ! in_array($run->stage, ['mysql_published', 'complete'], true)) {
                throw new \RuntimeException('selected_face_generation_conflict');
            }
        }
        $vectors = DB::connection('pgsql_rag')->table('face_embeddings')->where('file_registry_face_id', $faceId)
            ->limit(2)->when($lock, fn ($query) => $query->lockForUpdate())->get();
        foreach ($vectors as $vector) {
            if (! $run || $vectors->count() !== 1 || (int) $vector->file_registry_id !== $registryId
                || $vector->person_cluster_id !== null || $vector->matched_face_id !== null || ! empty($vector->is_representative)
                || empty($vector->source_current) || $vector->superseded_at !== null || $vector->source_sha256 !== $hash
                || $vector->face_region_convention !== 'normalized_top_left') {
                throw new \RuntimeException('selected_face_vector_conflict');
            }
        }
        $raw = (array) $face;
        unset($raw['source_generation_key'], $raw['updated_at']);

        return ['face_id' => $faceId, 'region' => $region, 'snapshot' => $raw, 'review_source' => $proof,
            'queue_snapshot' => (array) $queue, 'receipt_snapshot' => (array) $receipt];
    }

    private function selectedGenerationReviewJson(mixed $bytes): array
    {
        if (! is_string($bytes) || strlen($bytes) > max(1, (int) config('file_registry.face_generation.max_packet_bytes'))) {
            throw new \RuntimeException('selected_face_review_evidence_limit');
        }
        $decoded = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \RuntimeException('selected_face_review_evidence_invalid');
        }

        return $decoded;
    }

    private function selectedGenerationReceiptRegionMatches(mixed $candidate, array $region): bool
    {
        if (! is_array($candidate) || count($candidate) !== 4 || array_diff(array_keys($candidate), ['x', 'y', 'w', 'h']) !== []) {
            return false;
        }
        foreach ($region as $axis => $value) {
            if (! is_numeric($candidate[$axis]) || ! is_finite((float) $candidate[$axis])
                || round((float) $candidate[$axis], 8) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function anonymousGenerationEngineVersion(): array
    {
        $script = base_path(self::PYTHON_SCRIPT);
        if (! is_file($script) || ! is_readable($script)) {
            throw new \RuntimeException('detector_unavailable');
        }
        $timeoutSeconds = config('file_registry.face_generation.engine_version_timeout_seconds');
        if (! is_int($timeoutSeconds) || $timeoutSeconds < 1 || $timeoutSeconds > 60) {
            throw new \RuntimeException('detector_version_unavailable');
        }
        $result = Process::timeout($timeoutSeconds)->env($this->engineProofEnvironment())->run([$this->pythonPath, $script, '--version-only']);
        $report = json_decode($result->output(), true);
        $engine = is_array($report) ? ($report['engine_provenance'] ?? null) : null;
        if (! $result->successful() || ($report['success'] ?? null) !== true || ! $this->validAnonymousGenerationEngine($engine)) {
            throw new \RuntimeException('detector_version_unavailable');
        }
        $engine['script_sha256'] = hash_file('sha256', $script);
        if (! is_string($engine['script_sha256'])) {
            throw new \RuntimeException('detector_version_unavailable');
        }

        return $engine;
    }

    private function engineProofEnvironment(): array
    {
        return ['PLOS_FACE_ENGINE_PROOF_MAX_BYTES' => (string) config('file_registry.face_generation.max_model_proof_bytes'),
            'PLOS_FACE_ENGINE_PROOF_SECONDS' => (string) config('file_registry.face_generation.model_proof_seconds')];
    }

    private function validAnonymousGenerationEngine(mixed $engine, bool $selected = false): bool
    {
        if (! is_array($engine) || ($engine['schema'] ?? null) !== 'plos.face_generation_engine.v2'
            || ($engine['detector'] ?? null) !== ($selected ? 'operator_selected_region' : 'hog')
            || ($engine['embedding_model'] ?? null) !== 'dlib_face_recognition_resnet_model_v1'
            || ! is_string($engine['python'] ?? null) || ! is_array($engine['versions'] ?? null)
            || ! is_array($engine['checkpoints'] ?? null)
            || count($engine['checkpoints']) !== 3) {
            return false;
        }
        foreach (['face-recognition', 'face-recognition-models', 'dlib', 'numpy', 'Pillow'] as $package) {
            if (! is_string($engine['versions'][$package] ?? null) || $engine['versions'][$package] === '') {
                return false;
            }
        }
        foreach (['descriptor_sha256', 'landmarks_small_sha256', 'detector_binary_sha256'] as $key) {
            if (! is_string($engine['checkpoints'][$key] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $engine['checkpoints'][$key])) {
                return false;
            }
        }

        return true;
    }

    private function validAnonymousGenerationCaller(mixed $caller): bool
    {
        if (! is_array($caller) || count($caller) !== 5
            || array_diff(array_keys($caller), ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256']) !== []) {
            return false;
        }
        foreach (['session_id', 'turn_id', 'action_id'] as $field) {
            if (! is_string($caller[$field] ?? null) || $caller[$field] === '' || strlen($caller[$field]) > 255
                || preg_match('/[\\x00-\\x20\\x7f]/', $caller[$field])) {
                return false;
            }
        }
        foreach (['arguments_sha256', 'approval_scope_sha256'] as $field) {
            if (! is_string($caller[$field] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $caller[$field])) {
                return false;
            }
        }

        return true;
    }

    private function claimAnonymousGenerationResume(array $plan, string $idempotencyKey, string $runUuid, string $callerHash, array $caller): array
    {
        if (! Str::isUuid($runUuid)) {
            throw new \RuntimeException('resume_request_invalid');
        }

        return DB::transaction(function () use ($plan, $idempotencyKey, $runUuid, $callerHash, $caller): array {
            $run = DB::table('file_registry_face_generation_runs')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($run === null || $run->run_uuid !== $runUuid || $run->asset_uuid !== $plan['asset_uuid']
                || (int) $run->file_registry_id !== $plan['file_registry_id'] || $run->source_sha256 !== $plan['source_sha256']
                || $run->generation_key !== $plan['generation_key'] || $run->detector_version !== $plan['detector_version']) {
                throw new \RuntimeException('resume_generation_mismatch');
            }
            $historyBytes = $run->resume_evidence_json ?? '[]';
            if (strlen($historyBytes) > max(1, (int) config('file_registry.face_generation.max_resume_evidence_bytes'))) {
                throw new \RuntimeException('resume_evidence_invalid');
            }
            $history = json_decode($historyBytes, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($history) || ! array_is_list($history) || count($history) > max(1, (int) config('file_registry.face_generation.max_attempts'))) {
                throw new \RuntimeException('resume_evidence_invalid');
            }
            foreach ($history as $receipt) {
                if (! is_array($receipt) || ($receipt['schema'] ?? null) !== 'plos.anonymous_face_generation_resume.v1'
                    || ($receipt['run_uuid'] ?? null) !== $run->run_uuid
                    || ($receipt['idempotency_key'] ?? null) !== $run->idempotency_key
                    || ($receipt['asset_uuid'] ?? null) !== $run->asset_uuid
                    || ($receipt['source_sha256'] ?? null) !== $run->source_sha256
                    || ($receipt['generation_key'] ?? null) !== $run->generation_key
                    || ($receipt['detector_version'] ?? null) !== $run->detector_version
                    || ($receipt['original_binding_sha256'] ?? null) !== $run->binding_sha256
                    || ($receipt['packet_sha256'] ?? null) !== $run->packet_sha256
                    || ! $this->validAnonymousGenerationCaller($receipt['caller'] ?? null)
                    || ! is_string($receipt['binding_sha256'] ?? null)
                    || ! preg_match('/^[a-f0-9]{64}$/D', $receipt['binding_sha256'])
                    || ! hash_equals($receipt['caller_binding_sha256'] ?? '', hash('sha256', json_encode([
                        $receipt['caller']['session_id'], $receipt['caller']['turn_id'], $receipt['caller']['action_id'],
                        $receipt['caller']['arguments_sha256'], $receipt['caller']['approval_scope_sha256'],
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))) {
                    throw new \RuntimeException('resume_evidence_invalid');
                }
            }
            $boundaryRecovery = $run->stage === 'mysql_published' && $run->failure_code === 'mysql_generation_region_invalid';
            $pgsqlPublicationRecovery = $run->stage === 'mysql_published'
                && $run->failure_code === 'pgsql_generation_row_conflict';
            if ($run->stage !== 'complete' && ($run->stage !== 'mysql_published'
                || ! in_array($run->failure_code, ['pgsql_generation_row_conflict', 'mysql_generation_region_invalid'], true))) {
                throw new \RuntimeException('resume_stage_ineligible');
            }
            if ($run->lease_token !== null && $run->lease_expires_at !== null
                && CarbonImmutable::parse($run->lease_expires_at, 'UTC')->isFuture()) {
                throw new \RuntimeException('generation_busy');
            }
            $root = $this->anonymousPreviewPrivateRoot();
            $packet = $root === null ? null : $this->loadAnonymousGenerationPacket($root.'/'.$run->run_uuid.'/generation.json', $root, $run);
            if ($packet === null || ($packet['engine_provenance'] ?? null) !== $plan['engine']
                || ($packet['detector_limits'] ?? null) !== $plan['detector_limits']
                || ($packet['source_dimensions'] ?? null) !== $plan['source_dimensions']) {
                throw new \RuntimeException('resume_packet_invalid');
            }
            $observation = DB::table('file_registry_image_audit_observations')->where('source_kind', 'registry')
                ->where('source_id', $plan['file_registry_id'])
                ->whereRaw("JSON_EXTRACT(source_fingerprint, '$.coverage.run_uuid') = ?", [$run->run_uuid])->first();
            $registry = DB::table('file_registry')->where('id', $plan['file_registry_id'])->lockForUpdate()->first();
            if (! $this->anonymousGenerationSourceCurrent($plan, false, $registry)) {
                throw new \RuntimeException('resume_original_binding_unproven');
            }
            $bindingProofOrigin = 'historical_image_audit_observation';
            if ($observation !== null) {
                $snapshot = json_decode($observation->source_snapshot, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($snapshot) || $observation->observed_sha256 !== $plan['source_sha256']
                    || ! is_string($snapshot['updated_at'] ?? null)
                    || ($snapshot['current_path'] ?? null) !== $registry->current_path
                    || ($snapshot['content_hash'] ?? null) !== $registry->content_hash
                    || ($snapshot['nextcloud_modified_at'] ?? null) !== $registry->nextcloud_modified_at) {
                    throw new \RuntimeException('resume_original_binding_unproven');
                }
                $binding = ['schema' => 'plos.anonymous_face_generation_binding.v1',
                    'file_registry_id' => $plan['file_registry_id'], 'asset_uuid' => $plan['asset_uuid'],
                    'registry_revision' => $snapshot['updated_at'], 'path_sha256' => hash('sha256', $plan['current_path']),
                    'content_hash' => $registry->content_hash, 'nextcloud_modified_at' => $registry->nextcloud_modified_at,
                    'source_sha256' => $plan['source_sha256'], 'detector_version' => $plan['detector_version']];
                if (! hash_equals($run->binding_sha256, hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))) {
                    throw new \RuntimeException('resume_original_binding_unproven');
                }
            } else {
                if ((! $boundaryRecovery && ! $pgsqlPublicationRecovery)
                    || ! hash_equals($run->binding_sha256, $plan['binding_sha256'])) {
                    throw new \RuntimeException('resume_original_binding_unproven');
                }
                $bindingProofOrigin = $pgsqlPublicationRecovery
                    ? 'exact_current_plan_matches_original_run_binding_after_pgsql_conflict'
                    : 'exact_current_plan_matches_original_run_binding';
            }
            if (! isset($plan['selected_face'])) {
                $this->assertNoAnonymousGenerationReviewConflict($plan['file_registry_id'], $packet['faces'], $plan['source_sha256'], $plan['generation_key'], true);
            } elseif (($packet['selected_face'] ?? null) !== $plan['selected_face']) {
                throw new \RuntimeException('selected_face_packet_mismatch');
            }
            if (DB::table('file_registry_faces')->where('file_registry_id', $plan['file_registry_id'])
                ->where('source_generation_key', $plan['generation_key'])->where(function ($query): void {
                    $query->where('person_name', '!=', '')->orWhereNotNull('genealogy_person_id')
                        ->orWhere('verified', '!=', 0)->orWhereNotNull('global_identity_uuid')
                        ->orWhereNotNull('global_identity_approval_receipt_id')->orWhereNotNull('cluster_id')
                        ->orWhere('hidden', '!=', 0)->orWhere('favorite', '!=', 0)
                        ->orWhere('source_current', '!=', 1)->orWhereNotNull('superseded_at');
                })->exists()) {
                throw new \RuntimeException('resume_protected_face');
            }
            $originalIds = json_decode($run->mysql_face_ids ?? 'null', true, flags: JSON_THROW_ON_ERROR);
            $currentIds = DB::table('file_registry_faces')->where('file_registry_id', $plan['file_registry_id'])
                ->where('source_generation_key', $plan['generation_key'])->orderBy('face_index')->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (! is_array($originalIds) || $currentIds !== $originalIds || count($originalIds) !== count($packet['faces'])) {
                throw new \RuntimeException('resume_original_rows_mismatch');
            }
            foreach ($packet['faces'] as $index => $face) {
                $row = DB::table('file_registry_faces')->where('id', $originalIds[$index])->first();
                if ($row === null || $row->source !== 'ai_detection' || $row->source_sha256 !== $plan['source_sha256']
                    || $row->face_region_convention !== 'normalized_top_left' || (int) $row->face_index !== $face['face_index']) {
                    throw new \RuntimeException('resume_original_rows_mismatch');
                }
                $region = $this->canonicalGenerationRegion($face['face_region']);
                $expectedUnits = $boundaryRecovery ? $region['independent_units'] : $region['canonical_units'];
                foreach (['x', 'y', 'w', 'h'] as $axis) {
                    if (! $this->storedRegionAxisMatchesUnits($row->{'region_'.$axis} ?? null, $expectedUnits[$axis])) {
                        throw new \RuntimeException('resume_original_rows_mismatch');
                    }
                }
            }
            $unpublishedRecovery = $boundaryRecovery || ($pgsqlPublicationRecovery && $observation === null);
            if ($unpublishedRecovery) {
                $this->assertAnonymousBoundaryRecoveryUnreferenced($originalIds);
            }
            if ($run->stage === 'complete') {
                foreach ($history as $receipt) {
                    if (($receipt['binding_sha256'] ?? null) === $plan['binding_sha256']) {
                        foreach ($packet['faces'] as $index => $face) {
                            $mysqlFace = DB::table('file_registry_faces')->where('id', $originalIds[$index])
                                ->where('file_registry_id', $plan['file_registry_id'])->where('source_sha256', $plan['source_sha256'])
                                ->where('source_generation_key', $plan['generation_key'])->where('source_current', 1)
                                ->whereNull('superseded_at')->first();
                            if ($mysqlFace === null) {
                                throw new \RuntimeException('resume_original_rows_mismatch');
                            }
                            $box = $this->pgGenerationRegion($this->persistedGenerationRegion($mysqlFace, $face['face_region']));
                            $this->assertStoredAnonymousGenerationVector($plan, $face, $originalIds[$index], $box, $packet, $mysqlFace);
                        }

                        return [$run, null, true];
                    }
                }
                throw new \RuntimeException('resume_generation_mismatch');
            }
            $this->assertAnonymousGenerationSourceUnprotected($plan);
            if ($run->caller_binding_sha256 === $callerHash) {
                throw new \RuntimeException('resume_fresh_approval_required');
            }
            foreach ($history as $receipt) {
                if (($receipt['caller_binding_sha256'] ?? null) === $callerHash) {
                    throw new \RuntimeException('resume_fresh_approval_required');
                }
            }
            $attempts = (int) $run->attempts + 1;
            if ($attempts > max(1, (int) config('file_registry.face_generation.max_attempts'))) {
                throw new \RuntimeException('generation_attempt_limit');
            }
            $resumeReceipt = ['schema' => 'plos.anonymous_face_generation_resume.v1', 'run_uuid' => $run->run_uuid,
                'idempotency_key' => $run->idempotency_key, 'asset_uuid' => $run->asset_uuid,
                'source_sha256' => $run->source_sha256, 'generation_key' => $run->generation_key, 'detector_version' => $run->detector_version,
                'binding_sha256' => $plan['binding_sha256'], 'registry_revision' => $plan['registry_revision'],
                'original_binding_sha256' => $run->binding_sha256, 'packet_sha256' => $run->packet_sha256,
                'observation_id' => $observation === null ? null : (int) $observation->id,
                'original_binding_proof' => ['schema' => 'plos.anonymous_face_generation_original_binding_proof.v1',
                    'origin' => $bindingProofOrigin,
                    ...($pgsqlPublicationRecovery && $observation === null
                        ? ['predicate' => 'current_binding_equals_original_and_original_ids_have_no_pg_or_review_references'] : []),
                    'verified_at' => now()->toISOString()],
                'caller_binding_sha256' => $callerHash,
                'caller' => $caller, 'prior_failure_code' => $run->failure_code, 'attempt' => $attempts,
                'approved_at' => now()->toISOString()];
            if ($boundaryRecovery) {
                $adjustedIndexes = [];
                foreach ($packet['faces'] as $face) {
                    if ($this->canonicalGenerationRegion($face['face_region'])['adjusted_axes'] !== []) {
                        $adjustedIndexes[] = (int) $face['face_index'];
                    }
                }
                $resumeReceipt['region_boundary_recovery'] = ['schema' => 'plos.face_region_decimal_boundary_recovery.v1',
                    'packet_sha256' => $run->packet_sha256, 'mysql_face_ids' => $originalIds,
                    'adjusted_face_indexes' => $adjustedIndexes, 'verified_at' => now()->toISOString()];
            }
            $history[] = $resumeReceipt;
            $historyBytes = json_encode($history, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($historyBytes) > max(1, (int) config('file_registry.face_generation.max_resume_evidence_bytes'))) {
                throw new \RuntimeException('resume_evidence_invalid');
            }
            $token = (string) Str::uuid();
            DB::table('file_registry_face_generation_runs')->where('id', $run->id)->update([
                'resume_evidence_json' => $historyBytes,
                'lease_token' => $token, 'lease_expires_at' => now()->addSeconds(max(1, (int) config('file_registry.face_generation.lease_seconds'))),
                'attempts' => $attempts, 'updated_at' => now(),
            ]);
            $run->lease_token = $token;
            $run->attempts = $attempts;

            return [$run, $token, false];
        });
    }

    /** Keep full-asset publication anonymous; completed reads and reviewed selections have their own gates. */
    private function assertAnonymousGenerationSourceUnprotected(array $plan): void
    {
        if (! isset($plan['selected_face'])) {
            $this->assertAutomaticAssignmentSourceResolved((int) $plan['file_registry_id'], true);
            $protection = $this->scheduledCoveragePreviewHoldReason($plan['file_registry_id'], $plan['generation_key'], true);
            if ($protection !== null) {
                throw new \RuntimeException($protection);
            }
        }
    }

    private function claimAnonymousGeneration(array $plan, string $generationKey, string $idempotencyKey, string $callerHash): array
    {
        return DB::transaction(function () use ($plan, $generationKey, $idempotencyKey, $callerHash): array {
            $existing = DB::table('file_registry_face_generation_runs')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->asset_uuid !== $plan['asset_uuid'] || $existing->source_sha256 !== $plan['source_sha256']
                    || $existing->binding_sha256 !== $plan['binding_sha256'] || $existing->detector_version !== $plan['detector_version']) {
                    throw new \RuntimeException('idempotency_binding_conflict');
                }
                if ($existing->stage === 'complete') {
                    return [$existing, null, true];
                }
                if ($existing->stage === 'detector_running' || $existing->failure_code === 'generation_terminal_unknown') {
                    throw new InferenceAdmissionException('Generation detector terminal state is UNKNOWN; retained intent must not be replayed.');
                }
                if ($existing->stage === 'held') {
                    throw new \RuntimeException('generation_held');
                }
                if ($existing->lease_token !== null && $existing->lease_expires_at !== null
                    && CarbonImmutable::parse($existing->lease_expires_at, 'UTC')->isFuture()) {
                    throw new \RuntimeException('generation_busy');
                }
                $this->assertAnonymousGenerationSourceUnprotected($plan);
                $token = (string) Str::uuid();
                $attempts = (int) $existing->attempts + 1;
                if ($attempts > max(1, (int) config('file_registry.face_generation.max_attempts'))) {
                    throw new \RuntimeException('generation_attempt_limit');
                }
                DB::table('file_registry_face_generation_runs')->where('id', $existing->id)->update([
                    'lease_token' => $token, 'lease_expires_at' => now()->addSeconds(max(1, (int) config('file_registry.face_generation.lease_seconds'))),
                    'attempts' => $attempts, 'failure_code' => null, 'updated_at' => now(),
                ]);
                $existing->lease_token = $token;
                $existing->attempts = $attempts;
                $existing->failure_code = null;

                return [$existing, $token, false];
            }
            $generation = DB::table('file_registry_face_generation_runs')->where('file_registry_id', $plan['file_registry_id'])
                ->where('generation_key', $generationKey)->lockForUpdate()->first();
            if ($generation !== null) {
                throw new \RuntimeException('generation_already_exists_use_original_receipt');
            }
            $this->assertAnonymousGenerationSourceUnprotected($plan);
            $token = (string) Str::uuid();
            $runUuid = (string) Str::uuid();
            $id = DB::table('file_registry_face_generation_runs')->insertGetId([
                'run_uuid' => $runUuid, 'idempotency_key' => $idempotencyKey,
                'file_registry_id' => $plan['file_registry_id'], 'asset_uuid' => $plan['asset_uuid'],
                'source_sha256' => $plan['source_sha256'], 'binding_sha256' => $plan['binding_sha256'],
                'generation_key' => $generationKey, 'detector_version' => $plan['detector_version'],
                'caller_binding_sha256' => $callerHash, 'stage' => 'authorized', 'attempts' => 1,
                'lease_token' => $token, 'lease_expires_at' => now()->addSeconds(max(1, (int) config('file_registry.face_generation.lease_seconds'))),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $run = DB::table('file_registry_face_generation_runs')->where('id', $id)->first();

            return [$run, $token, false];
        });
    }

    private function assertAnonymousGenerationScriptCurrent(array $plan): void
    {
        $expected = $plan['engine']['script_sha256'] ?? null;
        $script = base_path(self::PYTHON_SCRIPT);
        $actual = is_file($script) && is_readable($script) ? hash_file('sha256', $script) : false;
        if (! is_string($expected) || ! is_string($actual) || ! hash_equals($expected, $actual)) {
            throw new \RuntimeException('detector_proof_invalid');
        }
    }

    private function validatedAnonymousGenerationPacket(array $detection, array $plan, object $run, string $generationKey): array
    {
        $this->assertAnonymousGenerationScriptCurrent($plan);
        $reportedEngine = $plan['engine'];
        unset($reportedEngine['script_sha256']);
        if (($detection['success'] ?? null) !== true || ($detection['source_sha256'] ?? null) !== $plan['source_sha256']
            || ($detection['face_region_convention'] ?? null) !== 'normalized_top_left'
            || ($detection['coordinate_basis'] ?? null) !== 'encoded_pixels'
            || ($detection['engine_provenance'] ?? null) !== $reportedEngine
            || ! is_array($detection['faces'] ?? null) || ! is_array($detection['decode_provenance'] ?? null)
            || ! is_array($detection['detector_provenance'] ?? null)
            || ! $this->validPreviewDecode($detection['decode_provenance'], $plan['source_dimensions'])
            || ! $this->validGenerationRegionProof($detection['detector_provenance'], $detection['decode_provenance'], $plan)) {
            throw new \RuntimeException($detection['error_code'] ?? 'detector_proof_invalid');
        }
        $limit = min(100, max(1, (int) config('file_registry.face_generation.max_faces_per_asset')));
        if (count($detection['faces']) > $limit || (isset($plan['selected_face']) && count($detection['faces']) !== 1)) {
            throw new \RuntimeException('face_count_limit');
        }
        $faces = [];
        foreach ($detection['faces'] as $index => $face) {
            $box = $face['normalized'] ?? null;
            $embedding = $face['embedding'] ?? null;
            if (! is_array($box) || ! is_array($embedding) || count($embedding) !== 128
                || ($face['source_sha256'] ?? null) !== $plan['source_sha256']
                || ($face['face_region_convention'] ?? null) !== 'normalized_top_left'
                || ($face['embedding_model'] ?? null) !== 'dlib_face_recognition_resnet_model_v1') {
                throw new \RuntimeException('detector_face_proof_invalid');
            }
            foreach (['x', 'y', 'w', 'h'] as $axis) {
                if (! is_numeric($box[$axis] ?? null) || ! is_finite((float) $box[$axis])) {
                    throw new \RuntimeException('detector_face_proof_invalid');
                }
                $box[$axis] = (float) $box[$axis];
            }
            if ($box['x'] < 0 || $box['y'] < 0 || $box['w'] <= 0 || $box['h'] <= 0
                || $box['x'] + $box['w'] > 1 || $box['y'] + $box['h'] > 1) {
                throw new \RuntimeException('detector_face_proof_invalid');
            }
            if (isset($plan['selected_face']) && ($index !== 0 || $box !== $plan['selected_face']['region'] || count($detection['faces']) !== 1)) {
                throw new \RuntimeException('selected_face_packet_mismatch');
            }
            foreach ($embedding as $value) {
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                    throw new \RuntimeException('detector_embedding_invalid');
                }
            }
            $faces[] = ['face_index' => (int) $index, 'face_region' => array_intersect_key($box, array_flip(['x', 'y', 'w', 'h'])),
                'embedding' => array_map('floatval', array_values($embedding))];
        }

        return ['schema' => isset($detection['detector_provenance']['tile_coverage'])
            ? 'plos.anonymous_face_generation.v2' : 'plos.anonymous_face_generation.v1', 'run_uuid' => $run->run_uuid,
            'asset_uuid' => $plan['asset_uuid'], 'source_sha256' => $plan['source_sha256'],
            'binding_sha256' => $plan['binding_sha256'], 'generation_key' => $generationKey,
            'detector_version' => $plan['detector_version'], 'engine_provenance' => $plan['engine'], 'detector_limits' => $plan['detector_limits'], 'source_dimensions' => $plan['source_dimensions'], 'faces' => $faces,
            ...(isset($detection['detector_provenance']['tile_coverage'])
                ? ['detector_coverage' => $detection['detector_provenance']['tile_coverage']] : []),
            ...(isset($plan['selected_face']) ? ['selected_face' => $plan['selected_face'], 'mode_scope' => 'operator_selected_region',
                'encoding_provenance' => $detection['detector_provenance'], 'decode_provenance' => $detection['decode_provenance']] : [])];
    }

    private function validGenerationRegionProof(array $proof, array $decode, array $plan): bool
    {
        if (! isset($plan['selected_face'])) {
            if (! $this->validPreviewDetector($proof, $decode, $plan['source_dimensions'], $plan['source_sha256'])) {
                return false;
            }
            $dimensions = $plan['source_dimensions'];
            $limit = (int) ($plan['detector_limits']['detector_dimension'] ?? 0);
            $maxPixels = (int) ($plan['detector_limits']['pixels'] ?? 0);
            $requiresTiles = $limit > 0 && max($dimensions) > $limit;
            if (! $requiresTiles) {
                return ! array_key_exists('tile_coverage', $proof);
            }
            $coverage = $proof['tile_coverage'] ?? null;
            if (! $this->validAnonymousGenerationTileCoverage($coverage, $dimensions,
                ['detector_dimension' => $limit, 'pixels' => $maxPixels])) {
                return false;
            }

            return true;
        }
        $region = $plan['selected_face']['region'];
        $raster = $proof['detector_dimensions'] ?? [];
        if (! is_array($region) || array_keys($region) !== ['x', 'y', 'w', 'h']
            || ! self::validNormalizedFaceRegion($region)
            || ! is_array($raster) || count($raster) !== 2
            || ! is_int($raster[0]) || ! is_int($raster[1]) || min($raster) < 1) {
            return false;
        }
        if (($proof['model'] ?? null) !== 'operator_selected_region' || ($proof['upsample'] ?? null) !== 0
            || ($proof['selected_region'] ?? null) != $region || count($raster) !== 2
            || ($proof['encoding_raster_box'] ?? null) !== [(int) floor($region['y'] * $raster[1]),
                (int) ceil(($region['x'] + $region['w']) * $raster[0]), (int) ceil(($region['y'] + $region['h']) * $raster[1]),
                (int) floor($region['x'] * $raster[0])]) {
            return false;
        }
        // Share raster/decode checks while retaining truthful operation provenance.
        $proof['model'] = 'hog';
        $proof['upsample'] = 1;

        return $this->validPreviewDetector($proof, $decode, $plan['source_dimensions'], $plan['source_sha256']);
    }

    private function anonymousGenerationTilePlan(array $dimensions, int $limit, int $maxPixels): ?array
    {
        if (count($dimensions) !== 2 || min($dimensions) < 1 || $limit < 1 || $maxPixels < 1
            || $maxPixels < $dimensions[0] * $dimensions[1]) {
            return null;
        }
        $intervals = static function (int $length) use ($limit): array {
            if ($length <= $limit) {
                return [[0, $length]];
            }
            $stride = max(1, (int) floor($limit * 0.75));
            $count = (int) ceil($length / $stride);
            $tileLength = min($limit, (int) ceil($length / ($count - 0.25 * ($count - 1))));
            if ($count < 2 || $tileLength < 1) {
                return [];
            }
            $result = [];
            for ($index = 0; $index < $count; $index++) {
                $start = (int) round($index * ($length - $tileLength) / ($count - 1), 0, PHP_ROUND_HALF_EVEN);
                $result[] = [$start, $tileLength];
            }

            return $result;
        };
        $xs = $intervals((int) $dimensions[0]);
        $ys = $intervals((int) $dimensions[1]);
        if (! $xs || ! $ys) {
            return null;
        }
        $boxes = [];
        foreach ($ys as [$y, $height]) {
            foreach ($xs as [$x, $width]) {
                $boxes[] = [$x, $y, $width, $height];
            }
        }
        $ratio = min(1, $limit / $dimensions[0], $limit / $dimensions[1]);
        $detectorWidth = max(1, (int) round($dimensions[0] * $ratio, 0, PHP_ROUND_HALF_EVEN));
        $detectorHeight = max(1, (int) round($dimensions[1] * $ratio, 0, PHP_ROUND_HALF_EVEN));
        $processedPixels = $detectorWidth * $detectorHeight;
        foreach ($boxes as [$x, $y, $width, $height]) {
            $processedPixels += $width * $height;
        }
        $maximumTiles = max(1, (int) ceil($maxPixels / ($limit * $limit)) * 4);
        if (count($boxes) > $maximumTiles || $processedPixels > $maxPixels) {
            return null;
        }

        return ['boxes' => $boxes, 'processed_pixels' => $processedPixels, 'maximum_tiles' => $maximumTiles];
    }

    private function validAnonymousGenerationTileCoverage(mixed $coverage, array $dimensions, array $limits): bool
    {
        $limit = (int) ($limits['detector_dimension'] ?? 0);
        $maxPixels = (int) ($limits['pixels'] ?? 0);
        if (! array_is_list($dimensions) || count($dimensions) !== 2
            || ! is_int($dimensions[0]) || ! is_int($dimensions[1]) || min($dimensions) < 1
            || max($dimensions) <= $limit) {
            return false;
        }
        $expected = $this->anonymousGenerationTilePlan($dimensions, $limit, $maxPixels);

        return $expected !== null && is_array($coverage)
            && array_keys($coverage) === ['method', 'boxes', 'processed_pixels', 'maximum_pixels', 'maximum_tiles']
            && ($coverage['method'] ?? null) === 'full_resolution_overlap_v1'
            && ($coverage['boxes'] ?? null) === $expected['boxes']
            && ($coverage['processed_pixels'] ?? null) === $expected['processed_pixels']
            && ($coverage['maximum_pixels'] ?? null) === $maxPixels
            && ($coverage['maximum_tiles'] ?? null) === $expected['maximum_tiles'];
    }

    private function loadAnonymousGenerationPacket(string $path, string $root, object $run, ?float $deadline = null): ?array
    {
        if (! $this->anonymousPreviewPrivatePath($path, $root) || ! is_file($path)
            || (fileperms($path) & 0777) !== 0600 || filesize($path) > max(1, (int) config('file_registry.face_generation.max_packet_bytes'))) {
            return null;
        }
        if ($deadline === null) {
            $bytes = file_get_contents($path);
        } else {
            $this->checkMatchingProofDeadline($deadline);
            $chunkBytes = filter_var(config('file_registry.discovery.hash_chunk_bytes'), FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 1048576]]);
            if ($chunkBytes === false) {
                throw new \RuntimeException('matching_proof_budget_unsupported:generation_packet_chunk_policy');
            }
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                return null;
            }
            try {
                $opened = fstat($handle);
                if (! $opened || ($opened['mode'] & 0170000) !== 0100000 || ($opened['mode'] & 0777) !== 0600
                    || $opened['size'] < 1 || $opened['size'] > min(4194304, (int) config('file_registry.face_generation.max_packet_bytes'))) {
                    return null;
                }
                $bytes = '';
                while (strlen($bytes) < $opened['size']) {
                    $this->checkMatchingProofDeadline($deadline);
                    $chunk = fread($handle, min($chunkBytes, $opened['size'] - strlen($bytes)));
                    if (! is_string($chunk) || $chunk === '') {
                        return null;
                    }
                    $bytes .= $chunk;
                }
                $this->checkMatchingProofDeadline($deadline);
                clearstatcache(true, $path);
                foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $key) {
                    if ((fstat($handle)[$key] ?? null) !== $opened[$key] || (lstat($path)[$key] ?? null) !== $opened[$key]) {
                        return null;
                    }
                }
            } finally {
                fclose($handle);
            }
        }
        if (! is_string($bytes) || ! is_string($run->packet_sha256)
            || ! hash_equals($run->packet_sha256, hash('sha256', $bytes))) {
            return null;
        }
        $packet = json_decode($bytes, true);
        if (! is_array($packet) || ! in_array(($packet['schema'] ?? null),
            ['plos.anonymous_face_generation.v1', 'plos.anonymous_face_generation.v2'], true)
            || ($packet['run_uuid'] ?? null) !== $run->run_uuid || ($packet['asset_uuid'] ?? null) !== $run->asset_uuid
            || ($packet['source_sha256'] ?? null) !== $run->source_sha256
            || ($packet['binding_sha256'] ?? null) !== $run->binding_sha256
            || ($packet['generation_key'] ?? null) !== $run->generation_key
            || ($packet['detector_version'] ?? null) !== $run->detector_version
            || ! is_array($packet['faces'] ?? null) || ! array_is_list($packet['faces'])
            || count($packet['faces']) !== (int) $run->face_count
            || count($packet['faces']) > min(100, (int) config('file_registry.face_generation.max_faces_per_asset'))) {
            return null;
        }
        if (($packet['schema'] ?? null) === 'plos.anonymous_face_generation.v2') {
            if (! is_array($packet['source_dimensions'] ?? null) || ! is_array($packet['detector_limits'] ?? null)
                || ! $this->validAnonymousGenerationTileCoverage($packet['detector_coverage'] ?? null,
                    $packet['source_dimensions'], $packet['detector_limits'])) {
                return null;
            }
        } elseif (array_key_exists('detector_coverage', $packet)) {
            return null;
        }
        // Legacy private packets stay readable without inventing checkpoint proof.
        if (array_key_exists('engine_provenance', $packet) || array_key_exists('detector_limits', $packet)) {
            $engine = $packet['engine_provenance'] ?? null;
            $detectorBinding = ['schema' => 'plos.anonymous_face_detector.v1', 'engine' => $engine, 'limits' => $packet['detector_limits'] ?? null];
            if (isset($packet['selected_face'])) {
                $detectorBinding['selected_face'] = $packet['selected_face'];
                if (($packet['mode_scope'] ?? null) !== 'operator_selected_region' || count($packet['faces']) !== 1
                    || ! is_array($packet['selected_face']) || ! is_int($packet['selected_face']['face_id'] ?? null)
                    || ($packet['selected_face']['face_id'] ?? 0) < 1
                    || ($packet['faces'][0]['face_region'] ?? null) != ($packet['selected_face']['region'] ?? null)
                    || ! is_array($packet['source_dimensions'] ?? null) || count($packet['source_dimensions']) !== 2
                    || ! is_array($packet['selected_face']['region'] ?? null)
                    || ! is_array($packet['decode_provenance'] ?? null) || ! is_array($packet['encoding_provenance'] ?? null)
                    || ! $this->validPreviewDecode($packet['decode_provenance'], $packet['source_dimensions'])
                    || ! $this->validGenerationRegionProof($packet['encoding_provenance'], $packet['decode_provenance'], $packet)) {
                    return null;
                }
            } elseif (isset($packet['mode_scope']) || isset($packet['encoding_provenance'])) {
                return null;
            }
            if (! $this->validAnonymousGenerationEngine($engine, isset($packet['selected_face'])) || ! is_string($engine['script_sha256'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', $engine['script_sha256']) || ! is_array($packet['detector_limits'] ?? null)
                || ! hash_equals($run->detector_version, hash('sha256', json_encode($detectorBinding, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))) {
                return null;
            }
        }
        foreach ($packet['faces'] as $index => $face) {
            if (! is_array($face) || ($face['face_index'] ?? null) !== $index
                || ! is_array($face['face_region'] ?? null) || ! is_array($face['embedding'] ?? null)
                || count($face['embedding']) !== 128) {
                return null;
            }
            foreach (['x', 'y', 'w', 'h'] as $axis) {
                if (! is_numeric($face['face_region'][$axis] ?? null) || ! is_finite((float) $face['face_region'][$axis])) {
                    return null;
                }
            }
            foreach ($face['embedding'] as $value) {
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                    return null;
                }
            }
        }

        return $packet;
    }

    private function publishAnonymousGenerationRows(array $plan, object $run, array $packet, string $generationKey, string $leaseToken, \Closure $assertAuthority, bool $resumeOnly = false): array
    {
        return DB::transaction(function () use ($plan, $run, $packet, $generationKey, $leaseToken, $assertAuthority, $resumeOnly): array {
            $assertAuthority();
            $registry = DB::table('file_registry')->where('id', $plan['file_registry_id'])->lockForUpdate()->first();
            if (! $this->anonymousGenerationSourceCurrent($plan, false, $registry)) {
                throw new \RuntimeException('source_binding_changed');
            }
            if (! isset($plan['selected_face'])) {
                $this->assertAutomaticAssignmentSourceResolved((int) $plan['file_registry_id'], true);
                $this->assertNoAnonymousGenerationReviewConflict((int) $plan['file_registry_id'], $packet['faces'], $plan['source_sha256'], $generationKey, true);
            }
            $boundaryRecovery = $resumeOnly && $run->stage === 'mysql_published'
                && $run->failure_code === 'mysql_generation_region_invalid';
            if ($boundaryRecovery) {
                $this->assertAnonymousBoundaryRecoveryUnreferenced(json_decode($run->mysql_face_ids ?? '[]', true, flags: JSON_THROW_ON_ERROR));
            }
            $ids = [];
            if (isset($plan['selected_face'])) {
                if (($packet['selected_face'] ?? null) !== $plan['selected_face'] || count($packet['faces']) !== 1
                    || $packet['faces'][0]['face_region'] != $plan['selected_face']['region']) {
                    throw new \RuntimeException('selected_face_packet_mismatch');
                }
                $id = $plan['selected_face']['face_id'];
                $face = DB::table('file_registry_faces')->where('id', $id)->lockForUpdate()->first();
                if ($face->source_generation_key !== $generationKey) {
                    if ($resumeOnly || $face->source_generation_key !== $plan['source_sha256']) {
                        throw new \RuntimeException('selected_face_generation_conflict');
                    }
                    DB::table('file_registry_faces')->where('id', $id)->update(['source_generation_key' => $generationKey, 'updated_at' => now()]);
                }
                $ids = [$id];
            } else {
                foreach ($packet['faces'] as $face) {
                    $region = $this->canonicalGenerationRegion($face['face_region']);
                    $box = $region['canonical'];
                    $existing = DB::table('file_registry_faces')->where('file_registry_id', $plan['file_registry_id'])
                        ->where('source_generation_key', $generationKey)->where('face_index', $face['face_index'])
                        ->where('person_name', '')->lockForUpdate()->first();
                    $values = ['file_registry_id' => $plan['file_registry_id'], 'person_name' => '', 'genealogy_person_id' => null,
                        'region_x' => $box['x'], 'region_y' => $box['y'], 'region_w' => $box['w'], 'region_h' => $box['h'],
                        'confidence' => null, 'source' => 'ai_detection', 'verified' => 0,
                        'source_sha256' => $plan['source_sha256'], 'face_region_convention' => 'normalized_top_left',
                        'face_index' => $face['face_index'], 'source_current' => 1, 'superseded_at' => null,
                        'source_generation_key' => $generationKey, 'global_identity_uuid' => null,
                        'global_identity_approval_receipt_id' => null, 'cluster_id' => null,
                        'hidden' => 0, 'favorite' => 0, 'created_at' => now(), 'updated_at' => now()];
                    if ($existing !== null) {
                        foreach ($values as $key => $value) {
                            if (in_array($key, ['created_at', 'updated_at'], true)) {
                                continue;
                            }
                            if (in_array($key, ['region_x', 'region_y', 'region_w', 'region_h'], true)) {
                                $axis = substr($key, strlen('region_'));
                                $canonicalMatch = $this->storedRegionAxisMatchesUnits($existing->{$key} ?? null, $region['canonical_units'][$axis]);
                                $independentMatch = $boundaryRecovery
                                    && $this->storedRegionAxisMatchesUnits($existing->{$key} ?? null, $region['independent_units'][$axis]);
                                if ($canonicalMatch || $independentMatch) {
                                    continue;
                                }
                            }
                            if (! property_exists($existing, $key) || $existing->{$key} != $value) {
                                throw new \RuntimeException('mysql_generation_row_conflict');
                            }
                        }
                        if ($boundaryRecovery && $region['adjusted_axes'] !== []) {
                            $updatedGeometry = DB::table('file_registry_faces')->where('id', $existing->id)
                                ->where('file_registry_id', $plan['file_registry_id'])->where('source', 'ai_detection')
                                ->where('source_sha256', $plan['source_sha256'])->where('source_generation_key', $generationKey)
                                ->where('source_current', 1)->whereNull('superseded_at')
                                ->update(['region_x' => $box['x'], 'region_y' => $box['y'], 'region_w' => $box['w'], 'region_h' => $box['h'], 'updated_at' => now()]);
                            if ($updatedGeometry !== 1) {
                                throw new \RuntimeException('resume_original_rows_mismatch');
                            }
                        }
                        $ids[] = (int) $existing->id;
                    } else {
                        if ($resumeOnly) {
                            throw new \RuntimeException('resume_original_rows_missing');
                        }
                        $ids[] = (int) DB::table('file_registry_faces')->insertGetId($values);
                    }
                }
            }
            if ($resumeOnly && $ids !== json_decode($run->mysql_face_ids ?? 'null', true)) {
                throw new \RuntimeException('resume_original_rows_mismatch');
            }
            $updated = DB::table('file_registry_face_generation_runs')->where('id', $run->id)
                ->where('lease_token', $leaseToken)->whereIn('stage', ['detected', 'mysql_published'])
                ->update(['stage' => 'mysql_published', 'mysql_face_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
                    'failure_code' => null, 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new \RuntimeException('generation_receipt_changed');
            }

            $assertAuthority();

            return $ids;
        });
    }

    private function publishAnonymousGenerationVectors(array $plan, array $packet, array $faceIds, \Closure $assertAuthority): void
    {
        if (count($faceIds) !== count($packet['faces'])) {
            throw new \RuntimeException('cross_store_face_count_mismatch');
        }
        if (! $this->anonymousGenerationSourceCurrent($plan)) {
            throw new \RuntimeException('source_binding_changed');
        }
        $mysqlFaces = DB::table('file_registry_faces')->where('file_registry_id', $plan['file_registry_id'])
            ->whereIn('id', $faceIds)->where('source_sha256', $plan['source_sha256'])
            ->where('source_generation_key', $packet['generation_key'])->where('face_region_convention', 'normalized_top_left')
            ->where('source_current', 1)->whereNull('superseded_at')->get()->keyBy('id');
        if ($mysqlFaces->count() !== count($faceIds)) {
            throw new \RuntimeException('mysql_generation_row_conflict');
        }
        $publish = function () use ($plan, $packet, $faceIds, $assertAuthority, $mysqlFaces): void {
            DB::connection('pgsql_rag')->transaction(function () use ($plan, $packet, $faceIds, $assertAuthority, $mysqlFaces): void {
                $assertAuthority();
                foreach ($packet['faces'] as $index => $face) {
                    $mysqlFace = $mysqlFaces->get($faceIds[$index]);
                    if ($mysqlFace === null) {
                        throw new \RuntimeException('mysql_generation_row_conflict');
                    }
                    $box = $this->pgGenerationRegion($this->persistedGenerationRegion($mysqlFace, $face['face_region']));
                    $vector = PgVector::literal($face['embedding'], self::VECTOR_LITERAL_PRECISION);
                    DB::connection('pgsql_rag')->insert("INSERT INTO face_embeddings (
                        file_registry_id, file_registry_face_id, person_cluster_id, embedding,
                        region_x, region_y, region_w, region_h, crop_path, matched_face_id,
                        match_confidence, embedding_model, source_sha256, face_region_convention,
                        generation_provenance, source_current, created_at, updated_at
                    ) VALUES (?, ?, NULL, ?::vector, ?, ?, ?, ?, NULL, NULL, NULL,
                        'dlib_face_recognition_resnet_model_v1', ?, 'normalized_top_left', ?, TRUE, NOW(), NOW())
                    ON CONFLICT (file_registry_face_id)
                    WHERE file_registry_face_id IS NOT NULL AND source_current IS TRUE AND superseded_at IS NULL
                    DO NOTHING", [
                        $plan['file_registry_id'], $faceIds[$index], $vector,
                        $box['x'], $box['y'], $box['w'], $box['h'], $plan['source_sha256'],
                        json_encode($this->anonymousVectorProvenance($packet, $face, $faceIds[$index]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    ]);
                    $this->assertStoredAnonymousGenerationVector($plan, $face, $faceIds[$index], $box, $packet, $mysqlFace);
                }
                $assertAuthority();
            });
        };
        if (isset($plan['selected_face'])) {
            $publish();

            return;
        }
        DB::transaction(function () use ($plan, $publish): void {
            $registry = DB::table('file_registry')->where('id', $plan['file_registry_id'])->lockForUpdate()->first();
            if (! $this->anonymousGenerationSourceCurrent($plan, false, $registry)) {
                throw new \RuntimeException('source_binding_changed');
            }
            // Hold source/media/review custody until the PG publication transaction commits.
            if ($this->hasAnonymousSourceMediaReview((int) $plan['file_registry_id'], true)) {
                throw new \RuntimeException('protected_reviewed_face_present');
            }
            $publish();
        });
    }

    private function persistedGenerationRegion(object $mysqlFace, array $detectedRegion): array
    {
        $region = $this->canonicalGenerationRegion($detectedRegion);
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            $stored = $mysqlFace->{'region_'.$axis} ?? null;
            if (! $this->storedRegionAxisMatchesUnits($stored, $region['canonical_units'][$axis])) {
                throw new \RuntimeException('mysql_generation_region_changed');
            }
        }

        return $region['canonical'];
    }

    /** Canonical DECIMAL(10,8) geometry derived only from a validated detector packet. */
    private function canonicalGenerationRegion(array $detectedRegion): array
    {
        if (! self::validNormalizedFaceRegion($detectedRegion)) {
            throw new \RuntimeException('mysql_generation_region_invalid');
        }
        $units = [];
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            $value = (float) $detectedRegion[$axis];
            $units[$axis] = (int) round(round($value, 8, PHP_ROUND_HALF_UP) * self::MYSQL_FACE_REGION_SCALE, 0, PHP_ROUND_HALF_UP);
        }
        $independentUnits = $units;
        $adjustedAxes = [];
        foreach ([['x', 'w'], ['y', 'h']] as [$origin, $extent]) {
            $sum = $units[$origin] + $units[$extent];
            if ($sum <= self::MYSQL_FACE_REGION_SCALE) {
                continue;
            }
            $rawEndpoint = (float) $detectedRegion[$origin] + (float) $detectedRegion[$extent];
            $adjustedExtent = (self::MYSQL_FACE_REGION_SCALE - $units[$origin]) / self::MYSQL_FACE_REGION_SCALE;
            if ($sum !== self::MYSQL_FACE_REGION_SCALE + 1 || abs($rawEndpoint - 1.0) > 0.000000000001
                || abs($adjustedExtent - (float) $detectedRegion[$extent])
                    > self::MYSQL_FACE_REGION_HALF_UNIT + 0.000000000001) {
                throw new \RuntimeException('mysql_generation_region_invalid');
            }
            $units[$extent] = self::MYSQL_FACE_REGION_SCALE - $units[$origin];
            $adjustedAxes[] = $extent;
        }
        $canonical = [];
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            $canonical[$axis] = $units[$axis] / self::MYSQL_FACE_REGION_SCALE;
        }
        if (! self::validNormalizedFaceRegion($canonical)) {
            throw new \RuntimeException('mysql_generation_region_invalid');
        }

        return ['canonical' => $canonical, 'canonical_units' => $units,
            'independent_units' => $independentUnits, 'adjusted_axes' => $adjustedAxes];
    }

    private function storedRegionAxisMatchesUnits(mixed $stored, int $expectedUnits): bool
    {
        if (! is_numeric($stored) || ! is_finite((float) $stored) || (float) $stored < 0 || (float) $stored > 1) {
            return false;
        }
        $storedUnits = (int) round(round((float) $stored, 8, PHP_ROUND_HALF_UP) * self::MYSQL_FACE_REGION_SCALE, 0, PHP_ROUND_HALF_UP);

        return $storedUnits === $expectedUnits;
    }

    /** Choose REAL-representable coordinates that retain strict normalized bounds. */
    private function pgGenerationRegion(array $mysqlRegion): array
    {
        $nearest = [];
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            $nearest[$axis] = $this->float32Nearest((float) $mysqlRegion[$axis]);
        }
        $box = $nearest;
        foreach ([['x', 'w'], ['y', 'h']] as [$origin, $extent]) {
            if ($box[$origin] + $box[$extent] <= 1) {
                continue;
            }
            $alternatives = [];
            $originCandidate = $this->float32Floor(1 - $box[$extent]);
            $extentCandidate = $this->float32Floor(1 - $box[$origin]);
            foreach ([[$origin, $originCandidate], [$extent, $extentCandidate]] as [$axis, $candidate]) {
                $choice = $box;
                $choice[$axis] = $candidate;
                $validComponents = true;
                foreach (['x', 'y', 'w', 'h'] as $component) {
                    $validComponents = $validComponents && is_finite($choice[$component])
                        && $choice[$component] >= 0 && $choice[$component] <= 1;
                }
                if (abs($choice[$axis] - (float) $mysqlRegion[$axis]) <= self::FACE_REGION_STORAGE_BOUND
                    && $validComponents && $choice['w'] > 0 && $choice['h'] > 0
                    && $choice[$origin] + $choice[$extent] <= 1) {
                    $alternatives[] = ['box' => $choice, 'cost' => abs($choice[$origin] - (float) $mysqlRegion[$origin])
                        + abs($choice[$extent] - (float) $mysqlRegion[$extent])];
                }
            }
            if ($alternatives === []) {
                throw new \RuntimeException('pgsql_generation_region_invalid');
            }
            usort($alternatives, fn (array $left, array $right): int => $left['cost'] <=> $right['cost']);
            $box = $alternatives[0]['box'];
        }
        if (! self::validNormalizedFaceRegion($box)) {
            throw new \RuntimeException('pgsql_generation_region_invalid');
        }

        return $box;
    }

    private function float32Nearest(float $value): float
    {
        return unpack('g', pack('g', $value))[1];
    }

    private function float32Floor(float $value): float
    {
        $value = max(0.0, min(1.0, $value));
        $packed = pack('g', $value);
        $nearest = unpack('g', $packed)[1];
        if ($nearest <= $value) {
            return $nearest;
        }
        $bits = unpack('V', $packed)[1];

        return unpack('g', pack('V', max(0, $bits - 1)))[1];
    }

    private function assertAnonymousBoundaryRecoveryUnreferenced(array $faceIds): void
    {
        $expectedIds = array_values(array_map('intval', $faceIds));
        $orderedExpectedIds = $expectedIds;
        sort($orderedExpectedIds);
        $lockedIds = DB::table('file_registry_faces')->whereIn('id', $orderedExpectedIds)->orderBy('id')
            ->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($lockedIds !== $orderedExpectedIds) {
            throw new \RuntimeException('resume_original_rows_mismatch');
        }
        foreach ($faceIds as $faceId) {
            if ($this->hasAnonymousGenerationReview((int) $faceId)) {
                throw new \RuntimeException('resume_protected_face');
            }
            if (Schema::hasTable('agent_review_queue')
                && DB::table('agent_review_queue')->where('review_type', 'global_face_identity')
                    ->where('details->global_identity_decision->face_id', (int) $faceId)->exists()) {
                throw new \RuntimeException('resume_protected_face');
            }
        }
        foreach (['face_identity_provenance', 'person_face_profile_faces'] as $table) {
            if (Schema::hasTable($table)
                && DB::table($table)->whereIn('file_registry_face_id', $faceIds)->exists()) {
                throw new \RuntimeException('resume_protected_face');
            }
        }
        if (Schema::hasTable('person_face_appearance_profiles')
            && DB::table('person_face_appearance_profiles')->whereIn('representative_file_registry_face_id', $faceIds)->exists()) {
            throw new \RuntimeException('resume_protected_face');
        }
        $placeholders = implode(',', array_fill(0, count($faceIds), '?'));
        if (DB::connection('pgsql_rag')->selectOne(
            'SELECT file_registry_face_id FROM face_embeddings /* boundary recovery preflight */ WHERE file_registry_face_id IN ('.$placeholders.') LIMIT 1',
            array_map('intval', $faceIds)
        ) !== null) {
            throw new \RuntimeException('resume_published_vector_conflict');
        }
    }

    private function assertStoredAnonymousGenerationVector(array $plan, array $face, int $faceId, array $box, array $packet, object $mysqlFace): void
    {
        $vector = PgVector::literal($face['embedding'], self::VECTOR_LITERAL_PRECISION);
        // REAL coordinates and pgvector components are float32. Compare
        // the exact stored representations, not their float64 inputs.
        $stored = DB::connection('pgsql_rag')->selectOne('SELECT file_registry_id, file_registry_face_id,
        source_sha256, face_region_convention, source_current, superseded_at,
        person_cluster_id, matched_face_id, embedding_model, generation_provenance, region_x, region_y, region_w, region_h
        FROM face_embeddings WHERE file_registry_id = ? AND file_registry_face_id = ?
            AND source_current = TRUE AND superseded_at IS NULL AND source_sha256 = ?
            AND face_region_convention = \'normalized_top_left\' AND embedding = ?::vector', [
            $plan['file_registry_id'], $faceId, $plan['source_sha256'], $vector,
        ]);
        $expectedVectorBox = (object) ['region_x' => $box['x'], 'region_y' => $box['y'],
            'region_w' => $box['w'], 'region_h' => $box['h']];
        $expectedVectorMatches = true;
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            $expectedVectorMatches = $expectedVectorMatches
                && pack('g', (float) ($stored->{'region_'.$axis} ?? NAN)) === pack('g', (float) $box[$axis]);
        }
        if (! $stored || (int) $stored->file_registry_id !== (int) $plan['file_registry_id']
            || (int) $stored->file_registry_face_id !== $faceId
            || $stored->source_sha256 !== $plan['source_sha256']
            || $stored->face_region_convention !== 'normalized_top_left' || ! $stored->source_current
            || $stored->superseded_at !== null
            || $stored->embedding_model !== 'dlib_face_recognition_resnet_model_v1'
            || $stored->person_cluster_id !== null || $stored->matched_face_id !== null
            || ! $expectedVectorMatches
            || ! self::faceRegionsMatchStoragePrecision($mysqlFace, $stored)
            || ! self::faceRegionsMatchStoragePrecision($expectedVectorBox, $stored)
            || ($stored->generation_provenance !== null && ! $this->sameGenerationProvenance(
                $stored->generation_provenance, $this->anonymousVectorProvenance($packet, $face, $faceId)))) {
            throw new \RuntimeException('pgsql_generation_row_conflict');
        }
    }

    private static function validNormalizedFaceRegion(array $box): bool
    {
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if (! is_numeric($box[$axis] ?? null) || ! is_finite((float) $box[$axis])
                || $box[$axis] < 0 || $box[$axis] > 1) {
                return false;
            }
        }

        return $box['w'] > 0 && $box['h'] > 0 && $box['x'] + $box['w'] <= 1 && $box['y'] + $box['h'] <= 1;
    }

    private function anonymousGenerationSourceCurrent(array $plan, bool $lock = false, ?object $lockedRegistry = null): bool
    {
        $registry = $lockedRegistry ?? DB::table('file_registry')->where('id', $plan['file_registry_id'])
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($registry === null || (int) $registry->id !== (int) $plan['file_registry_id']
            || strtolower((string) $registry->asset_uuid) !== $plan['asset_uuid'] || $registry->status !== 'active'
            || (string) $registry->current_path !== $plan['current_path']
            || (string) $registry->updated_at !== $plan['registry_revision']) {
            return false;
        }
        if ($this->anonymousReconciliationPending((int) $registry->id)) {
            return false;
        }
        if (isset($plan['selected_face'])) {
            try {
                if ($this->selectedGenerationFace((int) $registry->id, $plan['selected_face']['face_id'], $plan['source_sha256'], $lock) !== $plan['selected_face']) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }
        $path = $this->facePreviewLocalPath($registry->current_path);

        return $path !== null && $path === $plan['local_path'] && @hash_file('sha256', $path) === $plan['source_sha256'];
    }

    private function anonymousReconciliationPending(int $fileRegistryId): bool
    {
        return DB::table('file_registry_face_reconciliation_runs')
            ->where('file_registry_id', $fileRegistryId)->where('stage', '!=', 'complete')->exists();
    }

    private function assertNoAnonymousGenerationReviewConflict(int $fileRegistryId, array $faces, string $sourceHash, string $generationKey, bool $lockSourceMedia = false): void
    {
        if ($this->hasAnonymousSourceMediaReview($fileRegistryId, $lockSourceMedia)) {
            throw new \RuntimeException('protected_reviewed_face_present');
        }
        $existingFaces = DB::table('file_registry_faces')->where('file_registry_id', $fileRegistryId)->get();
        foreach ($existingFaces as $existing) {
            if (! $this->hasAnonymousGenerationReview((int) $existing->id)) {
                continue;
            }
            if ($existing->source_sha256 !== $sourceHash || $existing->face_region_convention !== 'normalized_top_left'
                || $existing->region_x === null || $existing->region_y === null || $existing->region_w === null || $existing->region_h === null) {
                throw new \RuntimeException('review_conflict_unbound_prior_region');
            }
            foreach ($faces as $face) {
                $box = $face['face_region'];
                $overlapWidth = min((float) $existing->region_x + (float) $existing->region_w, $box['x'] + $box['w'])
                    - max((float) $existing->region_x, $box['x']);
                $overlapHeight = min((float) $existing->region_y + (float) $existing->region_h, $box['y'] + $box['h'])
                    - max((float) $existing->region_y, $box['y']);
                if ($overlapWidth > 0 && $overlapHeight > 0) {
                    throw new \RuntimeException('review_conflict_overlapping_prior_region');
                }
            }
        }
        if (DB::table('file_registry_faces')->where('file_registry_id', $fileRegistryId)->where('source', 'ai_detection')
            ->where('source_current', 1)->whereNull('superseded_at')->where('source_generation_key', '!=', $generationKey)
            ->exists()) {
            throw new \RuntimeException('prior_current_generation_reconciliation_required');
        }
    }

    private function hasAnonymousGenerationReview(int $faceId): bool
    {
        foreach (DB::table('genealogy_face_match_queue')->where('file_registry_face_id', $faceId)
            ->get(['status', 'reviewed_at', 'match_details']) as $review) {
            if (in_array($review->status, ['pending', 'approved', 'rejected', 'dismissed'], true) || $review->reviewed_at !== null) {
                return true;
            }
            $details = is_string($review->match_details) && $review->match_details !== ''
                ? json_decode($review->match_details, true) : [];
            if (! is_array($details)) {
                return true;
            }
            foreach (['operator_confirmations', 'candidate_decisions', 'latest_candidate_decision', 'rejected_candidate_ids', 'candidate_decision_version'] as $key) {
                if (array_key_exists($key, $details) && $details[$key] !== null) {
                    return true;
                }
            }
        }
        if (DB::connection()->getDriverName() === 'sqlite') {
            foreach (DB::table('agent_review_queue')->where('review_type', 'global_face_identity')->get(['details']) as $review) {
                $details = is_string($review->details) && $review->details !== '' ? json_decode($review->details, true) : [];
                $decision = is_array($details) ? ($details['global_identity_decision'] ?? null) : null;
                if (is_array($decision) && ($decision['operation'] ?? null) === 'confirm_face'
                    && (int) ($decision['face_id'] ?? 0) === $faceId) {
                    return true;
                }
            }

            return false;
        }

        return DB::selectOne("SELECT id FROM agent_review_queue WHERE review_type = 'global_face_identity'
            AND CASE WHEN JSON_VALID(details) THEN JSON_UNQUOTE(JSON_EXTRACT(details, '$.global_identity_decision.operation')) ELSE NULL END = ?
            AND CASE WHEN JSON_VALID(details) THEN JSON_EXTRACT(details, '$.global_identity_decision.face_id') ELSE NULL END = ?
            LIMIT 1", ['confirm_face', $faceId]) !== null;
    }

    private function retireAnonymousGenerationRows(object $run, array $faceIds): void
    {
        if ($faceIds === []) {
            return;
        }
        DB::table('file_registry_faces')->whereIn('id', $faceIds)->where('source_generation_key', $run->generation_key)
            ->update(['source_current' => 0, 'superseded_at' => now(), 'updated_at' => now()]);
        try {
            DB::connection('pgsql_rag')->table('face_embeddings')->whereIn('file_registry_face_id', $faceIds)
                ->where('source_sha256', $run->source_sha256)->update(['source_current' => false, 'superseded_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable) {
            DB::table('file_registry_face_generation_runs')->where('id', $run->id)
                ->update(['stage' => 'held', 'failure_code' => 'cross_store_retirement_pending', 'updated_at' => now()]);
        }
    }

    private function holdStaleAnonymousGeneration(string $idempotencyKey): void
    {
        $run = DB::table('file_registry_face_generation_runs')->where('idempotency_key', $idempotencyKey)->first();
        if ($run === null || in_array($run->stage, ['complete', 'held', 'detector_running'], true)
            || $run->failure_code === 'generation_terminal_unknown') {
            return;
        }
        $faceIds = is_string($run->mysql_face_ids) ? json_decode($run->mysql_face_ids, true) : [];
        $faceIds = is_array($faceIds) ? array_values(array_filter($faceIds, fn ($id) => is_int($id) && $id > 0)) : [];
        if ($faceIds !== [] && ! DB::table('file_registry_faces')->whereIn('id', $faceIds)->where('source', 'manual')->exists()) {
            $this->retireAnonymousGenerationRows($run, $faceIds);
        }
        $failureCode = DB::table('file_registry_face_generation_runs')->where('id', $run->id)->value('failure_code');
        DB::table('file_registry_face_generation_runs')->where('id', $run->id)
            ->where('stage', $run->stage)->where('lease_token', $run->lease_token)->update([
                'stage' => 'held', 'failure_code' => $failureCode === 'cross_store_retirement_pending'
                    ? $failureCode : 'source_binding_changed',
                'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now(),
            ]);
    }

    private function anonymousGenerationError(\Throwable $e): string
    {
        $message = $e->getMessage();

        return preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $message) ? $message : 'generation_stage_failed';
    }

    /** Validate one explicitly selected packet; the default publishes no database projection. */
    public function registerAnonymousPreview(array $selector, string $packetPath, bool $dryRun = true, ?array $expectedDryRun = null): array
    {
        try {
            $source = $this->facePreviewSource($selector);
            $binding = $source ? $this->anonymousPreviewBinding($source) : null;
            $root = $this->anonymousPreviewPrivateRoot();
            $packet = $binding && $root ? $this->anonymousPreviewPacket($packetPath, $root, $binding) : null;
            if (! $packet || ! $this->anonymousPreviewSourceMatches($selector, $source, $packet['packet'])) {
                return ['success' => false, 'dry_run' => $dryRun, 'error' => 'anonymous_preview_unavailable'];
            }
            $projection = ['source_sha256' => $packet['packet']['source_sha256'],
                'source_dimensions' => $packet['packet']['source_dimensions'],
                'face_region_convention' => 'normalized_top_left', 'coordinate_basis' => 'encoded_pixels',
                'regions' => array_map(fn (array $face) => ['face_index' => $face['face_index'],
                    'face_region' => array_map(fn ($number) => (float) $number, $face['face_region'])], $packet['packet']['faces'])];
            $bytes = json_encode($projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($bytes) > (int) config('file_registry.face_preview.max_projection_bytes')) {
                throw new \RuntimeException('Projection budget exceeded');
            }
            $index = ['binding_key' => $this->anonymousPreviewIndexKey($binding),
                'selector_json' => json_encode($binding, JSON_THROW_ON_ERROR),
                'source_sha256' => $projection['source_sha256'], 'run_uuid' => basename(dirname($packetPath)),
                'packet_sha256' => $packet['sha256'], 'projection_json' => $bytes, 'projection_sha256' => hash('sha256', $bytes),
                'created_at' => now(), 'updated_at' => now()];
            $receipt = ['success' => true, 'dry_run' => true, 'published' => false,
                'source_sha256' => $index['source_sha256'], 'packet_sha256' => $index['packet_sha256'],
                'projection_sha256' => $index['projection_sha256'], 'face_count' => count($projection['regions'])];
            if ($expectedDryRun !== null
                && (count($expectedDryRun) !== count($receipt) || array_diff_key($expectedDryRun, $receipt)
                    || array_diff_key($receipt, $expectedDryRun))) {
                throw new \RuntimeException('Dry-run receipt invalid');
            }
            foreach ($expectedDryRun ?? [] as $key => $value) {
                if ($value !== $receipt[$key]) {
                    throw new \RuntimeException('Dry-run receipt does not match current packet');
                }
            }
            if (! $dryRun) {
                DB::transaction(function () use ($selector, $source, $packet, $packetPath, $root, $index, $expectedDryRun): void {
                    if ($expectedDryRun !== null) {
                        $existing = DB::table('anonymous_face_preview_indexes')->where('binding_key', $index['binding_key'])->lockForUpdate()->first();
                        if ($existing) {
                            foreach (['selector_json', 'source_sha256', 'run_uuid', 'packet_sha256', 'projection_sha256', 'projection_json'] as $key) {
                                if ($existing->{$key} !== $index[$key]) {
                                    throw new \RuntimeException('A different anonymous projection already exists');
                                }
                            }
                        }
                    }
                    if (! $this->anonymousPreviewSourceMatches($selector, $source, $packet['packet'])
                        || ! $this->anonymousPreviewPrivatePath($packetPath, $root)
                        || hash_file('sha256', $packetPath) !== $packet['sha256']) {
                        throw new \RuntimeException('Source or packet changed before publication');
                    }
                    if ($expectedDryRun !== null) {
                        // A unique-key insert also holds a competing publication after an absent-row read.
                        if (! $existing && ! DB::table('anonymous_face_preview_indexes')->insert($index)) {
                            throw new \RuntimeException('Anonymous projection publication failed');
                        }

                        return;
                    }
                    DB::table('anonymous_face_preview_indexes')->upsert([$index], ['binding_key'], [
                        'selector_json', 'source_sha256', 'run_uuid', 'packet_sha256', 'projection_json', 'projection_sha256', 'updated_at',
                    ]);
                });
            }

            return array_replace($receipt, ['dry_run' => $dryRun, 'published' => ! $dryRun]);
        } catch (\Throwable) {
            return ['success' => false, 'dry_run' => $dryRun, 'error' => 'anonymous_preview_unavailable'];
        }
    }

    /** Web readers use the anonymous database projection and never open CLI-private artifacts. */
    public function anonymousPreviewSuggestions(array $selector, array $currentProof): array
    {
        $hash = $currentProof['source_sha256'] ?? null;
        $dimensions = $currentProof['source_dimensions'] ?? null;
        $unavailable = ['state' => 'unavailable', 'source_sha256' => is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) ? $hash : null,
            'source_dimensions' => is_array($dimensions) && array_is_list($dimensions) && count($dimensions) === 2
                && is_int($dimensions[0]) && is_int($dimensions[1]) && min($dimensions) > 0 ? $dimensions : null,
            'face_region_convention' => 'normalized_top_left', 'coordinate_basis' => 'encoded_pixels', 'regions' => [],
            'provenance_state' => 'unavailable'];
        try {
            if (($currentProof['success'] ?? null) !== true || ($currentProof['coordinate_basis'] ?? null) !== 'encoded_pixels'
                || ($currentProof['face_region_convention'] ?? null) !== 'normalized_top_left') {
                return $unavailable;
            }
            $source = $this->facePreviewSource($selector);
            $binding = $source ? $this->anonymousPreviewBinding($source) : null;
            if (! $binding || ($currentProof['source_path'] ?? null) !== $source['current_path']) {
                return $unavailable;
            }
            $assetUuid = strtolower((string) ($source['registry']['asset_uuid'] ?? ''));
            if (Str::isUuid($assetUuid)) {
                try {
                    $versioned = DB::table('anonymous_face_preview_indexes')->where('asset_uuid', $assetUuid)
                        ->where('source_sha256', $hash)->orderByDesc('created_at')->first();
                    if ($versioned && is_string($versioned->detector_version) && preg_match('/^[a-f0-9]{64}$/D', $versioned->detector_version)
                        && $versioned->binding_key === $this->scheduledCoveragePreviewIndexKey($assetUuid, $hash, $versioned->detector_version)
                        && $versioned->selector_json === json_encode(['asset_uuid' => $assetUuid], JSON_THROW_ON_ERROR)
                        && is_string($versioned->engine_provenance_json) && is_string($versioned->projection_json)
                        && strlen($versioned->engine_provenance_json) <= (int) config('file_registry.face_generation.max_packet_bytes')
                        && strlen($versioned->projection_json) <= (int) config('file_registry.face_preview.max_projection_bytes')
                        && hash('sha256', $versioned->projection_json) === $versioned->projection_sha256) {
                        $projection = json_decode($versioned->projection_json, true, 16, JSON_THROW_ON_ERROR);
                        $engine = json_decode($versioned->engine_provenance_json, true, 16, JSON_THROW_ON_ERROR);
                        if (is_array($engine) && $this->anonymousPreviewDetectorVersion($engine) === $versioned->detector_version
                            && $this->validAnonymousPreviewProjection($projection, $hash, $dimensions)
                            && $this->anonymousPreviewSourceMatches($selector, $source, $projection, false)) {
                            return array_replace($unavailable, ['state' => 'available', 'regions' => $projection['regions'],
                                'provenance_state' => 'engine_bound_recorded_not_currently_verified',
                                'detector_version' => $versioned->detector_version, 'engine_provenance' => $engine]);
                        }
                    }
                } catch (\Throwable) {
                    // Newer index metadata must not make existing v1 review packets unreadable.
                }
            }
            $index = DB::table('anonymous_face_preview_indexes')->where('binding_key', $this->anonymousPreviewIndexKey($binding))->first();
            if (! $index || json_decode($index->selector_json, true, 8, JSON_THROW_ON_ERROR) !== $binding
                || $index->source_sha256 !== $hash || ! Str::isUuid($index->run_uuid)
                || ! preg_match('/^[a-f0-9]{64}$/D', $index->packet_sha256)
                || ! is_string($index->projection_json) || strlen($index->projection_json) > (int) config('file_registry.face_preview.max_projection_bytes')
                || hash('sha256', $index->projection_json) !== $index->projection_sha256) {
                return $unavailable;
            }
            $projection = json_decode($index->projection_json, true, 16, JSON_THROW_ON_ERROR);
            if (! $this->validAnonymousPreviewProjection($projection, $hash, $dimensions)
                || ! $this->anonymousPreviewSourceMatches($selector, $source, $projection, false)) {
                return $unavailable;
            }

            return array_replace($unavailable, ['state' => 'available', 'regions' => $projection['regions'],
                'provenance_state' => 'legacy_source_bound_engine_unrecorded']);
        } catch (\Throwable) {
            return $unavailable;
        }
    }

    private function validAnonymousPreviewProjection(mixed $projection, mixed $hash, mixed $dimensions): bool
    {
        if (! is_array($projection) || array_keys($projection) !== ['source_sha256', 'source_dimensions', 'face_region_convention', 'coordinate_basis', 'regions']
            || $projection['source_sha256'] !== $hash || $projection['source_dimensions'] !== $dimensions
            || $projection['face_region_convention'] !== 'normalized_top_left' || $projection['coordinate_basis'] !== 'encoded_pixels'
            || ! is_array($projection['regions']) || ! array_is_list($projection['regions'])
            || count($projection['regions']) > min(100, (int) config('file_registry.face_preview.max_suggestion_regions'))) {
            return false;
        }
        foreach ($projection['regions'] as $index => $face) {
            if (! is_array($face) || array_keys($face) !== ['face_index', 'face_region'] || $face['face_index'] !== $index
                || ! is_array($face['face_region']) || array_keys($face['face_region']) !== ['x', 'y', 'w', 'h']) {
                return false;
            }
            $box = $face['face_region'];
            foreach ($box as $value) {
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                    return false;
                }
            }
            if ($box['x'] < 0 || $box['y'] < 0 || $box['w'] <= 0 || $box['h'] <= 0 || $box['x'] + $box['w'] > 1 || $box['y'] + $box['h'] > 1) {
                return false;
            }
        }

        return true;
    }

    private function anonymousPreviewBinding(array $source): ?array
    {
        if ($source['media_id'] !== null && $source['tree_id'] !== null) {
            return ['tree_id' => (int) $source['tree_id'], 'media_id' => (int) $source['media_id']];
        }
        $uuid = $source['registry']['asset_uuid'] ?? null;

        return is_string($uuid) && Str::isUuid($uuid) ? ['asset_uuid' => strtolower($uuid)] : null;
    }

    private function anonymousPreviewDetectorVersion(array $engine): string
    {
        $limits = ['source_bytes' => (int) config('file_registry.face_preview.max_source_bytes'),
            'source_pixels' => (int) config('file_registry.face_preview.max_pixels'),
            'detector_dimension' => (int) config('file_registry.face_preview.max_detector_dimension'),
            'max_regions' => (int) config('file_registry.face_preview.max_suggestion_regions')];

        return hash('sha256', json_encode(['schema' => 'plos.anonymous_face_coverage_detector.v2',
            'mode' => 'detect_only_with_private_crops', 'engine' => $engine, 'limits' => $limits], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function scheduledCoveragePreviewIndexKey(string $assetUuid, string $sourceSha256, string $detectorVersion): string
    {
        return hash('sha256', json_encode(['schema' => 'plos.anonymous_face_preview_index.v2',
            'asset_uuid' => strtolower($assetUuid), 'source_sha256' => $sourceSha256,
            'detector_version' => $detectorVersion], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function assertScheduledCoveragePreviewAuthority(array $context, array $source, array $selector): void
    {
        $assetUuid = strtolower((string) ($context['asset_uuid'] ?? ''));
        $authority = $context['authority'] ?? null;
        $generation = is_array($authority) ? ($authority['generation'] ?? null) : null;
        $sourceRow = is_array($generation) ? ($generation['source'] ?? null) : null;
        $registrySnapshot = is_array($sourceRow)
            ? (($sourceRow['source_kind'] ?? null) === 'registry' ? ($sourceRow['source_snapshot'] ?? null) : ($sourceRow['registry_snapshot'] ?? null))
            : null;
        if (! Str::isUuid($assetUuid) || array_keys($selector) !== ['asset_uuid']
            || strtolower((string) ($selector['asset_uuid'] ?? '')) !== $assetUuid
            || strtolower((string) ($source['registry']['asset_uuid'] ?? '')) !== $assetUuid
            || ! is_array($generation) || ($generation['asset_uuid'] ?? null) !== $assetUuid
            || (int) ($generation['file_registry_id'] ?? 0) !== (int) ($source['registry']['id'] ?? 0)
            || ($generation['source_sha256'] ?? null) !== ($context['source_sha256'] ?? null)
            || ! is_array($sourceRow)
            || ! is_array($registrySnapshot) || (int) ($registrySnapshot['id'] ?? 0) !== (int) ($generation['file_registry_id'] ?? 0)
            || strtolower((string) ($registrySnapshot['asset_uuid'] ?? '')) !== $assetUuid
            || ($registrySnapshot['current_path'] ?? null) !== ($sourceRow['nextcloud_path'] ?? null)
            || ($sourceRow['observed_sha256'] ?? null) !== ($context['source_sha256'] ?? null)
            || ($sourceRow['nextcloud_path'] ?? null) !== ($source['current_path'] ?? null)) {
            throw new \RuntimeException('coverage_preview_binding_invalid');
        }
        app(FileRegistryFaceCoverageService::class)->assertAuthority($authority);
    }

    private function registerEngineBoundCoveragePreview(array $selector, string $packetPath, array $source, array $context): array
    {
        $assetUuid = strtolower((string) $context['asset_uuid']);
        $root = $this->anonymousPreviewPrivateRoot();
        $binding = ['asset_uuid' => $assetUuid];
        $packet = $root ? $this->anonymousPreviewPacket($packetPath, $root, $binding, 'plos.anonymous_face_coverage_preview.v2') : null;
        if (! $packet || ! $this->anonymousPreviewSourceMatches($selector, $source, $packet['packet'])
            || ($packet['packet']['detector_version'] ?? null) !== $context['detector_version']
            || ($packet['packet']['engine_provenance'] ?? null) !== $context['engine_provenance']) {
            return ['success' => false, 'error' => 'scheduled_preview_packet_invalid'];
        }
        $projection = ['source_sha256' => $packet['packet']['source_sha256'],
            'source_dimensions' => $packet['packet']['source_dimensions'],
            'face_region_convention' => 'normalized_top_left', 'coordinate_basis' => 'encoded_pixels',
            'regions' => array_map(fn (array $face) => ['face_index' => $face['face_index'],
                'face_region' => array_map(fn ($number) => (float) $number, $face['face_region'])], $packet['packet']['faces'])];
        $projectionBytes = json_encode($projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($projectionBytes) > (int) config('file_registry.face_preview.max_projection_bytes')) {
            return ['success' => false, 'error' => 'scheduled_preview_projection_over_budget'];
        }
        $engineBytes = json_encode($context['engine_provenance'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $bindingKey = $this->scheduledCoveragePreviewIndexKey($assetUuid, $context['source_sha256'], $context['detector_version']);
        $index = ['binding_key' => $bindingKey, 'asset_uuid' => $assetUuid, 'detector_version' => $context['detector_version'],
            'engine_provenance_json' => $engineBytes, 'selector_json' => json_encode($binding, JSON_THROW_ON_ERROR),
            'source_sha256' => $context['source_sha256'], 'packet_sha256' => $packet['sha256'],
            'run_uuid' => basename(dirname($packetPath)), 'projection_json' => $projectionBytes,
            'projection_sha256' => hash('sha256', $projectionBytes), 'created_at' => now(), 'updated_at' => now()];
        $this->assertCoveragePreviewAuthority($context, $source, $selector);
        if (! $this->anonymousPreviewSourceMatches($selector, $source, $packet['packet'])
            || ! $this->anonymousPreviewPrivatePath($packetPath, $root)
            || hash_file('sha256', $packetPath) !== $packet['sha256']) {
            return ['success' => false, 'error' => 'scheduled_preview_source_changed'];
        }
        try {
            $reused = DB::transaction(function () use ($index, $context, $source, $selector, $packet, $packetPath, $root): bool {
                $this->assertCoveragePreviewCurrent($context, $source, $selector);
                if (! $this->anonymousPreviewSourceMatches($selector, $source, $packet['packet'])
                    || ! $this->anonymousPreviewPrivatePath($packetPath, $root)
                    || hash_file('sha256', $packetPath) !== $packet['sha256']) {
                    throw new \RuntimeException('scheduled_preview_source_changed');
                }
                $existing = DB::table('anonymous_face_preview_indexes')->where('binding_key', $index['binding_key'])->lockForUpdate()->first();
                if ($existing !== null) {
                    foreach (['asset_uuid', 'detector_version', 'engine_provenance_json', 'selector_json', 'source_sha256',
                        'projection_json', 'projection_sha256'] as $key) {
                        if ($existing->{$key} !== $index[$key]) {
                            throw new \RuntimeException('scheduled_preview_index_conflict');
                        }
                    }
                    $this->assertCoveragePreviewCurrent($context, $source, $selector);

                    return true;
                }
                DB::table('anonymous_face_preview_indexes')->insert($index);
                $this->assertCoveragePreviewCurrent($context, $source, $selector);
                if (! $this->anonymousPreviewSourceMatches($selector, $source, $packet['packet'])
                    || ! $this->anonymousPreviewPrivatePath($packetPath, $root)
                    || hash_file('sha256', $packetPath) !== $packet['sha256']) {
                    throw new \RuntimeException('scheduled_preview_source_changed');
                }

                return false;
            });
        } catch (QueryException $exception) {
            // A duplicate-key race is reusable only after a full exact read-back.
            $sqlState = (string) ($exception->errorInfo[0] ?? '');
            $driverMessage = strtolower($exception->getMessage());
            $duplicate = $sqlState === '23505'
                || ($sqlState === '23000' && (str_contains($driverMessage, 'duplicate entry')
                    || str_contains($driverMessage, 'unique constraint failed')));
            if (! $duplicate) {
                return ['success' => false, 'error' => 'scheduled_preview_index_conflict'];
            }
            $existing = DB::table('anonymous_face_preview_indexes')->where('binding_key', $bindingKey)->first();
            if (! $existing || $existing->asset_uuid !== $index['asset_uuid']
                || $existing->detector_version !== $index['detector_version']
                || $existing->selector_json !== $index['selector_json']
                || $existing->engine_provenance_json !== $index['engine_provenance_json']
                || $existing->source_sha256 !== $index['source_sha256']
                || $existing->projection_json !== $index['projection_json']
                || $existing->projection_sha256 !== $index['projection_sha256']) {
                return ['success' => false, 'error' => 'scheduled_preview_index_conflict'];
            }
            try {
                $this->assertCoveragePreviewCurrent($context, $source, $selector);
            } catch (\Throwable) {
                return ['success' => false, 'error' => 'scheduled_preview_authority_changed'];
            }
            $reused = true;
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'scheduled_preview_index_conflict'];
        }

        try {
            $this->assertCoveragePreviewCurrent($context, $source, $selector);
            $stored = DB::table('anonymous_face_preview_indexes')->where('binding_key', $bindingKey)->first();
            if (! $stored || $stored->asset_uuid !== $index['asset_uuid']
                || $stored->detector_version !== $index['detector_version']
                || $stored->engine_provenance_json !== $index['engine_provenance_json']
                || $stored->selector_json !== $index['selector_json']
                || $stored->source_sha256 !== $index['source_sha256']
                || $stored->projection_json !== $index['projection_json']
                || $stored->projection_sha256 !== $index['projection_sha256']) {
                return ['success' => false, 'error' => 'scheduled_preview_index_conflict'];
            }
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'scheduled_preview_authority_changed'];
        }

        return ['success' => true, 'binding_key' => $bindingKey, 'face_count' => count($projection['regions']), 'reused' => $reused];
    }

    private function assertCoveragePreviewAuthority(array $context, array $source, array $selector): void
    {
        if (($context['native_cli'] ?? false) === true) {
            $this->assertNativeCoveragePreviewAuthority($context, $source, $selector);

            return;
        }
        if (array_key_exists('native_cli', $context) || array_key_exists('caller', $context) || array_key_exists('plan', $context)) {
            throw new \RuntimeException('coverage_preview_authority_invalid');
        }
        $this->assertScheduledCoveragePreviewAuthority($context, $source, $selector);
    }

    private function assertCoveragePreviewCurrent(array $context, array $source, array $selector): void
    {
        $this->assertCoveragePreviewAuthority($context, $source, $selector);
        $engine = $this->anonymousGenerationEngineVersion();
        if ($engine !== ($context['engine_provenance'] ?? null)
            || $this->anonymousPreviewDetectorVersion($engine) !== ($context['detector_version'] ?? null)) {
            throw new \RuntimeException('coverage_detector_version_changed');
        }
    }

    private function assertNativeCoveragePreviewAuthority(array $context, array $source, array $selector): void
    {
        $plan = $context['plan'] ?? null;
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        if (! is_array($plan) || ! is_array($context['caller'] ?? null) || $context['caller'] !== $caller
            || array_keys($selector) !== ['asset_uuid'] || strtolower((string) ($selector['asset_uuid'] ?? '')) !== ($plan['asset_uuid'] ?? null)
            || strtolower((string) ($source['registry']['asset_uuid'] ?? '')) !== ($plan['asset_uuid'] ?? null)
            || (int) ($source['registry']['id'] ?? 0) !== ($plan['file_registry_id'] ?? null)
            || ($context['asset_uuid'] ?? null) !== ($plan['asset_uuid'] ?? null)
            || ($context['source_sha256'] ?? null) !== ($plan['source_sha256'] ?? null)
            || ($context['engine_provenance'] ?? null) !== ($plan['engine_provenance'] ?? null)
            || ($context['detector_version'] ?? null) !== ($plan['coverage_preview_detector_version'] ?? null)) {
            throw new \RuntimeException('coverage_preview_binding_invalid');
        }
        $this->assertNativeCoveragePreviewPlan($plan);
        $this->assertNativeCoveragePreviewCaller($caller, $plan);
        $current = $this->anonymousCoveragePreviewPlan($plan['asset_uuid']);
        if ($current !== $plan || ($source['current_path'] ?? null) !== ($this->facePreviewSource($selector)['current_path'] ?? null)) {
            throw new \RuntimeException('coverage_preview_binding_changed');
        }
    }

    private function anonymousPreviewIndexKey(array $binding): string
    {
        return hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR));
    }

    private function anonymousPreviewPrivateRoot(): ?string
    {
        $configured = config('file_registry.face_preview.private_directory');
        if (! is_string($configured) || ! str_starts_with($configured, 'app/private/')
            || str_contains($configured, '\\') || str_contains($configured, "\0")
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $configured)) {
            return null;
        }
        $root = storage_path($configured);

        return $this->anonymousPreviewPrivatePath($root, storage_path()) && is_dir($root)
            && (fileperms($root) & 0777) === 0700 ? $root : null;
    }

    private function anonymousPreviewPrivatePath(string $path, string $root): bool
    {
        if (! str_starts_with($path, $root.'/') || str_contains($path, '\\') || str_contains($path, "\0")
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) || realpath($path) !== $path) {
            return false;
        }
        $cursor = $root;
        foreach (explode('/', substr($path, strlen($root) + 1)) as $part) {
            $cursor .= '/'.$part;
            if (is_link($cursor)) {
                return false;
            }
        }

        return true;
    }

    private function anonymousPreviewJson(string $path, string $root, int $limit): ?array
    {
        if ($limit < 1 || ! $this->anonymousPreviewPrivatePath($path, $root) || ! is_file($path)
            || (fileperms($path) & 0777) !== 0600 || filesize($path) > $limit) {
            return null;
        }
        $bytes = file_get_contents($path, false, null, 0, $limit + 1);
        if (! is_string($bytes) || strlen($bytes) > $limit) {
            return null;
        }
        $data = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);

        return is_array($data) ? ['data' => $data, 'sha256' => hash('sha256', $bytes)] : null;
    }

    private function anonymousPreviewSourceMatches(array $selector, array $source, array $packet, bool $verifyOrientation = true): bool
    {
        $path = $this->facePreviewLocalPath($source['current_path']);
        if (! $path) {
            return false;
        }
        clearstatcache(true, $path);
        if (filesize($path) > (int) config('file_registry.face_preview.max_source_bytes')) {
            return false;
        }
        $dimensions = @getimagesize($path);
        if (! $dimensions || min($dimensions[0], $dimensions[1]) < 1
            || (int) config('metadata_writeback.preview_max_source_pixels') < $dimensions[0] * $dimensions[1]) {
            return false;
        }
        if ($verifyOrientation && $dimensions[2] === IMAGETYPE_JPEG) {
            $exif = @exif_read_data($path);
            $orientation = $exif['Orientation'] ?? 1;
            if ($packet['orientation_provenance']['exif_orientation'] !== (in_array($orientation, range(1, 8), true) ? $orientation : null)) {
                return false;
            }
        }

        return [$dimensions[0], $dimensions[1]] === $packet['source_dimensions']
            && $this->facePreviewSource($selector) === $source
            && $this->facePreviewLocalPath($source['current_path']) === $path
            && @hash_file('sha256', $path) === $packet['source_sha256'];
    }

    private function anonymousPreviewPacket(string $path, string $root, array $binding, string $expectedSchema = 'plos.anonymous_face_preview.v1'): ?array
    {
        if (basename($path) !== 'preview.json' || dirname(dirname($path)) !== $root
            || ! Str::isUuid(basename(dirname($path))) || ! is_dir(dirname($path)) || (fileperms(dirname($path)) & 0777) !== 0700) {
            return null;
        }
        $json = $this->anonymousPreviewJson($path, $root, (int) config('file_registry.face_preview.max_packet_bytes'));
        $p = $json['data'] ?? [];
        $allowed = ['schema', 'operator_identity_decision_required', 'selector', 'queue_id', 'media_id', 'tree_id', 'asset_uuid',
            'current_source_verified', 'source_sha256', 'source_dimensions', 'face_region_convention', 'coordinate_basis',
            'orientation_provenance', 'decode_provenance', 'faces', 'detector_provenance', 'recorded_at'];
        if ($expectedSchema === 'plos.anonymous_face_coverage_preview.v2') {
            $allowed = [...$allowed, 'detector_version', 'engine_provenance'];
        }
        if (! $json || array_diff(array_keys($p), $allowed) || ($p['schema'] ?? null) !== $expectedSchema
            || ($p['operator_identity_decision_required'] ?? null) !== true || ($p['current_source_verified'] ?? null) !== true
            || ! preg_match('/^[a-f0-9]{64}$/D', $p['source_sha256'] ?? '')
            || ($p['face_region_convention'] ?? null) !== 'normalized_top_left' || ($p['coordinate_basis'] ?? null) !== 'encoded_pixels'
            || ! is_array($p['source_dimensions'] ?? null) || ! array_is_list($p['source_dimensions']) || count($p['source_dimensions']) !== 2
            || ! is_int($p['source_dimensions'][0]) || ! is_int($p['source_dimensions'][1]) || min($p['source_dimensions']) < 1
            || ! is_array($p['faces'] ?? null) || ! array_is_list($p['faces'])
            || count($p['faces']) > min(100, (int) config('file_registry.face_preview.max_suggestion_regions'))) {
            return null;
        }
        if ($expectedSchema === 'plos.anonymous_face_coverage_preview.v2'
            && (! is_string($p['detector_version'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $p['detector_version'])
                || ! is_array($p['engine_provenance'] ?? null) || ! is_string($p['asset_uuid'] ?? null)
                || ! Str::isUuid($p['asset_uuid']) || strtolower($p['asset_uuid']) !== $p['asset_uuid'])) {
            return null;
        }
        $decode = $p['decode_provenance'] ?? null;
        if (! is_array($decode) || ! is_bool($decode['reduced'] ?? null)) {
            return null;
        }
        foreach (['decoded_dimensions' => 2, 'draft_box' => 4, 'original_scale' => 2] as $key => $size) {
            if (! is_array($decode[$key] ?? null) || ! array_is_list($decode[$key]) || count($decode[$key]) !== $size) {
                return null;
            }
        }
        if (! $this->validPreviewDecode($decode, $p['source_dimensions'])) {
            return null;
        }
        $s = $p['selector'] ?? null;
        if (! is_array($s) || ! (
            (array_keys($s) === ['asset_uuid'] && is_string($s['asset_uuid']) && Str::isUuid($s['asset_uuid']) && $s['asset_uuid'] === ($p['asset_uuid'] ?? null))
            || (count($s) === 2 && isset($s['tree_id']) && (int) $s['tree_id'] > 0 && (int) $s['tree_id'] === ($p['tree_id'] ?? null)
                && ((isset($s['media_id']) && (int) $s['media_id'] > 0 && (int) $s['media_id'] === ($p['media_id'] ?? null))
                    || (isset($s['face_review_id']) && (int) $s['face_review_id'] > 0 && (int) $s['face_review_id'] === ($p['queue_id'] ?? null))))
        )) {
            return null;
        }
        if (array_diff(array_keys($p['decode_provenance']), ['method', 'source_dimensions', 'decoded_dimensions', 'draft_box', 'original_scale', 'reduced'])) {
            return null;
        }
        foreach ($binding as $key => $value) {
            if (($p[$key] ?? null) !== $value) {
                return null;
            }
        }
        $detector = array_key_exists('detector_provenance', $p);
        if ($detector && (! $this->validPreviewDetector($p['detector_provenance'], $p['decode_provenance'], $p['source_dimensions'], $p['source_sha256'])
            || array_diff(array_keys($p['detector_provenance']), ['source_sha256', 'source_dimensions', 'decoded_dimensions', 'draft_box', 'resize_box', 'detector_dimensions', 'method', 'original_scale', 'reduced', 'model', 'upsample']))) {
            return null;
        }
        $raster = $detector ? $p['detector_provenance']['detector_dimensions'] : $p['decode_provenance']['decoded_dimensions'];
        $orientation = $p['orientation_provenance'] ?? [];
        if (! is_array($orientation) || array_diff(array_keys($orientation), ['exif_orientation', 'transform', 'coordinate_basis'])
            || ($orientation['transform'] ?? null) !== 'none' || ($orientation['coordinate_basis'] ?? null) !== 'encoded_pixels'
            || ! array_key_exists('exif_orientation', $orientation)
            || ($orientation['exif_orientation'] !== null && (! is_int($orientation['exif_orientation']) || $orientation['exif_orientation'] < 1 || $orientation['exif_orientation'] > 8))) {
            return null;
        }
        foreach ($p['faces'] as $index => $face) {
            if (! is_array($face) || array_diff(array_keys($face), ['face_index', 'face_region', 'face_region_convention', 'coordinate_basis', 'crop_path', 'crop_provenance'])
                || ($face['face_index'] ?? null) !== $index || ($face['face_region_convention'] ?? null) !== 'normalized_top_left'
                || ($face['coordinate_basis'] ?? null) !== 'encoded_pixels'
                || array_keys($face['face_region'] ?? []) !== ['x', 'y', 'w', 'h']) {
                return null;
            }
            $box = $face['face_region'];
            foreach ($box as $value) {
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                    return null;
                }
            }
            $crop = $face['crop_path'] ?? null;
            $proof = $face['crop_provenance'] ?? [];
            if (! is_array($proof) || array_diff(array_keys($proof), ['source_sha256', 'coordinate_basis', 'decoded_dimensions', 'detector_dimensions', 'reduced'])
                || $box['x'] < 0 || $box['y'] < 0 || $box['w'] <= 0 || $box['h'] <= 0 || $box['x'] + $box['w'] > 1 || $box['y'] + $box['h'] > 1
                || ! is_string($crop) || ! $this->anonymousPreviewPrivatePath($crop, dirname($path)) || ! is_file($crop)
                || (fileperms($crop) & 0777) !== 0600 || filesize($crop) > (int) config('file_registry.face_preview.max_packet_bytes')
                || ($proof['source_sha256'] ?? null) !== $p['source_sha256'] || ($proof['coordinate_basis'] ?? null) !== ($detector ? 'detector_encoded_pixels' : 'decoded_encoded_pixels')
                || ($proof['decoded_dimensions'] ?? null) !== $p['decode_provenance']['decoded_dimensions']
                || ($detector && ($proof['detector_dimensions'] ?? null) !== $raster)
                || ($proof['reduced'] ?? null) !== ($p['decode_provenance']['reduced'] || ($detector && $p['detector_provenance']['reduced']))) {
                return null;
            }
            $image = @getimagesize($crop);
            if (! $image || $image[2] !== IMAGETYPE_JPEG || min($image[0], $image[1]) < 1
                || $image[0] > $raster[0] || $image[1] > $raster[1]) {
                return null;
            }
        }

        return ['packet' => $p, 'sha256' => $json['sha256']];
    }

    private function facePreviewSource(array $selector): ?array
    {
        $queue = null;
        $media = null;
        $registry = null;
        if (isset($selector['asset_uuid']) && count($selector) === 1 && Str::isUuid($selector['asset_uuid'])) {
            $registry = DB::selectOne("SELECT id, asset_uuid, current_path, status, extension, nextcloud_modified_at, content_hash
                FROM file_registry WHERE asset_uuid = ? AND status = 'active'", [$selector['asset_uuid']]);
            if (! $registry || ! in_array(strtolower($registry->extension), config('file_types.image'), true)) {
                return null;
            }
            $path = $registry->current_path;
        } elseif (isset($selector['tree_id']) && (int) $selector['tree_id'] > 0 && count($selector) === 2) {
            if (isset($selector['face_review_id']) && (int) $selector['face_review_id'] > 0) {
                $queue = DB::selectOne("SELECT id, tree_id, media_id, file_registry_face_id, status, updated_at
                    FROM genealogy_face_match_queue WHERE id = ? AND tree_id = ? AND status = 'pending'", [
                    $selector['face_review_id'], $selector['tree_id'],
                ]);
                if (! $queue) {
                    return null;
                }
                $mediaId = $queue->media_id;
            } elseif (isset($selector['media_id']) && (int) $selector['media_id'] > 0) {
                $mediaId = $selector['media_id'];
            } else {
                return null;
            }
            if ($mediaId) {
                $media = DB::selectOne('SELECT id, tree_id, nextcloud_path, original_path, updated_at
                    FROM genealogy_media WHERE id = ? AND tree_id = ?', [$mediaId, $selector['tree_id']]);
                if (! $media) {
                    return null;
                }
                $path = $media->nextcloud_path ?: $media->original_path;
                $matches = DB::select("SELECT id, asset_uuid, current_path, status, extension, nextcloud_modified_at, content_hash
                    FROM file_registry WHERE current_path = ? AND status = 'active' LIMIT 2", [$path]);
                if (count($matches) > 1) {
                    return null;
                }
                $registry = $matches[0] ?? null;
            } elseif ($queue && $queue->file_registry_face_id) {
                $registry = DB::selectOne("SELECT fr.id, fr.asset_uuid, fr.current_path, fr.status, fr.extension, fr.nextcloud_modified_at, fr.content_hash
                    FROM file_registry fr JOIN file_registry_faces f ON f.file_registry_id = fr.id
                    WHERE f.id = ? AND fr.status = 'active'", [$queue->file_registry_face_id]);
                if (! $registry) {
                    return null;
                }
                $path = $registry->current_path;
            } else {
                return null;
            }
        } else {
            return null;
        }

        return ['current_path' => $path, 'queue_id' => $queue->id ?? null, 'media_id' => $media->id ?? null,
            'tree_id' => $media->tree_id ?? $queue->tree_id ?? null, 'queue' => $queue ? (array) $queue : null,
            'media' => $media ? (array) $media : null, 'registry' => $registry ? (array) $registry : null];
    }

    private function facePreviewLocalPath(?string $path): ?string
    {
        $root = realpath((string) config('services.nextcloud.data_path', ''));
        if ($root === false || ! is_dir($root) || $path === null || $path === '' || str_contains($path, "\0")
            || str_contains($path, '\\') || in_array('..', explode('/', $path), true)) {
            return null;
        }
        $local = app(NextcloudFileApiService::class)->localPath($path);
        $resolved = is_string($local) ? realpath($local) : false;

        return $resolved !== false && str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            && is_file($resolved) && is_readable($resolved) ? $resolved : null;
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
        // Raw detection arrays cannot prove a retained source-vector producer.
        // Qualified IDs use assignToCluster; generation/restoration keep their
        // independent source-bound publishers and operator admission gates.
        return ['success' => false, 'status' => 'held', 'reason' => 'producer_context_required',
            'matching_complete' => false, 'operator_review_required' => false];
    }

    private function detectionSourceIsCurrent(int $fileRegistryId, string $imagePath, string $sourceHash, bool $lock = false): bool
    {
        $file = DB::selectOne('SELECT current_path, status FROM file_registry WHERE id = ?'.($lock ? ' FOR UPDATE' : ''), [$fileRegistryId]);
        if (! $file || $file->status !== 'active') {
            return false;
        }
        $path = realpath($file->current_path) ?: app(NextcloudFileApiService::class)->localPath($file->current_path);
        $hash = @hash_file('sha256', $imagePath);

        return is_string($path) && realpath($path) === realpath($imagePath)
            && is_string($hash) && hash_equals($sourceHash, $hash);
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
                    fe.file_registry_id, fe.file_registry_face_id, fe.source_sha256, fe.face_region_convention, fe.source_current, fe.superseded_at,
                    fe.region_x, fe.region_y, fe.region_w, fe.region_h,
                    pc.name as cluster_name,
                    pc.genealogy_person_id,
                    pc.face_count,
                    1 - (fe.embedding <=> ?::vector) as confidence
                FROM face_embeddings fe
                LEFT JOIN person_clusters pc ON pc.id = fe.person_cluster_id
                WHERE fe.source_current = TRUE AND fe.superseded_at IS NULL AND fe.source_sha256 IS NOT NULL AND fe.face_region_convention = \'normalized_top_left\'
                AND 1 - (fe.embedding <=> ?::vector) >= ?
                ORDER BY fe.embedding <=> ?::vector
                LIMIT ?
            ', [$embeddingStr, $embeddingStr, 1 - $tolerance, $embeddingStr, $limit]);

            return array_values(array_map(fn ($m) => (array) $m, array_filter($matches, fn ($m) => $this->embeddingHasCurrentSourceEvidence($m))));
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
                WHERE fe.person_cluster_id = ? AND fe.source_current = TRUE AND fe.superseded_at IS NULL
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
                     WHERE fe2.person_cluster_id = pc.id AND fe2.source_current = TRUE AND fe2.superseded_at IS NULL
                     ORDER BY fe2.embedding <=> ?::vector LIMIT 1) as best_match_crop
                FROM person_clusters pc
                INNER JOIN face_embeddings fe ON fe.person_cluster_id = pc.id AND fe.source_current = TRUE AND fe.superseded_at IS NULL
                WHERE pc.status IN ('confirmed', 'unreviewed')
                GROUP BY pc.id, pc.name, pc.status, pc.face_count, pc.genealogy_person_id
                HAVING MAX(1 - (fe.embedding <=> ?::vector)) >= ?
                ORDER BY MAX(1 - (fe.embedding <=> ?::vector)) DESC
                LIMIT ?
            ", [$embeddingStr, $embeddingStr, $embeddingStr, 1 - $tolerance, $embeddingStr, $limit]);

            return array_values(array_map(fn ($m) => (array) $m, array_filter($matches, fn ($m) => $this->clusterHasCurrentSourceEvidence((int) $m->cluster_id))));
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
            if (! $this->clusterHasCurrentSourceEvidence($confirmedClusterId)) {
                return [];
            }
            // Get all embeddings from the confirmed cluster
            $clusterEmbeddings = DB::connection('pgsql_rag')->select('
                SELECT id, embedding::text as embedding_str
                FROM face_embeddings
                WHERE person_cluster_id = ? AND source_current = TRUE AND superseded_at IS NULL
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
                         WHERE fe2.person_cluster_id = pc.id AND fe2.source_current = TRUE AND fe2.superseded_at IS NULL
                         ORDER BY fe2.embedding <=> ?::vector LIMIT 1) as sample_crop
                    FROM face_embeddings fe
                    INNER JOIN person_clusters pc ON pc.id = fe.person_cluster_id
                    WHERE pc.status = 'unreviewed'
                    AND fe.source_current = TRUE AND fe.superseded_at IS NULL
                    AND pc.id != ?
                    GROUP BY pc.id, pc.name, pc.face_count
                    HAVING MAX(1 - (fe.embedding <=> ?::vector)) >= ?
                ", [$ce->embedding_str, $ce->embedding_str, $confirmedClusterId, $ce->embedding_str, 1 - $tolerance]);

                foreach ($matches as $m) {
                    if (! $this->clusterHasCurrentSourceEvidence((int) $m->cluster_id)) {
                        continue;
                    }
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
     * Suggest similar groups after confirmation without expanding accepted membership.
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
        $suggested = [];
        try {
            $this->updateClusterCentroid($confirmedClusterId);
            $confirmed = DB::connection('pgsql_rag')->selectOne('SELECT centroid::text AS centroid_str FROM person_clusters WHERE id = ? AND centroid IS NOT NULL', [$confirmedClusterId]);
            if ($confirmed && $confirmed->centroid_str) {
                $candidates = DB::connection('pgsql_rag')->select("SELECT pc.id AS cluster_id, pc.name, pc.face_count,
                    1 - (pc.centroid <=> ?::vector) AS centroid_similarity
                    FROM person_clusters pc WHERE pc.status = 'unreviewed' AND pc.centroid IS NOT NULL
                    AND pc.face_count > 0 AND (pc.merge_retry IS NULL OR pc.merge_retry < 3)
                    AND 1 - (pc.centroid <=> ?::vector) >= 0.70
                    ORDER BY pc.centroid <=> ?::vector LIMIT 50", [$confirmed->centroid_str, $confirmed->centroid_str, $confirmed->centroid_str]);
                foreach ($candidates as $candidate) {
                    if (! $this->clusterHasCurrentSourceEvidence($confirmedClusterId) || ! $this->clusterHasCurrentSourceEvidence((int) $candidate->cluster_id)) {
                        continue;
                    }
                    if ((float) $candidate->centroid_similarity < 0.75) {
                        continue;
                    }
                    $suggested[] = ['cluster_id' => $candidate->cluster_id, 'name' => $candidate->name,
                        'face_count' => $candidate->face_count, 'centroid_similarity' => (float) $candidate->centroid_similarity,
                        'operator_review_required' => true];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('FaceEmbedding: propagation suggestions unavailable', ['exception' => $e::class]);
        }

        return ['auto_merged' => [], 'suggested' => $suggested, 'total_found' => count($suggested)];
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
        float $confidence,
        string $sourceHash,
        string $imagePath
    ): ?int {
        return DB::connection('pgsql_rag')->transaction(function () use ($fileRegistryId, $embedding, $normalized, $personClusterId, $cropPath, $matchedFaceId, $confidence, $sourceHash, $imagePath): ?int {
            DB::connection('pgsql_rag')->selectOne('SELECT id FROM person_clusters WHERE id = ? FOR UPDATE', [$personClusterId]);
            $currentHash = @hash_file('sha256', $imagePath);
            if (! is_string($currentHash) || ! hash_equals($sourceHash, $currentHash)
                || ! $this->detectionSourceIsCurrent($fileRegistryId, $imagePath, $sourceHash)
                || ! $this->canAutomaticallyGroupCluster($personClusterId)) {
                return null;
            }
            $embeddingStr = PgVector::literal($embedding, self::VECTOR_LITERAL_PRECISION);

            $result = DB::connection('pgsql_rag')->select('
            INSERT INTO face_embeddings (
                file_registry_id, person_cluster_id, embedding,
                region_x, region_y, region_w, region_h,
                crop_path, matched_face_id, match_confidence,
                embedding_model, source_sha256, face_region_convention, source_current, created_at, updated_at
            ) VALUES (?, ?, ?::vector, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, NOW(), NOW())
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
                'dlib_face_recognition_resnet_model_v1', $sourceHash, 'normalized_top_left',
            ]);

            $id = $result[0]->id;

            // Update cluster face count
            DB::connection('pgsql_rag')->update('
            UPDATE person_clusters
            SET face_count = face_count + 1, updated_at = NOW()
            WHERE id = ?
        ', [$personClusterId]);

            return $id;
        });
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

    /** Compare fresh current-source regions, independently of detector crop caches. */
    public function compareSourceFaceRegionsWithAI(int $fileRegistryId, string $imagePath, string $sourceHash, array $region, array $candidate, ?array $sourceEmbedding = null, string $sourceEmbeddingModel = 'dlib_face_recognition_resnet_model_v1'): array
    {
        $held = ['success' => false, 'decision' => 'abstain', 'same_person' => null,
            'confidence' => null, 'operator_review_required' => true];
        $directory = null;
        try {
            $candidateId = (int) ($candidate['face_id'] ?? 0);
            $readCandidate = fn () => DB::connection('pgsql_rag')->selectOne('SELECT person_cluster_id, file_registry_face_id, file_registry_id, source_sha256, face_region_convention, source_current, superseded_at, region_x, region_y, region_w, region_h, embedding, embedding_model FROM face_embeddings WHERE id = ?', [$candidateId]);
            $pinned = $readCandidate();
            $sourceFile = DB::selectOne('SELECT current_path, status FROM file_registry WHERE id = ?', [$fileRegistryId]);
            if (! $sourceFile || $sourceFile->status !== 'active' || ! $pinned || ! $this->embeddingHasCurrentSourceEvidence($pinned)
                || ! $this->detectionSourceIsCurrent($fileRegistryId, $imagePath, $sourceHash)) {
                return $held + ['error_code' => 'comparison_source_unavailable'];
            }
            foreach (['person_cluster_id', 'file_registry_face_id', 'file_registry_id', 'source_sha256', 'face_region_convention', 'source_current', 'superseded_at', 'region_x', 'region_y', 'region_w', 'region_h'] as $key) {
                if (! array_key_exists($key, $candidate) || $candidate[$key] != $pinned->{$key}) {
                    return $held + ['error_code' => 'comparison_source_changed'];
                }
            }
            $candidateFile = DB::selectOne('SELECT current_path, status FROM file_registry WHERE id = ?', [(int) $pinned->file_registry_id]);
            if (! $candidateFile || $candidateFile->status !== 'active') {
                return $held + ['error_code' => 'comparison_source_unavailable'];
            }
            $bridge = app(FaceLinkBridgeService::class);
            $candidateRegion = ['x' => $pinned->region_x, 'y' => $pinned->region_y, 'w' => $pinned->region_w, 'h' => $pinned->region_h];
            $inputs = [[$sourceFile->current_path, $sourceHash, $region, $fileRegistryId],
                [$candidateFile->current_path, $pinned->source_sha256, $candidateRegion, (int) $pinned->file_registry_id]];
            $directory = sys_get_temp_dir().'/'.Str::uuid()->toString();
            if (! mkdir($directory, 0700)) {
                return $held + ['error_code' => 'comparison_source_unavailable'];
            }
            $paths = [];
            $evidence = [];
            foreach ($inputs as $index => [$path, $hash, $box, $registryId]) {
                $source = $bridge->reviewSourcePath($path, $hash, true);
                if (! ($source['success'] ?? false) || ($source['source_sha256'] ?? null) !== $hash
                    || ($source['face_region_convention'] ?? null) !== 'normalized_top_left'
                    || ($source['coordinate_basis'] ?? null) !== 'encoded_pixels') {
                    return $held + ['error_code' => 'comparison_source_unavailable'];
                }
                $crop = $this->comparisonRegionCrop($source['bytes'] ?? '', $box);
                if ($crop === null) {
                    return $held + ['error_code' => 'comparison_region_unavailable'];
                }
                $cropPath = $directory.'/'.$index.'.jpg';
                if (file_put_contents($cropPath, $crop) !== strlen($crop) || ! chmod($cropPath, 0600)) {
                    return $held + ['error_code' => 'comparison_source_unavailable'];
                }
                $paths[] = $cropPath;
                $vectorPin = $this->comparisonVectorPin($index === 0 ? $sourceEmbedding : $pinned->embedding, $index === 0 ? $sourceEmbeddingModel : $pinned->embedding_model);
                $evidence[] = ($vectorPin ?? []) + ['file_registry_id' => $registryId, 'source_sha256' => $hash,
                    'region' => array_map(static fn ($value) => (float) $value, $box), 'face_region_convention' => 'normalized_top_left', 'coordinate_basis' => 'encoded_pixels'];
            }
            $result = $this->compareFaceCropsWithAI($paths[0], $paths[1]);
            $current = $readCandidate();
            $currentSourceFile = DB::selectOne('SELECT current_path, status FROM file_registry WHERE id = ?', [$fileRegistryId]);
            $currentFile = DB::selectOne('SELECT current_path, status FROM file_registry WHERE id = ?', [(int) $pinned->file_registry_id]);
            if (! $currentSourceFile || (array) $currentSourceFile !== (array) $sourceFile
                || ! $current || (array) $current !== (array) $pinned || ! $currentFile || (array) $currentFile !== (array) $candidateFile
                || ! $this->embeddingHasCurrentSourceEvidence($current)
                || ! $this->detectionSourceIsCurrent($fileRegistryId, $imagePath, $sourceHash)) {
                return $held + ['error_code' => 'comparison_source_changed'];
            }

            return $result + ['source_evidence' => $evidence, 'candidate_embedding_id' => $candidateId];
        } catch (\Throwable $error) {
            Log::warning('FaceEmbedding: Current-source advisory comparison failed', ['exception_type' => $error::class]);

            return $held + ['error_code' => 'comparison_source_unavailable'];
        } finally {
            if ($directory !== null) {
                File::deleteDirectory($directory);
            }
        }
    }

    /** Read current technical embedding evidence; no identity or cluster claims are projected. */
    public function comparisonEmbeddingEvidence(int $id, bool $includeGenerationProvenance = false, ?float $deadline = null): ?array
    {
        try {
            $this->checkMatchingProofDeadline($deadline);
            $row = DB::connection('pgsql_rag')->selectOne('SELECT * FROM face_embeddings WHERE id = ?', [$id]);
            if (! $row || ! ($deadline === null ? $this->embeddingHasCurrentSourceEvidence($row) : $this->embeddingHasCurrentSourceEvidence($row, $deadline))) {
                return null;
            }
            $vector = $this->comparisonVectorPin($row->embedding, $row->embedding_model);
            if ($vector === null) {
                return null;
            }
            $region = [];
            foreach (['x', 'y', 'w', 'h'] as $axis) {
                $region[$axis] = (float) $row->{'region_'.$axis};
            }

            // The durable photo-advice contract retains its seven original pins.
            // Only reference calibration requests the additional checkpoint proof.
            $evidence = $vector + ['file_registry_id' => (int) $row->file_registry_id, 'source_sha256' => $row->source_sha256,
                'region' => $region, 'face_region_convention' => 'normalized_top_left', 'coordinate_basis' => 'encoded_pixels'];
            if ($includeGenerationProvenance) {
                $evidence['generation_provenance'] = $this->currentEmbeddingGenerationProvenance($row, $deadline);
            }
            $this->checkMatchingProofDeadline($deadline);

            return $evidence;
        } catch (\Throwable $error) {
            if ($deadline !== null && str_starts_with($error->getMessage(), 'matching_proof_budget_unsupported:')) {
                throw $error;
            }

            return null;
        }
    }

    /** Read the existing pair comparison pins and exact producer contract; no inference or writes. */
    public function comparisonEmbeddingContractEvidence(int $id, ?float $deadline = null): array
    {
        $this->checkMatchingProofDeadline($deadline);
        $row = DB::connection('pgsql_rag')->selectOne(
            'SELECT id, person_cluster_id, file_registry_id, file_registry_face_id, source_sha256, face_region_convention, source_current, superseded_at, region_x, region_y, region_w, region_h, embedding, embedding_model FROM face_embeddings WHERE id = ?',
            [$id],
        );
        $pin = $deadline === null ? $this->comparisonEmbeddingEvidence($id, true) : $this->comparisonEmbeddingEvidence($id, true, $deadline);
        $provenance = $pin['generation_provenance'] ?? null;
        if (! $row || ! is_array($pin) || ! is_array($provenance)
            || ! ($deadline === null ? $this->embeddingHasCurrentSourceEvidence($row) : $this->embeddingHasCurrentSourceEvidence($row, $deadline))) {
            throw new \RuntimeException('comparison_source_unavailable');
        }
        unset($pin['generation_provenance']);
        if (! $this->comparisonEvidenceMatchesStoredRow($row, $pin)) {
            throw new \RuntimeException('comparison_source_changed');
        }
        $engine = $provenance['engine_provenance'] ?? null;
        $modelHash = $provenance['model_sha256'] ?? null;
        if (! is_array($engine) || ($engine['embedding_model'] ?? null) !== ($pin['embedding_model'] ?? null)
            || ($provenance['proof_origin'] ?? null) === null || ! is_string($modelHash)
            || ! preg_match('/^[a-f0-9]{64}$/D', $modelHash)
            || ! is_string($provenance['embedding_float32_sha256'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $provenance['embedding_float32_sha256'])) {
            throw new \RuntimeException('comparison_model_proof_unavailable');
        }
        $dimension = $engine['embedding_dimension'] ?? $engine['embedding_dimensions'] ?? 128;
        if ($dimension !== 128) {
            throw new \RuntimeException('comparison_model_contract_incompatible');
        }
        $contract = ['embedding_model' => $pin['embedding_model'], 'model_sha256' => $modelHash,
            'dimension' => 128, 'engine_contract_sha256' => hash('sha256', $this->canonicalFaceProvenanceJson($engine))];
        $this->checkMatchingProofDeadline($deadline);

        return ['row' => $row, 'pin' => $pin, 'generation_provenance' => $provenance, 'contract' => $contract];
    }

    /** Current selected-region producer proof for a source-bound technical refresh. */
    public function faceVectorRefreshEngineProvenance(): array
    {
        $engine = $this->anonymousGenerationEngineVersion();
        $engine['detector'] = 'operator_selected_region';
        if (! $this->validAnonymousGenerationEngine($engine, true)) {
            throw new \RuntimeException('detector_version_unavailable');
        }

        return $engine;
    }

    /** Hash the exact float32 representation used by pgvector, without decimal reformatting. */
    public function faceVectorRefreshEmbeddingSha256(mixed $embedding): ?string
    {
        if (is_string($embedding)) {
            $embedding = json_decode($embedding, true);
        }
        if (! is_array($embedding) || ! array_is_list($embedding) || count($embedding) !== 128) {
            return null;
        }
        $binary = '';
        foreach ($embedding as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                return null;
            }
            $binary .= pack('g', (float) $value);
        }

        return hash('sha256', $binary);
    }

    /** Confirm that separately queried row fields match their existing comparison evidence pins. */
    public function comparisonEvidenceMatchesStoredRow(object $row, array $evidence): bool
    {
        $embedding = $row->embedding ?? null;
        if (is_string($embedding)) {
            $embedding = json_decode($embedding, true);
        }
        $vector = $this->comparisonVectorPin($embedding, $row->embedding_model ?? null);
        if ($vector === null) {
            return false;
        }
        $expected = $vector + ['file_registry_id' => (int) ($row->file_registry_id ?? 0),
            'source_sha256' => $row->source_sha256 ?? null,
            'region' => ['x' => (float) ($row->region_x ?? NAN), 'y' => (float) ($row->region_y ?? NAN),
                'w' => (float) ($row->region_w ?? NAN), 'h' => (float) ($row->region_h ?? NAN)],
            'face_region_convention' => 'normalized_top_left', 'coordinate_basis' => 'encoded_pixels'];

        return $expected === $evidence;
    }

    /** Bind the producer's exact checkpoint/parameters to the stored float32 vector. */
    private function anonymousVectorProvenance(array $packet, array $face, int $faceId): array
    {
        $literal = json_decode(PgVector::literal($face['embedding'], self::VECTOR_LITERAL_PRECISION), true, flags: JSON_THROW_ON_ERROR);
        $binary = '';
        foreach ($literal as $value) {
            $binary .= pack('g', (float) $value);
        }

        return ['schema' => 'plos.face_vector_generation_provenance.v1',
            'run_uuid' => $packet['run_uuid'], 'generation_key' => $packet['generation_key'],
            'binding_sha256' => $packet['binding_sha256'], 'detector_version' => $packet['detector_version'],
            'file_registry_face_id' => $faceId, 'face_index' => $face['face_index'],
            'source_sha256' => $packet['source_sha256'], 'face_region' => $face['face_region'],
            'face_region_convention' => 'normalized_top_left',
            'embedding_float32_sha256' => hash('sha256', $binary),
            'engine_provenance' => $packet['engine_provenance'], 'detector_limits' => $packet['detector_limits'],
            'source_dimensions' => $packet['source_dimensions'],
            ...(isset($packet['selected_face']) ? ['selected_face' => $packet['selected_face'],
                'encoding_provenance' => $packet['encoding_provenance'], 'decode_provenance' => $packet['decode_provenance']] : [])];
    }

    private function sameGenerationProvenance(string $stored, array $expected): bool
    {
        $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (is_array($value)) {
                ksort($value);
                foreach ($value as $key => $child) {
                    $value[$key] = $canonical($child);
                }
            }

            return $value;
        };

        return json_encode($canonical($decoded), JSON_THROW_ON_ERROR) === json_encode($canonical($expected), JSON_THROW_ON_ERROR);
    }

    /** Unknown legacy weights stay unknown: only the retained original packet can prove them. */
    private function currentEmbeddingGenerationProvenance(object $embedding, ?float $deadline = null): ?array
    {
        try {
            $this->checkMatchingProofDeadline($deadline);
            $face = DB::table('file_registry_faces')->where('id', $embedding->file_registry_face_id ?? 0)->first();
            if (! $face || ! $face->source_current || $face->superseded_at !== null
                || (int) $face->file_registry_id !== (int) $embedding->file_registry_id
                || $face->source_sha256 !== $embedding->source_sha256
                || ! self::faceRegionsMatchStoragePrecision($face, $embedding)) {
                return null;
            }
            $storedProvenance = is_string($embedding->generation_provenance ?? null)
                ? json_decode($embedding->generation_provenance, true) : null;
            if (is_array($storedProvenance) && ($storedProvenance['schema'] ?? null) === 'plos.face_vector_technical_refresh.v1') {
                return $this->currentProtectedVectorRefreshProvenance($embedding, $face, $storedProvenance);
            }
            $run = DB::table('file_registry_face_generation_runs')->where('file_registry_id', $embedding->file_registry_id)
                ->where('generation_key', $face->source_generation_key)
                ->where('stage', 'complete')->whereNotNull('completed_at')->first();
            $root = $this->anonymousPreviewPrivateRoot();
            if (! $run || $root === null || ! Str::isUuid($run->run_uuid)) {
                return null;
            }
            $packet = $deadline === null
                ? $this->loadAnonymousGenerationPacket($root.'/'.$run->run_uuid.'/generation.json', $root, $run)
                : $this->loadAnonymousGenerationPacket($root.'/'.$run->run_uuid.'/generation.json', $root, $run, $deadline);
            $this->checkMatchingProofDeadline($deadline);
            if ($packet === null || ! $this->validAnonymousGenerationEngine($packet['engine_provenance'] ?? null, isset($packet['selected_face']))) {
                return null;
            }
            $registry = DB::table('file_registry')->where('id', $embedding->file_registry_id)->where('status', 'active')->first();
            if (! $registry || $registry->asset_uuid !== $run->asset_uuid) {
                return null;
            }
            $sourceChain = null;
            if ($run->source_sha256 !== $embedding->source_sha256) {
                $path = app(NextcloudFileApiService::class)->localPath($registry->current_path);
                if (! is_string($path) || $path === '') {
                    return null;
                }
                $inspector = app(ExifWritebackService::class);
                $args = [(int) $registry->id, $registry->asset_uuid, $run->source_sha256, $embedding->source_sha256, $path];
                if ($deadline !== null) {
                    $this->checkMatchingProofDeadline($deadline);
                    $args[] = $deadline - hrtime(true) / 1e9;
                }
                $sourceChain = $inspector->inspectVerifiedMetadataTransitionChain(...$args);
                if ($deadline !== null && str_starts_with((string) ($sourceChain['reason_code'] ?? ''), 'budget_unsupported:')) {
                    throw new \RuntimeException('matching_proof_budget_unsupported:'.substr($sourceChain['reason_code'], strlen('budget_unsupported:')));
                }
                if (($sourceChain['status'] ?? null) !== 'verified'
                    || ($sourceChain['registry_id'] ?? null) !== (int) $registry->id
                    || ($sourceChain['asset_uuid'] ?? null) !== $registry->asset_uuid
                    || ($sourceChain['before_sha256'] ?? null) !== $run->source_sha256
                    || ($sourceChain['after_sha256'] ?? null) !== $embedding->source_sha256) {
                    return null;
                }
            }
            $ids = json_decode($run->mysql_face_ids ?? 'null', true);
            if (! is_array($ids) || count($ids) !== count($packet['faces']) || count(array_unique($ids)) !== count($ids)) {
                return null;
            }
            $index = array_search((int) $face->id, $ids, true);
            if ($index === false || (isset($packet['selected_face'])
                ? ($index !== 0 || $packet['selected_face']['face_id'] !== (int) $face->id)
                : (int) $face->face_index !== $index)) {
                return null;
            }
            $produced = $packet['faces'][$index];
            $this->persistedGenerationRegion($face, $produced['face_region']);
            $provenance = $this->anonymousVectorProvenance($packet, $produced, (int) $face->id);
            $vector = is_string($embedding->embedding) ? json_decode($embedding->embedding, true) : $embedding->embedding;
            if ($this->comparisonVectorPin($vector, $embedding->embedding_model) === null
                || $embedding->embedding_model !== $provenance['engine_provenance']['embedding_model']) {
                return null;
            }
            $binary = '';
            foreach ($vector as $value) {
                $binary .= pack('g', (float) $value);
            }
            if (! hash_equals($provenance['embedding_float32_sha256'], hash('sha256', $binary))
                || (isset($embedding->generation_provenance) && ! $this->sameGenerationProvenance($embedding->generation_provenance, $provenance))) {
                return null;
            }

            return $provenance + ['packet_sha256' => $run->packet_sha256,
                'current_source_sha256' => $embedding->source_sha256, 'metadata_source_chain' => $sourceChain,
                'model_sha256' => $provenance['engine_provenance']['checkpoints']['descriptor_sha256'],
                'proof_origin' => isset($embedding->generation_provenance) ? 'persisted_vector_and_original_packet' : 'original_generation_packet'];
        } catch (\Throwable $error) {
            if ($deadline !== null && str_starts_with($error->getMessage(), 'matching_proof_budget_unsupported:')) {
                throw $error;
            }

            return null;
        }
    }

    private function currentProtectedVectorRefreshProvenance(object $embedding, object $face, array $provenance): ?array
    {
        try {
            $runUuid = $provenance['run_uuid'] ?? null;
            $engine = $provenance['engine_provenance'] ?? null;
            $region = $provenance['face_region'] ?? null;
            $vectorSha = $this->faceVectorRefreshEmbeddingSha256($embedding->embedding ?? null);
            if (! is_string($runUuid) || ! Str::isUuid($runUuid)
                || ! is_array($engine) || ! $this->validAnonymousGenerationEngine($engine, true)
                || ($provenance['file_registry_face_id'] ?? null) !== (int) $face->id
                || ($provenance['file_registry_id'] ?? null) !== (int) $embedding->file_registry_id
                || ($provenance['source_sha256'] ?? null) !== $embedding->source_sha256
                || ($provenance['embedding_model'] ?? null) !== $embedding->embedding_model
                || ($provenance['embedding_float32_sha256'] ?? null) !== $vectorSha
                || ! is_string($engine['script_sha256'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/D', $engine['script_sha256'])
                || ($provenance['engine_provenance_sha256'] ?? null) !== hash('sha256', $this->canonicalFaceProvenanceJson($engine))
                || ! is_array($region) || ! $this->faceVectorRefreshRegionMatches($face, $region)
                || ! self::faceRegionsMatchStoragePrecision($face, $embedding)) {
                return null;
            }
            $run = DB::table('file_registry_face_vector_refresh_runs')->where('run_uuid', $runUuid)
                ->where('stage', 'complete')->whereNotNull('completed_at')->first();
            if (! $run || (int) $run->file_registry_id !== (int) $embedding->file_registry_id
                || $run->completed_at === null
                || (int) $run->file_registry_face_id !== (int) $face->id
                || (int) $run->new_embedding_id !== (int) $embedding->id
                || $run->source_sha256 !== $embedding->source_sha256
                || $run->output_embedding_sha256 !== $vectorSha
                || $run->engine_provenance_sha256 !== $provenance['engine_provenance_sha256']
                || $run->engine_provenance_json !== $this->canonicalFaceProvenanceJson($engine)
                || $run->old_vector_sha256 !== ($provenance['old_embedding_float32_sha256'] ?? null)
                || ! is_string($run->caller_binding_sha256) || ! preg_match('/^[a-f0-9]{64}$/D', $run->caller_binding_sha256)
                || $run->identity_proof_sha256 !== ($provenance['identity_proof_sha256'] ?? null)
                || $run->provenance_sha256 !== hash('sha256', $this->canonicalFaceProvenanceJson($provenance))
                || $embedding->embedding_model !== ($engine['embedding_model'] ?? null)) {
                return null;
            }
            $currentVectors = DB::connection('pgsql_rag')->table('face_embeddings')
                ->where('file_registry_face_id', $face->id)->where('source_current', true)->whereNull('superseded_at')->limit(2)->get(['id']);
            if ($currentVectors->count() !== 1 || (int) $currentVectors[0]->id !== (int) $embedding->id) {
                return null;
            }

            return $provenance + ['model_sha256' => $engine['checkpoints']['descriptor_sha256'],
                'proof_origin' => 'protected_face_vector_refresh_receipt'];
        } catch (\Throwable) {
            return null;
        }
    }

    private function faceVectorRefreshRegionMatches(object $face, array $region): bool
    {
        if (array_diff(array_keys($region), ['x', 'y', 'w', 'h']) !== []
            || array_diff(['x', 'y', 'w', 'h'], array_keys($region)) !== []) {
            return false;
        }
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if (! is_numeric($region[$axis]) || ! is_finite((float) $region[$axis])
                || round((float) $region[$axis], 8) !== round((float) ($face->{'region_'.$axis} ?? NAN), 8)) {
                return false;
            }
        }

        return true;
    }

    private function canonicalFaceProvenanceJson(array $value): string
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

    private function comparisonVectorPin(mixed $embedding, mixed $model): ?array
    {
        if (is_string($embedding)) {
            $embedding = json_decode($embedding, true);
        }
        if (! is_array($embedding) || count($embedding) !== 128 || ! array_is_list($embedding)
            || ! is_string($model) || trim($model) === '' || strlen($model) > 100) {
            return null;
        }
        $norm = 0.0;
        foreach ($embedding as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                return null;
            }
            $norm += $value * $value;
        }
        if ($norm <= 0 || ! is_finite($norm)) {
            return null;
        }

        return ['embedding_model' => $model, 'embedding_sha256' => hash('sha256', PgVector::literal($embedding, self::VECTOR_LITERAL_PRECISION))];
    }

    /** Produce a bounded JPEG from exactly the normalized region in source bytes. */
    private function comparisonRegionCrop(string $bytes, array $region): ?string
    {
        $maxBytes = (int) config('file_registry.face_preview.max_source_bytes');
        $maxPixels = (int) config('file_registry.face_preview.max_pixels');
        $info = strlen($bytes) <= $maxBytes ? @getimagesizefromstring($bytes) : false;
        if (! $info || $maxPixels < 1 || $info[0] < 1 || $info[1] < 1 || $info[0] > $maxPixels / $info[1]) {
            return null;
        }
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            if (! is_numeric($region[$axis] ?? null) || ! is_finite((float) $region[$axis])) {
                return null;
            }
        }
        if ($region['x'] < 0 || $region['y'] < 0 || $region['w'] <= 0 || $region['h'] <= 0
            || $region['x'] + $region['w'] > 1 || $region['y'] + $region['h'] > 1) {
            return null;
        }
        $box = ['x' => (int) floor($region['x'] * $info[0]), 'y' => (int) floor($region['y'] * $info[1]),
            'width' => (int) floor($region['w'] * $info[0]), 'height' => (int) floor($region['h'] * $info[1])];
        if ($box['width'] < 1 || $box['height'] < 1) {
            return null;
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }
        $crop = null;
        try {
            $crop = imagecrop($source, $box);
            if ($crop === false) {
                return null;
            }
            ob_start();
            try {
                $encoded = imagejpeg($crop, null, 90);
                $payload = ob_get_contents();
            } finally {
                ob_end_clean();
            }

            return $encoded && is_string($payload) && strlen($payload) <= $maxBytes ? $payload : null;
        } finally {
            imagedestroy($source);
            if ($crop !== null && $crop !== false) {
                imagedestroy($crop);
            }
        }
    }

    /**
     * Local advisory comparison. Confidence is model-reported, not calibrated.
     * No identity, membership, metadata or tree decision is made here.
     *
     * @return array{success:bool, decision:string, same_person:bool|null, confidence:float|null, operator_review_required:bool, error_code?:string, crop_sha256?:array, provider?:string, model?:string, route_trace_id?:string, request_kind?:string, duration_ms?:int, fallback_suppressed?:bool|null, attempts?:array<string,string>, controller_failure?:array}
     */
    public function compareFaceCropsWithAI(string $cropPath1, string $cropPath2): array
    {
        $held = ['success' => false, 'decision' => 'abstain', 'same_person' => null,
            'confidence' => null, 'operator_review_required' => true];
        $images = [];
        $composite = null;
        try {
            $maxBytes = (int) config('file_registry.face_preview.max_source_bytes');
            $maxPixels = (int) config('file_registry.face_preview.max_pixels');
            if ($maxBytes < 1 || $maxPixels < 1) {
                return $held + ['error_code' => 'comparison_limits_unavailable'];
            }
            $hashes = [];
            foreach ([$cropPath1, $cropPath2] as $path) {
                if (! is_file($path) || ! is_readable($path)) {
                    return $held + ['error_code' => 'crop_unavailable'];
                }
                $bytes = @file_get_contents($path, false, null, 0, $maxBytes + 1);
                if (! is_string($bytes) || strlen($bytes) > $maxBytes) {
                    return $held + ['error_code' => 'crop_byte_limit'];
                }
                $info = @getimagesizefromstring($bytes);
                if (! is_array($info) || $info[0] < 1 || $info[1] < 1
                    || $info[0] > $maxPixels / $info[1]) {
                    return $held + ['error_code' => 'crop_invalid_or_pixel_limit'];
                }
                $image = @imagecreatefromstring($bytes);
                if ($image === false) {
                    return $held + ['error_code' => 'crop_decode_failed'];
                }
                $images[] = $image;
                $hashes[] = hash('sha256', $bytes);
            }
            $height = max(imagesy($images[0]), imagesy($images[1]));
            $widths = array_map(fn ($image) => max(1, (int) round(imagesx($image) * $height / imagesy($image))), $images);
            $gap = 10;
            $width = array_sum($widths) + $gap;
            if ($width > $maxPixels / $height) {
                return $held + ['error_code' => 'composite_pixel_limit'];
            }
            $composite = imagecreatetruecolor($width, $height);
            imagefill($composite, 0, 0, imagecolorallocate($composite, 255, 255, 255));
            foreach ($images as $index => $image) {
                imagecopyresampled($composite, $image, $index === 0 ? 0 : $widths[0] + $gap,
                    0, 0, 0, $widths[$index], $height, imagesx($image), imagesy($image));
            }
            ob_start();
            try {
                $encoded = imagejpeg($composite, null, 90);
                $payload = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! $encoded || ! is_string($payload) || strlen($payload) > $maxBytes) {
                return $held + ['error_code' => 'composite_encode_failed'];
            }
            $prompt = <<<'PROMPT'
Compare the two face crops side by side as advisory evidence only.
Return same_person true or false only when the visible evidence supports that judgment.
Use null when quality, age, pose, similarity or conflicting features prevent a reliable judgment.
Do not name either person. Report confidence between 0 and 1; it is a model estimate, not a calibrated probability.
Return ONLY JSON with exactly these keys: {"same_person": null, "confidence": 0.0}.
PROMPT;
            $routeTraceId = bin2hex(random_bytes(16));
            $requestKind = 'vision';
            $dispatchStarted = microtime(true);
            $result = $this->aiService->processImage($payload, $prompt, [
                'max_tokens' => 100, 'suppressAlert' => true, 'sensitive_data' => true,
                'local_only' => true, 'disable_external_fallback' => true,
                'ollama_required_no_fallback' => true, 'disable_cascade' => true,
                'route_trace_id' => $routeTraceId, 'request_kind' => $requestKind,
            ]);
            $diagnostics = $this->localComparisonDiagnostics(
                $result,
                $routeTraceId,
                $requestKind,
                (int) ((microtime(true) - $dispatchStarted) * 1000),
            );
            foreach ([$cropPath1, $cropPath2] as $index => $path) {
                $currentHash = @hash_file('sha256', $path);
                if (! is_string($currentHash) || ! hash_equals($hashes[$index], $currentHash)) {
                    return $held + ['error_code' => 'crop_changed_during_comparison'] + $diagnostics;
                }
            }
            if (($result['success'] ?? false) !== true) {
                return $held + ['error_code' => 'local_comparison_unavailable'] + $diagnostics;
            }
            $response = $result['response'] ?? null;
            if (! is_string($response) || strlen($response) > (int) config('file_registry.face_comparison.max_response_bytes')) {
                return $held + ['error_code' => 'invalid_comparison_response'] + $diagnostics;
            }
            $answer = json_decode($response, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($answer) || count($answer) !== 2 || ! array_key_exists('same_person', $answer)
                || ! array_key_exists('confidence', $answer)
                || (! is_bool($answer['same_person']) && $answer['same_person'] !== null)
                || (! is_int($answer['confidence']) && ! is_float($answer['confidence']))
                || ! is_finite((float) $answer['confidence']) || $answer['confidence'] < 0 || $answer['confidence'] > 1) {
                return $held + ['error_code' => 'invalid_comparison_response'] + $diagnostics;
            }

            return array_merge(['success' => true,
                'decision' => $answer['same_person'] === null ? 'abstain' : ($answer['same_person'] ? 'same_person' : 'different_person'),
                'same_person' => $answer['same_person'], 'confidence' => (float) $answer['confidence'],
                'operator_review_required' => true, 'crop_sha256' => $hashes], $diagnostics);
        } catch (\JsonException) {
            $failure = $held + ['error_code' => 'invalid_comparison_response'];
            if (isset($routeTraceId, $requestKind, $dispatchStarted)) {
                $diagnostics ??= $this->localComparisonDiagnostics([], $routeTraceId, $requestKind,
                    (int) ((microtime(true) - $dispatchStarted) * 1000));

                return $failure + $diagnostics;
            }

            return $failure;
        } catch (\Throwable $error) {
            Log::warning('FaceEmbedding: Local advisory comparison failed', ['exception_type' => get_class($error)]);

            $failure = $held + ['error_code' => 'local_comparison_failed'];
            if (isset($routeTraceId, $requestKind, $dispatchStarted)) {
                $diagnostics ??= $this->localComparisonDiagnostics(['controller_failure' => InferenceAdmissionException::controllerFailureFrom($error)], $routeTraceId, $requestKind,
                    (int) ((microtime(true) - $dispatchStarted) * 1000));

                return $failure + $diagnostics;
            }

            return $failure;
        } finally {
            foreach ($images as $image) {
                imagedestroy($image);
            }
            if ($composite !== null) {
                imagedestroy($composite);
            }
        }
    }

    /** Return only bounded routing metadata; never expose model output or raw errors. */
    private function localComparisonDiagnostics(array $result, string $traceId, string $requestKind, int $elapsedMs): array
    {
        $diagnostics = [
            'route_trace_id' => $traceId,
            'request_kind' => $requestKind,
            'duration_ms' => max(0, min(86_400_000, (int) ($result['duration_ms'] ?? $elapsedMs))),
            'fallback_suppressed' => is_bool($result['fallback_suppressed'] ?? null) ? $result['fallback_suppressed'] : null,
            'attempts' => [],
        ];
        foreach (['provider', 'model'] as $field) {
            $value = $result[$field] ?? null;
            if (is_string($value) && strlen($value) <= 128
                && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:\/+\-]{0,127}\z/', $value) === 1) {
                $diagnostics[$field] = $value;
            }
        }

        $attempts = $result['attempts'] ?? [];
        if (is_array($attempts)) {
            foreach (array_slice($attempts, 0, 8, true) as $key => $outcome) {
                if (! is_string($key) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:\-]{0,63}\z/', $key) !== 1) {
                    continue;
                }
                $diagnostics['attempts'][$key] = $this->localComparisonAttemptCode($outcome);
            }
        }

        $controllerFailure = is_array($result['controller_failure'] ?? null)
            ? InferenceAdmissionException::sanitizeControllerFailure($result['controller_failure']) : [];
        if ($controllerFailure !== []) {
            $diagnostics['controller_failure'] = $controllerFailure;
        }

        return $diagnostics;
    }

    /** Map arbitrary internal attempt text to a short, non-sensitive stable code. */
    private function localComparisonAttemptCode(mixed $outcome): string
    {
        if (! is_string($outcome)) {
            return 'unknown';
        }
        $text = strtolower($outcome);
        if (str_contains($text, 'circuit open')) {
            return 'circuit_open';
        }
        if (str_contains($text, 'busy') || str_contains($text, 'lock')) {
            return 'busy_or_locked';
        }
        if (preg_match('/\b(?:timeout|timed out)\b/i', $text) === 1) {
            return 'timeout';
        }
        if (str_contains($text, 'not advertised')) {
            return 'model_not_advertised';
        }
        if (str_contains($text, 'admission')) {
            return 'admission_failed';
        }
        if (str_contains($text, 'disabled by caller')) {
            return 'privacy_fallback_disabled';
        }
        if (str_contains($text, 'unavailable') || str_contains($text, 'not available')) {
            return 'provider_unavailable';
        }

        return 'unknown';
    }

    /**
     * Get person clusters for review
     *
     * @param  string  $status  Filter by status (unreviewed, confirmed, merged)
     * @param  int  $minFaces  Minimum faces in cluster
     * @param  int  $limit  Max results
     * @return array Clusters with sample faces
     */
    public function getClustersForReview(string $status = 'unreviewed', int $minFaces = 2, int $limit = 50, int $offset = 0, bool $failOnError = false): array
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
            if ($failOnError) {
                return ['success' => false,
                    'error' => 'Face cluster review is unavailable on the selected PLOS target. Check its database connection and retry when available.'];
            }

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

            // Compute centroid for newly confirmed cluster
            $this->updateClusterCentroid($clusterId);

            // Keep genealogy_person_media aligned via the shared bridge.
            // Drives off the persisted genealogy_person_id so callers that omit it
            // (or update the cluster separately) are still covered.
            $bridgeResult = $this->syncBridgeForCluster($clusterId, true);

            // Write face regions back to image files (media is source of truth)
            if ($writeToMedia && $name) {
                $syncResult = $this->syncClusterToMediaFiles($clusterId, $bridgeResult['confirmed_face_ids'] ?? []);
                Log::info('FaceEmbedding: Synced confirmed cluster to media', [
                    'cluster_id' => $clusterId,
                    'name' => $name,
                    'sync_result' => $syncResult,
                ]);
            }

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
        if (empty($sourceClusterIds) || $mergedBy !== 'user') {
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
     * is checked without materializing identities from cluster membership.
     * No-op when the cluster has no genealogy_person_id. Failures
     * are logged but never fail the cluster operation.
     */
    private function syncBridgeForCluster(int $clusterId, bool $operatorConfirmed = false): array
    {
        try {
            $cluster = DB::connection('pgsql_rag')->selectOne(
                'SELECT genealogy_person_id FROM person_clusters WHERE id = ?',
                [$clusterId]
            );
            $personId = $cluster->genealogy_person_id ?? null;
            if (! $personId) {
                return ['confirmed_face_ids' => []];
            }

            if ($operatorConfirmed) {
                $this->materializeClusterFaces($clusterId);
            }

            return app(FaceLinkBridgeService::class)->syncClusterLinks($clusterId, (int) $personId, $operatorConfirmed);
        } catch (\Throwable $e) {
            Log::warning('FaceEmbedding: bridge sync failed', [
                'cluster_id' => $clusterId,
                'error' => $e->getMessage(),
            ]);
        }

        return ['confirmed_face_ids' => []];
    }

    /**
     * Materialize detected regions for the cluster explicitly selected by the operator.
     * Identity confirmation is left to the guarded bridge.
     */
    private function materializeClusterFaces(int $clusterId): void
    {
        $faces = DB::connection('pgsql_rag')->select('SELECT * FROM face_embeddings
            WHERE person_cluster_id = ? AND source_current = TRUE AND superseded_at IS NULL', [$clusterId]);
        foreach ($faces as $face) {
            if (! $this->embeddingHasCurrentSourceEvidence($face)) {
                continue;
            }
            // An old detector reference is evidence to retain, never permission to replace it.
            if (! empty($face->file_registry_face_id)) {
                continue;
            }
            $existing = DB::selectOne('SELECT id FROM file_registry_faces WHERE file_registry_id = ?
                AND source_sha256 = ? AND source_current = 1 AND superseded_at IS NULL
                AND ABS(region_x - ?) <= ? AND ABS(region_y - ?) <= ?
                AND ABS(region_w - ?) <= ? AND ABS(region_h - ?) <= ?', [
                $face->file_registry_id, $face->source_sha256,
                $face->region_x, config('genealogy.face_region_match_tolerance'),
                $face->region_y, config('genealogy.face_region_match_tolerance'),
                $face->region_w, config('genealogy.face_region_match_tolerance'),
                $face->region_h, config('genealogy.face_region_match_tolerance'),
            ]);
            if (! $existing) {
                DB::insert("INSERT INTO file_registry_faces
                    (file_registry_id, person_name, genealogy_person_id, region_x, region_y, region_w, region_h,
                    source, verified, cluster_id, source_sha256, face_region_convention, source_generation_key, source_current, created_at, updated_at)
                    VALUES (?, NULL, NULL, ?, ?, ?, ?, 'ai_detection', 0, ?, ?, 'normalized_top_left', ?, 1, NOW(), NOW())", [
                    $face->file_registry_id, $face->region_x, $face->region_y, $face->region_w, $face->region_h,
                    $clusterId, $face->source_sha256, $face->source_sha256,
                ]);
                $existing = DB::selectOne('SELECT id FROM file_registry_faces WHERE file_registry_id = ?
                    AND source_sha256 = ? AND source_current = 1 AND superseded_at IS NULL
                    AND region_x = ? AND region_y = ? AND region_w = ? AND region_h = ?', [
                    $face->file_registry_id, $face->source_sha256, $face->region_x, $face->region_y, $face->region_w, $face->region_h,
                ]);
            }
            if ($existing) {
                DB::connection('pgsql_rag')->update('UPDATE face_embeddings SET file_registry_face_id = ?, updated_at = NOW()
                    WHERE id = ? AND file_registry_face_id IS NULL AND source_current = TRUE AND superseded_at IS NULL AND source_sha256 = ?',
                    [$existing->id, $face->id, $face->source_sha256]);
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
        $identities = array_map(static function (array|object $face): object {
            $face = (array) $face;

            return (object) [
                'id' => $face['file_registry_face_id'] ?? $face['id'] ?? $face['face_id'] ?? null,
                'genealogy_person_id' => $face['genealogy_person_id'] ?? null,
            ];
        }, $faces);
        $result = app(ExifWritebackService::class)->writeFaces($imagePath, $identities);

        return array_merge($result, ['faces_written' => (int) ($result['written'] ?? 0)]);
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
    public function syncClusterToMediaFiles(int $clusterId, array $operatorConfirmedFaceIds = []): array
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
                WHERE person_cluster_id = ? AND source_current = TRUE AND superseded_at IS NULL
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

                // Cluster similarity cannot authorize a name in the source file.
                // Retain only currently confirmed individual face/person bridges.
                $candidateFaces = DB::select(
                    'SELECT id, person_name, genealogy_person_id, region_x, region_y, region_w, region_h
                     FROM file_registry_faces
                     WHERE file_registry_id = ? AND cluster_id = ? AND verified = 1
                       AND COALESCE(hidden, 0) = 0 AND genealogy_person_id IS NOT NULL',
                    [$af->file_registry_id, $clusterId]
                );
                $confirmedRegions = [];
                foreach ($candidateFaces as $face) {
                    $retained = app(FaceLinkBridgeService::class)->syncAutomaticFaceLink(
                        (int) $face->id, (int) $face->genealogy_person_id
                    );
                    if (! ($retained['success'] ?? false)
                        || (! ($retained['operator_approved'] ?? false)
                            && ! in_array((int) $face->id, $operatorConfirmedFaceIds, true))) {
                        continue;
                    }
                    $confirmedRegions[] = [
                        'name' => $face->person_name,
                        // Registry boxes use top-left; MWG areas use center.
                        'x' => (float) $face->region_x + (float) $face->region_w / 2,
                        'y' => (float) $face->region_y + (float) $face->region_h / 2,
                        'w' => (float) $face->region_w,
                        'h' => (float) $face->region_h,
                    ];
                }
                if ($confirmedRegions === []) {
                    continue;
                }

                $result = ['success' => app(FaceRegionService::class)->mergeConfirmedFaceRegions(
                    $file->current_path, $confirmedRegions
                )];
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
                WHERE person_cluster_id = ? AND source_current = TRUE AND superseded_at IS NULL
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
                INNER JOIN face_embeddings fe ON fe.person_cluster_id = pc.id AND fe.source_current = TRUE AND fe.superseded_at IS NULL
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
                WHERE fe.person_cluster_id = ? AND fe.source_current = TRUE AND fe.superseded_at IS NULL
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
     * Report named-face review needs without changing operator-owned memberships.
     *
     * @return array Stats about clusters created
     */
    public function clusterNamedFaces(): array
    {
        // A displayed name is evidence, not an operator approval of membership.
        // This maintenance route cannot safely confirm or reassign named faces.
        $groups = DB::select("
            SELECT frf.person_name, frf.genealogy_person_id, gp.tree_id,
                   COUNT(*) AS face_count,
                   SUM(CASE WHEN frf.cluster_id IS NULL THEN 1 ELSE 0 END) AS unclustered_faces
            FROM file_registry_faces frf
            JOIN file_registry fr ON fr.id = frf.file_registry_id AND fr.status = 'active'
            LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
            WHERE TRIM(COALESCE(frf.person_name, '')) != '' AND frf.hidden = 0
            GROUP BY frf.person_name, frf.genealogy_person_id, gp.tree_id
        ");
        $faces = array_sum(array_map(fn ($group) => (int) $group->face_count, $groups));
        $unclustered = array_sum(array_map(fn ($group) => (int) $group->unclustered_faces, $groups));

        return [
            'people_found' => count($groups),
            'clusters_created' => 0,
            'faces_linked' => 0,
            'errors' => 0,
            'review_required' => $faces > 0,
            'review_required_faces' => $unclustered,
            'skipped_faces' => $faces,
            'preserved_clustered_faces' => $faces - $unclustered,
            'reason' => 'name_only_clustering_requires_operator_review',
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
        $skipped = 0;

        // Group by cluster label
        $grouped = [];
        $custodyHeldLabels = [];
        foreach ($clusterAssignments as $feId => $label) {
            try {
                $deadline = hrtime(true) / 1e9 + $this->automaticMatchingBounds()['max_proof_elapsed_seconds'];
                $source = $this->withAutomaticMatchingReadBounds(fn () => DB::connection('pgsql_rag')
                    ->selectOne('SELECT file_registry_id FROM face_embeddings WHERE id = ?', [(int) $feId]), $deadline);
                if ($source && (int) ($source->file_registry_id ?? 0) > 0
                    && ! $this->automaticGroupingCustodyAdmitted([(int) $source->file_registry_id], $deadline)) {
                    // Never drop an unresolved member and consume its already computed label.
                    $custodyHeldLabels[$label] = true;
                    $skipped++;

                    continue;
                }
            } catch (\Throwable) {
                $custodyHeldLabels[$label] = true;
                $skipped++;

                continue;
            }
            if (! $this->canAutomaticallyGroupFace((int) $feId)) {
                $skipped++;

                continue;
            }
            $grouped[$label][] = (int) $feId;
        }

        foreach ($grouped as $label => $faceEmbeddingIds) {
            if (isset($custodyHeldLabels[$label])) {
                $skipped += count($faceEmbeddingIds);

                continue;
            }
            try {
                // Recheck custody after label preparation, before creating a cluster.
                $deadline = hrtime(true) / 1e9 + $this->automaticMatchingBounds()['max_proof_elapsed_seconds'];
                $registryIds = $this->withAutomaticMatchingReadBounds(function () use ($faceEmbeddingIds, $deadline): array {
                    $registryIds = [];
                    foreach ($faceEmbeddingIds as $id) {
                        $this->checkMatchingProofDeadline($deadline);
                        $row = DB::connection('pgsql_rag')->selectOne('SELECT file_registry_id FROM face_embeddings WHERE id = ?', [$id]);
                        $registryIds[] = (int) ($row->file_registry_id ?? 0);
                    }

                    return $registryIds;
                }, $deadline);
                if (! $this->automaticGroupingCustodyAdmitted($registryIds, $deadline)) {
                    $skipped += count($faceEmbeddingIds);

                    continue;
                }
                // Create cluster (unnamed, unreviewed)
                $clusterId = DB::connection('pgsql_rag')->table('person_clusters')->insertGetId([
                    'name' => null,
                    'status' => 'unreviewed',
                    'face_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $clustersCreated++;

                foreach ($faceEmbeddingIds as $faceEmbeddingId) {
                    if ($this->publishImportedAnonymousAssignment($faceEmbeddingId, $clusterId, 0.0)) {
                        $facesAssigned++;
                    } else {
                        $skipped++;
                    }
                }
                $rep = DB::connection('pgsql_rag')->selectOne('SELECT id FROM face_embeddings
                    WHERE person_cluster_id = ? ORDER BY quality_score DESC NULLS LAST LIMIT 1', [$clusterId]);
                if ($rep) {
                    DB::connection('pgsql_rag')->update('UPDATE person_clusters SET representative_face_id = ? WHERE id = ?', [$rep->id, $clusterId]);
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
            'skipped_faces' => $skipped,
        ];
    }

    /** Validate stored vectors against their own current source, without changing old rows. */
    public function embeddingHasCurrentSourceEvidence(object $embedding, ?float $deadline = null): bool
    {
        try {
            $file = DB::selectOne('SELECT current_path, status AS file_status FROM file_registry WHERE id = ?', [(int) ($embedding->file_registry_id ?? 0)]);
            if (! $file) {
                return false;
            }
            if (! empty($embedding->file_registry_face_id)) {
                $face = DB::selectOne('SELECT * FROM file_registry_faces WHERE id = ?', [(int) $embedding->file_registry_face_id]);
                if (! $face || empty($face->source_current) || ! empty($face->superseded_at) || ! empty($face->hidden)
                    || (int) $face->file_registry_id !== (int) $embedding->file_registry_id
                    || ($face->source_sha256 ?? null) !== ($embedding->source_sha256 ?? null)
                    || ($face->face_region_convention ?? null) !== 'normalized_top_left') {
                    return false;
                }
                if (! self::faceRegionsMatchStoragePrecision($face, $embedding)) {
                    return false;
                }
            }
            $proof = (object) array_merge((array) $embedding, (array) $file);

            $bridge = app(FaceLinkBridgeService::class);
            $checked = $deadline === null ? $bridge->validateFaceSourceEvidence($proof) : $bridge->validateFaceSourceEvidence($proof, $deadline);

            return $checked['success'] ?? false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function checkMatchingProofDeadline(?float $deadline): void
    {
        if ($deadline !== null && (! is_finite($deadline) || hrtime(true) / 1e9 >= $deadline)) {
            throw new \RuntimeException('matching_proof_deadline_exceeded');
        }
    }

    /** Preserve source evidence across DECIMAL(10,8) and PostgreSQL REAL quantization. */
    public static function faceRegionsMatchStoragePrecision(object $face, object $embedding): bool
    {
        $faceBox = [];
        $vectorBox = [];
        foreach (['x', 'y', 'w', 'h'] as $axis) {
            $faceValue = $face->{'region_'.$axis} ?? null;
            $vectorValue = $embedding->{'region_'.$axis} ?? null;
            if (! is_numeric($faceValue) || ! is_numeric($vectorValue)
                || ! is_finite((float) $faceValue) || ! is_finite((float) $vectorValue)) {
                return false;
            }
            $faceBox[$axis] = (float) $faceValue;
            $rawVectorValue = (float) $vectorValue;
            if ($faceBox[$axis] < 0 || $faceBox[$axis] > 1 || $rawVectorValue < 0 || $rawVectorValue > 1) {
                return false;
            }
            // PostgreSQL REAL may be returned as its shortest round-tripping
            // decimal text. Reconstitute the stored float32 before comparing
            // it with MySQL DECIMAL coordinates.
            $vectorBox[$axis] = unpack('g', pack('g', $rawVectorValue))[1];
            if ($faceBox[$axis] < 0 || $faceBox[$axis] > 1 || $vectorBox[$axis] < 0 || $vectorBox[$axis] > 1
                || abs($faceBox[$axis] - $vectorBox[$axis]) > self::FACE_REGION_STORAGE_BOUND) {
                return false;
            }
        }

        return self::validNormalizedFaceRegion($faceBox) && self::validNormalizedFaceRegion($vectorBox);
    }

    public function clusterHasCurrentSourceEvidence(int $clusterId): bool
    {
        try {
            $members = DB::connection('pgsql_rag')->select('SELECT file_registry_face_id, file_registry_id, source_sha256, face_region_convention, source_current, superseded_at, region_x, region_y, region_w, region_h FROM face_embeddings WHERE person_cluster_id = ? AND source_current = TRUE AND superseded_at IS NULL', [$clusterId]);
            if ($members === []) {
                return false;
            }
            foreach ($members as $member) {
                if (! $this->embeddingHasCurrentSourceEvidence($member)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Whether a cluster has no name, identity, or individually reviewed members. */
    public function canAutomaticallyGroupCluster(int $clusterId): bool
    {
        try {
            $cluster = DB::connection('pgsql_rag')->selectOne('SELECT status, name, genealogy_person_id FROM person_clusters WHERE id = ?', [$clusterId]);
            if (! $cluster || $cluster->status !== 'unreviewed' || trim((string) $cluster->name) !== '' || ! empty($cluster->genealogy_person_id)) {
                return false;
            }
            $deadline = hrtime(true) / 1e9 + $this->automaticMatchingBounds()['max_proof_elapsed_seconds'];
            $registryIds = $this->withAutomaticMatchingReadBounds(fn () => $this->automaticGroupingClusterRegistryIds($clusterId, $deadline), $deadline);
            if (! $this->automaticGroupingCustodyAdmitted($registryIds, $deadline)) {
                return false;
            }
            $members = DB::connection('pgsql_rag')->select('SELECT file_registry_face_id FROM face_embeddings WHERE person_cluster_id = ? AND file_registry_face_id IS NOT NULL', [$clusterId]);
            $ids = array_values(array_unique(array_map(fn ($member) => (int) $member->file_registry_face_id, $members)));
            foreach ($ids as $id) {
                if ($this->faceHasOperatorReview($id)) {
                    return false;
                }
            }
            $where = 'cluster_id = ?';
            $params = [$clusterId];
            if ($ids !== []) {
                $where .= ' OR id IN ('.implode(',', array_fill(0, count($ids), '?')).')';
                $params = [...$params, ...$ids];
            }
            $faces = DB::select('SELECT id, person_name, genealogy_person_id, verified, global_identity_uuid, global_identity_approval_receipt_id FROM file_registry_faces WHERE '.$where, $params);
            foreach ($faces as $face) {
                if (trim((string) $face->person_name) !== '' || ! empty($face->genealogy_person_id) || ! empty($face->verified)
                    || $face->global_identity_uuid !== null || $face->global_identity_approval_receipt_id !== null
                    || $this->faceHasOperatorReview((int) $face->id)) {
                    return false;
                }
            }

            $count = DB::connection('pgsql_rag')->selectOne('SELECT COUNT(*) AS cnt FROM face_embeddings WHERE person_cluster_id = ? AND source_current = TRUE AND superseded_at IS NULL', [$clusterId]);

            return (int) ($count->cnt ?? 0) === 0 || $this->clusterHasCurrentSourceEvidence($clusterId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function canAutomaticallyGroupFace(int $faceEmbeddingId): bool
    {
        try {
            $embedding = DB::connection('pgsql_rag')->selectOne('SELECT person_cluster_id, file_registry_face_id, file_registry_id, source_sha256, face_region_convention, source_current, superseded_at, region_x, region_y, region_w, region_h FROM face_embeddings WHERE id = ?', [$faceEmbeddingId]);
            if (! $embedding || $embedding->person_cluster_id !== null || empty($embedding->file_registry_face_id)
                || ! $this->automaticGroupingCustodyAdmitted([(int) $embedding->file_registry_id])
                || ! $this->embeddingHasCurrentSourceEvidence($embedding)) {
                return false;
            }
            $face = DB::selectOne('SELECT frf.cluster_id, frf.person_name, frf.genealogy_person_id, frf.verified, frf.hidden, frf.global_identity_uuid, frf.global_identity_approval_receipt_id, frf.source_sha256, frf.face_region_convention, frf.source_current, frf.superseded_at, frf.source_generation_key, frf.region_x, frf.region_y, frf.region_w, frf.region_h, fr.current_path, fr.status
                FROM file_registry_faces frf JOIN file_registry fr ON fr.id = frf.file_registry_id WHERE frf.id = ?', [(int) $embedding->file_registry_face_id]);

            return $face && $face->status === 'active' && empty($face->hidden) && $face->cluster_id === null
                && $this->anonymousGenerationCompleteForFace((int) $embedding->file_registry_id, $face->source_generation_key ?? null)
                && trim((string) $face->person_name) === '' && empty($face->genealogy_person_id) && empty($face->verified)
                && $face->global_identity_uuid === null && $face->global_identity_approval_receipt_id === null
                && ! $this->faceHasOperatorReview((int) $embedding->file_registry_face_id)
                && (app(FaceLinkBridgeService::class)->validateFaceSourceEvidence($face)['success'] ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Reuse immutable custody proof; writer mode locks the source before the receipt range. */
    public function assertAutomaticAssignmentSourceResolved(int $fileRegistryId, bool $lockRegistry = false): void
    {
        if ($fileRegistryId < 1) {
            throw new \RuntimeException('matching_source_unavailable');
        }
        $deadline = hrtime(true) / 1e9 + $this->automaticMatchingBounds()['max_proof_elapsed_seconds'];
        $this->withAutomaticMatchingReadBounds(function () use ($fileRegistryId, $lockRegistry, $deadline): void {
            if ($lockRegistry && (DB::connection()->transactionLevel() < 1
                || ! DB::connection()->table('file_registry')->where('id', $fileRegistryId)->lockForUpdate()->first())) {
                throw new \RuntimeException('matching_source_unavailable');
            }
            $this->automaticAssignmentCustodyRows([$fileRegistryId], $deadline, null, $lockRegistry);
        }, $deadline);
    }

    /** Admission only: preserve legacy grouping algorithms while honoring durable UNKNOWN. */
    private function automaticGroupingCustodyAdmitted(array $registryIds, ?float $deadline = null): bool
    {
        try {
            $bounds = $this->automaticMatchingBounds();
            $deadline ??= hrtime(true) / 1e9 + $bounds['max_proof_elapsed_seconds'];
            $registryIds = array_values(array_unique($registryIds));
            if (count($registryIds) > $bounds['max_total_proof_inputs'] || array_filter($registryIds, fn ($id) => ! is_int($id) || $id < 1) !== []) {
                return false;
            }

            return $this->withAutomaticMatchingReadBounds(function () use ($registryIds, $deadline): bool {
                $this->automaticAssignmentCustodyRows($registryIds, $deadline);

                return true;
            }, $deadline);
        } catch (\Throwable) {
            return false;
        }
    }

    private function automaticGroupingClusterRegistryIds(int $clusterId, float $deadline): array
    {
        $this->checkMatchingProofDeadline($deadline);
        $cap = $this->automaticMatchingBounds()['max_total_proof_inputs'];
        $members = DB::connection('pgsql_rag')->select('SELECT file_registry_face_id, file_registry_id FROM face_embeddings
            WHERE person_cluster_id = ? ORDER BY id LIMIT ?', [$clusterId, $cap + 1]);
        if (count($members) > $cap) {
            throw new \RuntimeException('matching_protection_limit_exceeded');
        }
        $registryIds = array_map(fn ($row) => (int) ($row->file_registry_id ?? 0), $members);
        $faceIds = array_values(array_unique(array_filter(array_map(fn ($row) => (int) ($row->file_registry_face_id ?? 0), $members))));
        $where = 'cluster_id = ?';
        $params = [$clusterId];
        if ($faceIds !== []) {
            $where .= ' OR id IN ('.implode(',', array_fill(0, count($faceIds), '?')).')';
            $params = [...$params, ...$faceIds];
        }
        $faces = DB::select('SELECT file_registry_id FROM file_registry_faces WHERE '.$where.' ORDER BY id LIMIT '.($cap + 1), $params);
        if (count($faces) > $cap) {
            throw new \RuntimeException('matching_protection_limit_exceeded');
        }
        $registryIds = array_values(array_unique([...$registryIds, ...array_map(fn ($row) => (int) $row->file_registry_id, $faces)]));
        if (count($registryIds) > $cap || in_array(0, $registryIds, true)) {
            throw new \RuntimeException('matching_source_unavailable');
        }
        sort($registryIds);
        $this->checkMatchingProofDeadline($deadline);

        return $registryIds;
    }

    private function anonymousGenerationCompleteForFace(int $fileRegistryId, mixed $generationKey): bool
    {
        if (! is_string($generationKey) || $generationKey === '' || $generationKey === 'legacy') {
            return true;
        }
        try {
            $run = DB::table('file_registry_face_generation_runs')->where('file_registry_id', $fileRegistryId)
                ->where('generation_key', $generationKey)->first(['stage']);

            return $run === null || $run->stage === 'complete';
        } catch (\Throwable) {
            return false;
        }
    }

    private function faceHasOperatorReview(int $faceId, ?array $retainedReviews = null): bool
    {
        $reviews = $retainedReviews === null
            ? DB::select('SELECT status, reviewed_at, match_details FROM genealogy_face_match_queue WHERE file_registry_face_id = ?', [$faceId])
            : array_filter($retainedReviews['genealogy'], fn ($row) => (int) $row['file_registry_face_id'] === $faceId);
        foreach ($reviews as $review) {
            $review = (object) $review;
            if (in_array($review->status, ['approved', 'rejected'], true) || ! empty($review->reviewed_at)) {
                return true;
            }
            $details = empty($review->match_details) ? [] : json_decode((string) $review->match_details, true);
            if (! is_array($details)) {
                return true;
            }
            if (($details['source'] ?? null) === 'operator confirmation') {
                return true;
            }
            foreach (['operator_confirmations', 'candidate_decisions', 'latest_candidate_decision', 'rejected_candidate_ids', 'candidate_decision_version'] as $key) {
                if (array_key_exists($key, $details) && $details[$key] !== null) {
                    return true;
                }
            }
        }

        // Global face proposals and their rejections are independent of genealogy.
        // Staging is not approval, but it still reserves this exact face for review.
        if ($retainedReviews !== null) {
            foreach ($retainedReviews['global'] as $row) {
                if ((int) $row['binding_face_id'] === $faceId) {
                    return true;
                }
            }

            return false;
        }

        return DB::selectOne("SELECT id FROM agent_review_queue WHERE review_type = 'global_face_identity'
            AND CASE WHEN JSON_VALID(details) THEN JSON_UNQUOTE(JSON_EXTRACT(details, '$.global_identity_decision.operation')) ELSE NULL END = ?
            AND CASE WHEN JSON_VALID(details) THEN JSON_EXTRACT(details, '$.global_identity_decision.face_id') ELSE NULL END = ?
            LIMIT 1", ['confirm_face', $faceId]) !== null;
    }

    private function automaticMatchingBounds(): array
    {
        $bounds = config('file_registry.face_matching');
        $keys = ['max_candidate_clusters', 'max_cluster_members', 'max_total_proof_inputs', 'max_proof_elapsed_seconds'];
        if (! is_array($bounds) || array_diff($keys, array_keys($bounds)) !== []) {
            throw new \RuntimeException('matching_policy_unavailable');
        }
        $ceilings = ['max_candidate_clusters' => 25, 'max_cluster_members' => 1000,
            'max_total_proof_inputs' => 1000, 'max_proof_elapsed_seconds' => 30];
        foreach ($keys as $key) {
            if (! is_int($bounds[$key]) || $bounds[$key] < 1 || $bounds[$key] >= PHP_INT_MAX) {
                throw new \RuntimeException('matching_policy_unavailable');
            }
            $bounds[$key] = min($bounds[$key], $ceilings[$key]);
        }

        return array_intersect_key($bounds, array_flip($keys));
    }

    /** Scope supported database limits to this bounded matching step and restore them. */
    private function withAutomaticMatchingReadBounds(\Closure $operation, float $deadline, bool $checkCompletion = true): mixed
    {
        $this->checkMatchingProofDeadline($deadline);
        $milliseconds = (int) floor(($deadline - hrtime(true) / 1e9) * 1000);
        if ($milliseconds < 1000) {
            throw new \RuntimeException('matching_proof_deadline_exceeded');
        }
        $restores = [];
        $failure = null;
        $result = null;
        try {
            foreach ([DB::connection(), DB::connection('pgsql_rag')] as $connection) {
                $driver = $connection->getDriverName();
                if ($driver === 'sqlite') {
                    continue; // Dedicated behavior fixtures; no external database waits.
                }
                if ($driver === 'mysql') {
                    $version = strtolower((string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION));
                    if (str_contains($version, 'mariadb')) {
                        throw new \RuntimeException('matching_proof_budget_unsupported:mysql_statement_policy');
                    }
                    $previous = $connection->selectOne('SELECT @@SESSION.max_execution_time AS statement_ms, @@SESSION.innodb_lock_wait_timeout AS lock_seconds');
                    if (! $previous || ! isset($previous->statement_ms, $previous->lock_seconds)) {
                        throw new \RuntimeException('matching_proof_budget_unsupported:mysql_statement_policy');
                    }
                    $statement = (int) $previous->statement_ms;
                    $lock = (int) $previous->lock_seconds;
                    $restores[] = function () use ($connection, $statement, $lock): void {
                        try {
                            $connection->statement('SET SESSION max_execution_time = '.$statement);
                        } finally {
                            $connection->statement('SET SESSION innodb_lock_wait_timeout = '.$lock);
                        }
                    };
                    $connection->statement('SET SESSION max_execution_time = '.($statement > 0 ? min($statement, $milliseconds) : $milliseconds));
                    $connection->statement('SET SESSION innodb_lock_wait_timeout = '.min($lock, (int) floor($milliseconds / 1000)));
                } elseif ($driver === 'pgsql') {
                    $previous = $connection->selectOne("SELECT current_setting('statement_timeout') AS statement, current_setting('lock_timeout') AS lock");
                    if (! $previous || ! isset($previous->statement, $previous->lock)) {
                        throw new \RuntimeException('matching_proof_budget_unsupported:pgsql_statement_policy');
                    }
                    $restores[] = function () use ($connection, $previous): void {
                        try {
                            $connection->selectOne("SELECT set_config('statement_timeout', ?, false)", [$previous->statement]);
                        } finally {
                            $connection->selectOne("SELECT set_config('lock_timeout', ?, false)", [$previous->lock]);
                        }
                    };
                    foreach (['statement_timeout' => $previous->statement, 'lock_timeout' => $previous->lock] as $setting => $value) {
                        if (! preg_match('/\A(\d+)(ms|s|min|h|d)?\z/', (string) $value, $parts)) {
                            throw new \RuntimeException('matching_proof_budget_unsupported:pgsql_statement_policy');
                        }
                        $scale = match ($parts[2] ?? 'ms') {
                            's' => 1000, 'min' => 60000, 'h' => 3600000, 'd' => 86400000, default => 1
                        };
                        $existing = (int) $parts[1] * $scale;
                        $limit = $existing > 0 ? min($existing, $milliseconds) : $milliseconds;
                        $connection->selectOne("SELECT set_config('$setting', ?, false)", [$limit.'ms']);
                    }
                } else {
                    throw new \RuntimeException('matching_proof_budget_unsupported:database_driver');
                }
            }
            $this->checkMatchingProofDeadline($deadline);
            $result = $operation();
            if ($checkCompletion) {
                $this->checkMatchingProofDeadline($deadline);
            }
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            foreach (array_reverse($restores) as $restore) {
                try {
                    $restore();
                } catch (\Throwable $error) {
                    $failure ??= new \RuntimeException('matching_query_failed');
                }
            }
        }
        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    /** Complete anonymous metadata census before any model-space arithmetic. */
    private function automaticMatchingCensus(int $sourceId, array $bounds, float $deadline, array &$counts, ?array $custody = null, ?int $publishedClusterId = null): array
    {
        $this->checkMatchingProofDeadline($deadline);
        $pg = DB::connection('pgsql_rag');
        $source = $pg->selectOne('SELECT * FROM face_embeddings WHERE id = ?', [$sourceId]);
        if (! $source || ($source->person_cluster_id !== null && ($publishedClusterId === null || (int) $source->person_cluster_id !== $publishedClusterId)) || (int) ($source->file_registry_face_id ?? 0) < 1) {
            throw new \RuntimeException('matching_source_unavailable');
        }
        $clusters = $pg->select("SELECT * FROM person_clusters WHERE status = 'unreviewed'
            AND TRIM(COALESCE(name, '')) = '' AND genealogy_person_id IS NULL AND merged_into_id IS NULL
            ORDER BY id LIMIT ?", [$bounds['max_candidate_clusters'] + ($publishedClusterId === null ? 1 : 2)]);
        $counts['candidates'] = count($clusters);
        if (count($clusters) > $bounds['max_candidate_clusters'] + ($publishedClusterId === null ? 0 : 1)) {
            throw new \RuntimeException('matching_candidate_limit_exceeded');
        }
        $members = [];
        $faceIds = [(int) $source->file_registry_face_id];
        $proofInputs = [$sourceId => (array) $source];
        $counts['proof_inputs'] = 1;
        $counts['member_rows'] = 0;
        foreach ($clusters as $cluster) {
            $this->checkMatchingProofDeadline($deadline);
            $rows = $pg->select('SELECT * FROM face_embeddings WHERE person_cluster_id = ? ORDER BY id LIMIT ?',
                [(int) $cluster->id, $bounds['max_cluster_members'] + 1]);
            $counts['member_rows'] += count($rows);
            if (count($rows) > $bounds['max_cluster_members']) {
                throw new \RuntimeException('matching_member_limit_exceeded');
            }
            if ($counts['member_rows'] > $bounds['max_total_proof_inputs']) {
                throw new \RuntimeException('matching_protection_limit_exceeded');
            }
            foreach ($rows as $row) {
                if ((int) ($row->file_registry_face_id ?? 0) > 0) {
                    $faceIds[] = (int) $row->file_registry_face_id;
                }
                if ($this->matchingVectorIsCurrent($row)) {
                    $proofInputs[(int) $row->id] = (array) $row;
                }
            }
            $members[(int) $cluster->id] = array_map(fn ($row) => (array) $row, $rows);
        }
        $counts['proof_inputs'] = count($proofInputs);
        if (count($proofInputs) > $bounds['max_total_proof_inputs']) {
            throw new \RuntimeException('matching_proof_input_limit_exceeded');
        }
        $faceIds = array_values(array_unique($faceIds));
        $clusterIds = array_map(fn ($row) => (int) $row->id, $clusters);
        $faceSlots = implode(',', array_fill(0, count($faceIds), '?'));
        $where = 'id IN ('.$faceSlots.')';
        $params = $faceIds;
        if ($clusterIds !== []) {
            $where .= ' OR cluster_id IN ('.implode(',', array_fill(0, count($clusterIds), '?')).')';
            $params = [...$params, ...$clusterIds];
        }
        $cap = $bounds['max_total_proof_inputs'];
        $faces = DB::select('SELECT * FROM file_registry_faces WHERE '.$where.' ORDER BY id LIMIT '.($cap + 1), $params);
        if (count($faces) > $cap) {
            throw new \RuntimeException('matching_protection_limit_exceeded');
        }
        $byFace = array_column(array_map(fn ($row) => (array) $row, $faces), null, 'id');
        if (array_diff($faceIds, array_keys($byFace)) !== []) {
            throw new \RuntimeException('matching_source_unavailable');
        }
        $faceIds = array_map('intval', array_keys($byFace));
        $faceSlots = implode(',', array_fill(0, count($faceIds), '?'));
        $genealogy = DB::select('SELECT * FROM genealogy_face_match_queue WHERE file_registry_face_id IN ('.$faceSlots.') ORDER BY id LIMIT '.($cap + 1), $faceIds);
        $global = DB::select("SELECT *, CASE WHEN JSON_VALID(details) THEN JSON_EXTRACT(details, '$.global_identity_decision.face_id') ELSE NULL END AS binding_face_id
            FROM agent_review_queue WHERE review_type = 'global_face_identity'
            AND CASE WHEN JSON_VALID(details) THEN JSON_UNQUOTE(JSON_EXTRACT(details, '$.global_identity_decision.operation')) ELSE NULL END = ?
            AND CASE WHEN JSON_VALID(details) THEN JSON_EXTRACT(details, '$.global_identity_decision.face_id') ELSE NULL END IN ($faceSlots)
            ORDER BY id LIMIT ".($cap + 1), ['confirm_face', ...$faceIds]);
        if (count($genealogy) > $cap || count($global) > $cap) {
            throw new \RuntimeException('matching_protection_limit_exceeded');
        }
        $reviews = ['genealogy' => array_map(fn ($row) => (array) $row, $genealogy), 'global' => array_map(fn ($row) => (array) $row, $global)];
        foreach ($faces as $face) {
            if (trim((string) $face->person_name) !== '' || ! empty($face->genealogy_person_id) || ! empty($face->verified)
                || $face->global_identity_uuid !== null || $face->global_identity_approval_receipt_id !== null
                || $this->faceHasOperatorReview((int) $face->id, $reviews)) {
                throw new \RuntimeException('matching_operator_review_required');
            }
        }
        $sourceFace = (object) $byFace[$source->file_registry_face_id];
        if (($sourceFace->cluster_id !== null && ($publishedClusterId === null || (int) $sourceFace->cluster_id !== $publishedClusterId)) || ! empty($sourceFace->hidden)
            || ! $this->anonymousGenerationCompleteForFace((int) $source->file_registry_id, $sourceFace->source_generation_key ?? null)) {
            throw new \RuntimeException('matching_operator_review_required');
        }
        $registryIds = array_values(array_unique(array_map(fn ($row) => (int) $row->file_registry_id, $faces)));
        foreach ($members as $rows) {
            foreach ($rows as $row) {
                if ((int) ($row['file_registry_id'] ?? 0) < 1) {
                    throw new \RuntimeException('matching_source_unavailable');
                }
                $registryIds[] = (int) $row['file_registry_id'];
            }
        }
        $registryIds[] = (int) $source->file_registry_id;
        $registryIds = array_values(array_unique($registryIds));
        if (count($registryIds) > $cap) {
            throw new \RuntimeException('matching_protection_limit_exceeded');
        }
        $registrySlots = implode(',', array_fill(0, count($registryIds), '?'));
        $registries = DB::select('SELECT * FROM file_registry WHERE id IN ('.$registrySlots.') ORDER BY id LIMIT '.($cap + 1), $registryIds);
        if (count($registries) !== count($registryIds)) {
            throw new \RuntimeException('matching_source_unavailable');
        }
        $receipts = $this->automaticAssignmentCustodyRows($registryIds, $deadline, $custody);
        ksort($proofInputs);
        $this->checkMatchingProofDeadline($deadline);

        return ['source' => (array) $source, 'clusters' => array_map(fn ($row) => (array) $row, $clusters),
            'members' => $members, 'proof_inputs' => $proofInputs, 'faces' => array_values($byFace),
            'registries' => array_map(fn ($row) => (array) $row, $registries), 'reviews' => $reviews, 'custody_receipts' => $receipts];
    }

    private function matchingVectorIsCurrent(object $row): bool
    {
        return in_array($row->source_current ?? null, [true, 1, '1', 't'], true) && ($row->superseded_at ?? null) === null;
    }

    private function automaticMatchingProofs(array $census, array $callerValues, float $deadline, array &$counts): array
    {
        $proofs = [];
        $sourceContract = null;
        foreach ($census['proof_inputs'] as $id => $row) {
            $this->checkMatchingProofDeadline($deadline);
            $counts['strict_proof_reads']++;
            $proof = $this->withAutomaticMatchingReadBounds(fn (): array => $this->comparisonEmbeddingContractEvidence((int) $id, $deadline), $deadline);
            foreach ((array) $proof['row'] as $key => $value) {
                if (! array_key_exists($key, $row) || $row[$key] !== $value) {
                    throw new \RuntimeException('matching_proof_changed');
                }
            }
            if ((int) $id === (int) $census['source']['id']) {
                $storedHash = $this->faceVectorRefreshEmbeddingSha256($row['embedding']);
                if ($storedHash === null || $this->faceVectorRefreshEmbeddingSha256($callerValues) !== $storedHash
                    || ($proof['generation_provenance']['embedding_float32_sha256'] ?? null) !== $storedHash) {
                    throw new \RuntimeException('matching_caller_vector_changed');
                }
                $sourceContract = $proof['contract'];
            }
            $proofs[$id] = ['pin' => $proof['pin'], 'contract' => $proof['contract'], 'generation_provenance' => $proof['generation_provenance']];
        }
        foreach ($proofs as $proof) {
            if ($sourceContract === null || $proof['contract'] !== $sourceContract) {
                throw new \RuntimeException('matching_contract_incompatible');
            }
        }
        $this->checkMatchingProofDeadline($deadline);

        return $proofs;
    }

    private function automaticMatchingScores(int $sourceId, array $census, float $deadline): array
    {
        $matches = [];
        foreach ($census['clusters'] as $cluster) {
            $ids = [];
            foreach ($census['members'][$cluster['id']] as $row) {
                if ($this->matchingVectorIsCurrent((object) $row)) {
                    $ids[] = (int) $row['id'];
                }
            }
            if ($ids === []) {
                continue;
            }
            $slots = implode(',', array_fill(0, count($ids), '?'));
            $score = $this->withAutomaticMatchingReadBounds(fn () => DB::connection('pgsql_rag')->selectOne("SELECT COUNT(*) AS member_count,
                MAX(1 - (source.embedding <=> fe.embedding)) AS confidence,
                AVG(1 - (source.embedding <=> fe.embedding)) AS avg_similarity
                FROM face_embeddings source JOIN face_embeddings fe ON fe.id IN ($slots)
                WHERE source.id = ? AND source.source_current = TRUE AND source.superseded_at IS NULL
                AND fe.person_cluster_id = ? AND fe.source_current = TRUE AND fe.superseded_at IS NULL", [...$ids, $sourceId, (int) $cluster['id']]), $deadline);
            if (! $score || (int) $score->member_count !== count($ids) || ! is_numeric($score->confidence) || ! is_numeric($score->avg_similarity)
                || ! is_finite((float) $score->confidence) || ! is_finite((float) $score->avg_similarity)) {
                throw new \RuntimeException('matching_query_failed');
            }
            if ((float) $score->confidence >= 1 - self::MATCH_TOLERANCE) {
                $matches[] = ['cluster_id' => (int) $cluster['id'], 'confidence' => (float) $score->confidence,
                    'avg_similarity' => (float) $score->avg_similarity, 'face_count' => (int) $cluster['face_count']];
            }
        }
        usort($matches, fn ($a, $b) => ($b['confidence'] <=> $a['confidence']) ?: ($a['cluster_id'] <=> $b['cluster_id']));
        $this->checkMatchingProofDeadline($deadline);

        return $matches;
    }

    private function verifyAutomaticAssignmentPostimage(array $before, array $after, int $clusterId, float $confidence): void
    {
        $expected = $before;
        $source = $after['source'];
        $sourceBefore = $before['source'];
        foreach (['person_cluster_id', 'match_confidence', 'updated_at'] as $key) {
            unset($source[$key], $sourceBefore[$key]);
        }
        if ($source !== $sourceBefore || (int) $after['source']['person_cluster_id'] !== $clusterId
            || pack('f', (float) $after['source']['match_confidence']) !== pack('f', $confidence)) {
            throw new \RuntimeException('matching_custody_postimage_changed');
        }
        $expected['source'] = $after['source'];
        $expected['proof_inputs'][$before['source']['id']] = $after['source'];
        foreach ($expected['faces'] as &$face) {
            if ((int) $face['id'] === (int) $before['source']['file_registry_face_id']) {
                $face['cluster_id'] = $clusterId;
            }
        }
        unset($face);
        $clusters = array_column($after['clusters'], null, 'id');
        $target = $clusters[$clusterId] ?? null;
        if (! $target) {
            throw new \RuntimeException('matching_custody_postimage_changed');
        }
        $existing = false;
        foreach ($expected['clusters'] as &$cluster) {
            if ((int) $cluster['id'] === $clusterId) {
                $existing = true;
                $cluster['face_count'] = (int) $cluster['face_count'] + 1;
                $cluster['updated_at'] = $target['updated_at'];
            }
        }
        unset($cluster);
        if (! $existing) {
            if ($target['status'] !== 'unreviewed' || trim((string) $target['name']) !== ''
                || $target['genealogy_person_id'] !== null || $target['merged_into_id'] !== null
                || ($target['representative_face_id'] ?? null) !== null || (int) $target['face_count'] !== 1) {
                throw new \RuntimeException('matching_custody_postimage_changed');
            }
            $expected['clusters'][] = $target;
            usort($expected['clusters'], fn ($a, $b) => $a['id'] <=> $b['id']);
        }
        $expected['members'][$clusterId][] = $after['source'];
        usort($expected['members'][$clusterId], fn ($a, $b) => $a['id'] <=> $b['id']);
        if ($this->automaticAssignmentDigest($expected) !== $this->automaticAssignmentDigest($after)) {
            throw new \RuntimeException('matching_custody_postimage_changed');
        }
    }

    private function automaticAssignmentReference(int $registryId): string
    {
        if ($registryId < 1) {
            throw new \RuntimeException('matching_source_unavailable');
        }

        return Uuid::uuid5(Uuid::NAMESPACE_URL,
            'plos:auto-face-cluster-assignment:file-registry:'.$registryId)->toString();
    }

    private function automaticAssignmentPolicy(): array
    {
        $policy = config('file_registry.face_matching');
        $ceilings = ['max_assignment_receipts_per_source' => 1000, 'max_assignment_backup_bytes' => 16 * 1024 * 1024];
        foreach ($ceilings as $key => $ceiling) {
            if (! is_int($policy[$key] ?? null) || $policy[$key] < 1) {
                throw new \RuntimeException('matching_policy_unavailable');
            }
            $policy[$key] = min($policy[$key], $ceiling);
        }
        $directory = $policy['assignment_private_directory'] ?? null;
        if (! is_string($directory) || ! preg_match('#\Aapp/private/[a-zA-Z0-9_-]+\z#D', $directory)) {
            throw new \RuntimeException('matching_policy_unavailable');
        }

        return array_intersect_key($policy, array_flip([...array_keys($ceilings), 'assignment_private_directory']));
    }

    private function automaticAssignmentDigest(array $value): string
    {
        return hash('sha256', $this->canonicalFaceProvenanceJson($value));
    }

    /** A source's unresolved receipt also excludes its vectors from candidate arithmetic. */
    private function automaticAssignmentCustodyRows(array $registryIds, float $deadline, ?array $own = null, bool $lock = false): array
    {
        $this->checkMatchingProofDeadline($deadline);
        $policy = $this->automaticAssignmentPolicy();
        $references = array_map(fn ($id) => $this->automaticAssignmentReference((int) $id), $registryIds);
        if ($own !== null) {
            $owned = DB::connection()->table('review_desk_receipts')->where('id', $own['row']['id'])->first();
            if (! $owned || $this->canonicalFaceProvenanceJson((array) $owned) !== $this->canonicalFaceProvenanceJson($own['row'])) {
                throw new \RuntimeException('matching_custody_changed');
            }
            $this->automaticAssignmentPayload($owned);
        }
        // The entire invocation is capped too; never multiply per-source history by the member cap.
        $rows = DB::connection()->table('review_desk_receipts')->whereIn('reference', $references)
            ->where('action', self::AUTOMATIC_ASSIGNMENT_ACTION)->orderBy('id')
            ->limit($policy['max_assignment_receipts_per_source'] + 1)->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($rows->count() > $policy['max_assignment_receipts_per_source']) {
            throw new \RuntimeException('matching_custody_limit_exceeded');
        }
        $result = [];
        foreach ($rows as $row) {
            $this->checkMatchingProofDeadline($deadline);
            if ($own !== null && $row->id === $own['row']['id']) {
                if ($this->canonicalFaceProvenanceJson((array) $row) !== $this->canonicalFaceProvenanceJson($own['row'])) {
                    throw new \RuntimeException('matching_custody_changed');
                }

                continue;
            }
            $payload = $this->automaticAssignmentPayload($row);
            if ($row->state !== 'completed') {
                throw new \RuntimeException('matching_custody_outcome_held');
            }
            $this->verifyAutomaticAssignmentCompletion($row, $payload, $deadline);
            $result[] = (array) $row;
        }
        $this->checkMatchingProofDeadline($deadline);

        return $result;
    }

    private function automaticAssignmentPayload(object $row): array
    {
        try {
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new \RuntimeException('matching_custody_changed');
        }
        $request = $payload['request'] ?? null;
        if (! is_array($payload) || ! is_array($request) || ($payload['schema'] ?? null) !== self::AUTOMATIC_ASSIGNMENT_SCHEMA
            || ($payload['id'] ?? null) !== $row->id || ! Str::isUuid($row->id)
            || $row->action !== self::AUTOMATIC_ASSIGNMENT_ACTION || ($payload['reference'] ?? null) !== $row->reference
            || ($payload['idempotency_key'] ?? null) !== $row->idempotency_key
            || ($request['receipt_id'] ?? null) !== $row->id || ($request['reference'] ?? null) !== $row->reference
            || $row->reference !== $this->automaticAssignmentReference((int) ($request['file_registry_id'] ?? 0))
            || $row->idempotency_key !== 'face:'.(int) ($request['file_registry_face_id'] ?? 0)
            || (int) ($request['file_registry_face_id'] ?? 0) < 1
            || ($payload['request_digest'] ?? null) !== $row->request_digest
            || ! hash_equals($row->request_digest, $this->automaticAssignmentDigest($request))) {
            throw new \RuntimeException('matching_custody_changed');
        }

        return $payload;
    }

    private function automaticAssignmentDirectory(string $receiptId, bool $create): string
    {
        if (! Str::isUuid($receiptId) || ! function_exists('posix_geteuid')) {
            throw new \RuntimeException('matching_custody_backup_unavailable');
        }
        $root = realpath(storage_path());
        if ($root === false) {
            throw new \RuntimeException('matching_custody_backup_unavailable');
        }
        $relative = $this->automaticAssignmentPolicy()['assignment_private_directory'];
        $directory = $root;
        foreach (explode('/', $relative.'/'.$receiptId) as $part) {
            $directory .= '/'.$part;
            clearstatcache(true, $directory);
            if (is_link($directory)) {
                throw new \RuntimeException('matching_custody_backup_unavailable');
            }
            if (! is_dir($directory)) {
                if (! $create || ! @mkdir($directory, 0700)) {
                    throw new \RuntimeException('matching_custody_backup_unavailable');
                }
                $parent = @fopen(dirname($directory), 'r');
                if ($parent === false) {
                    throw new \RuntimeException('matching_custody_backup_unavailable');
                }
                try {
                    if (! fsync($parent)) {
                        throw new \RuntimeException('matching_custody_backup_unavailable');
                    }
                } finally {
                    fclose($parent);
                }
            }
        }
        foreach ([dirname($directory), $directory] as $owned) {
            $stat = lstat($owned);
            if (! $stat || ($stat['mode'] & 0777) !== 0700 || $stat['uid'] !== posix_geteuid()) {
                throw new \RuntimeException('matching_custody_backup_unavailable');
            }
        }

        return $directory;
    }

    /** Exclusive immutable files; failures preserve partial evidence and never overwrite it. */
    protected function writeAutomaticAssignmentArtifact(string $receiptId, string $filename, array $value, float $deadline): array
    {
        $this->checkMatchingProofDeadline($deadline);
        if (! in_array($filename, ['preimage.json', 'postimage.json', 'completion.json'], true)) {
            throw new \RuntimeException('matching_custody_backup_unavailable');
        }
        $bytes = $this->canonicalFaceProvenanceJson($value);
        $maximum = $this->automaticAssignmentPolicy()['max_assignment_backup_bytes'];
        if (strlen($bytes) > $maximum) {
            throw new \RuntimeException('matching_custody_backup_limit_exceeded');
        }
        $directory = $this->automaticAssignmentDirectory($receiptId, true);
        $path = $directory.'/'.$filename;
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('matching_custody_backup_unavailable');
        }
        try {
            if (! chmod($path, 0600)) {
                throw new \RuntimeException('matching_custody_backup_unavailable');
            }
            for ($offset = 0; $offset < strlen($bytes); $offset += 65536) {
                $this->checkMatchingProofDeadline($deadline);
                $chunk = substr($bytes, $offset, 65536);
                if (fwrite($handle, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('matching_custody_backup_unavailable');
                }
            }
            if (! fflush($handle) || ! fsync($handle)) {
                throw new \RuntimeException('matching_custody_backup_unavailable');
            }
        } finally {
            fclose($handle);
        }
        $parent = @fopen($directory, 'r');
        if ($parent === false) {
            throw new \RuntimeException('matching_custody_backup_unavailable');
        }
        try {
            if (! fsync($parent)) {
                throw new \RuntimeException('matching_custody_backup_unavailable');
            }
        } finally {
            fclose($parent);
        }
        $descriptor = ['receipt_id' => $receiptId, 'filename' => $filename, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        if ($this->canonicalFaceProvenanceJson($this->readAutomaticAssignmentArtifact($descriptor, $deadline)) !== $bytes) {
            throw new \RuntimeException('matching_custody_backup_changed');
        }

        return $descriptor;
    }

    private function readAutomaticAssignmentArtifact(array $descriptor, float $deadline): array
    {
        $maximum = $this->automaticAssignmentPolicy()['max_assignment_backup_bytes'];
        if (! Str::isUuid($descriptor['receipt_id'] ?? '')
            || ! in_array($descriptor['filename'] ?? null, ['preimage.json', 'postimage.json', 'completion.json'], true)
            || ! is_int($descriptor['bytes'] ?? null) || $descriptor['bytes'] < 1 || $descriptor['bytes'] > $maximum
            || ! preg_match('/\A[a-f0-9]{64}\z/D', $descriptor['sha256'] ?? '')) {
            throw new \RuntimeException('matching_custody_backup_changed');
        }
        $path = $this->automaticAssignmentDirectory($descriptor['receipt_id'], false).'/'.$descriptor['filename'];
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (! $before || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0777) !== 0600
            || $before['uid'] !== posix_geteuid() || $before['nlink'] !== 1 || $before['size'] !== $descriptor['bytes']) {
            throw new \RuntimeException('matching_custody_backup_changed');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('matching_custody_backup_changed');
        }
        $bytes = '';
        try {
            $pin = fn ($stat) => is_array($stat) ? array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'])) : null;
            if ($pin(fstat($handle)) !== $pin($before)) {
                throw new \RuntimeException('matching_custody_backup_changed');
            }
            while (! feof($handle)) {
                $this->checkMatchingProofDeadline($deadline);
                $chunk = fread($handle, 65536);
                if ($chunk === false || ($chunk === '' && ! feof($handle)) || strlen($bytes) + strlen($chunk) > $maximum) {
                    throw new \RuntimeException('matching_custody_backup_changed');
                }
                $bytes .= $chunk;
            }
            clearstatcache(true, $path);
            if ($pin(fstat($handle)) !== $pin($before) || $pin(lstat($path)) !== $pin($before) || strlen($bytes) !== $descriptor['bytes']
                || ! hash_equals($descriptor['sha256'], hash('sha256', $bytes))) {
                throw new \RuntimeException('matching_custody_backup_changed');
            }
        } finally {
            fclose($handle);
        }
        $this->checkMatchingProofDeadline($deadline);

        try {
            $decoded = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new \RuntimeException('matching_custody_backup_changed');
            }

            return $decoded;
        } catch (\Throwable $error) {
            throw new \RuntimeException('matching_custody_backup_changed');
        }
    }

    private function verifyAutomaticAssignmentCompletion(object $row, array $payload, float $deadline): array
    {
        $terminal = $payload['terminal'] ?? null;
        if (! is_array($terminal) || ($payload['effect_status'] ?? null) !== 'completed'
            || ! is_array($terminal['preimage'] ?? null) || ! is_array($terminal['postimage'] ?? null)
            || ($terminal['request_digest'] ?? null) !== $row->request_digest
            || ($terminal['preimage']['receipt_id'] ?? null) !== $row->id || ($terminal['preimage']['filename'] ?? null) !== 'preimage.json'
            || ($terminal['postimage']['receipt_id'] ?? null) !== $row->id || ($terminal['postimage']['filename'] ?? null) !== 'postimage.json'
            || ($terminal['publication']['mysql_commit_acknowledged'] ?? false) !== true
            || ($terminal['publication']['pg_commit_acknowledged'] ?? false) !== true
            || ($payload['terminal_digest'] ?? null) !== $this->automaticAssignmentDigest($terminal)) {
            throw new \RuntimeException('matching_custody_outcome_held');
        }
        $pre = $this->readAutomaticAssignmentArtifact($terminal['preimage'], $deadline);
        $post = $this->readAutomaticAssignmentArtifact($terminal['postimage'], $deadline);
        if (($pre['request_digest'] ?? null) !== $row->request_digest || ($post['request_digest'] ?? null) !== $row->request_digest
            || ($pre['receipt_id'] ?? null) !== $row->id || ($post['receipt_id'] ?? null) !== $row->id
            || ($pre['schema'] ?? null) !== self::AUTOMATIC_ASSIGNMENT_SCHEMA || ($post['schema'] ?? null) !== self::AUTOMATIC_ASSIGNMENT_SCHEMA
            || ! is_array($pre['census'] ?? null) || ! is_array($pre['proofs'] ?? null) || ! is_array($post['census'] ?? null)
            || $this->automaticAssignmentDigest($pre['census']) !== ($payload['request']['census_sha256'] ?? null)
            || $this->automaticAssignmentDigest($pre['proofs']) !== ($payload['request']['proofs_sha256'] ?? null)
            || (int) ($pre['census']['source']['id'] ?? 0) !== (int) $payload['request']['face_embedding_id']
            || (int) ($pre['census']['source']['file_registry_id'] ?? 0) !== (int) $payload['request']['file_registry_id']
            || (int) ($pre['census']['source']['file_registry_face_id'] ?? 0) !== (int) $payload['request']['file_registry_face_id']
            || ($terminal['publication']['effects_started'] ?? false) !== true
            || (int) ($terminal['publication']['cluster_id'] ?? 0) < 1) {
            throw new \RuntimeException('matching_custody_changed');
        }
        $this->verifyAutomaticAssignmentPostimage($pre['census'], $post['census'], (int) $terminal['publication']['cluster_id'], (float) $payload['request']['confidence']);
        // Its expected bytes are derived from the exact full terminal row, never an untrusted file hash.
        $seal = ['schema' => self::AUTOMATIC_ASSIGNMENT_SCHEMA, 'receipt' => (array) $row,
            'request_digest' => $row->request_digest, 'preimage' => $terminal['preimage'], 'postimage' => $terminal['postimage'],
            'terminal_digest' => $payload['terminal_digest']];
        $bytes = $this->canonicalFaceProvenanceJson($seal);
        if ($this->readAutomaticAssignmentArtifact(['receipt_id' => $row->id, 'filename' => 'completion.json',
            'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], $deadline) != $seal) {
            throw new \RuntimeException('matching_custody_outcome_held');
        }

        return $post;
    }

    protected function claimAutomaticAssignmentReceipt(array $request, float $deadline, array &$publication): array
    {
        $payload = ['schema' => self::AUTOMATIC_ASSIGNMENT_SCHEMA, 'id' => $request['receipt_id'],
            'reference' => $request['reference'], 'idempotency_key' => $request['idempotency_key'],
            'request' => $request, 'request_digest' => $this->automaticAssignmentDigest($request), 'effect_status' => 'not_started'];
        $row = ['id' => $request['receipt_id'], 'reference' => $request['reference'], 'idempotency_key' => $request['idempotency_key'],
            'request_digest' => $payload['request_digest'], 'action' => self::AUTOMATIC_ASSIGNMENT_ACTION, 'state' => 'processing',
            'payload' => $this->canonicalFaceProvenanceJson($payload), 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()];
        $result = $this->withAutomaticMatchingReadBounds(function () use ($request, $row, $deadline, &$publication): array {
            return DB::connection()->transaction(function () use ($request, $row, $deadline, &$publication): array {
                $registry = DB::connection()->table('file_registry')->where('id', $request['file_registry_id'])->lockForUpdate()->first();
                if (! $registry || $registry->asset_uuid !== $request['asset_uuid']) {
                    throw new \RuntimeException('matching_source_unavailable');
                }
                $this->automaticAssignmentCustodyRows([$request['file_registry_id']], $deadline);
                if (DB::connection()->table('review_desk_receipts')->where('reference', $row['reference'])
                    ->where('idempotency_key', $row['idempotency_key'])->lockForUpdate()->exists()) {
                    throw new \RuntimeException('matching_custody_outcome_held');
                }
                $publication['receipt_id'] = $row['id'];
                $publication['receipt_claim_attempted'] = true;
                DB::connection()->table('review_desk_receipts')->insert($row);

                return $row;
            });
        }, $deadline);
        $publication['receipt_claim_acknowledged'] = true;
        $publication['receipt_id'] = $row['id'];
        $fresh = $this->withAutomaticMatchingReadBounds(fn () => DB::connection()->table('review_desk_receipts')->where('id', $row['id'])->first(), $deadline);
        if (! $fresh || $this->canonicalFaceProvenanceJson((array) $fresh) !== $this->canonicalFaceProvenanceJson($result)) {
            throw new \RuntimeException('matching_custody_changed');
        }

        return ['row' => $result, 'payload' => $payload];
    }

    protected function updateAutomaticAssignmentReceipt(array $custody, array $payload, string $state, float $deadline): array
    {
        return $this->withAutomaticMatchingReadBounds(function () use ($custody, $payload, $state): array {
            return DB::connection()->transaction(function () use ($custody, $payload, $state): array {
                $row = $custody['row'];
                $locked = DB::connection()->table('review_desk_receipts')->where('id', $row['id'])->lockForUpdate()->first();
                if (! $locked || $this->canonicalFaceProvenanceJson((array) $locked) !== $this->canonicalFaceProvenanceJson($row)) {
                    throw new \RuntimeException('matching_custody_changed');
                }
                $encoded = $this->canonicalFaceProvenanceJson($payload);
                $updatedAt = now()->toDateTimeString();
                $query = DB::connection()->table('review_desk_receipts');
                foreach ($row as $key => $value) {
                    $query->where($key, $value);
                }
                if ($query->update(['payload' => $encoded, 'state' => $state, 'updated_at' => $updatedAt]) !== 1) {
                    throw new \RuntimeException('matching_custody_changed');
                }
                $fresh = DB::connection()->table('review_desk_receipts')->where('id', $row['id'])->first();
                $expected = array_replace($row, ['payload' => $encoded, 'state' => $state, 'updated_at' => $updatedAt]);
                if (! $fresh || $this->canonicalFaceProvenanceJson((array) $fresh) !== $this->canonicalFaceProvenanceJson($expected)) {
                    throw new \RuntimeException('matching_custody_changed');
                }

                return ['row' => (array) $fresh, 'payload' => $payload];
            });
        }, $deadline);
    }

    public function assignToCluster(int $faceEmbeddingId, array $embedding): array
    {
        $started = hrtime(true) / 1e9;
        $counts = ['candidates' => 0, 'member_rows' => 0, 'proof_inputs' => 0, 'strict_proof_reads' => 0];
        $bounds = [];
        $publication = ['receipt_claim_attempted' => false, 'receipt_claim_acknowledged' => false, 'receipt_id' => null,
            'effects_started' => false, 'pg_commit_attempted' => false, 'pg_commit_acknowledged' => false,
            'mysql_commit_attempted' => false, 'mysql_commit_acknowledged' => false, 'cluster_id' => null];
        try {
            $bounds = $this->automaticMatchingBounds();
            $this->automaticAssignmentPolicy();
            $deadline = $started + $bounds['max_proof_elapsed_seconds'];
            $current = $this->withAutomaticMatchingReadBounds(function () use ($faceEmbeddingId, $deadline): object {
                if (DB::connection()->transactionLevel() !== 0) {
                    throw new \RuntimeException('matching_proof_budget_unsupported:ambient_mysql_transaction');
                }
                if (DB::connection('pgsql_rag')->transactionLevel() !== 0) {
                    throw new \RuntimeException('matching_proof_budget_unsupported:ambient_pg_transaction');
                }
                $row = DB::connection('pgsql_rag')->selectOne('SELECT * FROM face_embeddings WHERE id = ?', [$faceEmbeddingId]);
                if (! $row || (int) ($row->file_registry_face_id ?? 0) < 1 || (int) ($row->file_registry_id ?? 0) < 1) {
                    throw new \RuntimeException('matching_source_unavailable');
                }
                $this->automaticAssignmentCustodyRows([(int) $row->file_registry_id], $deadline);

                return $row;
            }, $deadline);
            if ($current->person_cluster_id !== null) {
                // Owned completed receipts require the exact fresh postimage, never a new assignment.
                $this->withAutomaticMatchingReadBounds(function () use ($current, $bounds, $deadline, $embedding, &$counts): void {
                    $rows = $this->automaticAssignmentCustodyRows([(int) $current->file_registry_id], $deadline);
                    foreach ($rows as $receipt) {
                        $payload = $this->automaticAssignmentPayload((object) $receipt);
                        if ((int) $payload['request']['face_embedding_id'] === (int) $current->id) {
                            $post = $this->verifyAutomaticAssignmentCompletion((object) $receipt, $payload, $deadline);
                            $fresh = $this->automaticMatchingCensus((int) $current->id, $bounds, $deadline, $counts,
                                ['row' => $receipt], (int) $current->person_cluster_id);
                            if ($this->automaticAssignmentDigest($fresh) !== $this->automaticAssignmentDigest($post['census'])) {
                                throw new \RuntimeException('matching_custody_postimage_changed');
                            }
                            $this->automaticMatchingProofs($fresh, $embedding, $deadline, $counts);
                        }
                    }
                }, $deadline);

                return ['action' => 'retained', 'retained_cluster_id' => $current->person_cluster_id, 'reason' => 'existing_membership_preserved'];
            }
            $planned = $this->withAutomaticMatchingReadBounds(function () use ($faceEmbeddingId, $embedding, $bounds, $deadline, &$counts): array {
                $pg = DB::connection('pgsql_rag');
                $isolation = 'REPEATABLE READ';
                if ($pg->getDriverName() === 'pgsql') {
                    if ($pg->transactionLevel() !== 0) {
                        throw new \RuntimeException('matching_proof_budget_unsupported:ambient_pg_transaction');
                    }
                    $settings = $pg->selectOne("SELECT current_setting('default_transaction_isolation') AS isolation");
                    if (! $settings || ! in_array($settings->isolation, ['read uncommitted', 'read committed', 'repeatable read', 'serializable'], true)) {
                        throw new \RuntimeException('matching_proof_budget_unsupported:pgsql_snapshot_policy');
                    }
                    if ($settings->isolation === 'serializable') {
                        $isolation = 'SERIALIZABLE';
                    }
                }

                return $pg->transaction(function () use ($pg, $isolation, $faceEmbeddingId, $embedding, $bounds, $deadline, &$counts): array {
                    if ($pg->getDriverName() === 'pgsql') {
                        $pg->statement('SET TRANSACTION ISOLATION LEVEL '.$isolation.' READ ONLY');
                    }
                    $census = $this->automaticMatchingCensus($faceEmbeddingId, $bounds, $deadline, $counts);
                    $proofs = $this->automaticMatchingProofs($census, $embedding, $deadline, $counts);
                    $matches = $this->automaticMatchingScores($faceEmbeddingId, $census, $deadline);

                    return compact('census', 'proofs', 'matches');
                });
            }, $deadline);
            $initial = $planned['census'];
            $proofs = $planned['proofs'];
            $matches = $planned['matches'];
            $clusterId = null;
            $confidence = 0.0;
            foreach (array_slice($matches, 0, 5) as $match) {
                $threshold = self::HIGH_CONFIDENCE + ($match['face_count'] >= self::CLUSTER_SOFT_CAP ? self::CLUSTER_CAP_BOOST : 0);
                if ($match['avg_similarity'] >= $threshold) {
                    $clusterId = $match['cluster_id'];
                    $confidence = $match['avg_similarity'];
                    break;
                }
            }
            $singleton = $clusterId === null;
            $request = ['receipt_id' => (string) Str::uuid(), 'reference' => $this->automaticAssignmentReference((int) $initial['source']['file_registry_id']),
                'schema' => self::AUTOMATIC_ASSIGNMENT_SCHEMA, 'action' => self::AUTOMATIC_ASSIGNMENT_ACTION,
                'idempotency_key' => 'face:'.(int) $initial['source']['file_registry_face_id'],
                'file_registry_id' => (int) $initial['source']['file_registry_id'], 'file_registry_face_id' => (int) $initial['source']['file_registry_face_id'],
                'face_embedding_id' => $faceEmbeddingId, 'asset_uuid' => array_column($initial['registries'], null, 'id')[$initial['source']['file_registry_id']]['asset_uuid'],
                'census_sha256' => $this->automaticAssignmentDigest($initial), 'proofs_sha256' => $this->automaticAssignmentDigest($proofs),
                'source_binding' => $proofs[$faceEmbeddingId],
                'source_path_sha256' => hash('sha256', array_column($initial['registries'], null, 'id')[$initial['source']['file_registry_id']]['current_path']),
                'limits' => $bounds, 'custody_policy' => $this->automaticAssignmentPolicy(), 'target_cluster_id' => $clusterId, 'confidence' => $confidence];
            $custody = $this->claimAutomaticAssignmentReceipt($request, $deadline, $publication);
            $preimage = $this->writeAutomaticAssignmentArtifact($request['receipt_id'], 'preimage.json',
                ['schema' => self::AUTOMATIC_ASSIGNMENT_SCHEMA, 'receipt_id' => $request['receipt_id'],
                    'request_digest' => $custody['row']['request_digest'], 'census' => $initial, 'proofs' => $proofs], $deadline);
            $payload = $custody['payload'];
            $payload['preimage'] = $preimage;
            $custody = $this->updateAutomaticAssignmentReceipt($custody, $payload, 'processing', $deadline);
            $publishedId = $this->publishAutomaticAssignment($faceEmbeddingId, $clusterId, $confidence, $initial, $proofs, $embedding, $bounds, $deadline, $counts, $publication, $custody);
            if ($publishedId === null) {
                throw new \RuntimeException('matching_membership_changed');
            }

            $post = $this->withAutomaticMatchingReadBounds(function () use ($faceEmbeddingId, $publishedId, $initial, $proofs, $embedding, $confidence, $bounds, $deadline, &$counts, $custody): array {
                $census = $this->automaticMatchingCensus($faceEmbeddingId, $bounds, $deadline, $counts, $custody, $publishedId);
                $this->verifyAutomaticAssignmentPostimage($initial, $census, $publishedId, $confidence);
                $freshProofs = $this->automaticMatchingProofs($census, $embedding, $deadline, $counts);
                if ($this->automaticAssignmentDigest($freshProofs) !== $this->automaticAssignmentDigest($proofs)) {
                    throw new \RuntimeException('matching_proof_changed');
                }
                if ($this->automaticAssignmentDigest($this->automaticMatchingCensus($faceEmbeddingId, $bounds, $deadline, $counts, $custody, $publishedId)) !== $this->automaticAssignmentDigest($census)) {
                    throw new \RuntimeException('matching_census_changed');
                }

                return $census;
            }, $deadline);
            $postimage = $this->writeAutomaticAssignmentArtifact($request['receipt_id'], 'postimage.json',
                ['schema' => self::AUTOMATIC_ASSIGNMENT_SCHEMA, 'receipt_id' => $request['receipt_id'],
                    'request_digest' => $custody['row']['request_digest'], 'census' => $post], $deadline);
            $payload = $custody['payload'];
            $payload['effect_status'] = 'completed';
            $payload['terminal'] = ['request_digest' => $custody['row']['request_digest'], 'preimage' => $preimage,
                'postimage' => $postimage, 'publication' => $publication];
            $payload['terminal_digest'] = $this->automaticAssignmentDigest($payload['terminal']);
            $custody = $this->updateAutomaticAssignmentReceipt($custody, $payload, 'completed', $deadline);
            $stored = $this->withAutomaticMatchingReadBounds(fn () => DB::connection()->table('review_desk_receipts')
                ->where('id', $custody['row']['id'])->first(), $deadline);
            if (! $stored || $this->canonicalFaceProvenanceJson((array) $stored) !== $this->canonicalFaceProvenanceJson($custody['row'])) {
                throw new \RuntimeException('matching_custody_changed');
            }
            // The SQL guard above restored both connections before the seal admits this terminal row.
            $this->writeAutomaticAssignmentArtifact($request['receipt_id'], 'completion.json',
                ['schema' => self::AUTOMATIC_ASSIGNMENT_SCHEMA, 'receipt' => $custody['row'],
                    'request_digest' => $custody['row']['request_digest'], 'preimage' => $preimage,
                    'postimage' => $postimage, 'terminal_digest' => $payload['terminal_digest']], $deadline);

            return ['action' => $singleton ? 'singleton' : 'assigned', 'cluster_id' => $publishedId,
                'confidence' => $confidence, 'suggestions' => [], 'matching_complete' => true,
                'receipt_id' => $request['receipt_id'], 'custody_status' => 'completed_sealed'];
        } catch (\Throwable $error) {
            if ($publication['effects_started'] || $publication['receipt_claim_attempted'] || str_starts_with($error->getMessage(), 'matching_custody_')) {
                // Transaction acknowledgements and session cleanup are separate
                // boundaries. A thrown exception after effects began is never a
                // proved no-effect hold or permission to replay this source.
                return ['action' => 'unknown', 'reason' => $publication['mysql_commit_acknowledged']
                    ? 'matching_post_publication_cleanup_failed' : 'matching_publication_outcome_unknown',
                    'matching_status' => 'partial_or_unknown', 'matching_complete' => false,
                    'no_effects_proved' => false, 'automatic_replay_allowed' => false,
                    'technical_recovery_required' => true, 'durable_replay_exclusion_established' => $publication['receipt_claim_acknowledged'] || $error->getMessage() === 'matching_custody_outcome_held',
                    'face_embedding_id' => $faceEmbeddingId, 'receipt_id' => $publication['receipt_id'], 'publication' => $publication,
                    'custody_failure_code' => str_starts_with($error->getMessage(), 'matching_custody_') ? $error->getMessage() : null,
                    'failure_code' => preg_match('/\Amatching_[a-z_]+\z/D', $error->getMessage()) ? $error->getMessage() : 'matching_query_failed',
                    'limits' => $bounds, 'observed' => $counts,
                    'elapsed_seconds' => hrtime(true) / 1e9 - $started, 'suggestions' => []];
            }
            $reason = $error->getMessage();
            $unsupported = str_starts_with($reason, 'matching_proof_budget_unsupported:') ? substr($reason, strlen('matching_proof_budget_unsupported:')) : null;
            if ($unsupported !== null) {
                $reason = 'matching_proof_budget_unsupported';
            } elseif (! in_array($reason, ['matching_policy_unavailable', 'matching_candidate_limit_exceeded',
                'matching_member_limit_exceeded', 'matching_proof_input_limit_exceeded', 'matching_protection_limit_exceeded',
                'matching_source_unavailable', 'matching_operator_review_required', 'matching_caller_vector_changed',
                'matching_contract_incompatible', 'matching_census_changed', 'matching_proof_changed',
                'matching_proof_deadline_exceeded', 'matching_membership_changed', 'matching_query_failed',
                'comparison_source_unavailable', 'comparison_source_changed', 'comparison_model_proof_unavailable',
                'comparison_model_contract_incompatible'], true)) {
                $reason = 'matching_query_failed';
            }
            if ($bounds !== [] && hrtime(true) / 1e9 - $started >= $bounds['max_proof_elapsed_seconds']) {
                $reason = 'matching_proof_deadline_exceeded';
            }

            return ['action' => 'skipped', 'reason' => $reason, 'matching_status' => 'held', 'matching_complete' => false,
                'unsupported_proof_origin' => $unsupported, 'limits' => $bounds, 'observed' => $counts,
                'elapsed_seconds' => hrtime(true) / 1e9 - $started, 'suggestions' => []];
        }
    }

    /** Publish only a still-unassigned, unreviewed face to an anonymous cluster. */
    private function publishImportedAnonymousAssignment(int $faceEmbeddingId, int $clusterId, float $confidence): bool
    {
        try {
            $deadline = hrtime(true) / 1e9 + $this->automaticMatchingBounds()['max_proof_elapsed_seconds'];

            return DB::transaction(function () use ($faceEmbeddingId, $clusterId, $confidence, $deadline): bool {
                $pg = DB::connection('pgsql_rag');
                $embedding = $pg->selectOne('SELECT person_cluster_id, file_registry_face_id, file_registry_id FROM face_embeddings WHERE id = ?', [$faceEmbeddingId]);
                if (! $embedding || empty($embedding->file_registry_face_id)) {
                    return false;
                }
                DB::selectOne('SELECT id FROM file_registry_faces WHERE id = ? FOR UPDATE', [(int) $embedding->file_registry_face_id]);

                $registryIds = $this->withAutomaticMatchingReadBounds(function () use ($clusterId, $embedding, $deadline): array {
                    $ids = array_values(array_unique([...$this->automaticGroupingClusterRegistryIds($clusterId, $deadline), (int) $embedding->file_registry_id]));
                    sort($ids);
                    if (count($ids) > $this->automaticMatchingBounds()['max_total_proof_inputs']) {
                        throw new \RuntimeException('matching_protection_limit_exceeded');
                    }
                    foreach ($ids as $id) {
                        if ($id < 1 || ! DB::connection()->table('file_registry')->where('id', $id)->lockForUpdate()->first()) {
                            throw new \RuntimeException('matching_source_unavailable');
                        }
                    }
                    $this->automaticAssignmentCustodyRows($ids, $deadline);

                    return $ids;
                }, $deadline);

                return $pg->transaction(function () use ($pg, $faceEmbeddingId, $clusterId, $confidence, $embedding, $deadline, $registryIds): bool {
                    $pg->selectOne('SELECT id FROM person_clusters WHERE id = ? FOR UPDATE', [$clusterId]);
                    $pg->selectOne('SELECT id FROM face_embeddings WHERE id = ? FOR UPDATE', [$faceEmbeddingId]);
                    $freshSource = $this->withAutomaticMatchingReadBounds(fn () => $pg->selectOne('SELECT person_cluster_id, file_registry_face_id, file_registry_id FROM face_embeddings WHERE id = ?', [$faceEmbeddingId]), $deadline);
                    if (! $freshSource || $this->canonicalFaceProvenanceJson((array) $freshSource) !== $this->canonicalFaceProvenanceJson((array) $embedding)) {
                        return false;
                    }
                    if (! $this->canAutomaticallyGroupFace($faceEmbeddingId) || ! $this->canAutomaticallyGroupCluster($clusterId)) {
                        return false;
                    }
                    $freshIds = $this->withAutomaticMatchingReadBounds(fn () => array_values(array_unique([
                        ...$this->automaticGroupingClusterRegistryIds($clusterId, $deadline), (int) $embedding->file_registry_id])), $deadline);
                    sort($freshIds);
                    if ($freshIds !== $registryIds || ! $this->automaticGroupingCustodyAdmitted($freshIds, $deadline)) {
                        return false;
                    }
                    $updated = $pg->update("UPDATE face_embeddings SET person_cluster_id = ?, match_confidence = ?, updated_at = NOW()
                        WHERE id = ? AND person_cluster_id IS NULL AND EXISTS (SELECT 1 FROM person_clusters pc WHERE pc.id = ?
                        AND pc.status = 'unreviewed' AND TRIM(COALESCE(pc.name, '')) = '' AND pc.genealogy_person_id IS NULL)",
                        [$clusterId, $confidence, $faceEmbeddingId, $clusterId]);
                    if ($updated !== 1) {
                        return false;
                    }
                    $linked = DB::update("UPDATE file_registry_faces SET cluster_id = ? WHERE id = ? AND cluster_id IS NULL
                        AND genealogy_person_id IS NULL AND global_identity_uuid IS NULL AND global_identity_approval_receipt_id IS NULL
                        AND COALESCE(verified, 0) = 0 AND COALESCE(hidden, 0) = 0
                        AND TRIM(COALESCE(person_name, '')) = ''", [$clusterId, (int) $embedding->file_registry_face_id]);
                    if ($linked !== 1) {
                        // Throw through both local transactions; do not report an assignment that did not publish.
                        throw new \RuntimeException('Current face changed during anonymous assignment');
                    }
                    $pg->update('UPDATE person_clusters SET face_count = face_count + 1, updated_at = NOW() WHERE id = ?', [$clusterId]);

                    return true;
                });
            });
        } catch (\Throwable $e) {
            Log::warning('FaceEmbedding: anonymous assignment held', ['exception' => $e::class]);

            return false;
        }
    }

    /** Publish only a still-unassigned, unreviewed face to an anonymous cluster. */
    private function publishAutomaticAssignment(int $faceEmbeddingId, ?int $clusterId, float $confidence,
        array $initial, array $proofs, array $embeddingValues, array $bounds, float $deadline, array &$counts, array &$publication, array $custody): ?int
    {
        return $this->withAutomaticMatchingReadBounds(function () use ($faceEmbeddingId, $clusterId, $confidence, $initial, $proofs, $embeddingValues, $bounds, $deadline, &$counts, &$publication, $custody): ?int {
            $result = DB::transaction(function () use ($faceEmbeddingId, $clusterId, $confidence, $initial, $proofs, $embeddingValues, $bounds, $deadline, &$counts, &$publication, $custody): ?int {
                $pg = DB::connection('pgsql_rag');
                $embedding = $pg->selectOne('SELECT person_cluster_id, file_registry_face_id FROM face_embeddings WHERE id = ?', [$faceEmbeddingId]);
                if (! $embedding || empty($embedding->file_registry_face_id)) {
                    return null;
                }
                DB::selectOne('SELECT id FROM file_registry_faces WHERE id = ? FOR UPDATE', [(int) $embedding->file_registry_face_id]);

                $result = $pg->transaction(function () use ($pg, $faceEmbeddingId, $clusterId, $confidence, $embedding, $initial, $proofs, $embeddingValues, $bounds, $deadline, &$counts, &$publication, $custody): ?int {
                    foreach ($initial['clusters'] as $candidate) {
                        $this->checkMatchingProofDeadline($deadline);
                        $pg->selectOne('SELECT id FROM person_clusters WHERE id = ? FOR UPDATE', [(int) $candidate['id']]);
                    }
                    $ids = [(int) $faceEmbeddingId];
                    foreach ($initial['members'] as $members) {
                        foreach ($members as $member) {
                            $ids[] = (int) $member['id'];
                        }
                    }
                    $ids = array_values(array_unique($ids));
                    sort($ids);
                    $slots = implode(',', array_fill(0, count($ids), '?'));
                    $this->checkMatchingProofDeadline($deadline);
                    $pg->select('SELECT id FROM face_embeddings WHERE id IN ('.$slots.') ORDER BY id FOR UPDATE', $ids);
                    foreach (['faces' => 'file_registry_faces', 'registries' => 'file_registry'] as $relation => $table) {
                        $ids = array_map(fn ($row) => (int) $row['id'], $initial[$relation]);
                        $slots = implode(',', array_fill(0, count($ids), '?'));
                        $this->checkMatchingProofDeadline($deadline);
                        DB::select('SELECT id FROM '.$table.' WHERE id IN ('.$slots.') ORDER BY id FOR UPDATE', $ids);
                    }
                    if ($this->automaticMatchingBounds() !== $bounds) {
                        throw new \RuntimeException('matching_census_changed');
                    }
                    $fresh = $this->automaticMatchingCensus($faceEmbeddingId, $bounds, $deadline, $counts, $custody);
                    if ($this->canonicalFaceProvenanceJson($fresh) !== $this->canonicalFaceProvenanceJson($initial)) {
                        throw new \RuntimeException('matching_census_changed');
                    }
                    $freshProofs = $this->automaticMatchingProofs($fresh, $embeddingValues, $deadline, $counts);
                    if ($this->canonicalFaceProvenanceJson($freshProofs) !== $this->canonicalFaceProvenanceJson($proofs)) {
                        throw new \RuntimeException('matching_proof_changed');
                    }
                    $final = $this->automaticMatchingCensus($faceEmbeddingId, $bounds, $deadline, $counts, $custody);
                    if ($this->canonicalFaceProvenanceJson($final) !== $this->canonicalFaceProvenanceJson($fresh)) {
                        throw new \RuntimeException('matching_census_changed');
                    }
                    $this->checkMatchingProofDeadline($deadline);
                    $this->automaticAssignmentCustodyRows(array_column($initial['registries'], 'id'), $deadline, $custody);
                    $backup = $this->readAutomaticAssignmentArtifact($custody['payload']['preimage'], $deadline);
                    if (($backup['census'] ?? null) != $initial || ($backup['proofs'] ?? null) != $proofs) {
                        throw new \RuntimeException('matching_custody_backup_changed');
                    }
                    $publication['effects_started'] = true;
                    $clusterId ??= $this->createPersonCluster();
                    $publication['cluster_id'] = $clusterId;
                    $updated = $pg->update("UPDATE face_embeddings SET person_cluster_id = ?, match_confidence = ?, updated_at = NOW()
                        WHERE id = ? AND person_cluster_id IS NULL AND EXISTS (SELECT 1 FROM person_clusters pc WHERE pc.id = ?
                        AND pc.status = 'unreviewed' AND TRIM(COALESCE(pc.name, '')) = '' AND pc.genealogy_person_id IS NULL AND pc.merged_into_id IS NULL)",
                        [$clusterId, $confidence, $faceEmbeddingId, $clusterId]);
                    if ($updated !== 1) {
                        throw new \RuntimeException('matching_membership_changed');
                    }
                    $this->checkMatchingProofDeadline($deadline);
                    $linked = DB::update("UPDATE file_registry_faces SET cluster_id = ? WHERE id = ? AND cluster_id IS NULL
                        AND genealogy_person_id IS NULL AND global_identity_uuid IS NULL AND global_identity_approval_receipt_id IS NULL
                        AND COALESCE(verified, 0) = 0 AND COALESCE(hidden, 0) = 0
                        AND TRIM(COALESCE(person_name, '')) = ''", [$clusterId, (int) $embedding->file_registry_face_id]);
                    if ($linked !== 1) {
                        // Throw through both local transactions; do not report an assignment that did not publish.
                        throw new \RuntimeException('Current face changed during anonymous assignment');
                    }
                    $pg->update('UPDATE person_clusters SET face_count = face_count + 1, updated_at = NOW() WHERE id = ?', [$clusterId]);

                    $this->checkMatchingProofDeadline($deadline);

                    $publication['pg_commit_attempted'] = true;

                    return $clusterId;
                });
                $publication['pg_commit_acknowledged'] = true;
                $publication['mysql_commit_attempted'] = true;

                return $result;
            });
            $publication['mysql_commit_acknowledged'] = true;

            return $result;
        }, $deadline, false);
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
     * Confirm the operator-selected cluster; similar names remain review suggestions.
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
        // A shared displayed name is only a review lead, including across trees.
        $sameName = DB::connection('pgsql_rag')->select("SELECT id, face_count, genealogy_person_id FROM person_clusters
            WHERE name = ? AND status = 'confirmed' AND id != ?", [$name, $clusterId]);
        $nameSuggestions = array_map(fn ($candidate) => ['candidate_cluster_id' => $candidate->id,
            'face_count' => $candidate->face_count, 'genealogy_person_id' => $candidate->genealogy_person_id,
            'operator_review_required' => true], $sameName);

        // Normal identify — confirm this cluster
        if (! $this->confirmCluster($clusterId, $name, $genealogyPersonId, $writeToMedia)) {
            return ['action' => 'failed', 'cluster_id' => $clusterId];
        }

        // Propagate: newly confirmed cluster acts as anchor for matching unreviewed clusters
        $propagation = $this->propagateClusterMatches($clusterId);

        return [
            'action' => 'identified',
            'name_match_suggestions' => $nameSuggestions,
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
     * Suggest regrouping and anchor matches, and refresh derived centroids.
     * Existing memberships, empty groups, and decision receipts are preserved.
     *
     * Periodic face-cluster optimization job. Run periodically (every 6h).
     *
     * @param  bool  $dryRun  Report what would happen without making changes
     * @return array Optimization results
     */
    public function optimizeClusters(bool $dryRun = false): array
    {
        $results = ['unreviewed_merges' => 0, 'anchor_merges' => 0, 'anchor_suggestions' => [],
            'unreviewed_suggestions' => [], 'centroids_updated' => 0, 'empty_purged' => 0,
            'skipped_retry_limit' => 0, 'existing_memberships_preserved' => true];
        try {
            $clusters = DB::connection('pgsql_rag')->select("SELECT id, face_count, centroid::text AS centroid_str FROM person_clusters
                WHERE status = 'unreviewed' AND centroid IS NOT NULL AND face_count > 0
                AND (merge_retry IS NULL OR merge_retry < 3) ORDER BY face_count DESC");
            $pairs = [];
            foreach ($clusters as $cluster) {
                if (! $this->clusterHasCurrentSourceEvidence((int) $cluster->id)) {
                    continue;
                }
                if ($cluster->face_count < self::CLUSTER_SOFT_CAP) {
                    $similar = DB::connection('pgsql_rag')->select("SELECT pc.id, pc.face_count FROM person_clusters pc
                        WHERE pc.status = 'unreviewed' AND pc.centroid IS NOT NULL AND pc.id != ?
                        AND pc.face_count > 0 AND (pc.merge_retry IS NULL OR pc.merge_retry < 3)
                        AND 1 - (pc.centroid <=> ?::vector) >= 0.90 AND pc.face_count < ?
                        ORDER BY pc.centroid <=> ?::vector", [$cluster->id, $cluster->centroid_str, self::CLUSTER_SOFT_CAP, $cluster->centroid_str]);
                    foreach ($similar as $candidate) {
                        if (! $this->clusterHasCurrentSourceEvidence((int) $candidate->id)) {
                            continue;
                        }
                        $pair = [min($cluster->id, $candidate->id), max($cluster->id, $candidate->id)];
                        $key = implode(':', $pair);
                        if (! isset($pairs[$key])) {
                            $pairs[$key] = true;
                            $results['unreviewed_suggestions'][] = ['cluster_ids' => $pair, 'operator_review_required' => true];
                        }
                    }
                }
                $anchor = DB::connection('pgsql_rag')->selectOne("SELECT pc.id, pc.name,
                    1 - (pc.centroid <=> ?::vector) AS centroid_similarity FROM person_clusters pc
                    WHERE pc.status = 'confirmed' AND pc.centroid IS NOT NULL AND pc.face_count > 0
                    ORDER BY pc.centroid <=> ?::vector LIMIT 1", [$cluster->centroid_str, $cluster->centroid_str]);
                if ($anchor && (float) $anchor->centroid_similarity >= 0.75 && $this->clusterHasCurrentSourceEvidence((int) $anchor->id)) {
                    $results['anchor_suggestions'][] = ['unreviewed_cluster_id' => $cluster->id,
                        'unreviewed_face_count' => $cluster->face_count, 'suggested_anchor_id' => $anchor->id,
                        'suggested_name' => $anchor->name, 'similarity' => (float) $anchor->centroid_similarity,
                        'operator_review_required' => true];
                }
            }
            $results['skipped_retry_limit'] = (int) DB::connection('pgsql_rag')->selectOne("SELECT COUNT(*) AS cnt FROM person_clusters WHERE status = 'unreviewed' AND merge_retry >= 3")->cnt;
            // Derived centroids may refresh; existing membership and review records stay intact.
            $results['centroids_updated'] = $dryRun
                ? (int) DB::connection('pgsql_rag')->selectOne("SELECT COUNT(*) AS cnt FROM person_clusters
                    WHERE status IN ('confirmed', 'unreviewed') AND face_count > 0
                    AND (centroid IS NULL OR last_optimized_at IS NULL OR last_optimized_at < updated_at)")->cnt
                : $this->updateStaleCentroids(500);
            $results['preserved_empty_clusters'] = (int) DB::connection('pgsql_rag')->selectOne("SELECT COUNT(*) AS cnt FROM person_clusters WHERE face_count = 0 AND status <> 'merged'")->cnt;
        } catch (\Throwable $e) {
            $results['error'] = 'Cluster proposal evidence unavailable';
            Log::warning('FaceEmbedding: optimization proposals unavailable', ['exception' => $e::class]);
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
                    AND source_current = 1 AND superseded_at IS NULL
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
                    AND source_current = 1 AND superseded_at IS NULL
                ', [$cluster->name]);
                $mysqlIds = array_map(fn ($f) => $f->id, $namedFaceIds);
                $ph = implode(',', $mysqlIds);

                $authEmbeddings = DB::connection('pgsql_rag')->select("
                    SELECT embedding::text as emb_str FROM face_embeddings
                    WHERE file_registry_face_id IN ({$ph})
                    AND source_current = TRUE AND superseded_at IS NULL
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
                    WHERE person_cluster_id = ? AND source_current = TRUE AND superseded_at IS NULL
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
                WHERE person_cluster_id = ? AND source_current = TRUE AND superseded_at IS NULL
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
                         WHERE fe2.person_cluster_id = ? AND fe2.source_current = TRUE AND fe2.superseded_at IS NULL
                         ORDER BY fe2.embedding <=> ?::vector LIMIT 1) as best_match_crop
                    FROM face_embeddings fe
                    WHERE fe.person_cluster_id = ? AND fe.source_current = TRUE AND fe.superseded_at IS NULL
                    HAVING MAX(1 - (fe.embedding <=> ?::vector)) >= ?
                ', [$embeddingStr, $c->cluster_id, $embeddingStr, $c->cluster_id, $embeddingStr, 1 - $tolerance]);

                if ($best && $best->confidence !== null && $this->clusterHasCurrentSourceEvidence((int) $c->cluster_id)) {
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
