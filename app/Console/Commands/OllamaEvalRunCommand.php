<?php

namespace App\Console\Commands;

use App\Services\OllamaEvalLedgerService;
use App\Services\OllamaEvalRunnerService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class OllamaEvalRunCommand extends Command
{
    protected $signature = 'ollama:eval-run
        {model : Model name or provider:model target to evaluate}
        {--provider= : Optional provider override when model is unprefixed}
        {--instance= : Pin evaluation to one Ollama instance id}
        {--case=* : Optional case id(s) to run}
        {--max-tokens= : Bound every selected case to the same output-token cap}
        {--record : Persist a content-free evidence summary}
        {--json : Emit JSON output}';

    protected $description = 'Run fixed Sprint A eval fixtures against a model target';

    public function __construct(
        private readonly OllamaEvalRunnerService $runner,
        private readonly OllamaEvalLedgerService $ledger
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->runner->runModel(
                (string) $this->argument('model'),
                $this->option('case') !== [] ? (array) $this->option('case') : null,
                $this->option('provider') ? (string) $this->option('provider') : null,
                $this->option('instance') ? (string) $this->option('instance') : null,
                $this->option('max-tokens') !== null ? (int) $this->option('max-tokens') : null
            );

            if ($this->option('record')) {
                $result['evidence_record'] = $this->ledger->record($result);
            }
        } catch (InvalidArgumentException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($result['failed_cases'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info("Target: {$result['target']}");
        $this->line("Pass rate: {$result['pass_rate']}% ({$result['passed_cases']}/{$result['total_cases']})");
        $this->newLine();

        $rows = array_map(static fn (array $row): array => [
            $row['case_id'],
            $row['task_class'],
            $row['score'].'%',
            $row['passed'] ? 'pass' : 'fail',
            $row['duration_ms'],
        ], $result['results']);

        $this->table(['Case', 'Task', 'Score', 'Result', 'ms'], $rows);

        foreach ($result['results'] as $row) {
            if ($row['passed']) {
                continue;
            }

            $this->warn("Failed checks for {$row['case_id']}:");
            foreach ($row['checks'] as $check => $passed) {
                if (! $passed) {
                    $this->line("- {$check}");
                }
            }
        }

        return ($result['failed_cases'] ?? 0) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
