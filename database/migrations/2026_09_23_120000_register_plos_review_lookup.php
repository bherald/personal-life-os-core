<?php

use App\Services\Review\ReviewLookupService;
use App\Services\SkillLoaderService;
use App\Services\SkillVersionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $tool = [
                'name' => 'plos_review_lookup',
                'service_class' => ReviewLookupService::class,
                'method' => 'lookup',
                'description' => 'Read one Review Desk item by exact canonical reference or known alias, including closed history. No approval or mutation.',
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => ['reference' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255,
                        'pattern' => '^[^-\\u0000-\\u001F\\u007F][^\\u0000-\\u001F\\u007F]*$']],
                    'required' => ['reference'],
                    'additionalProperties' => false,
                ], JSON_THROW_ON_ERROR),
                'returns_description' => 'Review Desk lookup payload or null when no exact reference or known alias matches.',
                'permissions' => '[]',
                'enabled' => 1,
                'risk_level' => 'read',
                'category' => 'review',
                'requires_confirmation' => 0,
                'max_calls_per_run' => 20,
                'source' => 'manual',
                'notes' => 'Read only; ReviewDeskService owns canonical and alias resolution.',
                'availability_status' => 'available',
                'privacy_class' => 'internal_private',
                'allows_private_data' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $existing = DB::table('agent_tool_registry')->where('name', $tool['name'])->first();
            if ($existing === null) {
                DB::table('agent_tool_registry')->insert($tool);
            } elseif ($existing->service_class !== $tool['service_class'] || $existing->method !== $tool['method']) {
                throw new RuntimeException('Review lookup tool name is registered to a different service.');
            }

            if (! DB::table('skill_versions')->where('skill_name', 'plos-review-lookup')->exists()) {
                $skill = app(SkillLoaderService::class)->loadSkillSnapshot('plos-review-lookup');
                if ($skill === null) {
                    throw new RuntimeException('Review lookup skill source is unavailable.');
                }
                app(SkillVersionService::class)->trackVersion('plos-review-lookup', $skill['frontmatter'], $skill['body'], $skill['full_content']);
            }
        });
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')->where('name', 'plos_review_lookup')
            ->where('service_class', ReviewLookupService::class)->where('method', 'lookup')
            ->update(['enabled' => 0, 'updated_at' => now()]);

        $content = file_get_contents(SkillLoaderService::configuredSkillsBasePath().'/plos-review-lookup/SKILL.md');
        if ($content !== false) {
            DB::table('skill_versions')->where('skill_name', 'plos-review-lookup')
                ->where('content_hash', hash('sha256', $content))->update(['is_active' => false]);
        }
    }
};
