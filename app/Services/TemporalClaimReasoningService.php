<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * FC-7: Temporal Claim Reasoning — TSVer (EMNLP 2025)
 *
 * Detects time-sensitive claims and flags evidence that is stale relative
 * to the claim's temporal scope. Adjusts verification confidence when
 * evidence age doesn't match the claim's time requirements.
 *
 * Pipeline:
 *   1. Detect if claim is temporally sensitive (keywords + date references)
 *   2. Extract time references from the claim (years, relative dates, periods)
 *   3. Assess each evidence item's staleness relative to the claim's timeframe
 *   4. Adjust verdict confidence based on evidence freshness
 *
 * Example: "The current population of Tokyo is 14 million"
 *   - Temporal: yes (contains "current")
 *   - Claim timeframe: present day
 *   - Evidence from 2018: STALE (flag, reduce confidence)
 *   - Evidence from 2025: FRESH (keep full confidence)
 *
 * Reference: TSVer (Temporal-Sensitive Verification, EMNLP 2025)
 */
class TemporalClaimReasoningService
{
    /** Evidence older than this relative to claim timeframe is stale */
    public const STALE_THRESHOLD_YEARS = 3;

    /** Confidence penalty per stale evidence item (multiplicative) */
    public const STALE_PENALTY_FACTOR = 0.85;

    /** Maximum confidence reduction from staleness */
    public const MAX_STALENESS_REDUCTION = 0.40;

    /** Temporal claim keywords — presence suggests time-sensitivity */
    private const TEMPORAL_CLAIM_KEYWORDS = [
        'current', 'currently', 'now', 'today', 'present', 'recent', 'recently',
        'latest', 'as of', 'this year', 'this month', 'in 2024', 'in 2025', 'in 2026',
        'still', 'no longer', 'anymore', 'has since', 'was recently', 'just',
        'upcoming', 'will', 'is expected', 'is set to', 'is scheduled',
    ];

    /** Historical claim keywords — staleness doesn't apply */
    private const HISTORICAL_KEYWORDS = [
        'was born', 'died', 'founded', 'established', 'invented', 'discovered',
        'in 18', 'in 19', 'century', 'historical', 'ancient', 'original',
        'first', 'earliest', 'war', 'battle', 'treaty', 'colonial',
    ];

    /** Relative time expressions mapped to approximate year offsets */
    private const RELATIVE_TIME_MAP = [
        'yesterday' => 0, 'last week' => 0, 'last month' => 0,
        'last year' => -1, 'two years ago' => -2, 'three years ago' => -3,
        'five years ago' => -5, 'a decade ago' => -10,
        'this year' => 0, 'this month' => 0, 'this week' => 0,
    ];

    // =========================================================================
    // Temporal claim detection (pure — unit-testable)
    // =========================================================================

    /**
     * Detect if a claim is temporally sensitive.
     *
     * @param string $claim The claim text
     * @return array{is_temporal: bool, reason: string, temporal_type: string}
     */
    public function analyzeTemporality(string $claim): array
    {
        $lower = mb_strtolower($claim);

        // Historical claims — staleness doesn't apply
        foreach (self::HISTORICAL_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return [
                    'is_temporal' => false,
                    'reason' => "Historical claim (contains '{$kw}')",
                    'temporal_type' => 'historical',
                ];
            }
        }

