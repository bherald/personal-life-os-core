<?php

namespace App\Console\Commands;

use App\Services\BiasMaintenanceService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BiasMaintenanceCommand extends Command
{
    protected $signature = 'bias:maintenance
                            {--refresh : Refresh bias ratings from GitHub sources}
                            {--source=free : Refresh source: free/mbfc, allsides, or both}
                            {--confirm-allsides : Explicitly confirm AllSides refresh licensing/access before running}
                            {--polarizing : Update polarizing source flags}
                            {--seed-words : Seed emotional language words}
                            {--stats : Show current bias data statistics}
                            {--all : Run all maintenance tasks}';

    protected $description = 'Run bias data maintenance tasks (normally runs monthly from scheduled Maintenance jobs)';

    public function handle(BiasMaintenanceService $service): int
    {
        if ($this->option('stats')) {
            $this->showStatistics($service);

            return 0;
        }

        $source = (string) $this->option('source');
        $runAll = $this->option('all') || (! $this->option('refresh') && ! $this->option('polarizing') && ! $this->option('seed-words'));

        if ($this->requiresAllSidesConfirmation($source)) {
            $this->error('AllSides refresh requires explicit operator confirmation. Re-run with --confirm-allsides after verifying licensing/access.');

            return 2;
        }

        if ($runAll) {
            $this->info('Running all bias maintenance tasks...');
            $this->newLine();

            try {
                $results = $service->runMonthlyMaintenance($source);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return 2;
            }

            $this->info('✓ Bias ratings refresh:');
            $this->showRefreshResults($results['bias_refresh'] ?? []);

            $this->info("✓ Polarizing sources updated: {$results['polarizing_sources_updated']}");
            $this->info("✓ Emotional words added: {$results['emotional_words_added']}");

            $this->newLine();
            $this->showStatistics($service);

            return 0;
        }

        if ($this->option('refresh')) {
            $this->info('Refreshing bias ratings from GitHub...');
            try {
                $results = $service->refreshBiasRatings($source);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return 2;
            }

            $this->showRefreshResults($results);
        }

        if ($this->option('polarizing')) {
            $this->info('Updating polarizing source flags...');
            $count = $service->updatePolarizingSources();
            $this->info("Updated {$count} polarizing sources");
        }

        if ($this->option('seed-words')) {
            $this->info('Seeding emotional language words...');
            $count = $service->seedEmotionalWords();
            $this->info("Added {$count} new emotional words");
        }

        return 0;
    }

    private function requiresAllSidesConfirmation(string $source): bool
    {
        return in_array(strtolower(trim($source)), ['allsides', 'both'], true)
            && ! $this->option('confirm-allsides');
    }

    private function showRefreshResults(array $results): void
    {
        $this->line('  Source: '.($results['source'] ?? 'unknown'));
        $this->line('  AllSides: '.($results['allsides']['status'] ?? 'unknown'));
        $this->line('  MBFC: '.($results['mbfc']['status'] ?? 'unknown'));
    }

    private function showStatistics(BiasMaintenanceService $service): void
    {
        $stats = $service->getStatistics();

        $this->info('📊 Bias Data Statistics:');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Bias Ratings', $stats['bias_ratings']['total']],
                ['AllSides Only', $stats['bias_ratings']['by_source']['allsides'] ?? 0],
                ['MBFC Only', $stats['bias_ratings']['by_source']['mbfc'] ?? 0],
                ['Both Sources', $stats['bias_ratings']['by_source']['both'] ?? 0],
                ['Manual Ratings', $stats['bias_ratings']['by_source']['manual'] ?? 0],
                ['Polarizing Sources', $stats['bias_ratings']['polarizing_sources']],
                ['Source Aliases', $stats['source_aliases']['total'] ?? 0],
                ['Active Source Aliases', $stats['source_aliases']['active'] ?? 0],
                ['Inactive Source Aliases', $stats['source_aliases']['inactive'] ?? 0],
                ['Orphaned Source Aliases', $stats['source_aliases']['orphaned'] ?? 0],
                ['Emotional Words', $stats['emotional_words']],
                ['Polarizing Topics', $stats['polarizing_topics']],
                ['Last Refresh', $stats['last_refresh'] ?? 'Never'],
            ]
        );
    }
}
