<?php

namespace App\Console\Commands;

use App\Services\EmailClassificationService;
use App\Engine\MCPRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EmailClassifyCommand extends Command
{
    protected $signature = 'email:classify
                            {query? : Search query for emails}
                            {--folder=INBOX : Email folder to search}
                            {--limit=5 : Maximum number of emails to classify}
                            {--stats : Show classification statistics}';

    protected $description = 'Classify emails using AI';

    public function handle(EmailClassificationService $service, MCPRouter $mcpRouter): int
    {
        if ($this->option('stats')) {
            return $this->showStats($service);
        }

        $query = $this->argument('query') ?? '';
        $folder = $this->option('folder');
        $limit = (int) $this->option('limit');

        $this->info("🔍 Searching emails in '{$folder}'...");

        try {
            // Search emails via Thunderbird MCP
            $searchResult = $mcpRouter->callTool('thunderbird', 'searchMessages', [
                'query' => $query,
                'folder' => $folder,
            ]);

            if (!($searchResult['success'] ?? false)) {
                $this->error('❌ Email search failed via Thunderbird MCP');
                return 1;
            }

            $emails = $searchResult['result'] ?? [];

            if (empty($emails)) {
                $this->warn('No emails found matching query');
                return 0;
            }

            $this->info(sprintf('Found %d emails, classifying %d...', count($emails), min(count($emails), $limit)));
            $this->newLine();

            // Classify each email
            $bar = $this->output->createProgressBar(min(count($emails), $limit));
            $bar->start();

            $classified = [];
            foreach (array_slice($emails, 0, $limit) as $email) {
                $result = $service->classifyEmail($email);

                if ($result['success'] ?? false) {
                    $classified[] = [
                        'from' => $email['from'] ?? 'unknown',
                        'subject' => $email['subject'] ?? '',
                        'classification' => $result['classification'],
                    ];
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            // Display results
            $this->displayResults($classified);

            $this->newLine();
            $this->info(sprintf('✓ Successfully classified %d/%d emails', count($classified), $limit));

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Classification failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function displayResults(array $classified): void
    {
        foreach ($classified as $result) {
            $class = $result['classification'];

            $this->line('─────────────────────────────────────────────────────');
            $this->line(sprintf('<fg=cyan>From:</> %s', substr($result['from'], 0, 60)));
            $this->line(sprintf('<fg=cyan>Subject:</> %s', substr($result['subject'], 0, 60)));
            $this->line(sprintf(
                '<fg=yellow>Category:</> %s  <fg=yellow>Priority:</> %s  <fg=yellow>Confidence:</> %.2f',
                $class['category'],
                $class['priority'],
                $class['confidence']
            ));

            if (!empty($class['tags'])) {
                $this->line(sprintf('<fg=blue>Tags:</> %s', implode(', ', $class['tags'])));
            }

            if (!empty($class['summary'])) {
                $this->line(sprintf('<fg=green>Summary:</> %s', $class['summary']));
            }
        }

        $this->line('─────────────────────────────────────────────────────');
    }

    private function showStats(EmailClassificationService $service): int
    {
        try {
            $stats = $service->getStats();

            $this->info('📊 Email Classification Statistics');
            $this->newLine();

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Classified', $stats['total_classified']],
                    ['Average Confidence', sprintf('%.4f', $stats['average_confidence'] ?? 0)],
                ]
            );

            $this->newLine();
            $this->info('By Category:');
            $this->table(
                ['Category', 'Count'],
                collect($stats['by_category'] ?? [])->map(fn($count, $cat) => [$cat, $count])->values()->toArray()
            );

            $this->newLine();
            $this->info('By Priority:');
            $this->table(
                ['Priority', 'Count'],
                collect($stats['by_priority'] ?? [])->map(fn($count, $pri) => [$pri, $count])->values()->toArray()
            );

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Failed to get stats: ' . $e->getMessage());
            return 1;
        }
    }
}
