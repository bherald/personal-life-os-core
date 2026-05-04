<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Domain Credibility Service — Framework-wide shared domain trust scores.
 *
 * Single source of truth for domain credibility scoring, backed by the
 * `domain_credibility` MySQL table. Used by EvidenceRetrieverService,
 * ClaimVerificationService, SourceCredibilityService, and any future
 * service needing domain trust evaluation.
 *
 * Loads from DB with 5-minute cache. Falls back to hardcoded defaults
 * if the table is empty or unavailable.
 *
 * Uses raw SQL per project standards — NO Eloquent/Query Builder.
 */
class DomainCredibilityService
{
    /** @var int Cache TTL in seconds (5 minutes) */
    private const CACHE_TTL = 300;

    private const CACHE_KEY = 'domain_credibility_scores';

    /** @var float Default score for unknown domains */
    private const DEFAULT_SCORE = 0.50;

    /** @var array Minimal hardcoded fallback if DB is unavailable */
    private const FALLBACK_SCORES = [
        'gov' => 0.95,
        'edu' => 0.92,
        'reuters.com' => 0.95,
        'apnews.com' => 0.95,
        'nature.com' => 0.96,
        'bbc.com' => 0.92,
        'nytimes.com' => 0.86,
        'wikipedia.org' => 0.72,
        'dailymail.co.uk' => 0.42,
        'infowars.com' => 0.15,
        'naturalnews.com' => 0.10,
    ];

    /** @var array|null Loaded domain scores (domain => score) */
    private ?array $scores = null;

    /** @var array|null TLD patterns loaded from DB */
    private ?array $tldPatterns = null;

    /**
     * Get credibility score for a domain.
     *
     * Lookup order:
     * 1. Exact domain match from DB
     * 2. TLD pattern match (.gov, .edu, .ac.uk, etc.)
     * 3. Partial domain match (subdomain contains known domain)
     * 4. Default score (0.50)
     */
    public function getScore(string $domain): float
    {
        if (empty($domain)) {
            return self::DEFAULT_SCORE;
        }

        $domain = strtolower(trim($domain));
        $this->ensureLoaded();

        // 1. Exact match
        if (isset($this->scores[$domain])) {
            return $this->scores[$domain];
        }

        // 2. TLD pattern match
        foreach ($this->tldPatterns as $pattern => $score) {
            if (str_ends_with($domain, '.'.$pattern)) {
                return $score;
            }
        }

        // 3. Partial match (subdomain of known domain)
        foreach ($this->scores as $known => $score) {
            if (str_contains($domain, $known)) {
                return $score;
            }
        }

        return self::DEFAULT_SCORE;
    }

    /**
     * Get the tier (1-5) for a domain.
     *
     * @return int Tier number, or 3 for unknown domains
     */
    public function getTier(string $domain): int
    {
        $score = $this->getScore($domain);

        if ($score >= 0.88) {
            return 1;
        }
        if ($score >= 0.75) {
            return 2;
        }
        if ($score >= 0.70) {
            return 3;
        }
        if ($score >= 0.50) {
            return 4;
        }

        return 5;
    }

    /**
     * Get all domain scores (for admin/debugging).
     *
     * @return array Array of domain_credibility rows
     */
    public function getAll(): array
    {
        try {
            return DB::select('
                SELECT domain, credibility_score, tier, category, is_tld_pattern, is_active, notes
                FROM domain_credibility
                WHERE is_active = 1
                ORDER BY tier ASC, credibility_score DESC
            ');
        } catch (\Exception $e) {
            Log::warning('DomainCredibilityService: Failed to load all scores', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Add or update a domain score.
     *
     * @return bool Success
     */
    public function upsert(string $domain, float $score, int $tier, ?string $category = null, ?string $notes = null, bool $isTldPattern = false): bool
    {
        try {
            DB::insert('
                INSERT INTO domain_credibility (domain, credibility_score, tier, category, is_tld_pattern, notes)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    credibility_score = VALUES(credibility_score),
                    tier = VALUES(tier),
                    category = COALESCE(VALUES(category), category),
                    notes = COALESCE(VALUES(notes), notes),
                    updated_at = NOW()
            ', [$domain, $score, $tier, $category, $isTldPattern ? 1 : 0, $notes]);

            $this->clearCache();

            return true;
        } catch (\Exception $e) {
            Log::error('DomainCredibilityService: Upsert failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Clear cached scores (after DB changes).
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->scores = null;
        $this->tldPatterns = null;
    }

    /**
     * Load scores from DB (cached) or fallback.
     */
    private function ensureLoaded(): void
    {
        if ($this->scores !== null) {
            return;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            $this->scores = $cached['scores'];
            $this->tldPatterns = $cached['tld_patterns'];

            return;
        }

        $this->scores = [];
        $this->tldPatterns = [];

        try {
            $rows = DB::select('
                SELECT domain, credibility_score, is_tld_pattern
                FROM domain_credibility
                WHERE is_active = 1
            ');

            foreach ($rows as $row) {
                if ($row->is_tld_pattern) {
                    $this->tldPatterns[$row->domain] = (float) $row->credibility_score;
                } else {
                    $this->scores[$row->domain] = (float) $row->credibility_score;
                }
            }

            if (empty($this->scores)) {
                $this->loadFallback();
            }
        } catch (\Exception $e) {
            Log::warning('DomainCredibilityService: DB load failed, using fallback', [
                'error' => $e->getMessage(),
            ]);
            $this->loadFallback();
        }

        Cache::put(self::CACHE_KEY, [
            'scores' => $this->scores,
            'tld_patterns' => $this->tldPatterns,
        ], self::CACHE_TTL);
    }

    /**
     * Load hardcoded fallback scores.
     */
    private function loadFallback(): void
    {
        $this->scores = self::FALLBACK_SCORES;
        $this->tldPatterns = [
            'gov' => 0.95,
            'gov.uk' => 0.95,
            'gov.au' => 0.95,
            'edu' => 0.92,
            'ac.uk' => 0.92,
        ];
    }
}
