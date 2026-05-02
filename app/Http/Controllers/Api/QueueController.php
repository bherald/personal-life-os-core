<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;
use Exception;

class QueueController extends Controller
{
    /**
     * Get all failed jobs
     */
    public function getFailedJobs(): JsonResponse
    {
        try {
            // Get failed jobs using raw SQL
            $sql = "SELECT * FROM failed_jobs ORDER BY failed_at DESC";
            $failedJobs = DB::select($sql);

            $jobs = array_map(function ($job) {
                return [
                    'id' => $job->id,
                    'uuid' => $job->uuid ?? null,
                    'connection' => $job->connection,
                    'queue' => $job->queue,
                    'payload' => $job->payload,
                    'exception' => $job->exception,
                    'failed_at' => $job->failed_at
                ];
            }, $failedJobs);

            return response()->json([
                'success' => true,
                'data' => $jobs
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FETCH_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    /**
     * Retry a failed job
     */
    public function retryJob(int $id): JsonResponse
    {
        try {
            // Check if job exists using raw SQL
            $sql = "SELECT id FROM failed_jobs WHERE id = ? LIMIT 1";
            $jobs = DB::select($sql, [$id]);

            if (empty($jobs)) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Failed job not found']
                ], 404);
            }

            $failedJob = app('queue.failer')->find($id);
            if (!$failedJob) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Failed job payload not found']
                ], 404);
            }

            Queue::connection($failedJob->connection)->pushRaw(
                $failedJob->payload,
                $failedJob->queue
            );

            app('queue.failer')->forget($id);

            return response()->json([
                'success' => true,
                'message' => 'Job queued for retry'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'RETRY_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    /**
     * Delete a failed job
     */
    public function deleteJob(int $id): JsonResponse
    {
        try {
            $deleted = DB::delete("DELETE FROM failed_jobs WHERE id = ?", [$id]);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'error' => ['code' => 'NOT_FOUND', 'message' => 'Failed job not found']
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Failed job deleted'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'DELETE_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    /**
     * Get queue statistics
     */
    public function getStats(): JsonResponse
    {
        try {
            $failedCount = (int) (DB::select("SELECT COUNT(*) as count FROM failed_jobs")[0]->count ?? 0);
            $failedCount24h = (int) (DB::select(
                "SELECT COUNT(*) as count FROM failed_jobs WHERE failed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            )[0]->count ?? 0);

            $pendingCount = $this->getPendingQueueCount();

            return response()->json([
                'success' => true,
                'data' => [
                    'failed_jobs' => $failedCount,
                    'failed_jobs_24h' => $failedCount24h,
                    'pending_jobs' => $pendingCount,
                    'queue_connection' => config('queue.default'),
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'STATS_FAILED', 'message' => $e->getMessage()]
            ], 500);
        }
    }

    private function getPendingQueueCount(): int
    {
        if (config('queue.default') === 'redis') {
            $redis = Redis::connection();
            $queues = array_values(array_unique(array_filter([
                config('queue.connections.redis.queue', 'default'),
                'high',
                'default',
                'low',
                'workflow',
                'long-running',
                'speculative',
            ])));
            $total = 0;

            foreach ($queues as $queue) {
                $total += (int) ($redis->llen("queues:{$queue}") ?? 0);
            }

            return $total;
        }

        $sql = "SELECT COUNT(*) as count FROM jobs";
        return (int) (DB::select($sql)[0]->count ?? 0);
    }
}
