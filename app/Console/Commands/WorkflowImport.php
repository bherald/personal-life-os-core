<?php

namespace App\Console\Commands;

use App\Services\WorkflowPortableDefinitionService;
use Illuminate\Console\Command;
use Throwable;

class WorkflowImport extends Command
{
    protected $signature = 'workflow:import
        {file : Path to a JSON workflow definition}
        {--overwrite : Replace an existing workflow only when it has no execution history}
        {--execute : Apply the import}
        {--confirm : Confirm the inactive import write}
        {--json : Emit machine-readable output}';

    protected $description = 'Validate or import a portable workflow definition; preview is the default';

    public function handle(WorkflowPortableDefinitionService $definitions): int
    {
        try {
            $manifest = $definitions->read((string) $this->argument('file'));
            $overwrite = (bool) $this->option('overwrite');
            $plan = $definitions->planImport($manifest, $overwrite);
            $execute = (bool) $this->option('execute');
            if ($plan['status'] === 'blocked') {
                return $this->finish($this->payload('blocked', true, $plan), self::FAILURE);
            }
            if (! $execute) {
                return $this->finish($this->payload('planned', true, $plan));
            }
            if (! (bool) $this->option('confirm')) {
                return $this->finish($this->payload('confirmation_required', true, $plan), self::FAILURE);
            }

            $result = $definitions->import($plan['manifest'], $overwrite);

            return $this->finish($this->payload('imported_inactive', false, $plan) + [
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            return $this->finish([
                'schema' => WorkflowPortableDefinitionService::SCHEMA,
                'status' => 'blocked',
                'mode' => 'preview',
                'read_only' => true,
                'error' => $e->getMessage(),
            ], self::FAILURE);
        }
    }

    /** @param array<string,mixed> $plan */
    private function payload(string $status, bool $readOnly, array $plan): array
    {
        return [
            'schema' => WorkflowPortableDefinitionService::SCHEMA,
            'status' => $status,
            'mode' => $readOnly ? 'preview' : 'execute',
            'read_only' => $readOnly,
            'summary' => $plan['summary'],
            'warnings' => $plan['warnings'],
            'errors' => $plan['errors'],
            'integrity' => $plan['integrity'],
            'existing' => $plan['existing'],
            'posture' => $plan['posture'],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function finish(array $payload, int $exit = self::SUCCESS): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('workflow:import '.($payload['status'] ?? 'unknown'));
            if (isset($payload['summary']['name'])) {
                $this->line('Workflow: '.$payload['summary']['name']);
            }
            if (isset($payload['error'])) {
                $this->error((string) $payload['error']);
            }
        }

        return $exit;
    }
}