        // Check for explicit temporal keywords
        foreach (self::TEMPORAL_CLAIM_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return [
                    'is_temporal' => true,
                    'reason' => "Contains temporal keyword '{$kw}'",
                    'temporal_type' => 'current_state',
                ];
            }
        }

        // Check for explicit year references in the claim
        $years = $this->extractYearReferences($claim);
        if (!empty($years)) {
            $currentYear = (int) date('Y');
            $maxYear = max($years);
            if ($maxYear >= $currentYear - 2) {
                return [
                    'is_temporal' => true,
                    'reason' => "References recent year ({$maxYear})",
                    'temporal_type' => 'dated',
                ];
            }
        }

        return [
            'is_temporal' => false,
            'reason' => 'No temporal signals detected',
            'temporal_type' => 'atemporal',
        ];
    }

    // =========================================================================
    // Time reference extraction (pure — unit-testable)
    // =========================================================================

    /**
     * Extract year references from a claim.
     *
     * @return int[] Array of years found
     */
    public function extractYearReferences(string $claim): array
    {
        $years = [];

        // Explicit 4-digit years (1800-2099)
        if (preg_match_all('/\b(1[89]\d{2}|20\d{2})\b/', $claim, $matches)) {
            $years = array_map('intval', $matches[1]);
        }

        // Relative time expressions
        $lower = mb_strtolower($claim);
        $currentYear = (int) date('Y');
        foreach (self::RELATIVE_TIME_MAP as $expr => $offset) {
            if (str_contains($lower, $expr)) {
                $years[] = $currentYear + $offset;
            }
        }

        return array_values(array_unique($years));
    }

    /**
     * Determine the claim's target timeframe (the period the claim refers to).
     *
     * @return array{target_year: int|null, tolerance_years: int}
     */
    public function getClaimTimeframe(string $claim): array
    {
        $years = $this->extractYearReferences($claim);
        $analysis = $this->analyzeTemporality($claim);

        if (!empty($years)) {
            // Use the most recent year mentioned
            return [
                'target_year' => max($years),
                'tolerance_years' => self::STALE_THRESHOLD_YEARS,
            ];
        }

        if ($analysis['temporal_type'] === 'current_state') {
            return [
                'target_year' => (int) date('Y'),
                'tolerance_years' => self::STALE_THRESHOLD_YEARS,
            ];
        }

        // Non-temporal claim — no target year
        return [
            'target_year' => null,
            'tolerance_years' => self::STALE_THRESHOLD_YEARS,
        ];
    }

    // =========================================================================
    // Evidence staleness assessment (pure — unit-testable)
    // =========================================================================

    /**
     * Assess staleness of evidence items relative to the claim's timeframe.
     *
     * @param array $evidence Evidence items with 'published_at' or 'created_at'
     * @param int $targetYear The year the claim refers to
     * @param int $toleranceYears How many years of age is acceptable
     * @return array Evidence items annotated with staleness metadata
     */
    public function assessEvidenceStaleness(array $evidence, int $targetYear, int $toleranceYears): array
    {
        foreach ($evidence as &$item) {
            $evidenceDate = $item['published_at'] ?? $item['created_at'] ?? null;
            $evidenceYear = $this->extractYearFromDate($evidenceDate);

            if ($evidenceYear === null) {
                $item['temporal'] = [
                    'evidence_year' => null,
                    'is_stale' => false,
                    'staleness_note' => 'Unknown publication date — cannot assess',
                    'years_offset' => null,
                ];
                continue;
            }

            $offset = abs($targetYear - $evidenceYear);
            $isStale = $offset > $toleranceYears;

            $item['temporal'] = [
                'evidence_year' => $evidenceYear,
                'is_stale' => $isStale,
                'years_offset' => $offset,
                'staleness_note' => $isStale
                    ? "Evidence from {$evidenceYear} is {$offset} years from claim target {$targetYear} (threshold: {$toleranceYears})"
                    : "Evidence from {$evidenceYear} is within {$toleranceYears}-year threshold",
            ];
        }
        unset($item);

        return $evidence;
    }

    // =========================================================================
    // Verdict adjustment (pure — unit-testable)
    // =========================================================================

    /**
     * Adjust a verdict's confidence based on evidence staleness.
     *
     * @param array $verdict The original verdict
     * @param array $evidence Evidence items with 'temporal' metadata from assessEvidenceStaleness()
     * @return array Adjusted verdict with temporal metadata
     */
    public function adjustVerdictForStaleness(array $verdict, array $evidence): array
    {
        $staleCount = 0;
        $freshCount = 0;
        $unknownCount = 0;

        foreach ($evidence as $item) {
            $temporal = $item['temporal'] ?? null;
            if ($temporal === null) {
                $unknownCount++;
            } elseif ($temporal['is_stale']) {
                $staleCount++;
            } else {
                $freshCount++;
            }
        }

        $total = $staleCount + $freshCount + $unknownCount;
        if ($total === 0) {
            $verdict['temporal_assessment'] = [
                'stale_count' => 0,
                'fresh_count' => 0,
                'adjustment' => 'none',
                'note' => 'No evidence to assess',
            ];
            return $verdict;
        }

        $staleRatio = $staleCount / $total;
        $originalConfidence = $verdict['confidence'] ?? 0.5;

        // Apply penalty proportional to stale evidence ratio
        $penalty = 1.0;
        if ($staleCount > 0) {
            $penalty = pow(self::STALE_PENALTY_FACTOR, $staleCount);
            $penalty = max(1.0 - self::MAX_STALENESS_REDUCTION, $penalty);
        }

        $adjustedConfidence = round($originalConfidence * $penalty, 3);
        $adjustment = $adjustedConfidence < $originalConfidence ? 'reduced' : 'none';

        // If ALL evidence is stale, downgrade supported verdicts
        if ($staleRatio >= 0.8 && $verdict['verdict'] === 'supported') {
            $verdict['verdict'] = 'inconclusive';
            $adjustment = 'verdict_downgraded';
        }

        $verdict['confidence'] = $adjustedConfidence;
        $verdict['temporal_assessment'] = [
            'stale_count' => $staleCount,
            'fresh_count' => $freshCount,
            'unknown_count' => $unknownCount,
            'stale_ratio' => round($staleRatio, 2),
            'penalty_applied' => round(1.0 - $penalty, 3),
            'original_confidence' => $originalConfidence,
            'adjustment' => $adjustment,
            'note' => $staleCount > 0
                ? "{$staleCount}/{$total} evidence items are stale — confidence reduced by " . round((1 - $penalty) * 100, 1) . '%'
                : 'All evidence is within temporal threshold',
        ];

        return $verdict;
    }

    // =========================================================================
    // Full temporal reasoning pass
    // =========================================================================

    /**
     * Run full temporal reasoning on a claim + evidence + verdict.
     * This is the main integration point for ClaimVerificationService.
     *
     * @param string $claim The claim text
     * @param array $evidence Evidence items
     * @param array $verdict The existing verdict
     * @return array Adjusted verdict with temporal metadata (unchanged if non-temporal claim)
     */
    public function reason(string $claim, array $evidence, array $verdict): array
    {
        $analysis = $this->analyzeTemporality($claim);

        if (!$analysis['is_temporal']) {
            $verdict['temporal_analysis'] = $analysis;
            return $verdict;
        }

        $timeframe = $this->getClaimTimeframe($claim);
        if ($timeframe['target_year'] === null) {
            $verdict['temporal_analysis'] = $analysis;
            return $verdict;
        }

        // Assess evidence staleness
        $annotatedEvidence = $this->assessEvidenceStaleness(
            $evidence,
            $timeframe['target_year'],
            $timeframe['tolerance_years']
        );

        // Adjust verdict
        $adjusted = $this->adjustVerdictForStaleness($verdict, $annotatedEvidence);
        $adjusted['temporal_analysis'] = array_merge($analysis, [
            'target_year' => $timeframe['target_year'],
            'tolerance_years' => $timeframe['tolerance_years'],
        ]);

        return $adjusted;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Extract a year from a date string.
     */
    private function extractYearFromDate(?string $date): ?int
    {
        if (empty($date)) {
            return null;
        }

        if (preg_match('/\b(1[89]\d{2}|20\d{2})\b/', $date, $m)) {
            return (int) $m[1];
        }

        try {
            return (int) Carbon::parse($date)->year;
        } catch (\Exception $e) {
            return null;
        }
    }
}
