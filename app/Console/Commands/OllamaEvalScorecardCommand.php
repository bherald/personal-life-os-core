<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class OllamaEvalScorecardCommand extends Command
{
    protected $signature = 'ollama:eval-scorecard
        {model? : Optional model name to inspect}
        {--list : Show the candidate queue}
        {--cases : Show eval cases}
        {--json : Emit JSON instead of table/text output}';

    protected $description = 'Show the local Ollama Sprint A routing, scorecard fields, and candidate queue';

    public function handle(): int
    {
        $config = config('ollama_eval');
        $model = $this->argument('model');

        if (! is_array($config) || $config === []) {
            $this->error('ollama_eval config is missing or empty.');

            return self::FAILURE;
        }

        $payload = [
            'routing' => $config['routing'] ?? [],
            'scorecard_fields' => $config['scorecard_fields'] ?? [],
            'minimum_acceptance' => $config['minimum_acceptance'] ?? [],
            'candidate_queue' => $config['candidate_queue'] ?? [],
            'eval_cases' => $config['eval_cases'] ?? [],
        ];

        if ($model !== null) {
            $candidate = collect($payload['candidate_queue'])->firstWhere('model', $model);

            if (! is_array($candidate)) {
                $this->error("Model not found in candidate queue: {$model}");

                return self::FAILURE;
            }

            if ($this->option('json')) {
                $this->line(json_encode([
                    'model' => $candidate,
                    'scorecard_fields' => $payload['scorecard_fields'],
                    'minimum_acceptance' => $payload['minimum_acceptance'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->info("Model: {$candidate['model']}");
            $this->line("Status: {$candidate['status']}");
            $this->line("Use: {$candidate['intended_use']}");
            $this->line("Notes: {$candidate['notes']}");
            $this->newLine();
            $this->info('Scorecard Fields');
            foreach ($payload['scorecard_fields'] as $field) {
                $this->line("- {$field}");
            }
            $this->newLine();
            $this->info('Minimum Acceptance');
            foreach ($payload['minimum_acceptance'] as $rule) {
                $this->line("- {$rule}");
            }

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Routing Buckets');
        foreach ($payload['routing'] as $bucket => $tasks) {
            $this->line("{$bucket}: ".implode(', ', $tasks));
        }

        $this->newLine();
        $this->info('Scorecard Fields');
        foreach ($payload['scorecard_fields'] as $field) {
            $this->line("- {$field}");
        }

        $this->newLine();
        $this->info('Minimum Acceptance');
        foreach ($payload['minimum_acceptance'] as $rule) {
            $this->line("- {$rule}");
        }

        if ($this->option('list') || ! $this->option('cases')) {
            $this->newLine();
            $this->info('Candidate Queue');
            $rows = array_map(static fn (array $candidate) => [
                $candidate['model'],
                $candidate['status'],
                $candidate['intended_use'],
                $candidate['notes'],
            ], $payload['candidate_queue']);
            $this->table(['Model', 'Status', 'Use', 'Notes'], $rows);
        }

        if ($this->option('cases')) {
            $this->newLine();
            $this->info('Eval Cases');
            $rows = array_map(static fn (array $case) => [
                $case['id'],
                $case['task_class'],
                $case['prompt_shape'],
                $case['success_rule'],
            ], $payload['eval_cases']);
            $this->table(['ID', 'Task Class', 'Prompt Shape', 'Success Rule'], $rows);
        }

        return self::SUCCESS;
    }
}
