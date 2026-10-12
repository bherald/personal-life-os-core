<?php

namespace App\Console\Commands;

use App\Services\OllamaCoexistenceAdmissionService;
use Illuminate\Console\Command;
use RuntimeException;

class OllamaCoexistenceAdmissionCommand extends Command
{
    protected $signature = 'k3:ollama-coexistence
        {action=status : status, promote, or rollback}
        {--execute : Required for promote or rollback}
        {--json : Emit compact JSON}';

    protected $description = 'Inspect or control reversible K3-COEX-04 Ollama serialized admission';

    public function __construct(private readonly OllamaCoexistenceAdmissionService $admission)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['status', 'promote', 'rollback'], true)) {
            $this->error('Action must be status, promote, or rollback.');

            return self::FAILURE;
        }
        if ($action !== 'status' && ! $this->option('execute')) {
            $this->error('Promote and rollback require --execute.');

            return self::FAILURE;
        }

        try {
            $payload = match ($action) {
                'promote' => $this->admission->promote(),
                'rollback' => $this->admission->rollback(),
                default => $this->admission->status(),
            };
        } catch (\JsonException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf('%s durable=%s mode=%s route=%s', $payload['instance_id'], $payload['durable'] ? 'yes' : 'no', $payload['mode'], $payload['routability']));
        }

        return self::SUCCESS;
    }
}
