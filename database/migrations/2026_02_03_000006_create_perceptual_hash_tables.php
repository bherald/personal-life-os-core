<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Perceptual Image Hashing Tables
     *
     * Stores perceptual hashes (dHash 128-bit, pHash 64-bit) for images,
     * tracks similar image pairs with hamming distance classification,
     * and provides a MySQL function for efficient 128-bit hamming distance calculation.
     */
    public function up(): void
    {
        // Perceptual hashes table
        Schema::create('file_registry_perceptual_hashes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('file_registry_id');

            // dHash (128-bit as hex string and two 64-bit integers for fast comparison)
            $table->char('dhash_hex', 32)->comment('128-bit dHash as 32 hex characters');
            $table->unsignedBigInteger('dhash_int_hi')->comment('Upper 64 bits of dHash');
            $table->unsignedBigInteger('dhash_int_lo')->comment('Lower 64 bits of dHash');

            // pHash (64-bit)
            $table->char('phash_hex', 16)->nullable()->comment('64-bit pHash as 16 hex characters');
            $table->unsignedBigInteger('phash_int')->nullable()->comment('64-bit pHash as integer');

            $table->string('algorithm_version', 10)->default('1.0');
            $table->timestamp('computed_at')->useCurrent();

            // Indexes for fast hamming distance lookups
            $table->index('dhash_int_hi');
            $table->index('dhash_int_lo');
            $table->index('phash_int');

            $table->foreign('file_registry_id')
                  ->references('id')
                  ->on('file_registry')
                  ->onDelete('cascade');

            $table->unique('file_registry_id', 'unique_file');
        });

        // Similar image pairs table
        Schema::create('file_registry_similar_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('file_id_a');
            $table->unsignedBigInteger('file_id_b');

            $table->tinyInteger('hamming_distance')->unsigned();
            $table->enum('similarity_type', ['exact', 'near_duplicate', 'similar']);
            $table->enum('algorithm_used', ['dhash', 'phash', 'combined'])->default('dhash');

            $table->enum('status', ['pending_review', 'confirmed_duplicate', 'false_positive', 'different_versions'])
                  ->default('pending_review');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('file_id_a');
            $table->index('file_id_b');
            $table->index('status');
            $table->index('hamming_distance');

            $table->foreign('file_id_a')
                  ->references('id')
                  ->on('file_registry')
                  ->onDelete('cascade');

            $table->foreign('file_id_b')
                  ->references('id')
                  ->on('file_registry')
                  ->onDelete('cascade');

            $table->unique(['file_id_a', 'file_id_b'], 'unique_pair');
        });

        // Add constraint to ensure file_id_a < file_id_b (avoid duplicate pairs)
        // MySQL 8.0.16+ supports CHECK constraints
        DB::statement('ALTER TABLE file_registry_similar_images ADD CONSTRAINT chk_ordered_pair CHECK (file_id_a < file_id_b)');

        // Create MySQL function for 128-bit hamming distance calculation
        // Note: This requires SUPER privilege or log_bin_trust_function_creators=1
        // If it fails, the PerceptualHashService will use PHP-based calculation instead
        try {
            DB::unprepared('DROP FUNCTION IF EXISTS HAMMING_DISTANCE_128');

            DB::unprepared('
                CREATE FUNCTION HAMMING_DISTANCE_128(
                    a_hi BIGINT UNSIGNED, a_lo BIGINT UNSIGNED,
                    b_hi BIGINT UNSIGNED, b_lo BIGINT UNSIGNED
                )
                RETURNS TINYINT UNSIGNED
                DETERMINISTIC NO SQL
                COMMENT "Calculate hamming distance between two 128-bit values stored as pairs of 64-bit integers"
                BEGIN
                    RETURN BIT_COUNT(a_hi ^ b_hi) + BIT_COUNT(a_lo ^ b_lo);
                END
            ');
        } catch (\Exception $e) {
            // Function creation failed (likely due to binary logging restrictions)
            // The service will use inline SQL with BIT_COUNT instead
            \Illuminate\Support\Facades\Log::warning(
                'HAMMING_DISTANCE_128 function could not be created. ' .
                'Using inline BIT_COUNT calculation instead. Error: ' . $e->getMessage()
            );
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS HAMMING_DISTANCE_128');

        Schema::dropIfExists('file_registry_similar_images');
        Schema::dropIfExists('file_registry_perceptual_hashes');
    }
};
