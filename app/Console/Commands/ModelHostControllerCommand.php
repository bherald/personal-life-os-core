<?php

namespace App\Console\Commands;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\ModelHostControllerClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class ModelHostControllerCommand extends Command
{
    protected $signature = 'model-host:control
        {action=status : status, plan, activate, or release}
        {--target= : Exact allowlisted target identity}
        {--request-id= : Idempotent controller request identity}
        {--lease-id= : Exact controller lease identity for activate or release}
        {--execute : Required for activate or release}
        {--json : Emit compact JSON}';

    protected $description = 'Inspect or operate the guarded .87 model-host controller';

    public function __construct(private readonly ModelHostControllerClient $controller)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['status', 'plan', 'activate', 'release'], true)) {
            $this->error('Action must be status, plan, activate, or release.');

            return self::FAILURE;
        }
        if (in_array($action, ['activate', 'release'], true) && ! $this->option('execute')) {
            $this->error('Activate and release require --execute.');

            return self::FAILURE;
        }
        $leaseId = trim((string) $this->option('lease-id'));
        if (in_array($action, ['activate', 'release'], true) && $leaseId === '') {
            $this->error('Activate and release require --lease-id.');

            return self::FAILURE;
        }
        $target = trim((string) $this->option('target'));
        if (in_array($action, ['plan', 'activate'], true) && $target === '') {
            $this->error('Plan and activate require --target.');

            return self::FAILURE;
        }
        $requestId = trim((string) $this->option('request-id'));
        if ($requestId === '') {
            $requestId = 'plos-'.Str::uuid()->toString();
        }

        try {
            $payload = match ($action) {
                'plan' => $this->controller->plan($target, $requestId),
                'activate' => $this->controller->activate($target, $requestId, $leaseId),
                'release' => $this->controller->release($requestId, $leaseId),
                default => $this->controller->status(),
            };
        } catch (RuntimeException $exception) {
            if ($this->option('json')) {
                $diagnostics = InferenceAdmissionException::controllerFailureFrom($exception);
                $this->line(json_encode([
                    'success' => false,
                    'result' => 'failed',
                    'action' => $action,
                    'error_code' => 'model_host_controller_command_failed',
                    'controller_failure' => $diagnostics === [] ? ['stage' => 'unknown'] : $diagnostics,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf(
                '%s host=%s result=%s target=%s',
                $action,
                $payload['host_id'] ?? 'unknown',
                $payload['result'] ?? $payload['state'] ?? 'unknown',
                $payload['target'] ?? $payload['active_target'] ?? 'none'
            ));
        }

        return self::SUCCESS;
    }
}
