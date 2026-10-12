<?php

namespace App\Console\Commands;

use App\Services\Ops\PromptCandidateEvalService;
use Illuminate\Console\Command;

class CodexOfflinePromptCandidateEvalCommand extends Command
{
    protected $signature = 'codex:offline-prompt-candidate-eval
        {--artifact= : storage/app-relative prompt candidate JSON artifact}
        {--example : Evaluate the built-in hashes-only example}
        {--json : Emit machine-readable JSON}
        {--compact : Omit nonessential detail}
        {--fail-on-ineligible : Return failure unless the candidate is eligible for operator review}';

    protected $description = 'Evaluate a prompt or instruction candidate against disjoint multi-metric held-out evidence';

    public function handle(PromptCandidateEvalService $service): int
    {
        $input = $this->artifact($service);
        if ($input['error'] !== null) {
            return $this->finish([
                'schema' => PromptCandidateEvalService::RESULT_SCHEMA,
                'status' => 'invalid',
                'read_only' => true,
                'violations' => [$input['error']],
            ], self::FAILURE);
        }

        $payload = $service->evaluate($input['artifact']);
        $payload['source'] = $input['source'];
        $output = $this->option('compact') ? $service->compactPayload($payload) : $payload;
        $exit = $this->option('fail-on-ineligible') && ($payload['status'] ?? 'invalid') !== 'eligible_for_review'
            ? self::FAILURE
            : self::SUCCESS;

        return $this->finish($output, $exit);
    }

    /** @return array{artifact:array<string,mixed>,source:string,error:?string} */
    private function artifact(PromptCandidateEvalService $service): array
    {
        $example = (bool) $this->option('example');
        $relative = trim((string) ($this->option('artifact') ?: ''));
        if ($example === ($relative !== '')) {
            return [
                'artifact' => [],
                'source' => '',
                'error' => 'Provide exactly one of --example or --artifact.',
            ];
        }
        if ($example) {
            return ['artifact' => $service->exampleArtifact(), 'source' => 'built_in_example', 'error' => null];
        }

        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            return ['artifact' => [], 'source' => '', 'error' => 'Artifact path must be storage/app-relative without ..'];
        }
        $path = storage_path('app/'.$relative);
        if (! is_file($path) || filesize($path) === false || filesize($path) > 1_048_576) {
            return ['artifact' => [], 'source' => '', 'error' => 'Artifact must be a readable JSON file no larger than 1 MiB.'];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return ['artifact' => [], 'source' => '', 'error' => 'Artifact is not valid JSON object data.'];
        }

        return ['artifact' => $decoded, 'source' => $relative, 'error' => null];
    }

    /** @param array<string,mixed> $payload */
    private function finish(array $payload, int $exit): int
    {
        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode prompt candidate evaluation JSON.');

                return self::FAILURE;
            }
            $this->line($json);

            return $exit;
        }

        $this->line(sprintf(
            'Prompt candidate eval: %s  holdout=%d  regressions=%d  violations=%d',
            strtoupper((string) ($payload['status'] ?? 'invalid')),
            (int) data_get($payload, 'holdout.case_count', 0),
            count((array) ($payload['regressions'] ?? [])),
            count((array) ($payload['violations'] ?? [])),
        ));

        return $exit;
    }
}
