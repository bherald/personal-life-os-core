<?php

namespace App\Services\Ops;

use App\Services\DevAgent\TraceEnvelopeService;

class TraceGoldenEvalService
{
    public function __construct(private readonly TraceEnvelopeService $traces) {}

    /**
     * @param  array<string,array<string,mixed>>|null  $cases
     * @return array<string,mixed>
     */
    public function evaluate(?array $cases = null): array
    {
        $cases ??= (array) config('dev_agent.trace_golden_eval.cases', []);
        $results = [];

        foreach ($cases as $name => $case) {
            if (! is_array($case)) {
                $results[] = $this->invalidCase((string) $name, 'invalid_case_definition');

                continue;
            }

            $results[] = $this->evaluateCase((string) $name, $case);
        }

        $failed = array_values(array_filter(
            $results,
            static fn (array $result): bool => ! (bool) ($result['passed'] ?? false)
        ));

        return [
            'schema' => (string) config('dev_agent.trace_golden_eval.schema', 'plos.trace_golden_eval.v1'),
            'status' => $cases !== [] && $failed === [] ? 'pass' : 'fail',
            'generated_at' => now('UTC')->toIso8601String(),
            'summary' => [
                'cases' => count($results),
                'passed' => count($results) - count($failed),
                'failed' => count($failed),
                'checks' => array_sum(array_column($results, 'check_count')),
                'violations' => array_sum(array_map(
                    static fn (array $result): int => count((array) ($result['violation_codes'] ?? [])),
                    $results
                )),
            ],
            'cases' => $results,
            'posture' => [
                'deterministic_fixtures' => true,
                'filesystem_writes' => false,
                'raw_prompts_included' => false,
                'raw_completions_included' => false,
                'secret_values_included' => false,
                'production_trace_events_read' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function compactPayload(array $payload): array
    {
        $cases = array_values(array_filter((array) ($payload['cases'] ?? []), 'is_array'));

        return [
            'schema' => $payload['schema'] ?? 'plos.trace_golden_eval.v1',
            'status' => $payload['status'] ?? 'fail',
            'compact' => true,
            'generated_at' => $payload['generated_at'] ?? null,
            'summary' => $payload['summary'] ?? [],
            'failed_cases' => array_values(array_map(
                static fn (array $case): array => [
                    'case' => $case['case'] ?? 'unknown',
                    'violation_codes' => $case['violation_codes'] ?? [],
                ],
                array_filter($cases, static fn (array $case): bool => ! (bool) ($case['passed'] ?? false))
            )),
            'posture' => $payload['posture'] ?? [],
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function evaluateCase(string $name, array $case): array
    {
        $event = is_array($case['event'] ?? null) ? $case['event'] : [];
        $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];
        if ($event === [] || $expect === []) {
            return $this->invalidCase($name, 'missing_event_or_expectation');
        }

        $prepared = $this->traces->prepareEnvelope($event);
        $expectedAccepted = (bool) ($expect['accepted'] ?? true);
        $checks = [];
        $this->check($checks, 'acceptance', (bool) ($prepared['success'] ?? false) === $expectedAccepted);

        if (! $expectedAccepted) {
            $this->check(
                $checks,
                'rejection_error',
                (string) ($prepared['error'] ?? '') === (string) ($expect['error'] ?? '')
            );
            $this->check($checks, 'rejected_without_event', ! isset($prepared['event']));

            return $this->caseResult($name, 'rejection', $checks);
        }

        if (! ($prepared['success'] ?? false) || ! is_array($prepared['event'] ?? null)) {
            $this->check($checks, 'prepared_event_present', false);

            return $this->caseResult($name, 'acceptance', $checks);
        }

        $envelope = $prepared['event'];
        $encoded = (string) ($prepared['json'] ?? '');

        foreach ((array) ($expect['paths'] ?? []) as $path => $expected) {
            $this->check($checks, 'path_match:'.$this->safePath((string) $path), data_get($envelope, (string) $path) === $expected);
        }

        foreach ((array) ($expect['required_paths'] ?? []) as $path) {
            $path = (string) $path;
            $value = data_get($envelope, $path);
            $this->check($checks, 'required_path:'.$this->safePath($path), $value !== null && $value !== '');
        }

        foreach ((array) ($expect['required_fragments'] ?? []) as $index => $fragment) {
            $this->check($checks, 'required_fragment:'.(int) $index, str_contains($encoded, (string) $fragment));
        }

        foreach ((array) ($expect['forbidden_fragments'] ?? []) as $index => $fragment) {
            $this->check($checks, 'forbidden_fragment:'.(int) $index, ! str_contains($encoded, (string) $fragment));
        }

        return $this->caseResult($name, 'acceptance', $checks);
    }

    /**
     * @param  list<array{code:string,passed:bool}>  $checks
     */
    private function check(array &$checks, string $code, bool $passed): void
    {
        $checks[] = ['code' => $code, 'passed' => $passed];
    }

    /**
     * @param  list<array{code:string,passed:bool}>  $checks
     * @return array<string,mixed>
     */
    private function caseResult(string $name, string $contract, array $checks): array
    {
        $violations = array_values(array_map(
            static fn (array $check): string => $check['code'],
            array_filter($checks, static fn (array $check): bool => ! $check['passed'])
        ));

        return [
            'case' => $this->safeName($name),
            'contract' => $contract,
            'passed' => $violations === [],
            'check_count' => count($checks),
            'violation_codes' => $violations,
        ];
    }

    /** @return array<string,mixed> */
    private function invalidCase(string $name, string $code): array
    {
        return [
            'case' => $this->safeName($name),
            'contract' => 'invalid',
            'passed' => false,
            'check_count' => 1,
            'violation_codes' => [$code],
        ];
    }

    private function safeName(string $name): string
    {
        return preg_match('/^[a-z][a-z0-9_]{1,80}$/', $name) === 1 ? $name : 'invalid_case';
    }

    private function safePath(string $path): string
    {
        return preg_match('/^[a-z0-9_.]{1,120}$/', $path) === 1 ? $path : 'invalid_path';
    }
}
