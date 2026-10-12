<?php

namespace App\Services\Genealogy;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\ComputeRouterService;
use App\Services\OfflinePolicyService;
use App\Support\RuntimeBoolean;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
 *   Provision the approved model cache separately; runtime never downloads models.
 */
class HtrTranscriptionService
{
    private const TIMEOUT_SEC = 120;

    private const CACHE_TTL = 86400; // 24h — same image = same transcript

    private const MIN_FREE_VRAM_MB = 2048;

    public const OOM_COOLDOWN_SEC = 600;

    private const MIN_GENEALOGY_PERSIST_CONFIDENCE = 0.70;

    private const GENEALOGY_ATTEMPT_TABLE = 'genealogy_media_htr_attempts';

    private ?ComputeRouterService $computeRouter = null;

    private ?bool $available = null;

    private ?string $lastPreDispatchDeferralCode = null;

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
        $this->lastPreDispatchDeferralCode = null;

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

        $sourceHash = is_file($imagePath) && is_readable($imagePath) ? hash_file('sha256', $imagePath) : false;
        if (! is_string($sourceHash)) {
            return null;
        }
        $cacheKey = 'htr_transcription:v2:'.hash('sha256', $imagePath."\0".$sourceHash);
        $bypassCache = (bool) ($options['bypass_cache'] ?? false);
        $cached = $bypassCache ? null : Cache::get($cacheKey);
        if ($cached !== null) {
            if (! self::isCompleteTranscriptResult($cached)) {
                Cache::forget($cacheKey);

                return null;
            }

            return $this->currentSourceMatches($imagePath, $sourceHash) ? $cached : null;
        }

        try {
            $instance = $this->getComputeRouter()->route('htr');
            if (! $instance) {
                return $this->recordPreDispatchDeferral($options, 'route_unavailable');
            }

            if ($this->isInstanceCoolingDown($instance->instance_id)) {
                Log::info('HtrTranscriptionService: skipping during OOM cooldown', [
                    'path' => $imagePath,
                    'instance' => $instance->instance_id,
                ]);

                return $this->recordPreDispatchDeferral($options, 'instance_cooling_down');
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

                return $this->recordPreDispatchDeferral($options, 'insufficient_vram');
            }

            $input = json_encode(['image_path' => $imagePath]);
            $result = ComputeRouterService::withRequiredInstance(
                $instance->instance_id,
                fn () => $this->getComputeRouter()->executeScript('htr', 'htr_transcribe.py', $input, [], self::TIMEOUT_SEC)
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

            if (! self::isCompleteTranscriptResult($parsed) || ! $this->currentSourceMatches($imagePath, $sourceHash)) {
                Log::warning('HtrTranscriptionService: unusable transcript result', [
                    'path' => $imagePath,
                    'instance' => $result['instance_id'],
                    'confidence' => $parsed['confidence'] ?? null,
                    'line_count' => $parsed['line_count'] ?? null,
                ]);

                return null;
            }

            if (! $bypassCache) {
                Cache::put($cacheKey, $parsed, self::CACHE_TTL);
            }

            return $parsed;

        } catch (\Exception $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }
            Log::error('HtrTranscriptionService: exception', ['exception_kind' => $e::class]);

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
            'SELECT gm.id, gm.tree_id, gm.file_exists, gm.nextcloud_path, gm.local_filename,
                    gm.media_type AS mime_type, gm.description AS title, gm.transcription_text,
                    gm.transcription, gm.analysis_status
             FROM genealogy_media gm
             WHERE gm.id = ?',
            [$mediaId]
        );

        if (! $media || ! $this->genealogyMediaEligible($media)) {
            return null;
        }

        // Known unavailability and path-policy skips happen before durable intent.
        if (! $this->isAvailable()) {
            return null;
        }

        $local = $this->resolveLocalPath((string) $media->nextcloud_path);
        if (! $local || ! is_file($local) || ! is_readable($local) || ! $this->pathIsHtrEnabled($local)) {
            return null;
        }

