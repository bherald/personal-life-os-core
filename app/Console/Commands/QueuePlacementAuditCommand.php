<?php

namespace App\Console\Commands;

use App\Services\Scheduler\QueuePlacementAuditor;
use Illuminate\Console\Command;

/**
 * APL #10 / row 8C — queue-placement audit.
 *
 * Scans app/Jobs/*.php, extracts each job's declared queue from its
 * `$this->onQueue(...)` call (or defaults to 'default'), runs the
 * QueuePlacementAuditor against each, and prints a table of
 * recommendations.
 *
 * Recommend-only — does not move any job, does not touch the DB.
 * Designed to run as a weekly review job so operators see placement
 * drift early instead of finding it when a short `default` job is
 * starved by a 20-minute AI job on the same queue.
 */
class QueuePlacementAuditCommand extends Command
{
    protected $signature = 'queue:audit-placement
        {--json : Output machine-readable JSON}
        {--only-drift : Only show jobs whose declared queue differs from recommended}';

    protected $description = 'APL #10: recommend queue placement for each app/Jobs/*.php';

    public function handle(QueuePlacementAuditor $auditor): int
    {
        $jobs = $this->collectJobs();
        if ($jobs === []) {
            $this->warn('No job files discovered under app/Jobs.');
            return self::SUCCESS;
        }

        $results = $auditor->audit($jobs);

        if ($this->option('only-drift')) {
            $results = array_values(array_filter($results, fn (array $r) => $r['declared_queue'] !== $r['recommended_queue']));
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info('Queue-placement audit for app/Jobs/*.php:');
        $this->table(
            ['Job', 'Declared', 'Recommended', 'Severity', 'Reason'],
            array_map(
                static fn (array $r) => [
                    $r['name'],
                    $r['declared_queue'],
                    $r['recommended_queue'],
                    $r['severity'],
                    $r['reason'],
                ],
                $results
            )
        );

        $drift = array_filter($results, fn (array $r) => $r['declared_queue'] !== $r['recommended_queue']);
        if ($drift !== []) {
            $this->warn(sprintf(
                'Found %d job(s) whose declared queue does not match the recommended queue. Review before moving; recommendation is heuristic.',
                count($drift)
            ));
            $this->info(sprintf('[ITEMS_PROCESSED:%d]', count($drift)));
        } else {
            $this->info('All jobs are placed on the recommended queue.');
            $this->info('[ITEMS_PROCESSED:0]');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{name:string, declared_queue:string, content:string}>
     */
    private function collectJobs(): array
    {
        $dir = app_path('Jobs');
        if (! is_dir($dir)) {
            return [];
        }

        $jobs = [];
        foreach (glob($dir.'/*.php') as $path) {
            $content = (string) file_get_contents($path);
            $name = basename($path, '.php');
            $declared = 'default';
            if (preg_match("~onQueue\(\s*['\"]([^'\"]+)['\"]~", $content, $m)) {
                $declared = $m[1];
            }
            $jobs[] = [
                'name' => $name,
                'declared_queue' => $declared,
                'content' => $content,
            ];
        }

        return $jobs;
    }
}
