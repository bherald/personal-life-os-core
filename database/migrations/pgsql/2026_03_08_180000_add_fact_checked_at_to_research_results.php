<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FC-5: Add fact_checked_at tracking column to research_results.
     */
    public function up(): void
    {
        try {
            DB::connection('pgsql_rag')->statement(
                "ALTER TABLE research_results ADD COLUMN fact_checked_at TIMESTAMP NULL"
            );
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'already exists')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement(
            "ALTER TABLE research_results DROP COLUMN IF EXISTS fact_checked_at"
        );
    }
};
