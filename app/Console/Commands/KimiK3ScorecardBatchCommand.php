<?php

namespace App\Console\Commands;

use App\Services\KimiK3ScorecardBatchService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class KimiK3ScorecardBatchCommand extends Command
{
    protected $signature = 'k3:scorecard-batch
        {--execute : Run the approved temporary five-case live batch}
        {--batch=1 : Exact frozen K3-05 batch number, 1 through 4}
        {--json : Emit compact JSON}';

    protected $description = 'Inspect or run the fixed K3-05 candidate/Qwen scorecard batch';

    public function __construct(private readonly KimiK3ScorecardBatchService $scorecard)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $batchOption = (string) $this->option('batch');
            $batch = filter_var($batchOption, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4]]);
            if ($batch === false || (string) $batch !== $batchOption) {
                throw new InvalidArgumentException('K3-05 batch must be an integer from 1 through 4.');
            }
            $payload = $this->option('execute')
                ? $this->scorecard->run($batch)
                : $this->scorecard->contract($batch);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line(sprintf(
                '%s cases=%d max_tokens=%d execute=%s',
                $payload['batch_id'],
                $payload['case_count'],
                $payload['requested_max_tokens'] ?? $payload['max_tokens'],
                $this->option('execute') ? 'yes' : 'no'
            ));
        }

        return self::SUCCESS;
    }
}
