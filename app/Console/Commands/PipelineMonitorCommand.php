<?php

namespace App\Console\Commands;

use App\Services\ScheduledJobService;
use App\Services\SystemConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PipelineMonitorCommand - Pipeline status monitor and stall fixer
 *
 * Runs periodically to report enrichment pipeline backlogs and auto-fix
 * stalled jobs. Does NOT trigger catch-up runs — that's the job of the
 * dedicated scheduled jobs (rag_file_bulk_index, file_thumbnails_generate, etc.)
 *
 * Usage:
 *   php artisan pipeline:monitor          # Check status and fix stalls
 *   php artisan pipeline:monitor --status # Status report only, no actions
 */
class PipelineMonitorCommand extends Command
{
    protected $signature = 'pipeline:monitor
                            {--status : Show status report only, no actions}';

    protected $description = 'Monitor enrichment pipeline backlogs and fix stalled jobs';

    private const LOG_FILE = '/tmp/pipeline-monitor.log';

    private const RUNTIME_POLICY_KEY = 'ops.pipeline_monitor_runtime_policy';

    private ScheduledJobService $scheduledJobService;

    private SystemConfigService $systemConfigService;

    public function __construct(
        ScheduledJobService $scheduledJobService,
        SystemConfigService $systemConfigService
    ) {
        parent::__construct();
        $this->scheduledJobService = $scheduledJobService;
        $this->systemConfigService = $systemConfigService;
    }

    public function handle(): int
    {
        $statusOnly = $this->option('status');
        $runtimePolicy = $this->runtimePolicy();
        if ($runtimePolicy === null) {
            $this->error('Pipeline monitor blocked: required table policy is missing or invalid.');

            return self::FAILURE;
        }

        $status = $this->gatherStatus($runtimePolicy);
        $this->reportStatus($status);

        if ($statusOnly) {
            return 0;
        }

        $fixed = $this->fixStalledJobs($status['stalled_jobs']);

        if ($fixed === 0) {
            $this->log('No stalled jobs found');
        } else {
            $this->log("{$fixed} stalled jobs fixed");
        }

        return 0;
    }

