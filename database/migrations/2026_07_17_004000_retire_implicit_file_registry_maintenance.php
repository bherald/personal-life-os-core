<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AUDIT_KEY = 'runtime_policy_leak_audit';

    private const JOB_NAME = 'file_registry_maintenance';

    private const LEGACY_COMMAND = 'files:registry --maintenance';

    private const RETIRED_DESCRIPTION = '[RETIRED] Duplicate implicit file cleanup replaced by the explicit table-owned missing-file cleanup job.';

    private const LIMIT_FINGERPRINT = 'e5a4c7975512b9689c4e09e5925f7607708a93f479c5c5bd73afc887642c3a48';

    private const LIMIT_OVERRIDE = [
        'classification' => 'intentional_safety_rail',
        'reason' => 'Manual scan/verify batch default; automatic file registry jobs provide explicit table-owned limits or --all.',
    ];

    /** @return array<string,mixed> */
    private function retirementNotes(): array
    {
        return [
            'summary' => 'Retired duplicate maintenance job that depended on implicit command defaults.',
            'retired_reason' => 'The explicit file_registry_missing_cleanup job runs every six hours with table-owned failure_threshold and limit metadata.',
            'replacement_job' => 'file_registry_missing_cleanup',
            'safety' => [
                'implicit_deletion_threshold_disabled' => true,
                'scheduled_cleanup_removed' => false,
                'manual_maintenance_requires_explicit_threshold' => true,
            ],
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')
                ->where('name', self::JOB_NAME)
                ->where('command', self::LEGACY_COMMAND)
                ->update([
                    'description' => self::RETIRED_DESCRIPTION,
                    'enabled' => 0,
                    'next_run_at' => null,
                    'notification_mode' => 'silent',
                    'notes' => json_encode($this->retirementNotes(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }

        $this->updateAuditOverride(true);
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')
                ->where('name', self::JOB_NAME)
                ->where('command', self::LEGACY_COMMAND)
                ->where('description', self::RETIRED_DESCRIPTION)
                ->where('notes', json_encode($this->retirementNotes(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
                ->update([
                    'description' => 'Self-healing maintenance: confirms orphaned files are deleted and removes them from active tracking',
                    'enabled' => 1,
                    'next_run_at' => null,
                    'notification_mode' => 'digest',
                    'notes' => '[ORIG_CRON: 0 2 * * 0]',
                    'updated_at' => now(),
                ]);
        }

        $this->updateAuditOverride(false);
    }

    private function updateAuditOverride(bool $add): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', self::AUDIT_KEY)
            ->first(['config_value']);
        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['classifier'] ?? null)) {
            return;
        }

        $overrides = is_array($policy['classifier']['finding_overrides'] ?? null)
            ? $policy['classifier']['finding_overrides']
            : [];
        if ($add) {
            $overrides[self::LIMIT_FINGERPRINT] = self::LIMIT_OVERRIDE;
        } elseif (($overrides[self::LIMIT_FINGERPRINT] ?? null) === self::LIMIT_OVERRIDE) {
            unset($overrides[self::LIMIT_FINGERPRINT]);
        }
        $policy['classifier']['finding_overrides'] = $overrides;

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', self::AUDIT_KEY)
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        Cache::forget('syscfg:ops.'.self::AUDIT_KEY);
    }
};
