<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        DB::connection($this->connection)->statement('
            ALTER TABLE knowledge_graph
            DROP CONSTRAINT IF EXISTS knowledge_graph_source_document_id_fkey
        ');
        DB::connection($this->connection)->statement('
            ALTER TABLE knowledge_graph
            ADD CONSTRAINT knowledge_graph_source_document_id_fkey
            FOREIGN KEY (source_document_id)
            REFERENCES rag_documents(id)
            ON DELETE RESTRICT
        ');
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('
            ALTER TABLE knowledge_graph
            DROP CONSTRAINT IF EXISTS knowledge_graph_source_document_id_fkey
        ');
        DB::connection($this->connection)->statement('
            ALTER TABLE knowledge_graph
            ADD CONSTRAINT knowledge_graph_source_document_id_fkey
            FOREIGN KEY (source_document_id)
            REFERENCES rag_documents(id)
            ON DELETE SET NULL
        ');
    }
};
