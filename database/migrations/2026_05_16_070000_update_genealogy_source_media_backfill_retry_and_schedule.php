<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_tool_registry')) {
            DB::table('agent_tool_registry')
                ->where('name', 'source_media_backfill')
                ->update([
                    'parameters' => json_encode($this->parameters(), JSON_UNESCAPED_SLASHES),
                    'notes' => 'MCP bridge registration for source URL media backfill; skips previously blocked failures by default unless retry_blocked is requested.',
                    'updated_at' => now(),
                ]);
        }

        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'genealogy_backfill_source_media')
            ->update([
                'command' => 'genealogy:backfill-source-media --mode=sources --tree=all --since=30d --limit=25 --order=oldest --confirm-download --confirm-storage-write --nara-metadata-snapshot --json',
                'cron_expression' => '*/10 * * * *',
                'enabled' => 1,
                'run_in_background' => 1,
                'without_overlapping' => 1,
                'timeout_minutes' => 25,
                'next_run_at' => now(),
                'notes' => 'Captures URL-only genealogy_sources into tree-local FT storage in small frequent batches. Failed rows are marked source_media_backfill_blocked and skipped until retry_blocked is requested.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_tool_registry')) {
            $parameters = $this->parameters();
            unset($parameters['properties']['retry_blocked']);

            DB::table('agent_tool_registry')
                ->where('name', 'source_media_backfill')
                ->update([
                    'parameters' => json_encode($parameters, JSON_UNESCAPED_SLASHES),
                    'notes' => 'MCP bridge registration for source URL media backfill; command enforces dry-run and explicit download/storage confirmation flags.',
                    'updated_at' => now(),
                ]);
        }
    }

    private function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID whose URL-only sources should be backfilled'],
                'since' => ['type' => 'string', 'description' => 'Window to scan, e.g. 24h, 14d, all', 'default' => '14d'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum source rows per batch', 'default' => 25],
                'order' => ['type' => 'string', 'description' => 'oldest or newest', 'default' => 'oldest'],
                'source_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'confirm_download' => ['type' => 'boolean', 'default' => false],
                'confirm_storage_write' => ['type' => 'boolean', 'default' => false],
                'nara_metadata_snapshot' => ['type' => 'boolean', 'default' => true],
                'retry_blocked' => ['type' => 'boolean', 'default' => false],
                'link_sources' => ['type' => 'boolean', 'default' => true],
                'max_bytes' => ['type' => 'integer'],
            ],
            'required' => ['tree_id'],
        ];
    }
};
