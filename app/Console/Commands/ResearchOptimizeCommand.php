<?php

namespace App\Console\Commands;

use App\Services\Research\SourceOptimizationService;
use App\Services\Research\DynamicSourceDiscoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ResearchOptimizeCommand - Optimize and heal research sources
 *
 * Similar to RssFeedHealthMonitor, this command:
 * - Runs self-healing on failing sources
 * - Optimizes discovery rules based on performance
 * - Generates health reports
 * - Suggests new sources based on patterns
 *
 * Usage:
 *   php artisan research:optimize --heal       # Run self-healing
 *   php artisan research:optimize --rules      # Optimize rules
 *   php artisan research:optimize --report     # Generate health report
 *   php artisan research:optimize --suggest=genealogy  # Suggest sources
 *   php artisan research:optimize --all        # Run all optimizations
 */
class ResearchOptimizeCommand extends Command
{
    protected $signature = 'research:optimize
                            {--heal : Run self-healing on failing sources}
                            {--rules : Optimize discovery rules based on performance}
                            {--report : Generate comprehensive health report}
                            {--suggest= : Suggest new sources for a category}
                            {--all : Run all optimizations}
                            {--json : Output results as JSON}';

    protected $description = 'Optimize research sources and discovery rules';

    private string $connection = 'pgsql_rag';

