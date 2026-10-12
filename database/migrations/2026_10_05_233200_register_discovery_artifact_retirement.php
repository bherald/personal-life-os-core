<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('file_registry', 'lifecycle_generation_key')) {
            Schema::table('file_registry', function (Blueprint $table): void {
                $table->uuid('lifecycle_generation_key')->nullable();
            });
        }
        DB::table('system_configs')->insertOrIgnore([
            'section' => 'files', 'config_key' => 'registry_discovery_retirement_policy',
            'config_value' => json_encode(config('file_registry.discovery.retirement'), JSON_THROW_ON_ERROR),
            'data_type' => 'json',
            'description' => 'Discovery artifact retirement must enqueue durably on the same database transaction; existing operator policy retained.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('file_registry', 'lifecycle_generation_key')) {
            if (DB::table('file_registry')->whereNotNull('lifecycle_generation_key')->exists()) {
                throw new RuntimeException('Pending artifact retirement custody prevents dropping its generation fence.');
            }
            Schema::table('file_registry', function (Blueprint $table): void {
                $table->dropColumn('lifecycle_generation_key');
            });
        }
        // Retain operator queue policy on rollback.
    }
};
