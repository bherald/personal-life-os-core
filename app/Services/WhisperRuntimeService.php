<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class WhisperRuntimeService
{
    private const GPU_LOCK_KEY = 'whisper_gpu_lock';

    /**
     * @return array<string,mixed>
     */
    public function capability(): array
    {
        $path = $this->executablePath();
        $model = (string) config('services.whisper.model', 'base');
        $modelDir = $this->modelDirectory();
        $modelCached = $modelDir !== null ? is_file($modelDir.'/'.$model.'.pt') : null;

        return [
            'status' => $path === null ? 'not_detected' : ($modelCached === false ? 'not_ready' : 'available'),
            'provider' => 'local Whisper-compatible command path',
            'path' => $path,
            'model' => $model,
            'model_dir' => $modelDir,
            'model_cached' => $modelCached,
            'device' => $this->device(),
            'timeout_seconds' => $this->transcriptionTimeoutSeconds(null, $model),
            'timeout_policy' => [
                'mode' => 'duration_model_aware_bounded',
                'transcription_floor_seconds' => $this->transcriptionTimeoutFloorSeconds(),
                'transcription_seconds_per_audio_second' => $this->transcriptionSecondsPerAudioSecond(),
                'transcription_cap_seconds' => $this->transcriptionTimeoutCapSeconds(),
                'model' => $model,
                'model_factor' => $this->modelTimeoutFactor($model),
                'model_factors' => $this->modelTimeoutFactors(),
                'unknown_model_factor' => 1.0,
                'baseline_timeout_never_shortened' => true,
                'preparation_floor_seconds' => $this->preparationTimeoutFloorSeconds(),
                'preparation_seconds_per_media_second' => $this->preparationSecondsPerMediaSecond(),
                'preparation_cap_seconds' => $this->preparationTimeoutCapSeconds(),
                'duration_probe_timeout_seconds' => $this->durationProbeTimeoutSeconds(),
            ],
        ];
    }

    public function transcriptionTimeoutSeconds(?float $durationSeconds, ?string $model = null): int
    {
        $floor = $this->transcriptionTimeoutFloorSeconds();
        $factor = $this->modelTimeoutFactor($model);

        if ($durationSeconds === null || ! is_finite($durationSeconds) || $durationSeconds <= 0) {
            return min($this->transcriptionTimeoutCapSeconds(), (int) ceil($floor * $factor));
        }

        return min(
            $this->transcriptionTimeoutCapSeconds(),
            max($floor, (int) ceil($durationSeconds * $this->transcriptionSecondsPerAudioSecond() * $factor))
        );
    }

    public function modelTimeoutFactor(?string $model = null): float
    {
        $model = strtolower(trim($model ?? (string) config('services.whisper.model', 'base')));

        return $this->modelTimeoutFactors()[$model] ?? 1.0;
    }

    public function preparationTimeoutSeconds(?float $durationSeconds): int
    {
        $floor = $this->preparationTimeoutFloorSeconds();

        if ($durationSeconds === null || ! is_finite($durationSeconds) || $durationSeconds <= 0) {
            return $floor;
        }

        return min(
            $this->preparationTimeoutCapSeconds(),
            max($floor, (int) ceil($durationSeconds * $this->preparationSecondsPerMediaSecond()))
        );
    }

    public function gpuLockTtlSeconds(?float $durationSeconds, ?string $model = null): int
    {
        $configuredFloor = max(60, (int) config('services.whisper.gpu_lock_ttl', 900));

        return max($configuredFloor, $this->transcriptionTimeoutSeconds($durationSeconds, $model) + 60);
    }

    public function mediaDurationSeconds(string $filePath): ?float
    {
        if (! is_file($filePath)) {
            return null;
        }

        try {
            $result = Process::timeout($this->durationProbeTimeoutSeconds())->run([
                'ffprobe',
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $filePath,
            ]);

            if (! $result->successful()) {
                return null;
            }

            $duration = (float) trim($result->output());

            return is_finite($duration) && $duration > 0 ? $duration : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function transcribe(string $filePath, ?string $language = null, string $operation = 'local_transcription'): array
    {
        $durationSeconds = $this->mediaDurationSeconds($filePath);
        $model = trim((string) config('services.whisper.model', 'base')) ?: 'base';
        $modelFactor = $this->modelTimeoutFactor($model);
        $timeoutSeconds = $this->transcriptionTimeoutSeconds($durationSeconds, $model);
        $outputDir = null;
        $outputDirIdentity = null;
        $processAttempted = false;
        $processReturned = false;
        $lockToken = null;

        try {
            $whisperPath = $this->executablePath();
            if ($whisperPath === null || ! is_file($filePath)) {
                return $this->transcriptionFailure('Whisper runtime or input is unavailable', 'runtime_or_input_unavailable', $durationSeconds, $timeoutSeconds, $model, $modelFactor);
            }

            $lock = $this->acquireGpuLock($operation, $this->gpuLockTtlSeconds($durationSeconds, $model));
            if (! $lock['acquired']) {
                return $this->transcriptionFailure(
                    $lock['reason'] ?? 'Whisper GPU unavailable',
                    'gpu_unavailable',
                    $durationSeconds,
                    $timeoutSeconds,
                    $model,
                    $modelFactor
                );
            }
            $lockToken = $lock['token'];

            $outputDir = rtrim(sys_get_temp_dir(), '/').'/plos-whisper-output-'.bin2hex(random_bytes(8));
            if (! mkdir($outputDir, 0700, true)) {
                return $this->transcriptionFailure('Unable to create Whisper output directory', 'output_directory_unavailable', $durationSeconds, $timeoutSeconds, $model, $modelFactor);
            }

            $outputDirIdentity = @stat($outputDir);

            $command = [
                $whisperPath,
                $filePath,
                '--model', $model,
                '--output_dir', $outputDir,
                '--output_format', 'txt',
            ];
            $resolvedLanguage = trim($language ?? (string) config('services.whisper.language', ''));
            if ($resolvedLanguage !== '') {
                array_push($command, '--language', $resolvedLanguage);
            }
            $device = $this->device();
            if ($device !== 'auto') {
                array_push($command, '--device', $device);
            }
            if ($device === 'cpu') {
                array_push($command, '--fp16', 'False');
            }
            $modelDirectory = $this->modelDirectory();
            if ($modelDirectory !== null) {
                array_push($command, '--model_dir', $modelDirectory);
            }

            $processAttempted = true;
            $result = Process::timeout($timeoutSeconds)->run($command);
            $processReturned = true;
            if (! $result->successful()) {
                $error = trim($result->errorOutput());

                return $this->transcriptionFailure(
                    $error !== '' ? Str::limit($error, 500, '') : 'Whisper process failed',
                    'process_failed',
                    $durationSeconds,
                    $timeoutSeconds,
                    $model,
                    $modelFactor
                );
            }

            $txtFiles = glob($outputDir.'/*.txt') ?: [];
            $text = $txtFiles !== [] ? trim((string) file_get_contents($txtFiles[0])) : '';

            return [
                'success' => $text !== '',
                'text' => $text,
                'error' => $text === '' ? 'Whisper returned an empty transcript' : null,
                'failure_reason' => $text === '' ? 'empty_transcript' : null,
                'duration_seconds' => $durationSeconds,
                'timeout_seconds' => $timeoutSeconds,
                'model' => $model,
                'model_timeout_factor' => $modelFactor,
            ];
        } catch (Throwable $e) {
            if (InferenceAdmissionException::isAdmissionFailure($e)) {
                throw $e;
            }
            if ($processAttempted && ! $processReturned) {
                throw new InferenceAdmissionException('Whisper process outcome is UNKNOWN; input, output and owned GPU evidence require reconciliation.', 0, $e);
            }
            Log::warning('Whisper runtime execution failed', [
                'error_type' => $e::class,
                'error_sha256' => hash('sha256', $e->getMessage()),
                'timeout_seconds' => $timeoutSeconds,
                'model' => $model,
                'model_timeout_factor' => $modelFactor,
            ]);

            $timedOut = $e instanceof ProcessTimedOutException
                || str_contains(strtolower($e->getMessage()), 'timed out');

            return $this->transcriptionFailure(
                $timedOut
                    ? "Whisper process timed out after {$timeoutSeconds} seconds for model {$model}; review the model-aware timeout policy before retrying"
                    : 'Whisper runtime execution failed',
                $timedOut ? 'transcription_timeout' : 'runtime_exception',
                $durationSeconds,
                $timeoutSeconds,
                $model,
                $modelFactor
            );
        } finally {
            // Artifact disposal after an ordinary return is not descendant/physical cleanup proof.
            // A throwing runner keeps its private evidence for the separate durable recovery path.
            if ($outputDir !== null && is_array($outputDirIdentity) && (! $processAttempted || $processReturned)) {
                clearstatcache(true, $outputDir);
                $current = @lstat($outputDir);
                if (! is_link($outputDir) && is_dir($outputDir) && is_array($current)
                    && $current['dev'] === $outputDirIdentity['dev'] && $current['ino'] === $outputDirIdentity['ino']) {
                    foreach (glob($outputDir.'/*') ?: [] as $artifact) {
                        @unlink($artifact);
                    }
                    @rmdir($outputDir);
                }
            }
            if (! $processAttempted || $processReturned) {
                $this->releaseGpuLock($lockToken);
            }
        }
    }

    public function device(): string
    {
        $device = strtolower(trim((string) config('services.whisper.device', 'auto')));

        return in_array($device, ['auto', 'cpu', 'cuda'], true) ? $device : 'auto';
    }

    public function modelDirectory(): ?string
    {
        $path = trim((string) config('services.whisper.model_dir', ''));

        return $path !== '' ? rtrim($path, '/') : null;
    }

    public function usesGpu(): bool
    {
        return $this->device() !== 'cpu';
    }

    public function executablePath(): ?string
    {
        foreach ($this->candidatePaths() as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        $path = (new ExecutableFinder)->find('whisper');

        return is_string($path) && is_file($path) && is_executable($path) ? $path : null;
    }

    /**
     * @return array{acquired:bool,token:?string,reason:?string}
     */
    public function acquireGpuLock(string $operation, ?int $ttlSeconds = null): array
    {
        if (! $this->usesGpu()) {
            return ['acquired' => true, 'token' => null, 'reason' => null];
        }

        if (Cache::has('ollama_busy_lock')) {
            return ['acquired' => false, 'token' => null, 'reason' => 'GPU busy (Ollama)'];
        }

        $token = (string) Str::uuid();
        $locked = Cache::add(self::GPU_LOCK_KEY, [
            'token' => $token,
            'operation' => Str::limit($operation, 120, ''),
            'started_at' => time(),
            'pid' => getmypid(),
        ], max(60, $ttlSeconds ?? (int) config('services.whisper.gpu_lock_ttl', 900)));

        return $locked
            ? ['acquired' => true, 'token' => $token, 'reason' => null]
            : ['acquired' => false, 'token' => null, 'reason' => 'Whisper GPU lock unavailable'];
    }

    public function releaseGpuLock(?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }

        $current = Cache::get(self::GPU_LOCK_KEY);
        if (is_array($current) && hash_equals((string) ($current['token'] ?? ''), $token)) {
            Cache::forget(self::GPU_LOCK_KEY);
        }
    }

    /**
     * @return list<string>
     */
    private function candidatePaths(): array
    {
        $home = trim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')));

        return array_values(array_unique(array_filter([
            trim((string) config('services.whisper.path', '')),
            $home !== '' ? $home.'/.local/share/plos/whisper-venv/bin/whisper' : null,
            $home !== '' ? $home.'/.local/bin/whisper' : null,
            '/usr/local/bin/whisper',
            '/usr/bin/whisper',
        ], static fn (?string $path): bool => $path !== null && $path !== '')));
    }

    /**
     * @return array<string,mixed>
     */
    private function transcriptionFailure(
        string $error,
        string $failureReason,
        ?float $durationSeconds,
        int $timeoutSeconds,
        string $model,
        float $modelFactor,
    ): array {
        return [
            'success' => false,
            'text' => '',
            'error' => $error,
            'failure_reason' => $failureReason,
            'duration_seconds' => $durationSeconds,
            'timeout_seconds' => $timeoutSeconds,
            'model' => $model,
            'model_timeout_factor' => $modelFactor,
        ];
    }

    /** @return array<string,float> */
    private function modelTimeoutFactors(): array
    {
        $configured = (array) config('services.whisper.timeout_model_factors', []);
        $factors = [];

        foreach ($configured as $model => $factor) {
            $model = strtolower(trim((string) $model));
            if ($model === '' || ! is_numeric($factor)) {
                continue;
            }

            $factors[$model] = max(1.0, min(8.0, (float) $factor));
        }

        return $factors;
    }

    private function transcriptionTimeoutFloorSeconds(): int
    {
        return max(30, (int) config('services.whisper.timeout', 300));
    }

    private function transcriptionSecondsPerAudioSecond(): float
    {
        return max(0.1, (float) config('services.whisper.timeout_per_audio_second', 10));
    }

    private function transcriptionTimeoutCapSeconds(): int
    {
        return max(
            $this->transcriptionTimeoutFloorSeconds(),
            (int) config('services.whisper.timeout_cap', 43_200)
        );
    }

    private function preparationTimeoutFloorSeconds(): int
    {
        return max(30, (int) config('services.whisper.preparation_timeout', 120));
    }

    private function preparationSecondsPerMediaSecond(): float
    {
        return max(0.1, (float) config('services.whisper.preparation_timeout_per_media_second', 0.5));
    }

    private function preparationTimeoutCapSeconds(): int
    {
        return max(
            $this->preparationTimeoutFloorSeconds(),
            (int) config('services.whisper.preparation_timeout_cap', 21_600)
        );
    }

    private function durationProbeTimeoutSeconds(): int
    {
        return max(1, (int) config('services.whisper.duration_probe_timeout', 10));
    }
}
