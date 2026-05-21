<?php

namespace App\Services\Genealogy;

use App\Services\ComputeRouterService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * N102 — Local HTR (Handwritten Text Recognition) Pipeline
 * N106 — Refactored to use ComputeRouterService for dynamic compute routing
 *
 * Wraps the Python TrOCR pipeline (scripts/htr_transcribe.py) to transcribe
 * handwritten genealogy documents (letters, church registers, census returns,
 * wills, diaries) that resist standard OCR.
 *
 * Model: microsoft/trocr-base-handwritten (~340 MB, fits GTX 1060 6GB VRAM)
 * CPU fallback: same model on CPU when CUDA is unavailable or incompatible.
 *
 * GPU lock: ComputeRouterService handles per-instance GPU locks and
 * transition compatibility with legacy whisper_gpu_lock/ollama_busy_lock on
 * the primary local GPU role.
 *
 * To install:
 *   pip install torch torchvision transformers Pillow tiktoken sentencepiece
 *   (model downloads automatically on first use to ~/.cache/huggingface)
 */
class HtrTranscriptionService
{
    private const TIMEOUT_SEC = 120;

    private const CACHE_TTL = 86400; // 24h — same image = same transcript

    private const MIN_FREE_VRAM_MB = 2048;

    private const OOM_COOLDOWN_SEC = 600;

    private const MIN_GENEALOGY_PERSIST_CONFIDENCE = 0.70;

    private ?ComputeRouterService $computeRouter = null;

    private ?bool $available = null;

    private function getComputeRouter(): ComputeRouterService
    {
        if ($this->computeRouter === null) {
            $this->computeRouter = app(ComputeRouterService::class);
        }

        return $this->computeRouter;
    }

    /**
     * Transcribe a handwritten image by local file path.
     *
     * @param  string  $imagePath  Absolute path to the image file
     * @param  array  $options  ['force' => bool]  bypass path-scope policy
     * @return array|null ['text'=>string, 'confidence'=>float, 'model'=>string, 'lines'=>[...]]
     *                    or null if TrOCR not available, transcription failed,
     *                    or path is not covered by the genealogy.htr_enabled_paths policy.
     */
    public function transcribe(string $imagePath, array $options = []): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        if (! file_exists($imagePath)) {
            Log::warning('HtrTranscriptionService: image not found', ['path' => $imagePath]);

            return null;
        }

        // Path-scope policy: reserve GPU for genealogy trees. Callers outside
        // the enabled prefixes (e.g., future file_enrich_* integrations) skip
        // HTR unless they pass ['force' => true] (used by the HTR fallback
        // inside ContentExtractionService, the HTR audit command, and tests).
        // Empty config preserves current behavior: HTR runs everywhere.
        if (! ($options['force'] ?? false) && ! $this->pathIsHtrEnabled($imagePath)) {
            Log::info('HtrTranscriptionService: htr_policy_skip', [
                'path' => $imagePath,
                'reason' => 'path not in config(genealogy.htr_enabled_paths)',
            ]);

            return null;
        }

        $cacheKey = 'htr_transcription:'.md5($imagePath.filemtime($imagePath));
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            if (! $this->isUsableTranscriptResult($cached)) {
                Cache::forget($cacheKey);

                return null;
            }

