<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_registry', function (Blueprint $table) {
            // Core date field - the actual date the photo was taken
            $table->timestamp('date_taken')->nullable()->after('ai_analysis_version');

            // Source of the date (for audit trail and confidence)
            $table->enum('date_taken_source', [
                'exif_original',      // DateTimeOriginal from EXIF
                'exif_digitized',     // DateTimeDigitized from EXIF
                'exif_modified',      // DateTime (modify date) from EXIF
                'path_extracted',     // Extracted from folder path
                'filename_extracted', // Extracted from filename
                'ai_estimated',       // AI visual analysis estimate
                'user_manual',        // User manually set
                'file_modified',      // File system modified date (fallback)
            ])->nullable()->after('date_taken');

            // Confidence score (0.0 - 1.0)
            $table->decimal('date_taken_confidence', 3, 2)->nullable()->after('date_taken_source');

            // AI reasoning for estimated dates
            $table->text('date_taken_reasoning')->nullable()->after('date_taken_confidence');

            // Track when we processed date extraction
            $table->timestamp('date_extracted_at')->nullable()->after('date_taken_reasoning');

            // Whether EXIF was written back to file
            $table->boolean('exif_written')->default(false)->after('date_extracted_at');

            // Index for efficient queries
            $table->index('date_taken');
            $table->index('date_taken_source');
            $table->index('date_extracted_at');
        });
    }

    public function down(): void
    {
        Schema::table('file_registry', function (Blueprint $table) {
            $table->dropIndex(['date_taken']);
            $table->dropIndex(['date_taken_source']);
            $table->dropIndex(['date_extracted_at']);
            $table->dropColumn([
                'date_taken',
                'date_taken_source',
                'date_taken_confidence',
                'date_taken_reasoning',
                'date_extracted_at',
                'exif_written',
            ]);
        });
    }
};
