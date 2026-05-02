<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix internet_archive_search — was missing parameters
        DB::update(
            "UPDATE agent_tool_registry SET parameters = ? WHERE name = 'internet_archive_search'",
            [json_encode([
                'query' => ['type' => 'string', 'required' => true, 'description' => 'Search query (person name, topic, document type)'],
                'options' => ['type' => 'array', 'default' => [], 'description' => 'Options: collection, rows, page'],
            ])]
        );

        // Fix nara_search — was pointing at non-existent WebResearchService method
        DB::update(
            "UPDATE agent_tool_registry SET service_class = ?, method = ?, parameters = ? WHERE name = 'nara_search'",
            [
                'App\\Services\\Genealogy\\GenealogySourceService',
                'searchNARA',
                json_encode([
                    'query' => ['type' => 'string', 'required' => true, 'description' => 'Search query (person name, record type, location)'],
                    'options' => ['type' => 'array', 'default' => [], 'description' => 'Options: type_filter (marriage, birth, death, military, immigration)'],
                ]),
            ]
        );
    }

    public function down(): void
    {
        // No rollback needed
    }
};
