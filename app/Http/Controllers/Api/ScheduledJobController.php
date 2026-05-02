<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ScheduledJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ScheduledJobController - API for managing scheduled jobs
 *
 * Provides CRUD operations for the centralized job scheduler.
 */
class ScheduledJobController extends Controller
{
    private ScheduledJobService $service;

    public function __construct(ScheduledJobService $service)
    {
        $this->service = $service;
    }

    /**
     * List all scheduled jobs
     */
    public function index(Request $request): JsonResponse
    {
        $jobs = $this->service->getAllJobs();

        // Apply filters
        if ($module = $request->query('module')) {
            $jobs = array_filter($jobs, fn ($j) => stripos($j->source_module ?? '', $module) !== false);
        }

        if ($status = $request->query('status')) {
            $jobs = match ($status) {
                'enabled' => array_filter($jobs, fn ($j) => $j->enabled),
                'disabled' => array_filter($jobs, fn ($j) => ! $j->enabled),
                'failed' => array_filter($jobs, fn ($j) => $j->last_run_status === 'failed'),
                'running' => array_filter($jobs, fn ($j) => $j->last_run_status === 'running'),
                default => $jobs,
            };
        }

        // Add human-readable schedule description
        $jobs = array_map(function ($job) {
            $job->schedule_description = $this->service->describeCron($job->cron_expression);

            return $job;
        }, $jobs);

        return response()->json([
            'success' => true,
            'data' => array_values($jobs),
        ]);
    }

    /**
     * Get jobs grouped by module
     */
    public function byModule(): JsonResponse
    {
        $grouped = $this->service->getJobsByModule();

        // Add schedule descriptions
        foreach ($grouped as $module => &$jobs) {
            foreach ($jobs as &$job) {
                $job->schedule_description = $this->service->describeCron($job->cron_expression);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $grouped,
        ]);
    }

    /**
     * Get scheduler statistics
     */
    public function stats(): JsonResponse
    {
        $stats = $this->service->getStats();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Get a single job
     */
    public function show(int $id): JsonResponse
    {
        $job = $this->service->getJob($id);

        if (! $job) {
            return response()->json([
                'success' => false,
                'error' => 'Job not found',
            ], 404);
        }

        $job->schedule_description = $this->service->describeCron($job->cron_expression);
        $job->history = $this->service->getJobHistory($id, 10);

        return response()->json([
            'success' => true,
            'data' => $job,
        ]);
    }

    /**
     * Create a new job
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:scheduled_jobs,name',
            'description' => 'nullable|string',
            'job_type' => 'required|in:command,workflow,job_class',
            'command' => 'required|string|max:500',
            'cron_expression' => 'required|string|max:100',
            'enabled' => 'boolean',
            'run_in_background' => 'boolean',
            'without_overlapping' => 'boolean',
            'timeout_minutes' => 'integer|min:1|max:1440',
            'notes' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'source_module' => 'nullable|string|max:100',
        ]);

        // Validate cron expression
        $cronValidation = $this->service->validateCronExpression($validated['cron_expression']);
        if (! $cronValidation['valid']) {
            return response()->json([
                'success' => false,
                'error' => $cronValidation['error'],
            ], 422);
        }

        try {
            $id = $this->service->createJob($validated);

            Log::info('Scheduled job created', ['id' => $id, 'name' => $validated['name']]);

            return response()->json([
                'success' => true,
                'data' => ['id' => $id],
                'message' => 'Job created successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create scheduled job', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to create job: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a job
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $job = $this->service->getJob($id);
        if (! $job) {
            return response()->json([
                'success' => false,
                'error' => 'Job not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'string|max:255|unique:scheduled_jobs,name,'.$id,
            'description' => 'nullable|string',
            'job_type' => 'in:command,workflow,job_class',
            'command' => 'string|max:500',
            'cron_expression' => 'string|max:100',
            'enabled' => 'boolean',
            'run_in_background' => 'boolean',
            'without_overlapping' => 'boolean',
            'timeout_minutes' => 'integer|min:1|max:1440',
            'notes' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'source_module' => 'nullable|string|max:100',
        ]);

        // Validate cron expression if provided
        if (isset($validated['cron_expression'])) {
            $cronValidation = $this->service->validateCronExpression($validated['cron_expression']);
            if (! $cronValidation['valid']) {
                return response()->json([
                    'success' => false,
                    'error' => $cronValidation['error'],
                ], 422);
            }
        }

        try {
            $this->service->updateJob($id, $validated);

            Log::info('Scheduled job updated', ['id' => $id]);

            return response()->json([
                'success' => true,
                'message' => 'Job updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update scheduled job', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to update job: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a job
     */
    public function destroy(int $id): JsonResponse
    {
        $job = $this->service->getJob($id);
        if (! $job) {
            return response()->json([
                'success' => false,
                'error' => 'Job not found',
            ], 404);
        }

        try {
            $this->service->deleteJob($id);

            Log::info('Scheduled job deleted', ['id' => $id, 'name' => $job->name]);

            return response()->json([
                'success' => true,
                'message' => 'Job deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete scheduled job', ['id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to delete job: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle job enabled status
     */
    public function toggle(int $id): JsonResponse
    {
        $newStatus = $this->service->toggleJob($id);

        if ($newStatus === null) {
            return response()->json([
                'success' => false,
                'error' => 'Job not found',
            ], 404);
        }

        Log::info('Scheduled job toggled', ['id' => $id, 'enabled' => $newStatus]);

        return response()->json([
            'success' => true,
            'data' => ['enabled' => $newStatus],
            'message' => $newStatus ? 'Job enabled' : 'Job disabled',
        ]);
    }

    /**
     * Run a job immediately
     */
    public function run(int $id): JsonResponse
    {
        $job = $this->service->getJob($id);
        if (! $job) {
            return response()->json([
                'success' => false,
                'error' => 'Job not found',
            ], 404);
        }

        Log::warning('Manual scheduled job execution blocked from UI/API', ['id' => $id, 'name' => $job->name]);

        return response()->json([
            'success' => false,
            'error' => 'Manual scheduled job execution from the UI/API is disabled. Use production CLI or scheduler control paths instead.',
        ], 403);
    }

    /**
     * Get job run history
     */
    public function history(int $id, Request $request): JsonResponse
    {
        $job = $this->service->getJob($id);
        if (! $job) {
            return response()->json([
                'success' => false,
                'error' => 'Job not found',
            ], 404);
        }

        $limit = min((int) $request->query('limit', 50), 100);
        $history = $this->service->getJobHistory($id, $limit);

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    /**
     * Validate a cron expression
     */
    public function validateCron(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cron_expression' => 'required|string|max:100',
        ]);

        $result = $this->service->validateCronExpression($validated['cron_expression']);
        $result['description'] = $result['valid']
            ? $this->service->describeCron($validated['cron_expression'])
            : null;

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Get list of available modules
     */
    public function modules(): JsonResponse
    {
        $stats = $this->service->getStats();

        $modules = array_map(function ($m) {
            return [
                'name' => $m->module,
                'job_count' => (int) $m->job_count,
                'enabled_count' => (int) $m->enabled_count,
            ];
        }, $stats['by_module']);

        return response()->json([
            'success' => true,
            'data' => $modules,
        ]);
    }

    /**
     * Clean up old run history
     */
    public function cleanupHistory(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $deleted = $this->service->cleanupHistory($days);

        Log::info('Scheduled job history cleanup', ['days' => $days, 'deleted' => $deleted]);

        return response()->json([
            'success' => true,
            'data' => ['deleted' => $deleted],
            'message' => "Deleted {$deleted} old run records",
        ]);
    }
}
