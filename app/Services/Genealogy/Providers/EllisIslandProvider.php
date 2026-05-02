<?php

namespace App\Services\Genealogy\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GEN-2: Ellis Island Immigration Records Provider (1892-1957)
 *
 * Searches the Statue of Liberty - Ellis Island Foundation passenger
 * manifest database. ~65 million records of immigrants arriving at
 * the Port of New York.
 *
 * API: libertyellisfoundation.org search endpoint (HTML scraping).
 * No authentication required.
 */
class EllisIslandProvider extends AbstractGenealogyProvider
{
    protected const PROVIDER_ID = 'ellis_island';
    protected const PROVIDER_NAME = 'Ellis Island';
    protected const BASE_URL = 'https://heritage.statueofliberty.org';
    protected const SEARCH_URL = 'https://heritage.statueofliberty.org/passenger';

    protected array $defaultCapabilities = [
        'search_persons' => true,
        'search_records' => true,
        'get_record' => true,
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
        return true;
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function searchPersons(array $criteria, array $options = []): array
    {
        return $this->searchRecords($criteria, $options);
    }

    /**
     * Search Ellis Island passenger manifests
     *
     * Criteria: given_name, surname, birth_year, arrival_year, ethnicity, ship_name
     */
    public function searchRecords(array $criteria, array $options = []): array
    {
        $params = [];

        if (!empty($criteria['given_name'])) {
            $params['givenName'] = $criteria['given_name'];
        }
        if (!empty($criteria['surname'])) {
            $params['surname'] = $criteria['surname'];
        }
        if (!empty($criteria['birth_year'])) {
            $params['birthYear'] = $criteria['birth_year'];
        }
        if (!empty($criteria['arrival_year'])) {
            $params['arrivalYear'] = $criteria['arrival_year'];
        }
        if (!empty($criteria['ethnicity'])) {
            $params['ethnicity'] = $criteria['ethnicity'];
        }
        if (!empty($criteria['ship_name'])) {
            $params['shipName'] = $criteria['ship_name'];
        }

        if (empty($params)) {
            return ['success' => false, 'error' => 'At least one search criterion required', 'results' => []];
        }

        try {
            $html = $this->fetchHtml(self::SEARCH_URL, $params);

            if (!$html) {
                return ['success' => false, 'error' => $this->lastError ?? 'Failed to fetch results', 'results' => []];
            }

            $results = $this->parseSearchResults($html);

            Log::info('EllisIslandProvider: Search completed', [
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
            Log::error('EllisIslandProvider: Search failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage(), 'results' => []];
        }
    }

    public function getRecord(string $recordId): ?array
    {
        try {
            $url = self::BASE_URL . '/passenger/' . urlencode($recordId);
            $html = $this->fetchHtml($url);

            if (!$html) {
                return null;
            }

            return $this->parseRecordDetail($html, $recordId);
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    protected function parseSearchResults(string $html): array
    {
        $results = [];

        // Parse passenger result rows from HTML
        if (preg_match_all('/<div[^>]*class="[^"]*result[^"]*"[^>]*>(.*?)<\/div>/si', $html, $matches)) {
            foreach ($matches[1] as $block) {
                $record = $this->extractRecordFromBlock($block);
                if ($record) {
                    $results[] = $record;
                }
            }
        }

        // Fallback: try table rows
        if (empty($results) && preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $html, $rows)) {
            foreach ($rows[1] as $row) {
                if (preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $row, $cells) && count($cells[1]) >= 3) {
                    $name = strip_tags($cells[1][0] ?? '');
                    if (!empty(trim($name)) && !str_contains(strtolower($name), 'name')) {
                        $results[] = [
                            'name' => trim($name),
                            'arrival_date' => trim(strip_tags($cells[1][1] ?? '')),
                            'ship' => trim(strip_tags($cells[1][2] ?? '')),
                            'source' => self::PROVIDER_ID,
                            'record_type' => 'passenger_manifest',
                        ];
                    }
                }
            }
        }

        return $results;
    }

    private function extractRecordFromBlock(string $block): ?array
    {
        $name = '';
        if (preg_match('/<a[^>]*>(.*?)<\/a>/i', $block, $m)) {
            $name = trim(strip_tags($m[1]));
        }
        if (empty($name)) {
            return null;
        }

        $href = '';
        if (preg_match('/href="([^"]*passenger[^"]*)"/i', $block, $m)) {
            $href = $m[1];
        }

        return [
            'name' => $name,
            'url' => $href ? (str_starts_with($href, 'http') ? $href : self::BASE_URL . $href) : null,
            'source' => self::PROVIDER_ID,
            'record_type' => 'passenger_manifest',
            'raw_html' => strip_tags($block),
        ];
    }

    private function parseRecordDetail(string $html, string $recordId): ?array
    {
        $data = [
            'id' => $recordId,
            'source' => self::PROVIDER_ID,
            'record_type' => 'passenger_manifest',
            'url' => self::BASE_URL . '/passenger/' . $recordId,
        ];

        // Extract key fields from detail page
        $fields = ['Name', 'Arrival Date', 'Birth Date', 'Ship', 'Port of Departure', 'Ethnicity', 'Age'];
        foreach ($fields as $field) {
            if (preg_match("/{$field}[^<]*<[^>]*>([^<]+)/i", $html, $m)) {
                $key = strtolower(str_replace(' ', '_', $field));
                $data[$key] = trim($m[1]);
            }
        }

        return !empty($data['name'] ?? '') ? $data : null;
    }

    protected function fetchHtml(string $url, array $params = []): ?string
    {
        try {
            $this->respectRateLimit();

            $response = Http::connectTimeout(5)->timeout(15)
                ->withHeaders([
                    'User-Agent' => 'PLOS/1.0 (genealogy research)',
                    'Accept' => 'text/html',
                ])
                ->get($url, $params);

            $this->requestCount++;

            if (!$response->successful()) {
                $this->lastError = "HTTP {$response->status()}";
                return null;
            }

            return $response->body();
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    private function respectRateLimit(): void
    {
        // 1 request per second to be polite
        if ($this->requestCount > 0) {
            usleep(1000000);
        }
    }
}
