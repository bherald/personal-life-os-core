<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * ops:commit-ratio — Analyze fix:feature commit ratio with regression detection.
 *
 * Categorizes commits by prefix convention:
 *   feat:  — planned new feature
 *   fix:   — regression fix (caused by recent feat)
 *   maint: — pre-existing issue / legacy cleanup
 *   docs:  — documentation only
 *   chore: — tooling, config, CI
 *
 * A "fix:" is classified as a regression if it appears within --proximity commits
 * of a "feat:" commit. Otherwise it's reclassified as legacy (same as maint:).
 *
 * Usage:
 *   php artisan ops:commit-ratio                  # Last 20 commits
 *   php artisan ops:commit-ratio --window=50      # Last 50 commits
 *   php artisan ops:commit-ratio --since=2026-03-15
 *   php artisan ops:commit-ratio --json           # Machine-readable
 */
class CommitRatioCommand extends Command
{
    protected $signature = 'ops:commit-ratio
                            {--window=20 : Number of recent commits to analyze}
                            {--since= : Analyze commits since date (YYYY-MM-DD), overrides --window}
                            {--proximity=2 : Max distance between feat and fix to count as regression}
                            {--json : Output results as JSON}';

    protected $description = 'Analyze commit ratio — regression vs legacy fixes vs features';

