<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add rag_indexed_at column to domain tables for RAG batch indexing tracking
 */
return new class extends Migration
{
    /**
     * MySQL tables to add rag_indexed_at column
     */
    private array $mysqlTables = [
        'genealogy_persons',
        'email_threads',
        'youtube_transcripts',
    ];

    /**
     * PostgreSQL tables to add rag_indexed_at column
     */
    private array $pgsqlTables = [
        'research_results',
    ];

    public function up(): void
    {
        // MySQL tables
        foreach ($this->mysqlTables as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'rag_indexed_at')) {
                Schema::table($tableName, function (Blueprint $blueprint) {
                    $blueprint->timestamp('rag_indexed_at')->nullable();
                    $blueprint->index('rag_indexed_at');
                });
            }
        }

        // PostgreSQL tables
        foreach ($this->pgsqlTables as $tableName) {
            $schema = Schema::connection('pgsql_rag');
            if ($schema->hasTable($tableName) && !$schema->hasColumn($tableName, 'rag_indexed_at')) {
                $schema->table($tableName, function (Blueprint $blueprint) {
                    $blueprint->timestamp('rag_indexed_at')->nullable();
                    $blueprint->index('rag_indexed_at');
                });
            }
        }
    }

    public function down(): void
    {
        // MySQL tables
        foreach ($this->mysqlTables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'rag_indexed_at')) {
                Schema::table($tableName, function (Blueprint $blueprint) use ($tableName) {
                    $blueprint->dropIndex("{$tableName}_rag_indexed_at_index");
                    $blueprint->dropColumn('rag_indexed_at');
                });
            }
        }

        // PostgreSQL tables
        foreach ($this->pgsqlTables as $tableName) {
            $schema = Schema::connection('pgsql_rag');
            if ($schema->hasTable($tableName) && $schema->hasColumn($tableName, 'rag_indexed_at')) {
                $schema->table($tableName, function (Blueprint $blueprint) use ($tableName) {
                    $blueprint->dropIndex("{$tableName}_rag_indexed_at_index");
                    $blueprint->dropColumn('rag_indexed_at');
                });
            }
        }
    }
};
