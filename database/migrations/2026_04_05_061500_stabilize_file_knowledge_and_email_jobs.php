<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateAgentTask('file_ops_agent', function (array $notes): array {
            $notes['notify'] = $notes['notify'] ?? true;
            $notes['model_role'] = 'fast';
            $notes['max_iterations'] = 3;
            $notes['benchmark_mode'] = 'agentic';

            return $notes;
        }, 60);

        $this->updateAgentTask('knowledge_curator_agent', function (array $notes): array {
            $notes['notify'] = $notes['notify'] ?? false;
            $notes['model_role'] = 'fast';
            $notes['max_iterations'] = 3;
            $notes['benchmark_mode'] = 'agentic';

            return $notes;
        }, 90);

        DB::update(
            "UPDATE scheduled_jobs
             SET command = ?, timeout_minutes = GREATEST(COALESCE(timeout_minutes, 0), 60), stall_exempt = 1, updated_at = NOW()
             WHERE name = ? AND enabled = 1",
            ['email:rag-index --limit=8 --max-files=2 --timeout=45', 'email_rag_index']
        );
    }

    public function down(): void
    {
        $this->revertAgentTask('file_ops_agent', 39);
        $this->revertAgentTask('knowledge_curator_agent', 90);

        DB::update(
            "UPDATE scheduled_jobs
             SET command = ?, timeout_minutes = 60, updated_at = NOW()
             WHERE name = ? AND enabled = 1",
            ['email:rag-index --limit=8 --timeout=45', 'email_rag_index']
        );
    }

    private function updateAgentTask(string $name, callable $mutator, int $timeoutMinutes): void
    {
        $job = DB::selectOne("SELECT id, notes FROM scheduled_jobs WHERE name = ? LIMIT 1", [$name]);
        if (!$job) {
            return;
        }

        $notes = json_decode($job->notes ?? '[]', true);
        if (!is_array($notes)) {
            $notes = [];
        }

        $notes = $mutator($notes);

        DB::update(
            "UPDATE scheduled_jobs
             SET notes = ?, timeout_minutes = GREATEST(COALESCE(timeout_minutes, 0), ?), stall_exempt = 1, updated_at = NOW()
             WHERE id = ?",
            [json_encode($notes, JSON_UNESCAPED_SLASHES), $timeoutMinutes, $job->id]
        );
    }

    private function revertAgentTask(string $name, int $timeoutMinutes): void
    {
        $job = DB::selectOne("SELECT id, notes FROM scheduled_jobs WHERE name = ? LIMIT 1", [$name]);
        if (!$job) {
            return;
        }

        $notes = json_decode($job->notes ?? '[]', true);
        if (!is_array($notes)) {
            $notes = [];
        }

        unset($notes['model_role'], $notes['max_iterations'], $notes['benchmark_mode']);

        DB::update(
            "UPDATE scheduled_jobs
             SET notes = ?, timeout_minutes = ?, updated_at = NOW()
             WHERE id = ?",
            [json_encode($notes, JSON_UNESCAPED_SLASHES), $timeoutMinutes, $job->id]
        );
    }
};
