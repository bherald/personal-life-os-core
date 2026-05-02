<?php

namespace App\Console\Commands;

use App\Services\DomainRegistryService;
use Illuminate\Console\Command;

/**
 * DomainsCommand - Manage and inspect data domains
 *
 * Personal Life OS domain management CLI.
 */
class DomainsCommand extends Command
{
    protected $signature = 'domains
                            {action=list : Action: list|stats|health|maintenance}
                            {--domain= : Specific domain to operate on}
                            {--group= : Filter by domain group}
                            {--json : Output as JSON}';

    protected $description = 'Manage Personal Life OS data domains';

    public function handle(DomainRegistryService $registry): int
    {
        $action = $this->argument('action');
        $domain = $this->option('domain');

        return match($action) {
            'list' => $this->listDomains($registry),
            'stats' => $domain ? $this->domainStats($registry, $domain) : $this->allStats($registry),
            'health' => $this->healthCheck($registry),
            'maintenance' => $this->runMaintenance($registry),
            default => $this->error("Unknown action: {$action}") ?? 1,
        };
    }

    private function listDomains(DomainRegistryService $registry): int
    {
        $group = $this->option('group');

        if ($group) {
            $domains = $registry->getDomainsByGroup($group);
        } else {
            $domains = $registry->getAllDomains();
        }

        if ($this->option('json')) {
            $this->line(json_encode($domains, JSON_PRETTY_PRINT));
            return 0;
        }

        $rows = [];
        foreach ($domains as $key => $domain) {
            $rows[] = [
                $key,
                $domain['name'] ?? $key,
                $domain['table'] ?? '-',
                $domain['rag_type'] ?? '-',
                $domain['enabled'] ? '✓' : '✗',
            ];
        }

        $this->table(
            ['Key', 'Name', 'Table', 'RAG Type', 'Enabled'],
            $rows
        );

        // Show groups
        $this->newLine();
        $this->info('Domain Groups:');
        foreach ($registry->getGroups() as $key => $group) {
            $domainList = implode(', ', $group['domains']);
            $this->line("  {$key}: {$group['name']} ({$domainList})");
        }

        return 0;
    }

    private function allStats(DomainRegistryService $registry): int
    {
        $stats = $registry->getAllStats();

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));
            return 0;
        }

        // Domain stats
        $rows = [];
        foreach ($stats['domains'] as $key => $domain) {
            if (isset($domain['error'])) {
                $rows[] = [$key, $domain['name'] ?? $key, 'Error', '-', '-', $domain['error']];
                continue;
            }

            $rows[] = [
                $key,
                $domain['name'],
                number_format($domain['total_records']),
                number_format($domain['rag_indexed']),
                number_format($domain['rag_pending']),
                $domain['enabled'] ? '✓' : '✗',
            ];
        }

        $this->table(
            ['Domain', 'Name', 'Records', 'RAG Indexed', 'RAG Pending', 'Enabled'],
            $rows
        );

        // Totals
        $this->newLine();
        $this->info('Totals:');
        $this->line("  Total Records: " . number_format($stats['totals']['total_records']));
        $this->line("  RAG Indexed: " . number_format($stats['totals']['rag_indexed']));
        $this->line("  RAG Pending: " . number_format($stats['totals']['rag_pending']));

        // By group
        $this->newLine();
        $this->info('By Group:');
        foreach ($stats['by_group'] as $key => $group) {
            $this->line("  {$group['name']}: " . number_format($group['total_records']) . " records");
        }

        return 0;
    }

    private function domainStats(DomainRegistryService $registry, string $domain): int
    {
        try {
            $stats = $registry->getDomainStats($domain);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return 1;
        }

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));
            return 0;
        }

        $this->info("Domain: {$stats['name']}");
        $this->table(['Metric', 'Value'], [
            ['Table', $stats['table']],
            ['Enabled', $stats['enabled'] ? 'Yes' : 'No'],
            ['Total Records', number_format($stats['total_records'])],
            ['RAG Indexed', number_format($stats['rag_indexed'])],
            ['RAG Pending', number_format($stats['rag_pending'])],
            ['Retention Days', $stats['retention_days'] ?: 'Forever'],
        ]);

        return 0;
    }

    private function healthCheck(DomainRegistryService $registry): int
    {
        $health = $registry->healthCheck();

        if ($this->option('json')) {
            $this->line(json_encode($health, JSON_PRETTY_PRINT));
            return 0;
        }

        $statusEmoji = $health['status'] === 'healthy' ? '✅' : '⚠️';
        $this->info("{$statusEmoji} Overall Status: {$health['status']}");

        $this->newLine();

        $rows = [];
        foreach ($health['domains'] as $key => $domain) {
            $status = $domain['healthy'] ? '✓ Healthy' : '✗ Issues';
            $details = [];

            foreach ($domain['checks'] as $check => $result) {
                $details[] = "{$check}: {$result}";
            }

            if (isset($domain['warning'])) {
                $details[] = "⚠️ {$domain['warning']}";
            }

            $rows[] = [$key, $status, implode(', ', $details)];
        }

        $this->table(['Domain', 'Status', 'Details'], $rows);

        if (!empty($health['issues'])) {
            $this->newLine();
            $this->warn('Issues:');
            foreach ($health['issues'] as $issue) {
                $this->line("  - {$issue}");
            }
        }

        return $health['status'] === 'healthy' ? 0 : 1;
    }

    private function runMaintenance(DomainRegistryService $registry): int
    {
        $domain = $this->option('domain');

        if ($domain) {
            $this->info("Running maintenance for domain: {$domain}");
            try {
                $result = $registry->runDomainMaintenance($domain);
                $this->showMaintenanceResult($domain, $result);
            } catch (\Exception $e) {
                $this->error($e->getMessage());
                return 1;
            }
        } else {
            $this->info('Running maintenance for all enabled domains...');
            $results = $registry->runMaintenance();

            foreach ($results as $key => $result) {
                $this->showMaintenanceResult($key, $result);
            }
        }

        return 0;
    }

    private function showMaintenanceResult(string $domain, array $result): void
    {
        $this->newLine();
        $this->line("<info>{$domain}:</info>");

        if (isset($result['error'])) {
            $this->error("  Error: {$result['error']}");
            return;
        }

        if (isset($result['skipped'])) {
            $this->line("  Skipped: {$result['skipped']}");
            return;
        }

        if ($result['synced'] ?? false) {
            $this->line("  ✓ Synced");
            if (isset($result['sync_result'])) {
                $this->line("    " . json_encode($result['sync_result']));
            }
        }

        if (($result['rag_indexed'] ?? 0) > 0) {
            $this->line("  ✓ RAG indexed: {$result['rag_indexed']} records");
        }

        if (($result['cleaned'] ?? 0) > 0) {
            $this->line("  ✓ Cleaned: {$result['cleaned']} old records");
        }

        if (isset($result['sync_error'])) {
            $this->warn("  Sync error: {$result['sync_error']}");
        }
        if (isset($result['rag_error'])) {
            $this->warn("  RAG error: {$result['rag_error']}");
        }
    }
}
