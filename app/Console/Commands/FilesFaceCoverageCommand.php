<?php

namespace App\Console\Commands;

use App\Services\FileRegistryFaceCoverageService;
use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class FilesFaceCoverageCommand extends Command
{
    protected $signature = 'files:face-coverage {--status : Read aggregate coverage without running detection} {--json : Emit aggregate JSON} {--slice : Internal independently bounded child} {--scheduler-run= : Exact scheduler run inherited by its child}';

    protected $description = 'Track historical and incoming anonymous photo generations under scheduled custody';

    public function handle(FileRegistryFaceCoverageService $coverage): int
    {
        try {
            if ($this->option('status')) {
                if ($this->option('slice') || $this->option('scheduler-run')) {
                    throw new RuntimeException('face_coverage_options_invalid');
                }
                $report = $coverage->status();
            } else {
                if (app()->isDownForMaintenance()) {
                    throw new RuntimeException('maintenance_mode');
                }
                $policy = $coverage->policy();
                if ($this->option('slice')) {
                    $id = $this->option('scheduler-run');
                    if (! is_string($id) || preg_match('/^[1-9][0-9]{0,15}$/D', $id) !== 1) {
                        throw new RuntimeException('face_coverage_scheduler_run_required');
                    }
                    $report = $coverage->runScheduledSlice((int) $id);
                } else {
                    if ($this->option('scheduler-run')) {
                        throw new RuntimeException('face_coverage_options_invalid');
                    }
                    $authority = $coverage->schedulerAuthority();
                    $result = Process::path(base_path())->timeout($policy['outer_timeout_seconds'])->run([
                        $policy['timeout_binary'], '--signal=KILL', $policy['hard_timeout_seconds'].'s',
                        PHP_BINARY, base_path('artisan'), 'files:face-coverage', '--slice', '--json',
                        '--scheduler-run='.$authority['run_id'], '--no-interaction',
                    ]);
                    $child = json_decode(trim($result->output()), true);
                    $summary = [];
                    foreach (['generated', 'unchanged', 'held'] as $outcome) {
                        if (is_int($child['outcomes'][$outcome] ?? null) && $child['outcomes'][$outcome] >= 0) {
                            $summary[$outcome] = $child['outcomes'][$outcome];
                        }
                    }
                    $valid = is_array($child) && in_array($child['state'] ?? null, ['ready', 'busy', 'held'], true)
                        && count($summary) === 3 && is_int($child['slice_processed'] ?? null) && $child['slice_processed'] >= 0
                        && array_sum($summary) === $child['slice_processed'] && ($child['identity_decisions_performed'] ?? null) === false;
                    $revisits = $child['revisits'] ?? ['assessed' => 0, 'ready_for_read_plan' => 0, 'held' => 0];
                    $valid = $valid && is_array($revisits) && count($revisits) === 3
                        && is_int($revisits['assessed'] ?? null) && $revisits['assessed'] >= 0
                        && is_int($revisits['ready_for_read_plan'] ?? null) && $revisits['ready_for_read_plan'] >= 0
                        && is_int($revisits['held'] ?? null) && $revisits['held'] >= 0
                        && $revisits['assessed'] === $revisits['ready_for_read_plan'] + $revisits['held']
                        && $policy['max_rows'] >= $child['slice_processed'] + $revisits['assessed'];
                    $timedOut = in_array($result->exitCode(), [124, 137], true);
                    $heldReason = in_array($child['reason'] ?? null, ['face_coverage_slice_held', 'face_coverage_admission_or_execution_held',
                        'face_coverage_hard_timeout'], true) ? $child['reason'] : 'face_coverage_child_held';
                    $reason = $timedOut ? 'face_coverage_hard_timeout' : (! $valid ? 'face_coverage_child_report_invalid'
                        : (! $result->successful() || $child['state'] === 'held' ? $heldReason : null));
                    $report = ['state' => $reason === null ? ($child['state'] === 'busy' ? 'busy' : 'completed_slice') : 'held',
                        'reason' => $reason, 'outcomes' => $valid ? $summary : [], 'revisits' => $valid ? $revisits : [], 'after' => $coverage->status()];
                }
            }
        } catch (ProcessTimedOutException) {
            $report = ['state' => 'held', 'reason' => 'face_coverage_hard_timeout'];
        } catch (Throwable) {
            $report = ['state' => 'held', 'reason' => 'face_coverage_admission_or_execution_held'];
        }
        // Never forward child output, exceptions, paths, hashes, names or crop identifiers.
        $report['source_identifiers_withheld'] = true;
        $report['identity_decisions_performed'] = false;
        $this->line($this->option('json') ? json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            : 'Anonymous photo coverage: '.$report['state']);

        return ($report['state'] ?? null) === 'held' ? self::FAILURE : self::SUCCESS;
    }
}
