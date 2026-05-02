<?php

namespace App\Console\Commands;

use App\Services\DataRemovalService;
use App\Services\BrokerScraperService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Data Removal Scan Command
 *
 * Scans data brokers for a subject's personal information.
 * Creates removal requests for any discovered data.
 *
 * E06: Personal Data Removal System
 */
class DataRemovalScan extends Command
{
    protected $signature = 'data-removal:scan
                            {--subject= : Subject ID to scan (required unless --all)}
                            {--all : Scan for all active subjects}
                            {--broker= : Specific broker ID to scan (optional)}
                            {--tier= : Filter brokers by automation tier (1, 2, or 3)}
                            {--limit=10 : Maximum number of brokers to scan per subject}
                            {--dry-run : Show what would be scanned without actually scanning}';

    protected $description = 'Scan data brokers for subject personal information and create removal requests';

    private DataRemovalService $dataRemovalService;
    private BrokerScraperService $scraperService;

    public function __construct(DataRemovalService $dataRemovalService, BrokerScraperService $scraperService)
    {
        parent::__construct();
        $this->dataRemovalService = $dataRemovalService;
        $this->scraperService = $scraperService;
    }

    public function handle(): int
    {
        $subjectId = $this->option('subject');
        $scanAll = $this->option('all');
        $brokerId = $this->option('broker');
        $tierFilter = $this->option('tier') ? (int) $this->option('tier') : null;
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        // Validate arguments
        if (!$subjectId && !$scanAll) {
            $this->error('You must specify --subject=<id> or --all');
            return Command::FAILURE;
        }

        // Get subjects to scan
        $subjects = [];
        if ($scanAll) {
            $subjects = $this->dataRemovalService->getSubjects(true);
            if (empty($subjects)) {
                $this->warn('No active subjects found.');
                return Command::SUCCESS;
            }
            $this->info('Found ' . count($subjects) . ' active subjects to scan.');
        } else {
            $subject = $this->dataRemovalService->getSubject((int) $subjectId);
            if (!$subject) {
                $this->error("Subject not found: {$subjectId}");
                return Command::FAILURE;
            }
            $subjects = [$subject];
        }

        // Get brokers to scan
        $brokers = [];
        if ($brokerId) {
            $broker = $this->dataRemovalService->getBroker((int) $brokerId);
            if (!$broker) {
                $this->error("Broker not found: {$brokerId}");
                return Command::FAILURE;
            }
            $brokers = [$broker];
        } else {
            $brokers = $this->dataRemovalService->getBrokers(true, null, $tierFilter);
            if (empty($brokers)) {
                $this->warn('No active brokers found' . ($tierFilter ? " for tier {$tierFilter}" : '') . '.');
                return Command::SUCCESS;
            }
        }

        // Apply limit
        if (count($brokers) > $limit) {
            $brokers = array_slice($brokers, 0, $limit);
            $this->info("Limiting scan to {$limit} brokers.");
        }

        $this->info('Scanning ' . count($brokers) . ' broker(s) for ' . count($subjects) . ' subject(s)...');

        if ($dryRun) {
            $this->info('');
            $this->warn('DRY RUN MODE - No actual scanning will be performed.');
            $this->info('');

            $this->table(
                ['Subject', 'Subject ID', 'Brokers to Scan'],
                collect($subjects)->map(fn($s) => [$s->name, $s->id, count($brokers)])->toArray()
            );

            $this->info('');
            $this->info('Brokers:');
            $this->table(
                ['ID', 'Name', 'Domain', 'Tier', 'Method'],
                collect($brokers)->map(fn($b) => [
                    $b->id,
                    $b->name,
                    $b->domain,
                    $b->automation_tier,
                    $b->removal_method,
                ])->toArray()
            );

            return Command::SUCCESS;
        }

        // Ensure browser servers are available for JavaScript-heavy sites
        $this->info('Initializing browser automation...');
        if ($this->scraperService->ensureBrowserAvailable()) {
            $status = $this->scraperService->getBrowserStatus();
            $engines = [];
            if ($status['puppeteer']['available'] ?? false) $engines[] = 'Puppeteer';
            if ($status['playwright']['available'] ?? false) $engines[] = 'Playwright';
            $this->info('Browser engines ready: ' . implode(', ', $engines));
        } else {
            $this->warn('No browser engines available - JavaScript-heavy sites may fail.');
            $this->warn('Falling back to HTTP requests only.');
        }

        $totalScanned = 0;
        $totalFound = 0;
        $totalErrors = 0;

        foreach ($subjects as $subject) {
            $this->info('');
            $this->line("Scanning for: <comment>{$subject->name}</comment>");

            $subjectResults = [];

            foreach ($brokers as $broker) {
                $this->output->write("  {$broker->domain}... ");

                // Check rate limit
                if (!$this->scraperService->checkRateLimit($broker)) {
                    $this->output->writeln('<comment>Rate limited</comment>');
                    continue;
                }

                try {
                    $result = $this->scraperService->searchBroker($broker, $subject);
                    $this->scraperService->recordScan($broker);
                    $totalScanned++;

                    if ($result['found'] ?? false) {
                        $this->output->writeln('<info>FOUND</info>');
                        $totalFound++;

                        // Check if request already exists
                        $existingRequest = $this->dataRemovalService->getRequestBySubjectAndBroker(
                            $subject->id,
                            $broker->id
                        );

                        if ($existingRequest) {
                            $this->line("    Request already exists (ID: {$existingRequest->id}, Status: {$existingRequest->status})");
                        } else {
                            // Create removal request
                            $requestId = $this->dataRemovalService->createRequest($subject->id, $broker->id, [
                                'data_found' => $result['data_found'] ?? [],
                                'profile_url' => $result['profile_url'] ?? null,
                            ]);
                            $this->line("    Created removal request: <info>#{$requestId}</info>");
                        }

                        $subjectResults[] = [
                            'broker' => $broker->domain,
                            'found' => true,
                            'data' => $result['data_found'] ?? [],
                        ];
                    } elseif (isset($result['captcha_detected']) && $result['captcha_detected']) {
                        $this->output->writeln('<comment>CAPTCHA</comment>');
                        $totalErrors++;
                    } elseif (!($result['success'] ?? true)) {
                        $this->output->writeln('<error>ERROR: ' . ($result['error'] ?? 'Unknown') . '</error>');
                        $totalErrors++;
                    } else {
                        $this->output->writeln('<fg=gray>Not found</>');
                    }

                    // Throttle between requests
                    usleep(1000000); // 1 second

                } catch (\Exception $e) {
                    $this->output->writeln('<error>ERROR: ' . $e->getMessage() . '</error>');
                    Log::error("DataRemovalScan: Error scanning {$broker->domain}", [
                        'subject_id' => $subject->id,
                        'broker_id' => $broker->id,
                        'error' => $e->getMessage(),
                    ]);
                    $totalErrors++;
                }
            }
        }

        $this->info('');
        $this->info('=== Scan Complete ===');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Subjects Scanned', count($subjects)],
                ['Brokers Scanned', $totalScanned],
                ['Data Found', $totalFound],
                ['Errors', $totalErrors],
            ]
        );

        if ($totalFound > 0) {
            $this->info('');
            $this->comment("Review pending requests with: php artisan data-removal:digest --pending");
        }

        return Command::SUCCESS;
    }
}
