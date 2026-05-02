<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::update(
            "UPDATE review_type_registry SET approve_method = 'approveGenealogyFinding' WHERE name = 'genealogy_finding'"
        );
    }

    public function down(): void
    {
        DB::update(
            "UPDATE review_type_registry SET approve_method = NULL WHERE name = 'genealogy_finding'"
        );
    }
};
