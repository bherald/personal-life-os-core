<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * E13 Phase 3.1: Folder tracking for smarter rescan logic
     *
     * Tracks folder scan status to:
     * - Skip folders with no changes since last scan
     * - Prioritize unscanned folders over already-scanned ones
     * - Resume interrupted scans from checkpoint
     * - Detect folder structure changes
     */
    public function up(): void
    {
        Schema::create('file_registry_folder_status', function (Blueprint $table) {
            $table->id();
            $table->text('folder_path');  // Full Nextcloud path (e.g., /Library/Unsorted/2020)
            $table->string('path_hash', 64)->unique(); // SHA256 hash of folder_path for fast lookups
            $table->integer('depth')->default(0); // Folder depth from root (for prioritization)

            // Scan tracking
            $table->timestamp('last_scanned_at')->nullable(); // When folder was last fully scanned
            $table->timestamp('scan_started_at')->nullable(); // For resuming interrupted scans
            $table->enum('scan_status', ['pending', 'scanning', 'completed', 'partial'])->default('pending');
            $table->integer('scan_progress')->default(0); // Items processed in current scan

            // Folder contents snapshot (for change detection)
            $table->integer('file_count')->default(0); // Number of files in folder
            $table->integer('subfolder_count')->default(0); // Number of subfolders
            $table->bigInteger('total_size')->default(0); // Total size of files in bytes
            $table->string('content_checksum', 64)->nullable(); // Hash of file list for change detection
            $table->timestamp('nextcloud_mtime')->nullable(); // Folder mtime from Nextcloud for quick change check

            // Results from last scan
            $table->integer('files_registered')->default(0);
            $table->integer('proposals_created')->default(0);
            $table->integer('bundles_detected')->default(0);
            $table->integer('errors')->default(0);

            // Priority and flags
            $table->integer('priority')->default(0); // Higher = scan first (0 = normal, negative = deprioritize)
            $table->boolean('is_organized')->default(false); // True for 01-Identity, 02-Financial, etc.
            $table->boolean('skip_proposals')->default(false); // True to skip creating proposals for this folder
            $table->text('notes')->nullable(); // Human notes about the folder

            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['scan_status', 'priority'], 'idx_scan_priority');
            $table->index(['last_scanned_at'], 'idx_last_scanned');
            $table->index(['is_organized'], 'idx_is_organized');
        });

        // Add index on folder_path for prefix searches (using raw SQL for TEXT column)
        DB::statement('CREATE INDEX idx_folder_path ON file_registry_folder_status (folder_path(500))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_registry_folder_status');
    }
};
