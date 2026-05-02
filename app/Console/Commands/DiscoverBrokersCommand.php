<?php

namespace App\Console\Commands;

use App\Services\BrokerDiscoveryService;
use App\Services\BrowserAutomationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Discover Data Brokers Command
 *
 * Uses AI to discover new data broker sites and queues them for human approval.
 * Does NOT auto-add brokers - all discovered brokers require human vetting.
 *
 * E06: Personal Data Removal System
 */
class DiscoverBrokersCommand extends Command
{
    protected $signature = 'brokers:discover
                            {--max=10 : Maximum number of brokers to discover}
                            {--category= : Focus on specific category (people_search, marketing, background_check)}
                            {--dry-run : Show what would be discovered without saving}
                            {--status : Show current discovery queue status}
                            {--approve= : Approve a pending broker by ID}
                            {--reject= : Reject a pending broker by ID}
                            {--list-pending : List all pending brokers for review}';

    protected $description = 'AI-powered data broker discovery with human approval workflow';

    private BrokerDiscoveryService $discoveryService;
    private BrowserAutomationService $browserService;

    public function __construct(BrokerDiscoveryService $discoveryService, BrowserAutomationService $browserService)
    {
        parent::__construct();
        $this->discoveryService = $discoveryService;
        $this->browserService = $browserService;
    }

    public function handle(): int
    {
        // D2 decision (2026-03-16): broker_discovery_queue table dropped.
        // This command is disabled until CA DROP API integration (Spring 2026).
        $this->warn('⚠️  broker_discovery_queue table was dropped (D2 decision). Command disabled.');
        $this->info('Broker management now via data_brokers table directly. CA DROP API coming Spring 2026.');
        return Command::SUCCESS;

        // Handle status check
        if ($this->option('status')) {
            return $this->showStatus();
        }

        // Handle listing pending
        if ($this->option('list-pending')) {
            return $this->listPending();
        }

        // Handle approval
        if ($this->option('approve')) {
            return $this->approveBroker((int) $this->option('approve'));
        }

        // Handle rejection
        if ($this->option('reject')) {
            return $this->rejectBroker((int) $this->option('reject'));
        }

        // Run discovery
        return $this->runDiscovery();
    }