        $sourceHash = hash_file('sha256', $local);
        if (! is_string($sourceHash)) {
            return null;
        }

        $sourceRevision = hash('sha256', implode("\0", [
            (string) $media->id,
            (string) $media->tree_id,
            (string) $media->nextcloud_path,
            $sourceHash,
        ]));
        $attemptToken = (string) Str::uuid();
        $claim = $this->claimGenealogyHtrAttempt($media, $sourceHash, $sourceRevision, $attemptToken);
        if ($claim !== 'claimed') {
            $result = ['persisted' => false, 'skipped_reason' => $claim];
            if (in_array($claim, ['processing', 'unknown', 'deferred', 'held_low_confidence', 'completed', 'stale_source'], true)) {
                $result['attempt_state'] = $claim;
            }

            return $result;
        }

        $preDispatchHash = is_file($local) ? hash_file('sha256', $local) : false;
        if (! is_string($preDispatchHash) || ! hash_equals($sourceHash, $preDispatchHash)) {
            $this->finishGenealogyHtrAttempt($mediaId, $attemptToken, 'stale_source', 'source_changed_before_dispatch');

            return ['persisted' => false, 'skipped_reason' => 'source_changed'];
        }

        // The provider may have received work after this intent. Never retry a
        // null, timeout, exception, or incomplete result automatically.
        try {
            $result = $this->transcribe($local, [
                'bypass_cache' => true,
                'report_pre_dispatch_deferral' => true,
            ]);
        } catch (\Throwable $exception) {
            $this->finishGenealogyHtrAttempt($mediaId, $attemptToken, 'unknown', 'post_dispatch_exception');
            Log::warning('HtrTranscriptionService: genealogy HTR attempt outcome unknown', ['media_id' => $mediaId]);

            return ['persisted' => false, 'attempt_state' => 'unknown', 'skipped_reason' => 'attempt_unknown'];
        }

        if (in_array($this->lastPreDispatchDeferralCode, ['route_unavailable', 'instance_cooling_down', 'insufficient_vram'], true)) {
            $this->finishGenealogyHtrAttempt($mediaId, $attemptToken, 'deferred', $this->lastPreDispatchDeferralCode);

            return ['persisted' => false, 'attempt_state' => 'deferred', 'skipped_reason' => $this->lastPreDispatchDeferralCode];
        }

        if (! self::isCompleteTranscriptResult($result)) {
            $this->finishGenealogyHtrAttempt($mediaId, $attemptToken, 'unknown', 'non_complete_result');

            return ['persisted' => false, 'attempt_state' => 'unknown', 'skipped_reason' => 'attempt_unknown'];
        }

        $confidence = (float) ($result['confidence'] ?? 0);
        if (! $this->isPersistableGenealogyTranscript($result)) {
            $sourceAfter = hash_file('sha256', $local);
            $state = $sourceAfter === $sourceHash ? 'held_low_confidence' : 'stale_source';
            $this->finishGenealogyHtrAttempt(
                $mediaId,
                $attemptToken,
                $state,
                $state === 'held_low_confidence' ? 'low_confidence' : 'source_changed'
            );
            Log::warning('HtrTranscriptionService: low-confidence genealogy transcript held', [
                'media_id' => $mediaId,
                'confidence' => $confidence,
                'threshold' => self::MIN_GENEALOGY_PERSIST_CONFIDENCE,
            ]);

            $result['skipped_reason'] = $state === 'held_low_confidence' ? 'low_confidence' : 'source_changed';
            $result['attempt_state'] = $state;
            $result['confidence_threshold'] = self::MIN_GENEALOGY_PERSIST_CONFIDENCE;
            $result['persisted'] = false;

            return $result;
        }

        $sourceAfter = hash_file('sha256', $local);
        if (! is_string($sourceAfter) || ! hash_equals($sourceHash, $sourceAfter)) {
            $this->finishGenealogyHtrAttempt($mediaId, $attemptToken, 'stale_source', 'source_changed');
            $result['persisted'] = false;
            $result['skipped_reason'] = 'source_changed';

            return $result;
        }

