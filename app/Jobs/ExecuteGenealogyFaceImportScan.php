<?php

namespace App\Jobs;

use App\Services\Genealogy\GenealogyMediaService;
use App\Services\SystemConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteGenealogyFaceImportScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const RUNTIME_POLICY_CONFIG_KEY = 'genealogy.face_scan_job_runtime_policy';

    private const DEFAULT_TIMEOUT_SECONDS = 7200;

    private const DEFAULT_TRIES = 1;

    private const DEFAULT_QUEUE = 'long-running';

    private const DEFAULT_STATUS_CACHE_TTL_HOURS = 24;

    private const DEFAULT_RUNTIME_POLICY = [
        'timeout_seconds' => self::DEFAULT_TIMEOUT_SECONDS,
        'tries' => self::DEFAULT_TRIES,
        'queue' => self::DEFAULT_QUEUE,
        'status_cache_ttl_hours' => self::DEFAULT_STATUS_CACHE_TTL_HOURS,
    ];

    public int $timeout = self::DEFAULT_TIMEOUT_SECONDS;

    public int $tries = self::DEFAULT_TRIES;

    private string $runtimePolicySource = 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY;

    public function __construct(
        private int $treeId,
        private string $folder,
        private bool $recursive = true
    ) {
        $policy = $this->runtimePolicy();
        $this->timeout = $this->positiveInt($policy['timeout_seconds'] ?? null, self::DEFAULT_TIMEOUT_SECONDS);
        $this->tries = $this->positiveInt($policy['tries'] ?? null, self::DEFAULT_TRIES);
        $this->onQueue($this->stringValue($policy['queue'] ?? null, self::DEFAULT_QUEUE));
    }

    public function handle(GenealogyMediaService $mediaService): void
    {
        Log::info('Queued genealogy face-import scan started', [
            'tree_id' => $this->treeId,
            'folder' => $this->folder,
            'recursive' => $this->recursive,
            'timeout_seconds' => $this->timeout,
            'tries' => $this->tries,
            'runtime_policy_source' => $this->runtimePolicySource,
        ]);

        $result = $mediaService->scanNextcloudFolderWithFaces(
            $this->treeId,
            $this->folder,
            $this->recursive
        );

        Log::info('Queued genealogy face-import scan completed', [
            'tree_id' => $this->treeId,
            'folder' => $this->folder,
            'result' => $result,
            'runtime_policy_source' => $this->runtimePolicySource,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued genealogy face-import scan failed', [
            'tree_id' => $this->treeId,
            'folder' => $this->folder,
            'error' => $exception?->getMessage(),
            'runtime_policy_source' => $this->runtimePolicySource,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }

    private function stringValue(mixed $value, string $fallback): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        return trim($value);
    }
}
