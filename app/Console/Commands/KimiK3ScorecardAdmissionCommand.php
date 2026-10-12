<?php

namespace App\Console\Commands;

use App\Services\KimiK3ScorecardAdmissionService;
use Illuminate\Console\Command;
use RuntimeException;

class KimiK3ScorecardAdmissionCommand extends Command
{
    protected $signature = 'k3:scorecard-admission
        {action=status : status, open, or close}
        {--execute : Required for open or close}
        {--retry : Reopen only the exact completed batch under an active operator authorization}
        {--batch=1 : Exact frozen K3-05 batch number for open, 1 through 4}
        {--json : Emit compact JSON}';

    protected $description = 'Inspect or control the temporary K3-05 exact-provider admission';

    public function __construct(private readonly KimiK3ScorecardAdmissionService $admission)
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
            $batchOption = (string) $this->option('batch');
            $batch = filter_var($batchOption, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]);
            if ($batch === false || (string) $batch !== $batchOption) {
                throw new RuntimeException('K3-05 admission batch must be an integer from 1 through 4.');
            }
            if ($action !== 'open' && $batch !== 1) {
                throw new RuntimeException('--batch is valid only with open.');
            }
            $payload = match ($action) {
                'open' => $this->admission->open((bool) $this->option('retry'), $batch),
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
