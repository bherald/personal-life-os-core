<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The database connection to use for this migration.
     */
    protected $connection = 'pgsql_rag';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create table with standard Laravel columns
        Schema::connection('pgsql_rag')->create('rag_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 50)->index();
            $table->string('title', 500)->nullable();
            $table->text('content');
            $table->jsonb('metadata')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_type')->nullable()->index();
            $table->timestamps();
        });

        // Add vector column using raw SQL (Laravel doesn't support pgvector natively)
        DB::connection('pgsql_rag')->statement('ALTER TABLE rag_documents ADD COLUMN embedding vector(768) NOT NULL');

        // Create composite index for document_type + created_at
        DB::connection('pgsql_rag')->statement('CREATE INDEX idx_rag_document_type_created ON rag_documents(document_type, created_at DESC)');

        // Create index for source queries
        DB::connection('pgsql_rag')->statement('CREATE INDEX idx_rag_source ON rag_documents(source_id, source_type)');

        // Create GIN index for JSONB metadata
        DB::connection('pgsql_rag')->statement('CREATE INDEX idx_rag_metadata_gin ON rag_documents USING gin(metadata)');

        // Create full-text search index (for hybrid search)
        DB::connection('pgsql_rag')->statement("CREATE INDEX idx_rag_content_fts ON rag_documents USING gin(to_tsvector('english', content))");

        // Create HNSW index for vector similarity search
        // m=32: Higher than default (16) for better recall with 768-dim vectors
        // ef_construction=128: Balance between build time and recall
        DB::connection('pgsql_rag')->statement('CREATE INDEX idx_rag_embedding_hnsw ON rag_documents USING hnsw (embedding vector_cosine_ops) WITH (m = 32, ef_construction = 128)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pgsql_rag')->dropIfExists('rag_documents');
    }
};
