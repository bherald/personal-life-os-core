<?php

namespace App\Services\Ops;

use InvalidArgumentException;

class AgentConfidenceCalibrationService
{
    public const SCHEMA = 'plos.agent_confidence_calibration.v1';

    /**
     * @param  list<array{confidence:mixed,approved:mixed}>  $samples
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public function report(array $samples, array $policy): array
    {
        $validatedPolicy = $this->policy($policy);
        $normalized = array_map(fn (array $sample): array => $this->sample($sample), $samples);
        $sampleCount = count($normalized);
        $approvedCount = array_sum(array_column($normalized, 'approved'));
        $rejectedCount = $sampleCount - $approvedCount;
        $bins = $this->bins($normalized, $validatedPolicy['bin_count']);
        $thresholdSamples = array_values(array_filter(
            $normalized,
            static fn (array $sample): bool => $sample['confidence'] >= $validatedPolicy['actionability_threshold']
        ));
        $thresholdApproved = array_sum(array_column($thresholdSamples, 'approved'));
        $thresholdPrecision = $thresholdSamples === [] ? null : $this->round($thresholdApproved / count($thresholdSamples));
        $measured = $sampleCount >= $validatedPolicy['min_samples'] && $approvedCount > 0 && $rejectedCount > 0;

        $metrics = [
            'sample_count' => $sampleCount,
            'approved_count' => $approvedCount,
            'rejected_count' => $rejectedCount,
            'mean_confidence' => $sampleCount === 0 ? null : $this->round($this->mean(array_column($normalized, 'confidence'))),
            'observed_approval_rate' => $sampleCount === 0 ? null : $this->round($approvedCount / $sampleCount),
            'expected_calibration_error' => $sampleCount === 0 ? null : $this->expectedCalibrationError($bins, $sampleCount),
            'brier_score' => $sampleCount === 0 ? null : $this->brierScore($normalized),
            'auroc' => $approvedCount === 0 || $rejectedCount === 0 ? null : $this->auroc($normalized),
            'monotonicity_violations' => $this->monotonicityViolations($bins),
            'threshold_sample_count' => count($thresholdSamples),
            'threshold_approved_count' => $thresholdApproved,
            'threshold_precision' => $thresholdPrecision,
            'threshold_coverage' => $sampleCount === 0 ? null : $this->round(count($thresholdSamples) / $sampleCount),
        ];

        $reasons = [];
        if ($measured) {
            if ($metrics['expected_calibration_error'] > $validatedPolicy['max_expected_calibration_error']) {
                $reasons[] = 'expected_calibration_error_above_policy';
            }
            if ($metrics['brier_score'] > $validatedPolicy['max_brier_score']) {
                $reasons[] = 'brier_score_above_policy';
            }
            if ($metrics['monotonicity_violations'] > $validatedPolicy['max_monotonicity_violations']) {
                $reasons[] = 'confidence_bins_not_monotonic';
            }
            if (count($thresholdSamples) < $validatedPolicy['min_threshold_samples']) {
                $reasons[] = 'insufficient_threshold_samples';
            } elseif ($thresholdPrecision < $validatedPolicy['min_threshold_precision']) {
                $reasons[] = 'threshold_precision_below_policy';
            }
        }

        return [
            'schema' => self::SCHEMA,
            'status' => ! $measured ? 'insufficient_evidence' : ($reasons === [] ? 'ok' : 'review_required'),
            'metrics' => $metrics,
            'bins' => $bins,
            'policy' => $validatedPolicy,
            'review_reasons' => $reasons,
            'posture' => [
                'deterministic' => true,
                'read_only' => true,
                'content_free' => true,
                'approval_is_operator_decision_proxy' => true,
                'approval_is_not_global_factual_correctness' => true,
                'changes_confidence_thresholds' => false,
                'changes_review_routing' => false,
                'changes_approval_authority' => false,
                'llm_used' => false,
                'network_used' => false,
                'compression' => false,
                'precompaction' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function policy(array $policy): array
    {
        $validated = [
            'min_samples' => $this->positiveInt($policy['min_samples'] ?? null, 'min_samples'),
            'bin_count' => $this->boundedInt($policy['bin_count'] ?? null, 2, 20, 'bin_count'),
            'actionability_threshold' => $this->probability($policy['actionability_threshold'] ?? null, 'actionability_threshold'),
            'min_threshold_samples' => $this->positiveInt($policy['min_threshold_samples'] ?? null, 'min_threshold_samples'),
            'max_expected_calibration_error' => $this->probability($policy['max_expected_calibration_error'] ?? null, 'max_expected_calibration_error'),
            'max_brier_score' => $this->probability($policy['max_brier_score'] ?? null, 'max_brier_score'),
            'min_threshold_precision' => $this->probability($policy['min_threshold_precision'] ?? null, 'min_threshold_precision'),
            'max_monotonicity_violations' => $this->boundedInt($policy['max_monotonicity_violations'] ?? null, 0, 20, 'max_monotonicity_violations'),
        ];

        if ($validated['min_threshold_samples'] > $validated['min_samples']) {
            throw new InvalidArgumentException('min_threshold_samples must not exceed min_samples');
        }

        return $validated;
    }

    /** @return array{confidence:float,approved:int} */
    private function sample(array $sample): array
    {
        if (! array_key_exists('confidence', $sample) || ! array_key_exists('approved', $sample)) {
            throw new InvalidArgumentException('Every calibration sample requires confidence and approved fields');
        }

        return [
            'confidence' => $this->probability($sample['confidence'], 'sample confidence'),
            'approved' => match ($sample['approved']) {
                true, 1, '1' => 1,
                false, 0, '0' => 0,
                default => throw new InvalidArgumentException('Sample approved must be boolean'),
            },
        ];
    }

