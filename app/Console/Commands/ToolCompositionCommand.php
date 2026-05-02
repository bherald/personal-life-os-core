<?php

namespace App\Console\Commands;

use App\Services\ToolCompositionService;
use Illuminate\Console\Command;

class ToolCompositionCommand extends Command
{
    protected $signature = 'tool:compositions
        {--discover : Discover composition candidates from procedural memory}
        {--propose : Auto-propose discovered candidates for review}
        {--list : List all composite tools}
        {--stats : Show composition usage statistics}
        {--pending : Show pending composition proposals}
        {--enable= : Enable a composite tool by name}
        {--disable= : Disable a composite tool by name}
        {--delete= : Delete a composite tool by name}
        {--agent= : Filter to a specific agent ID}';

    protected $description = 'Manage dynamic tool compositions (S18: composite tool pipelines)';

    public function handle(ToolCompositionService $service): int
    {
        if ($this->option('stats')) {
            return $this->showStats($service);
        }

        if ($this->option('list')) {
            return $this->listCompositions($service);
        }

        if ($this->option('pending')) {
            return $this->showPending($service);
        }

        if ($this->option('discover')) {
            return $this->discover($service);
        }

        if ($this->option('enable')) {
            return $this->toggleComposition($service, $this->option('enable'), true);
        }

        if ($this->option('disable')) {
            return $this->toggleComposition($service, $this->option('disable'), false);
        }

        if ($this->option('delete')) {
            return $this->deleteComposition($service, $this->option('delete'));
        }

        // Default: show stats overview
        return $this->showStats($service);
    }

    private function discover(ToolCompositionService $service): int
    {
        $agentId = $this->option('agent');
        $this->info('Discovering composition candidates' . ($agentId ? " for agent: {$agentId}" : ' (all agents)') . '...');

        $result = $service->discoverCompositions($agentId);

        if (empty($result['candidates'])) {
            $this->line("Analyzed {$result['total_procedures_analyzed']} procedures, {$result['total_unique_sequences']} unique sequences.");
            $this->comment('No candidates meet threshold (3+ occurrences, 80%+ success rate).');
            return 0;
        }

        $this->line("Analyzed {$result['total_procedures_analyzed']} procedures, {$result['total_unique_sequences']} unique sequences.");
        $this->info("Found {$result['composition_candidates']} candidate(s):");
        $this->line('');

        $rows = [];
        foreach ($result['candidates'] as $c) {
            $status = $c['already_enabled'] ? '<fg=green>ACTIVE</>' : ($c['already_registered'] ? '<fg=yellow>PENDING</>' : '<fg=cyan>NEW</>');
            $rows[] = [
                $status,
                $c['composed_name'],
                implode(' → ', $c['tools']),
                $c['procedure_count'],
                count($c['agents']) > 1 ? implode(', ', array_slice($c['agents'], 0, 3)) : ($c['agents'][0] ?? '-'),
                round($c['avg_success_rate'] * 100) . '%',
                $c['total_uses'],
            ];
        }

        $this->table(
            ['Status', 'Name', 'Pipeline', 'Procs', 'Agent(s)', 'Success', 'Uses'],
            $rows
        );

        if ($this->option('propose')) {
            $this->line('');
            $newCandidates = array_filter($result['candidates'], fn($c) => !$c['already_registered']);
            if (empty($newCandidates)) {
                $this->comment('No new candidates to propose (all already registered).');
                return 0;
            }

            $this->info('Proposing ' . count($newCandidates) . ' new candidate(s) for review...');
            foreach ($newCandidates as $c) {
                $propResult = $service->proposeComposition($c['tools'], '', 'cli');
                if ($propResult['success']) {
                    $this->line("  <fg=green>✓</> {$propResult['composed_name']} — submitted (token: {$propResult['review_token']})");
                } else {
                    $this->line("  <fg=red>✗</> " . implode(' → ', $c['tools']) . " — {$propResult['error']}");
                }
            }
        }

        return 0;
    }

    private function listCompositions(ToolCompositionService $service): int
    {
        $compositions = $service->getCompositions();

        if (empty($compositions)) {
            $this->comment('No composite tools registered.');
            return 0;
        }

        $this->info('Composite Tools (' . count($compositions) . '):');

        $rows = [];
        foreach ($compositions as $c) {
            $status = $c['enabled'] ? '<fg=green>ON</>' : '<fg=red>OFF</>';
            $rate = $c['times_executed'] > 0
                ? round(($c['times_succeeded'] / $c['times_executed']) * 100) . '%'
                : '-';

            $rows[] = [
                $status,
                $c['name'],
                implode(' → ', $c['component_tools']),
                $c['proposed_by'] ?? '-',
                $c['times_executed'],
                $rate,
                $c['avg_duration_ms'] . 'ms',
                $c['last_executed_at'] ?? 'never',
            ];
        }

        $this->table(
            ['', 'Name', 'Pipeline', 'By', 'Execs', 'Success', 'Avg ms', 'Last Run'],
            $rows
        );

        return 0;
    }

    private function showPending(ToolCompositionService $service): int
    {
        $result = $service->pendingCompositions([]);

        if ($result['count'] === 0) {
            $this->comment('No pending composition proposals.');
            return 0;
        }

        $this->info("Pending Proposals ({$result['count']}):");

        $rows = [];
        foreach ($result['proposals'] as $p) {
            $details = $p['details'] ?? [];
            $rows[] = [
                $details['composed_name'] ?? '-',
                $details['pipeline'] ?? '-',
                $p['agent_id'] ?? '-',
                $p['token'] ?? '-',
                $p['created_at'] ?? '-',
            ];
        }

        $this->table(
            ['Name', 'Pipeline', 'Agent', 'Token', 'Created'],
            $rows
        );

        return 0;
    }

    private function showStats(ToolCompositionService $service): int
    {
        $stats = $service->compositionStats([]);

        $this->info('Tool Composition Dashboard');
        $this->line("Active: {$stats['active']} | Pending: {$stats['pending']}");

        if (!empty($stats['usage'])) {
            $this->line('');
            $this->comment('Usage Statistics:');
            $rows = [];
            foreach ($stats['usage'] as $u) {
                $total = $u['times_executed'] ?? 0;
                $rate = $total > 0 ? round(($u['times_succeeded'] / $total) * 100) . '%' : '-';
                $rows[] = [
                    $u['tool_name'],
                    $total,
                    $u['times_succeeded'] ?? 0,
                    $u['times_failed'] ?? 0,
                    $rate,
                    ($u['avg_duration_ms'] ?? 0) . 'ms',
                    $u['last_executed_at'] ?? 'never',
                ];
            }
            $this->table(
                ['Tool', 'Execs', 'OK', 'Fail', 'Rate', 'Avg ms', 'Last Run'],
                $rows
            );
        }

        return 0;
    }

    private function toggleComposition(ToolCompositionService $service, string $name, bool $enable): int
    {
        $success = $enable ? $service->enableComposition($name) : $service->disableComposition($name);

        if ($success) {
            $action = $enable ? 'enabled' : 'disabled';
            $this->info("Composite tool '{$name}' {$action}.");
        } else {
            $this->error("Composite tool '{$name}' not found.");
            return 1;
        }

        return 0;
    }

    private function deleteComposition(ToolCompositionService $service, string $name): int
    {
        if ($service->deleteComposition($name)) {
            $this->info("Composite tool '{$name}' deleted.");
        } else {
            $this->error("Composite tool '{$name}' not found.");
            return 1;
        }

        return 0;
    }
}
