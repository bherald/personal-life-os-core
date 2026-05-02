<?php

namespace App\Jobs;

use App\Services\Research\UniversalResearchOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteResearchMission implements ShouldQueue
{
    use Queueable;

    public $timeout = 7200;

    public $tries = 1;

    protected string $missionId;

    public function __construct(string $missionId)
    {
        $this->missionId = $missionId;
        $this->onQueue('long-running');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('research-mission:' . $this->missionId))
                ->releaseAfter(300)
                ->expireAfter(7500),
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
}
