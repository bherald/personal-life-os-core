<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::delete("DELETE FROM scheduled_jobs WHERE name = 'research_run'");
    }

    public function down(): void
    {
        // No-op. Legacy job intentionally removed from scheduler inventory.
    }
};
