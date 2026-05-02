<?php

namespace App\Console\Commands;

use App\Services\AdaptiveModeService;
use Illuminate\Console\Command;

class AdaptiveModeCommand extends Command
{
    protected $signature = 'adaptive:mode
        {agent? : Agent ID to analyze}
        {--recommend : Show mode recommendation for agent}
        {--task= : Task description for task-specific recommendation}
        {--history : Show adaptive mode selection history}
        {--stats : Show overall adaptive mode statistics}
        {--accuracy : Show prediction accuracy}
        {--override= : Set mode override (format: mode or mode:runs)}
        {--reason= : Reason for override}
        {--clear-override : Clear active override for agent}';

    protected $description = 'Manage adaptive workflow mode selection (S20: auto mode)';

    public function handle(AdaptiveModeService $service): int
    {
        if ($this->option('stats')) {
            return $this->showStats($service);
        }

        if ($this->option('accuracy')) {
            return $this->showAccuracy($service);
        }

        $agent = $this->argument('agent');

        if ($this->option('history')) {
            if (!$agent) {
                $this->error('Agent ID required for history. Usage: adaptive:mode {agent} --history');
                return 1;
            }
            return $this->showHistory($service, $agent);
        }

        if ($override = $this->option('override')) {
            if (!$agent) {
                $this->error('Agent ID required for override. Usage: adaptive:mode {agent} --override=hybrid:5');
                return 1;
            }
            return $this->setOverride($service, $agent, $override);
        }

        if ($this->option('clear-override')) {
            if (!$agent) {
                $this->error('Agent ID required.');
                return 1;
            }
            \Illuminate\Support\Facades\Cache::forget('adaptive_mode_override:' . $agent);
            $this->info("Override cleared for {$agent}.");
            return 0;
        }

        if ($this->option('recommend') || $agent) {
            if (!$agent) {
                $this->error('Agent ID required. Usage: adaptive:mode {agent} --recommend');
                return 1;
            }
            return $this->showRecommendation($service, $agent);
        }

        $this->error('Usage: adaptive:mode {agent} --recommend | --history | --stats | --accuracy | --override=mode:runs');
        return 1;
    }

    private function showStats(AdaptiveModeService $service): int
    {
        $agent = $this->argument('agent');
        $stats = $service->getStats($agent);

        $this->info(($agent ? "Adaptive Mode Stats: {$agent}" : "Adaptive Mode Stats: All Agents"));
        $this->line("Total selections: {$stats['total_selections']}");
        $this->newLine();

        if (!empty($stats['distribution'])) {
            $this->info('Selection Distribution:');
            $headers = ['Mode', 'Selections', 'Fallbacks', 'Avg Confidence', 'Successes', 'Failures', 'Pending'];
            $rows = [];
            foreach ($stats['distribution'] as $d) {
                $rows[] = [
                    $d->selected_mode,
                    $d->selections,
                    $d->fallbacks,
                    round((float) $d->avg_confidence, 3),
                    $d->successes,
                    $d->failures,
                    $d->pending,
                ];
            }
            $this->table($headers, $rows);
        }

        if (!empty($stats['outcome_quality'])) {
            $this->info('Outcome Quality by Mode:');
            $headers = ['Mode', 'Avg Accuracy', 'Avg Completeness', 'Avg Relevance', 'Avg Duration (s)', 'Avg Tokens'];
            $rows = [];
            foreach ($stats['outcome_quality'] as $q) {
                $rows[] = [
                    $q->selected_mode,
                    round((float) ($q->avg_accuracy ?? 0), 1),
                    round((float) ($q->avg_completeness ?? 0), 1),
                    round((float) ($q->avg_relevance ?? 0), 1),
                    round((float) ($q->avg_duration ?? 0) / 1000, 1),
                    (int) ($q->avg_tokens ?? 0),
                ];
            }
            $this->table($headers, $rows);
        }

        $pa = $stats['prediction_accuracy'];
        if ($pa['total_evaluated'] > 0) {
            $this->info("Prediction Accuracy: {$pa['optimal_picks']}/{$pa['total_evaluated']} optimal ({$pa['accuracy_pct']}%)");
        } else {
            $this->line('Prediction accuracy: No evaluated selections yet');
        }

        return 0;
    }

