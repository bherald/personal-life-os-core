<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'whiteroom_monitor_due_report';

    public function up(): void
    {
        $this->updateMetadata(true);
    }

    public function down(): void
    {
        $this->updateMetadata(false);
    }

    private function updateMetadata(bool $expanded): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->first(['notes']);
        if (! $row) {
            return;
        }

        $notes = is_string($row->notes ?? null) ? json_decode((string) $row->notes, true) : [];
        $notes = is_array($notes) ? $notes : [];
        $notes['runtime'] = is_array($notes['runtime'] ?? null) ? $notes['runtime'] : [];
        $notes['safety'] = is_array($notes['safety'] ?? null) ? $notes['safety'] : [];

        if ($expanded) {
            $description = 'Daily compact due-source report for the upstream repository and research monitor.';
            $notes['summary'] = 'Observe-only due-source report for repository, paper, and metadata-standard monitoring.';
            $notes['policy_source'] = [
                'config.whiteroom_projects.projects',
                'config.whiteroom_projects.monitor_projects',
                'config.whiteroom_projects.research_sources',
            ];
            $notes['runtime']['source_types'] = ['repository', 'paper', 'standard'];
            unset($notes['safety']['source_reuse_approved']);
            $notes['safety']['source_reuse_requires_legal_basis'] = true;
        } else {
            $description = 'Daily compact due-item report for the whiteroom project monitor registry.';
            $notes['summary'] = 'Observe-only due-item report for external project watchlist monitoring.';
            $notes['policy_source'] = 'config.whiteroom_projects.monitor_projects';
            unset($notes['runtime']['source_types'], $notes['safety']['source_reuse_requires_legal_basis']);
            $notes['safety']['source_reuse_approved'] = false;
        }

        DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->update([
                'description' => $description,
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
