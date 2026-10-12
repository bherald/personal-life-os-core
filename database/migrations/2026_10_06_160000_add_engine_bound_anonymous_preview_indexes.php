<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LOOKUP_INDEX = 'anonymous_preview_asset_source_index';

    public function up(): void
    {
        if (! Schema::hasTable('anonymous_face_preview_indexes')) {
            throw new RuntimeException('Anonymous preview index table is unavailable.');
        }
        Schema::table('anonymous_face_preview_indexes', function (Blueprint $table): void {
            if (! Schema::hasColumn('anonymous_face_preview_indexes', 'asset_uuid')) {
                $table->uuid('asset_uuid')->nullable();
            }
            if (! Schema::hasColumn('anonymous_face_preview_indexes', 'detector_version')) {
                $table->char('detector_version', 64)->nullable();
            }
            if (! Schema::hasColumn('anonymous_face_preview_indexes', 'engine_provenance_json')) {
                $table->text('engine_provenance_json')->nullable();
            }
        });
        if (! Schema::hasIndex('anonymous_face_preview_indexes', self::LOOKUP_INDEX)) {
            Schema::table('anonymous_face_preview_indexes', function (Blueprint $table): void {
                $table->index(['asset_uuid', 'source_sha256'], self::LOOKUP_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('anonymous_face_preview_indexes')) {
            return;
        }
        if (DB::table('anonymous_face_preview_indexes')->whereNotNull('detector_version')->exists()) {
            throw new RuntimeException('Retain engine-bound anonymous preview evidence; restore prior code without dropping its provenance.');
        }
        Schema::table('anonymous_face_preview_indexes', function (Blueprint $table): void {
            if (Schema::hasIndex('anonymous_face_preview_indexes', self::LOOKUP_INDEX)) {
                $table->dropIndex(self::LOOKUP_INDEX);
            }
            foreach (['engine_provenance_json', 'detector_version', 'asset_uuid'] as $column) {
                if (Schema::hasColumn('anonymous_face_preview_indexes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
