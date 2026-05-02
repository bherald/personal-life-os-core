<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GPSProofGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * N67 — Batch GPS proof argument auto-generation.
 *
 * Finds open/in_progress gps_research_tasks with evidence and generates
 * GPS/NGSQ-style proof arguments, saving conclusions back to the task.
 */
class GenealogyGenerateProofs extends Command
{
    protected $signature = 'genealogy:generate-proofs
                            {--tree=   : Tree ID (required)}
                            {--task=   : Generate for a single task ID}
                            {--limit=5 : Max tasks to process}
                            {--dry-run : Show eligible tasks without generating}';

    protected $description = 'N67: Auto-generate GPS proof arguments for open research tasks';

    public function handle(GPSProofGeneratorService $generator): int
    {
        $treeId  = (int) $this->option('tree');
        $taskId  = $this->option('task') ? (int)$this->option('task') : null;
        $limit   = max(1, (int)$this->option('limit'));
        $dryRun  = $this->option('dry-run');

        // Single-task mode
        if ($taskId) {
            return $this->handleSingleTask($generator, $taskId, $dryRun);
        }

        if (!$treeId) {
            $this->error('--tree is required (or use --task for a single task)');
            return 1;
        }

        $this->info("GPS Proof Generation — tree={$treeId}, limit={$limit}" . ($dryRun ? ' [DRY RUN]' : ''));

        $result = $generator->generateForOpenTasks($treeId, $limit, $dryRun);

        $this->line("Processed: {$result['processed']} | Skipped: {$result['skipped']} | Errors: {$result['errors']}");

        if (!empty($result['tasks'])) {
            $this->table(
                ['Task ID', 'Person ID', 'Status', 'Confidence / Reason'],
                array_map(fn($t) => [
                    $t['task_id'],
                    $t['person_id'] ?? '-',
                    $t['status'],
                    $t['confidence'] ?? ($t['reason'] ?? ($t['error'] ?? '-')),
                ], $result['tasks'])
            );
        }

        if (!$dryRun && $result['processed'] > 0) {
            $this->info("Proofs saved to gps_research_tasks.conclusion.");
        }

        return $result['errors'] > 0 ? 1 : 0;
    }

    private function handleSingleTask(GPSProofGeneratorService $generator, int $taskId, bool $dryRun): int
    {
        $task = DB::selectOne(
            "SELECT id, person_id, tree_id, task_type, question, status FROM gps_research_tasks WHERE id = ?",
            [$taskId]
        );

        if (!$task) {
            $this->error("Task {$taskId} not found.");
            return 1;
        }

        $this->line("Task {$taskId}: {$task->question}");
        $this->line("Person: {$task->person_id} | Type: {$task->task_type} | Status: {$task->status}");

        if ($dryRun) {
            $this->info("[DRY RUN] Would generate proof for task {$taskId}.");
            return 0;
        }

        $result = $generator->generateProofArgument(
            (int)$task->person_id,
            $task->question,
            ['task_id' => $taskId]
        );

        if (!$result['success']) {
            $this->error("Generation failed: " . ($result['error'] ?? 'unknown'));
            return 1;
        }

        $this->info("Confidence: {$result['confidence']}");
        $this->line("Citations validated: {$result['citations_validated']} | Warnings: " . count($result['warnings']));
        $this->line("Task saved: " . ($result['task_saved'] ? 'yes' : 'no'));

        if (!empty($result['warnings'])) {
            foreach ($result['warnings'] as $w) {
                $this->warn($w);
            }
        }

        $this->line("\n--- PROOF ---");
        $this->line($result['proof']);

        return 0;
    }
}
