<?php

namespace App\Console\Commands;

use App\Services\Ops\CapacityCheckpointService;
use Illuminate\Console\Command;

class OpsCapacityCheckpointCommand extends Command
{
    protected $signature = 'ops:capacity-checkpoint
        {--json : Emit machine-readable JSON}
        {--markdown : Emit Markdown}
        {--dry-run : Validate command wiring without invoking collectors}
        {--window=24h : Time window, e.g. 60m, 24h, 7d}';

    protected $description = 'Observe-only no-decision capacity checkpoint over existing ops evidence';

    public function handle(CapacityCheckpointService $checkpoints): int
    {
        if ($this->option('json') && $this->option('markdown')) {
            $this->error('Choose either --json or --markdown, not both.');

            return self::FAILURE;
        }

        $window = $checkpoints->parseWindow((string) $this->option('window'));
        if ($window === null) {
            $this->error('Invalid --window. Use Nm (minutes), Nh (hours), or Nd (days).');

            return 2;
        }

        $payload = $checkpoints->buildCheckpoint($window, (bool) $this->option('dry-run'));

        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode capacity checkpoint JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        $this->line($this->option('markdown')
            ? $checkpoints->toMarkdown($payload)
            : $checkpoints->toText($payload));

        return self::SUCCESS;
    }
}