    /**
     * @param  array{default_stall_timeout_minutes:int,recent_run_window_minutes:int,categories:list<string>}  $runtimePolicy
     */
    private function gatherStatus(array $runtimePolicy): array
    {
        $available = $this->availableFileRegistryPredicate();

        $total = (int) DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND {$available}")->c;

        // RAG indexing
        $ragIndexed = 0;
        try {
            $ragIndexed = (int) DB::connection('pgsql_rag')->selectOne(
                "SELECT COUNT(*) as c FROM rag_documents WHERE source_type = 'file_registry'"
            )->c;
        } catch (\Exception $e) {
            $this->log('RAG count failed: '.$e->getMessage());
        }

        // Thumbnails (only thumbnail-capable types: images, videos, PDFs, office docs)
        $thumbsPending = (int) DB::selectOne(
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND {$available} AND thumbnail_generated_at IS NULL AND thumbnail_error IS NULL
             AND extension IN ('jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif','mp4','mov','avi','mkv','webm','wmv','m4v','flv','pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp')"
        )->c;

        // EXIF (images only — EXIF doesn't apply to documents/videos)
        $exifPending = (int) DB::selectOne(
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND {$available} AND (exif_checked IS NULL OR exif_checked = 0) AND extension IN ('jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif')"
        )->c;

        // Faces (images only)
        $facesPending = (int) DB::selectOne(
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND {$available} AND face_scan_at IS NULL AND extension IN ('jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif')"
        )->c;

        // AI analysis (images + documents that are AI-analyzable, exclude permanently skipped)
        $aiPending = (int) DB::selectOne(
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND {$available} AND ai_analyzed_at IS NULL
             AND (ai_analysis_version IS NULL OR (ai_analysis_version != 'skipped' AND ai_analysis_version NOT LIKE 'fail:%' AND ai_analysis_version != 'processing' AND ai_analysis_version NOT LIKE 'processing:%'))
             AND extension IN ('jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif','pdf','doc','docx','rtf','odt','txt','xls','xlsx','csv','ppt','pptx')"
        )->c;

        // Writeback (images with non-EXIF dates at writeback-eligible confidence)
        $writebackPending = (int) DB::selectOne(
            "SELECT COUNT(*) as c FROM file_registry
             WHERE status = 'active'
             AND {$available}
             AND extension IN ('jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif')
             AND date_taken IS NOT NULL
             AND (COALESCE(date_taken_precision, 'exact') = 'exact' OR (date_taken_precision = 'year' AND date_taken_source = 'user_manual'))
             AND date_taken_source NOT LIKE 'exif_%'
             AND (exif_written IS NULL OR exif_written = 0)
             AND date_taken_confidence >= 0.3"
        )->c;

        $categories = $runtimePolicy['categories'];
        $categoryPlaceholders = implode(', ', array_fill(0, count($categories), '?'));

        // Check for stalled jobs using the job timeout or the table-owned fallback.
        $stalledJobs = DB::select(
            "SELECT sj.id, sj.name, sj.last_run_at, sj.timeout_minutes, sj.last_pid, sj.running_pids, sj.running_count
             FROM scheduled_jobs sj
             WHERE sj.last_run_status = 'running'
             AND sj.stall_exempt = 0
             AND COALESCE(sj.job_type, '') <> 'agent_task'
             AND TIMESTAMPDIFF(SECOND, sj.last_run_at, NOW()) > COALESCE(sj.timeout_minutes, ?) * 60
             AND sj.category IN ({$categoryPlaceholders})",
            [$runtimePolicy['default_stall_timeout_minutes'], ...$categories]
        );

        // Recent run visibility uses the same table-owned monitor policy.
        $recentRuns = DB::select(
            "SELECT sj.name, COUNT(*) as run_count, SUM(CASE WHEN sjr.status = 'success' THEN 1 ELSE 0 END) as success_count
             FROM scheduled_job_runs sjr
             JOIN scheduled_jobs sj ON sj.id = sjr.scheduled_job_id
             WHERE TIMESTAMPDIFF(SECOND, sjr.started_at, NOW()) < ?
             AND sj.category IN ({$categoryPlaceholders})
             GROUP BY sj.name",
            [$runtimePolicy['recent_run_window_minutes'] * 60, ...$categories]
        );

        // RAG pending (text docs + images with AI descriptions — not source code/binaries)
        $ragPending = (int) DB::selectOne(
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND {$available} AND rag_indexed_at IS NULL
             AND (extension IN ('pdf','doc','docx','txt','rtf','odt','md','csv','html','htm','xls','xlsx','ppt','pptx')
                  OR (extension IN ('jpg','jpeg','png','gif','bmp','webp','tiff','tif','heic','heif') AND ai_description IS NOT NULL))"
        )->c;

        return [
            'total_files' => $total,
            'rag_indexed' => $ragIndexed,
            'rag_pending' => $ragPending,
            'thumbs_pending' => $thumbsPending,
            'exif_pending' => $exifPending,
            'faces_pending' => $facesPending,
            'ai_pending' => $aiPending,
            'writeback_pending' => $writebackPending,
            'stalled_jobs' => $stalledJobs,
            'recent_runs' => $recentRuns,
            'runtime_policy' => [
                'source' => 'system_configs.'.self::RUNTIME_POLICY_KEY,
                'default_stall_timeout_minutes' => $runtimePolicy['default_stall_timeout_minutes'],
                'recent_run_window_minutes' => $runtimePolicy['recent_run_window_minutes'],
                'category_count' => count($categories),
            ],
            'timestamp' => now()->toDateTimeString(),
        ];
    }

