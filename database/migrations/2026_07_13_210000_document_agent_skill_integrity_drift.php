<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'agent_skill_drift_audit';

    public function up(): void
    {
        $this->updateJob(true);
    }

    public function down(): void
    {
        $this->updateJob(false);
    }

    private function updateJob(bool $enableIntegrityMetadata): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->first(['id', 'notes']);
        if ($row === null) {
            return;
        }

        $notes = json_decode((string) ($row->notes ?? ''), true);
        $notes = is_array($notes) ? $notes : [];
        $runtime = is_array($notes['runtime'] ?? null) ? $notes['runtime'] : [];
        $safety = is_array($notes['safety'] ?? null) ? $notes['safety'] : [];

        if ($enableIntegrityMetadata) {
            $notes['summary'] = 'Scans complete configured PLOS skill bundles, writes a content-free SHA-256 manifest, and fails only when normalized audit or content drift is detected.';
            $runtime['content_integrity'] = 'plos.agent_skill_integrity.v1';
            $runtime['support_files_included'] = true;
            $runtime['expected_digest_pin_supported'] = true;
            $safety['skill_content_emitted'] = false;
            $safety['symlinks_allowed'] = false;
            $safety['signature_presence_is_verification'] = false;
        } else {
            $notes['summary'] = 'Scans configured PLOS agent skills, writes a report artifact, and fails the scheduled run only when normalized audit drift is detected.';
            unset(
                $runtime['content_integrity'],
                $runtime['support_files_included'],
                $runtime['expected_digest_pin_supported'],
                $safety['skill_content_emitted'],
                $safety['symlinks_allowed'],
                $safety['signature_presence_is_verification'],
            );
        }

        $notes['runtime'] = $runtime;
        $notes['safety'] = $safety;

        DB::table('scheduled_jobs')
            ->where('id', $row->id)
            ->update([
                'description' => $enableIntegrityMetadata
                    ? 'Observe-only complete PLOS skill bundle audit with content integrity drift detection.'
                    : 'Observe-only installed PLOS skill audit with report drift detection.',
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES),
                'last_run_output' => null,
                'updated_at' => now(),
            ]);
    }
};
