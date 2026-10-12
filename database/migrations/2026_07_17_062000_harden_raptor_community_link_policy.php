<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'rag';

    private const KEY = 'raptor_community_link_runtime_policy';

    private const DESCRIPTION = 'Table-owned bounds and evidence threshold for guarded RAPTOR-to-community linking.';

    private const JOB_NAME = 'raptor_link_communities';

    private const OLD_COMMAND = 'rag:link-raptor-communities --limit=500';

    private const NEW_COMMAND = 'rag:link-raptor-communities --execute --limit=500';

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.rag_raptor_community_link_runtime_policy.v1',
            'max_limit' => 50000,
            'minimum_entity_votes' => 1,
            'policy_notes' => [
                'selection' => 'Every processing invocation must supply an explicit positive --limit no greater than max_limit.',
                'write_gate' => 'Mutation requires --execute; omitted mode and --dry-run are read-only.',
                'scheduler' => 'The scheduled_jobs command remains the owner of its per-run limit and explicit execution intent.',
                'failure_behavior' => 'Missing, malformed, or out-of-range policy stops before PostgreSQL selection or updates.',
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

        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')
                ->where('name', self::JOB_NAME)
                ->where('command', self::OLD_COMMAND)
                ->update([
                    'command' => self::NEW_COMMAND,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')
                ->where('name', self::JOB_NAME)
                ->where('command', self::NEW_COMMAND)
                ->update([
                    'command' => self::OLD_COMMAND,
                    'updated_at' => now(),
                ]);
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

    private function encodedPolicy(): string
    {
        return json_encode($this->policy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
