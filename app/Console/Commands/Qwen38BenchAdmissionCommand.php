<?php

namespace App\Console\Commands;

use App\Services\Qwen38BenchAdmissionService;
use Illuminate\Console\Command;
use RuntimeException;

class Qwen38BenchAdmissionCommand extends Command
{
    protected $signature = 'k3:qwen38-admission
        {action=status : status, open, or close}
        {--execute : Required for open or close}
        {--retry : Reopen only exact K3-ALT-04 completion under a new operator gate}
        {--json : Emit compact JSON}';

    protected $description = 'Inspect or control temporary Qwen3.8 exact-provider admission';

    public function __construct(private readonly Qwen38BenchAdmissionService $admission)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['status', 'open', 'close'], true)) {
            $this->error('Action must be status, open, or close.');

            return self::FAILURE;
        }
        if ($action !== 'status' && ! $this->option('execute')) {
            $this->error('Open and close require --execute.');

            return self::FAILURE;
        }
        if ($action !== 'open' && $this->option('retry')) {
            $this->error('--retry is valid only with open.');

            return self::FAILURE;
        }

        try {
            $payload = match ($action) {
                'open' => $this->admission->open((bool) $this->option('retry')),
                'close' => $this->admission->close(),
                default => $this->admission->status(),
            };
        } catch (\JsonException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf(
                '%s active=%s healthy=%s route=%s phase=%s',
                $payload['instance_id'],
                $payload['is_active'] ? 'yes' : 'no',
                $payload['is_healthy'] ? 'yes' : 'no',
                $payload['routability'],
                $payload['phase']
            ));
        }

        return self::SUCCESS;
    }
}