    private function reportStatus(array $status): void
    {
        $total = $status['total_files'];

        $lines = [
            "=== Pipeline Monitor - {$status['timestamp']} ===",
            sprintf('Total files: %s', number_format($total)),
            sprintf('RAG indexed:     %s (pending: %s)', number_format($status['rag_indexed']), number_format($status['rag_pending'])),
            sprintf('Thumbs pending:  %s', number_format($status['thumbs_pending'])),
            sprintf('EXIF pending:    %s', number_format($status['exif_pending'])),
            sprintf('Faces pending:   %s', number_format($status['faces_pending'])),
            sprintf('AI pending:      %s', number_format($status['ai_pending'])),
            sprintf('Writeback pend:  %s', number_format($status['writeback_pending'])),
            sprintf(
                'Monitor policy:    stall fallback %d min; recent window %d min; categories %d',
                $status['runtime_policy']['default_stall_timeout_minutes'],
                $status['runtime_policy']['recent_run_window_minutes'],
                $status['runtime_policy']['category_count']
            ),
        ];

        if (! empty($status['stalled_jobs'])) {
            $lines[] = '';
            $lines[] = 'STALLED JOBS:';
            foreach ($status['stalled_jobs'] as $j) {
                $pidInfo = '';
                if ($j->last_pid) {
                    $alive = $this->scheduledJobService->isProcessAlive($j->last_pid);
                    $pidInfo = " PID:{$j->last_pid}".($alive ? ' ALIVE' : ' DEAD');
                }
                if (($j->running_count ?? 0) > 0) {
                    $pidInfo .= " workers:{$j->running_count}";
                }
                $lines[] = "  - {$j->name} (running since {$j->last_run_at}{$pidInfo})";
            }
        }

        if (! empty($status['recent_runs'])) {
            $lines[] = '';
            $lines[] = sprintf('Runs (last %d minutes):', $status['runtime_policy']['recent_run_window_minutes']);
            foreach ($status['recent_runs'] as $r) {
                $lines[] = "  {$r->name}: {$r->success_count}/{$r->run_count}";
            }
        }

        foreach ($lines as $line) {
            $this->line($line);
        }

        $this->log(implode("\n", $lines));
    }

    /**
     * Fix stalled jobs — PID-aware: verifies process state before acting.
     */
    private function fixStalledJobs(array $stalledJobs): int
    {
        $fixed = 0;

        foreach ($stalledJobs as $job) {
            $pid = $job->last_pid ?? null;
            $action = 'timeout';

            if ($pid) {
                if ($this->scheduledJobService->isProcessAlive($pid)) {
                    // Process still alive but past timeout — kill it
                    $this->scheduledJobService->killProcess($pid);
                    $action = "killed PID {$pid}";
                } else {
                    $action = "dead PID {$pid}";
                }
            }

            $this->warn("Fixing stalled job: {$job->name} ({$action})");
            $this->log("Fixing stalled job: {$job->name} (ID {$job->id}, {$action})");

            DB::update(
                "UPDATE scheduled_jobs SET last_run_status = 'failed', last_pid = NULL, running_pids = NULL, running_count = 0
                 WHERE id = ? AND last_run_status = 'running'",
                [$job->id]
            );

            DB::update(
                "UPDATE scheduled_job_runs SET status = 'failed', completed_at = NOW(),
                 output = CONCAT(COALESCE(output, ''), '\n[Monitor] Stalled after timeout ({$action})')
                 WHERE scheduled_job_id = ? AND status = 'running'",
                [$job->id]
            );

            $fixed++;
        }

        return $fixed;
    }

    private function log(string $message): void
    {
        $line = '['.now()->toDateTimeString().'] '.$message."\n";
        file_put_contents(self::LOG_FILE, $line, FILE_APPEND);
    }

    /**
     * @return array{default_stall_timeout_minutes:int,recent_run_window_minutes:int,categories:list<string>}|null
     */
    private function runtimePolicy(): ?array
    {
        $policy = $this->systemConfigService->get(self::RUNTIME_POLICY_KEY);
        if (! is_array($policy)) {
            return null;
        }

        $defaultTimeout = filter_var(
            $policy['default_stall_timeout_minutes'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1440]]
        );
        $recentWindow = filter_var(
            $policy['recent_run_window_minutes'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1440]]
        );
        $categories = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $category): string => is_string($category) ? trim($category) : '',
                is_array($policy['categories'] ?? null) ? $policy['categories'] : []
            ),
            static fn (string $category): bool => $category !== '' && strlen($category) <= 100
        )));

        if ($defaultTimeout === false || $recentWindow === false || $categories === []) {
            return null;
        }

        return [
            'default_stall_timeout_minutes' => $defaultTimeout,
            'recent_run_window_minutes' => $recentWindow,
            'categories' => $categories,
        ];
    }

    private function availableFileRegistryPredicate(string $alias = 'file_registry'): string
    {
        $prefix = $alias !== '' ? "{$alias}." : '';
        $error = "LOWER(COALESCE({$prefix}thumbnail_error, ''))";

        return "COALESCE({$prefix}verification_failures, 0) = 0
            AND {$error} NOT LIKE '%404%'
            AND {$error} NOT LIKE '%not found%'
            AND {$error} NOT LIKE '%no such file%'
            AND {$error} NOT LIKE '%failed to download source file%'";
    }
}
