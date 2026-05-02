<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix agent_task scheduled jobs: command must be the skill directory name
        // (e.g. 'research-ops'), not the artisan signature (e.g. 'research:operations').
        // ScheduledJobService passes command directly to AgentLoopService::execute()
        // which looks up resources/agents/skills/{command}/SKILL.md
        $fixes = [
            'research_ops_agent' => ['research:operations', 'research-ops'],
            'research_analyst_agent' => ['research:analyst', 'research-analyst'],
            'log_analyst_agent' => ['log:analyst', 'log-analyst'],
        ];

        foreach ($fixes as $jobName => [$oldCmd, $newCmd]) {
            DB::update(
                "UPDATE scheduled_jobs SET command = ? WHERE name = ? AND command = ?",
                [$newCmd, $jobName, $oldCmd]
            );
        }
    }

    public function down(): void
    {
        $reverts = [
            'research_ops_agent' => ['research-ops', 'research:operations'],
            'research_analyst_agent' => ['research-analyst', 'research:analyst'],
            'log_analyst_agent' => ['log-analyst', 'log:analyst'],
        ];

        foreach ($reverts as $jobName => [$oldCmd, $newCmd]) {
            DB::update(
                "UPDATE scheduled_jobs SET command = ? WHERE name = ? AND command = ?",
                [$newCmd, $jobName, $oldCmd]
            );
        }
    }
};
