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

class ExecuteGenealogyFolderScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const RUNTIME_POLICY_CONFIG_KEY = 'genealogy.folder_scan_job_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'timeout_seconds' => 7200,
        'tries' => 1,
    ];

    public int $timeout = 7200;
    public int $tries = 1;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function __construct(
        private int $treeId,
        private string $folder,
        private bool $recursive = true,
        private bool $filterForMatches = false
    ) {
        $this->applyRuntimePolicy();

        $this->onQueue('long-running');
    }

    public function handle(GenealogyMediaService $mediaService): void
    {
        Log::info('Queued genealogy folder scan started', [
            'tree_id' => $this->treeId,
            'folder' => $this->folder,
            'recursive' => $this->recursive,
            'filter_for_matches' => $this->filterForMatches,
        ]);

        $result = $mediaService->scanNextcloudFolder(
            $this->treeId,
            $this->folder,
            $this->recursive,
            $this->filterForMatches
        );

        Log::info('Queued genealogy folder scan completed', [
            'tree_id' => $this->treeId,
            'folder' => $this->folder,
            'result' => $result,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued genealogy folder scan failed', [
            'tree_id' => $this->treeId,
            'folder' => $this->folder,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function applyRuntimePolicy(): void
    {
        $this->timeout = $this->runtimePolicyInt('timeout_seconds');
        $this->tries = $this->runtimePolicyInt('tries');
    }

    private function runtimePolicyInt(string $key): int
    {
        return $this->positiveInt(
            $this->runtimePolicy()[$key] ?? null,
            (int) self::DEFAULT_RUNTIME_POLICY[$key]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        if ($this->runtimePolicy !== null) {
            return $this->runtimePolicy;
        }

        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return $this->runtimePolicy = array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : $fallback;
    }
}
