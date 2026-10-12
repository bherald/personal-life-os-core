<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Photo calendar dates can predate the MySQL TIMESTAMP epoch.
        // Convert under the existing connection timezone to preserve displayed calendar values.
        Schema::table('file_registry', function (Blueprint $table): void {
            $table->dateTime('date_taken')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('file_registry')->whereNotNull('date_taken')
            ->whereRaw('(UNIX_TIMESTAMP(date_taken) IS NULL OR UNIX_TIMESTAMP(date_taken) < 1 OR UNIX_TIMESTAMP(date_taken) > 2147483647 OR FROM_UNIXTIME(UNIX_TIMESTAMP(date_taken)) <> date_taken)')
            ->exists()) {
            throw new RuntimeException('Photo calendar dates cannot round-trip through TIMESTAMP; retain DATETIME and roll back application code only.');
        }
        Schema::table('file_registry', function (Blueprint $table): void {
            $table->timestamp('date_taken')->nullable()->change();
        });
    }
};
