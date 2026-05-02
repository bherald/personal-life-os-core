<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * DomainRegistryService - Central registry for all data domains
 *
 * Provides:
 * - Discovery of all registered domains
 * - Aggregated statistics across domains
 * - Domain health checks
 * - Maintenance coordination
 */
class DomainRegistryService
{
    /**
     * Get all registered domains
     */
    public function getAllDomains(): array
    {
        return config('domains.domains', []);
    }

    /**
     * Get only enabled domains
     */
    public function getEnabledDomains(): array
    {
        return array_filter(
            $this->getAllDomains(),
            fn($domain) => $domain['enabled'] ?? false
        );
    }

    /**
     * Get a specific domain configuration
     */
    public function getDomain(string $key): ?array
    {
        return config("domains.domains.{$key}");
    }

    /**
     * Get domains by group
     */
    public function getDomainsByGroup(string $group): array
    {
        $groupConfig = config("domains.groups.{$group}");
        if (!$groupConfig) {
            return [];
        }

        $domains = [];
        foreach ($groupConfig['domains'] as $key) {
            $domain = $this->getDomain($key);
            if ($domain) {
                $domains[$key] = $domain;
            }
        }

        return $domains;
    }

    /**
     * Get all groups
     */
    public function getGroups(): array
    {
        return config('domains.groups', []);
    }

