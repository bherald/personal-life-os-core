<?php

namespace App\Console\Commands;

use App\Services\Research\UniversalResearchOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResearchRunMissionsCommand extends Command
{
    private const DEFAULT_TIMEOUT_MINUTES = 30;
    private const TIMEOUT_SAFETY_BUFFER_SECONDS = 180;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'research:run-missions
                            {--mission= : Specific mission UUID to execute}
                            {--limit=1 : Max missions per run (each can take up to 30min)}
                            {--force : Re-execute completed/failed missions}
                            {--stats : Show mission stats only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute pending research missions via UniversalResearchOrchestrator';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $missionId = $this->option('mission');
        $limit     = max(1, (int) $this->option('limit'));
        $force     = $this->option('force');

        $missions = $this->resolveMissions($missionId, $limit, $force);

        if (empty($missions)) {
            $this->info('No missions to execute.');
            return Command::SUCCESS;
        }

        $attempted = 0;
        $succeeded = 0;
        $failed    = 0;
        $startedAt = microtime(true);
        $deadlineSeconds = $this->resolveDeadlineSeconds();

        foreach ($missions as $mission) {
            if ($this->shouldStopBeforeStartingMission($mission, $startedAt, $deadlineSeconds)) {
                $this->warn('Stopping early to stay within the scheduled runtime budget.');
                Log::info('research:run-missions stopping early for runtime budget', [
                    'attempted' => $attempted,
                    'deadline_seconds' => $deadlineSeconds,
                ]);
                break;
            }

            $attempted++;
            $id    = $mission->id;
            $title = $mission->title ?? $id;

            $this->line("Running mission [{$id}]: {$title}");

            try {
                $result = app(UniversalResearchOrchestrator::class)->executeMission($id, [
                    'skip_recursive' => true,
                    'trace_timing' => true,
                    'max_verification_facts' => 12,
                ]);

                if (!empty($result['success'])) {
                    $facts = $result['facts_stored'] ?? 0;
                    $this->info("  => Completed. Facts stored: {$facts}");
                    Log::info('research:run-missions mission completed', [
                        'mission_id'   => $id,
                        'facts_stored' => $facts,
                    ]);
                    $succeeded++;
                } else {
                    $error = $result['error'] ?? 'Unknown error';
                    $this->warn("  => Failed: {$error}");
                    Log::warning('research:run-missions mission failed', [
                        'mission_id' => $id,
                        'error'      => $error,
                    ]);
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->error("  => Exception: {$e->getMessage()}");
                Log::error('research:run-missions mission exception', [
                    'mission_id' => $id,
                    'exception'  => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $this->line("[ITEMS_PROCESSED:{$attempted}]");

        Log::info('research:run-missions completed', [
            'attempted' => $attempted,
            'succeeded' => $succeeded,
            'failed'    => $failed,
        ]);

        // Exit FAILURE only when all missions failed and at least one was attempted
        if ($attempted > 0 && $failed === $attempted) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Show mission stats grouped by status.
     */
    private function showStats(): int
    {
        $rows = DB::connection('pgsql_rag')->select(
            "SELECT status, COUNT(*) AS count FROM research_missions GROUP BY status ORDER BY status ASC"
        );

        if (empty($rows)) {
            $this->info('No missions found.');
            return Command::SUCCESS;
        }

        $tableRows = array_map(fn($r) => [$r->status, $r->count], $rows);
        $this->table(['Status', 'Count'], $tableRows);

        return Command::SUCCESS;
    }

    /**
     * Resolve the list of missions to execute.
     *
     * @return array<object>
     */
    private function resolveMissions(?string $missionId, int $limit, bool $force): array
    {
        if ($missionId !== null) {
            // Single mission by UUID, regardless of status
            return DB::connection('pgsql_rag')->select(
                "SELECT id, title, status, time_limit_minutes FROM research_missions WHERE id = ?",
                [$missionId]
            );
        }

        if ($force) {
            return DB::connection('pgsql_rag')->select(
                "SELECT id, title, status, time_limit_minutes FROM research_missions
                 WHERE status IN ('pending','failed','timeout')
                 ORDER BY created_at ASC
                 LIMIT ?",
                [$limit]
            );
        }

        return DB::connection('pgsql_rag')->select(
            "SELECT id, title, status, time_limit_minutes FROM research_missions
             WHERE status = 'pending'
             ORDER BY created_at ASC
             LIMIT ?",
            [$limit]
        );
    }

    private function resolveDeadlineSeconds(): int
    {
        try {
            $job = DB::selectOne(
                "SELECT timeout_minutes FROM scheduled_jobs WHERE name = 'research_run_missions' LIMIT 1"
            );
            $timeoutMinutes = max(1, (int) ($job->timeout_minutes ?? self::DEFAULT_TIMEOUT_MINUTES));
        } catch (\Throwable) {
            $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES;
        }

        return max(
            60,
            ($timeoutMinutes * 60) - self::TIMEOUT_SAFETY_BUFFER_SECONDS
        );
    }

    private function shouldStopBeforeStartingMission(object $mission, float $startedAt, int $deadlineSeconds): bool
    {
        $elapsedSeconds = microtime(true) - $startedAt;
        $missionBudgetSeconds = max(60, ((int) ($mission->time_limit_minutes ?? self::DEFAULT_TIMEOUT_MINUTES)) * 60);

        return ($elapsedSeconds + $missionBudgetSeconds) >= $deadlineSeconds;
    }
}
