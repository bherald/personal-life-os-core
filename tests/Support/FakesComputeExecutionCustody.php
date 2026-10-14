<?php

namespace Tests\Support;

use App\Services\Compute\ComputeExecutionCustodyService;
use Illuminate\Process\Factory;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Mockery;

trait FakesComputeExecutionCustody
{
    private function mockComputeCustody(): ComputeExecutionCustodyService
    {
        $custody = Mockery::mock(ComputeExecutionCustodyService::class);
        $custody->shouldReceive('occupied')->andReturn(false);
        $custody->shouldReceive('acquire')->andReturnUsing(fn ($instance, $slot, $token) => (object) ['id' => 1, 'owner_token' => $token]);
        $custody->shouldReceive('dispatching', 'terminal', 'release', 'unknown', 'noDispatch')->andReturnNull();

        return $custody;
    }

    /** Laravel synchronous fakes omit run's output callback; restore it only in this test factory. */
    private function installComputeProcessFactory(): void
    {
        Process::swap(new class extends Factory
        {
            public function newPendingProcess()
            {
                return (new class($this) extends PendingProcess
                {
                    public function run(array|string|null $command = null, ?callable $output = null)
                    {
                        $result = parent::run($command, $output);
                        if ($result instanceof FakeProcessResult && $output !== null) {
                            $output('out', $result->output());
                            $output('err', $result->errorOutput());
                        }

                        return $result;
                    }
                })->withFakeHandlers($this->fakeHandlers);
            }
        });
    }

    private function computeTargetCommand($process): array
    {
        $args = $process->command;
        if (is_array($args) && in_array('--transport-argv', $args, true)) {
            return json_decode($args[array_search('--transport-argv', $args, true) + 1], true, 512, JSON_THROW_ON_ERROR);
        }

        return $args;
    }

    private function fakeComputeTerminal($process, string $stdout, string $stderr = '', int $exitCode = 0)
    {
        $args = $this->computeTargetCommand($process);
        if ($args[0] === 'ssh') {
            $shell = end($args);
            preg_match("/'--owner-token' '([^']+)'/", $shell, $token);
            preg_match("/'--command' '(.*)'$/s", $shell, $command);
            $owner = $token[1];
            $originalCommand = str_replace("'\\''", "'", $command[1]);
        } else {
            $owner = $args[array_search('--owner-token', $args, true) + 1];
            $originalCommand = $args[array_search('--command', $args, true) + 1];
        }

        $receipt = ['schema' => 'plos.compute_terminal.v1', 'owner_token' => $owner,
            'command_sha256' => hash('sha256', $originalCommand), 'cleanup_verified' => true, 'dispatched' => true,
            'exit_code' => $exitCode, 'stdout_base64' => base64_encode($stdout), 'stderr_base64' => base64_encode($stderr)];
        ksort($receipt);

        return Process::result(json_encode($receipt));
    }
}
