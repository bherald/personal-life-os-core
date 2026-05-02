<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * N105 — spaCy NLP Service
 * N106 — Refactored to use ComputeRouterService for dynamic compute routing
 *
 * Wraps the Python spaCy en_core_web_sm model for CPU-only NLP extraction.
 * Provides entity extraction (persons, places, dates, orgs) + genealogy-specific
 * fact extraction from research finding text.
 *
 * Performance: 50-200ms per call (CPU, en_core_web_sm ~15MB)
 * Fallback: Returns null if no compute instance with 'nlp' capability is available.
 *
 * To install:
 *   pip install spacy && python3 -m spacy download en_core_web_sm
 */
class SpacyNLPService
{
    private const CACHE_TTL = 300; // 5 min — same text = same extraction

    private ?ComputeRouterService $computeRouter = null;
    private ?bool $available = null;

    private function getComputeRouter(): ComputeRouterService
    {
        if ($this->computeRouter === null) {
            $this->computeRouter = app(ComputeRouterService::class);
        }
        return $this->computeRouter;
    }

    /**
     * Extract entities and genealogy facts from text.
     * Returns null if no NLP compute instance is available.
     *
     * @param string $text Input text (capped at 5000 chars in Python)
     * @return array|null ['dates'=>[], 'places'=>[], 'persons'=>[], 'facts'=>['birth_date'=>..., ...]]
     */
    public function extract(string $text): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $cacheKey = 'spacy_nlp:' . md5($text);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $input = json_encode(['text' => $text]);
            $result = $this->getComputeRouter()->executeScript(
                'nlp',
                'nlp_extract.py',
                $input,
                [],
                10
            );

            if (!$result['success'] || empty($result['output'])) {
                return null;
            }

            $parsed = json_decode($result['output'], true);
            if (!is_array($parsed) || isset($parsed['error'])) {
                if (($parsed['error'] ?? '') === 'spacy_not_available') {
                    $this->available = false;
                }
                return null;
            }

            Cache::put($cacheKey, $parsed, self::CACHE_TTL);
            return $parsed;

        } catch (\Exception $e) {
            Log::warning('SpacyNLPService: extraction failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Convert spaCy extraction output to fact_update proposals (same format as regex extractor).
     */
    public function toFactProposals(array $extraction, int $personId, string $evidenceSummary): array
    {
        $proposals = [];
        $facts = $extraction['facts'] ?? [];
        $fieldMap = [
            'birth_date'  => ['fact_update', 'birth_date',  0.55],
            'death_date'  => ['fact_update', 'death_date',  0.55],
            'birth_place' => ['fact_update', 'birth_place', 0.50],
            'death_place' => ['fact_update', 'death_place', 0.50],
            'occupation'  => ['fact_update', 'occupation',  0.45],
        ];

        foreach ($fieldMap as $key => [$changeType, $fieldName, $confidence]) {
            if (!empty($facts[$key])) {
                $proposals[] = [
                    'person_id'       => $personId,
                    'change_type'     => $changeType,
                    'field_name'      => $fieldName,
                    'proposed_value'  => trim($facts[$key]),
                    'confidence'      => $confidence,
                    'evidence_sources' => ['spacy-extracted from research findings'],
                    'evidence_summary' => substr($evidenceSummary, 0, 300),
                ];
            }
        }

        return $proposals;
    }

    /**
     * Check if any compute instance with NLP capability is available.
     * Caches result for the process lifetime.
     */
    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $this->available = $this->getComputeRouter()->route('nlp') !== null;
        return $this->available;
    }
}
