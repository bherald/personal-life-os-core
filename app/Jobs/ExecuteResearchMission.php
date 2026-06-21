<?php

namespace App\Jobs;

use App\Services\Research\UniversalResearchOrchestrator;
use App\Services\SystemConfigService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteResearchMission implements ShouldQueue
{
    use Queueable;

    private const RUNTIME_POLICY_CONFIG_KEY = 'research.execute_research_mission_job_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'timeout_seconds' => 7200,
        'tries' => 1,
        'overlap_release_after_seconds' => 300,
        'overlap_expire_after_seconds' => 7500,
    ];

    public $timeout = 7200;

    public $tries = 1;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    protected string $missionId;

    public function __construct(string $missionId)
    {
        $this->missionId = $missionId;
        $this->applyRuntimePolicy();

        $this->onQueue('long-running');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('research-mission:' . $this->missionId))
                ->releaseAfter($this->runtimePolicyInt('overlap_release_after_seconds'))
                ->expireAfter($this->runtimePolicyInt('overlap_expire_after_seconds')),
        ];
    }

    public function handle(UniversalResearchOrchestrator $orchestrator): void
    {
        Log::info('Starting queued research mission execution', [
            'mission_id' => $this->missionId,
            'attempt' => $this->attempts(),
        ]);

        $result = $orchestrator->executeMissionWithDeduplication($this->missionId);

        Log::info('Queued research mission execution finished', [
            'mission_id' => $this->missionId,
            'success' => (bool) ($result['success'] ?? false),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Queued research mission execution failed permanently', [
            'mission_id' => $this->missionId,
            'attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
        ]);
    }

    public function tags(): array
    {
        return ['research', 'research-mission:' . $this->missionId];
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
