<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return array<string, array{command:string, notes:string}>
     */
    private function updates(): array
    {
        return [
            'face_integrity_daily_audit' => [
                'command' => 'ops:face-integrity-audit --json --compact --limit=20',
                'notes' => 'Observe-only face identity integrity audit. Runs after nightly face sync/recluster lanes and stores aggregate unknown, mismatch, duplicate, stale bridge, and review counts in scheduled job history. Compact scheduled output omits samples. Does not run faces:triage-data or any --confirm repair path.',
            ],
            'privacy_routing_audit_report' => [
                'command' => 'ops:audit-privacy-routing --json --compact',
                'notes' => 'Observe-only compact privacy routing audit. Strict failure mode and detailed sensitive tool/provider lists are intentionally not scheduled.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        foreach ($this->updates() as $name => $values) {
            DB::update(
                'UPDATE scheduled_jobs SET command = ?, notes = ?, updated_at = ? WHERE name = ?',
                [$values['command'], $values['notes'], now(), $name]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::update(
            'UPDATE scheduled_jobs SET command = ?, updated_at = ? WHERE name = ?',
            ['ops:face-integrity-audit --json --limit=20', now(), 'face_integrity_daily_audit']
        );
        DB::update(
            'UPDATE scheduled_jobs SET command = ?, updated_at = ? WHERE name = ?',
            ['ops:audit-privacy-routing --json', now(), 'privacy_routing_audit_report']
        );
    }
};