    public function handle(SourceOptimizationService $optimizationService): int
    {
        $runAll = $this->option('all');
        $asJson = $this->option('json');
        $results = [];

        if (!$runAll && !$this->option('heal') && !$this->option('rules')
            && !$this->option('report') && !$this->option('suggest')) {
            $this->showStatus();
            return 0;
        }

        // Self-healing
        if ($runAll || $this->option('heal')) {
            if (!$asJson) {
                $this->info("\n🔧 Running self-healing...");
            }
            $healResults = $optimizationService->runSelfHealing();
            $results['healing'] = $healResults;

            if (!$asJson) {
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Sources Checked', $healResults['sources_checked'] ?? 0],
                        ['Sources Healed', $healResults['sources_healed'] ?? 0],
                        ['Sources Deactivated', $healResults['sources_deactivated'] ?? 0],
                        ['Duration', ($healResults['duration_ms'] ?? 0) . 'ms'],
                    ]
                );

                if (!empty($healResults['healing_attempts'])) {
                    $this->line("\nHealing Details:");
                    foreach ($healResults['healing_attempts'] as $attempt) {
                        $status = $attempt['result']['success'] ? '✓' : '✗';
                        $action = $attempt['result']['action'] ?? 'unknown';
                        $this->line("  {$status} {$attempt['domain']} - {$action}");
                    }
                }
            }
        }

        // Rule optimization
        if ($runAll || $this->option('rules')) {
            if (!$asJson) {
                $this->info("\n📊 Optimizing discovery rules...");
            }
            $ruleResults = $optimizationService->optimizeDiscoveryRules();
            $results['rule_optimization'] = $ruleResults;

            if (!$asJson) {
                $this->table(
                    ['Metric', 'Value'],
                    [
                        ['Rules Updated', $ruleResults['rules_updated'] ?? 0],
                        ['Rules Disabled', $ruleResults['rules_disabled'] ?? 0],
                        ['Duration', ($ruleResults['duration_ms'] ?? 0) . 'ms'],
                    ]
                );

                if (!empty($ruleResults['tld_adjustments'])) {
                    $this->line("\nTLD Adjustments:");
                    foreach ($ruleResults['tld_adjustments'] as $adj) {
                        $direction = $adj['adjustment'] > 0 ? '↑' : '↓';
                        $this->line("  {$direction} .{$adj['tld']}: {$adj['previous_score']} → {$adj['new_score']}");
                    }
                }
            }
        }

        // Health report
        if ($runAll || $this->option('report')) {
            if (!$asJson) {
                $this->info("\n📋 Generating health report...");
            }
            $report = $optimizationService->generateHealthReport();
            $results['health_report'] = $report;

            if (!$asJson) {
                $this->displayHealthReport($report);
            }
        }

        // Source suggestions
        $suggestCategory = $this->option('suggest');
        if ($suggestCategory) {
            if (!$asJson) {
                $this->info("\n💡 Generating source suggestions for: {$suggestCategory}");
            }
            $suggestions = $optimizationService->suggestNewSources($suggestCategory);
            $results['suggestions'] = $suggestions;

            if (!$asJson) {
                $this->displaySuggestions($suggestions, $suggestCategory);
            }
        }

        // JSON output
        if ($asJson) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT));
        }

        return 0;
    }

    /**
     * Show current status when no options provided
     */
    private function showStatus(): void
    {
        $this->info("\n📊 Research Source Status");
        $this->line(str_repeat('─', 50));

        // Get quick stats
        $stats = DB::connection($this->connection)->select("
            SELECT
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE is_active) as active,
                COUNT(*) FILTER (WHERE is_whitelisted) as whitelisted,
                COUNT(*) FILTER (WHERE consecutive_failures >= 3 AND is_active) as degraded,
                COUNT(*) FILTER (WHERE consecutive_failures >= 5) as failed
            FROM discovered_sources
        ");

        $ruleStats = DB::connection($this->connection)->select("
            SELECT COUNT(*) as total, SUM(times_applied) as applications
            FROM discovery_rules WHERE is_active = true
        ");

        $s = $stats[0] ?? (object)[];
        $r = $ruleStats[0] ?? (object)[];

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Sources', $s->total ?? 0],
                ['Active Sources', $s->active ?? 0],
                ['Whitelisted', $s->whitelisted ?? 0],
                ['Degraded (3+ failures)', $s->degraded ?? 0],
                ['Failed (5+ failures)', $s->failed ?? 0],
                ['Active Rules', $r->total ?? 0],
                ['Rule Applications', $r->applications ?? 0],
            ]
        );

        $this->line("\nUsage:");
        $this->line("  php artisan research:optimize --heal       Run self-healing");
        $this->line("  php artisan research:optimize --rules      Optimize rules");
        $this->line("  php artisan research:optimize --report     Health report");
        $this->line("  php artisan research:optimize --suggest=genealogy");
        $this->line("  php artisan research:optimize --all        All operations");
    }

    /**
     * Display health report
     */
    private function displayHealthReport(array $report): void
    {
        $summary = $report['summary'] ?? [];

        $this->line("\n📈 Summary");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Sources', $summary['total_sources'] ?? 0],
                ['Active', $summary['active_sources'] ?? 0],
                ['Whitelisted', $summary['whitelisted'] ?? 0],
                ['Blacklisted', $summary['blacklisted'] ?? 0],
                ['Degraded', $summary['degraded'] ?? 0],
                ['Failed', $summary['failed'] ?? 0],
                ['Perfect (no failures)', $summary['perfect'] ?? 0],
                ['Avg Trust Score', $summary['avg_trust'] ?? 'N/A'],
                ['Avg Safety Score', $summary['avg_safety'] ?? 'N/A'],
                ['Success Rate', ($summary['overall_success_rate'] ?? 0) . '%'],
            ]
        );

        // Failing sources
        if (!empty($report['failing_sources'])) {
            $this->line("\n⚠️  Failing Sources (top 10)");
            $rows = array_map(fn($s) => [
                $s['domain'],
                $s['consecutive_failures'],
                substr($s['last_error_message'] ?? 'N/A', 0, 40),
            ], array_slice($report['failing_sources'], 0, 10));

            $this->table(['Domain', 'Failures', 'Last Error'], $rows);
        }

        // Top performing
        if (!empty($report['top_performing'])) {
            $this->line("\n🌟 Top Performing Sources (top 10)");
            $rows = array_map(fn($s) => [
                $s['domain'],
                $s['success_count'],
                $s['success_rate'] . '%',
                $s['trust_score'],
            ], array_slice($report['top_performing'], 0, 10));

            $this->table(['Domain', 'Successes', 'Rate', 'Trust'], $rows);
        }

        // Rule effectiveness
        if (!empty($report['rule_effectiveness'])) {
            $this->line("\n📋 Rule Effectiveness");
            $rows = array_map(fn($r) => [
                $r['rule_type'],
                $r['rule_count'],
                $r['total_applications'],
                $r['unused_rules'],
            ], $report['rule_effectiveness']);

            $this->table(['Type', 'Rules', 'Applications', 'Unused'], $rows);
        }

        // Recommendations
        if (!empty($report['recommendations'])) {
            $this->line("\n💡 Recommendations");
            foreach ($report['recommendations'] as $rec) {
                $icon = match ($rec['priority']) {
                    'high' => '🔴',
                    'medium' => '🟡',
                    default => '🟢',
                };
                $this->line("  {$icon} [{$rec['type']}] {$rec['message']}");
            }
        }
    }

    /**
     * Display source suggestions
     */
    private function displaySuggestions(array $suggestions, string $category): void
    {
        if (empty($suggestions) || ($suggestions['based_on_count'] ?? 0) === 0) {
            $this->warn("No successful sources found for category: {$category}");
            $this->line("Try running some research missions first to build source performance data.");
            return;
        }

        $this->line("\nBased on {$suggestions['based_on_count']} successful sources:");

        if (!empty($suggestions['common_tlds'])) {
            $this->line("\n📌 Common TLDs:");
            foreach ($suggestions['common_tlds'] as $tld => $count) {
                $this->line("  .{$tld}: {$count} sources");
            }
        }

        if (!empty($suggestions['common_keywords'])) {
            $this->line("\n🔑 Common Keywords:");
            $keywords = array_slice($suggestions['common_keywords'], 0, 5, true);
            $this->line("  " . implode(', ', array_keys($keywords)));
        }

        if (!empty($suggestions['suggested_search_terms'])) {
            $this->line("\n🔍 Suggested Search Terms:");
            foreach (array_slice($suggestions['suggested_search_terms'], 0, 5) as $term) {
                $this->line("  • {$term}");
            }
        }

        if (!empty($suggestions['example_domains'])) {
            $this->line("\n✨ Example High-Performing Domains:");
            foreach ($suggestions['example_domains'] as $domain) {
                $this->line("  • {$domain}");
            }
        }
    }
}
