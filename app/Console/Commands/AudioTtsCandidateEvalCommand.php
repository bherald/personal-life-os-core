<?php

namespace App\Console\Commands;

use App\Services\Ops\TtsCandidateEvalService;
use Illuminate\Console\Command;

class AudioTtsCandidateEvalCommand extends Command
{
    protected $signature = 'audio:tts-candidate-eval
        {--artifact= : storage/app-relative TTS candidate JSON artifact}
        {--example : Evaluate the built-in content-free synthetic contract example}
        {--json : Emit machine-readable JSON}
        {--compact : Omit per-case metrics}
        {--fail-on-ineligible : Return failure unless the candidate is eligible for operator review}';

    protected $description = 'Evaluate TTS candidates with intelligibility, naturalness, audio-defect, and real-time evidence';

    public function handle(TtsCandidateEvalService $service): int
    {
        $input = $this->artifact($service);
        if ($input['error'] !== null) {
            return $this->finish([
                'schema' => TtsCandidateEvalService::RESULT_SCHEMA,
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
    private function artifact(TtsCandidateEvalService $service): array
    {
        $example = (bool) $this->option('example');
        $relative = trim((string) ($this->option('artifact') ?: ''));
        if ($example === ($relative !== '')) {
            return ['artifact' => [], 'source' => '', 'error' => 'Provide exactly one of --example or --artifact.'];
        }
        if ($example) {
            return ['artifact' => $service->exampleArtifact(), 'source' => 'built_in_synthetic_contract_example', 'error' => null];
        }

        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            return ['artifact' => [], 'source' => '', 'error' => 'Artifact path must be storage/app-relative without ..'];
        }
        $relative = trim($relative, '/');
        $base = realpath(storage_path('app'));
        $path = storage_path('app/'.$relative);
        $resolved = realpath($path);
        if ($base === false || $resolved === false || ! str_starts_with($resolved, $base.DIRECTORY_SEPARATOR) || ! is_file($resolved) || ! is_readable($resolved) || is_link($path)) {
            return ['artifact' => [], 'source' => '', 'error' => 'Artifact must resolve to a regular file inside storage/app.'];
        }
        $maxBytes = max(1, (int) config('tts_candidate_eval.limits.max_artifact_bytes', 1024 * 1024));
        $bytes = filesize($resolved);
        if ($bytes === false || $bytes > $maxBytes) {
            return ['artifact' => [], 'source' => '', 'error' => 'Artifact must be readable JSON within the configured size limit.'];
        }
        $decoded = json_decode((string) file_get_contents($resolved), true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            return ['artifact' => [], 'source' => '', 'error' => 'Artifact is not valid JSON object data.'];
        }

        return ['artifact' => $decoded, 'source' => $relative, 'error' => null];
    }

    /** @param array<string, mixed> $payload */
    private function finish(array $payload, int $exit): int
    {
        if ($this->option('json')) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode TTS candidate evaluation JSON.');

                return self::FAILURE;
            }
            $this->line($json);

            return $exit;
        }

        $this->line(sprintf(
            'TTS candidate eval: %s  cases=%d  WER=%.4f  naturalness=%.2f  RTF=%.4f',
            strtoupper((string) ($payload['status'] ?? 'invalid')),
            (int) data_get($payload, 'candidate.case_count', 0),
            (float) data_get($payload, 'candidate.word_error_rate', 0),
            (float) data_get($payload, 'candidate.naturalness_score', 0),
            (float) data_get($payload, 'candidate.real_time_factor', 0),
        ));

        return $exit;
    }
}
