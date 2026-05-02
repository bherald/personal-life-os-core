<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bias Rating Service — raw SQL only, no Eloquent models.
 *
 * REF-006: Consolidated from BiasRating, PolarizingTopic, EmotionalLanguageWord
 * models. All 3 models deleted — logic moved here as static helper methods.
 */
class BiasRatingService
{
    public function getRating(string $sourceName, ?string $feedUrl = null): ?array
    {
        $cacheKey = 'bias_rating:'.md5(strtolower($sourceName).($feedUrl ?? ''));

        return Cache::remember($cacheKey, 3600, function () use ($sourceName, $feedUrl) {
            $rating = $this->findBySource($sourceName);

            if (! $rating && $feedUrl) {
                $domain = $this->extractDomain($feedUrl);
                if ($domain) {
                    $rating = $this->findBySource($domain);
                    if ($rating) {
                        $rating->match_method = 'feed_url_'.($rating->match_method ?? 'unknown');
                    }
                }
            }

            if (! $rating) {
                return null;
            }

            return [
                'source' => $rating->news_source,
                'rating' => $rating->rating,
                'rating_num' => $rating->rating_num,
                'emoji' => self::ratingEmoji($rating->rating),
                'color' => self::ratingColor($rating->rating),
                'confidence' => $rating->confidence_level,
                'type' => $rating->type,
                'data_source' => $rating->data_source,
                'match_method' => $rating->match_method ?? 'unknown',
                'mbfc_factual_rating' => $rating->mbfc_factual_rating,
                'mbfc_credibility_score' => $rating->mbfc_credibility_score,
                'is_polarizing_source' => $rating->is_polarizing_source,
            ];
        });
    }

    public function enrichArticle(array $article): array
    {
        $sourceName = $article['source'] ?? $article['source_name'] ?? null;
        $feedUrl = $article['feed_url'] ?? null;

        if ($sourceName) {
            $rating = $this->getRating($sourceName, $feedUrl);
            if ($rating) {
                $article['bias_rating'] = $rating;
            }
        }

        $textToAnalyze = $this->getArticleText($article);

        $polarizingTopics = self::detectPolarizingTopics($textToAnalyze);
        $polarizationScore = self::calculatePolarizationScore($textToAnalyze);

        if (! empty($polarizingTopics)) {
            $article['polarizing_topics'] = [
                'detected' => $polarizingTopics,
                'score' => $polarizationScore,
                'is_polarizing' => $polarizationScore >= 40,
            ];
        }

        $emotionalAnalysis = self::analyzeEmotionalLanguage($textToAnalyze);
        $article['emotional_language'] = [
            'score' => $emotionalAnalysis['score'],
            'density' => $emotionalAnalysis['density'],
            'sentiment_breakdown' => $emotionalAnalysis['sentiment_breakdown'],
            'is_sensational' => $emotionalAnalysis['score'] >= 30,
        ];

        $article['article_grade'] = $this->calculateArticleGrade($article);

        return $article;
    }

    public function enrichArticles(array $articles): array
    {
        return array_map(fn ($article) => $this->enrichArticle($article), $articles);
    }

    // =========================================================================
    // Bias Rating Lookup (from BiasRating model)
    // =========================================================================

