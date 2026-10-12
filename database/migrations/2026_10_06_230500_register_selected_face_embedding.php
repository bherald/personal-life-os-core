<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const METHODS = [
        'file_face_generation_plan' => 'planAnonymousFaceGeneration',
        'file_face_generation_execute' => 'restoreAnonymousFaceGeneration',
        'file_face_generation_resume' => 'resumeAnonymousFaceGeneration',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'cli')->where('config_key', 'framework_services')->lockForUpdate()->first();
            $policy = $row ? json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR) : null;
            if (! is_array($policy) || ! is_array($policy['tools'] ?? null)) {
                throw new RuntimeException('Missing table-owned CLI service policy.');
            }
            foreach (self::METHODS as $name => $method) {
                $tool = DB::table('agent_tool_registry')->where('name', $name)->lockForUpdate()->first();
                if (! $tool || $tool->service_class !== 'App\\Services\\FaceEmbeddingService' || $tool->method !== $method
                    || $tool->risk_level !== ($name === 'file_face_generation_plan' ? 'read' : 'write')
                    || (int) $tool->requires_confirmation !== ($name === 'file_face_generation_plan' ? 0 : 1)
                    || ((int) $tool->enabled === 1 && ($policy['tools'][$name] ?? null) !== ['target' => 'local', 'tree_scoped' => false, 'references' => []])) {
                    throw new RuntimeException('Conflicting generation registration: '.$name);
                }
                $parameters = json_decode($tool->parameters, true, flags: JSON_THROW_ON_ERROR);
                $selector = ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional exact anonymous manual face created by completed current-region operator review; encode its persisted ROI without HOG detection or identity assignment.'];
                if (! is_array($parameters['properties'] ?? null) || in_array('selected_face_id', $parameters['required'] ?? [], true)
                    || (isset($parameters['properties']['selected_face_id']) && $parameters['properties']['selected_face_id'] !== $selector)) {
                    throw new RuntimeException('Conflicting selected face selector: '.$name);
                }
                $parameters['properties']['selected_face_id'] = $selector;
                DB::table('agent_tool_registry')->where('id', $tool->id)->update([
                    'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Retain admission and custody for any selected-region generation receipts.
        throw new RuntimeException('Selected-region registration rollback requires reviewed generation custody.');
    }
};
