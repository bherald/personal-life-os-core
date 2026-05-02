<?php

namespace App\Services\Genealogy\Support;

/**
 * Proximity-based full-name matcher for genealogy evidence vetting.
 *
 * Rejects cross-document token co-occurrence (e.g., "Michael" in one article
 * and "Smith" in another, or a FindAGrave page that contains both tokens in
 * unrelated memorials). Genealogy evidence must tie to the same person entity,
 * not the same tokens — GPS Standard #3 correlation, NGS Standard 45.
 */
class ProximityNameMatcher
{
    public const DEFAULT_WINDOW = 3;

    public static function matchesFullName(string $text, string $given, string $surname, ?int $window = null, ?array $givenVariants = null): bool
    {
        return self::explain($text, $given, $surname, $window, $givenVariants)['matched'];
    }

    /**
     * @param array<string>|null $givenVariants optional — additional
     *   lowercase tokens to accept as equivalent to the given name.
     *   Typically produced by `GivenNameVariants::variantsFor($given)`
     *   so `Mike` in the body can satisfy a target of `Michael`. When
     *   null, only the given name itself is matched.
     * @return array{matched: bool, reason: string, nearest_gap_tokens: int|null}
     */
    public static function explain(string $text, string $given, string $surname, ?int $window = null, ?array $givenVariants = null): array
    {
        if ($window === null) {
            $window = (int) config('genealogy.name_match.proximity_window', self::DEFAULT_WINDOW);
        }

        $given = self::firstToken($given);
        $surname = self::firstToken($surname);

        if ($given === '' && $surname === '') {
            return ['matched' => false, 'reason' => 'both names empty', 'nearest_gap_tokens' => null];
        }
        if ($given === '') {
            return ['matched' => false, 'reason' => 'given name empty', 'nearest_gap_tokens' => null];
        }
        if ($surname === '') {
            return ['matched' => false, 'reason' => 'surname empty', 'nearest_gap_tokens' => null];
        }

        $acceptedGivens = [$given];
        if ($givenVariants !== null) {
            foreach ($givenVariants as $variant) {
                $variant = strtolower(trim((string) $variant));
                if ($variant !== '' && ! in_array($variant, $acceptedGivens, true)) {
                    $acceptedGivens[] = $variant;
                }
            }
        }

        $tokens = self::tokenize($text);
        if (empty($tokens)) {
            return ['matched' => false, 'reason' => 'no text tokens', 'nearest_gap_tokens' => null];
        }

        $givenPositions = [];
        $surnamePositions = [];
        foreach ($tokens as $i => $tok) {
            if (in_array($tok, $acceptedGivens, true)) {
                $givenPositions[] = $i;
            }
            if ($tok === $surname) {
                $surnamePositions[] = $i;
            }
        }

        if (empty($givenPositions) && empty($surnamePositions)) {
            return ['matched' => false, 'reason' => 'neither name found', 'nearest_gap_tokens' => null];
        }
        if (empty($givenPositions)) {
            return ['matched' => false, 'reason' => 'given name not found', 'nearest_gap_tokens' => null];
        }
        if (empty($surnamePositions)) {
            return ['matched' => false, 'reason' => 'surname not found', 'nearest_gap_tokens' => null];
        }

        $minGap = PHP_INT_MAX;
        foreach ($givenPositions as $gp) {
            foreach ($surnamePositions as $sp) {
                $gap = abs($gp - $sp) - 1;
                if ($gap < $minGap) {
                    $minGap = $gap;
                }
            }
        }

        if ($minGap <= $window) {
            $reason = $minGap === 0 ? 'adjacent' : "within {$minGap} token(s)";
            return ['matched' => true, 'reason' => $reason, 'nearest_gap_tokens' => $minGap];
        }

        return ['matched' => false, 'reason' => "too distant ({$minGap} tokens between)", 'nearest_gap_tokens' => $minGap];
    }

    /**
     * Return the minimum token-gap between any occurrence of a token in
     * $setA and any occurrence of a token in $setB within $text, or null
     * when either set has no matching tokens in $text. Used by 2.1e
     * relationship-proximity checks where we need to verify a relative's
     * name falls near the target's name span.
     *
     * Identical positions are skipped so a shared token (e.g., both sets
     * contain the same surname) does not report gap = -1.
     *
     * @param array<string> $setA
     * @param array<string> $setB
     */
    public static function minCrossSetGap(string $text, array $setA, array $setB): ?int
    {
        $tokens = self::tokenize($text);
        if (empty($tokens)) {
            return null;
        }

        $normA = array_filter(array_map('strtolower', array_map('trim', $setA)));
        $normB = array_filter(array_map('strtolower', array_map('trim', $setB)));
        if (empty($normA) || empty($normB)) {
            return null;
        }

        $positionsA = [];
        $positionsB = [];
        foreach ($tokens as $i => $tok) {
            if (in_array($tok, $normA, true)) {
                $positionsA[] = $i;
            }
            if (in_array($tok, $normB, true)) {
                $positionsB[] = $i;
            }
        }

        if (empty($positionsA) || empty($positionsB)) {
            return null;
        }

        $minGap = PHP_INT_MAX;
        foreach ($positionsA as $a) {
            foreach ($positionsB as $b) {
                if ($a === $b) {
                    continue;
                }
                $gap = abs($a - $b) - 1;
                if ($gap < $minGap) {
                    $minGap = $gap;
                }
            }
        }

        return $minGap === PHP_INT_MAX ? null : $minGap;
    }

    private static function firstToken(string $name): string
    {
        $tokens = self::tokenize($name);
        return $tokens[0] ?? '';
    }

    private static function tokenize(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[.,:;!?()\[\]{}"\/\\\\]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', trim($text));
        if ($text === '') {
            return [];
        }
        return explode(' ', $text);
    }
}
