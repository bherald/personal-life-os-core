<?php

namespace App\Nodes;

use App\Services\Research\SourceOptimizationService;
use App\Services\SystemConfigService;
use Illuminate\Support\Facades\Log;

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
    private const RUNTIME_POLICY_CONFIG_KEY = 'research.source_maintenance_runtime_policy';

    private const DEFAULT_RUNTIME_POLICY = [
        'enabled' => true,
        'run_healing_default' => true,
        'run_refresh_default' => true,
        'run_discover_default' => true,
        'run_optimize_default' => true,
        'send_notification_default' => false,
    ];

    private SourceOptimizationService $optimizationService;

    /** @var array<string,mixed>|null */
    private ?array $runtimePolicy = null;

    public function execute(array $input): array
    {
        $startTime = microtime(true);
        $this->optimizationService = app(SourceOptimizationService::class);

        if (! $this->runtimePolicyBool('enabled')) {
            return $this->standardOutput(null, [
                'node' => 'ResearchMaintenanceNode',
                'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
            ], 'Research source maintenance runtime policy is disabled');
        }

        // Get config
        $runHealing = $this->configuredBool('run_healing') ?? $this->runtimePolicyBool('run_healing_default');
        $runRefresh = $this->configuredBool('run_refresh') ?? $this->runtimePolicyBool('run_refresh_default');
        $runDiscover = $this->configuredBool('run_discover') ?? $this->runtimePolicyBool('run_discover_default');
        $runOptimize = $this->configuredBool('run_optimize') ?? $this->runtimePolicyBool('run_optimize_default');
        $category = $this->getConfigValue('category', null);
        $sendNotification = $this->configuredBool('send_notification')
            ?? $this->runtimePolicyBool('send_notification_default');

        Log::info('ResearchMaintenanceNode starting', [
            'healing' => $runHealing,
            'refresh' => $runRefresh,
            'discover' => $runDiscover,
            'optimize' => $runOptimize,
            'category' => $category,
            'send_notification' => $sendNotification,
            'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
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
            'send_notification' => $sendNotification,
            'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
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
                'send_notification' => $sendNotification,
                'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
            ]);

        } catch (\Exception $e) {
            Log::error('ResearchMaintenanceNode failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->standardOutput(null, [
                'node' => 'ResearchMaintenanceNode',
                'error' => true,
                'runtime_policy_source' => 'system_configs.'.self::RUNTIME_POLICY_CONFIG_KEY,
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

    private function configuredBool(string $key): ?bool
    {
        $value = $this->getConfigValue($key);
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private function runtimePolicyBool(string $key): bool
    {
        $fallback = (bool) self::DEFAULT_RUNTIME_POLICY[$key];
        $value = $this->runtimePolicy()[$key] ?? $fallback;
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $fallback;
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimePolicy(): array
    {
        if ($this->runtimePolicy !== null) {
            return $this->runtimePolicy;
        }

        $configured = app(SystemConfigService::class)->get(self::RUNTIME_POLICY_CONFIG_KEY, self::DEFAULT_RUNTIME_POLICY);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            $configured = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($configured)) {
            $configured = [];
        }

        return $this->runtimePolicy = array_replace(self::DEFAULT_RUNTIME_POLICY, $configured);
    }
}
