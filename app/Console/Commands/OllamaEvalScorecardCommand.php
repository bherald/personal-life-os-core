<?php

namespace App\Console\Commands;

use App\Support\PromptIntegrityPolicy;
use Illuminate\Console\Command;

class OllamaEvalScorecardCommand extends Command
{
    protected $signature = 'ollama:eval-scorecard
        {model? : Optional model name to inspect}
        {--list : Show the candidate queue}
        {--cases : Show eval cases}
        {--json : Emit JSON instead of table/text output}
        {--compact : Emit aggregate-only scorecard posture without notes}';

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
            'suite_id' => $config['suite_id'] ?? 'unknown',
            'routing' => $config['routing'] ?? [],
            'scorecard_fields' => $config['scorecard_fields'] ?? [],
            'minimum_acceptance' => $config['minimum_acceptance'] ?? [],
            'candidate_queue' => $config['candidate_queue'] ?? [],
            'eval_cases' => $config['eval_cases'] ?? [],
            'compression_eval_enabled' => PromptIntegrityPolicy::allowsLossyTransforms(),
            'compression_benchmark_enabled' => PromptIntegrityPolicy::allowsLossyTransforms(),
            'compression_families' => $config['compression_families'] ?? [],
            'structured_output_evidence' => [
                'schema_version' => config('ollama_output_schemas.schema_version', 'unknown'),
                'provider_neutral_transport' => true,
                'evaluator_revalidation' => true,
                'promotion_requires_all_compliant' => true,
            ],
        ];

        if ($model !== null) {
            $candidate = collect($payload['candidate_queue'])->firstWhere('model', $model);

            if (! is_array($candidate)) {
                $this->error("Model not found in candidate queue: {$model}");

                return self::FAILURE;
            }

            if ($this->option('json')) {
                $modelPayload = [
                    'model' => $candidate,
                    'scorecard_fields' => $payload['scorecard_fields'],
                    'minimum_acceptance' => $payload['minimum_acceptance'],
                ];
                if ($this->option('compact')) {
                    $modelPayload = $this->compactModelPayload($candidate);
                }

                $this->line(json_encode($modelPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
            $output = $this->option('compact') ? $this->compactScorecardPayload($payload) : $payload;
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactScorecardPayload(array $payload): array
    {
        $candidates = is_array($payload['candidate_queue'] ?? null) ? $payload['candidate_queue'] : [];
        $routing = is_array($payload['routing'] ?? null) ? $payload['routing'] : [];
        $configuredCompressionFamilies = config('ollama_eval.compression_families', []);
        $compressionEvalEnabled = filter_var($payload['compression_eval_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $compressionBenchmarkEnabled = filter_var($payload['compression_benchmark_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $compressionFamiliesActive = $compressionEvalEnabled || $compressionBenchmarkEnabled;
        $compressionFamilyNames = array_values(array_map(
            static fn (array $family): string => (string) ($family['family'] ?? 'unknown'),
            is_array($configuredCompressionFamilies) ? $configuredCompressionFamilies : []
        ));

        $candidateCounts = [];
        $activeModels = [];
        $benchModels = [];
        $watchModels = [];
        $caveatModels = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $status = (string) ($candidate['status'] ?? 'unknown');
            $model = (string) ($candidate['model'] ?? 'unknown');
            $candidateCounts[$status] = ($candidateCounts[$status] ?? 0) + 1;

            if ($status === 'active') {
                $activeModels[] = $model;
            } elseif ($status === 'bench') {
                $benchModels[] = $model;
            } elseif ($status === 'watch') {
                $watchModels[] = $model;
            }

            if (str_contains(strtolower((string) ($candidate['notes'] ?? '')), 'caveat')) {
                $caveatModels[] = $model;
            }
        }

        return [
            'status' => 'ok',
            'read_only' => true,
            'no_write' => true,
            'promotion_apply_enabled' => false,
            'suite_id' => (string) ($payload['suite_id'] ?? 'unknown'),
            'structured_output_evidence' => $payload['structured_output_evidence'] ?? [],
            'routing_buckets' => array_map(static fn ($tasks): int => is_array($tasks) ? count($tasks) : 0, $routing),
            'scorecard_field_count' => count($payload['scorecard_fields'] ?? []),
            'minimum_acceptance_count' => count($payload['minimum_acceptance'] ?? []),
            'eval_case_count' => count($payload['eval_cases'] ?? []),
            'active_eval_case_count' => $this->activeEvalCaseCount($payload['eval_cases'] ?? []),
            'candidate_counts' => $candidateCounts,
            'active_models' => $activeModels,
            'bench_models' => $benchModels,
            'watch_models' => $watchModels,
            'caveat_models' => $caveatModels,
            'compression_eval_enabled' => $compressionEvalEnabled,
            'compression_benchmark_enabled' => $compressionBenchmarkEnabled,
            'compression_families' => $compressionFamiliesActive ? $compressionFamilyNames : [],
            'compression_families_configured' => $compressionFamilyNames,
        ];
    }

    /** @param array<string, mixed> $candidate */
    private function compactModelPayload(array $candidate): array
    {
        return [
            'status' => 'ok',
            'read_only' => true,
            'no_write' => true,
            'model' => (string) ($candidate['model'] ?? 'unknown'),
            'candidate_status' => (string) ($candidate['status'] ?? 'unknown'),
            'intended_use' => (string) ($candidate['intended_use'] ?? 'unknown'),
            'has_caveat' => str_contains(strtolower((string) ($candidate['notes'] ?? '')), 'caveat'),
        ];
    }

    private function activeEvalCaseCount(array $cases): int
    {
        $compressionEvalEnabled = PromptIntegrityPolicy::allowsLossyTransforms()
            && filter_var(config('ollama_eval.compression_eval_enabled', false), FILTER_VALIDATE_BOOLEAN);

        return collect($cases)
            ->filter(static fn (array $case): bool => ! filter_var(
                $case['requires_lossy_transform'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ) || $compressionEvalEnabled)
            ->count();
    }
}
