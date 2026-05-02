<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_registry', function (Blueprint $table) {
            $table->timestamp('nextcloud_modified_at')->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('file_registry', function (Blueprint $table) {
            $table->dropColumn('nextcloud_modified_at');
        });
    }
};
