<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

/**
 * Research Enhancements Service
 *
 * Provides exponential backoff/jitter, Archive.org integration,
 * LLM query expansion, and research confidence scoring.
 */
class ResearchEnhancementsService
{
    private ?AIService $aiService = null;

    private function getAIService(): AIService
    {
        if ($this->aiService === null) {
            $this->aiService = app(AIService::class);
        }
        return $this->aiService;
    }

    // =========================================================================
    // EXPONENTIAL BACKOFF + JITTER
    // =========================================================================

    public function calculateBackoff(int $attempt, float $baseDelay = 1.0, float $maxDelay = 60.0): float
    {
        $delay = min($maxDelay, $baseDelay * pow(2, $attempt));
        // Add jitter: 0-50% of calculated delay
        $jitter = $delay * (mt_rand(0, 500) / 1000);
        return $delay + $jitter;
    }

    public function executeWithBackoff(callable $fn, int $maxAttempts = 3, float $baseDelay = 1.0): array
    {
        $lastError = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $result = $fn();
                return ['success' => true, 'result' => $result, 'attempts' => $attempt + 1];
            } catch (Exception $e) {
                $lastError = $e;
                if ($attempt < $maxAttempts - 1) {
                    $delay = $this->calculateBackoff($attempt, $baseDelay);
                    Log::info('ResearchEnhancements: Retry with backoff', [
                        'attempt' => $attempt + 1,
                        'delay' => round($delay, 2),
                        'error' => $e->getMessage(),
                    ]);
                    usleep((int) ($delay * 1000000));
                }
            }
        }

        return [
            'success' => false,
            'error' => $lastError ? $lastError->getMessage() : 'Max attempts exceeded',
            'attempts' => $maxAttempts,
        ];
    }

    public function getCircuitBreakerStatus(string $engineName): array
    {
        $cacheKey = "research_circuit_{$engineName}";
        $state = Cache::get($cacheKey, [
            'state' => 'closed',
            'failures' => 0,
            'last_failure' => null,
            'next_retry' => null,
        ]);

        return [
            'engine' => $engineName,
            'state' => $state['state'],
            'failures' => $state['failures'],
            'last_failure' => $state['last_failure'],
            'next_retry' => $state['next_retry'],
        ];
    }

    public function recordCircuitFailure(string $engineName, string $error): void
    {
        $cacheKey = "research_circuit_{$engineName}";
        $state = Cache::get($cacheKey, ['state' => 'closed', 'failures' => 0, 'last_failure' => null, 'next_retry' => null]);

        $state['failures']++;
        $state['last_failure'] = now()->toIso8601String();

        if ($state['failures'] >= 5) {
            $state['state'] = 'open';
            $backoff = $this->calculateBackoff($state['failures'] - 5, 60, 3600);
            $state['next_retry'] = now()->addSeconds((int) $backoff)->toIso8601String();
        }

        Cache::put($cacheKey, $state, 3600);
    }

    public function resetCircuitBreaker(string $engineName): void
    {
        Cache::forget("research_circuit_{$engineName}");
    }

    // =========================================================================
    // ARCHIVE.ORG INTEGRATION
    // =========================================================================

    public function archiveUrl(string $url): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(30)
                ->withUserAgent('PLOS/3.7 (Research Archival)')
                ->withOptions(['allow_redirects' => true])
                ->withHeaders(['Accept' => '*/*'])
                ->get("https://web.archive.org/save/{$url}");

            $responseText = $response->body();
            $httpCode = $response->status();

            if ($httpCode >= 200 && $httpCode < 400) {
                // Extract archived URL from headers
                $contentLocation = $response->header('Content-Location');
                if ($contentLocation) {
                    $archiveUrl = 'https://web.archive.org' . $contentLocation;
                } elseif (preg_match('/Content-Location:\s*(\S+)/i', $responseText, $matches)) {
                    $archiveUrl = 'https://web.archive.org' . $matches[1];
                } else {
                    $archiveUrl = "https://web.archive.org/web/" . date('YmdHis') . "/{$url}";
                }

                Log::info('ResearchEnhancements: URL archived', ['url' => $url, 'archive' => $archiveUrl]);
                return ['success' => true, 'archive_url' => $archiveUrl];
            }

            return ['success' => false, 'error' => "HTTP {$httpCode}", 'url' => $url];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'url' => $url];
        }
    }

    public function getArchivedVersion(string $url): array
    {
        try {
            $apiUrl = "https://archive.org/wayback/available?url=" . urlencode($url);
            $response = Http::connectTimeout(5)->timeout(15)
                ->withUserAgent('PLOS/3.7')
                ->get($apiUrl);

            if (!$response->successful()) {
                return ['success' => false, 'error' => 'HTTP ' . $response->status()];
            }

            $data = $response->json();
            $snapshot = $data['archived_snapshots']['closest'] ?? null;

            if ($snapshot && $snapshot['available']) {
                return [
                    'success' => true,
                    'available' => true,
                    'url' => $snapshot['url'],
                    'timestamp' => $snapshot['timestamp'],
                    'status' => $snapshot['status'],
                ];
            }

            return ['success' => true, 'available' => false, 'url' => $url];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function archiveResearchSources(int $topicId): array
    {
        $sources = DB::connection('pgsql_rag')->select(
            "SELECT id, url FROM research_source_results WHERE research_topic_id = ? AND url IS NOT NULL AND url != ''",
            [$topicId]
        );

        $results = ['total' => count($sources), 'archived' => 0, 'already_archived' => 0, 'failed' => 0];

        foreach ($sources as $source) {
            $existing = $this->getArchivedVersion($source->url);
            if ($existing['success'] && ($existing['available'] ?? false)) {
                $results['already_archived']++;
                continue;
            }

            $archiveResult = $this->archiveUrl($source->url);
            if ($archiveResult['success']) {
                $results['archived']++;
            } else {
                $results['failed']++;
            }

            // Rate limit: 1 request per 5 seconds to be polite to Archive.org
            usleep(5000000);
        }

        return $results;
    }

    public function getArchiveStats(): array
    {
        $total = DB::connection('pgsql_rag')->selectOne(
            "SELECT COUNT(*) as count FROM research_source_results WHERE url IS NOT NULL AND url != ''"
        );

        return [
            'total_source_urls' => $total->count ?? 0,
        ];
    }

    // =========================================================================
    // QUERY EXPANSION VIA LLM
    // =========================================================================

    public function expandQuery(string $originalQuery, int $numVariants = 3): array
    {
        $cacheKey = 'research_expand_' . md5($originalQuery . $numVariants);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        try {
            $prompt = "Generate exactly {$numVariants} alternative search queries for the following research query. "
                . "Each variant should approach the topic from a different angle or use different terminology. "
                . "Return ONLY the queries, one per line, no numbering or explanations.\n\n"
                . "Original query: {$originalQuery}";

            $result = $this->getAIService()->process($prompt, [
                'max_tokens' => 300,
                'system' => 'You generate alternative search queries. Return only the queries, one per line.',
            ]);

            $content = $result['response'] ?? $result['content'] ?? '';
            $variants = array_filter(array_map('trim', explode("\n", $content)));
            $variants = array_values(array_slice($variants, 0, $numVariants));

            $output = [
                'original' => $originalQuery,
                'variants' => $variants,
                'count' => count($variants),
            ];

            Cache::put($cacheKey, $output, 3600);
            return $output;
        } catch (Exception $e) {
            Log::error('ResearchEnhancements: Query expansion failed', ['error' => $e->getMessage()]);
            return ['original' => $originalQuery, 'variants' => [], 'count' => 0, 'error' => $e->getMessage()];
        }
    }

    public function mergeExpandedResults(array $originalResults, array $expandedResults): array
    {
        $seen = [];
        $merged = [];

        // Original results take priority
        foreach ($originalResults as $result) {
            $key = $result['url'] ?? $result['content_hash'] ?? md5(json_encode($result));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result['source'] = 'original';
                $merged[] = $result;
            }
        }

        // Add expanded results that aren't duplicates
        foreach ($expandedResults as $result) {
            $key = $result['url'] ?? $result['content_hash'] ?? md5(json_encode($result));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result['source'] = 'expanded';
                $merged[] = $result;
            }
        }

        return $merged;
    }

    // =========================================================================
    // RESEARCH CONFIDENCE SCORING
    // =========================================================================

    public function calculateConfidence(array $results): array
    {
        if (empty($results)) {
            return ['score' => 0, 'components' => [], 'level' => 'none'];
        }

        $sourceDiversity = $this->scoreSourceDiversity($results);
        $verificationDepth = $this->scoreVerificationDepth($results);
        $recency = $this->scoreRecency($results);
        $agreement = $this->scoreAgreement($results);

        // Weighted composite: source_diversity (0.3), verification_depth (0.25), recency (0.2), agreement (0.25)
        $composite = ($sourceDiversity * 0.30)
                   + ($verificationDepth * 0.25)
                   + ($recency * 0.20)
                   + ($agreement * 0.25);

        $level = 'low';
        if ($composite >= 0.8) $level = 'high';
        elseif ($composite >= 0.5) $level = 'medium';

        return [
            'score' => round($composite, 3),
            'level' => $level,
            'components' => [
                'source_diversity' => round($sourceDiversity, 3),
                'verification_depth' => round($verificationDepth, 3),
                'recency' => round($recency, 3),
                'agreement' => round($agreement, 3),
            ],
        ];
    }

    public function scoreSourceDiversity(array $results): float
    {
        $domains = [];
        foreach ($results as $result) {
            $url = $result['url'] ?? '';
            if ($url) {
                $host = parse_url($url, PHP_URL_HOST);
                if ($host) {
                    $domains[$host] = true;
                }
            }
        }

        $uniqueDomains = count($domains);
        $total = count($results);

        if ($total === 0) return 0;
        return min(1.0, $uniqueDomains / max($total, 1));
    }

    public function scoreVerificationDepth(array $results): float
    {
        if (empty($results)) return 0;

        $totalEvidence = 0;
        foreach ($results as $result) {
            $evidenceCount = $result['evidence_count'] ?? 0;
            $totalEvidence += min($evidenceCount, 5); // Cap at 5 per result
        }

        $avgEvidence = $totalEvidence / count($results);
        return min(1.0, $avgEvidence / 3); // 3+ avg evidence = max score
    }

    public function scoreRecency(array $results): float
    {
        if (empty($results)) return 0;

        $now = time();
        $scores = [];

        foreach ($results as $result) {
            $date = $result['published_at'] ?? $result['created_at'] ?? null;
            if ($date) {
                $timestamp = strtotime($date);
                if ($timestamp) {
                    $daysOld = ($now - $timestamp) / 86400;
                    // Score: 1.0 for today, decays over 365 days
                    $scores[] = max(0, 1.0 - ($daysOld / 365));
                }
            }
        }

        return empty($scores) ? 0.5 : array_sum($scores) / count($scores);
    }

    public function scoreAgreement(array $results): float
    {
        if (count($results) < 2) return 0.5;

        // Simple agreement: check if extracted facts overlap across results
        $allFacts = [];
        foreach ($results as $result) {
            $facts = $result['extracted_facts'] ?? [];
            if (is_string($facts)) {
                $facts = json_decode($facts, true) ?? [];
            }
            foreach ($facts as $fact) {
                $normalized = strtolower(trim(is_string($fact) ? $fact : ($fact['statement'] ?? '')));
                if ($normalized) {
                    $allFacts[$normalized] = ($allFacts[$normalized] ?? 0) + 1;
                }
            }
        }

        if (empty($allFacts)) return 0.5;

        // Proportion of facts confirmed by multiple sources
        $confirmed = count(array_filter($allFacts, fn($count) => $count >= 2));
        $total = count($allFacts);

        return min(1.0, $confirmed / max($total, 1));
    }
}
