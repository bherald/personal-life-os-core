<?php

namespace App\Console\Commands;

use App\Services\ScheduledJobService;
use Illuminate\Console\Command;

class GenealogyTaskSprint extends Command
{
    protected $signature = 'genealogy:task-sprint
                            {--tree=4 : Tree ID to target}
                            {--stale-hours=72 : Recover processing tasks older than this many hours}
                            {--triage=5 : Number of quality triage candidates to show}
                            {--cleanup-only : Skip triage output}';

    protected $description = 'Clean up stale current-table genealogy tasks and show the best current triage candidates';

    public function handle(ScheduledJobService $jobs): int
    {
        $treeId = (int) $this->option('tree');
        $staleHours = max(1, (int) $this->option('stale-hours'));
        $triageLimit = max(1, (int) $this->option('triage'));

        $cleanup = $jobs->cleanupGenealogyTaskBacklog($treeId, $staleHours);

        $this->info("Genealogy task sprint complete for tree {$treeId}");
        $this->line("Recovered stale tasks: {$cleanup['stale_task_count']}");
        $this->line("Reset queue items: {$cleanup['released_queue_count']}");

        if (! empty($cleanup['stale_task_ids'])) {
            $this->line('Recovered task IDs: '.implode(', ', $cleanup['stale_task_ids']));
        }

        if (! empty($cleanup['released_queue_item_ids'])) {
            $this->line('Reset queue IDs: '.implode(', ', $cleanup['released_queue_item_ids']));
        }

        if ($this->option('cleanup-only')) {
            return 0;
        }

        $candidates = $jobs->getGenealogyTaskSprintCandidates($treeId, $triageLimit);

        if ($candidates === []) {
            $this->warn('No quality triage candidates found.');

            return 0;
        }

        $this->newLine();
        $this->info('Quality triage candidates');
        $this->table(
            ['Task', 'Person', 'Type', 'Priority', 'Sources', 'Reviews', 'Outcome', 'Updated', 'Question'],
            array_map(
                static fn (array $row) => [
                    $row['id'],
                    $row['person_name'],
                    $row['task_type'],
                    $row['priority'],
                    $row['source_count'],
                    $row['review_items_count'],
                    $row['outcome_state'] ?: $row['status'],
                    $row['updated_at'],
                    mb_strimwidth((string) $row['research_question'], 0, 80, '...'),
                ],
                $candidates
            )
        );

        return 0;
    }
}
