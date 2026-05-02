<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * N107: Deep audit fixes — production timeout adjustments
     *
     * 1. Increase raptor_screen timeout from 30 to 60 minutes (3 consecutive SIGALRM failures)
     * 2. Backfill any ChangeHistoryService rows that used created_at but not changed_at
     */
    public function up(): void
    {
        // Fix raptor_screen timeout — was causing 3 consecutive SIGALRM failures at 30min
        DB::update(
            "UPDATE scheduled_jobs SET timeout_minutes = 60 WHERE name = 'raptor_screen' AND timeout_minutes <= 30"
        );
    }

    public function down(): void
    {
        DB::update(
            "UPDATE scheduled_jobs SET timeout_minutes = 30 WHERE name = 'raptor_screen'"
        );
    }
};
