<?php

namespace App\Console\Commands;

use App\Services\Qwen38DurableAdmissionService;
use Illuminate\Console\Command;
use RuntimeException;

class Qwen38DurableAdmissionCommand extends Command
{
    protected $signature = 'k3:qwen38-durable
        {action=status : status, promote, or rollback}
        {--execute : Required for promote or rollback}
        {--json : Emit compact JSON}';

    protected $description = 'Inspect or control reversible K3-ALT-06 Qwen3.8 durable admission';

    public function __construct(private readonly Qwen38DurableAdmissionService $admission)
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
            $this->line(sprintf('%s durable=%s route=%s profile=%s', $payload['instance_id'], $payload['durable'] ? 'yes' : 'no', $payload['routability'], $payload['profile_status']));
        }

        return self::SUCCESS;
    }
}
