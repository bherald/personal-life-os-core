<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // N48c: Fix invisible card text — blue background + dark text = unreadable.
        // NULL color falls through to DynamicReviewCard's default bg-ops-plum (readable).
        DB::update("UPDATE review_type_registry SET color = NULL WHERE name = 'genealogy_finding'");
    }

    public function down(): void
    {
        DB::update("UPDATE review_type_registry SET color = 'blue' WHERE name = 'genealogy_finding'");
    }
};
