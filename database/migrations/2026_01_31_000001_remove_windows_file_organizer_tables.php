<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove Windows File Organizer tables
 *
 * This migration drops the Windows-to-Nextcloud sync and file action queue tables
 * as part of the conversion from File Organizer to read-only File Catalog.
 *
 * Tables being dropped:
 * - windows_file_index: Windows file indexing
 * - windows_file_actions: Action queue for file moves (pending/approved/executed)
 * - windows_file_config: Configuration settings
 * - windows_folder_mappings: Source folder -> destination mappings
 * - windows_folder_rules: Folder exclusion/action rules
 * - windows_bundle_types: Bundle detection patterns
 * - windows_document_types: Document type categorization rules
 *
 * Tables being kept:
 * - file_registry: Core file registration
 * - file_registry_duplicates: Duplicate file tracking
 * - file_registry_path_history: Path change history
 * - file_registry_sync_runs: Scan run history
 * - file_registry_folder_status: Folder scan status
 * - folder_semantics: Folder meaning context for RAG
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop in dependency order (child tables with FKs first, then referenced tables)
        Schema::dropIfExists('windows_file_actions');  // References windows_file_index
        Schema::dropIfExists('windows_file_index');
        Schema::dropIfExists('windows_file_config');
        Schema::dropIfExists('windows_folder_mappings');
        Schema::dropIfExists('windows_folder_rules');
        Schema::dropIfExists('windows_bundle_types');
        Schema::dropIfExists('windows_document_types');
    }

    /**
     * Reverse the migrations.
     *
     * Note: This recreates the table structures but not the data.
     * A full rollback would require a backup restore.
     */
    public function down(): void
    {
        // Recreate windows_file_config
        Schema::create('windows_file_config', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50);
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['category', 'key']);
        });

        // Recreate windows_folder_rules
        Schema::create('windows_folder_rules', function (Blueprint $table) {
            $table->id();
            $table->string('folder_path', 500);
            $table->string('rule_type', 50); // exclude, include, action
            $table->string('action', 50)->nullable();
            $table->string('destination', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index('folder_path');
            $table->index(['is_active', 'priority']);
        });

        // Recreate windows_folder_mappings
        Schema::create('windows_folder_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('source_folder', 500);
            $table->string('destination_folder', 500);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('source_folder');
        });

        // Recreate windows_bundle_types
        Schema::create('windows_bundle_types', function (Blueprint $table) {
            $table->id();
            $table->string('type_key', 50)->unique();
            $table->string('display_name', 100);
            $table->text('description')->nullable();
            $table->json('detection_markers'); // File/folder patterns to detect this bundle type
            $table->string('default_destination', 500)->nullable();
            $table->string('default_action', 50)->default('zip'); // zip, move, rag
            $table->string('default_storage', 50)->default('none'); // none, rag, joplin
            $table->boolean('is_active')->default(true);
            $table->string('created_by', 50)->default('system'); // system, ai, human
            $table->timestamps();
        });

        // Recreate windows_document_types
        Schema::create('windows_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('type_key', 50)->unique();
            $table->string('display_name', 100);
            $table->string('category', 50)->nullable();
            $table->json('file_patterns'); // Extensions and name patterns
            $table->string('nextcloud_folder', 500)->nullable();
            $table->string('default_storage', 50)->default('none');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Recreate windows_file_index
        Schema::create('windows_file_index', function (Blueprint $table) {
            $table->id();
            $table->string('windows_path', 1000);
            $table->string('path_hash', 64)->unique();
            $table->string('filename', 500);
            $table->string('extension', 50)->nullable();
            $table->bigInteger('file_size')->default(0);
            $table->timestamp('file_modified_at')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('status', 50)->default('pending');
            $table->string('nextcloud_path', 1000)->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index('extension');
        });

        // Recreate windows_file_actions
        Schema::create('windows_file_actions', function (Blueprint $table) {
            $table->id();
            $table->string('file_path', 1000);
            $table->boolean('is_bundle')->default(false);
            $table->string('bundle_type', 50)->nullable();
            $table->string('bundle_action', 50)->nullable();
            $table->json('bundle_analysis')->nullable();
            $table->boolean('delete_after_zip')->default(true);
            $table->string('action_type', 50)->default('move');
            $table->string('recommended_destination', 500)->nullable();
            $table->string('recommended_storage', 50)->default('none');
            $table->string('document_type', 50)->nullable();
            $table->string('status', 50)->default('pending');
            $table->text('reason')->nullable();
            $table->json('ai_reasoning')->nullable();
            $table->json('photo_analysis')->nullable();
            $table->json('document_analysis')->nullable();
            $table->json('media_analysis')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->text('execution_error')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index('is_bundle');
            $table->index(['status', 'is_bundle']);
        });
    }
};
