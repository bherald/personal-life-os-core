<?php

namespace App\Console\Commands;

use App\Services\FileRegistryImageAuditService;
use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class FilesImageAuditCommand extends Command
{
    protected $signature = 'files:image-audit {--status : Inspect aggregate audit status without starting a worker} {--json : Emit aggregate JSON} {--slice : Internal bounded child inspection}';

    protected $description = 'Inspect one bounded image-audit slice through the registered maintenance schedule';

    public function handle(FileRegistryImageAuditService $audit): int
    {
        $report = ['state' => 'held', 'reason' => null, 'worker_started' => false,
            'source_identifiers_withheld' => true, 'before' => null, 'after' => null];
        try {
            if ($this->option('status') && $this->option('slice')) {
                throw new RuntimeException('conflicting_audit_options');
            }
            if ($this->option('status')) {
                $report = $this->aggregate($audit->status()) + ['worker_started' => false, 'source_identifiers_withheld' => true];
            } else {
                if (app()->isDownForMaintenance()) {
                    throw new RuntimeException('maintenance_mode');
                }
                $policy = $audit->policy();
                $binary = $this->admission($policy);
                if ($this->option('slice')) {
                    $report = $this->aggregate($audit->runSlice()) + ['worker_started' => false, 'source_identifiers_withheld' => true];
                } else {
                    $report['before'] = $this->aggregate($audit->status());
                    if (in_array($report['before']['state'], ['waiting', 'complete', 'busy', 'held', 'failed', 'timed_out'], true)) {
                        $report['state'] = $report['before']['state'];
                        $report['reason'] = $report['before']['reason'];
                        $report['after'] = $report['before'];
                    } else {
                        $report['worker_started'] = true;
                        try {
                            // A separate process-group deadline survives this parent; never use --foreground or --force.
                            $result = Process::path(base_path())->timeout($policy['outer_timeout_seconds'])->quietly()->run([
                                $binary, '--signal=KILL', $policy['hard_timeout_seconds'].'s',
                                PHP_BINARY, base_path('artisan'), 'files:image-audit', '--slice', '--json', '--no-interaction',
                            ]);
                            $timedOut = in_array($result->exitCode(), [124, 137], true);
                            $report['state'] = $timedOut ? 'timed_out' : ($result->successful() ? 'completed_slice' : 'failed');
                            $report['reason'] = $timedOut ? 'worker_hard_timeout' : ($result->successful() ? null : 'worker_nonzero_exit');
                            $report['worker_exit_code'] = $result->exitCode();
                            $worker = $this->workerAggregate($result->output());
                            if ($worker !== null) {
                                $report['worker'] = $worker;
                                if (! $timedOut && in_array($worker['state'], ['held', 'failed', 'timed_out'], true)) {
                                    $report['state'] = $worker['state'] === 'timed_out' ? 'timed_out' : 'failed';
                                    $report['reason'] = $worker['reason'] ?? 'worker_nonzero_exit';
                                }
                            }
                        } catch (ProcessTimedOutException) {
                            $report['state'] = 'timed_out';
                            $report['reason'] = 'worker_hard_timeout';
                        }
                        $report['after'] = $this->aggregate($audit->status());
                        if ($report['state'] === 'completed_slice' && in_array($report['after']['state'], ['held', 'failed', 'timed_out'], true)) {
                            $report['state'] = $report['after']['state'];
                            $report['reason'] = $report['after']['reason'];
                        }
                    }
                }
            }
        } catch (Throwable $error) {
            $report['state'] = 'held';
            $report['reason'] = $error instanceof RuntimeException && in_array($error->getMessage(), [
                'conflicting_audit_options', 'maintenance_mode', 'invalid_audit_bounds',
                'audit_scheduler_admission_invalid', 'audit_timeout_fence_invalid', 'independent_timeout_unavailable',
            ], true) ? $error->getMessage() : 'audit_admission_or_reporting_unavailable';
        }
        $this->line($this->option('json') ? json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            : 'Image audit: '.$report['state'].($report['reason'] ? ' ('.$report['reason'].')' : ''));

        return in_array($report['state'], ['held', 'failed', 'timed_out'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function admission(array $policy): string
    {
        foreach (['max_seconds', 'hard_timeout_seconds', 'outer_timeout_seconds', 'lease_seconds', 'timeout_minutes'] as $key) {
            $bound = config('file_registry.image_audit.'.($key === 'lease_seconds' ? 'state_bounds' : 'maintenance_bounds').'.'.$key);
            if (! is_int($policy[$key] ?? null) || $policy[$key] < 1 || ! is_int($bound) || $policy[$key] > $bound) {
                throw new RuntimeException('invalid_audit_bounds');
            }
        }
        $scheduled = DB::table('scheduled_jobs')->where('name', 'file_registry_image_audit')->first();
        if (! $scheduled || $scheduled->command !== 'files:image-audit --json' || (int) $scheduled->enabled !== 1
            || (int) $scheduled->run_in_background !== 1 || (int) $scheduled->without_overlapping !== 1 || (int) $scheduled->max_parallel !== 1
            || ! is_numeric($scheduled->timeout_minutes) || (int) $scheduled->timeout_minutes !== $policy['timeout_minutes']) {
            throw new RuntimeException('audit_scheduler_admission_invalid');
        }
        if ($policy['max_seconds'] >= $policy['hard_timeout_seconds'] || $policy['hard_timeout_seconds'] >= $policy['outer_timeout_seconds']
            || $policy['outer_timeout_seconds'] >= $policy['lease_seconds'] || $policy['outer_timeout_seconds'] >= $policy['timeout_minutes'] * 60) {
            throw new RuntimeException('audit_timeout_fence_invalid');
        }
        $binary = $policy['timeout_binary'] ?? null;
        if (! is_string($binary) || ! is_file($binary) || ! is_executable($binary)) {
            throw new RuntimeException('independent_timeout_unavailable');
        }

        return $binary;
    }

    /** Defense in depth: unexpected adapter fields and raw errors never become CLI output. */
    private function aggregate(array $result): array
    {
        $states = ['ready', 'pending', 'progressed', 'complete', 'waiting', 'busy', 'idle', 'held', 'failed', 'timed_out', 'completed_slice'];
        if (! in_array($result['state'] ?? null, $states, true)) {
            throw new RuntimeException('Unexpected audit aggregate state.');
        }
        $reason = $result['reason'] ?? null;
        if ($reason !== null && (! is_string($reason) || ! in_array($reason, FileRegistryImageAuditService::AGGREGATE_REASONS, true))) {
            throw new RuntimeException('Unexpected audit aggregate reason.');
        }
        $safe = ['state' => $result['state'], 'reason' => $reason];
        $databaseError = FileRegistryImageAuditService::sanitizeDatabaseError($result['database_error'] ?? null);
        if ($databaseError !== []) {
            $safe['database_error'] = $databaseError;
        }
        if (($result['completion_scope'] ?? null) === 'captured_maximum_selector_ids') {
            $safe['completion_scope'] = 'captured_maximum_selector_ids';
        }
        foreach (['registry_epoch_complete', 'media_epoch_complete'] as $key) {
            if (array_key_exists($key, $result)) {
                if (! is_bool($result[$key])) {
                    throw new RuntimeException('Unexpected audit completion aggregate.');
                }
                $safe[$key] = $result[$key];
            }
        }
        foreach (['processed', 'findings', 'held'] as $key) {
            if (is_int($result[$key] ?? null) && $result[$key] >= 0) {
                $safe[$key] = $result[$key];
            }
        }
        $safe['counters'] = [];
        $counterKeys = ['attempts', 'inspections', 'findings', 'retries', 'processed', 'held'];
        foreach ($counterKeys as $key) {
            if (is_int($result['counters'][$key] ?? null) && $result['counters'][$key] >= 0) {
                $safe['counters'][$key] = $result['counters'][$key];
            }
        }
        foreach (['registry', 'media'] as $lane) {
            foreach ($counterKeys as $key) {
                if (is_int($result['counters'][$lane][$key] ?? null) && $result['counters'][$lane][$key] >= 0) {
                    $safe['counters'][$lane][$key] = $result['counters'][$lane][$key];
                }
            }
        }
        $next = $result['next_attempt_at'] ?? null;
        if ($next !== null && (! is_string($next) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $next) !== 1)) {
            throw new RuntimeException('Unexpected audit retry aggregate.');
        }
        $safe['next_attempt_at'] = $next;

        return $safe;
    }

    /** Child stdout is untrusted; only the existing aggregate whitelist can cross this boundary. */
    private function workerAggregate(string $output): ?array
    {
        try {
            $decoded = json_decode($output, true, 32, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $this->aggregate($decoded) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