    /** @param list<array{confidence:float,approved:int}> $samples */
    private function bins(array $samples, int $binCount): array
    {
        $buckets = array_fill(0, $binCount, []);
        foreach ($samples as $sample) {
            $index = min($binCount - 1, (int) floor($sample['confidence'] * $binCount));
            $buckets[$index][] = $sample;
        }

        $bins = [];
        foreach ($buckets as $index => $bucket) {
            if ($bucket === []) {
                continue;
            }
            $meanConfidence = $this->mean(array_column($bucket, 'confidence'));
            $approvalRate = $this->mean(array_column($bucket, 'approved'));
            $bins[] = [
                'index' => $index,
                'lower_bound' => $this->round($index / $binCount),
                'upper_bound' => $this->round(($index + 1) / $binCount),
                'sample_count' => count($bucket),
                'mean_confidence' => $this->round($meanConfidence),
                'approval_rate' => $this->round($approvalRate),
                'calibration_gap' => $this->round(abs($meanConfidence - $approvalRate)),
            ];
        }

        return $bins;
    }

    private function expectedCalibrationError(array $bins, int $sampleCount): float
    {
        return $this->round(array_sum(array_map(
            static fn (array $bin): float => ($bin['sample_count'] / $sampleCount) * $bin['calibration_gap'],
            $bins
        )));
    }

    private function brierScore(array $samples): float
    {
        return $this->round($this->mean(array_map(
            static fn (array $sample): float => ($sample['confidence'] - $sample['approved']) ** 2,
            $samples
        )));
    }

    private function auroc(array $samples): float
    {
        usort($samples, static fn (array $left, array $right): int => $left['confidence'] <=> $right['confidence']);
        $positiveRankSum = 0.0;
        $positiveCount = array_sum(array_column($samples, 'approved'));
        $negativeCount = count($samples) - $positiveCount;
        $index = 0;
        while ($index < count($samples)) {
            $end = $index;
            while ($end + 1 < count($samples) && $samples[$end + 1]['confidence'] === $samples[$index]['confidence']) {
                $end++;
            }
            $averageRank = (($index + 1) + ($end + 1)) / 2;
            for ($cursor = $index; $cursor <= $end; $cursor++) {
                if ($samples[$cursor]['approved'] === 1) {
                    $positiveRankSum += $averageRank;
                }
            }
            $index = $end + 1;
        }

        return $this->round(($positiveRankSum - ($positiveCount * ($positiveCount + 1) / 2)) / ($positiveCount * $negativeCount));
    }

    private function monotonicityViolations(array $bins): int
    {
        $violations = 0;
        for ($index = 1; $index < count($bins); $index++) {
            if ($bins[$index]['approval_rate'] < $bins[$index - 1]['approval_rate']) {
                $violations++;
            }
        }

        return $violations;
    }

    private function mean(array $values): float
    {
        return array_sum($values) / count($values);
    }

    private function probability(mixed $value, string $field): float
    {
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
            throw new InvalidArgumentException("{$field} must be between 0 and 1");
        }

        return (float) $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        return $this->boundedInt($value, 1, 5000, $field);
    }

    private function boundedInt(mixed $value, int $minimum, int $maximum, string $field): int
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("{$field} must be an integer between {$minimum} and {$maximum}");
        }

        return $value;
    }

    private function round(float $value): float
    {
        return round($value, 4);
    }
}
