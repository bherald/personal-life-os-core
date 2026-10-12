<?php

use App\Services\ContentExtractionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $tool = DB::table('agent_tool_registry')->where('name', 'content_extract')
                ->where('service_class', ContentExtractionService::class)->where('method', 'extract')->lockForUpdate()->first();
            if ($tool === null) {
                return;
            }
            $parameters = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
            if (isset($parameters['type']) || ! isset($parameters['file_path']) || array_key_exists('options', $parameters)) {
                return;
            }
            $defaults = ['use_tika' => true, 'use_vision' => true, 'use_ocr' => true,
                'use_transcription' => true, 'extract_faces' => false];
            foreach ($defaults as $name => $default) {
                $parameters += [$name => ['type' => 'boolean', 'default' => $default, 'x_context_only' => true]];
            }
            $parameters['options'] = ['type' => 'object', 'x_context_only' => true,
                'x_parameter_bundle' => array_keys($defaults)];
            if (DB::table('agent_tool_registry')->where('id', $tool->id)
                ->whereRaw('CAST(parameters AS CHAR) = ?', [$tool->parameters])
                ->update(['parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('Content extraction options changed during migration.');
            }
        });
    }

    public function down(): void
    {
        // Retain the usable shared parameter contract and operator defaults.
    }
};
