<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement('ALTER TABLE compute_instances ADD COLUMN shares_gpu_with_llm TINYINT(1) NOT NULL DEFAULT 0 AFTER success_rate');
        } catch (\Exception $e) {
            // Column already exists (fresh install ran updated N106 migration)
        }

        // The default local GPU worker shares its GPU with Ollama.
        DB::update("UPDATE compute_instances SET shares_gpu_with_llm = 1 WHERE instance_id = 'gpu_local'");
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE compute_instances DROP COLUMN shares_gpu_with_llm');
        } catch (\Exception $e) {
            // ignore
        }
    }
};
