<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Video Perceptual Hashing Tables (vHash)
     *
     * Stores keyframe-based perceptual hashes for video files.
     * Extracts frames via FFmpeg, generates pHash/dHash per frame,
     * combines into video fingerprint for duplicate detection.
     */
    public function up(): void
    {
        // Video hashes table - stores keyframe-based video fingerprints
        Schema::create('file_registry_video_hashes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('file_registry_id');

            // Video metadata
            $table->integer('duration_seconds')->unsigned()->nullable()->comment('Video duration in seconds');
            $table->smallInteger('keyframe_count')->unsigned()->default(0)->comment('Number of extracted keyframes');

            // Keyframe hashes stored as JSON array
            // Format: [{timestamp: float, phash: string, dhash: string}, ...]
            $table->json('keyframe_hashes')->nullable()->comment('Array of {timestamp, phash, dhash} for each keyframe');

            // Combined fingerprint (aggregated from keyframe hashes)
            $table->char('combined_hash', 128)->nullable()->comment('Aggregated video fingerprint');

            // Hash algorithm metadata
            $table->string('hash_algorithm', 20)->default('phash')->comment('Primary algorithm: phash, dhash, or combined');
            $table->string('extraction_method', 20)->default('interval')->comment('interval, scene_change, or keyframe');
            $table->tinyInteger('extraction_interval')->unsigned()->default(10)->comment('Seconds between frames if interval method');

            // Video technical metadata
            $table->smallInteger('width')->unsigned()->nullable();
            $table->smallInteger('height')->unsigned()->nullable();
            $table->string('codec', 32)->nullable();
            $table->decimal('fps', 6, 2)->nullable();

            $table->timestamps();

            // Indexes
            $table->index('combined_hash');
            $table->index('duration_seconds');
            $table->index('keyframe_count');

            $table->foreign('file_registry_id')
                  ->references('id')
                  ->on('file_registry')
                  ->onDelete('cascade');

            $table->unique('file_registry_id', 'unique_video_file');
        });

        // Similar videos table - tracks video similarity pairs
        Schema::create('file_registry_similar_videos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('video_hash_id_1');
            $table->unsignedBigInteger('video_hash_id_2');

            // Similarity metrics
            $table->decimal('similarity_score', 5, 4)->comment('0.0000 to 1.0000');
            $table->smallInteger('matched_keyframes')->unsigned()->default(0)->comment('Number of keyframes with similarity > threshold');
            $table->tinyInteger('avg_hamming_distance')->unsigned()->nullable()->comment('Average hamming distance across matched frames');

            // Review status
            $table->enum('status', ['pending_review', 'confirmed_duplicate', 'false_positive', 'different_versions'])
                  ->default('pending_review');
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('video_hash_id_1');
            $table->index('video_hash_id_2');
            $table->index('similarity_score');
            $table->index('status');

            $table->foreign('video_hash_id_1')
                  ->references('id')
                  ->on('file_registry_video_hashes')
                  ->onDelete('cascade');

            $table->foreign('video_hash_id_2')
                  ->references('id')
                  ->on('file_registry_video_hashes')
                  ->onDelete('cascade');

            $table->unique(['video_hash_id_1', 'video_hash_id_2'], 'unique_video_pair');
        });

        // Add constraint to ensure video_hash_id_1 < video_hash_id_2 (avoid duplicate pairs)
        DB::statement('ALTER TABLE file_registry_similar_videos ADD CONSTRAINT chk_video_ordered_pair CHECK (video_hash_id_1 < video_hash_id_2)');
    }

    public function down(): void
    {
        Schema::dropIfExists('file_registry_similar_videos');
        Schema::dropIfExists('file_registry_video_hashes');
    }
};
