<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add rag_indexed_at column to file_registry table
 *
 * This column tracks when each file was last indexed to the RAG system
 * for semantic search capabilities. Files with NULL rag_indexed_at
 * are pending indexing.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('file_registry', function (Blueprint $table) {
            $table->timestamp('rag_indexed_at')->nullable()->after('last_verified_at');
            $table->index('rag_indexed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('file_registry', function (Blueprint $table) {
            $table->dropIndex(['rag_indexed_at']);
            $table->dropColumn('rag_indexed_at');
        });
    }
};
