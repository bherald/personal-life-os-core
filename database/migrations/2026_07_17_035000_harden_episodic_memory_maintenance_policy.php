<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'agents';

    private const KEY = 'episodic_memory_maintenance_runtime_policy';

    private const DESCRIPTION = 'Table-owned bounds for episodic memory archive, backfill, and archived-embedding prune maintenance.';

    private const JOB_NAME = 'episodic_memory_archive';

    private const OLD_COMMAND = 'episodic:memory --archive';

    private const NEW_COMMAND = 'episodic:memory --archive --limit=500 --confirm --json --compact';

    private const MARKER = '2026_07_17_035000_harden_episodic_memory_maintenance_policy';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.episodic_memory_maintenance_runtime_policy.v1',
            'archive' => [
                'retention_days' => 90,
                'importance_below' => 0.6,
                'preview_default_limit' => 50,
                'max_limit' => 5000,
            ],
            'backfill' => [
                'default_days' => 30,
                'max_days' => 3650,
                'preview_default_limit' => 50,
                'max_limit' => 500,
            ],
            'prune' => [
                'preview_default_limit' => 50,
                'max_limit' => 5000,
                'scan_batch' => 1000,
                'scan_cap' => 100000,
                'delete_batch' => 500,
            ],
            'policy_notes' => [
                'write_scope' => 'Archive, backfill, and prune writes require --confirm plus an explicit positive --limit within the mode maximum.',
                'preview' => 'Dry-runs may omit --limit and use the table-owned preview default without writing.',
                'archive' => 'Archive is oldest eligible first, soft-archives summaries, and removes only their pgvector embeddings.',
                'failure_behavior' => 'Missing, malformed, inconsistent, or out-of-range policy stops maintenance before selecting or changing rows.',
                'prompt_integrity' => 'Backfill preserves source detail and uses mechanical summaries; prompt compression and precompaction remain disabled.',
            ],
        ];
    }

    public function up(): void
    {
        $this->seedPolicy();
        $this->hardenArchiveSchedule();
    }

    public function down(): void
    {
        $this->restoreArchiveSchedule();
        $this->removeOwnedPolicy();
    }

    private function seedPolicy(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

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

    private function hardenArchiveSchedule(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->where('command', self::OLD_COMMAND)
            ->first(['id', 'notes']);
        if ($row === null) {
            return;
        }

        $notes = $this->decodedNotes($row->notes ?? null);
        if ($notes === null) {
            return;
        }

        $notes['runtime'] = is_array($notes['runtime'] ?? null) ? $notes['runtime'] : [];
        $owned = [];
        foreach ($this->archiveRuntimeNotes() as $key => $value) {
            if (! array_key_exists($key, $notes['runtime'])) {
                $notes['runtime'][$key] = $value;
                $owned[$key] = $value;
            }
        }
        $notes['_migration_extensions'] = is_array($notes['_migration_extensions'] ?? null)
            ? $notes['_migration_extensions']
            : [];
        $notes['_migration_extensions'][self::MARKER] = ['runtime' => $owned];

        DB::table('scheduled_jobs')->where('id', $row->id)->update([
            'command' => self::NEW_COMMAND,
            'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function restoreArchiveSchedule(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->where('command', self::NEW_COMMAND)
            ->first(['id', 'notes']);
        if ($row === null) {
            return;
        }

        $notes = $this->decodedNotes($row->notes ?? null);
        if ($notes === null) {
            return;
        }
        $owned = $notes['_migration_extensions'][self::MARKER]['runtime'] ?? [];
        if (is_array($owned)) {
            foreach ($owned as $key => $value) {
                if (($notes['runtime'][$key] ?? null) === $value) {
                    unset($notes['runtime'][$key]);
                }
            }
        }
        unset($notes['_migration_extensions'][self::MARKER]);
        if (($notes['_migration_extensions'] ?? null) === []) {
            unset($notes['_migration_extensions']);
        }
        if (($notes['runtime'] ?? null) === []) {
            unset($notes['runtime']);
        }

        DB::table('scheduled_jobs')->where('id', $row->id)->update([
            'command' => self::OLD_COMMAND,
            'notes' => $notes === [] ? null : json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function removeOwnedPolicy(): void
    {
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

    /** @return array<string,mixed>|null */
    private function decodedNotes(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<string,mixed> */
    private function archiveRuntimeNotes(): array
    {
        return [
            'selection_policy' => 'oldest_eligible_first',
            'archive_limit' => 500,
            'maintenance_policy_source' => 'system_configs.agents.episodic_memory_maintenance_runtime_policy',
            'write_gate' => 'explicit_limit_plus_confirm',
        ];
    }

    private function encodedPolicy(): string
    {
        return json_encode(
            $this->policy(),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }
};