        return $this->publishGenealogyHtrResult($media, $sourceHash, $sourceRevision, $attemptToken, $result);
    }

    private function genealogyMediaEligible(object $media): bool
    {
        $status = (string) ($media->analysis_status ?? '');

        return (int) ($media->file_exists ?? 0) === 1
            && trim((string) ($media->nextcloud_path ?? '')) !== ''
            && trim((string) ($media->transcription_text ?? '')) === ''
            && trim((string) ($media->transcription ?? '')) === ''
            && $status !== 'skipped'
            && $status !== 'processing'
            && ! str_starts_with($status, 'processing:');
    }

    private function claimGenealogyHtrAttempt(object $snapshot, string $sourceHash, string $sourceRevision, string $attemptToken): string
    {
        return DB::transaction(function () use ($snapshot, $sourceHash, $sourceRevision, $attemptToken): string {
            $current = DB::table('genealogy_media')->where('id', $snapshot->id)->lockForUpdate()->first();
            if (! $current || ! $this->genealogyMediaEligible($current)
                || (int) $current->tree_id !== (int) $snapshot->tree_id
                || (string) $current->nextcloud_path !== (string) $snapshot->nextcloud_path) {
                return 'media_changed_or_ineligible';
            }

            // Unknown/processing attempts hold the media across source revisions.
            $unresolved = DB::table(self::GENEALOGY_ATTEMPT_TABLE)->where('media_id', $snapshot->id)
                ->whereIn('state', ['processing', 'unknown'])->first();
            if ($unresolved) {
                return (string) $unresolved->state;
            }

            $existing = DB::table(self::GENEALOGY_ATTEMPT_TABLE)->where('media_id', $snapshot->id)
                ->where('source_revision_sha256', $sourceRevision)->first();
            if ($existing) {
                if ($existing->state === 'deferred') {
                    if (Carbon::parse($existing->updated_at)->addSeconds(self::OOM_COOLDOWN_SEC)->isFuture()) {
                        return 'deferred';
                    }

                    $reclaimed = DB::table(self::GENEALOGY_ATTEMPT_TABLE)
                        ->where('id', $existing->id)
                        ->where('attempt_token', $existing->attempt_token)
                        ->where('state', 'deferred')
                        ->update([
                            'attempt_token' => $attemptToken,
                            'state' => 'processing',
                            'outcome_code' => null,
                            'updated_at' => now(),
                        ]);

                    return $reclaimed === 1 ? 'claimed' : 'deferred';
                }

                return (string) $existing->state;
            }

            DB::table(self::GENEALOGY_ATTEMPT_TABLE)->insert([
                'media_id' => $snapshot->id,
                'tree_id' => $snapshot->tree_id,
                'source_sha256' => $sourceHash,
                'source_revision_sha256' => $sourceRevision,
                'attempt_token' => $attemptToken,
                'state' => 'processing',
                'outcome_code' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return 'claimed';
        });
    }

    private function finishGenealogyHtrAttempt(int $mediaId, string $attemptToken, string $state, string $outcomeCode): void
    {
        DB::table(self::GENEALOGY_ATTEMPT_TABLE)
            ->where('media_id', $mediaId)
            ->where('attempt_token', $attemptToken)
            ->where('state', 'processing')
            ->update(['state' => $state, 'outcome_code' => $outcomeCode, 'updated_at' => now()]);
    }

    private function recordPreDispatchDeferral(array $options, string $code): ?array
    {
        if (($options['report_pre_dispatch_deferral'] ?? false) === true) {
            $this->lastPreDispatchDeferralCode = $code;
        }

        return null;
    }

    private function publishGenealogyHtrResult(object $snapshot, string $sourceHash, string $sourceRevision, string $attemptToken, array $result): array
    {
        $published = DB::transaction(function () use ($snapshot, $sourceHash, $sourceRevision, $attemptToken, $result): bool {
            $attempt = DB::table(self::GENEALOGY_ATTEMPT_TABLE)
                ->where('media_id', $snapshot->id)
                ->where('attempt_token', $attemptToken)
                ->where('source_revision_sha256', $sourceRevision)
                ->where('state', 'processing')
                ->lockForUpdate()
                ->first();
            if (! $attempt) {
                return false;
            }

            $current = DB::table('genealogy_media')->where('id', $snapshot->id)->lockForUpdate()->first();
            $sourcePath = $this->resolveLocalPath((string) $snapshot->nextcloud_path);
            $currentHash = $sourcePath && is_file($sourcePath) ? hash_file('sha256', $sourcePath) : false;
            $status = (string) ($current->analysis_status ?? '');
            $eligible = $current
                && (int) $current->tree_id === (int) $snapshot->tree_id
                && (int) $current->file_exists === 1
                && (string) $current->nextcloud_path === (string) $snapshot->nextcloud_path
                && trim((string) ($current->transcription_text ?? '')) === ''
                && trim((string) ($current->transcription ?? '')) === ''
                && $status !== 'skipped'
                && $status !== 'processing'
                && ! str_starts_with($status, 'processing:')
                && is_string($currentHash)
                && hash_equals($sourceHash, $currentHash);

            if (! $eligible) {
                DB::table(self::GENEALOGY_ATTEMPT_TABLE)->where('id', $attempt->id)->where('attempt_token', $attemptToken)
                    ->where('state', 'processing')->update([
                        'state' => 'stale_source', 'outcome_code' => 'publication_guard_lost', 'updated_at' => now(),
                    ]);

                return false;
            }

            $updated = DB::table('genealogy_media')->where('id', $snapshot->id)
                ->where('tree_id', $snapshot->tree_id)->where('file_exists', 1)
                ->where('nextcloud_path', $snapshot->nextcloud_path)
                ->where(function ($query): void {
                    $query->whereNull('transcription_text')->orWhereRaw("TRIM(transcription_text) = ''");
                })
                ->where(function ($query): void {
                    $query->whereNull('transcription')->orWhereRaw("TRIM(transcription) = ''");
                })
                ->where(function ($query): void {
                    $query->whereNull('analysis_status')->orWhere(function ($nested): void {
                        $nested->where('analysis_status', '<>', 'skipped')
                            ->where('analysis_status', '<>', 'processing')
                            ->where('analysis_status', 'not like', 'processing:%');
                    });
                })
                ->update(['transcription_text' => $result['text'], 'updated_at' => now()]);

            if ($updated !== 1) {
                DB::table(self::GENEALOGY_ATTEMPT_TABLE)->where('id', $attempt->id)->where('state', 'processing')
                    ->update(['state' => 'stale_source', 'outcome_code' => 'publication_cas_lost', 'updated_at' => now()]);

                return false;
            }

            $completed = DB::table(self::GENEALOGY_ATTEMPT_TABLE)->where('id', $attempt->id)
                ->where('attempt_token', $attemptToken)->where('state', 'processing')
                ->update(['state' => 'completed', 'outcome_code' => 'published', 'updated_at' => now()]);
            if ($completed !== 1) {
                throw new \RuntimeException('HTR attempt publication state changed inside locked transaction.');
            }

            return true;
        });

        $result['persisted'] = $published;
        if (! $published) {
            $result['skipped_reason'] = 'media_changed_or_transcript_present';
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

    private static function isUsableTranscriptResult(mixed $result): bool
    {
        if (! is_array($result) || isset($result['error'])) {
            return false;
        }

        $text = trim((string) ($result['text'] ?? ''));
        if ($text === '' || str_contains(strtolower($text), '[transcription error:')) {
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

    private function currentSourceMatches(string $path, string $expectedHash): bool
    {
        $current = is_file($path) && is_readable($path) ? hash_file('sha256', $path) : false;

        return is_string($current) && hash_equals($expectedHash, $current);
    }

    private function isPersistableGenealogyTranscript(array $result): bool
    {
        $text = trim((string) ($result['text'] ?? ''));
        if ($text === '') {
            return false;
        }

        return (float) ($result['confidence'] ?? 0) >= self::MIN_GENEALOGY_PERSIST_CONFIDENCE;
    }

    public static function isCompleteTranscriptResult(mixed $result): bool
    {
        return self::isUsableTranscriptResult($result)
            && ($result['complete'] ?? null) === true
            && is_int($result['total_strips'] ?? null)
            && $result['total_strips'] > 0
            && is_int($result['source_frame_count'] ?? null)
            && $result['source_frame_count'] === 1
            && is_int($result['processed_strips'] ?? null)
            && $result['processed_strips'] === $result['total_strips']
            && is_int($result['max_new_tokens'] ?? null)
            && $result['max_new_tokens'] === 128
            && ($result['termination'] ?? null) === 'eos'
            && array_key_exists('confidence', $result)
            && is_numeric($result['confidence'])
            && is_finite((float) $result['confidence'])
            && array_key_exists('line_count', $result)
            && is_numeric($result['line_count'])
            && (int) $result['line_count'] >= 0;
    }

    /**
     * Transcribe with explicitly requested, policy-admitted Transkribus fallback.
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
        $tryTranskribus = ($options['try_transkribus'] ?? false) === true;

        // Try local TrOCR first
        $localResult = $this->transcribe($imagePath, $options);

        // Null can hide a dispatched timeout or uncertain worker outcome.
        // Only a proven complete local result permits a new cloud request.
        if (! self::isCompleteTranscriptResult($localResult)) {
            return null;
        }

        if (($localResult['confidence'] ?? 0) >= $threshold) {
            $localResult['source'] = 'trocr_local';

            return $localResult;
        }

        // Fallback to Transkribus if enabled and configured
        if ($tryTranskribus) {
            $cloudResult = $this->transcribeViaTranskribus($imagePath, $options);
            if ($cloudResult) {
                $cloudResult['source'] = 'transkribus';
                $cloudResult['fallback_reason'] = 'low_local_confidence_'.round(($localResult['confidence'] ?? 0) * 100);

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
    public function transcribeViaTranskribus(string $imagePath, array $options = []): ?array
    {
        // Configured credentials do not authorize exporting local private data.
        if (($options['try_transkribus'] ?? false) !== true
            || ($options['sensitive_data'] ?? true) !== false
            || (isset($options['required_instance_id']) && $options['required_instance_id'] !== '')) {
            return null;
        }
        foreach (['local_only', 'disable_external_fallback', 'disable_cascade', 'no_fallback', 'ollama_required_no_fallback'] as $flag) {
            if (array_key_exists($flag, $options) && RuntimeBoolean::parse($options[$flag]) !== false) {
                return null;
            }
        }

        $baseUrl = 'https://app.transkribus.org/metagrapho/api';
        try {
            $policy = app(OfflinePolicyService::class);
            if ($policy->isOfflineModeActive() || ! $policy->isInternetAccessEnabled()) {
                return null;
            }
            $host = parse_url($baseUrl, PHP_URL_HOST);
            $decisions = [
                $policy->evaluateRemoteDomain($host),
                $policy->evaluatePath($imagePath, 'read'),
                $policy->evaluateProvider(['instance_id' => 'transkribus', 'instance_type' => 'custom', 'allows_private_data' => false]),
                $policy->evaluateOperation('cloud_llm_call', ['remote_domain' => $host]),
            ];
            foreach ($decisions as $decision) {
                if (! $decision->allowed || $decision->requiresConfirmation) {
                    return null;
                }
            }
        } catch (\Throwable $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }

            return null;
        }

        $apiKey = config('services.transkribus.api_key');

        if (! $apiKey) {
            Log::debug('HtrTranscription: Transkribus not configured (no API key)');

            return null;
        }

        if (! file_exists($imagePath)) {
            return null;
        }

        try {
            // Step 1: Upload image and start recognition
            $response = Http::connectTimeout(5)->timeout(60)
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
                    'error_kind' => 'http_status',
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

                $statusResponse = Http::connectTimeout(5)->timeout(15)
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
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }
            Log::error('HtrTranscription: Transkribus error', ['exception_kind' => $e::class]);

            return null;
        }
    }
}
