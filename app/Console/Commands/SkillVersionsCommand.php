<?php

namespace App\Console\Commands;

use App\Services\SkillVersionService;
use App\Services\SkillLoaderService;
use Illuminate\Console\Command;

class SkillVersionsCommand extends Command
{
    protected $signature = 'skill:versions
        {skill? : Skill name (omit for all skills)}
        {--snapshot : Snapshot all current SKILL.md files into version tracking}
        {--history : Show version history for a skill}
        {--rollback= : Roll back to a specific version ID}
        {--compare= : Compare two version IDs (format: id1,id2)}
        {--stats : Show version tracking statistics}';

    protected $description = 'Manage SKILL.md version tracking — snapshot, history, rollback, compare';

    public function handle(SkillVersionService $versionService, SkillLoaderService $loaderService): int
    {
        if ($this->option('snapshot')) {
            return $this->runSnapshot($versionService);
        }

        if ($this->option('stats')) {
            return $this->showStats($versionService);
        }

        if ($this->option('rollback')) {
            return $this->runRollback($versionService);
        }

        if ($this->option('compare')) {
            return $this->runCompare($versionService);
        }

        if ($this->option('history') || $this->argument('skill')) {
            return $this->showHistory($versionService, $loaderService);
        }

        // Default: show stats
        return $this->showStats($versionService);
    }

    private function runSnapshot(SkillVersionService $versionService): int
    {
        $this->info('Snapshotting all SKILL.md files...');
        $count = $versionService->snapshotAll();
        $this->info("Snapshotted {$count} skills.");
        return self::SUCCESS;
    }

    private function showStats(SkillVersionService $versionService): int
    {
        $stats = $versionService->getStats();

        $this->info("Skill Version Statistics");
        $this->line("  Total versions tracked: {$stats['total_versions']}");
        $this->line("  Unique skills: {$stats['unique_skills']}");
        $this->line("  Active versions: {$stats['active_versions']}");
        $this->newLine();

        if (!empty($stats['per_skill'])) {
            $headers = ['Skill', 'Versions', 'Latest', 'Last Updated'];
            $rows = [];
            foreach ($stats['per_skill'] as $skill) {
                $rows[] = [
                    $skill->skill_name,
                    $skill->version_count,
                    $skill->latest_version,
                    $skill->last_updated,
                ];
            }
            $this->table($headers, $rows);
        } else {
            $this->warn('No versions tracked yet. Run: php artisan skill:versions --snapshot');
        }

        return self::SUCCESS;
    }

    private function showHistory(SkillVersionService $versionService, SkillLoaderService $loaderService): int
    {
        $skillName = $this->argument('skill');

        if (!$skillName) {
            // List all skills with their active version
            $skills = $loaderService->getSkillIndex();
            $headers = ['Skill', 'Version (frontmatter)', 'Tools', 'Schedule'];
            $rows = [];
            foreach ($skills as $skill) {
                $rows[] = [
                    $skill['name'],
                    $skill['version'],
                    count($skill['tools']),
                    $skill['schedule'] ?? '-',
                ];
            }
            $this->table($headers, $rows);
            return self::SUCCESS;
        }

        $history = $versionService->getHistory($skillName);

        if (empty($history)) {
            $this->warn("No version history for '{$skillName}'. Run: php artisan skill:versions --snapshot");
            return self::SUCCESS;
        }

        $this->info("Version history for: {$skillName}");
        $headers = ['ID', 'Version', 'Active', 'Tools', 'Phases', 'Changed By', 'Summary', 'Created'];
        $rows = [];
        foreach ($history as $v) {
            $rows[] = [
                $v->id,
                $v->version,
                $v->is_active ? '*' : '',
                $v->tools_count,
                $v->tool_phases_count,
                $v->changed_by,
                substr($v->change_summary ?? '', 0, 50),
                $v->created_at,
            ];
        }
        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    private function runRollback(SkillVersionService $versionService): int
    {
        $versionId = (int) $this->option('rollback');
        $skillName = $this->argument('skill');

        if (!$skillName) {
            $this->error('Skill name required for rollback. Usage: php artisan skill:versions ai-ops --rollback=42');
            return self::FAILURE;
        }

        $version = $versionService->getVersion($versionId);
        if (!$version) {
            $this->error("Version ID {$versionId} not found.");
            return self::FAILURE;
        }

        $this->warn("Rolling back '{$skillName}' to version {$version->version} (ID: {$versionId})");
        $this->line("  Created: {$version->created_at}");
        $this->line("  Tools: {$version->tools_count}");
        $this->line("  Change: {$version->change_summary}");

        if (!$this->confirm('Proceed with rollback?')) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $success = $versionService->rollback($skillName, $versionId);

        if ($success) {
            $this->info("Rolled back '{$skillName}' to version {$version->version}.");
        } else {
            $this->error('Rollback failed. Check logs.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function runCompare(SkillVersionService $versionService): int
    {
        $parts = explode(',', $this->option('compare'));
        if (count($parts) !== 2) {
            $this->error('Compare requires two version IDs: --compare=41,42');
            return self::FAILURE;
        }

        $diff = $versionService->compareVersions((int) $parts[0], (int) $parts[1]);

        if (!$diff) {
            $this->error('One or both version IDs not found.');
            return self::FAILURE;
        }

        $this->info("Comparing versions for: {$diff['skill']}");
        $this->line("  Version A: {$diff['version_a']['version']} (ID: {$diff['version_a']['id']}, {$diff['version_a']['created_at']})");
        $this->line("  Version B: {$diff['version_b']['version']} (ID: {$diff['version_b']['id']}, {$diff['version_b']['created_at']})");
        $this->newLine();

        $this->line("  Version changed: " . ($diff['version_changed'] ? 'Yes' : 'No'));
        $this->line("  Body changed: " . ($diff['body_changed'] ? "Yes ({$diff['body_size_a']} → {$diff['body_size_b']} chars)" : 'No'));
        $this->line("  Tools: {$diff['tools_a_count']} → {$diff['tools_b_count']}");

        if (!empty($diff['tools_added'])) {
            $this->line("  Added: " . implode(', ', $diff['tools_added']));
        }
        if (!empty($diff['tools_removed'])) {
            $this->line("  Removed: " . implode(', ', $diff['tools_removed']));
        }

        return self::SUCCESS;
    }
}
