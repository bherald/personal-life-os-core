<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, permissions, enabled, risk_level, category, source, notes, created_at, updated_at)
            VALUES
                ('newspapers_media_download', 'App\\\\Services\\\\Genealogy\\\\GenealogyMediaDownloadService', 'downloadNewspapersMedia',
                 'Download or capture Newspapers.com media with configured paid/library access. Private/personal-gated by NEWSPAPERS_BARCODE and NEWSPAPERS_PERSONAL_AUTOMATION_ENABLED; returns stored media or capture status.',
                 '{\"url\": {\"type\": \"string\", \"required\": true, \"description\": \"Newspapers.com image, clipping, or article URL\"}, \"tree_id\": {\"type\": \"integer\", \"required\": true, \"description\": \"Genealogy tree ID for storage\"}}',
                 '[\"genealogy:write\"]', 1, 'write', 'genealogy', 'manual',
                 'Private paid/library media adapter for configured Newspapers.com access. Files are stored in genealogy downloaded media storage for later source/media linking.',
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
        DB::statement("DELETE FROM agent_tool_registry WHERE name = 'newspapers_media_download'");
    }
};
