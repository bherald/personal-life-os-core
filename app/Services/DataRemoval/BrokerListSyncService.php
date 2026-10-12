<?php

namespace App\Services\DataRemoval;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

class BrokerListSyncService
{
    public const SOURCE_URL = 'https://raw.githubusercontent.com/digisamroc/eraser/main/data/brokers.yaml';

    public const SOURCE_ID = 'digisamroc/eraser';

    public const SOURCE_LICENSE = 'MIT';

    public function previewSync(bool $brokenOnly = false): array
    {
        $source = $this->fetchSource();
        if (($source['success'] ?? false) !== true) {
            return $source;
        }

        $query = DB::table('data_brokers')->select([
            'id', 'name', 'domain', 'removal_url', 'health_status', 'is_active',
        ]);
        if ($brokenOnly) {
            $query->where('is_active', 1)->where('health_status', 'broken');
        }

        $existing = [];
        foreach ($query->get() as $broker) {
            $domain = $this->normalizeDomain((string) $broker->domain);
            if ($domain !== '') {
                $existing[$domain] = $broker;
            }
        }

        $matched = 0;
        $unchanged = 0;
        $missingSourceUrl = 0;
        $unsafeSourceUrl = 0;
        $urlChanges = [];
        $matchedDomains = [];
        foreach ($source['brokers'] as $domain => $broker) {
            $current = $existing[$domain] ?? null;
            if ($current === null) {
                continue;
            }

            $matched++;
            $matchedDomains[$domain] = true;
            $sourceUrl = trim((string) ($broker['optout_url'] ?? ''));
            if ($sourceUrl === '') {
                $missingSourceUrl++;

                continue;
            }
            if (! $this->isSafeSourceUrl($sourceUrl)) {
                $unsafeSourceUrl++;

                continue;
            }
            if ($this->sameUrl((string) $current->removal_url, $sourceUrl)) {
                $unchanged++;

                continue;
            }

            $urlChanges[] = [
                'broker_id' => (int) $current->id,
                'name' => (string) $current->name,
                'domain' => $domain,
                'current_url' => (string) $current->removal_url,
                'source_url' => $sourceUrl,
                'source_id' => (string) ($broker['id'] ?? ''),
                'source_repository' => self::SOURCE_ID,
                'source_license' => self::SOURCE_LICENSE,
            ];
        }

        $unmatchedBrokers = [];
        foreach ($existing as $domain => $broker) {
            if (isset($matchedDomains[$domain])) {
                continue;
            }

            $unmatchedBrokers[] = [
                'broker_id' => (int) $broker->id,
                'name' => (string) $broker->name,
                'domain' => $domain,
                'current_url' => (string) $broker->removal_url,
                'health_status' => (string) $broker->health_status,
            ];
        }

        return [
            'success' => true,
            'read_only' => true,
            'broken_only' => $brokenOnly,
            'source' => [
                'repository' => self::SOURCE_ID,
                'url' => self::SOURCE_URL,
                'license' => self::SOURCE_LICENSE,
            ],
            'summary' => [
                'source_rows' => $source['source_rows'],
                'source_domains' => count($source['brokers']),
                'candidate_brokers' => count($existing),
                'matched' => $matched,
                'unmatched' => count($unmatchedBrokers),
                'unchanged_urls' => $unchanged,
                'url_changes' => count($urlChanges),
                'missing_source_url' => $missingSourceUrl,
                'unsafe_source_url' => $unsafeSourceUrl,
            ],
            'url_changes' => $urlChanges,
            'unmatched_brokers' => array_slice($unmatchedBrokers, 0, 100),
        ];
    }

    public function repairBrokenUrls(bool $confirmed = false): array
    {
        if (! $confirmed) {
            return [
                'success' => false,
                'error' => 'explicit_confirmation_required',
                'writes_enabled' => false,
            ];
        }

        $preview = $this->previewSync(true);
        if (($preview['success'] ?? false) !== true) {
            return $preview;
        }

        $updated = [];
        foreach ($preview['url_changes'] as $change) {
            $attribution = sprintf(
                '[%s] URL repaired from %s (%s, %s); prior URL: %s',
                now()->toDateString(),
                self::SOURCE_ID,
                self::SOURCE_LICENSE,
                $change['source_id'] ?: 'source-id-unavailable',
                $change['current_url'] ?: 'none'
            );
            $existingNotes = trim((string) DB::table('data_brokers')
                ->where('id', $change['broker_id'])
                ->value('removal_notes'));

            $affected = DB::table('data_brokers')
                ->where('id', $change['broker_id'])
                ->where('is_active', 1)
                ->where('health_status', 'broken')
                ->update([
                    'removal_url' => $change['source_url'],
                    'badbool_id' => $change['source_id'] ?: null,
                    'health_status' => 'unknown',
                    'last_health_check' => null,
                    'optout_page_hash' => null,
                    'form_config_source' => self::SOURCE_ID,
                    'form_config_updated_at' => now(),
                    'removal_notes' => $existingNotes === '' ? $attribution : $existingNotes."\n".$attribution,
                    'updated_at' => now(),
                ]);

            if ($affected === 1) {
                $updated[] = [
                    'broker_id' => $change['broker_id'],
                    'domain' => $change['domain'],
                    'source_url' => $change['source_url'],
                ];
            }
        }

        return [
            'success' => true,
            'writes_enabled' => true,
            'source' => $preview['source'],
            'previewed' => count($preview['url_changes']),
            'updated' => count($updated),
            'repairs' => $updated,
        ];
    }

