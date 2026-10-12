<?php

require $argv[1].'/vendor/autoload.php';
require_once $argv[1].'/app/Services/ScheduledJobService.php';
require_once $argv[1].'/app/Console/Commands/SchedulerRunCommand.php';

use App\Console\Commands\SchedulerRunCommand;
use App\Services\Custody\TaskCustodyService;
use App\Services\ScheduledJobService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

$receipt = $argv[2];
$mode = $argv[3];
$state = ['marker' => null, 'forbidden' => [], 'reason_code' => null, 'running_state_retained' => true];
$save = static function () use (&$state, $receipt): void {
    $file = fopen($receipt, 'w');
    fwrite($file, json_encode($state, JSON_THROW_ON_ERROR));
    fflush($file);
    fsync($file);
    fclose($file);
};
$save();
$container = new Container;
Container::setInstance($container);
Facade::setFacadeApplication($container);
$db = new class($mode, $state, $save)
{
    public function __construct(private string $mode, private array &$state, private Closure $save) {}

    public function transaction(Closure $callback): mixed
    {
        $this->state['forbidden'][] = 'transaction';
        ($this->save)();

        return $callback();
    }

    public function selectOne(string $sql, array $bindings): object
    {
        $this->state['forbidden'][] = 'selectOne';
        ($this->save)();
        if ($this->mode === 'dbfail') {
            throw new RuntimeException('private fixture diagnostic');
        }
        if ($this->mode === 'hung') {
            ($this->save)();
            sleep(10);
        }
        if (str_contains($sql, 'task_custody_records')) {
            return (object) ['id' => 13, 'owner_token' => 'pid:'.($this->mode === 'foreign' ? 999 : getmypid()).':start:1:run:81'];
        }

        return (object) ['id' => str_contains($sql, 'scheduled_job_runs') ? 81 : 7, 'pid' => getmypid()];
    }

    public function __call(string $name, array $arguments): never
    {
        $this->state['forbidden'][] = $name;
        ($this->save)();
        throw new LogicException('Forbidden mutation');
    }
};
$container->instance('db', $db);
$container->instance('log', new class($state, $save)
{
    public function __construct(private array &$state, private Closure $save) {}

    public function debug(string $message, array $context): void {}

    public function notice(string $message, array $context): void
    {
        $this->state['reason_code'] = $context['reason_code'];
        ($this->save)();
    }
});
$custody = new class($mode, $state, $save) extends TaskCustodyService
{
    public function __construct(private string $mode, private array &$state, private Closure $save) {}

    public function scheduledJobOwnerToken(?int $pid, int $runId): string
    {
        return "pid:{$pid}:start:1:run:{$runId}";
    }

    public function hold(int $custodyId, int $extendBySeconds, ?string $progressNote = null): bool
    {
        if ($this->mode === 'holdfail') {
            return false;
        }
        $this->state['marker'] = $progressNote;
        ($this->save)();

        return true;
    }
};
$command = new SchedulerRunCommand(new ScheduledJobService, $custody);
$method = new ReflectionMethod($command, 'installWallClockTimeout');
$method->invoke($command, (object) ['id' => 7, 'name' => 'fixture', 'timeout_minutes' => 1], 81, 'fixture-worker');
pcntl_alarm(1);
while (true) {
    usleep(200000);
}
