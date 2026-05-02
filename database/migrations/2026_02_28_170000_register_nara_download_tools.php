<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Register nara_download tool (download specific digital object)
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, enabled, risk_level, category, source, notes, created_at, updated_at)
            VALUES
                ('nara_download', 'App\\\\Services\\\\Genealogy\\\\GenealogySourceService', 'downloadNaraObject',
                 'Download a digital object (TIFF/JPG/PDF) from a NARA catalog record. Requires naId and download URL from search results.',
                 '{\"na_id\": {\"type\": \"string\", \"required\": true, \"description\": \"NARA record ID (naId)\"}, \"download_url\": {\"type\": \"string\", \"required\": true, \"description\": \"Direct download URL from digitalObjects array\"}, \"filename\": {\"type\": \"string\", \"required\": false, \"description\": \"Override filename\"}, \"family_surname\": {\"type\": \"string\", \"required\": false, \"description\": \"Organize under family surname folder\"}}',
                 '[]', 1, 'write', 'genealogy', 'manual',
                 'Downloads NARA digital objects (census pages, military records, maps, photos). Rate limit: 10K/month. Files stored at storage/app/nara/.',
                 NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                service_class = VALUES(service_class),
                method = VALUES(method),
                description = VALUES(description),
                parameters = VALUES(parameters),
                permissions = VALUES(permissions),
                risk_level = VALUES(risk_level),
                notes = VALUES(notes),
                updated_at = NOW()
        ");

        // Register nara_download_best tool (auto-select best format)
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, enabled, risk_level, category, source, notes, created_at, updated_at)
            VALUES
                ('nara_download_best', 'App\\\\Services\\\\Genealogy\\\\GenealogySourceService', 'downloadBestNaraObject',
                 'Download the best available format (TIFF>JPG>PDF) for a NARA record. Auto-fetches digital objects and picks optimal file.',
                 '{\"na_id\": {\"type\": \"string\", \"required\": true, \"description\": \"NARA record ID (naId)\"}, \"family_surname\": {\"type\": \"string\", \"required\": false, \"description\": \"Organize under family surname folder\"}}',
                 '[]', 1, 'write', 'genealogy', 'manual',
                 'Convenience wrapper: fetches digital objects list, selects best format, downloads. Two API calls.',
                 NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                service_class = VALUES(service_class),
                method = VALUES(method),
                description = VALUES(description),
                parameters = VALUES(parameters),
                permissions = VALUES(permissions),
                risk_level = VALUES(risk_level),
                notes = VALUES(notes),
                updated_at = NOW()
        ");

        // Register nara_copy_to_tree tool (copy to genealogy tree + file registry)
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, enabled, risk_level, category, source, notes, created_at, updated_at)
            VALUES
                ('nara_copy_to_tree', 'App\\\\Services\\\\Genealogy\\\\GenealogySourceService', 'copyNaraToTree',
                 'Copy a downloaded NARA file into a genealogy tree folder on Nextcloud and register in file_registry.',
                 '{\"local_path\": {\"type\": \"string\", \"required\": true, \"description\": \"Path relative to storage/app (from download result)\"}, \"tree_id\": {\"type\": \"integer\", \"required\": true, \"description\": \"Genealogy tree ID\"}, \"subfolder\": {\"type\": \"string\", \"required\": false, \"description\": \"Target subfolder: documents, photos, etc. Default: documents\"}, \"metadata\": {\"type\": \"object\", \"required\": false, \"description\": \"NARA metadata: na_id, title, record_group, type\"}}',
                 '[]', 1, 'write', 'genealogy', 'manual',
                 'Uploads to Nextcloud tree folder, registers in file_registry with source:nara tags. Triggers enrichment pipeline.',
                 NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                service_class = VALUES(service_class),
                method = VALUES(method),
                description = VALUES(description),
                parameters = VALUES(parameters),
                permissions = VALUES(permissions),
                risk_level = VALUES(risk_level),
                notes = VALUES(notes),
                updated_at = NOW()
        ");

        // Register nara_get_objects tool (list downloadable files)
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, enabled, risk_level, category, source, notes, created_at, updated_at)
            VALUES
                ('nara_get_objects', 'App\\\\Services\\\\Genealogy\\\\GenealogySourceService', 'getNaraDigitalObjects',
                 'Get list of downloadable digital objects (files) for a NARA record by naId.',
                 '{\"na_id\": {\"type\": \"string\", \"required\": true, \"description\": \"NARA record ID (naId)\"}}',
                 '[]', 1, 'read', 'genealogy', 'manual',
                 'Returns URLs, formats, sizes for all digital objects attached to a NARA record.',
                 NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                service_class = VALUES(service_class),
                method = VALUES(method),
                description = VALUES(description),
                parameters = VALUES(parameters),
                permissions = VALUES(permissions),
                risk_level = VALUES(risk_level),
                notes = VALUES(notes),
                updated_at = NOW()
        ");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM agent_tool_registry WHERE name IN ('nara_download', 'nara_download_best', 'nara_copy_to_tree', 'nara_get_objects')");
    }
};