    public function syncBADBOOL(): array
    {
        $source = $this->fetchSource();
        if (($source['success'] ?? false) !== true) {
            return $source;
        }

        return $this->importBrokers(array_values($source['brokers']));
    }

    public function importBrokers(array $data): array
    {
        $results = ['total' => count($data), 'new' => 0, 'updated' => 0, 'skipped' => 0];
        $existingByDomain = [];
        foreach (DB::table('data_brokers')->select(['id', 'domain'])->get() as $existing) {
            $normalized = $this->normalizeDomain((string) $existing->domain);
            if ($normalized !== '') {
                $existingByDomain[$normalized] = (int) $existing->id;
            }
        }

        foreach ($data as $broker) {
            $domain = $this->normalizeDomain((string) ($broker['domain'] ?? $broker['url'] ?? ''));
            if ($domain === '') {
                $results['skipped']++;

                continue;
            }

            $existingId = $existingByDomain[$domain] ?? null;
            $badboolId = $broker['id'] ?? null;
            $name = $broker['name'] ?? $domain;
            $optoutUrl = $broker['optout_url'] ?? $broker['removal_url'] ?? null;
            $optoutUrl = is_string($optoutUrl) && $this->isSafeSourceUrl($optoutUrl) ? $optoutUrl : null;
            $category = $broker['category'] ?? null;

            if ($existingId !== null) {
                $updates = [
                    'badbool_id' => $badboolId,
                    'form_config_source' => self::SOURCE_ID,
                    'form_config_updated_at' => now(),
                    'updated_at' => now(),
                ];
                if ($optoutUrl !== null) {
                    $updates['removal_url'] = $optoutUrl;
                }
                DB::table('data_brokers')->where('id', $existingId)->update($updates);
                $results['updated']++;
            } else {
                $id = DB::table('data_brokers')->insertGetId([
                    'name' => $name,
                    'domain' => $domain,
                    'category' => $category,
                    'removal_url' => $optoutUrl,
                    'badbool_id' => $badboolId,
                    'health_status' => 'unknown',
                    'form_config_source' => self::SOURCE_ID,
                    'form_config_updated_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $existingByDomain[$domain] = (int) $id;
                $results['new']++;
            }
        }

        Log::info('BrokerListSync: Import completed', $results);

        return array_merge(['success' => true], $results);
    }

    public function getSyncStatus(): array
    {
        $total = DB::selectOne('SELECT COUNT(*) as count FROM data_brokers');
        $withSource = DB::selectOne('SELECT COUNT(*) as count FROM data_brokers WHERE badbool_id IS NOT NULL');
        $lastUpdated = DB::selectOne('SELECT MAX(updated_at) as last_update FROM data_brokers WHERE badbool_id IS NOT NULL');

        return [
            'total_brokers' => $total->count ?? 0,
            'source_brokers' => $withSource->count ?? 0,
            'last_sync' => $lastUpdated->last_update ?? null,
            'source_repository' => self::SOURCE_ID,
            'source_license' => self::SOURCE_LICENSE,
        ];
    }

    /** @return array{success: bool, brokers?: array<string, array<string, mixed>>, source_rows?: int, error?: string} */
    private function fetchSource(): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(30)
                ->withUserAgent('PLOS Data Removal Source Review')
                ->get(self::SOURCE_URL);
            if (! $response->successful() || trim($response->body()) === '') {
                return ['success' => false, 'error' => 'source_http_'.$response->status()];
            }

            $parsed = Yaml::parse($response->body());
            $rows = is_array($parsed) && is_array($parsed['brokers'] ?? null) ? $parsed['brokers'] : [];
            $brokers = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $domain = $this->normalizeDomain((string) ($row['website'] ?? ''));
                if ($domain === '') {
                    continue;
                }

                $category = match ((string) ($row['category'] ?? '')) {
                    'people-search' => 'people_search',
                    'background-check' => 'background_check',
                    'data-aggregator' => 'data_aggregator',
                    'marketing' => 'marketing',
                    default => 'other',
                };
                $brokers[$domain] = [
                    'id' => isset($row['id']) ? substr((string) $row['id'], 0, 50) : null,
                    'name' => (string) ($row['name'] ?? $domain),
                    'domain' => $domain,
                    'optout_url' => isset($row['opt_out_url']) ? trim((string) $row['opt_out_url']) : null,
                    'category' => $category,
                ];
            }

            if ($brokers === []) {
                return ['success' => false, 'error' => 'source_contains_no_brokers'];
            }

            return ['success' => true, 'brokers' => $brokers, 'source_rows' => count($rows)];
        } catch (\Throwable $e) {
            Log::error('BrokerListSync: source fetch failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function normalizeDomain(string $value): string
    {
        $value = trim(strtolower($value));
        if ($value === '') {
            return '';
        }

        $url = str_contains($value, '://') ? $value : 'https://'.$value;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_replace('/^www\./', '', $host) ?? '';
    }

    private function isSafeSourceUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    private function sameUrl(string $current, string $source): bool
    {
        return rtrim(trim($current), '/') === rtrim(trim($source), '/');
    }
}
