<?php

use Cron\CronExpression;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'files';

    private const KEY = 'scan_date_runtime_policy';

    private const DESCRIPTION = 'Table-owned bounds for resumable scan detection and operator-reviewed AI date suggestions.';

    private const JOB_NAME = 'scan_date_fix';

    private const OLD_COMMAND = 'files:date-fix-scans --reprocess --use-ai --limit=100';

    private const NEW_COMMAND = 'files:date-fix-scans --execute --reprocess --use-ai --limit=100 --json --compact';

    private const OLD_CRON = '30 1 * * 3';

    private const NEW_CRON = '30 1 * * *';

    private const MARKER = '2026_07_17_scan_date_runtime_policy';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.files_scan_date_runtime_policy.v1',
            'preview_default_limit' => 20,
            'max_limit' => 5000,
            'runtime_budget_seconds' => 1500,
            'per_item_reserve_seconds' => 180,
            'pending_review_soft_cap' => 250,
            'policy_notes' => [
                'write_gate' => 'Catalog mutation requires --execute; omitted mode and --dry-run are read-only.',
                'file_metadata' => 'This producer never writes XMP, IPTC, or EXIF metadata to files.',
                'review_gate' => 'AI estimates remain pending until a purpose-built Review Hub decision.',
                'checkpointing' => 'Stop before the outer scheduler timeout and resume from committed catalog rows.',
            ],
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            $exists = DB::table('system_configs')
                ->where('section', self::SECTION)
                ->where('config_key', self::KEY)
                ->exists();
            if (! $exists) {
                DB::table('system_configs')->insert([
                    'section' => self::SECTION,
                    'config_key' => self::KEY,
                    'config_value' => $this->encodedPolicy(),
                    'data_type' => 'json',
                    'description' => self::DESCRIPTION,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
        }

        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->where('command', self::OLD_COMMAND)
            ->where('cron_expression', self::OLD_CRON)
            ->first(['id', 'notes']);
        if ($row === null) {
            return;
        }

        $notes = json_decode((string) ($row->notes ?? ''), true);
        $notes = is_array($notes) ? $notes : [];
        $notes['_migration_extensions'][self::MARKER] = [
            'previous' => [
                'scan_date_policy_source' => $notes['scan_date_policy_source'] ?? null,
                'scan_date_safety' => $notes['scan_date_safety'] ?? null,
            ],
        ];
        $notes['scan_date_policy_source'] = 'system_configs.files.scan_date_runtime_policy';
        $notes['scan_date_safety'] = [
            'explicit_execute' => true,
            'review_backlog_soft_cap' => true,
            'graceful_runtime_checkpoint' => true,
            'automatic_file_metadata_write' => false,
        ];

        DB::table('scheduled_jobs')->where('id', $row->id)->update([
            'command' => self::NEW_COMMAND,
            'cron_expression' => self::NEW_CRON,
            'next_run_at' => $this->nextRun(self::NEW_CRON),
            'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            $row = DB::table('scheduled_jobs')
                ->where('name', self::JOB_NAME)
                ->where('command', self::NEW_COMMAND)
                ->where('cron_expression', self::NEW_CRON)
                ->first(['id', 'notes']);
            if ($row !== null) {
                $notes = json_decode((string) ($row->notes ?? ''), true);
                $notes = is_array($notes) ? $notes : [];
                $previous = $notes['_migration_extensions'][self::MARKER]['previous'] ?? [];
                if (($notes['scan_date_policy_source'] ?? null) === 'system_configs.files.scan_date_runtime_policy') {
                    $this->restoreValue($notes, 'scan_date_policy_source', $previous['scan_date_policy_source'] ?? null);
                }
                if (($notes['scan_date_safety'] ?? null) === [
                    'explicit_execute' => true,
                    'review_backlog_soft_cap' => true,
                    'graceful_runtime_checkpoint' => true,
                    'automatic_file_metadata_write' => false,
                ]) {
                    $this->restoreValue($notes, 'scan_date_safety', $previous['scan_date_safety'] ?? null);
                }
                unset($notes['_migration_extensions'][self::MARKER]);
                if (($notes['_migration_extensions'] ?? []) === []) {
                    unset($notes['_migration_extensions']);
                }

                DB::table('scheduled_jobs')->where('id', $row->id)->update([
                    'command' => self::OLD_COMMAND,
                    'cron_expression' => self::OLD_CRON,
                    'next_run_at' => $this->nextRun(self::OLD_CRON),
                    'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            }
        }

        if (! Schema::hasTable('system_configs')) {
            return;
        }
        DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->where('config_value', $this->encodedPolicy())
            ->where('description', self::DESCRIPTION)
            ->delete();
        Cache::forget('syscfg:'.self::SECTION.'.'.self::KEY);
    }

    private function restoreValue(array &$notes, string $key, mixed $previous): void
    {
        if ($previous === null) {
            unset($notes[$key]);

            return;
        }
        $notes[$key] = $previous;
    }

    private function nextRun(string $cron): string
    {
        return (new CronExpression($cron))->getNextRunDate()->format('Y-m-d H:i:s');
    }

    private function encodedPolicy(): string
    {
        return json_encode($this->policy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
