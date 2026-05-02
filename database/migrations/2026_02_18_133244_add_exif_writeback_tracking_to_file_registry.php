<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track which metadata has been written back to physical files
     * Physical file = source of truth
     *
     * Note: exif_written already exists for date tracking
     */
    public function up(): void
    {
        Schema::table('file_registry', function (Blueprint $table) {
            // Date writeback timestamp (exif_written boolean already exists)
            if (!Schema::hasColumn('file_registry', 'exif_date_written_at')) {
                $table->timestamp('exif_date_written_at')->nullable()->after('exif_written');
            }

            // Face writeback tracking
            if (!Schema::hasColumn('file_registry', 'exif_faces_written')) {
                $table->tinyInteger('exif_faces_written')->default(0)->after('face_scan_at');
            }
            if (!Schema::hasColumn('file_registry', 'exif_faces_written_at')) {
                $table->timestamp('exif_faces_written_at')->nullable()->after('exif_faces_written');
            }

            // Tags/keywords writeback tracking
            if (!Schema::hasColumn('file_registry', 'exif_tags_written')) {
                $table->tinyInteger('exif_tags_written')->default(0)->after('exif_faces_written_at');
            }
            if (!Schema::hasColumn('file_registry', 'exif_tags_written_at')) {
                $table->timestamp('exif_tags_written_at')->nullable()->after('exif_tags_written');
            }

            // Unified writeback timestamp for any metadata
            if (!Schema::hasColumn('file_registry', 'metadata_synced_at')) {
                $table->timestamp('metadata_synced_at')->nullable()->after('exif_tags_written_at');
            }
        });

        // Add indexes separately to avoid issues
        Schema::table('file_registry', function (Blueprint $table) {
            // Index for finding files needing writeback - use exif_written for dates
            $table->index(['exif_written', 'date_taken'], 'idx_date_writeback_pending');
            $table->index(['exif_faces_written'], 'idx_faces_writeback_pending');
            $table->index(['exif_tags_written'], 'idx_tags_writeback_pending');
        });
    }

    public function down(): void
    {
        Schema::table('file_registry', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex('idx_date_writeback_pending');
            $table->dropIndex('idx_faces_writeback_pending');
            $table->dropIndex('idx_tags_writeback_pending');
        });

        Schema::table('file_registry', function (Blueprint $table) {
            $columns = [
                'exif_date_written_at',
                'exif_faces_written',
                'exif_faces_written_at',
                'exif_tags_written',
                'exif_tags_written_at',
                'metadata_synced_at',
            ];

            foreach ($columns as $col) {
                if (Schema::hasColumn('file_registry', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