            return $cached;
        }

        try {
            $instance = $this->getComputeRouter()->route('htr');
            if (! $instance) {
                return null;
            }

            if ($this->isInstanceCoolingDown($instance->instance_id)) {
                Log::info('HtrTranscriptionService: skipping during OOM cooldown', [
                    'path' => $imagePath,
                    'instance' => $instance->instance_id,
                ]);

                return null;
            }

            $health = $this->getComputeRouter()->healthCheckInstance($instance);
            if (($instance->gpu_vram_mb ?? 0) > 0 && ($health['memory_free_mb'] ?? 0) < self::MIN_FREE_VRAM_MB) {
                $this->markInstanceCoolingDown($instance->instance_id);
                Log::warning('HtrTranscriptionService: insufficient free VRAM, deferring transcription', [
                    'path' => $imagePath,
                    'instance' => $instance->instance_id,
                    'memory_free_mb' => $health['memory_free_mb'] ?? null,
                    'threshold_mb' => self::MIN_FREE_VRAM_MB,
                ]);

                return null;
            }

            $input = json_encode(['image_path' => $imagePath]);
            $result = $this->getComputeRouter()->executeScript(
                'htr',
                'htr_transcribe.py',
                $input,
                [],
                self::TIMEOUT_SEC
            );

            if (! $result['success'] || empty($result['output'])) {
                Log::warning('HtrTranscriptionService: compute execution failed', [
                    'path' => $imagePath,
                    'instance' => $result['instance_id'] ?? null,
                    'error' => $result['error'] ?? 'empty output',
                ]);

                return null;
            }

            $parsed = json_decode($result['output'], true);
            if (! is_array($parsed) || isset($parsed['error'])) {
                if ($this->isOomError($parsed['error'] ?? '')) {
                    $this->markInstanceCoolingDown($result['instance_id'] ?? $instance->instance_id);
                }
                Log::warning('HtrTranscriptionService: script error', [
                    'path' => $imagePath,
                    'error' => $parsed['error'] ?? 'unknown',
                    'instance' => $result['instance_id'],
                ]);

                return null;
            }

            if (! $this->isUsableTranscriptResult($parsed)) {
                Log::warning('HtrTranscriptionService: unusable transcript result', [
                    'path' => $imagePath,
                    'instance' => $result['instance_id'],
                    'confidence' => $parsed['confidence'] ?? null,
                    'line_count' => $parsed['line_count'] ?? null,
                ]);

                return null;
            }

            Cache::put($cacheKey, $parsed, self::CACHE_TTL);

            return $parsed;

        } catch (\Exception $e) {
            Log::error('HtrTranscriptionService: exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Transcribe a file from the file_registry by UUID.
     * Resolves the local path via filesystem-first pattern (N71).
     *
     * @param  string  $uuid  file_registry UUID
     * @return array|null Transcription result or null
     */
    public function transcribeByUuid(string $uuid): ?array
    {
        $file = DB::selectOne(
            'SELECT id, current_path AS file_path, mime_type FROM file_registry WHERE asset_uuid = ?',
            [$uuid]
        );

        if (! $file) {
            Log::warning('HtrTranscriptionService: UUID not found', ['uuid' => $uuid]);

            return null;
        }

        if (! $this->isSupportedMime($file->mime_type)) {
            Log::info('HtrTranscriptionService: unsupported MIME', [
                'uuid' => $uuid,
                'mime' => $file->mime_type,
            ]);

            return null;
        }

        // Filesystem-first (N71): try local path before WebDAV
        $localPath = $this->resolveLocalPath($file->file_path);
        if (! $localPath) {
            Log::warning('HtrTranscriptionService: cannot resolve local path', ['uuid' => $uuid]);

            return null;
        }

        $result = $this->transcribe($localPath);

        if ($result) {
            Log::info('HtrTranscriptionService: transcribed', [
                'uuid' => $uuid,
                'confidence' => $result['confidence'],
                'lines' => $result['line_count'] ?? count($result['lines'] ?? []),
                'device' => $result['device'],
            ]);
        }

        return $result;
    }

    /**
     * Transcribe a genealogy media record by its media ID.
     * Stores the transcription back into genealogy_media.transcription_text.
     *
     * @param  int  $mediaId  genealogy_media.id
     */
    public function transcribeGenealogyMedia(int $mediaId): ?array
    {
        $media = DB::selectOne(
            'SELECT gm.id, gm.nextcloud_path, gm.local_filename, gm.media_type AS mime_type, gm.description AS title
             FROM genealogy_media gm
             WHERE gm.id = ?',
            [$mediaId]
        );

        if (! $media) {
            return null;
        }

        // Try nextcloud_path-based transcription
        $result = null;
        if ($media->nextcloud_path) {
            $local = $this->resolveLocalPath($media->nextcloud_path);
            if ($local) {
                $result = $this->transcribe($local);
            }
        }

        if ($result && ! empty($result['text'])) {
            if (! $this->isPersistableGenealogyTranscript($result)) {
                $confidence = (float) ($result['confidence'] ?? 0);
                $preview = mb_substr(trim((string) $result['text']), 0, 160);

                DB::update(
                    "UPDATE genealogy_media
                     SET analysis_status = CASE WHEN analysis_status = 'completed' THEN analysis_status ELSE 'skipped' END,
                         analysis_error = ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    ['htr_low_confidence:'.round($confidence, 4), $mediaId]
                );

                Log::warning('HtrTranscriptionService: low-confidence genealogy transcript not persisted', [
                    'media_id' => $mediaId,
                    'confidence' => $confidence,
                    'threshold' => self::MIN_GENEALOGY_PERSIST_CONFIDENCE,
                    'preview' => $preview,
                ]);

                $result['skipped_reason'] = 'low_confidence';
                $result['confidence_threshold'] = self::MIN_GENEALOGY_PERSIST_CONFIDENCE;
                $result['persisted'] = false;

                return $result;
            }

            // Persist transcription back to genealogy_media
            DB::update(
                'UPDATE genealogy_media SET transcription_text = ?, analysis_error = NULL, updated_at = NOW() WHERE id = ?',
                [substr($result['text'], 0, 65535), $mediaId]
            );
        }

        return $result;
    }

    /**
     * Check if any compute instance with HTR capability is available.
     * Caches result for the process lifetime.
     */
    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $this->available = $this->getComputeRouter()->route('htr') !== null;

        return $this->available;
    }

    /**
     * Return installation status and GPU availability via ComputeRouter.
     */
    public function getStatus(): array
    {
        $router = $this->getComputeRouter();
        $instance = $router->route('htr');

        return [
            'installed' => $instance !== null,
            'routed_to' => $instance ? $instance->instance_id : null,
            'gpu_model' => $instance->gpu_model ?? null,
            'gpu_vram_mb' => $instance->gpu_vram_mb ?? null,
            'host' => $instance->host ?? null,
            'install_cmd' => 'pip install torch torchvision transformers Pillow tiktoken sentencepiece',
            'model' => 'microsoft/trocr-base-handwritten (~340MB; GPU preferred, CPU fallback)',
        ];
    }

    private function isSupportedMime(?string $mime): bool
    {
        if (! $mime) {
            return false;
        }

        return in_array($mime, [
            'image/jpeg', 'image/jpg', 'image/png', 'image/tiff',
            'image/bmp', 'image/webp', 'image/gif',
            'image/jp2', 'image/jpx', 'image/j2k', 'image/x-jp2',
        ], true);
    }

    /**
     * Apply the path-scope policy from config('genealogy.htr_enabled_paths').
     *
     * Returns true when the path is under ANY enabled prefix, or when the
     * policy is disabled (empty config — fail-open to preserve behavior
     * for any installation that hasn't opted in). The prefix check matches
     * the resolved absolute path on disk, since callers pass local paths
     * after NextcloudFileApiService or similar resolution.
     */
    private function pathIsHtrEnabled(string $imagePath): bool
    {
        $prefixes = array_values(array_filter(array_map(
            static fn ($p) => is_string($p) ? rtrim((string) $p, '/') : null,
            (array) config('genealogy.htr_enabled_paths', [])
        )));

        // Fail-open: empty config means HTR runs everywhere, preserving
        // behavior for any caller that hasn't configured the policy.
        if (empty($prefixes)) {
            return true;
        }

        $nextcloudDataPath = rtrim((string) config('services.nextcloud.data_path', ''), '/');

        foreach ($prefixes as $prefix) {
            if ($prefix === '') {
                continue;
            }
            // Direct prefix match (absolute path). Also match paths the caller
            // provides already resolved through the Nextcloud data path, by
            // trimming the data-path prefix before comparing.
            if (str_starts_with($imagePath, $prefix.'/') || $imagePath === $prefix) {
                return true;
            }
            if ($nextcloudDataPath !== '' && str_starts_with($imagePath, $nextcloudDataPath.$prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function resolveLocalPath(string $filePath): ?string
    {
        // Filesystem-first (N71): check configured Nextcloud data path
        $nextcloudDataPath = config('services.nextcloud.data_path');
        if ($nextcloudDataPath) {
            $localPath = rtrim($nextcloudDataPath, '/').'/'.ltrim($filePath, '/');
            if (file_exists($localPath)) {
                return $localPath;
            }
        }

        // Try absolute path directly
        if (str_starts_with($filePath, '/') && file_exists($filePath)) {
            return $filePath;
        }

        return null;
    }

    private function isInstanceCoolingDown(string $instanceId): bool
    {
        return Cache::has($this->getCooldownCacheKey($instanceId));
    }

    private function markInstanceCoolingDown(string $instanceId): void
    {
        Cache::put($this->getCooldownCacheKey($instanceId), true, self::OOM_COOLDOWN_SEC);
    }

    private function getCooldownCacheKey(string $instanceId): string
    {
        return "htr:oom_cooldown:{$instanceId}";
    }

    private function isOomError(string $error): bool
    {
        $error = strtolower($error);

        return str_contains($error, 'out of memory')
            || str_contains($error, 'cuda out of memory')
            || str_contains($error, 'no kernel image is available')
            || str_contains($error, 'cudaerrornokernelimagefordevice')
            || str_contains($error, 'model_load_failed');
    }

    private function isUsableTranscriptResult(mixed $result): bool
    {
        if (! is_array($result) || isset($result['error'])) {
            return false;
        }

        $text = trim((string) ($result['text'] ?? ''));
        if ($text !== '' && str_contains(strtolower($text), '[transcription error:')) {
            return false;
        }

        foreach ((array) ($result['lines'] ?? []) as $line) {
            $lineText = is_array($line) ? (string) ($line['text'] ?? '') : (string) $line;
            if (str_contains(strtolower($lineText), '[transcription error:')) {
                return false;
            }
        }

        return true;
    }

    private function isPersistableGenealogyTranscript(array $result): bool
    {
        $text = trim((string) ($result['text'] ?? ''));
        if ($text === '') {
            return false;
        }

        return (float) ($result['confidence'] ?? 0) >= self::MIN_GENEALOGY_PERSIST_CONFIDENCE;
    }

    /**
     * Transcribe with automatic fallback to Transkribus cloud API.
     *
     * Flow: Local TrOCR → (if confidence < threshold) → Transkribus metagrapho API
     *
     * @param  string  $imagePath  Absolute path to image
     * @param  array  $options  [confidence_threshold: float, try_transkribus: bool]
     * @return array|null Result with 'source' key indicating which method was used
     */
    public function transcribeWithFallback(string $imagePath, array $options = []): ?array
    {
        $threshold = $options['confidence_threshold'] ?? 0.70;
        $tryTranskribus = $options['try_transkribus'] ?? true;

        // Try local TrOCR first
        $localResult = $this->transcribe($imagePath);

        if ($localResult && ($localResult['confidence'] ?? 0) >= $threshold) {
            $localResult['source'] = 'trocr_local';

            return $localResult;
        }

        // Fallback to Transkribus if enabled and configured
        if ($tryTranskribus) {
            $cloudResult = $this->transcribeViaTranskribus($imagePath);
            if ($cloudResult) {
                $cloudResult['source'] = 'transkribus';
                $cloudResult['fallback_reason'] = $localResult
                    ? 'low_local_confidence_'.round(($localResult['confidence'] ?? 0) * 100)
                    : 'local_unavailable';

                Log::info('HtrTranscription: Used Transkribus fallback', [
                    'path' => basename($imagePath),
                    'reason' => $cloudResult['fallback_reason'],
                    'transkribus_confidence' => $cloudResult['confidence'] ?? null,
                ]);

                return $cloudResult;
            }
        }

        // Return low-confidence local result if available
        if ($localResult) {
            $localResult['source'] = 'trocr_local';

            return $localResult;
        }

        return null;
    }

    /**
     * Transcribe via Transkribus Metagrapho REST API.
     *
     * Uploads image, starts recognition job, polls for completion.
     * Requires TRANSKRIBUS_API_KEY in .env.
     *
     * @param  string  $imagePath  Local image file path
     * @return array|null ['text', 'confidence', 'lines', 'model', 'source']
     */
    public function transcribeViaTranskribus(string $imagePath): ?array
    {
        $apiKey = config('services.transkribus.api_key');

        if (! $apiKey) {
            Log::debug('HtrTranscription: Transkribus not configured (no API key)');

            return null;
        }

        if (! file_exists($imagePath)) {
            return null;
        }

        $baseUrl = 'https://app.transkribus.org/metagrapho/api';

        try {
            // Step 1: Upload image and start recognition
            $response = \Illuminate\Support\Facades\Http::connectTimeout(5)->timeout(60)
                ->withHeaders(['Authorization' => "Bearer {$apiKey}"])
                ->attach('file', file_get_contents($imagePath), basename($imagePath))
                ->post("{$baseUrl}/processes", [
                    'config' => json_encode([
                        'textRecognition' => [
                            'htrId' => 'default',
                        ],
                    ]),
                ]);

            if (! $response->successful()) {
                Log::warning('HtrTranscription: Transkribus upload failed', [
                    'status' => $response->status(),
                    'error' => substr($response->body(), 0, 200),
                ]);

                return null;
            }

            $processId = $response->json('processId');
            if (! $processId) {
                return null;
            }

            // Step 2: Poll for completion (max 90 seconds)
            $maxAttempts = 30;
            $result = null;

            for ($i = 0; $i < $maxAttempts; $i++) {
                sleep(3);

                $statusResponse = \Illuminate\Support\Facades\Http::connectTimeout(5)->timeout(15)
                    ->withHeaders(['Authorization' => "Bearer {$apiKey}"])
                    ->get("{$baseUrl}/processes/{$processId}");

                if (! $statusResponse->successful()) {
                    continue;
                }

                $status = $statusResponse->json('status');

                if ($status === 'FINISHED') {
                    $result = $statusResponse->json();
                    break;
                }

                if (in_array($status, ['FAILED', 'CANCELLED'])) {
                    Log::warning('HtrTranscription: Transkribus job failed', [
                        'process_id' => $processId,
                        'status' => $status,
                    ]);

                    return null;
                }
            }

            if (! $result) {
                Log::warning('HtrTranscription: Transkribus job timed out', ['process_id' => $processId]);

                return null;
            }

            // Step 3: Extract text from result
            $pages = $result['content']['pages'] ?? [];
            $fullText = '';
            $lines = [];
            $totalConfidence = 0;
            $lineCount = 0;

            foreach ($pages as $page) {
                foreach ($page['regions'] ?? [] as $region) {
                    foreach ($region['lines'] ?? [] as $line) {
                        $lineText = $line['text'] ?? '';
                        $lineConf = $line['confidence'] ?? 0.5;
                        $fullText .= $lineText."\n";
                        $lines[] = ['text' => $lineText, 'confidence' => $lineConf];
                        $totalConfidence += $lineConf;
                        $lineCount++;
                    }
                }
            }

            return [
                'text' => trim($fullText),
                'confidence' => $lineCount > 0 ? round($totalConfidence / $lineCount, 4) : 0,
                'lines' => $lines,
                'model' => 'transkribus_metagrapho',
                'process_id' => $processId,
            ];

        } catch (\Exception $e) {
            Log::error('HtrTranscription: Transkribus error', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
