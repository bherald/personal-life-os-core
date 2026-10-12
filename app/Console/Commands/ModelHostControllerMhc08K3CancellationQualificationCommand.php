<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerMhc08K3CancellationQualificationService;
use App\Services\ModelHostControllerMhc08K3EvidenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class ModelHostControllerMhc08K3CancellationQualificationCommand extends Command
{
    protected $signature = 'model-host:mhc08-k3-cancellation-qualify
                            {--execute : Run the fixed cross-process cancellation qualification}
                            {--internal-worker-task-id= : Internal exact UUID task identity}
                            {--internal-worker-requester-id= : Internal exact UUID owner identity}
                            {--json : Emit compact content-free JSON}';

    protected $description = 'Prove pre-promotion MHC-08 candidate cancellation, cleanup, and recovery';

    public function __construct(
        private readonly ModelHostControllerMhc08K3CancellationQualificationService $qualification,
    ) {
        parent::__construct();
    }

    public function handle(ModelHostControllerMhc08K3EvidenceService $evidence): int
    {
        $workerTaskId = trim((string) $this->option('internal-worker-task-id'));
        $workerRequesterId = trim((string) $this->option('internal-worker-requester-id'));
        if ($workerTaskId !== '' || $workerRequesterId !== '') {
            return $this->runWorker($workerTaskId, $workerRequesterId);
        }
        if (! $this->option('execute')) {
            $this->error('The MHC-08 K3 cancellation qualification requires --execute.');

            return self::FAILURE;
        }

        $prepared = [];
        $process = null;
        try {
            $prepared = $this->qualification->prepare();
            $process = Process::path(base_path())
                ->timeout(26100)
                ->start([
                    PHP_BINARY,
                    'artisan',
                    'model-host:mhc08-k3-cancellation-qualify',
                    '--internal-worker-task-id='.$prepared['task_id'],
                    '--internal-worker-requester-id='.$prepared['requester_id'],
                    '--json',
                ]);
            $readyDeadline = microtime(true) + 2100;
            while (! $this->qualification->workerReady($prepared['task_id'], $prepared['requester_id'])) {
                if (! $process->running()) {
                    throw new RuntimeException('MHC-08 K3 cancellation qualification worker exited before stream readiness.');
                }
                if (microtime(true) >= $readyDeadline) {
                    throw new RuntimeException('MHC-08 K3 cancellation qualification stream readiness timed out.');
                }
                usleep(250000);
            }
            $this->qualification->signalCancellation($prepared['task_id'], $prepared['requester_id']);
            $result = $process->wait();
            if (! $result->successful()) {
                throw new RuntimeException('MHC-08 K3 cancellation qualification worker failed.');
            }
            $workerReceipt = $this->decodeWorkerReceipt($result->output());
            $receipt = $this->qualification->finalize($prepared, $workerReceipt);
            $evidence->store('cancellation', $receipt);
            $this->emit($receipt);

            return self::SUCCESS;
        } catch (Throwable) {
            if ($process !== null && $process->running()) {
                $process->stop(5);
            }
            $clean = $prepared !== [] && $this->qualification->cleanup($prepared);
            $this->emit([
                'schema' => ModelHostControllerMhc08K3CancellationQualificationService::RECEIPT_SCHEMA,
                'result' => 'rejected',
                'cleanup_result' => $clean ? 'proven' : 'unproven',
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ]);

            return self::FAILURE;
        }
    }

    private function runWorker(string $taskId, string $requesterId): int
    {
        if ($taskId === '' || $requesterId === '' || $this->option('execute')) {
            $this->emit([
                'schema' => ModelHostControllerMhc08K3CancellationQualificationService::WORKER_SCHEMA,
                'result' => 'rejected',
                'cleanup_result' => 'unproven',
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ]);

            return self::FAILURE;
        }

        try {
            $receipt = $this->qualification->work($taskId, $requesterId);
        } catch (Throwable) {
            $receipt = [
                'schema' => ModelHostControllerMhc08K3CancellationQualificationService::WORKER_SCHEMA,
                'task_id' => $taskId,
                'result' => 'rejected',
                'cleanup_result' => 'unproven',
                'content_free' => true,
                'contains_prompt_or_response_content' => false,
            ];
        }
        $this->emit($receipt);

        return ($receipt['result'] ?? null) === 'green' ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function decodeWorkerReceipt(string $output): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $output) ?: [])));
        $receipt = count($lines) === 1 ? json_decode($lines[0], true) : null;
        if (! is_array($receipt)
            || ($receipt['schema'] ?? null) !== ModelHostControllerMhc08K3CancellationQualificationService::WORKER_SCHEMA
            || ($receipt['content_free'] ?? false) !== true
            || ($receipt['contains_prompt_or_response_content'] ?? true) !== false) {
            throw new RuntimeException('MHC-08 K3 cancellation qualification worker output is inexact.');
        }

        return $receipt;
    }

    /** @param array<string,mixed> $receipt */
    private function emit(array $receipt): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }
        $this->line(sprintf(
            'MHC-08 cancellation qualification result=%s cleanup=%s',
            (string) ($receipt['result'] ?? 'rejected'),
            ($receipt['controller_cleanup_proven'] ?? false) === true
                && ($receipt['admission_cleanup_proven'] ?? false) === true
                && ($receipt['custody_released'] ?? false) === true
                ? 'proven'
                : (string) ($receipt['cleanup_result'] ?? 'unproven'),
        ));
    }
}
