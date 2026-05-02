<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N102 — Local HTR (Handwritten Text Recognition) Pipeline
 *
 * Registers agent tools for HtrTranscriptionService.
 * Also checks if genealogy_media has a transcription_text column
 * and adds it if missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add transcription_text column to genealogy_media if not present
        try {
            DB::statement("
                ALTER TABLE genealogy_media
                ADD COLUMN transcription_text MEDIUMTEXT NULL AFTER description
            ");
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column name')) {
                throw $e;
            }
        }

        // Register agent tools
        $tools = [
            [
                'name'          => 'transcribe_handwriting',
                'description'   => 'N102 — Transcribe a handwritten genealogy document using local TrOCR (microsoft/trocr-base-handwritten). Accepts a file_registry UUID or absolute image path. Returns transcribed text + confidence. Best for: letters, church registers, census returns, wills, diaries. Falls back to CPU (trocr-small) if GPU busy.',
                'service_class' => 'App\\Services\\Genealogy\\HtrTranscriptionService',
                'method'   => 'transcribeByUuid',
                'parameters'    => json_encode([
                    'uuid' => ['type' => 'string', 'required' => true, 'description' => 'file_registry UUID of the image to transcribe'],
                ]),
                'permissions'   => json_encode([]),
                'enabled'       => 1,
            ],
            [
                'name'          => 'transcribe_media_handwriting',
                'description'   => 'N102 — Transcribe a genealogy_media record handwriting using TrOCR. Stores result in genealogy_media.transcription_text. Use this when you have a media_id from the genealogy tree.',
                'service_class' => 'App\\Services\\Genealogy\\HtrTranscriptionService',
                'method'   => 'transcribeGenealogyMedia',
                'parameters'    => json_encode([
                    'media_id' => ['type' => 'integer', 'required' => true, 'description' => 'genealogy_media.id to transcribe'],
                ]),
                'permissions'   => json_encode([]),
                'enabled'       => 1,
            ],
            [
                'name'          => 'htr_status',
                'description'   => 'N102 — Check if TrOCR/HTR pipeline is installed and whether CUDA GPU is available.',
                'service_class' => 'App\\Services\\Genealogy\\HtrTranscriptionService',
                'method'   => 'getStatus',
                'parameters'    => json_encode([]),
                'permissions'   => json_encode([]),
                'enabled'       => 1,
            ],
        ];

        foreach ($tools as $tool) {
            DB::statement("
                INSERT INTO agent_tool_registry
                    (name, description, service_class, method, parameters, permissions, enabled)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    description   = VALUES(description),
                    service_class = VALUES(service_class),
                    method   = VALUES(method),
                    parameters    = VALUES(parameters),
                    permissions   = VALUES(permissions),
                    enabled       = VALUES(enabled)
            ", [
                $tool['name'],
                $tool['description'],
                $tool['service_class'],
                $tool['method'],
                $tool['parameters'],
                $tool['permissions'],
                $tool['enabled'],
            ]);
        }
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->whereIn('name', ['transcribe_handwriting', 'transcribe_media_handwriting', 'htr_status'])
            ->delete();

        try {
            DB::statement("ALTER TABLE genealogy_media DROP COLUMN transcription_text");
        } catch (\Exception $e) {
            // column may not exist
        }
    }
};
