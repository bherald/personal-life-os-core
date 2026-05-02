<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!\Schema::hasTable('removal_requests')) {
            return;
        }
        DB::statement("ALTER TABLE removal_requests MODIFY COLUMN status ENUM('pending','submitted','awaiting_confirmation','confirmed','failed','verified_removed','reappeared','ignored') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (!\Schema::hasTable('removal_requests')) {
            return;
        }
        DB::statement("ALTER TABLE removal_requests MODIFY COLUMN status ENUM('pending','submitted','awaiting_confirmation','confirmed','failed','verified_removed','reappeared') NOT NULL DEFAULT 'pending'");
    }
};
