<?php

namespace App\Console\Commands;

use App\Services\ModelHostControllerWaitService;
use Illuminate\Console\Command;
use Throwable;

class ModelHostControllerWaitReconcileCommand extends Command
{
    protected $signature = 'model-host:reconcile-wait
                            {ticket : Exact distributed_tasks ticket ID}
                            {request : Exact original request ID}
                            {--host= : Exact controller host ID}
                            {--later-lease-acquired-at= : UTC acquisition time read from independent host state}
                            {--host-boot-at= : Exact UTC host boot time from independent host evidence}
                            {--evidence-sha256= : SHA-256 of the independently retained host evidence artifact}
                            {--released-lease-id= : Exact original lease from protected host receipts}
                            {--target= : Exact original target for receipt recovery}
                            {--preview-digest= : Exact passing receipt preview digest required for apply}
                            {--evidence= : Private evidence record reference}
                            {--fence-reserved : Fence only one pristine application reserved admission; no host calls}
                            {--apply : Settle only this queue ticket after a passing preview}';

    protected $description = 'Preview or settle one orphaned controller wait ticket without replaying its request';

    public function handle(ModelHostControllerWaitService $waiting): int
    {
        try {
            $ticket = filter_var($this->argument('ticket'), FILTER_VALIDATE_INT);
            if ($ticket === false) {
                throw new \RuntimeException('Exact numeric ticket ID is required.');
            }
            $lease = $this->option('released-lease-id');
            $target = $this->option('target');
            $digest = $this->option('preview-digest');
            $later = $this->option('later-lease-acquired-at');
            $boot = $this->option('host-boot-at');
            $evidenceSha256 = $this->option('evidence-sha256');
            if ($this->option('fence-reserved')) {
                if ($later !== null || $lease !== null || $target !== null || $boot !== null || $evidenceSha256 !== null) {
                    throw new \RuntimeException('Reserved application fence cannot be combined with host recovery proof.');
                }
                $receipt = $waiting->fenceReserved($ticket, (string) $this->option('host'),
                    (string) $this->argument('request'), (string) $this->option('evidence'), $digest, (bool) $this->option('apply'));
            } elseif ($boot !== null || $evidenceSha256 !== null) {
                if ($later !== null || $lease !== null || $target !== null || $boot === null || $evidenceSha256 === null) {
                    throw new \RuntimeException('Choose one complete recovery proof: later lease, exact released lease and target, or host boot and evidence hash.');
                }
                $receipt = $waiting->reconcileOrphanAfterHostBoot($ticket, (string) $this->option('host'),
                    (string) $this->argument('request'), (string) $boot, (string) $this->option('evidence'),
                    (string) $evidenceSha256, $digest, (bool) $this->option('apply'));
            } elseif ($lease !== null || $target !== null || $digest !== null) {
                if ($later !== null || $lease === null || $target === null) {
                    throw new \RuntimeException('Choose one recovery proof: later lease, or exact original released lease and target.');
                }
                $receipt = $waiting->reconcileOrphanFromReceipts($ticket, (string) $this->option('host'),
                    (string) $this->argument('request'), (string) $target, (string) $lease,
                    (string) $this->option('evidence'), $digest, (bool) $this->option('apply'));
            } else {
                $receipt = $waiting->reconcileOrphan($ticket, (string) $this->option('host'),
                    (string) $this->argument('request'), (string) $later,
                    (string) $this->option('evidence'), (bool) $this->option('apply'));
            }
            $this->line(json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
