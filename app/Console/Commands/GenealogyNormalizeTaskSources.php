<?php

namespace App\Console\Commands;

use App\Services\Genealogy\ResearchTaskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenealogyNormalizeTaskSources extends Command
{
    protected $signature = 'genealogy:normalize-task-sources
                            {--task= : Normalize one task ID}
                            {--tree=4 : Tree ID for batch mode}
                            {--limit=5 : Max tasks to normalize in batch mode}';

    protected $description = 'Normalize genealogy research logs into linked genealogy sources';

    public function handle(ResearchTaskService $tasks): int
    {
        $taskId = $this->option('task') ? (int) $this->option('task') : null;

        if ($taskId) {
            $result = $tasks->normalizeTaskEvidenceSources($taskId);
            $this->table(['Task', 'Created Sources', 'Linked Sources'], [[
                $result['task_id'],
                $result['created_sources'],
                $result['linked_sources'],
            ]]);

            return 0;
        }

        $treeId = (int) $this->option('tree');
        $limit = max(1, (int) $this->option('limit'));
        $rows = DB::select(
            "SELECT t.id
             FROM gps_research_tasks t
             LEFT JOIN genealogy_person_sources ps ON ps.person_id = t.person_id
             LEFT JOIN gps_research_logs l ON l.task_id = t.id
             WHERE t.tree_id = ?
               AND t.status IN ('open', 'in_progress')
             GROUP BY t.id
             HAVING COUNT(DISTINCT l.id) > 0 AND COUNT(DISTINCT ps.source_id) = 0
             ORDER BY t.updated_at DESC
             LIMIT ?",
            [$treeId, $limit]
        );

        if ($rows === []) {
            $this->warn('No normalization candidates found.');
            return 0;
        }

        $table = [];
        foreach ($rows as $row) {
            $result = $tasks->normalizeTaskEvidenceSources((int) $row->id);
            $table[] = [$result['task_id'], $result['created_sources'], $result['linked_sources']];
        }

        $this->table(['Task', 'Created Sources', 'Linked Sources'], $table);

        return 0;
    }
}
