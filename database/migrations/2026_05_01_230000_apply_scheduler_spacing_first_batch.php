<?php

use App\Services\ScheduledJobService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FIRST_BATCH = [
        'midday_digest' => ['0 16 * * *', '29 16 * * *'],
        'se_screen' => ['0 1,7,13,19 * * *', '47 1,7,13,19 * * *'],
        'devops_ai_maintenance' => ['30 4 * * *', '13 4 * * *'],
        'genealogy_coverage_rebuild' => ['30 3 * * *', '19 3 * * *'],
        'workflow_research_source_maintenance' => ['0 3 * * *', '53 3 * * *'],
    ];

    public function up(): void
    {
        $this->applyCronMap(self::FIRST_BATCH, 1);
    }

    public function down(): void
    {
        $this->applyCronMap(self::FIRST_BATCH, 0);
    }

    /**
     * Move only the minute field for the approved first batch. Timeout,
     * queue/overlap, parallelism, job limits, and batch-size settings stay as-is.
     */
    private function applyCronMap(array $jobs, int $targetIndex): void
    {
        $scheduler = app(ScheduledJobService::class);
        $sourceIndex = $targetIndex === 1 ? 0 : 1;

        foreach ($jobs as $name => $crons) {
            $targetCron = $crons[$targetIndex];
            $nextRun = $scheduler->calculateNextRun($targetCron);

            DB::table('scheduled_jobs')
                ->where('name', $name)
                ->whereIn('cron_expression', [$crons[$sourceIndex], $targetCron])
                ->update([
                    'cron_expression' => $targetCron,
                    'next_run_at' => $nextRun,
                    'updated_at' => now(),
                ]);
        }
    }
};
