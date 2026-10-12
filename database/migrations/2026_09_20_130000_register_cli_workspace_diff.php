<?php

use App\Services\NativeCodexToolService;
use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('agent_tool_registry')->insertOrIgnore([
                'name' => 'native_workspace_diff', 'service_class' => NativeCodexToolService::class,
                'method' => 'workspaceDiff', 'description' => 'Read tracked workspace Git changes through a fixed read-only native command; exclude protected paths before content reads.',
                'parameters' => json_encode(['type' => 'object', 'additionalProperties' => false,
                    'properties' => ['path' => ['type' => 'string', 'minLength' => 1],
                        'staged' => ['type' => 'boolean', 'default' => false]], 'required' => ['path']], JSON_THROW_ON_ERROR),
                'returns_description' => 'Full diff bytes, selected and excluded paths, and explicit tracked-file scope.',
                'permissions' => '["native:read"]', 'risk_level' => 'read', 'category' => 'coding',
                'requires_confirmation' => false, 'enabled' => true, 'source' => 'manual',
                'notes' => 'PLOS workspace diff v1; fixed Git reads through the existing native readOnly sandbox with networking disabled.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $skill = app(SkillLoaderService::class)->loadSkillSnapshot('offline-assistant');
            if ($skill === null) {
                throw new RuntimeException('The complete deployed offline-assistant skill is required.');
            }
            app(SkillVersionService::class)->trackVersion('offline-assistant', $skill['frontmatter'], $skill['body'], $skill['full_content']);
        });
    }

    public function down(): void
    {
        // Retain versioned skill evidence and operator decisions. Release rollback
        // must restore the prior skill version together with its runtime source.
    }
};
