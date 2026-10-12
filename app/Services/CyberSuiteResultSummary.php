<?php

namespace App\Services;

/** Present recorded synthetic checks separately from model commentary. */
final class CyberSuiteResultSummary
{
    public function summarize(array $report, array $receipts, array $expectedCoverage): array
    {
        $results = $report['results'] ?? [];
        $problems = [];
        $completed = [];
        if (($report['error'] ?? null) !== null || $expectedCoverage === []) {
            $problems[] = 'run_error_or_missing_coverage_contract';
        }
        foreach ($receipts as $receipt) {
            if (($receipt['state'] ?? null) !== 'completed') {
                $problems[] = 'unfinished_action_receipt';
            }
        }
        foreach ($expectedCoverage as $case => $coverage) {
            $retest = $results['retests'][$case] ?? [];
            $hash = $results['candidate_hashes'][$case] ?? null;
            $valid = is_int($coverage) && $coverage > 0 && is_string($hash)
                && preg_match('/^[a-f0-9]{64}$/D', $hash)
                && ($retest['source_sha256'] ?? null) === $hash;
            foreach ([$retest, $retest['clean_holdout'] ?? []] as $check) {
                $valid = $valid && ($check['case_id'] ?? null) === $case
                    && ($check['status'] ?? null) === 'complete_clean'
                    && ($check['coverage'] ?? null) === $coverage
                    && ($check['failed_checks'] ?? null) === [];
            }
            $matches = 0;
            $attempts = 0;
            foreach ($receipts as $receipt) {
                $document = $receipt['document'] ?? [];
                if (($document['action']['name'] ?? null) !== 'suite_retest'
                    || ($document['action']['arguments']['case_id'] ?? null) !== $case) {
                    continue;
                }
                $attempts++;
                $output = $document['result']['output'] ?? null;
                $recorded = is_string($output) ? json_decode($output, true) : null;
                if (is_array($recorded) && ($recorded['operator_closed'] ?? false) === false) {
                    unset($recorded['operator_closed']);
                }
                if (($receipt['state'] ?? null) === 'completed'
                    && ($document['outcome'] ?? null) === 'succeeded'
                    && ($document['result']['success'] ?? null) === true
                    && is_array($recorded) && $this->sameJsonEvidence($recorded, $retest)) {
                    $matches++;
                }
            }
            if ($valid && $attempts === 1 && $matches === 1) {
                $completed[] = $case;
            } else {
                $problems[] = 'unverified_retest:'.$case;
            }
        }
        $extra = array_diff(array_keys($results['retests'] ?? []), array_keys($expectedCoverage));
        if ($extra !== []) {
            $problems[] = 'unexpected_retest_cases';
        }
        $passed = $problems === [];

        return ['schema' => 'plos.cyber.recorded-suite-summary.v1',
            'status' => $passed ? 'recorded_synthetic_checks_passed' : 'review_incomplete_evidence',
            'text' => $passed
                ? count($completed).' synthetic cases passed their recorded candidate and clean-control checks. Human review remains open; this does not establish application-wide security.'
                : 'The recorded evidence is incomplete or inconsistent. Human review remains open.',
            'completed_cases' => $completed, 'expected_cases' => array_keys($expectedCoverage),
            'problems' => array_values(array_unique($problems)), 'operator_closed' => false,
            'security_certification' => false, 'model_commentary_authoritative' => false];
    }

    /** JSON storage does not retain object-key order or the PHP distinction between 331 and 331.0. */
    private function sameJsonEvidence(array $recorded, array $retest): bool
    {
        try {
            return json_encode($this->orderedJsonObjectKeys($recorded), JSON_THROW_ON_ERROR)
                === json_encode($this->orderedJsonObjectKeys($retest), JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
    }

    private function orderedJsonObjectKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->orderedJsonObjectKeys($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
