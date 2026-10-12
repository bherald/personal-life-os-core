<?php

namespace App\Services\UnifiedCli;

use Closure;
use Symfony\Component\Process\Process;

/** Keep an owned runtime lease during a bounded, isolated media process. */
final class CliMaintainedProcess
{
    public function run(Process $process, ?Closure $keeper = null): array
    {
        // An invalid lease must not launch even a short-lived child.
        $keeper?->__invoke();
        $interrupted = false;
        $custodyLost = false;
        try {
            $process->start();
            while ($process->isRunning()) {
                $this->maintain($keeper, $custodyLost);
                $process->checkTimeout();
                usleep(50000);
            }
            $this->maintain($keeper, $custodyLost);
        } catch (\Throwable) {
            $interrupted = true;
        } finally {
            if ($process->isRunning()) {
                // Linux bubblewrap dies with its parent; the Windows launcher
                // owns a kill-on-close Job Object for its complete child tree.
                $process->stop(1);
            }
        }

        return ['success' => ! $interrupted && $process->isSuccessful(),
            'exit_code' => $process->getExitCode(), 'interrupted' => $interrupted,
            'custody_lost' => $custodyLost,
            'stdout' => $process->isStarted() ? $process->getOutput() : '',
            'stderr' => $process->isStarted() ? $process->getErrorOutput() : 'Local media process could not be started.'];
    }

    private function maintain(?Closure $keeper, bool &$lost): void
    {
        try {
            $keeper?->__invoke();
        } catch (\Throwable $error) {
            $lost = true;
            throw $error;
        }
    }
}
