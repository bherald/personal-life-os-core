<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * AIOperationsService - AI workload management and pipeline oversight
 *
 * Provides tools for the AI Operations Agent to monitor and adjust
 * enrichment pipelines, AI service capacity, and scheduled job configs.
 */
class AIOperationsService
{
    public function isClaudeCliEnabled(): bool
    {
        try {
            $row = DB::selectOne(
                'SELECT is_active FROM llm_instances WHERE instance_id = ? LIMIT 1',
                ['claude_cli']
            );

            return (bool) ($row->is_active ?? false);
        } catch (\Throwable $e) {
            Log::debug('AIOperationsService: Claude CLI enabled check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function buildClaudeCliEnv(): ?array
    {
        $token = config('services.anthropic.cli_oauth_token');
        if (! $token) {
            return null;
        }

        $env = $_ENV;
        foreach (['HOME', 'PATH', 'USER', 'LOGNAME', 'SHELL', 'LANG', 'TERM', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'PWD'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== null && $value !== '') {
                $env[$key] = $value;
            }
        }

        $env['CLAUDE_CODE_OAUTH_TOKEN'] = $token;

        return $env;
    }

    /** @see config/file_types.php */

    /**
     * Get comprehensive pipeline status with backlog counts and rates
     */
    public function getPipelineStatus(): array
    {
        $total = (int) DB::selectOne("SELECT COUNT(*) as c FROM file_registry WHERE status = 'active'")->c;
        $imageExts = "'".implode("','", config('file_types.image'))."'";

        // RAG indexing
        $ragIndexed = 0;
        try {
            $ragIndexed = (int) DB::connection('pgsql_rag')->selectOne(
                "SELECT COUNT(*) as c FROM rag_documents WHERE source_type = 'file_registry'"
            )->c;
        } catch (\Exception $e) {
            Log::debug('AIOperationsService: RAG document count query failed', ['error' => $e->getMessage()]);
        }

        // Thumbnails
        $thumbsDone = (int) DB::selectOne(
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND thumbnail_generated_at IS NOT NULL"
        )->c;

        // EXIF (images only — the actual enrichment scope)
        $exifDone = (int) DB::selectOne(
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND extension IN ({$imageExts}) AND date_taken IS NOT NULL"
        )->c;
        $totalImages = (int) DB::selectOne(
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND extension IN ({$imageExts})"
        )->c;

        // Face detection (images only)
        $facesDone = (int) DB::selectOne(
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND face_scan_at IS NOT NULL AND extension IN ({$imageExts})"
        )->c;

        // AI description
        $aiDone = (int) DB::selectOne(
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND ai_analyzed_at IS NOT NULL"
        )->c;

        // Writeback
        $writebackDone = (int) DB::selectOne('SELECT COUNT(*) as c FROM file_registry WHERE exif_written = 1')->c;
        $writebackEligible = (int) DB::selectOne(
            "SELECT COUNT(*) as c FROM file_registry WHERE status = 'active' AND date_taken IS NOT NULL
             AND date_taken_source NOT LIKE 'exif_%' AND date_taken_confidence >= 0.3"
        )->c;

        // Perceptual hash
        $phashDone = (int) DB::selectOne(
            "SELECT COUNT(DISTINCT fr.id) as c FROM file_registry fr
             INNER JOIN file_registry_perceptual_hashes ph ON fr.id = ph.file_registry_id
             WHERE fr.status='active' AND fr.extension IN ({$imageExts})"
        )->c;

        // Recent processing rates (last 24 hours)
        $rates = $this->getProcessingRates();

        return [
            'total_files' => $total,
            'total_images' => $totalImages,
            'pipelines' => [
                'rag_index' => ['done' => $ragIndexed, 'total' => $total, 'pending' => $total - $ragIndexed],
                'thumbnails' => ['done' => $thumbsDone, 'total' => $total, 'pending' => $total - $thumbsDone],
                'exif_extract' => ['done' => $exifDone, 'total' => $totalImages, 'pending' => $totalImages - $exifDone],
                'face_detection' => ['done' => $facesDone, 'total' => $totalImages, 'pending' => $totalImages - $facesDone],
                'ai_description' => ['done' => $aiDone, 'total' => $total, 'pending' => $total - $aiDone],
                'exif_writeback' => ['done' => $writebackDone, 'total' => $writebackEligible, 'pending' => $writebackEligible - $writebackDone],
                'perceptual_hash' => ['done' => $phashDone, 'total' => $totalImages, 'pending' => $totalImages - $phashDone],
            ],
            'processing_rates' => $rates,
            'timestamp' => now()->toDateTimeString(),
        ];
    }

    /**
     * Get processing rates from recent job runs
     */
    public function getProcessingRates(): array
    {
        $runs = DB::select("
            SELECT sj.name, COUNT(*) as run_count,
                   SUM(CASE WHEN sjr.status = 'success' THEN 1 ELSE 0 END) as success_count,
                   AVG(TIMESTAMPDIFF(SECOND, sjr.started_at, sjr.completed_at)) as avg_duration_sec
            FROM scheduled_job_runs sjr
            JOIN scheduled_jobs sj ON sj.id = sjr.scheduled_job_id
            WHERE sjr.started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND sj.category IN ('E13-FileRegistry', 'Files', 'File Management')
            GROUP BY sj.name
        ");

        $rates = [];
        foreach ($runs as $run) {
            $rates[$run->name] = [
                'runs_24h' => (int) $run->run_count,
                'success_rate' => $run->run_count > 0 ? round($run->success_count / $run->run_count * 100, 1) : 0,
                'avg_duration_sec' => round($run->avg_duration_sec ?? 0),
            ];
        }

        return $rates;
    }

    /**
     * Get AI provider capacity and utilization
     */
    public function getAICapacity(): array
    {
        // Ollama instances
        $instances = DB::select('
            SELECT instance_id, instance_name, base_url, is_healthy, health_score,
                   total_requests, total_failures, success_rate, circuit_state,
                   max_concurrent, avg_response_ms
            FROM llm_instances WHERE is_active = 1
        ');

        // Claude CLI slot usage
        $claudeSlots = Cache::get('claude_cli_slots', []);
        $activeSlots = count(array_filter($claudeSlots, fn ($s) => ! empty($s)));
        $maxClaudeSlots = (int) config('agents.claude_absolute_max', 20);

        // GPU lock status
        $ollamaBusy = Cache::has('ollama_busy_lock');
        $whisperBusy = Cache::has('whisper_gpu_lock');

        // Check Ollama model availability
        $ollamaModels = [];
        foreach ($instances as $inst) {
            if ($inst->base_url && str_contains($inst->instance_id, 'ollama')) {
                try {
                    $response = Http::connectTimeout(5)->timeout(5)->get(rtrim($inst->base_url, '/').'/api/tags');
                    if ($response->successful()) {
                        $data = $response->json();
                        $ollamaModels[$inst->instance_id] = array_map(
                            fn ($m) => $m['name'],
                            $data['models'] ?? []
                        );
                    } else {
                        $ollamaModels[$inst->instance_id] = ['error' => 'HTTP '.$response->status()];
                    }
                } catch (\Exception $e) {
                    $ollamaModels[$inst->instance_id] = ['error' => $e->getMessage()];
                }
            }
        }

        return [
            'instances' => array_map(fn ($i) => (array) $i, $instances),
            'claude_slots' => [
                'active' => $activeSlots,
                'max' => $maxClaudeSlots,
                'available' => max(0, $maxClaudeSlots - $activeSlots),
            ],
            'gpu' => [
                'ollama_busy' => $ollamaBusy,
                'whisper_busy' => $whisperBusy,
            ],
            'ollama_models' => $ollamaModels,
        ];
    }

    /**
     * Get scheduled job configs for enrichment pipelines
     */
    public function getEnrichmentJobConfigs(): array
    {
        $jobs = DB::select("
            SELECT id, name, command, cron_expression, enabled, timeout_minutes,
                   last_run_at, last_run_status, next_run_at, run_in_background
            FROM scheduled_jobs
            WHERE category IN ('E13-FileRegistry', 'Files', 'File Management')
            AND enabled = 1
            ORDER BY name
        ");

        return array_map(fn ($j) => (array) $j, $jobs);
    }

    /**
     * Adjust a scheduled job's batch size or frequency
     *
     * Only modifies enrichment pipeline jobs. Validates limits.
     */
    public function adjustJobConfig(int $jobId, array $changes): array
    {
        // Verify it's an enrichment job
        $job = DB::selectOne("
            SELECT id, name, command, cron_expression, timeout_minutes, category
            FROM scheduled_jobs
            WHERE id = ? AND category IN ('E13-FileRegistry', 'Files', 'File Management')
        ", [$jobId]);

        if (! $job) {
            return ['success' => false, 'error' => 'Job not found or not an enrichment pipeline job'];
        }

        $updates = [];
        $params = [];

        // Adjust batch limit in command
        if (isset($changes['limit'])) {
            $newLimit = min(max((int) $changes['limit'], 10), 5000); // 10-5000 range
            $newCommand = preg_replace('/--limit=\d+/', "--limit={$newLimit}", $job->command);
            if ($newCommand !== $job->command) {
                $updates[] = 'command = ?';
                $params[] = $newCommand;
            }
        }

        // Adjust cron expression
        if (isset($changes['cron_expression'])) {
            $cron = $changes['cron_expression'];
            // Validate cron expression
            try {
                new \Cron\CronExpression($cron);
                $updates[] = 'cron_expression = ?';
                $params[] = $cron;
            } catch (\Exception $e) {
                return ['success' => false, 'error' => 'Invalid cron expression: '.$e->getMessage()];
            }
        }

        // Adjust timeout
        if (isset($changes['timeout_minutes'])) {
            $timeout = min(max((int) $changes['timeout_minutes'], 5), 120); // 5-120 min
            $updates[] = 'timeout_minutes = ?';
            $params[] = $timeout;
        }

        if (empty($updates)) {
            return ['success' => false, 'error' => 'No valid changes provided'];
        }

        $params[] = $jobId;
        DB::update('UPDATE scheduled_jobs SET '.implode(', ', $updates).', updated_at = NOW() WHERE id = ?', $params);

        // Recalculate next_run_at if cron changed
        if (isset($changes['cron_expression'])) {
            $nextRun = (new \Cron\CronExpression($changes['cron_expression']))
                ->getNextRunDate(now()->toDateTimeImmutable())
                ->format('Y-m-d H:i:s');
            DB::update('UPDATE scheduled_jobs SET next_run_at = ? WHERE id = ?', [$nextRun, $jobId]);
        }

        Log::info("AIOperationsService: Job {$job->name} adjusted", [
            'job_id' => $jobId,
            'changes' => $changes,
        ]);

        return [
            'success' => true,
            'job_name' => $job->name,
            'changes_applied' => $changes,
        ];
    }

    /**
     * Check Claude CLI authentication status. Alerts via Pushover if not logged in.
     *
     * Health-only exception to the Claude boundary: inference must stay inside
     * AIService/AIRouter, while this probe may run `claude auth status`.
     */
    public function checkClaudeCliAuth(): array
    {
        if (! $this->isClaudeCliEnabled()) {
            return [
                'available' => false,
                'logged_in' => false,
                'auth_method' => 'disabled',
                'disabled' => true,
            ];
        }

        $claudePath = config('services.anthropic.cli_path', 'claude');
        $process = null;
        $pipes = [];

        // Pass OAuth token from Laravel env to subprocess (systemd doesn't have .bashrc)
        $env = $this->buildClaudeCliEnv();

        try {
            $descriptorspec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(
                [$claudePath, 'auth', 'status'],
                $descriptorspec,
                $pipes,
                null,
                $env
            );

            if (! is_resource($process)) {
                return ['available' => false, 'error' => 'Failed to start Claude CLI'];
            }

            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $timeoutSeconds = 10.0;
            $deadline = microtime(true) + $timeoutSeconds;
            $output = '';
            while (microtime(true) < $deadline) {
                $read = [$pipes[1], $pipes[2]];
                $w = $e = [];
                $remaining = max(0.0, $deadline - microtime(true));
                $seconds = (int) floor($remaining);
                $microseconds = (int) (($remaining - $seconds) * 1_000_000);
                $changed = @stream_select($read, $w, $e, $seconds, $microseconds);
                if ($changed === false) {
                    break;
                }

                if ($changed > 0) {
                    foreach ($read as $pipe) {
                        $chunk = fread($pipe, 8192);
                        if ($chunk !== false && $chunk !== '') {
                            $output .= $chunk;
                        }
                    }
                }
                if (feof($pipes[1]) && feof($pipes[2])) {
                    break;
                }
            }
            $output .= stream_get_contents($pipes[1]) ?: '';
            $output .= stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);

            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
                proc_close($process);
                $process = null;

                return ['available' => false, 'error' => 'Claude CLI auth status timed out'];
            }

            proc_close($process);
            $process = null;

            // claude auth status may output text before/after JSON — extract the JSON object
            $data = json_decode(trim($output), true);
            if ($data === null && preg_match('/\{[^}]*"loggedIn"[^}]*\}/', $output, $jsonMatch)) {
                $data = json_decode($jsonMatch[0], true);
            }

            $loggedIn = $data['loggedIn'] ?? false;
            $authMethod = $data['authMethod'] ?? 'unknown';

            if (! $loggedIn) {
                // Log only — daily report and pre-screen cover alerting.
                // Pushover removed: subprocess env issues cause false negatives,
                // producing contradictory alerts (title says NOT LOGGED IN, body shows loggedIn:true).
                Log::warning('AIOperations: Claude CLI auth check returned not-logged-in', [
                    'output' => mb_substr($output, 0, 500),
                    'parsed' => $data,
                ]);
            }

            return [
                'available' => true,
                'logged_in' => $loggedIn,
                'auth_method' => $authMethod,
                'raw' => $data,
            ];
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (is_resource($process)) {
                $status = proc_get_status($process);
                if (($status['running'] ?? false) === true) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }
        }
    }

    /**
     * Check GPU utilization via nvidia-smi
     */
    public function getGPUStatus(): array
    {
        $result = Process::timeout(5)->run([
            'nvidia-smi',
            '--query-gpu=utilization.gpu,utilization.memory,memory.used,memory.total',
            '--format=csv,noheader,nounits',
        ]);

        if (! $result->successful() || trim($result->output()) === '') {
            return ['available' => false, 'error' => 'nvidia-smi not available'];
        }

        $parts = array_map('trim', explode(',', trim($result->output())));

        return [
            'available' => true,
            'gpu_utilization_pct' => (int) ($parts[0] ?? 0),
            'memory_utilization_pct' => (int) ($parts[1] ?? 0),
            'memory_used_mb' => (int) ($parts[2] ?? 0),
            'memory_total_mb' => (int) ($parts[3] ?? 0),
        ];
    }

    /**
     * Get stalled jobs that need intervention
     */
    public function getStalledJobs(): array
    {
        $stalled = DB::select("
            SELECT id, name, command, last_run_at, last_run_status, timeout_minutes
            FROM scheduled_jobs
            WHERE last_run_status = 'running'
            AND stall_exempt = 0
            AND COALESCE(job_type, '') <> 'agent_task'
            AND last_run_at < DATE_SUB(NOW(), INTERVAL COALESCE(timeout_minutes, 30) MINUTE)
            AND enabled = 1
        ");

        return array_map(fn ($j) => (array) $j, $stalled);
    }

    /**
     * Fix a stalled job by resetting its status
     */
    public function fixStalledJob(int $jobId): array
    {
        $job = DB::selectOne("
            SELECT id, name, last_run_status, last_run_at, timeout_minutes
            FROM scheduled_jobs
            WHERE id = ?
              AND last_run_status = 'running'
              AND stall_exempt = 0
              AND COALESCE(job_type, '') <> 'agent_task'
        ", [$jobId]);

        if (! $job) {
            return ['success' => false, 'error' => 'Job not found or not in running state'];
        }

        DB::update("UPDATE scheduled_jobs SET last_run_status = 'failed' WHERE id = ?", [$jobId]);
        DB::update("
            UPDATE scheduled_job_runs SET status = 'failed', completed_at = NOW(),
                   output = CONCAT(COALESCE(output, ''), '\n[AI-Ops] Marked as stalled')
            WHERE scheduled_job_id = ? AND status = 'running'
        ", [$jobId]);

        Log::info("AIOperationsService: Fixed stalled job {$job->name}", ['job_id' => $jobId]);

        return ['success' => true, 'job_name' => $job->name, 'was_running_since' => $job->last_run_at];
    }

    /**
     * Health check across all agent_task scheduled jobs.
     *
     * Queries scheduled_jobs, scheduled_job_runs, and agent_episodes to detect
     * silent failures: zero-result outputs, missed schedules, stuck identical
     * output, tool failures, and duration anomalies.
     */
    public function getAgentHealthCheck(): array
    {
        $agents = DB::select("
            SELECT id, name, command, cron_expression, enabled,
                   last_run_at, last_run_status, run_count, fail_count, next_run_at
            FROM scheduled_jobs
            WHERE job_type = 'agent_task'
            ORDER BY name
        ");

        $result = ['agents' => [], 'alerts' => [], 'summary' => ''];
        $statusCounts = ['healthy' => 0, 'degraded' => 0, 'critical' => 0, 'disabled' => 0, 'no_data' => 0];

        foreach ($agents as $agent) {
            $agentId = $this->extractAgentId($agent->command);
            $entry = [
                'agent_id' => $agentId,
                'job_name' => $agent->name,
                'enabled' => (bool) $agent->enabled,
                'last_run_at' => $agent->last_run_at,
                'last_status' => $agent->last_run_status,
                'runs_7d' => 0,
                'success_rate_7d' => 0.0,
                'avg_duration_sec' => 0,
                'status' => 'healthy',
                'issues' => [],
            ];

            if (! $agent->enabled) {
                $entry['status'] = 'disabled';
                $statusCounts['disabled']++;
                $result['agents'][] = $entry;

                continue;
            }

            // Last 5 runs
            $recentRuns = DB::select('
                SELECT status, output, TIMESTAMPDIFF(SECOND, started_at, completed_at) as duration_seconds,
                       started_at, completed_at
                FROM scheduled_job_runs
                WHERE scheduled_job_id = ?
                ORDER BY started_at DESC LIMIT 5
            ', [$agent->id]);

            if (empty($recentRuns)) {
                $entry['status'] = 'no_data';
                $isOverdue = ! empty($agent->next_run_at) && strtotime($agent->next_run_at) < time();

                if ($isOverdue) {
                    $entry['status'] = 'degraded';
                    $entry['issues'][] = [
                        'issue' => 'overdue_no_recent_runs',
                        'severity' => 'warning',
                        'message' => 'Agent is overdue and has no recent run history',
                        'sample_output' => null,
                    ];
                    $result['alerts'][] = [
                        'issue' => 'overdue_no_recent_runs',
                        'severity' => 'warning',
                        'message' => 'Agent is overdue and has no recent run history',
                        'sample_output' => null,
                        'agent_id' => $agentId,
                    ];
                    $statusCounts['degraded']++;
                } else {
                    $statusCounts['no_data']++;
                }

                $result['agents'][] = $entry;

                continue;
            }

            // 7-day stats
            $stats7d = DB::selectOne("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successes,
                       AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_dur
                FROM scheduled_job_runs
                WHERE scheduled_job_id = ?
                AND started_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            ", [$agent->id]);

            $entry['runs_7d'] = (int) ($stats7d->total ?? 0);
            $entry['success_rate_7d'] = $entry['runs_7d'] > 0
                ? round(($stats7d->successes / $entry['runs_7d']) * 100, 1)
                : 0.0;
            $entry['avg_duration_sec'] = (int) round($stats7d->avg_dur ?? 0);

            // Recent episodes
            $episodes = DB::select('
                SELECT event_type, summary, details, duration_ms, created_at
                FROM agent_episodes
                WHERE agent_id = ?
                AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY created_at DESC LIMIT 20
            ', [$agentId]);

            // --- Heuristic checks ---
            $issues = [];

            // 1. Zero-result output detection
            $zeroResultCount = 0;
            $sampleOutput = null;
            $successRuns = array_filter($recentRuns, fn ($r) => $r->status === 'success');
            foreach (array_slice($successRuns, 0, 3) as $run) {
                if ($run->output && $this->isZeroResultOutput($run->output)) {
                    $zeroResultCount++;
                    $sampleOutput = $sampleOutput ?? mb_substr($run->output, 0, 200);
                }
            }
            if ($zeroResultCount >= 3) {
                $issues[] = ['issue' => 'zero_result_output', 'severity' => 'critical',
                    'message' => "Last {$zeroResultCount} successful runs produced zero/empty results",
                    'sample_output' => $sampleOutput];
            } elseif ($zeroResultCount >= 1) {
                $issues[] = ['issue' => 'zero_result_output', 'severity' => 'warning',
                    'message' => "{$zeroResultCount} of last 3 successful runs produced zero/empty results",
                    'sample_output' => $sampleOutput];
            }

            // 2. Missed schedule detection
            if ($agent->last_run_at && $agent->cron_expression) {
                try {
                    $cron = new \Cron\CronExpression($agent->cron_expression);
                    $expectedInterval = $cron->getNextRunDate(
                        new \DateTimeImmutable($agent->last_run_at)
                    )->getTimestamp() - strtotime($agent->last_run_at);
                    $actualGap = time() - strtotime($agent->last_run_at);
                    if ($expectedInterval > 0 && $actualGap > $expectedInterval * 2) {
                        $hoursLate = round(($actualGap - $expectedInterval) / 3600, 1);
                        $issues[] = ['issue' => 'missed_schedule', 'severity' => 'warning',
                            'message' => "Last run was {$hoursLate}h overdue (interval: ".round($expectedInterval / 3600, 1).'h)',
                            'sample_output' => null];
                    }
                } catch (\Exception $e) {
                    Log::debug('AIOperationsService: agent cron expression parse failed', ['error' => $e->getMessage()]);
                }
            }

            // 3. All tools failing — check episodes for error events
            $errorEpisodes = array_filter($episodes, fn ($ep) => $ep->event_type === 'error');
            $totalRecentEpisodes = count($episodes);
            if ($totalRecentEpisodes > 0 && count($errorEpisodes) > $totalRecentEpisodes * 0.5) {
                $issues[] = ['issue' => 'high_error_rate', 'severity' => count($errorEpisodes) > 5 ? 'critical' : 'warning',
                    'message' => count($errorEpisodes)." of {$totalRecentEpisodes} recent episodes are errors",
                    'sample_output' => $errorEpisodes[0]->summary ?? null];
            }

            // 4. Stuck identical output — compare last 3 outputs
            $outputs = array_filter(array_map(fn ($r) => $r->output, array_slice($recentRuns, 0, 3)));
            if (count($outputs) >= 3) {
                $outputs = array_values($outputs);
                $sim01 = 0;
                $sim02 = 0;
                similar_text($outputs[0], $outputs[1], $sim01);
                similar_text($outputs[0], $outputs[2], $sim02);
                if ($sim01 > 95 && $sim02 > 95) {
                    $issues[] = ['issue' => 'stuck_identical_output', 'severity' => 'warning',
                        'message' => 'Last 3 run outputs are >95% identical (similarity: '.round(($sim01 + $sim02) / 2, 1).'%)',
                        'sample_output' => mb_substr($outputs[0], 0, 200)];
                }
            }

            // 5. Duration anomaly — latest vs avg of last 5
            $durations = array_filter(array_map(fn ($r) => $r->duration_seconds, $recentRuns));
            if (count($durations) >= 2) {
                $latest = reset($durations);
                $avg = array_sum($durations) / count($durations);
                if ($avg > 0 && ($latest > $avg * 10 || ($latest > 0 && $latest < $avg * 0.1))) {
                    $ratio = round($latest / $avg, 1);
                    $issues[] = ['issue' => 'duration_anomaly', 'severity' => 'info',
                        'message' => "Latest duration ({$latest}s) is {$ratio}x the average ({$avg}s)",
                        'sample_output' => null];
                }
            }

            // Determine overall status
            $severities = array_column($issues, 'severity');
            if (in_array('critical', $severities)) {
                $entry['status'] = 'critical';
            } elseif (in_array('warning', $severities)) {
                $entry['status'] = 'degraded';
            }

            $entry['issues'] = $issues;
            $statusCounts[$entry['status']]++;
            $result['agents'][] = $entry;

            // Add to global alerts
            foreach ($issues as $issue) {
                $issue['agent_id'] = $agentId;
                $result['alerts'][] = $issue;
            }
        }

        $total = count($agents);
        $parts = [];
        foreach ($statusCounts as $status => $count) {
            if ($count > 0) {
                $parts[] = "{$count} {$status}";
            }
        }
        $result['summary'] = "{$total} agents checked. ".implode(', ', $parts).'.';

        return $result;
    }

    /**
     * Extract agent ID from a scheduled_jobs command string.
     * e.g. "agent:run ai-ops --notify" → "ai-ops"
     */
    private function extractAgentId(string $command): string
    {
        if (preg_match('/agent:run\s+(\S+)/', $command, $m)) {
            return $m[1];
        }

        // Fallback: return the command itself trimmed
        return trim($command);
    }

    /**
     * Check if output text indicates zero/empty results.
     */
    private function isZeroResultOutput(string $output): bool
    {
        // Match patterns like "0 persons", "0 documents", "0 hints", "0 results", "0 records"
        if (preg_match('/\b0\s+(persons?|documents?|hints?|results?|records?|items?|matches?|files?)\b/i', $output)) {
            return true;
        }
        // Match "empty tree", "no data", "no results", "nothing found"
        if (preg_match('/\b(empty\s+tree|no\s+data|no\s+results|nothing\s+found|no\s+persons?\s+found)\b/i', $output)) {
            return true;
        }

        return false;
    }

    /**
     * Discover available models on all configured LLM providers and compare against DB.
     *
     * Checks:
     * - Ollama instances: GET /api/tags to list available models
     * - Claude CLI: runs `claude --version` (no model list endpoint, reports CLI version)
     * - External APIs (Groq, OpenRouter): hit model listing endpoints
     *
     * Returns per-provider diff: new models not in supported_models, and models in DB that
     * are no longer available. Creates review queue items when diffs are detected.
     */
    public function checkModelUpdates(): array
    {
        $results = [];
        $recommendations = [];

        try {
            $providers = DB::select('
                SELECT instance_id, instance_name, instance_type, base_url, api_key, api_key_env,
                       supported_models, is_active
                FROM llm_instances
                WHERE is_active = 1
                ORDER BY priority ASC
            ');

            foreach ($providers as $provider) {
                $dbModels = json_decode($provider->supported_models ?? '[]', true) ?: [];
                $available = [];
                $error = null;

                if ($provider->instance_type === 'ollama') {
                    // Ollama: GET /api/tags
                    try {
                        $url = rtrim($provider->base_url, '/').'/api/tags';
                        $response = Http::connectTimeout(5)->timeout(10)->get($url);
                        if ($response->successful()) {
                            $data = $response->json();
                            $available = array_column($data['models'] ?? [], 'name');
                        } else {
                            $error = 'HTTP '.$response->status();
                        }
                    } catch (\Throwable $e) {
                        $error = $e->getMessage();
                    }

                } elseif ($provider->instance_type === 'claude_cli') {
                    // Claude CLI: report CLI version, no model listing endpoint
                    try {
                        $claudePath = config('services.anthropic.cli_path', 'claude');
                        $versionResult = \Illuminate\Support\Facades\Process::timeout(10)->run([$claudePath, '--version']);
                        $version = trim($versionResult->output().' '.$versionResult->errorOutput());
                        $available = ['(see CLI version)'];
                        $results[$provider->instance_id] = [
                            'name' => $provider->instance_name,
                            'type' => $provider->instance_type,
                            'cli_version' => trim($version),
                            'db_models' => $dbModels,
                            'note' => 'Claude CLI has no model list endpoint. Use DB models config to set roles.',
                            'error' => null,
                        ];

                        continue;
                    } catch (\Throwable $e) {
                        $error = $e->getMessage();
                    }

                } elseif ($provider->instance_type === 'custom' || $provider->instance_type === 'google_gemini') {
                    // External APIs with OpenAI-compatible /models endpoint (Groq, OpenRouter, etc.)
                    $apiKey = $provider->api_key ?: $this->resolveRuntimeEnvValue($provider->api_key_env ?? null);
                    if ($apiKey && $provider->base_url) {
                        try {
                            $response = Http::withToken($apiKey)
                                ->connectTimeout(5)
                                ->timeout(15)
                                ->get(rtrim($provider->base_url, '/').'/models');

                            if ($response->successful()) {
                                $data = $response->json();
                                // OpenAI-compatible format: data[].id
                                $available = array_column($data['data'] ?? [], 'id');
                            } else {
                                $error = 'HTTP '.$response->status();
                            }
                        } catch (\Throwable $e) {
                            $error = $e->getMessage();
                        }
                    } else {
                        $error = 'No API key configured';
                    }
                } else {
                    $results[$provider->instance_id] = [
                        'name' => $provider->instance_name,
                        'type' => $provider->instance_type,
                        'note' => 'Provider type not supported for model discovery',
                        'error' => null,
                    ];

                    continue;
                }

                if ($error) {
                    $results[$provider->instance_id] = [
                        'name' => $provider->instance_name,
                        'type' => $provider->instance_type,
                        'error' => $error,
                    ];

                    continue;
                }

                $newModels = array_values(array_diff($available, $dbModels));
                $missingModels = array_values(array_diff($dbModels, $available));

                $results[$provider->instance_id] = [
                    'name' => $provider->instance_name,
                    'type' => $provider->instance_type,
                    'db_models' => $dbModels,
                    'available_models' => $available,
                    'new_models' => $newModels,
                    'deprecated_models' => $missingModels,
                    'error' => null,
                ];

                if (! empty($newModels) || ! empty($missingModels)) {
                    $recommendations[] = [
                        'provider' => $provider->instance_id,
                        'action' => 'review_models',
                        'new' => $newModels,
                        'deprecated' => $missingModels,
                    ];
                }
            }

            // Create review queue item if any diffs found
            if (! empty($recommendations)) {
                try {
                    $title = 'Model updates detected on '.count($recommendations).' provider(s)';
                    $summary = 'ai-ops discovered new or deprecated models. Review and update supported_models and config.models in DB.';
                    $details = json_encode(['providers' => $recommendations]);

                    $existing = DB::selectOne(
                        "SELECT id
                         FROM agent_review_queue
                         WHERE review_type = ?
                           AND agent_id = ?
                           AND status = 'pending'
                         ORDER BY created_at DESC
                         LIMIT 1",
                        ['ai_model_update', 'ai-ops']
                    );

                    if ($existing) {
                        DB::update(
                            'UPDATE agent_review_queue
                             SET priority = ?, title = ?, summary = ?, details = ?, updated_at = NOW()
                             WHERE id = ?',
                            [
                                0,
                                $title,
                                $summary,
                                $details,
                                $existing->id,
                            ]
                        );
                    } else {
                        $token = bin2hex(random_bytes(16));
                        DB::insert(
                            'INSERT INTO agent_review_queue
                                (review_type, priority, status, title, summary, details, agent_id, token, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                            [
                                'ai_model_update',
                                0, // 0=normal, 1=high, 2=urgent (TINYINT UNSIGNED)
                                'pending',
                                $title,
                                $summary,
                                $details,
                                'ai-ops',
                                $token,
                            ]
                        );
                    }
                } catch (\Throwable $e) {
                    Log::warning('AIOperationsService: checkModelUpdates could not create review item', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'success' => true,
                'providers' => $results,
                'recommendations' => $recommendations,
                'summary' => count($recommendations).' provider(s) have model changes',
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'providers' => [],
                'recommendations' => [],
            ];
        }
    }

    /**
     * Probe all unhealthy/open-circuit LLM providers and attempt recovery.
     * Returns summary of which providers recovered and which remain down.
     */
    public function probeUnhealthyProviders(): array
    {
        try {
            $poolManager = app(LLMPoolManagerService::class);
            $results = $poolManager->probeUnhealthyProviders();

            $recovered = array_filter($results, fn ($r) => $r === 'recovered');
            $stillDown = array_filter($results, fn ($r) => $r !== 'recovered');

            return [
                'success' => true,
                'probed' => count($results),
                'recovered' => array_keys($recovered),
                'still_unhealthy' => $stillDown,
                'summary' => count($recovered).'/'.count($results).' providers recovered',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function resolveRuntimeEnvValue(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