    private function showAccuracy(AdaptiveModeService $service): int
    {
        $agent = $this->argument('agent');
        $stats = $service->getStats($agent);
        $pa = $stats['prediction_accuracy'];

        if ($pa['total_evaluated'] === 0) {
            $this->warn('No adaptive selections with recorded outcomes yet.');
            $this->line('Selections are evaluated after execution completes and outcomes are recorded.');
            return 0;
        }

        $this->info("Prediction Accuracy" . ($agent ? " ({$agent})" : " (all agents)"));
        $this->line("  Evaluated: {$pa['total_evaluated']}");
        $this->line("  Optimal picks: {$pa['optimal_picks']}");
        $this->line("  Accuracy: {$pa['accuracy_pct']}%");

        return 0;
    }

    private function showHistory(AdaptiveModeService $service, string $agent): int
    {
        $history = $service->getSelectionHistory($agent, 20);

        if (empty($history)) {
            $this->warn("No adaptive selections found for {$agent}.");
            return 0;
        }

        $this->info("Adaptive Mode History: {$agent} (last 20)");
        $headers = ['Time', 'Task Key', 'Mode', 'Conf.', 'Fallback', 'Success', 'Duration', 'Tokens'];
        $rows = [];
        foreach ($history as $h) {
            $rows[] = [
                substr($h->created_at, 5, 14),
                $h->task_key ?? '-',
                $h->selected_mode,
                round((float) $h->confidence, 2),
                $h->was_fallback ? 'Y' : 'N',
                $h->outcome_success === null ? '...' : ($h->outcome_success ? 'OK' : 'FAIL'),
                $h->outcome_duration_ms ? round($h->outcome_duration_ms / 1000, 1) . 's' : '-',
                $h->outcome_tokens ?? '-',
            ];
        }
        $this->table($headers, $rows);

        return 0;
    }

    private function showRecommendation(AdaptiveModeService $service, string $agent): int
    {
        $task = $this->option('task');
        $taskKey = $task ? $service->classifyTask($agent, $task) : null;
        $scores = $service->scoreModes($agent, $taskKey);

        if (empty($scores)) {
            $this->warn("No benchmark data for {$agent}. Run agent:benchmark first.");
            return 0;
        }

        $this->info("Mode Recommendation: {$agent}" . ($taskKey ? " (task: {$taskKey})" : ' (all tasks)'));
        $this->newLine();

        // Sort by composite descending
        uasort($scores, fn($a, $b) => $b['composite'] <=> $a['composite']);

        $headers = ['Mode', 'Composite', 'Quality', 'Speed', 'Spec Boost', 'Accuracy', 'Completeness', 'Relevance', 'Avg Duration', 'Samples'];
        $rows = [];
        $first = true;
        foreach ($scores as $mode => $data) {
            $prefix = $first ? '>>> ' : '    ';
            $rows[] = [
                $prefix . $mode,
                $data['composite'],
                $data['quality'],
                $data['speed'],
                $data['speculative_boost'],
                $data['avg_accuracy'],
                $data['avg_completeness'],
                $data['avg_relevance'],
                round($data['avg_duration_ms'] / 1000, 1) . 's',
                $data['samples'],
            ];
            $first = false;
        }
        $this->table($headers, $rows);

        $best = array_key_first($scores);
        $this->info("Recommended: {$best}");

        return 0;
    }

    private function setOverride(AdaptiveModeService $service, string $agent, string $override): int
    {
        // Parse "mode" or "mode:runs"
        $parts = explode(':', $override);
        $mode = $parts[0];
        $runs = isset($parts[1]) ? (int) $parts[1] : 5;
        $reason = $this->option('reason') ?? 'Manual CLI override';

        $result = $service->setOverride($agent, $mode, $runs, $reason);

        if ($result['success']) {
            $this->info("Override set: {$agent} → {$mode} for next {$runs} runs");
        } else {
            $this->error($result['error']);
            return 1;
        }

        return 0;
    }
}
