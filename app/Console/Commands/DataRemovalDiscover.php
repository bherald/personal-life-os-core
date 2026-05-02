<?php

namespace App\Console\Commands;

use App\Services\BrokerDiscoveryService;
use App\Services\DataRemovalService;
use Illuminate\Console\Command;

/**
 * Data Removal Broker Discovery Command
 *
 * Uses AI to discover new data broker sites and optionally add them to the database.
 *
 * E06: Personal Data Removal System - Phase 6
 */
class DataRemovalDiscover extends Command
{
    protected $signature = 'data-removal:discover
                            {--max=10 : Maximum number of brokers to discover}
                            {--category= : Filter by category (people_search, marketing, background_check, data_aggregator)}
                            {--add : Add discovered brokers to the database}
                            {--dry-run : Show what would be added without making changes}
                            {--seed : Add well-known brokers that are not yet in database}';

    protected $description = 'Discover new data brokers using AI research';

    private BrokerDiscoveryService $discoveryService;
    private DataRemovalService $dataRemovalService;

    public function __construct(BrokerDiscoveryService $discoveryService, DataRemovalService $dataRemovalService)
    {
        parent::__construct();
        $this->discoveryService = $discoveryService;
        $this->dataRemovalService = $dataRemovalService;
    }

    public function handle(): int
    {
        $max = (int) $this->option('max');
        $category = $this->option('category');
        $addToDB = $this->option('add');
        $dryRun = $this->option('dry-run');
        $seed = $this->option('seed');

        if ($seed) {
            return $this->seedWellKnownBrokers();
        }

        $this->info('Starting AI-powered broker discovery...');
        $this->info('');

        // Get existing domains for comparison
        $existingBrokers = $this->dataRemovalService->getBrokers(false);
        $existingDomains = array_map(fn($b) => strtolower($b->domain), $existingBrokers);
        $this->line("Current brokers in database: <comment>" . count($existingBrokers) . "</comment>");

        if ($addToDB || $dryRun) {
            // Auto-discover and add mode
            $result = $this->discoveryService->autoDiscoverAndAdd([
                'max_results' => $max,
                'category' => $category,
                'max_to_add' => $max,
                'dry_run' => $dryRun,
            ]);

            if (!$result['success']) {
                $this->error('Discovery failed: ' . ($result['error'] ?? 'Unknown error'));
                return Command::FAILURE;
            }

            $this->info('');
            if ($dryRun) {
                $this->warn('DRY RUN - No changes made');
            }

            if (!empty($result['added'])) {
                $this->info('Brokers ' . ($dryRun ? 'that would be added' : 'added') . ':');
                $this->table(
                    $dryRun ? ['Domain', 'Name', 'Status'] : ['ID', 'Domain', 'Name', 'Status'],
                    array_map(function ($b) use ($dryRun) {
                        return $dryRun
                            ? [$b['domain'], $b['name'], $b['status']]
                            : [$b['id'], $b['domain'], $b['name'], $b['status']];
                    }, $result['added'])
                );
            } else {
                $this->warn('No new brokers were discovered or all suggestions already exist.');
            }

            if (!empty($result['errors'])) {
                $this->info('');
                $this->warn('Errors:');
                foreach ($result['errors'] as $err) {
                    $this->error("  {$err['domain']}: {$err['error']}");
                }
            }

        } else {
            // Discovery only mode (no database changes)
            $result = $this->discoveryService->discoverBrokers([
                'max_results' => $max,
                'category' => $category,
            ]);

            if (!$result['success']) {
                $this->error('Discovery failed: ' . ($result['error'] ?? 'Unknown error'));
                return Command::FAILURE;
            }

            if (empty($result['suggestions'])) {
                $this->warn('No new brokers discovered.');
                return Command::SUCCESS;
            }

            $this->info('Discovered ' . count($result['suggestions']) . ' potential brokers:');
            $this->info('');

            $this->table(
                ['Domain', 'Name', 'Category', 'Removal URL'],
                array_map(function ($s) {
                    return [
                        $s['domain'],
                        $s['name'] ?? '-',
                        $s['category'] ?? '-',
                        isset($s['removal_url']) ? substr($s['removal_url'], 0, 40) . '...' : '-',
                    ];
                }, $result['suggestions'])
            );

            $this->info('');
            $this->comment('To add these brokers to the database, run with --add flag:');
            $this->comment("  php artisan data-removal:discover --add --max={$max}");
        }

        return Command::SUCCESS;
    }

    /**
     * Seed well-known brokers that are not yet in the database
     */
    private function seedWellKnownBrokers(): int
    {
        $this->info('Checking well-known data brokers...');
        $this->info('');

        $wellKnown = $this->discoveryService->getWellKnownBrokers();
        $existingBrokers = $this->dataRemovalService->getBrokers(false);
        $existingDomains = array_map(fn($b) => strtolower($b->domain), $existingBrokers);

        $toAdd = [];
        foreach ($wellKnown as $broker) {
            if (!in_array(strtolower($broker['domain']), $existingDomains)) {
                $toAdd[] = $broker;
            }
        }

        if (empty($toAdd)) {
            $this->info('All well-known brokers are already in the database.');
            return Command::SUCCESS;
        }

        $this->info('Found ' . count($toAdd) . ' brokers to add:');
        $this->table(
            ['Domain', 'Name', 'Category'],
            $toAdd
        );

        if (!$this->confirm('Add these brokers to the database?', true)) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        $added = 0;
        $errors = 0;

        foreach ($toAdd as $broker) {
            try {
                // Research the broker for more details
                $this->output->write("  Researching {$broker['domain']}... ");

                $research = $this->discoveryService->researchBroker($broker['domain']);

                if ($research['success'] && isset($research['broker'])) {
                    $brokerData = array_merge($broker, $research['broker']);
                } else {
                    $brokerData = $broker;
                    $brokerData['automation_tier'] = 3; // Default to human-assisted
                    $brokerData['requires_captcha'] = true;
                    $brokerData['uses_javascript'] = true;
                }

                $id = $this->dataRemovalService->createBroker([
                    'name' => $brokerData['name'] ?? ucwords(str_replace(['.', '-'], ' ', $broker['domain'])),
                    'domain' => $brokerData['domain'],
                    'category' => $brokerData['category'] ?? 'people_search',
                    'removal_method' => $brokerData['removal_method'] ?? 'web_form',
                    'removal_url' => $brokerData['removal_url'] ?? null,
                    'automation_tier' => $brokerData['automation_tier'] ?? 2,
                    'requires_captcha' => $brokerData['requires_captcha'] ?? true,
                    'uses_javascript' => $brokerData['uses_javascript'] ?? true,
                    'discovery_notes' => json_encode([
                        'discovered_by' => 'seed',
                        'discovered_at' => now()->toIso8601String(),
                        'ai_notes' => $brokerData['notes'] ?? null,
                    ]),
                ]);

                $this->output->writeln("<info>Added (ID: {$id})</info>");
                $added++;

            } catch (\Exception $e) {
                $this->output->writeln("<error>Failed: {$e->getMessage()}</error>");
                $errors++;
            }

            // Throttle requests
            usleep(500000); // 0.5 seconds
        }

        $this->info('');
        $this->info("=== Seeding Complete ===");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Added', $added],
                ['Errors', $errors],
            ]
        );

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
