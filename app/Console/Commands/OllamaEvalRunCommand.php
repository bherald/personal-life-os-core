<?php

namespace App\Console\Commands;

use App\Services\OllamaEvalRunnerService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class OllamaEvalRunCommand extends Command
{
    protected $signature = 'ollama:eval-run
        {model : Model name or provider:model target to evaluate}
        {--provider= : Optional provider override when model is unprefixed}
        {--case=* : Optional case id(s) to run}
        {--json : Emit JSON output}';

    protected $description = 'Run fixed Sprint A eval fixtures against a model target';

    public function __construct(
        private readonly OllamaEvalRunnerService $runner
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->runner->runModel(
                (string) $this->argument('model'),
                $this->option('case') !== [] ? (array) $this->option('case') : null,
                $this->option('provider') ? (string) $this->option('provider') : null
            );
        } catch (InvalidArgumentException $e) {
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
