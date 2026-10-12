<?php

namespace App\Services\UnifiedCli;

use Closure;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

/** Keep an existing model lease while the managed MCP worker owns its child. */
final class CliMcpWorkerProcess
{
    public function run(Process $process, string $request, ?Closure $keeper = null): void
    {
        if ($keeper === null) {
            $process->setInput($request);
            $process->run();

            return;
        }
        $input = new InputStream;
        $input->write($request."\n");
        $process->setInput($input);
        // Refuse before starting a worker when custody is already unavailable.
        $keeper();
        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $keeper();
                usleep(100000);
            }
        } catch (Throwable $error) {
            if ($process->isRunning()) {
                $input->write("{\"cancel\":true}\n");
                $input->close();
                // Allow SDK cancellation, child shutdown and full receipt publication.
                $deadline = hrtime(true) + 3000000000;
                while ($process->isRunning() && hrtime(true) < $deadline) {
                    usleep(20000);
                }
                if ($process->isRunning()) {
                    $process->stop(0.5);
                }
            }
            throw $error;
        } finally {
            $input->close();
        }
    }
}