    private function findBySource(string $sourceName): ?object
    {
        $result = $this->findByAlias($sourceName);
        if ($result) {
            return $this->withMatchMethod($result, 'alias');
        }

        $normalized = self::normalizeSourceName($sourceName);

        $result = DB::selectOne('SELECT * FROM bias_ratings WHERE LOWER(news_source) = ? LIMIT 1', [strtolower($normalized)]);
        if ($result) {
            return $this->withMatchMethod($result, 'exact_name');
        }

        $result = DB::selectOne('SELECT * FROM bias_ratings WHERE news_source LIKE ? LIMIT 1', ["%{$normalized}%"]);
        if ($result) {
            return $this->withMatchMethod($result, 'fuzzy_name');
        }

        $domain = self::normalizeDomainHost($sourceName);
        if ($domain) {
            $result = DB::selectOne('SELECT * FROM bias_ratings WHERE screen_name LIKE ? LIMIT 1', ["%{$domain}%"]);
            if ($result) {
                return $this->withMatchMethod($result, 'domain_screen_name');
            }

            $result = DB::selectOne('SELECT * FROM bias_ratings WHERE url LIKE ? LIMIT 1', ["%{$domain}%"]);
            if ($result) {
                return $this->withMatchMethod($result, 'domain_url');
            }
        }

        $result = DB::selectOne('SELECT * FROM bias_ratings WHERE screen_name LIKE ? LIMIT 1', ["%{$normalized}%"]);
        if ($result) {
            return $this->withMatchMethod($result, 'screen_name');
        }

        if ($normalized !== $sourceName) {
            $result = DB::selectOne('SELECT * FROM bias_ratings WHERE news_source LIKE ? OR screen_name LIKE ? LIMIT 1', ["%{$sourceName}%", "%{$sourceName}%"]);
            if ($result) {
                return $this->withMatchMethod($result, 'input_fallback');
            }
        }

        return null;
    }

    private function findByAlias(string $sourceName): ?object
    {
        if (! Schema::hasTable('bias_rating_aliases')) {
            return null;
        }

        foreach (self::sourceAliasCandidates($sourceName) as $candidate) {
            $result = DB::selectOne(
                'SELECT br.*
                   FROM bias_rating_aliases bra
                   JOIN bias_ratings br ON br.news_source = bra.canonical_source
                  WHERE bra.active = 1
                    AND bra.alias = ?
                  LIMIT 1',
                [$candidate]
            );

            if ($result) {
                return $result;
            }
        }

        return null;
    }

    private function withMatchMethod(object $rating, string $method): object
    {
        $rating->match_method = $method;

        return $rating;
    }

    public static function ratingColor(?string $rating): string
    {
        return match ($rating) {
            'left' => '#0000FF',
            'left-center' => '#7777FF',
            'center' => '#888888',
            'right-center' => '#FF7777',
            'right' => '#FF0000',
            'allsides' => '#00AA00',
            default => '#888888',
        };
    }

    public static function ratingEmoji(?string $rating): string
    {
        return match ($rating) {
            'left' => "\u{2B05}\u{FE0F}",
            'left-center' => "\u{2199}\u{FE0F}",
            'center' => "\u{2B06}\u{FE0F}",
            'right-center' => "\u{2197}\u{FE0F}",
            'right' => "\u{27A1}\u{FE0F}",
            'allsides' => "\u{2696}\u{FE0F}",
            default => "\u{2753}",
        };
    }

    // =========================================================================
    // Polarizing Topics (from PolarizingTopic model)
    // =========================================================================

    public static function detectPolarizingTopics(string $text): array
    {
        $text = strtolower($text);
        $topics = DB::select('SELECT keyword, category, weight FROM polarizing_topics WHERE active = 1');

        $matches = [];
        foreach ($topics as $topic) {
            if (stripos($text, $topic->keyword) !== false) {
                $matches[] = ['keyword' => $topic->keyword, 'category' => $topic->category, 'weight' => $topic->weight];
            }
        }

        return $matches;
    }

    public static function calculatePolarizationScore(string $text): int
    {
        $matches = self::detectPolarizingTopics($text);
        if (empty($matches)) {
            return 0;
        }

        return min(100, array_sum(array_column($matches, 'weight')) * 20);
    }

    // =========================================================================
    // Emotional Language (from EmotionalLanguageWord model)
    // =========================================================================

