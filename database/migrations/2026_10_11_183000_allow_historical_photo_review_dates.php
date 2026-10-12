<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Match photo calendar storage; retain displayed dates in the existing session timezone.
        Schema::table('file_date_review_decisions', function (Blueprint $table): void {
            $table->dateTime('proposed_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('file_date_review_decisions')->whereNotNull('proposed_date')
            ->whereRaw('(UNIX_TIMESTAMP(proposed_date) IS NULL OR UNIX_TIMESTAMP(proposed_date) < 1 OR UNIX_TIMESTAMP(proposed_date) > 2147483647 OR FROM_UNIXTIME(UNIX_TIMESTAMP(proposed_date)) <> proposed_date)')
            ->exists()) {
            throw new RuntimeException('Photo review calendar dates cannot round-trip through TIMESTAMP; retain DATETIME and roll back application code only.');
        }
        Schema::table('file_date_review_decisions', function (Blueprint $table): void {
            $table->timestamp('proposed_date')->nullable()->change();
        });
    }
};
