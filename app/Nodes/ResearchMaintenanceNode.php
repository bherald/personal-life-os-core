<?php

namespace App\Nodes;

use App\Services\Research\SourceOptimizationService;
use App\Services\Research\DynamicSourceDiscoveryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

/**
 * ResearchMaintenanceNode - Workflow node for automated research source maintenance
 *
 * Performs:
 * - Self-healing of failing sources
 * - Category health checks
 * - Source refresh for stale categories
 * - Discovery rules optimization
 *
 * Config options:
 *   run_healing: bool (default: true) - Run self-healing
 *   run_refresh: bool (default: true) - Refresh stale sources
 *   run_discover: bool (default: true) - Discover for empty categories
 *   run_optimize: bool (default: true) - Optimize discovery rules
 *   category: string|null - Limit to specific category
 *   send_notification: bool (default: false) - Send notification on completion
 *
 * Recommended schedule: Daily at 3:00 AM
 */
class ResearchMaintenanceNode extends BaseNode
{
    private SourceOptimizationService $optimizationService;

    public function execute(array $input): array
    {
        $startTime = microtime(true);
        $this->optimizationService = app(SourceOptimizationService::class);

        // Get config
        $runHealing = $this->getConfigValue('run_healing', true);
        $runRefresh = $this->getConfigValue('run_refresh', true);
        $runDiscover = $this->getConfigValue('run_discover', true);
        $runOptimize = $this->getConfigValue('run_optimize', true);
        $category = $this->getConfigValue('category', null);
        $sendNotification = $this->getConfigValue('send_notification', false);

        Log::info('ResearchMaintenanceNode starting', [
            'healing' => $runHealing,
            'refresh' => $runRefresh,
            'discover' => $runDiscover,
            'optimize' => $runOptimize,
            'category' => $category,
        ]);

        $results = [
            'tasks_run' => [],
            'healing' => null,
            'refresh' => null,
            'discover' => null,
            'optimize' => null,
            'health_summary' => null,
            'issues_found' => [],
            'actions_taken' => [],
        ];

        try {
            // 1. Self-healing
            if ($runHealing) {
                $results['healing'] = $this->optimizationService->runSelfHealing();
                $results['tasks_run'][] = 'healing';

                if (($results['healing']['sources_healed'] ?? 0) > 0) {
                    $results['actions_taken'][] = "Healed {$results['healing']['sources_healed']} failing sources";
                }
                if (($results['healing']['sources_deactivated'] ?? 0) > 0) {
                    $results['actions_taken'][] = "Deactivated {$results['healing']['sources_deactivated']} persistently failing sources";
                }
            }

            // 2. Category health & refresh
            if ($runRefresh || $runDiscover) {
                $categoryHealth = $this->optimizationService->getCategoryHealth();
                $needsAttention = $this->optimizationService->getCategoriesNeedingAttention();
                $results['health_summary'] = $categoryHealth;

                foreach ($needsAttention as $cat => $info) {
                    // Skip if category filter is set and doesn't match
                    if ($category && $cat !== $category) {
                        continue;
                    }

                    $results['issues_found'][] = [
                        'category' => $cat,
                        'reason' => $info['reason'],
                        'priority' => $info['priority'],
                    ];

                    // Refresh if enabled and needed
                    if ($runRefresh && $info['action'] === 'refresh') {
                        $refreshResult = $this->optimizationService->refreshCategorySources($cat);
                        if (!$results['refresh']) {
                            $results['refresh'] = ['categories' => []];
                        }
                        $results['refresh']['categories'][$cat] = $refreshResult;
                        $results['tasks_run'][] = "refresh:{$cat}";
                        $results['actions_taken'][] = "Refreshed {$refreshResult['sources_verified']} sources in '{$cat}'";
                    }

                    // Discover if enabled and needed
                    if ($runDiscover && $info['action'] === 'discover') {
                        // research:maintain command not yet implemented — skip gracefully
                        $results['tasks_run'][] = "discover:{$cat}:skipped";
                        $results['actions_taken'][] = "Discovery skipped for '{$cat}' (command not implemented)";
                    }
                }
            }

            // 3. Rule optimization
            if ($runOptimize) {
                $results['optimize'] = $this->optimizationService->optimizeDiscoveryRules();
                $results['tasks_run'][] = 'optimize';

                if (($results['optimize']['rules_updated'] ?? 0) > 0) {
                    $results['actions_taken'][] = "Updated {$results['optimize']['rules_updated']} discovery rules";
                }
            }

            $results['duration_seconds'] = round(microtime(true) - $startTime, 2);
            $results['success'] = true;

            Log::info('ResearchMaintenanceNode completed', [
                'duration' => $results['duration_seconds'],
                'tasks' => count($results['tasks_run']),
                'issues' => count($results['issues_found']),
                'actions' => count($results['actions_taken']),
            ]);

            // Build summary for output
            $summary = $this->buildSummary($results);

            return $this->standardOutput([
                'summary' => $summary,
                'details' => $results,
            ], [
                'node' => 'ResearchMaintenanceNode',
                'duration_seconds' => $results['duration_seconds'],
                'tasks_run' => $results['tasks_run'],
                'issues_found' => count($results['issues_found']),
                'actions_taken' => count($results['actions_taken']),
            ]);

        } catch (\Exception $e) {
            Log::error('ResearchMaintenanceNode failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->standardOutput(null, [
                'node' => 'ResearchMaintenanceNode',
                'error' => true,
            ], $e->getMessage());
        }
    }

    /**
     * Build human-readable summary
     */
    private function buildSummary(array $results): string
    {
        $lines = ['Research Source Maintenance Report', ''];

        // Tasks run
        $lines[] = "Tasks completed: " . count($results['tasks_run']);

        // Healing results
        if ($results['healing']) {
            $lines[] = sprintf(
                "Self-healing: %d checked, %d healed, %d deactivated",
                $results['healing']['sources_checked'] ?? 0,
                $results['healing']['sources_healed'] ?? 0,
                $results['healing']['sources_deactivated'] ?? 0
            );
        }

        // Category issues
        if (!empty($results['issues_found'])) {
            $lines[] = '';
            $lines[] = "Issues found:";
            foreach ($results['issues_found'] as $issue) {
                $lines[] = "  - {$issue['category']}: {$issue['reason']} ({$issue['priority']} priority)";
            }
        }

        // Actions taken
        if (!empty($results['actions_taken'])) {
            $lines[] = '';
            $lines[] = "Actions taken:";
            foreach ($results['actions_taken'] as $action) {
                $lines[] = "  - {$action}";
            }
        }

        // Duration
        $lines[] = '';
        $lines[] = "Duration: {$results['duration_seconds']}s";

        return implode("\n", $lines);
    }
}