    public static function analyzeEmotionalLanguage(string $text): array
    {
        $text = strtolower($text);
        $words = str_word_count($text, 1);
        $totalWords = count($words);

        if ($totalWords === 0) {
            return ['score' => 0, 'density' => 0, 'total_emotional_words' => 0, 'matches' => [], 'sentiment_breakdown' => ['positive' => 0, 'negative' => 0, 'sensational' => 0]];
        }

        $emotionalWords = DB::select('SELECT word, sentiment, intensity FROM emotional_language_words WHERE active = 1');
        $matches = [];
        $sentimentCounts = ['positive' => 0, 'negative' => 0, 'sensational' => 0];

        foreach ($emotionalWords as $ew) {
            $matchCount = preg_match_all('/\b'.preg_quote($ew->word, '/').'\b/i', $text);
            if ($matchCount > 0) {
                $matches[] = ['word' => $ew->word, 'sentiment' => $ew->sentiment, 'intensity' => $ew->intensity, 'count' => $matchCount];
                $sentimentCounts[$ew->sentiment] = ($sentimentCounts[$ew->sentiment] ?? 0) + $matchCount;
            }
        }

        $weightedScore = array_sum(array_map(fn ($m) => $m['intensity'] * $m['count'], $matches));
        $density = round(($weightedScore / $totalWords) * 100, 2);

        return [
            'score' => min(100, round($density * 10)),
            'density' => $density,
            'total_emotional_words' => count($matches),
            'matches' => $matches,
            'sentiment_breakdown' => $sentimentCounts,
        ];
    }

    // =========================================================================
    // Helpers (from BiasRating model)
    // =========================================================================

    private static function extractDomain(string $url): ?string
    {
        $parsed = parse_url($url);
        if (! isset($parsed['host'])) {
            return null;
        }

        return self::stripHostPrefix($parsed['host']);
    }

    private static function normalizeSourceName(string $sourceName): string
    {
        $mappings = [
            'cnn.com' => 'CNN', 'cnn' => 'CNN', 'bbc.com' => 'BBC', 'bbc.co.uk' => 'BBC', 'bbc news' => 'BBC',
            'nytimes.com' => 'The New York Times', 'nyt' => 'The New York Times',
            'washingtonpost.com' => 'Washington Post', 'foxnews.com' => 'Fox News', 'fox news' => 'Fox News',
            'nbcnews.com' => 'NBC News', 'cbsnews.com' => 'CBS News', 'abcnews.go.com' => 'ABC News',
            'reuters.com' => 'Reuters', 'apnews.com' => 'Associated Press', 'ap.org' => 'Associated Press',
            'theguardian.com' => 'The Guardian', 'usatoday.com' => 'USA Today', 'wsj.com' => 'Wall Street Journal',
            'latimes.com' => 'Los Angeles Times', 'nypost.com' => 'New York Post', 'politico.com' => 'Politico',
            'thehill.com' => 'The Hill', 'npr.org' => 'NPR', 'pbs.org' => 'PBS', 'msnbc.com' => 'MSNBC',
            'breitbart.com' => 'Breitbart News', 'huffpost.com' => 'HuffPost', 'huffingtonpost.com' => 'HuffPost',
            'slate.com' => 'Slate', 'vox.com' => 'Vox', 'theatlantic.com' => 'The Atlantic',
            'time.com' => 'Time', 'newsweek.com' => 'Newsweek', 'bloomberg.com' => 'Bloomberg',
            'forbes.com' => 'Forbes', 'businessinsider.com' => 'Business Insider', 'economist.com' => 'The Economist',
        ];

        $lower = strtolower(trim($sourceName));
        $domain = self::normalizeDomainHost($lower);
        if ($domain !== null) {
            if (isset($mappings[$domain])) {
                return $mappings[$domain];
            }

            $lower = $domain;
        }

        if (isset($mappings[$lower])) {
            return $mappings[$lower];
        }

        $cleaned = preg_replace('/\.(com|org|net|co\.uk|gov|edu)$/i', '', $lower);
        if (isset($mappings[$cleaned])) {
            return $mappings[$cleaned];
        }

        $withoutNews = preg_replace('/\s+news$/i', '', $cleaned);
        if (isset($mappings[$withoutNews])) {
            return $mappings[$withoutNews];
        }

        return ucwords($cleaned);
    }

    private static function sourceAliasCandidates(string $sourceName): array
    {
        $sourceName = strtolower(trim($sourceName));
        if ($sourceName === '') {
            return [];
        }

        $candidates = [$sourceName];
        $domain = self::normalizeDomainHost($sourceName);

        if ($domain !== null) {
            $candidates[] = $domain;
        }

        return array_values(array_unique($candidates));
    }

