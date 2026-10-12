<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['preimage_json', 'input_binding_json', 'backup_relative_path', 'backup_sha256'] as $column) {
            if (! Schema::hasColumn('file_registry_face_vector_refresh_runs', $column)) {
                Schema::table('file_registry_face_vector_refresh_runs', function (Blueprint $table) use ($column): void {
                    match ($column) {
                        'preimage_json', 'input_binding_json' => $table->longText($column)->nullable(),
                        'backup_sha256' => $table->char($column, 64)->nullable(),
                        default => $table->text($column)->nullable(),
                    };
                });
            }
        }
    }

    public function down(): void
    {
        // A retained preimage remains necessary for an interrupted publication.
        if (Schema::hasColumn('file_registry_face_vector_refresh_runs', 'preimage_json')
            && DB::table('file_registry_face_vector_refresh_runs')->whereNotNull('preimage_json')->exists()) {
            throw new RuntimeException('Retain protected vector refresh preimages before rolling back this migration.');
        }
        foreach (['preimage_json', 'input_binding_json', 'backup_relative_path', 'backup_sha256'] as $column) {
            if (Schema::hasColumn('file_registry_face_vector_refresh_runs', $column)) {
                Schema::table('file_registry_face_vector_refresh_runs', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
