<?php

namespace App\Services\DataRemoval;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class BrokerListSyncService
{
    // nicholasgasior/BADBOOL gone (404 since ~Feb 2026).
    // Replaced with digisamroc/eraser — 800+ brokers, active, YAML format.
    private const ERASER_URL = 'https://raw.githubusercontent.com/digisamroc/eraser/main/data/brokers.yaml';

    public function syncBADBOOL(): array
    {
        try {
            $httpResponse = Http::connectTimeout(5)->timeout(30)
                ->withUserAgent('PLOS/3.7 (Data Removal Sync)')
                ->get(self::ERASER_URL);

            $response = $httpResponse->body();
            $httpCode = $httpResponse->status();

            if ($httpCode !== 200 || !$response) {
                return ['success' => false, 'error' => "HTTP {$httpCode} from eraser source"];
            }

            $brokers = $this->parseEraserYaml($response);
            if (empty($brokers)) {
                return ['success' => false, 'error' => 'No brokers found in YAML response'];
            }

            // Map eraser categories (hyphen-separated) to DB enum (underscore-separated)
            $categoryMap = [
                'people-search'    => 'people_search',
                'background-check' => 'background_check',
                'data-aggregator'  => 'data_aggregator',
                'marketing'        => 'marketing',
            ];

            // Normalize eraser fields to match importBrokers() expectations
            $normalized = array_map(function (array $b) use ($categoryMap): array {
                $website = $b['website'] ?? null;
                $domain = $website ? preg_replace('#^https?://(www\.)?#', '', rtrim($website, '/')) : null;
                $cat = $b['category'] ?? null;
                return [
                    'id'          => isset($b['id']) ? substr($b['id'], 0, 50) : null,
                    'name'        => $b['name'] ?? $domain,
                    'domain'      => $domain,
                    'optout_url'  => $b['opt_out_url'] ?? null,
                    'category'    => $categoryMap[$cat] ?? 'other',
                ];
            }, $brokers);

            return $this->importBrokers($normalized);
        } catch (Exception $e) {
            Log::error('BrokerListSync: eraser sync failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Parse the eraser brokers.yaml without a YAML library dependency.
     * The format is a simple flat list — no nested objects, no special escaping.
     */
    private function parseEraserYaml(string $yaml): array
    {
        $brokers = [];
        $current = [];
        foreach (explode("\n", $yaml) as $line) {
            // New broker entry
            if (preg_match('/^\s{4}-\s+id:\s*(.+)$/', $line, $m)) {
                if (!empty($current)) {
                    $brokers[] = $current;
                }
                $current = ['id' => trim($m[1])];
                continue;
            }
            // Key: value pairs inside a broker block
            if (preg_match('/^\s{6}(\w+):\s*(.*)$/', $line, $m)) {
                $current[trim($m[1])] = trim($m[2]);
            }
        }
        if (!empty($current)) {
            $brokers[] = $current;
        }
        return $brokers;
    }

    public function importBrokers(array $data): array
    {
        $results = ['total' => count($data), 'new' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($data as $broker) {
            $domain = $broker['domain'] ?? $broker['url'] ?? null;
            if (!$domain) {
                $results['skipped']++;
                continue;
            }

            // Normalize domain
            $domain = preg_replace('#^https?://#', '', $domain);
            $domain = rtrim($domain, '/');

            $existing = DB::selectOne(
                "SELECT id FROM data_brokers WHERE domain = ?",
                [$domain]
            );

            $badboolId = $broker['id'] ?? null;
            $name = $broker['name'] ?? $domain;
            $optoutUrl = $broker['optout_url'] ?? $broker['removal_url'] ?? null;
            $category = $broker['category'] ?? null;

            if ($existing) {
                DB::update(
                    "UPDATE data_brokers SET badbool_id = ?, removal_url = COALESCE(?, removal_url), updated_at = NOW()
                     WHERE id = ?",
                    [$badboolId, $optoutUrl, $existing->id]
                );
                $results['updated']++;
            } else {
                DB::insert(
                    "INSERT INTO data_brokers (name, domain, category, removal_url, badbool_id, health_status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 'unknown', NOW(), NOW())",
                    [$name, $domain, $category, $optoutUrl, $badboolId]
                );
                $results['new']++;
            }
        }

        Log::info('BrokerListSync: Import completed', $results);
        return array_merge(['success' => true], $results);
    }

    public function getSyncStatus(): array
    {
        $total = DB::selectOne("SELECT COUNT(*) as count FROM data_brokers");
        $withBadbool = DB::selectOne("SELECT COUNT(*) as count FROM data_brokers WHERE badbool_id IS NOT NULL");
        $lastUpdated = DB::selectOne("SELECT MAX(updated_at) as last_update FROM data_brokers WHERE badbool_id IS NOT NULL");
        // Note: badbool_id column now stores eraser source IDs (reused after BADBOOL 404)

        return [
            'total_brokers' => $total->count ?? 0,
            'badbool_brokers' => $withBadbool->count ?? 0,
            'last_sync' => $lastUpdated->last_update ?? null,
        ];
    }
}
