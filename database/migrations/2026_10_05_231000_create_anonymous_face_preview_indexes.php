<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('anonymous_face_preview_indexes')) {
            return;
        }
        Schema::create('anonymous_face_preview_indexes', function (Blueprint $table): void {
            $table->char('binding_key', 64)->primary();
            $table->text('selector_json');
            $table->char('source_sha256', 64);
            $table->char('packet_sha256', 64);
            $table->uuid('run_uuid');
            // TEXT retains the exact bytes bound to projection_sha256 across runtimes.
            $table->text('projection_json');
            $table->char('projection_sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('anonymous_face_preview_indexes') && DB::table('anonymous_face_preview_indexes')->exists()) {
            throw new RuntimeException('Retain published anonymous face evidence; restore prior code without dropping its index.');
        }
        Schema::dropIfExists('anonymous_face_preview_indexes');
    }
};
