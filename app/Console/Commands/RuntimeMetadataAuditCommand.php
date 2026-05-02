<?php

namespace App\Console\Commands;

use App\Services\SkillLoaderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RuntimeMetadataAuditCommand extends Command
{
    protected $signature = 'ops:runtime-metadata-audit
                            {--json : Output machine-readable JSON}
                            {--strict : Return non-zero exit code when issues are found}';

    protected $description = 'Audit skill and scheduled-job runtime metadata drift';

    private const PRIORITY_SKILLS = [
        'genealogy-assessor',
        'genealogy-researcher',
        'genealogy-records',
        'genealogy-newspapers',
        'genealogy-web',
        'genealogy-analyst',
        'research-ops',
        'system-guardian',
        'knowledge-curator',
        'log-analyst',
    ];

    private const PRIORITY_JOBS = [
        'genealogy_agent_assess',
        'genealogy_agent_research_queue',
        'genealogy_newspaper_research',
        'genealogy_research_colonial_fan',
        'research_ops_agent',
        'system_guardian_agent',
        'knowledge_curator_agent',
        'log_analyst_agent',
    ];

    public function handle(SkillLoaderService $skills): int
    {
        $report = [
            'skills' => $this->auditSkills($skills),
            'scheduled_jobs' => $this->auditScheduledJobs(),
            'generated_at' => now()->toIso8601String(),
        ];

        $issueCount = count($report['skills']['missing_runtime_role'])
            + count($report['scheduled_jobs']['missing_runtime_mode'])
            + count($report['scheduled_jobs']['missing_resource_profile'])
            + count($report['scheduled_jobs']['missing_backlog_metric']);

        $report['issue_count'] = $issueCount;

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));
        } else {
            $this->info('=== Runtime Metadata Audit ===');
            $this->line('Priority skills missing runtime_role: '.count($report['skills']['missing_runtime_role']));
            foreach ($report['skills']['missing_runtime_role'] as $skill) {
                $this->line("  - {$skill}");
            }

            $this->line('Priority jobs missing runtime_mode: '.count($report['scheduled_jobs']['missing_runtime_mode']));
            foreach ($report['scheduled_jobs']['missing_runtime_mode'] as $job) {
                $this->line("  - {$job}");
            }

            $this->line('Priority jobs missing resource_profile: '.count($report['scheduled_jobs']['missing_resource_profile']));
            foreach ($report['scheduled_jobs']['missing_resource_profile'] as $job) {
                $this->line("  - {$job}");
            }

            $this->line('Priority jobs missing backlog_metric: '.count($report['scheduled_jobs']['missing_backlog_metric']));
            foreach ($report['scheduled_jobs']['missing_backlog_metric'] as $job) {
                $this->line("  - {$job}");
            }

            $this->line("Issue count: {$issueCount}");
        }

        if ($this->option('strict') && $issueCount > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function auditSkills(SkillLoaderService $skills): array
    {
        $index = collect($skills->getSkillIndex())->keyBy('name');
        $missingRuntimeRole = [];

        foreach (self::PRIORITY_SKILLS as $skillName) {
            $skill = $index->get($skillName);
            if (! $skill || empty($skill['runtime_role'])) {
                $missingRuntimeRole[] = $skillName;
            }
        }

        return [
            'priority_skills' => self::PRIORITY_SKILLS,
            'missing_runtime_role' => $missingRuntimeRole,
        ];
    }

    private function auditScheduledJobs(): array
    {
        $rows = DB::select(
            'SELECT name, command, notes, enabled, runtime_mode, resource_profile, backlog_metric
             FROM scheduled_jobs
             WHERE name IN ('.implode(',', array_fill(0, count(self::PRIORITY_JOBS), '?')).')
             ORDER BY name',
            self::PRIORITY_JOBS
        );

        $byName = [];
        foreach ($rows as $row) {
            $byName[$row->name] = $row;
        }

        $missingRuntimeMode = [];
        $missingResourceProfile = [];
        $missingBacklogMetric = [];

        foreach (self::PRIORITY_JOBS as $jobName) {
            $row = $byName[$jobName] ?? null;
            if (! $row) {
                $missingRuntimeMode[] = $jobName;
                $missingResourceProfile[] = $jobName;
                $missingBacklogMetric[] = $jobName;

                continue;
            }

            $runtimeMode = $this->runtimeValueFromRow($row, 'runtime_mode');
            $resourceProfile = $this->runtimeValueFromRow($row, 'resource_profile');
            $backlogMetric = $this->runtimeValueFromRow($row, 'backlog_metric');

            if (! $runtimeMode) {
                $missingRuntimeMode[] = $jobName;
            }
            if (! $resourceProfile) {
                $missingResourceProfile[] = $jobName;
            }
            if (! $backlogMetric) {
                $missingBacklogMetric[] = $jobName;
            }
        }

        return [
            'priority_jobs' => self::PRIORITY_JOBS,
            'missing_runtime_mode' => $missingRuntimeMode,
            'missing_resource_profile' => $missingResourceProfile,
            'missing_backlog_metric' => $missingBacklogMetric,
        ];
    }

    private function runtimeValueFromRow(object $row, string $key): ?string
    {
        $typed = $row->{$key} ?? null;
        if (is_string($typed) && trim($typed) !== '') {
            return trim($typed);
        }

        return $this->extractRuntimeValue($row->notes ?? null, $key);
    }

    private function extractRuntimeValue(?string $notes, string $key): ?string
    {
        if (! is_string($notes) || trim($notes) === '') {
            return null;
        }

        $decoded = $this->decodeNotes($notes);
        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $runtime = $decoded['runtime'] ?? null;
        $value = is_array($runtime) ? ($runtime[$key] ?? null) : null;
        $value ??= $decoded[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function decodeNotes(string $notes): array
    {
        $decoded = json_decode($notes, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($notes, '{');
        $end = strrpos($notes, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $candidate = substr($notes, $start, $end - $start + 1);
        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : [];
    }
}
