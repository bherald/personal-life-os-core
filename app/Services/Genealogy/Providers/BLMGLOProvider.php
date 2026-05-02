<?php

namespace App\Services\Genealogy\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GEN-3: Bureau of Land Management General Land Office Records Provider
 *
 * Searches the BLM GLO land patent database (1788-present).
 * Federal land patents, homestead entries, and land grants.
 * ~5 million records.
 *
 * API: glorecords.blm.gov search endpoint (HTML scraping).
 * No authentication required.
 */
class BLMGLOProvider extends AbstractGenealogyProvider
{
    protected const PROVIDER_ID = 'blm_glo';
    protected const PROVIDER_NAME = 'BLM GLO Land Records';
    protected const BASE_URL = 'https://glorecords.blm.gov';
    protected const SEARCH_URL = 'https://glorecords.blm.gov/results/default.aspx';

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
     * Search BLM GLO land patent records
     *
     * Criteria: given_name, surname, state, county, land_office, patent_type
     */
    public function searchRecords(array $criteria, array $options = []): array
    {
        $params = [];

        if (!empty($criteria['given_name'])) {
            $params['fname'] = $criteria['given_name'];
        }
        if (!empty($criteria['surname'])) {
            $params['lname'] = $criteria['surname'];
        }
        if (!empty($criteria['state'])) {
            $params['state'] = $criteria['state'];
        }
        if (!empty($criteria['county'])) {
            $params['county'] = $criteria['county'];
        }
        if (!empty($criteria['land_office'])) {
            $params['land_office'] = $criteria['land_office'];
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

            Log::info('BLMGLOProvider: Search completed', [
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
            Log::error('BLMGLOProvider: Search failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage(), 'results' => []];
        }
    }

    public function getRecord(string $recordId): ?array
    {
        try {
            $url = self::BASE_URL . '/details/patent/default.aspx?accession=' . urlencode($recordId);
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

        // Parse result table rows
        if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $html, $rows)) {
            foreach ($rows[1] as $row) {
                if (preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $row, $cells) && count($cells[1]) >= 4) {
                    $patentee = trim(strip_tags($cells[1][0] ?? ''));

                    // Skip header rows
                    if (empty($patentee) || str_contains(strtolower($patentee), 'patentee')) {
                        continue;
                    }

                    $accession = '';
                    if (preg_match('/accession=([^&"\']+)/i', $row, $m)) {
                        $accession = $m[1];
                    }

                    $results[] = [
                        'name' => $patentee,
                        'state' => trim(strip_tags($cells[1][1] ?? '')),
                        'issue_date' => trim(strip_tags($cells[1][2] ?? '')),
                        'authority' => trim(strip_tags($cells[1][3] ?? '')),
                        'accession' => $accession,
                        'url' => $accession ? self::BASE_URL . '/details/patent/default.aspx?accession=' . $accession : null,
                        'source' => self::PROVIDER_ID,
                        'record_type' => 'land_patent',
                    ];
                }
            }
        }

        return $results;
    }

    private function parseRecordDetail(string $html, string $recordId): ?array
    {
        $data = [
            'id' => $recordId,
            'source' => self::PROVIDER_ID,
            'record_type' => 'land_patent',
            'url' => self::BASE_URL . '/details/patent/default.aspx?accession=' . $recordId,
        ];

        // Extract key fields
        $fields = [
            'Patentee' => 'name',
            'Issue Date' => 'issue_date',
            'State' => 'state',
            'County' => 'county',
            'Meridian' => 'meridian',
            'Township' => 'township',
            'Range' => 'range',
            'Section' => 'section',
            'Aliquots' => 'aliquots',
            'Acres' => 'acres',
            'Authority' => 'authority',
            'Document Nr' => 'document_number',
        ];

        foreach ($fields as $label => $key) {
            if (preg_match("/{$label}[^<]*<[^>]*>([^<]+)/i", $html, $m)) {
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
        if ($this->requestCount > 0) {
            usleep(1000000); // 1 req/sec
        }
    }
}
