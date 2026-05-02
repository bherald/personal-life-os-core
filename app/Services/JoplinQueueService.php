<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Joplin Queue Service
 *
 * Handles queueing of Joplin operations when locks cannot be acquired immediately.
 * Implements retry logic with exponential backoff.
 * Independent PLOS queueing around an operator-managed sync target; it does not
 * use upstream Joplin application or server source code.
 */
class JoplinQueueService
{
    /**
     * Queue a Joplin operation for later execution
     *
     * @param  string  $operationType  Type of operation (create_note, update_note, etc.)
     * @param  array  $payload  Operation data
     * @param  string|null  $noteId  Note ID (if applicable)
     * @param  int  $maxAttempts  Maximum retry attempts
     */
    public function queueOperation(
        string $operationType,
        array $payload,
        ?string $noteId = null,
        int $maxAttempts = 5
    ): object {
        DB::insert('INSERT INTO joplin_queue_jobs (operation_type, note_id, payload, status, attempts, max_attempts, next_attempt_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $operationType,
            $noteId,
            json_encode($payload),
            'pending',
            0,
            $maxAttempts,
            now(),
            now(),
            now(),
        ]);

        $jobId = (int) DB::getPdo()->lastInsertId();
        $job = DB::selectOne('SELECT * FROM joplin_queue_jobs WHERE id = ?', [$jobId]);

        Log::info('Queued Joplin operation', [
            'job_id' => $jobId,
            'operation' => $operationType,
            'note_id' => $noteId,
        ]);

        return $job;
    }

    /**
     * Get pending jobs ready for processing
     *
     * @param  int  $limit  Maximum number of jobs to return
     */
    public function getPendingJobs(int $limit = 10): \Illuminate\Support\Collection
    {
        return collect(DB::select("
            SELECT * FROM joplin_queue_jobs
            WHERE status = 'pending'
              AND next_attempt_at <= ?
              AND attempts < max_attempts
            ORDER BY next_attempt_at
            LIMIT ?
        ", [now(), $limit]));
    }

    /**
     * Process a queued job
     *
     * @return bool Success status
     */
    public function processJob(object $job, JoplinWriteService $writeService): bool
    {
        $this->markAsProcessing($job->id);

        try {
            $payload = json_decode($job->payload, true);

            $result = match ($job->operation_type) {
                'create_note' => $writeService->createNote(
                    $payload['title'],
                    $payload['content'],
                    $payload['parent_id'] ?? null,
                    $payload['options'] ?? [],
                    true // skipLock
                ),
                'update_note' => $writeService->updateNote(
                    $job->note_id,
                    $payload['updates'],
                    $payload['detect_conflict'] ?? true,
                    true // skipLock
                ),
                'append_note' => $writeService->appendToNote(
                    $job->note_id,
                    $payload['content'],
                    $payload['separator'] ?? "\n\n"
                ),
                'delete_note' => $writeService->deleteNote($job->note_id),
                'create_notebook' => $writeService->createNotebook(
                    $payload['title'],
                    $payload['parent_id'] ?? null
                ),
                default => ['success' => false, 'error' => 'Unknown operation type'],
            };

            if ($result['success']) {
                $this->markAsCompleted($job->id);
                Log::info('Joplin queue job completed', [
                    'job_id' => $job->id,
                    'operation' => $job->operation_type,
                ]);

                return true;
            } else {
                $this->markAsFailed($job->id, $job->attempts, $job->max_attempts, $result['error'] ?? 'Operation failed');

                return false;
            }

        } catch (\Exception $e) {
            $this->markAsFailed($job->id, $job->attempts, $job->max_attempts, $e->getMessage());
            Log::error('Joplin queue job failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Mark job as processing
     */
    private function markAsProcessing(int $jobId): void
    {
        DB::update("UPDATE joplin_queue_jobs SET status = 'processing', updated_at = ? WHERE id = ?", [now(), $jobId]);
    }

    /**
     * Mark job as completed
     */
    private function markAsCompleted(int $jobId): void
    {
        DB::update("UPDATE joplin_queue_jobs SET status = 'completed', completed_at = ?, updated_at = ? WHERE id = ?", [now(), now(), $jobId]);
    }

    /**
     * Mark job as failed with retry logic
     */
    private function markAsFailed(int $jobId, int $currentAttempts, int $maxAttempts, string $error): void
    {
        $newAttempts = $currentAttempts + 1;

        if ($newAttempts >= $maxAttempts) {
            // Final failure
            DB::update("UPDATE joplin_queue_jobs SET status = 'failed', attempts = ?, error_message = ?, updated_at = ? WHERE id = ?", [
                $newAttempts, $error, now(), $jobId,
            ]);
        } else {
            // Schedule retry with exponential backoff
            $backoffSeconds = pow(2, $newAttempts) * 30; // 60s, 120s, 240s, 480s
            $nextAttempt = now()->addSeconds($backoffSeconds);

            DB::update("UPDATE joplin_queue_jobs SET status = 'pending', attempts = ?, error_message = ?, next_attempt_at = ?, updated_at = ? WHERE id = ?", [
                $newAttempts, $error, $nextAttempt, now(), $jobId,
            ]);
        }
    }

    /**
     * Get queue statistics
     */
    public function getStatistics(): array
    {
        $pending = DB::selectOne("SELECT COUNT(*) as count FROM joplin_queue_jobs WHERE status = 'pending'");
        $processing = DB::selectOne("SELECT COUNT(*) as count FROM joplin_queue_jobs WHERE status = 'processing'");
        $completed = DB::selectOne("SELECT COUNT(*) as count FROM joplin_queue_jobs WHERE status = 'completed'");
        $failed = DB::selectOne("SELECT COUNT(*) as count FROM joplin_queue_jobs WHERE status = 'failed'");
        $oldest = DB::selectOne("SELECT created_at FROM joplin_queue_jobs WHERE status = 'pending' ORDER BY created_at LIMIT 1");

        return [
            'pending' => $pending->count ?? 0,
            'processing' => $processing->count ?? 0,
            'completed' => $completed->count ?? 0,
            'failed' => $failed->count ?? 0,
            'oldest_pending' => $oldest ? \Carbon\Carbon::parse($oldest->created_at)->diffForHumans() : null,
        ];
    }
}