    private static function normalizeDomainHost(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            $host = preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $value) === 1 ? $value : null;
        }

        if (! is_string($host) || $host === '') {
            return null;
        }

        return self::stripHostPrefix($host);
    }

    private static function stripHostPrefix(string $host): string
    {
        $host = strtolower(trim($host));

        foreach (['www.', 'feeds.', 'rss.', 'api.', 'news.'] as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return substr($host, strlen($prefix));
            }
        }

        return $host;
    }

    private function getArticleText(array $article): string
    {
        $parts = [];
        if (! empty($article['title'])) {
            $parts[] = $article['title'];
        }
        if (! empty($article['description'])) {
            $parts[] = $article['description'];
        }
        if (! empty($article['content'])) {
            $parts[] = substr($article['content'], 0, 500);
        }

        return implode(' ', $parts);
    }

    public function getBiasDistribution(array $articles): array
    {
        $dist = ['left' => 0, 'left-center' => 0, 'center' => 0, 'right-center' => 0, 'right' => 0, 'unknown' => 0];
        foreach ($articles as $a) {
            $r = $a['bias_rating']['rating'] ?? 'unknown';
            $dist[$r] = ($dist[$r] ?? $dist['unknown']) + 1;
        }

        return $dist;
    }

    public function filterByRating(array $articles, array $allowedRatings): array
    {
        return array_filter($articles, fn ($a) => in_array($a['bias_rating']['rating'] ?? null, $allowedRatings));
    }

    public function getBalancedSelection(array $articles, int $limit = 25): array
    {
        $grouped = [];
        foreach ($articles as $a) {
            $grouped[$a['bias_rating']['rating'] ?? 'unknown'][] = $a;
        }
        $perGroup = (int) floor($limit / max(1, count($grouped)));
        $selected = [];
        foreach ($grouped as $group) {
            $selected = array_merge($selected, array_slice($group, 0, $perGroup));
        }

        return array_slice($selected, 0, $limit);
    }

    public function getBiasSummary(array $articles): string
    {
        $dist = $this->getBiasDistribution($articles);
        $total = array_sum($dist);
        if ($total === 0) {
            return 'No bias data available';
        }

        $parts = [];
        foreach ($dist as $rating => $count) {
            if ($count > 0 && $rating !== 'unknown') {
                $pct = round(($count / $total) * 100);
                $parts[] = self::ratingEmoji($rating)." {$rating}: {$count} ({$pct}%)";
            }
        }

        return implode(', ', $parts);
    }

    private function calculateArticleGrade(array $article): array
    {
        $score = 0;

        if (isset($article['bias_rating']['rating_num'])) {
            $score += min(30, abs($article['bias_rating']['rating_num']) * 15);
        }
        if (! empty($article['bias_rating']['is_polarizing_source'])) {
            $score += 10;
        }
        if (isset($article['polarizing_topics']['score'])) {
            $score += min(25, $article['polarizing_topics']['score'] * 0.25);
        }
        if (isset($article['emotional_language']['score'])) {
            $score += min(25, $article['emotional_language']['score'] * 0.25);
        }
        if (isset($article['bias_rating']['mbfc_credibility_score'])) {
            $score += (100 - $article['bias_rating']['mbfc_credibility_score']) * 0.1;
        }

        $score = min(100, round($score));
        $level = match (true) {
            $score <= 25 => 'balanced', $score <= 50 => 'moderate', $score <= 75 => 'high', default => 'extreme'
        };

        return [
            'score' => $score,
            'level' => $level,
            'emoji' => match ($level) {
                'balanced' => "\u{2705}", 'moderate' => "\u{26A0}\u{FE0F}", 'high' => "\u{1F536}", 'extreme' => "\u{1F6A8}"
            },
            'color' => match ($level) {
                'balanced' => '#28A745', 'moderate' => '#FFC107', 'high' => '#FD7E14', 'extreme' => '#DC3545'
            },
            'description' => match ($level) {
                'balanced' => 'Balanced, factual reporting', 'moderate' => 'Some bias or sensationalism', 'high' => 'Notable bias or polarization', 'extreme' => 'Highly biased or sensational'
            },
        ];
    }
}