    /**
     * Run AI-powered broker discovery
     */
    private function runDiscovery(): int
    {
        $maxResults = (int) $this->option('max');
        $category = $this->option('category');
        $dryRun = $this->option('dry-run');

        $this->info("🔍 Starting AI-powered broker discovery...");
        $this->info("   Max results: {$maxResults}");
        if ($category) {
            $this->info("   Category filter: {$category}");
        }
        if ($dryRun) {
            $this->warn("   DRY RUN - no changes will be saved");
        }
        $this->newLine();

        // Get existing domains to skip
        $existingDomains = $this->getExistingDomains();
        $this->info("   Existing brokers to skip: " . count($existingDomains));

        // Run AI discovery
        $result = $this->discoveryService->discoverBrokers([
            'max_results' => $maxResults,
            'category' => $category,
            'skip_existing' => true,
        ]);

        if (!$result['success']) {
            $this->error("Discovery failed: " . ($result['error'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        $suggestions = $result['suggestions'] ?? [];

        if (empty($suggestions)) {
            $this->info("No new brokers discovered.");
            return Command::SUCCESS;
        }

        $this->info("📋 Discovered " . count($suggestions) . " potential brokers:");
        $this->newLine();

        $queued = 0;
        $skipped = 0;

        foreach ($suggestions as $suggestion) {
            $domain = $suggestion['domain'] ?? '';
            $name = $suggestion['name'] ?? $domain;
            $category = $suggestion['category'] ?? 'unknown';

            // Skip if already in queue or brokers table
            if ($this->isDomainInQueue($domain) || in_array($domain, $existingDomains)) {
                $this->line("   ⏭️  {$domain} - already exists, skipping");
                $skipped++;
                continue;
            }

            $this->line("   🆕 {$name} ({$domain})");
            $this->line("      Category: {$category}");
            if (!empty($suggestion['removal_url'])) {
                $this->line("      Removal URL: {$suggestion['removal_url']}");
            }

            if (!$dryRun) {
                // Research the broker for more details
                $this->line("      ⏳ Researching...");
                $research = $this->discoveryService->researchBroker($domain);

                $brokerData = array_merge($suggestion, $research['broker'] ?? []);

                // Calculate confidence score
                $confidence = $this->calculateConfidence($brokerData);

                // Queue for approval
                $this->queueForApproval($domain, $name, $brokerData, $confidence);
                $this->line("      ✅ Queued for approval (confidence: {$confidence}%)");
                $queued++;
            }

            $this->newLine();
        }

        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("   Discovered: " . count($suggestions));
        $this->info("   Queued for approval: {$queued}");
        $this->info("   Skipped (duplicates): {$skipped}");

        if (!$dryRun && $queued > 0) {
            $this->newLine();
            $this->warn("⚠️  {$queued} brokers require human approval before being added.");
            $this->info("   Run: php artisan brokers:discover --list-pending");
            $this->info("   Approve: php artisan brokers:discover --approve=<id>");
        }

        Log::info('BrokerDiscovery: Discovery completed', [
            'discovered' => count($suggestions),
            'queued' => $queued,
            'skipped' => $skipped,
        ]);

        return Command::SUCCESS;
    }

    // broker_discovery_queue table dropped (D2 decision 2026-03-16).
    // Methods below are unreachable — handle() returns early above.
    // Stubbed to remove broken SQL references. Restore when CA DROP API (Spring 2026) is built.

    private function showStatus(): int
    {
        $this->warn('broker_discovery_queue table dropped (D2). Stub only.');
        return Command::SUCCESS;
    }

    private function listPending(): int
    {
        $this->warn('broker_discovery_queue table dropped (D2). Stub only.');
        return Command::SUCCESS;
    }

    private function approveBroker(int $id): int
    {
        $this->warn('broker_discovery_queue table dropped (D2). Stub only.');
        return Command::FAILURE;
    }

    private function rejectBroker(int $id): int
    {
        $this->warn('broker_discovery_queue table dropped (D2). Stub only.');
        return Command::FAILURE;
    }

    private function getExistingDomains(): array
    {
        return array_map(fn($r) => $r->domain, DB::select("SELECT LOWER(domain) as domain FROM data_brokers"));
    }

    private function isDomainInQueue(string $domain): bool
    {
        return false; // broker_discovery_queue table dropped (D2)
    }

    private function queueForApproval(string $domain, string $name, array $analysis, float $confidence): void
    {
        // broker_discovery_queue table dropped (D2 decision 2026-03-16). No-op until CA DROP API built.
    }

    /**
     * Calculate confidence score based on AI analysis
     */
    private function calculateConfidence(array $analysis): float
    {
        $score = 50.0; // Base score

        // Has removal URL (+20)
        if (!empty($analysis['removal_url'])) {
            $score += 20;
        }

        // Has specific category (+10)
        if (!empty($analysis['category']) && $analysis['category'] !== 'unknown') {
            $score += 10;
        }

        // Has description (+10)
        if (!empty($analysis['description'])) {
            $score += 10;
        }

        // Has data types listed (+5)
        if (!empty($analysis['data_collected'])) {
            $score += 5;
        }

        // Has removal method specified (+5)
        if (!empty($analysis['removal_method']) && $analysis['removal_method'] !== 'unknown') {
            $score += 5;
        }

        return min(100.0, $score);
    }

    /**
     * Add domain to Puppeteer allowlist (via BrowserAutomationService)
     */
    private function addToAllowlist(string $domain): void
    {
        if (!$this->browserService->addToAllowlist($domain)) {
            $this->line("   Note: Could not add to Puppeteer allowlist (server may not be running)");
        }
    }
}
