<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // research_facts uses ON CONFLICT (fact_hash) in 3 services but had no unique constraint
        DB::connection('pgsql_rag')->statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS research_facts_fact_hash_unique ON research_facts (fact_hash)"
        );

        // graph_find_duplicates tool needs levenshtein() for fuzzy name matching
        DB::connection('pgsql_rag')->statement(
            "CREATE EXTENSION IF NOT EXISTS fuzzystrmatch"
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement(
            "DROP INDEX IF EXISTS research_facts_fact_hash_unique"
        );
    }
};
