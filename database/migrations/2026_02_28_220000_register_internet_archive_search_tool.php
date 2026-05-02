<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // internet_archive_search was referenced in SKILL.md and a prior migration
        // tried to UPDATE it, but the initial INSERT was never created.
        // Exists on prod (manually inserted) but not dev.
        // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both environments.

        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description,
                 permissions, enabled, risk_level, category, requires_confirmation,
                 max_calls_per_run, source, created_at, updated_at)
            VALUES
                ('internet_archive_search',
                 'App\\\\Services\\\\InternetArchiveService',
                 'search',
                 'Search the Internet Archive catalog (37M+ items: books, newspapers, census microfilm, genealogy collections, historical documents)',
                 ?,
                 'Array of search results with identifier, title, creator, date, description, mediatype, collection',
                 '[]',
                 1,
                 'read',
                 'genealogy',
                 0,
                 20,
                 'config',
                 NOW(),
                 NOW())
            ON DUPLICATE KEY UPDATE
                service_class = VALUES(service_class),
                method = VALUES(method),
                description = VALUES(description),
                parameters = VALUES(parameters),
                returns_description = VALUES(returns_description),
                updated_at = NOW()
        ", [
            json_encode([
                'query' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Search query (person name, topic, document type)',
                ],
                'options' => [
                    'type' => 'array',
                    'default' => [],
                    'description' => 'Options: collection (e.g. census, newspapers), rows (max 100), page, mediatype (texts/image), year_range ([from, to])',
                ],
            ]),
        ]);
    }

    public function down(): void
    {
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'internet_archive_search'");
    }
};
