<?php

namespace App\Jobs;

use App\Services\Genealogy\GedcomParserService;
use App\Services\Genealogy\GenealogyService;
use App\Services\SystemConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteGenealogyMediaPathSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const RUNTIME_POLICY_CONFIG_KEY = 'genealogy.media_path_sync_job_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'timeout_seconds' => 3600,
        'tries' => 1,
    ];

    public int $timeout = 3600;
    public int $tries = 1;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function __construct(
        private int $treeId,
        private string $gedcomPath
    ) {
        $this->applyRuntimePolicy();

        $this->onQueue('long-running');
    }

    public function handle(GenealogyService $genealogyService): void
    {
        Log::info('Queued genealogy media path sync started', [
            'tree_id' => $this->treeId,
            'gedcom_path' => $this->gedcomPath,
        ]);

        $parser = new GedcomParserService($this->gedcomPath);
        $data = $parser->parse();
        $result = $genealogyService->updateMediaPathsFromGedcom($this->treeId, $data['media'] ?? []);

        Log::info('Queued genealogy media path sync completed', [
            'tree_id' => $this->treeId,
            'gedcom_path' => $this->gedcomPath,
            'result' => $result,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued genealogy media path sync failed', [
            'tree_id' => $this->treeId,
            'gedcom_path' => $this->gedcomPath,
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
