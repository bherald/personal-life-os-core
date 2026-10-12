<?php

namespace App\Console\Commands;

use App\Services\Ops\OcrCandidateEvalService;
use Illuminate\Console\Command;

class ContentOcrCandidateEvalCommand extends Command
{
    protected $signature = 'content:ocr-candidate-eval
        {--artifact= : storage/app-relative OCR candidate JSON artifact}
        {--example : Evaluate the built-in content-free synthetic contract example}
        {--json : Emit machine-readable JSON}
        {--compact : Omit per-case metrics}
        {--fail-on-ineligible : Return failure unless the candidate is eligible for operator review}';

    protected $description = 'Evaluate OCR candidates with heterogeneous capability, per-case quality, fidelity, provenance, latency, and VRAM evidence';

    public function handle(OcrCandidateEvalService $service): int
    {
        $input = $this->artifact($service);
        if ($input['error'] !== null) {
            return $this->finish([
                'schema' => OcrCandidateEvalService::RESULT_SCHEMA,
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
    private function artifact(OcrCandidateEvalService $service): array
    {
        $example = (bool) $this->option('example');
        $relative = trim((string) ($this->option('artifact') ?: ''));
        if ($example === ($relative !== '')) {
            return ['artifact' => [], 'source' => '', 'error' => 'Provide exactly one of --example or --artifact.'];
        }
        if ($example) {
            return ['artifact' => $service->exampleArtifact(), 'source' => 'built_in_example', 'error' => null];
        }

        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            return ['artifact' => [], 'source' => '', 'error' => 'Artifact path must be storage/app-relative without ..'];
        }
        $path = storage_path('app/'.$relative);
        $maxBytes = max(1, (int) config('ocr_candidate_eval.limits.max_artifact_bytes', 4 * 1024 * 1024));
        $bytes = is_file($path) ? filesize($path) : false;
        if ($bytes === false || $bytes > $maxBytes) {
            return ['artifact' => [], 'source' => '', 'error' => 'Artifact must be readable JSON within the configured size limit.'];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || array_is_list($decoded)) {
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
                $this->error('Failed to encode OCR candidate evaluation JSON.');

                return self::FAILURE;
            }
            $this->line($json);

            return $exit;
        }

        $this->line(sprintf(
            'OCR candidate eval: %s  cases=%d  edit=%.4f  order=%.4f  units=%.4f  regressions=%d  case_floors=%d',
            strtoupper((string) ($payload['status'] ?? 'invalid')),
            (int) data_get($payload, 'candidate.case_count', 0),
            (float) data_get($payload, 'candidate.normalized_edit_similarity', 0),
            (float) data_get($payload, 'candidate.reading_order_pass_rate', 0),
            (float) data_get($payload, 'candidate.unit_test_pass_rate', 0),
            count((array) ($payload['regressions'] ?? [])),
            count((array) ($payload['case_quality_failures'] ?? [])),
        ));

        return $exit;
    }
}
