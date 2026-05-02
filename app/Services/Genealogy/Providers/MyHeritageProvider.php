<?php

namespace App\Services\Genealogy\Providers;

use App\Services\VisionScreenshotService;
use Illuminate\Support\Facades\Log;

/**
 * MyHeritage Provider — private/personal-gated screenshot search
 *
 * MyHeritage has public search pages at myheritage.com/research
 * that don't require authentication. We use Puppeteer screenshot +
 * vision AI extraction to get structured results.
 *
 * Disabled by default in GenealogyProviderManager unless
 * MYHERITAGE_PERSONAL_AUTOMATION_ENABLED is explicitly set.
 */
class MyHeritageProvider extends AbstractGenealogyProvider
{
    protected const PROVIDER_ID = 'myheritage';
    protected const PROVIDER_NAME = 'MyHeritage';
    protected const SEARCH_URL = 'https://www.myheritage.com/research/collection-1/myheritage-family-trees';

    protected array $defaultCapabilities = [
        'search_persons' => true,
        'search_records' => true,
        'get_record' => false,
        'get_person' => false,
        'get_family' => false,
        'get_collections' => false,
        'hints' => false,
        'attach_records' => false,
        'dna_matches' => false,
    ];

    public function getProviderId(): string
    {
        return self::PROVIDER_ID;
    }

    public function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getAuthType(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return $this->automationEnabled();
    }

    public function isAuthenticated(): bool
    {
        return $this->automationEnabled();
    }

    /**
     * Search MyHeritage public family trees via vision scraping
     */
    public function searchPersons(array $criteria, array $options = []): array
    {
        if (! $this->automationEnabled()) {
            return [
                'success' => false,
                'manual_only' => true,
                'manual_required' => true,
                'policy' => 'private_personal_adapter_disabled',
                'error' => 'MyHeritage screenshot automation is private/personal-gated and disabled for this install',
                'provider' => self::PROVIDER_ID,
                'results' => [],
            ];
        }

        $params = [];

        if (!empty($criteria['given_name'])) {
            $params['first'] = $criteria['given_name'];
        }
        if (!empty($criteria['surname'])) {
            $params['last'] = $criteria['surname'];
        }
        if (!empty($criteria['birth_year'])) {
            $params['birth_year'] = $criteria['birth_year'];
        }
        if (!empty($criteria['death_year'])) {
            $params['death_year'] = $criteria['death_year'];
        }
        if (!empty($criteria['birth_place'])) {
            $params['birth_place'] = $criteria['birth_place'];
        }

        if (empty($params)) {
            return ['success' => false, 'error' => 'At least one search criterion required', 'results' => []];
        }

        $url = self::SEARCH_URL . '?' . http_build_query($params);

        try {
            $this->respectRateLimit();

            $visionService = app(VisionScreenshotService::class);
            $extractionPrompt = 'Extract all person search results from this genealogy page. '
                . 'For each person, return a JSON array with objects containing: '
                . 'name (full name), birth_date, birth_place, death_date, death_place, '
                . 'spouse (if shown), parents (if shown), record_url (if visible). '
                . 'Return ONLY valid JSON array. If no results found, return [].';

            $result = $visionService->captureAndExtract($url, $extractionPrompt, [
                'wait_for' => '.search-results, .results-list, .record-list, table',
                'wait_timeout' => 8000,
                'full_page' => false,
            ]);

            $this->requestCount++;

            if (!($result['success'] ?? false)) {
                $this->lastError = $result['error'] ?? 'Screenshot extraction failed';
                return ['success' => false, 'error' => $this->lastError, 'results' => []];
            }

            $results = $this->parseVisionResults($result['extracted_text'] ?? '');

            Log::info('MyHeritageProvider: Search completed', [
                'criteria' => $params,
                'results' => count($results),
            ]);

            return [
                'success' => true,
                'results' => $results,
                'total' => count($results),
                'provider' => self::PROVIDER_ID,
            ];
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error('MyHeritageProvider: Search failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage(), 'results' => []];
        }
    }

    public function searchRecords(array $criteria, array $options = []): array
    {
        return $this->searchPersons($criteria, $options);
    }

    private function automationEnabled(): bool
    {
        return (bool) config('services.myheritage.personal_automation_enabled', false);
    }

    public function getRecord(string $recordId): ?array
    {
        $this->lastError = 'Record detail not available via scraping';
        return null;
    }

    /**
     * Parse vision AI JSON extraction into standard result format
     */
    private function parseVisionResults(string $text): array
    {
        // Extract JSON array from response (may have surrounding text)
        if (preg_match('/\[.*\]/s', $text, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (is_array($parsed)) {
                return array_map(function ($item) {
                    return [
                        'name' => $item['name'] ?? '',
                        'birth_date' => $item['birth_date'] ?? null,
                        'birth_place' => $item['birth_place'] ?? null,
                        'death_date' => $item['death_date'] ?? null,
                        'death_place' => $item['death_place'] ?? null,
                        'spouse' => $item['spouse'] ?? null,
                        'parents' => $item['parents'] ?? null,
                        'url' => $item['record_url'] ?? null,
                        'source' => self::PROVIDER_ID,
                        'record_type' => 'family_tree',
                    ];
                }, $parsed);
            }
        }

        return [];
    }

    private function respectRateLimit(): void
    {
        if ($this->requestCount > 0) {
            usleep(2000000); // 2 sec between requests (polite scraping)
        }
    }
}