    /**
     * Get statistics for all enabled domains
     */
    public function getAllStats(): array
    {
        $stats = [
            'domains' => [],
            'totals' => [
                'total_records' => 0,
                'rag_indexed' => 0,
                'rag_pending' => 0,
            ],
            'by_group' => [],
        ];

        foreach ($this->getEnabledDomains() as $key => $domain) {
            try {
                $domainStats = $this->getDomainStats($key, $domain);
                $stats['domains'][$key] = $domainStats;

                $stats['totals']['total_records'] += $domainStats['total_records'];
                $stats['totals']['rag_indexed'] += $domainStats['rag_indexed'];
                $stats['totals']['rag_pending'] += $domainStats['rag_pending'];
            } catch (Exception $e) {
                $stats['domains'][$key] = [
                    'name' => $domain['name'] ?? $key,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Aggregate by group
        foreach ($this->getGroups() as $groupKey => $group) {
            $groupStats = [
                'name' => $group['name'],
                'total_records' => 0,
                'rag_indexed' => 0,
            ];

            foreach ($group['domains'] as $domainKey) {
                if (isset($stats['domains'][$domainKey]['total_records'])) {
                    $groupStats['total_records'] += $stats['domains'][$domainKey]['total_records'];
                    $groupStats['rag_indexed'] += $stats['domains'][$domainKey]['rag_indexed'];
                }
            }

            $stats['by_group'][$groupKey] = $groupStats;
        }

        return $stats;
    }

    /**
     * Get statistics for a specific domain
     */
    public function getDomainStats(string $key, ?array $domain = null): array
    {
        $domain = $domain ?? $this->getDomain($key);
        if (!$domain) {
            throw new Exception("Domain not found: {$key}");
        }

        $table = $domain['table'];
        $connection = $domain['connection'] ?? 'mysql';

        // Check if table exists
        try {
            $total = DB::connection($connection)->selectOne("SELECT COUNT(*) as cnt FROM {$table}")->cnt;
        } catch (Exception $e) {
            // Table doesn't exist
            return [
                'name' => $domain['name'] ?? $key,
                'table' => $table,
                'enabled' => $domain['enabled'] ?? false,
                'total_records' => 0,
                'rag_indexed' => 0,
                'rag_pending' => 0,
                'error' => 'Table not found',
            ];
        }

        // Count RAG indexed
        $ragIndexed = 0;
        try {
            $ragIndexed = DB::connection($connection)->selectOne(
                "SELECT COUNT(*) as cnt FROM {$table} WHERE rag_indexed_at IS NOT NULL"
            )->cnt;
        } catch (Exception $e) {
            // Column doesn't exist - that's OK
        }

        return [
            'name' => $domain['name'] ?? $key,
            'table' => $table,
            'enabled' => $domain['enabled'] ?? false,
            'total_records' => $total,
            'rag_indexed' => $ragIndexed,
            'rag_pending' => $total - $ragIndexed,
            'retention_days' => $domain['retention_days'] ?? 0,
        ];
    }

    /**
     * Get instantiated service for a domain
     */
    public function getService(string $key): ?object
    {
        $domain = $this->getDomain($key);
        if (!$domain || !($domain['service'] ?? null)) {
            return null;
        }

        try {
            return app($domain['service']);
        } catch (Exception $e) {
            Log::warning("Failed to instantiate domain service", [
                'domain' => $key,
                'service' => $domain['service'],
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Run maintenance on all enabled domains
     */
    public function runMaintenance(): array
    {
        $results = [];

        foreach ($this->getEnabledDomains() as $key => $domain) {
            try {
                $result = $this->runDomainMaintenance($key, $domain);
                $results[$key] = $result;
            } catch (Exception $e) {
                $results[$key] = [
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Run maintenance for a specific domain
     */
    public function runDomainMaintenance(string $key, ?array $domain = null): array
    {
        $domain = $domain ?? $this->getDomain($key);
        if (!$domain) {
            throw new Exception("Domain not found: {$key}");
        }

        $result = [
            'domain' => $key,
            'synced' => false,
            'rag_indexed' => 0,
            'cleaned' => 0,
        ];

        $service = $this->getService($key);
        if (!$service) {
            $result['skipped'] = 'No service configured';
            return $result;
        }

        // Run sync if method configured
        if (!empty($domain['sync_method']) && method_exists($service, $domain['sync_method'])) {
            try {
                $syncResult = $service->{$domain['sync_method']}();
                $result['synced'] = true;
                $result['sync_result'] = $syncResult;
            } catch (Exception $e) {
                $result['sync_error'] = $e->getMessage();
            }
        }

        // RAG index pending records
        if (method_exists($service, 'getPendingRagIndex') && method_exists($service, 'formatForRag')) {
            try {
                $batchSize = config('domains.defaults.rag_batch_size', 50);
                $pending = $service->getPendingRagIndex($batchSize);

                if (!empty($pending)) {
                    $ragService = app(RAGService::class);

                    foreach ($pending as $record) {
                        try {
                            $ragData = $service->formatForRag($record);
                            $ragService->index($ragData);
                            $service->markRagIndexed($record->id);
                            $result['rag_indexed']++;
                        } catch (Exception $e) {
                            // Continue with next record
                        }
                    }
                }
            } catch (Exception $e) {
                $result['rag_error'] = $e->getMessage();
            }
        }

        // Cleanup old records
        $retentionDays = $domain['retention_days'] ?? 0;
        if ($retentionDays > 0 && method_exists($service, 'cleanup')) {
            try {
                $result['cleaned'] = $service->cleanup($retentionDays);
            } catch (Exception $e) {
                $result['cleanup_error'] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Check health of all domains
     */
    public function healthCheck(): array
    {
        $health = [
            'status' => 'healthy',
            'domains' => [],
            'issues' => [],
        ];

        foreach ($this->getEnabledDomains() as $key => $domain) {
            $domainHealth = $this->checkDomainHealth($key, $domain);
            $health['domains'][$key] = $domainHealth;

            if (!$domainHealth['healthy']) {
                $health['status'] = 'degraded';
                $health['issues'][] = "{$key}: {$domainHealth['issue']}";
            }
        }

        return $health;
    }

    /**
     * Check health of a specific domain
     */
    public function checkDomainHealth(string $key, ?array $domain = null): array
    {
        $domain = $domain ?? $this->getDomain($key);

        $health = [
            'domain' => $key,
            'healthy' => true,
            'checks' => [],
        ];

        // Check table exists
        try {
            $table = $domain['table'];
            $connection = $domain['connection'] ?? 'mysql';
            DB::connection($connection)->select("SELECT 1 FROM {$table} LIMIT 1");
            $health['checks']['table'] = 'ok';
        } catch (Exception $e) {
            $health['healthy'] = false;
            $health['checks']['table'] = 'missing';
            $health['issue'] = 'Table not accessible';
            return $health;
        }

        // Check RAG backlog
        try {
            $stats = $this->getDomainStats($key, $domain);
            $pendingRatio = $stats['total_records'] > 0
                ? $stats['rag_pending'] / $stats['total_records']
                : 0;

            if ($pendingRatio > 0.5 && $stats['rag_pending'] > 100) {
                $health['checks']['rag_backlog'] = 'warning';
                $health['warning'] = "RAG backlog: {$stats['rag_pending']} records pending";
            } else {
                $health['checks']['rag_backlog'] = 'ok';
            }
        } catch (Exception $e) {
            $health['checks']['rag_backlog'] = 'unknown';
        }

        // Check service
        $service = $this->getService($key);
        $health['checks']['service'] = $service ? 'ok' : 'not_configured';

        return $health;
    }
}
