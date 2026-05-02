<?php

namespace App\Services\Genealogy\Support;

/**
 * Temporal-proximity check for genealogy source/event proposals.
 *
 * Single source of truth for the gap/severity calculation that lives
 * in three places by the time of GPS Sprint:
 *
 *   - ReviewContextEnrichmentService::detectTemporalMismatch
 *     (display-time signal in the review UI)
 *   - PersonService::checkTemporalProximity
 *     (apply-time backstop on source_add proposals)
 *   - ProposalValidatorService::checkTemporal
 *     (creation-time gate before genealogy_proposed_changes insert)
 *
 * Pre-extraction, the same logic lived in two of those callers and
 * was about to be cloned into the third — this class consolidates so
 * adjusting the margin or severity threshold is a one-file change.
 *
 * Operator-facing context: the Mary Billington defect (1652-1718,
 * proposed Civil War 1864 pension records) is the canonical case
 * this checker catches. Surname-only agent searches without a
 * lifetime filter produce these every cycle.
 *
 * Margins (all caller-shared):
 *   birth - 50  → ancestral context (parents' marriage records)
 *   death + 100 → estate / probate / descendants citing ancestor
 *
 * Severity thresholds (gap from actual lifetime edge, not from
 * allowed-margin edge — operator mental model is "146 years past
 * death," not "46 years past my +100 margin"):
 *   gap > 100 → 'far' (Civil War source for 1700s person)
 *   gap 1-100 → 'near' (Revolutionary War source for 1720s person)
 *   gap = 0   → not flagged
 */
final class TemporalProximityChecker
{
    private const BIRTH_MARGIN = 50;
    private const DEATH_MARGIN = 100;
    private const FAR_THRESHOLD = 100;

    /**
     * Run the check. Returns null when no mismatch (or insufficient
     * data); structured array when at least one extracted year falls
     * outside the lifetime + margin.
     *
     * @param int|null $birthYear Person's birth year, null if unknown
     * @param int|null $deathYear Person's death year, null if unknown
     * @param string   $haystack  Combined evidence text (summary +
     *                            proposed_value, etc.) to scan for years
     *
     * @return array{worst_year: int, person_birth: int|null, person_death: int|null, gap_years: int, matched_years: array<int, int>, severity: string}|null
     */
    public static function check(?int $birthYear, ?int $deathYear, string $haystack): ?array
    {
        if ($birthYear === null && $deathYear === null) {
            return null;
        }
        // If we only have one anchor, assume a 100-year lifespan to bound the other.
        $rangeStart = $birthYear ?? ($deathYear - 100);
        $rangeEnd   = $deathYear ?? ($birthYear + 100);
        $allowedMin = $rangeStart - self::BIRTH_MARGIN;
        $allowedMax = $rangeEnd + self::DEATH_MARGIN;

        $haystack = trim($haystack);
        if ($haystack === '') {
            return null;
        }

        // Extract 4-digit years 1500-2099 (genealogy-relevant range).
        if (! preg_match_all('/\b(1[5-9]\d{2}|20\d{2})\b/', $haystack, $m)) {
            return null;
        }
        $years = array_values(array_unique(array_map('intval', $m[1])));
        sort($years);

        // Any year in range = the source has at least some lifetime
        // overlap. Bail (no mismatch) so we don't false-positive on a
        // 1864 source that incidentally mentions an 1730 footnote.
        $inRange = array_filter($years, fn (int $y) => $y >= $allowedMin && $y <= $allowedMax);
        if ($inRange !== []) {
            return null;
        }

        // No year in range — pick the year farthest from the actual
        // lifetime (not from the allowed-margin edge) and report.
        $worst = $years[0];
        $gap = 0;
        foreach ($years as $y) {
            $thisGap = ($y < $rangeStart) ? ($rangeStart - $y) : ($y - $rangeEnd);
            if ($thisGap > $gap) {
                $worst = $y;
                $gap = $thisGap;
            }
        }
        $severity = $gap > self::FAR_THRESHOLD ? 'far' : 'near';

        return [
            'worst_year' => $worst,
            'person_birth' => $birthYear,
            'person_death' => $deathYear,
            'gap_years' => $gap,
            'matched_years' => $years,
            'severity' => $severity,
        ];
    }

    /**
     * Convenience: extract a 4-digit year from a GEDCOM-style date
     * string ("1652", "30 SEP 1630", "abt 1700", "1700-1750", etc.)
     * Genealogy-relevant range: 1500-2099 (filters out '0001', stray
     * digit-strings, etc.).
     */
    public static function extractYear(mixed $dateStr): ?int
    {
        if (! is_scalar($dateStr)) {
            return null;
        }
        if (! preg_match('/\b(1[5-9]\d{2}|20\d{2})\b/', (string) $dateStr, $m)) {
            return null;
        }
        return (int) $m[1];
    }
}
