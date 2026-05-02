<?php

namespace App\Console\Commands;

use App\Nodes\ResearchTopicRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResearchTopicRun extends Command
{
    private const DEFAULT_TIMEOUT_MINUTES = 90;
    private const TIMEOUT_SAFETY_BUFFER_SECONDS = 900;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'research:run
                            {--topic= : Specific topic ID to research}
                            {--max=1 : Maximum number of topics to process}
                            {--force : Force run even if not due}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run scheduled research on topics that are due';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $topicId = $this->option('topic');
        $maxTopics = max(1, (int) $this->option('max'));
        $force = $this->option('force');
        $startedAt = microtime(true);
        $deadlineSeconds = $this->resolveDeadlineSeconds();

        $this->info('Starting research topic runner...');

        // If force is set and topic ID is provided, update the topic to be due
        if ($force && $topicId) {
            $updated = DB::connection('pgsql_rag')->update(
                "UPDATE research_topics SET last_ran_at = NULL, updated_at = NOW() WHERE id = ?",
                [$topicId]
            );
            if ($updated) {
                $this->info("Force mode: Resetting last_ran_at for topic {$topicId}");
            }
        }

        // Create and execute the node
        $node = new ResearchTopicRunner([
            'topic_id' => $topicId,
            'max_topics' => $maxTopics,
            'deadline_seconds' => $deadlineSeconds,
        ]);

        $result = $node->execute([]);

        if (!empty($result['error'])) {
            $this->error('Research failed: ' . $result['error']);
            Log::error('research:run command failed', ['error' => $result['error']]);
            return Command::FAILURE;
        }

        $data = $result['data'];

        if (isset($data['message']) && $data['message'] === 'No topics due for research') {
            $this->info('No topics are due for research.');
            return Command::SUCCESS;
        }

        if (!empty($data['time_limited'])) {
            $this->warn("Stopped early to stay within runtime budget ({$deadlineSeconds}s).");
        }

        $this->info("Processed: {$data['processed']} topics");

        if ($data['failed'] > 0) {
            $this->warn("Failed: {$data['failed']} topics");
            foreach ($data['errors'] as $error) {
                $this->error("  - Topic {$error['topic_id']}: {$error['error']}");
            }
        }

        if (!empty($data['topics'])) {
            $this->info('Successfully processed topics:');
            foreach ($data['topics'] as $topic) {
                $this->line("  - [{$topic['id']}] {$topic['description']} -> Result #{$topic['result_id']}");
            }
        }

        Log::info('research:run command completed', [
            'processed' => $data['processed'],
            'failed' => $data['failed'],
        ]);

        return Command::SUCCESS;
    }

    private function resolveDeadlineSeconds(): int
    {
        try {
            $job = DB::selectOne(
                "SELECT timeout_minutes FROM scheduled_jobs WHERE name = 'research_run' LIMIT 1"
            );
            $timeoutMinutes = max(1, (int) ($job->timeout_minutes ?? self::DEFAULT_TIMEOUT_MINUTES));
        } catch (\Throwable) {
            $timeoutMinutes = self::DEFAULT_TIMEOUT_MINUTES;
        }

        return max(60, ($timeoutMinutes * 60) - self::TIMEOUT_SAFETY_BUFFER_SECONDS);
    }
}
