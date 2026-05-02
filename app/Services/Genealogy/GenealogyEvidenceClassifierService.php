<?php

namespace App\Services\Genealogy;

/**
 * Evidence classifier — answers "what kind of genealogy evidence is this?"
 * using only the file path + filename, with no I/O and no AI call.
 *
 * Pure function of string input → {media_type, matched_keywords}.
 * Separates tool-routing (extension classifier) from evidence classification
 * (this service). Both read different config domains: extensions live in
 * `config/file_types.php`; keyword heuristics live here (by design — this
 * data is a genealogy-domain signal, not a file-system signal).
 *
 * Priority order if multiple keyword families hit: obituary > census >
 * certificate > military > document. Matches earliest keyword wins —
 * `matched_keywords` returns every keyword that triggered, so callers
 * can audit why a classification landed where it did.
 *
 * Extracted from `GenealogyDocumentIngestionService::TYPE_KEYWORDS` in
 * Phase 2.1 of the ingest/file-scanning unification sprint. Keywords are
 * unchanged — pure refactor.
 */
final class GenealogyEvidenceClassifierService
{
    /**
     * Keyword map by media type. Order matters: the array keys are walked
     * in order when classifying, so the first-matching type wins.
     *
     * @var array<string, array<int, string>>
     */
    private const TYPE_KEYWORDS = [
        'obituary' => [
            'obituary', 'obit', 'death_notice', 'death notice', 'memorial', 'funeral home',
        ],
        'census' => [
            'census', '1790', '1800', '1810', '1820', '1830', '1840', '1850', '1860',
            '1870', '1880', '1900', '1910', '1920', '1930', '1940', '1950',
        ],
        'certificate' => [
            'certificate', 'cert_', '_cert', 'birth cert', 'death cert', 'marriage cert',
            'marriage record', 'birth record', 'death record', 'baptism', 'christening',
        ],
        'military' => [
            'military', 'draft card', 'service record', 'discharge', 'wwi', 'wwii',
            'ww1', 'ww2', 'civil war', 'pension', 'enlistment', 'muster', 'soldiers',
        ],
    ];

    /**
     * Valid media types, including the default bucket.
     *
     * @var array<int, string>
     */
    public const VALID_TYPES = ['obituary', 'census', 'certificate', 'military', 'document'];

    /**
     * Classify a file by path + filename into a media_type.
     *
     * @return array{media_type: string, matched_keywords: array<int, string>}
     */
    public function classify(string $path, string $filename): array
    {
        $haystack = strtolower($path . ' ' . $filename);
        $allMatches = [];

        foreach (self::TYPE_KEYWORDS as $type => $keywords) {
            $matches = [];
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    $matches[] = $keyword;
                }
            }
            if ($matches !== []) {
                return [
                    'media_type' => $type,
                    'matched_keywords' => array_values(array_unique($matches)),
                ];
            }
            $allMatches[$type] = $matches;
        }

        return [
            'media_type' => 'document',
            'matched_keywords' => [],
        ];
    }

    /**
     * True if the given string is a recognized media type (including 'document' fallback).
     */
    public function isValidType(string $candidate): bool
    {
        return in_array(strtolower($candidate), self::VALID_TYPES, true);
    }

    /**
     * Coerce arbitrary input to a valid media_type. Used by callers that
     * accept an AI response and need a safe fallback.
     */
    public function normalize(string $candidate): string
    {
        $needle = strtolower(trim($candidate));
        foreach (self::VALID_TYPES as $type) {
            if (str_contains($needle, $type)) {
                return $type;
            }
        }

        return 'document';
    }
}
