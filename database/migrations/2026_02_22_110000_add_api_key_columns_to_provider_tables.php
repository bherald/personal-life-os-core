<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add api_key column to llm_instances (plain text — single-user self-hosted system)
        try {
            DB::statement("ALTER TABLE llm_instances ADD COLUMN api_key VARCHAR(500) DEFAULT NULL AFTER api_key_env");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        // Add api_key column to genealogy_research_providers
        try {
            DB::statement("ALTER TABLE genealogy_research_providers ADD COLUMN api_key VARCHAR(500) DEFAULT NULL AFTER api_key_env");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        // Migrate any existing .env keys into the DB columns
        $providers = DB::select("SELECT id, instance_id, api_key_env FROM llm_instances WHERE api_key_env IS NOT NULL AND api_key IS NULL");
        foreach ($providers as $p) {
            $key = env($p->api_key_env);
            if ($key) {
                DB::update("UPDATE llm_instances SET api_key = ? WHERE id = ?", [$key, $p->id]);
            }
        }
    }

    public function down(): void
    {
        try {
            DB::statement("ALTER TABLE llm_instances DROP COLUMN api_key");
        } catch (\Exception $e) {}

        try {
            DB::statement("ALTER TABLE genealogy_research_providers DROP COLUMN api_key");
        } catch (\Exception $e) {}
    }
};