    public function handle(): int
    {
        $startTime = microtime(true);
        $since = $this->option('since');
        $window = (int) $this->option('window');
        $proximity = (int) $this->option('proximity');
        $json = (bool) $this->option('json');

        // Fetch commits
        $args = ['git', 'log', '--oneline'];
        if ($since) {
            $args[] = '--since=' . $since;
        } else {
            $args[] = '-' . $window;
        }

        $result = Process::path(base_path())->run($args);
        $output = preg_split('/\r?\n/', trim($result->output())) ?: [];
        $exitCode = $result->exitCode();

        if ($exitCode !== 0 || empty($output)) {
            if ($json) {
                $this->line(json_encode(['error' => 'Could not read git log'], JSON_PRETTY_PRINT));
            } else {
                $this->error('Could not read git log.');
            }
            return Command::FAILURE;
        }

        // Parse commits into categories
        $commits = [];
        foreach ($output as $line) {
            // Format: "abc1234 type: message" or "abc1234 message"
            if (!preg_match('/^([a-f0-9]+)\s+(.*)$/', $line, $m)) {
                continue;
            }

            $hash = $m[1];
            $message = $m[2];
            $type = $this->classifyCommit($message);
            $commits[] = [
                'hash' => $hash,
                'message' => $message,
                'type' => $type,
            ];
        }

        // Find feat positions for regression detection
        $featPositions = [];
        foreach ($commits as $i => $c) {
            if ($c['type'] === 'feat') {
                $featPositions[] = $i;
            }
        }

        // Classify fix: commits as regression or legacy based on proximity to feat:
        $categories = [
            'feat' => 0,
            'regression' => 0,
            'legacy' => 0,
            'docs' => 0,
            'chore' => 0,
        ];
        $regressionCommits = [];
        $legacyCommits = [];
        $featCommits = [];

        foreach ($commits as $i => $c) {
            switch ($c['type']) {
                case 'feat':
                    $categories['feat']++;
                    $featCommits[] = $c;
                    break;

                case 'fix':
                    // Check if this fix comes AFTER a feat within proximity
                    // (git log is newest-first, so lower index = more recent)
                    // A fix at position 3 is a regression of feat at position 4 or 5
                    // (feat was committed earlier, fix came after)
                    $isRegression = false;
                    foreach ($featPositions as $fp) {
                        if ($fp > $i && ($fp - $i) <= $proximity) {
                            $isRegression = true;
                            break;
                        }
                    }
                    if ($isRegression) {
                        $categories['regression']++;
                        $regressionCommits[] = $c;
                    } else {
                        $categories['legacy']++;
                        $legacyCommits[] = $c;
                    }
                    break;

                case 'maint':
                    $categories['legacy']++;
                    $legacyCommits[] = $c;
                    break;

                case 'docs':
                    $categories['docs']++;
                    break;

                case 'chore':
                    $categories['chore']++;
                    break;
            }
        }

        // Calculate ratios
        $totalCommits = count($commits);
        $feats = $categories['feat'];
        $regressions = $categories['regression'];
        $legacy = $categories['legacy'];

        $regressionRatio = $feats > 0
            ? round($regressions / $feats, 2)
            : ($regressions > 0 ? 999.0 : 0.0);

        $legacyPct = $totalCommits > 0
            ? round(($legacy / $totalCommits) * 100, 1)
            : 0.0;

        // Thresholds
        $regressionThreshold = 0.5; // > 0.5 regressions per feature = warning
        $legacyThreshold = 50.0;    // > 50% legacy = capacity warning

        $regressionStatus = $regressionRatio <= $regressionThreshold ? 'PASS' : 'WARN';
        $legacyStatus = $legacyPct <= $legacyThreshold ? 'OK' : 'WARN';

        $duration = round(microtime(true) - $startTime, 1);

        if ($json) {
            $this->line(json_encode([
                'commits_analyzed' => $totalCommits,
                'window' => $since ? "since {$since}" : "last {$window}",
                'categories' => $categories,
                'regression_ratio' => $regressionRatio,
                'regression_status' => $regressionStatus,
                'regression_threshold' => $regressionThreshold,
                'legacy_pct' => $legacyPct,
                'legacy_status' => $legacyStatus,
                'legacy_threshold' => $legacyThreshold,
                'regressions' => array_map(fn($c) => $c['hash'] . ' ' . $c['message'], $regressionCommits),
                'duration_s' => $duration,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->info("=== Commit Ratio Analysis ===\n");

            $rangeLabel = $since ? "since {$since}" : "last {$window} commits";
            $this->line("  Analyzed: {$totalCommits} commits ({$rangeLabel})");
            $this->newLine();

            $this->line("  [Categories]");
            $this->line("    feat:       {$categories['feat']}");
            $this->line("    regression: {$categories['regression']}  (fix: within {$proximity} commits of feat:)");
            $this->line("    legacy:     {$categories['legacy']}  (maint: + distant fix:)");
            $this->line("    docs:       {$categories['docs']}");
            $this->line("    chore:      {$categories['chore']}");
            $this->newLine();

            // Regression ratio
            $regColor = $regressionStatus === 'PASS' ? 'green' : 'yellow';
            $this->line("  [Regression Ratio]");
            $this->line("    <fg={$regColor}>{$regressionRatio}:1</> (threshold: {$regressionThreshold}:1) — {$regressionStatus}");
            if (!empty($regressionCommits)) {
                foreach ($regressionCommits as $c) {
                    $this->line("      {$c['hash']} {$c['message']}");
                }
            }
            $this->newLine();

            // Legacy budget
            $legColor = $legacyStatus === 'OK' ? 'green' : 'yellow';
            $this->line("  [Legacy Fix Budget]");
            $this->line("    <fg={$legColor}>{$legacyPct}%</> of commits are legacy fixes (threshold: {$legacyThreshold}%)");
            if ($legacyStatus === 'WARN') {
                $this->line("    <fg=yellow>Consider scheduling a dedicated maintenance session.</>");
            }
            $this->newLine();

            $this->line("--- Summary ---");
            if ($regressionStatus === 'PASS') {
                $this->info("Regression ratio healthy. New features shipping clean. ({$duration}s)");
            } else {
                $this->warn("Regression ratio elevated — investigate recent feature quality. ({$duration}s)");
            }
        }

        $this->line("[ITEMS_PROCESSED:{$totalCommits}]");

        return $regressionStatus === 'WARN' ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Classify a commit message into a category.
     */
    private function classifyCommit(string $message): string
    {
        // Exact prefix match (conventional commits style)
        if (preg_match('/^feat[\(:]/', $message)) {
            return 'feat';
        }
        if (preg_match('/^fix[\(:]/', $message)) {
            return 'fix';
        }
        if (preg_match('/^maint[\(:]/', $message)) {
            return 'maint';
        }
        if (preg_match('/^docs[\(:]/', $message)) {
            return 'docs';
        }
        if (preg_match('/^chore[\(:]/', $message)) {
            return 'chore';
        }

        // Fallback heuristics for non-prefixed commits
        $lower = strtolower($message);

        // Implementation commits (DI-1, SC-3, D1+D2, N## pattern) → feat
        if (preg_match('/^(DI-|SC-|D\d|N\d{2,3})/i', $message)) {
            return 'feat';
        }

        // Sync/build artifacts → chore
        if (str_starts_with($lower, 'sync ') || str_starts_with($lower, 'build')) {
            return 'chore';
        }

        // Default: chore (uncategorized)
        return 'chore';
    }
}
